<?php
declare(strict_types=1);
ini_set('display_errors', '0');
umask(0077);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
$reply = static function (int $status, array $body): never {
    http_response_code($status);
    echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    exit;
};
try {
    $app = realpath(getenv('CAPLOCK_APP') ?: '/nonexistent-caplock');
    $root = realpath($_SERVER['DOCUMENT_ROOT'] ?? '');
    $normalize = static fn($p) => strtolower(str_replace('\\', '/', $p));
    if (!$app || !$root || str_starts_with($normalize($app) . '/', rtrim($normalize($root), '/') . '/')) {
        $reply(503, ['error'=>'Прием заявок пока недоступен. Используйте контакты рядом с формой.']);
    }
    require $app . '/core.php';
    $c = \Caplock\config();
    if (realpath($c['document_root']) !== $root || ($_SERVER['HTTPS'] ?? '') !== 'on') {
        \Caplock\fail(503, 'Прием заявок пока недоступен.');
    }
    $method = $_SERVER['REQUEST_METHOD'] ?? '';
    if (!in_array($method, ['GET','POST'], true)) { header('Allow: GET, POST'); \Caplock\fail(405, 'Метод не поддерживается.'); }
    if ($method === 'POST' && (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 26*1024*1024) { \Caplock\fail(413, 'Суммарный размер файлов: до 25 МБ.'); }
    if ($method === 'POST' && !str_starts_with($_SERVER['CONTENT_TYPE'] ?? '', 'multipart/form-data;')) { \Caplock\fail(415, 'Требуется multipart/form-data.'); }
    session_name('caplock_session');
    session_start(['use_strict_mode'=>1,'use_only_cookies'=>1,'cookie_secure'=>true,'cookie_httponly'=>true,'cookie_samesite'=>'Strict','cookie_path'=>'/api/']);
    if (session_status() !== PHP_SESSION_ACTIVE) { \Caplock\fail(503, 'Прием заявок пока недоступен.'); }
    $_SESSION['csrf'] ??= bin2hex(random_bytes(32));
    $csrf = $_SESSION['csrf'];
    session_write_close();
    if ($method === 'GET') {
        $reply(200, ['csrf'=>$csrf,'consent_text'=>$c['consent_text']]);
    }
    \Caplock\guard($_SERVER, $_POST, $csrf, $c['origin']);
    \Caplock\rate($c, $_SERVER['REMOTE_ADDR'] ?? 'unknown');
    $d = \Caplock\validate($_POST, $c['origin']);
    $files = \Caplock\uploads($_FILES);
    $id = \Caplock\text($_POST, 'idempotency_key', 32, true);
    $result = \Caplock\enqueue($c, $id, $d, $files);
    $reply(202, $result);
} catch (\Throwable $e) {
    if ($e instanceof \Caplock\HttpError) { $reply($e->status, ['error'=>$e->getMessage()]); }
    // В ответах и журнале нет персональных данных, путей или секретов.
    error_log('caplock: request failed');
    $reply(503, ['error'=>'Не удалось подтвердить прием заявки. Данные сохранены в форме. Повторите отправку позже.']);
}
