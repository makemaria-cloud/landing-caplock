<?php
declare(strict_types=1);
namespace Caplock;

final class DeliveryError extends \RuntimeException
{
    public function __construct(public string $outcome) { parent::__construct($outcome); }
}
function escape(string $value): string
{
    return htmlspecialchars(str_replace(['[',']'], ['(',')'], $value), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
function comment(array $job, ?string $card = null, array $links = [], bool $html = true): string
{
    $lines = ['CUP-LOCK: ' . $job['id']];
    foreach ($job['data'] as $key=>$value) { if ($value !== '') { $lines[] = $key . ': ' . $value; } }
    if ($job['files']) {
        $lines[] = 'Вложения: ' . implode(', ', array_column($job['files'], 'name'));
        foreach ($links as $link) { $lines[] = 'Файл в CRM (требуется авторизация): ' . $link; }
        if ($card) { $lines[] = 'Карточка CRM с вложениями (не прямая ссылка на файл): ' . $card; }
    }
    $text = implode("\n", $lines);
    return $html ? nl2br(escape($text), false) : $text;
}
function safeFileUrl(mixed $url, array $c, int $lead): ?string
{
    if (!is_string($url) || strlen($url) > 2048 || preg_match('/[\x00-\x20\\\\]/', $url)) { return null; }
    $portal = $c['crm']['portal'];
    if (str_starts_with($url, '/') && !str_starts_with($url, '//')) { $url = $portal . $url; }
    $u = parse_url($url); $p = parse_url($portal);
    if (!$u || ($u['scheme'] ?? '') !== 'https' || ($u['host'] ?? '') !== $p['host'] || ($u['port'] ?? 443) !== ($p['port'] ?? 443)
        || isset($u['user']) || isset($u['pass']) || isset($u['fragment'])
        || ($u['path'] ?? '') !== '/bitrix/components/bitrix/crm.lead.show/show_file.php') { return null; }
    parse_str($u['query'] ?? '', $q);
    if (array_diff(array_keys($q), ['auth','ownerId','fieldName','dynamic','fileId']) || (isset($q['auth']) && $q['auth'] !== '')
        || ($q['ownerId'] ?? '') !== (string)$lead || ($q['fieldName'] ?? '') !== $c['crm']['files_field']
        || ($q['dynamic'] ?? '') !== 'Y' || !is_string($q['fileId'] ?? null) || !preg_match('/^[1-9]\d*$/D', $q['fileId'])) { return null; }
    unset($q['auth']);
    return $portal . $u['path'] . '?' . http_build_query($q, '', '&', PHP_QUERY_RFC3986);
}
function leadFields(array $c, array $job): array
{
    $r = $c['crm']; $d = $job['data'];
    $fields = $r['required_fields'];
    $fields['TITLE'] = 'CUP-LOCK: ' . $d['city'] . ' [' . $job['id'] . ']';
    $fields['PHONE'] = [['VALUE'=>$d['phone'],'VALUE_TYPE'=>'WORK']];
    if ($d['email'] !== '') { $fields['EMAIL'] = [['VALUE'=>$d['email'],'VALUE_TYPE'=>'WORK']]; }
    $fields['ASSIGNED_BY_ID'] = $r['assigned_by_id'];
    $fields['COMMENTS'] = comment($job);
    if ($d['city'] !== '') { $fields[$r['address_field']] = $d['city']; }
    $fields[$r['id_field']] = $job['id'];
    $fields['UF_CRM_1703676529460'] = $r['source_value'];
    foreach (['utm_source','utm_medium','utm_campaign','utm_content','utm_term'] as $key) { $fields[strtoupper($key)] = $d[$key]; }
    $fields[$r['files_field']] = [];
    foreach ($job['files'] as $f) {
        $path = $c['storage'] . '/' . $job['id'] . '/' . $f['name'];
        if (!is_file($path) || !hash_equals($f['sha256'], (string)hash_file('sha256', $path))) { throw new DeliveryError('failed'); }
        $fields[$r['files_field']][] = ['fileData'=>[$f['name'], base64_encode((string)file_get_contents($path))]];
    }
    return $fields;
}
function crmCall(array $c, string $method, array $payload): mixed
{
    // URL только из серверного конфига. Порт 8443 сохраняется, редиректы запрещены.
    $url = $c['crm']['webhook'] . $method . '.json';
    $ch = curl_init($url); $body = '';
    curl_setopt_array($ch, [CURLOPT_POST=>true, CURLOPT_POSTFIELDS=>json_encode($payload, JSON_THROW_ON_ERROR),
        CURLOPT_HTTPHEADER=>['Content-Type: application/json'], CURLOPT_CONNECTTIMEOUT=>10, CURLOPT_TIMEOUT=>60,
        CURLOPT_FOLLOWLOCATION=>false, CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,
        CURLOPT_SSL_VERIFYPEER=>true, CURLOPT_SSL_VERIFYHOST=>2,
        CURLOPT_WRITEFUNCTION=>static function ($ch, string $chunk) use (&$body): int {
            if (strlen($body) + strlen($chunk) > 1024*1024) { return 0; }
            $body .= $chunk; return strlen($chunk);
        }]);
    $ok = curl_exec($ch); $errno = curl_errno($ch); $http = curl_getinfo($ch, CURLINFO_RESPONSE_CODE); curl_close($ch);
    if ($ok === false) {
        throw new DeliveryError(in_array($errno, [CURLE_COULDNT_RESOLVE_HOST, CURLE_COULDNT_CONNECT, CURLE_SSL_CONNECT_ERROR, CURLE_PEER_FAILED_VERIFICATION], true) ? 'retry' : 'uncertain');
    }
    $response = json_decode($body, true);
    if (!is_array($response)) { throw new DeliveryError('uncertain'); }
    if (isset($response['error'])) {
        throw new DeliveryError($response['error'] === 'QUERY_LIMIT_EXCEEDED' ? 'retry' : 'failed');
    }
    if ($http < 200 || $http >= 300 || !array_key_exists('result', $response)) { throw new DeliveryError('uncertain'); }
    return $response['result'];
}
function deliver(array $c, string $channel, array $job, ?callable $rpc = null): mixed
{
    $call = $rpc ?? '\\Caplock\\crmCall';
    if ($channel === 'crm') {
        $id = $call($c, 'crm.lead.add', ['fields'=>leadFields($c, $job)]);
        if (!is_numeric($id) || (int)$id < 1) { throw new DeliveryError('uncertain'); }
        return (int)$id;
    }
    if ($channel === 'crm_comment') {
        $id = $job['crm']['result'];
        $card = $c['crm']['portal'] . '/crm/lead/details/' . $id . '/';
        $links = []; $files = [];
        try { $lead = $call($c, 'crm.lead.get', ['id'=>$id]); }
        catch (DeliveryError $e) { throw new DeliveryError($e->outcome === 'uncertain' ? 'retry' : $e->outcome); }
        if (!is_array($lead) || !is_string($lead['COMMENTS'] ?? null)) { throw new DeliveryError('failed'); }
        if ($job['files']) {
            // GET и повторная запись тех же COMMENTS не создают новых лидов.
            $files = is_array($lead) ? ($lead[$c['crm']['files_field']] ?? []) : [];
            if (!is_array($files)) { $files = []; }
            foreach ($files as $f) { $url = safeFileUrl(is_array($f) ? ($f['showUrl'] ?? null) : null, $c, $id); if ($url) { $links[] = $url; } }
            $links = array_values(array_unique($links));
        }
        $marker = 'CAPLOCK_FILES_' . $job['id'];
        if (str_contains($lead['COMMENTS'], $marker)) { return ['file_links'=>count($links),'expected'=>count($job['files']),'fallback'=>count($links) < count($job['files'])]; }
        $block = '<br><br>' . $marker;
        foreach ($links as $link) { $block .= '<br><a href="' . escape($link) . '">Файл в CRM (требуется авторизация)</a>'; }
        $block .= '<br><a href="' . escape($card) . '">Карточка CRM с вложениями (не прямая ссылка на файл)</a>';
        try { $result = $call($c, 'crm.lead.update', ['id'=>$id,'fields'=>['COMMENTS'=>$lead['COMMENTS'] . $block]]); }
        catch (DeliveryError $e) { throw new DeliveryError($e->outcome === 'uncertain' ? 'retry' : $e->outcome); }
        if ($result !== true) { throw new DeliveryError('uncertain'); }
        return ['file_links'=>count($links),'expected'=>count($job['files']),'fallback'=>count($links) < count($job['files'])];
    }
    $s = $c['smtp'];
    $m = new \PHPMailer\PHPMailer\PHPMailer(true);
    $m->isSMTP(); $m->Host = $s['host']; $m->Port = $s['port'];
    $m->SMTPSecure = $s['security']; $m->SMTPAuth = true;
    $m->Username = $s['username']; $m->Password = $s['password'];
    $m->Timeout = 20; $m->Timelimit = 60; $m->SMTPDebug = 0;
    $m->CharSet = 'UTF-8'; $m->setFrom($s['from'], 'CUP-LOCK'); $m->addAddress($s['to']);
    $m->Subject = 'CUP-LOCK [' . $job['id'] . ']';
    $m->MessageID = '<caplock.' . $job['id'] . '@caplock.dayagroup.ru>';
    $m->Body = comment($job, null, [], false);
    foreach ($job['files'] as $f) {
        $path = $c['storage'] . '/' . $job['id'] . '/' . $f['name'];
        if (!is_file($path) || !hash_equals($f['sha256'], (string)hash_file('sha256', $path))) { throw new DeliveryError('failed'); }
        $m->addAttachment($path, $f['name']);
    }
    // Сбой соединения до MAIL/DATA допускает повтор; после начала send исход может быть неизвестен.
    try { if (!$m->smtpConnect()) { throw new \RuntimeException('connect'); } }
    catch (\Throwable) { throw new DeliveryError('retry'); }
    try { if (!$m->send()) { throw new \RuntimeException('send'); } }
    catch (\Throwable) { throw new DeliveryError('uncertain'); }
    finally { $m->smtpClose(); }
    return true;
}
function processJob(array $c, string $path, callable $transport): void
{
    $job = read($path);
    if ($job['expired'] ?? false) { return; }
    foreach (['crm','email','crm_comment'] as $channel) {
        $state = &$job[$channel];
        if ($state['status'] === 'sending') { $state['status'] = 'uncertain'; atomic($path, $job); }
        if (!in_array($state['status'], ['pending','retry'], true) || $state['next'] > time()) { unset($state); continue; }
        if ($channel === 'crm_comment' && ($job['crm']['status'] !== 'sent' || empty($job['crm']['result']))) { unset($state); continue; }
        $state['status'] = 'sending'; $state['attempts']++; atomic($path, $job);
        try { $state['result'] = $transport($c, $channel, $job); $state['status'] = 'sent'; }
        catch (DeliveryError $e) { $state['status'] = $e->outcome; }
        catch (\Throwable) { $state['status'] = 'uncertain'; }
        if ($state['status'] === 'retry') {
            if ($state['attempts'] >= 5) { $state['status'] = 'failed'; }
            else { $state['next'] = time() + min(3600, 60 * (2 ** $state['attempts'])); }
        }
        atomic($path, $job); unset($state);
    }
}
function cleanup(array $c): void
{
    // Только фиксированная структура очереди; ссылки и произвольные пути не обходятся.
    locked($c['storage'], function () use ($c) {
        foreach (glob($c['storage'] . '/*.json') as $path) {
            $id = basename($path, '.json'); if (!preg_match('/^[a-f0-9]{32}$/D', $id)) { continue; }
            $job = read($path); $age = time() - $job['created'];
            if ($age < $c['retention_days']*86400) { continue; }
            $dir = $c['storage'] . '/' . $id;
            if (is_dir($dir) && !is_link($dir)) { foreach (glob($dir . '/*') as $f) { if (is_file($f) && !is_link($f)) { unlink($f); } } rmdir($dir); }
            if ($age >= $c['dedup_days']*86400) { unlink($path); }
            else { atomic($path, ['id'=>$id,'fingerprint'=>$job['fingerprint'],'created'=>$job['created'],'expired'=>true]); }
        }
        // При падении процесса между mkdir и commit могли остаться несвязанные вложения.
        foreach (glob($c['storage'] . '/*', GLOB_ONLYDIR) as $dir) {
            $id = basename($dir);
            if (!preg_match('/^[a-f0-9]{32}$/D', $id) || is_link($dir) || is_file($c['storage'] . '/' . $id . '.json') || filemtime($dir) > time()-86400) { continue; }
            foreach (glob($dir . '/*') as $f) { if (is_file($f) && !is_link($f)) { unlink($f); } } rmdir($dir);
        }
    });
}
