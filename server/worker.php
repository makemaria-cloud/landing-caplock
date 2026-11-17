<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
umask(0077);
require __DIR__ . '/core.php';
require __DIR__ . '/delivery.php';
try {
    $path = realpath(getenv('CAPLOCK_CONFIG') ?: '/nonexistent-caplock');
    if (!$path) { throw new RuntimeException('config'); }
    $c = require $path;
    if (!is_array($c) || !\Caplock\outside($path, (string)realpath($c['document_root'] ?? ''))) { throw new RuntimeException('config'); }
    \Caplock\ready($c);
    require __DIR__ . '/vendor/autoload.php';
    $lock = fopen($c['storage'] . '/.worker.lock', 'c');
    if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) { exit(0); }
    $command = $argv[1] ?? 'run';
    if ($command === 'run') {
        \Caplock\cleanup($c);
        foreach (glob($c['storage'] . '/*.json') as $job) {
            if (preg_match('/^[a-f0-9]{32}\.json$/D', basename($job))) { \Caplock\processJob($c, $job, '\\Caplock\\deliver'); }
        }
    } elseif ($command === 'cleanup') { \Caplock\cleanup($c); }
    elseif ($command === 'status') {
        foreach (glob($c['storage'] . '/*.json') as $p) {
            if (!preg_match('/^[a-f0-9]{32}\.json$/D', basename($p))) { continue; }
            $j = \Caplock\read($p);
            echo $j['id'] . ' ' . (($j['expired'] ?? false) ? 'expired' : implode(' ', array_map(fn($k)=>$k . '=' . $j[$k]['status'], ['crm','email','crm_comment']))) . PHP_EOL;
        }
    } else { throw new RuntimeException('command'); }
    flock($lock, LOCK_UN); fclose($lock);
} catch (Throwable) { fwrite(STDERR, "caplock: worker failed; check private config/storage, no delivery claimed\n"); exit(1); }
