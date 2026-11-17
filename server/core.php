<?php
declare(strict_types=1);
namespace Caplock;
require_once __DIR__ . '/consent.php';

final class HttpError extends \RuntimeException
{
    public function __construct(public int $status, string $message) { parent::__construct($message); }
}

function fail(int $status, string $message): never { throw new HttpError($status, $message); }
function outside(string $path, string $root): bool
{
    $path = strtolower(str_replace('\\', '/', $path));
    $root = rtrim(strtolower(str_replace('\\', '/', $root)), '/');
    return $path !== $root && !str_starts_with($path, $root . '/');
}
function config(): array
{
    $path = getenv('CAPLOCK_CONFIG') ?: '';
    $root = realpath($_SERVER['DOCUMENT_ROOT'] ?? '') ?: '';
    if (!$path || !is_file($path) || !$root || !outside((string) realpath($path), $root)) {
        fail(503, 'Прием заявок пока недоступен. Используйте контакты рядом с формой.');
    }
    $c = require $path;
    if (!is_array($c)) { fail(503, 'Прием заявок пока недоступен.'); }
    ready($c);
    return $c;
}
function ready(array $c): void
{
    $r = $c['crm'] ?? []; $s = $c['smtp'] ?? [];
    $storage = realpath($c['storage'] ?? ''); $root = realpath($c['document_root'] ?? '');
    $uf = fn($v) => is_string($v) && preg_match('/^UF_CRM_[A-Z0-9_]+$/D', $v);
    $u = parse_url($r['webhook'] ?? '');
    $portal = parse_url($r['portal'] ?? '');
    if (($c['enabled'] ?? false) !== true || ($c['consent_approved'] ?? false) !== true
        || ($c['consent_text'] ?? '') !== CONSENT_LABEL
        || ($c['origin'] ?? '') !== 'https://caplock.dayagroup.ru'
        || !$storage || !$root || !outside($storage, $root) || !is_writable($storage)
        || strlen($c['rate_key'] ?? '') < 32 || !is_file(__DIR__ . '/vendor/autoload.php')
        || !$uf($r['files_field'] ?? null) || !$uf($r['id_field'] ?? null)
        || !$uf($r['address_field'] ?? null)
        || ($r['portal'] ?? '') !== 'https://bitrix.opalubka.vip:8443'
        || ($u['host'] ?? '') !== ($portal['host'] ?? '') || ($u['port'] ?? null) !== 8443
        || count(array_unique([$r['files_field'] ?? '', $r['id_field'] ?? '', 'UF_CRM_1703676529460'])) !== 3
        || !isset($r['source_value']) || $r['source_value'] === '' || !is_scalar($r['source_value'])
        || !is_int($r['assigned_by_id'] ?? null) || $r['assigned_by_id'] < 1
        || !is_array($r['required_fields'] ?? null)
        || ($u['scheme'] ?? '') !== 'https' || empty($u['host']) || isset($u['user']) || isset($u['query']) || isset($u['fragment'])
        || !preg_match('~^/rest/[^/]+/[^/]+/$~D', $u['path'] ?? '')
        || !preg_match('/^[a-z0-9.-]+$/iD', $s['host'] ?? '')
        || !in_array([$s['port'] ?? 0, $s['security'] ?? ''], [[587, 'tls'], [465, 'ssl']], true)
        || empty($s['username']) || empty($s['password']) || !filter_var($s['from'] ?? '', FILTER_VALIDATE_EMAIL)
        || ($s['to'] ?? '') !== 'zayavka@dayagroup.ru'
        || ($c['retention_days'] ?? 0) < 1 || $c['retention_days'] > 7
        || ($c['dedup_days'] ?? 0) < 7 || $c['dedup_days'] > 90
        || ($c['max_jobs'] ?? 0) < 1) {
        fail(503, 'Прием заявок пока недоступен. Используйте контакты рядом с формой.');
    }
    foreach (['curl', 'fileinfo', 'mbstring', 'openssl'] as $ext) {
        if (!extension_loaded($ext)) { fail(503, 'Прием заявок пока недоступен.'); }
    }
}
function text(array $p, string $key, int $max, bool $required = false): string
{
    $v = $p[$key] ?? '';
    if (!is_string($v) || !mb_check_encoding($v, 'UTF-8') || mb_strlen($v) > $max
        || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $v)) { fail(422, 'Проверьте поле: ' . $key); }
    $v = trim($v);
    if ($required && $v === '') { fail(422, 'Заполните поле: ' . $key); }
    return $v;
}
function validate(array $p, string $origin): array
{
    $d = [];
    foreach (['phone'=>30, 'email'=>254, 'city'=>120, 'mode'=>16, 'area'=>12, 'height'=>12, 'start'=>120,
        'details'=>3000, 'project_url'=>2048, 'page'=>2048, 'utm_source'=>200, 'utm_medium'=>200,
        'utm_campaign'=>200, 'utm_content'=>200, 'utm_term'=>200] as $key=>$max) {
        $d[$key] = text($p, $key, $max, in_array($key, ['phone','city','mode','page'], true));
    }
    if (!in_array($d['mode'], ['project','parameters'], true)) { fail(422, 'Проверьте режим формы.'); }
    $raw = $d['phone']; $digits = preg_replace('/\D/', '', $raw);
    if (!preg_match('/^\+?[\d\s().-]+$/D', $raw)) { fail(422, 'Проверьте телефон.'); }
    if (!str_starts_with($raw, '+') && strlen($digits) === 10) { $digits = '7' . $digits; }
    elseif (!str_starts_with($raw, '+') && strlen($digits) === 11 && $digits[0] === '8') { $digits = '7' . substr($digits, 1); }
    if (!preg_match('/^[1-9]\d{7,14}$/D', $digits)
        || (!str_starts_with($raw, '+') && !preg_match('/^7[1-9]\d{9}$/D', $digits))
        || (str_starts_with($digits, '7') && !preg_match('/^7[1-9]\d{9}$/D', $digits))) { fail(422, 'Проверьте телефон.'); }
    $d['phone'] = '+' . $digits;
    if ($d['email'] !== '' && (!filter_var($d['email'], FILTER_VALIDATE_EMAIL) || preg_match('/[\r\n]/', $d['email']))) { fail(422, 'Проверьте email.'); }
    foreach (['area'=>100000000, 'height'=>10000] as $k=>$max) {
        if ($d[$k] !== '' && (!is_numeric($d[$k]) || (float)$d[$k] <= 0 || (float)$d[$k] > $max)) { fail(422, 'Проверьте параметры объекта.'); }
    }
    foreach (['project_url','page'] as $k) {
        if ($d[$k] === '') { continue; }
        $u = parse_url($d[$k]);
        if (!filter_var($d[$k], FILTER_VALIDATE_URL) || !in_array($u['scheme'] ?? '', ['http','https'], true)
            || isset($u['user']) || isset($u['pass']) || preg_match('/[\r\n]/', $d[$k])) { fail(422, 'Проверьте ссылку.'); }
        if ($k === 'page' && ($u['scheme'] . '://' . $u['host'] . (isset($u['port']) ? ':' . $u['port'] : '')) !== $origin) { fail(422, 'Проверьте адрес страницы.'); }
    }
    return $d;
}
function guard(array $server, array $p, string $csrf, string $origin): void
{
    if (($server['REQUEST_METHOD'] ?? '') !== 'POST') { fail(405, 'Требуется POST.'); }
    if (($server['HTTP_ORIGIN'] ?? '') !== $origin || !in_array($server['HTTP_SEC_FETCH_SITE'] ?? 'same-origin', ['same-origin'], true)) { fail(403, 'Недопустимый источник запроса.'); }
    if (!is_string($p['csrf'] ?? null) || $csrf === '' || !hash_equals($csrf, $p['csrf'])) { fail(403, 'Обновите страницу и повторите отправку.'); }
    if (text($p, 'website', 200) !== '') { fail(422, 'Заявка не принята.'); }
    if (($p['consent'] ?? '') !== '1') { fail(422, 'Необходимо подтверждение согласия.'); }
}
function atomic(string $path, array $value): void
{
    $tmp = $path . '.' . bin2hex(random_bytes(8)) . '.tmp';
    $f = fopen($tmp, 'xb');
    if (!$f) { throw new \RuntimeException('storage'); }
    try {
        chmod($tmp, 0600); $json = json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        if (fwrite($f, $json) !== strlen($json) || !fflush($f) || !fsync($f)) { throw new \RuntimeException('storage'); }
    } finally { fclose($f); }
    if (!rename($tmp, $path)) { @unlink($tmp); throw new \RuntimeException('storage'); }
}
function read(string $path): array { return json_decode((string)file_get_contents($path), true, 512, JSON_THROW_ON_ERROR); }
function locked(string $storage, callable $fn): mixed
{
    $f = fopen($storage . '/.lock', 'c');
    if (!$f || !flock($f, LOCK_EX)) { throw new \RuntimeException('lock'); }
    chmod($storage . '/.lock', 0600);
    try { return $fn(); } finally { flock($f, LOCK_UN); fclose($f); }
}
function rate(array $c, string $ip): void
{
    locked($c['storage'], function () use ($c, $ip) {
        $path = $c['storage'] . '/rate.json'; $r = is_file($path) ? read($path) : [];
        $now = time(); $r = array_filter($r, fn($v) => $v['until'] > $now);
        $key = hash_hmac('sha256', $ip, $c['rate_key']);
        $v = $r[$key] ?? ['count'=>0,'until'=>$now+3600];
        if ($v['count'] >= 10 || count($r) >= 10000 && !isset($r[$key])) { fail(429, 'Слишком много запросов. Попробуйте позже.'); }
        $v['count']++; $r[$key] = $v; atomic($path, $r);
    });
}
function uploads(array $files): array
{
    if (!$files) { return []; }
    if (array_keys($files) !== ['files']) { fail(422, 'Неизвестное поле вложений.'); }
    $f = $files['files'];
    foreach (['name','tmp_name','error','size'] as $k) {
        if (!is_array($f[$k] ?? null)) { fail(422, 'Проверьте вложения.'); }
    }
    if (count($f['name']) > 5) { fail(422, 'Можно приложить до 5 файлов.'); }
    $result = []; $total = 0;
    foreach ($f['name'] as $i=>$name) {
        if (($f['error'][$i] ?? -1) === UPLOAD_ERR_NO_FILE) { continue; }
        $tmp = $f['tmp_name'][$i] ?? '';
        if (!is_string($tmp) || !is_uploaded_file($tmp) || ($f['error'][$i] ?? -1) !== UPLOAD_ERR_OK) { fail(422, 'Не удалось загрузить вложение.'); }
        $size = filesize($tmp); $total += $size;
        if (!$size || $size > 20*1024*1024 || $total > 25*1024*1024) { fail(422, 'До 20 МБ на файл и 25 МБ суммарно.'); }
        $ext = fileType($name, $tmp);
        $result[] = ['tmp'=>$tmp, 'name'=>bin2hex(random_bytes(16)) . '.' . $ext, 'size'=>$size, 'sha256'=>hash_file('sha256', $tmp)];
    }
    return $result;
}
function fileType(mixed $name, string $path): string
{
    if (!is_string($name) || strlen($name) > 200 || preg_match('~[\\\\/\x00-\x1F]~', $name)) { fail(422, 'Недопустимое имя файла.'); }
    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    // Сигнатуры не заменяют антивирус. Архивы не распаковываем и не исполняем.
    $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($path);
    $head = file_get_contents($path, false, null, 0, 1024);
    $ok = match ($ext) {
        'pdf' => $mime === 'application/pdf' && str_starts_with($head, '%PDF-'),
        'dwg' => in_array($mime, ['image/vnd.dwg','application/acad','application/octet-stream'], true) && preg_match('/^AC10\d{2}/', $head),
        'dxf' => in_array($mime, ['text/plain','application/dxf','image/vnd.dxf','application/octet-stream'], true) && (str_starts_with($head, "AutoCAD Binary DXF\r\n\x1A\x00") || preg_match('/\A\s*0\s+SECTION\s+2\s+(HEADER|ENTITIES)/', $head)),
        'xls' => in_array($mime, ['application/vnd.ms-excel','application/x-ole-storage','application/CDFV2','application/x-cdf','application/octet-stream'], true) && str_starts_with($head, "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1"),
        'xlsx' => in_array($mime, ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet','application/zip','application/octet-stream'], true) && str_starts_with($head, "PK\x03\x04"),
        'docx' => in_array($mime, ['application/vnd.openxmlformats-officedocument.wordprocessingml.document','application/zip','application/octet-stream'], true) && str_starts_with($head, "PK\x03\x04"),
        'zip' => in_array($mime, ['application/zip','application/x-zip','application/x-zip-compressed','application/octet-stream'], true) && (str_starts_with($head, "PK\x03\x04") || str_starts_with($head, "PK\x05\x06")),
        default => false,
    };
    if (!$ok) { fail(422, 'Допустимы PDF, DWG, DXF, XLS, XLSX, DOCX, ZIP с корректной сигнатурой и MIME.'); }
    return $ext;
}
function fingerprint(array $d, array $files, string $key): string
{
    return hash_hmac('sha256', json_encode([$d, array_map(fn($f)=>[$f['sha256'],$f['size']], $files)], JSON_THROW_ON_ERROR), $key);
}
function enqueue(array $c, string $id, array $d, array $files, ?callable $move = null, ?array $document = null): array
{
    if (!preg_match('/^[a-f0-9]{32}$/D', $id)) { fail(422, 'Обновите страницу: нет идентификатора заявки.'); }
    $document ??= consentDocument($c);
    $fp = fingerprint($d, $files, $c['rate_key']); $storage = $c['storage'];
    return locked($storage, function () use ($c, $id, $d, $files, $fp, $storage, $move, $document) {
        $path = "$storage/$id.json";
        if (is_file($path)) {
            $old = read($path);
            if (!hash_equals($old['fingerprint'], $fp)) { fail(409, 'Состав заявки изменен. Дождитесь проверки предыдущей отправки.'); }
            if (($old['expired'] ?? false)) { fail(410, 'Срок обработки заявки истек. Свяжитесь с нами.'); }
            return ['id'=>$id,'status'=>'received'];
        }
        if (count(glob($storage . '/*.json')) >= $c['max_jobs']) { fail(503, 'Прием временно недоступен. Попробуйте позже.'); }
        $dir = "$storage/$id";
        // Остаток прерванного приема с тем же ID не перезаписывается автоматически.
        if (!mkdir($dir, 0700)) { throw new \RuntimeException('staging'); }
        $stored = [];
        try {
            foreach ($files as $f) {
                $target = $dir . '/' . $f['name'];
                if (!(($move ?? 'move_uploaded_file')($f['tmp'], $target))) { throw new \RuntimeException('upload move'); }
                chmod($target, 0600); $h = fopen($target, 'rb');
                if (!$h || !fsync($h)) { throw new \RuntimeException('upload sync'); }
                fclose($h); unset($f['tmp']); $stored[] = $f;
            }
            $channel = ['status'=>'pending','attempts'=>0,'next'=>0];
            atomic($path, ['id'=>$id,'fingerprint'=>$fp,'created'=>time(),'data'=>$d,'files'=>$stored,
                'consent'=>array_merge($document, ['approved'=>true,'confirmed_at'=>gmdate('c')]),
                'crm'=>$channel,'email'=>$channel,'crm_comment'=>$channel]);
        } catch (\Throwable $e) {
            foreach (glob($dir . '/*') as $f) { unlink($f); } rmdir($dir); throw $e;
        }
        return ['id'=>$id,'status'=>'received'];
    });
}
