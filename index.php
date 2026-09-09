<?php
declare(strict_types=1);
// Project root entry: send browser into /web/
$target = 'web/index.php';
if (!empty($_SERVER['HTTP_HOST'])) {
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || ((string)($_SERVER['SERVER_PORT'] ?? '') === '443');
    $scheme = $https ? 'https' : 'http';
    $dir = str_replace('\\', '/', dirname((string)($_SERVER['SCRIPT_NAME'] ?? '/')));
    if ($dir === '/' || $dir === '\\' || $dir === '.') {
        $dir = '';
    }
    $target = $scheme . '://' . $_SERVER['HTTP_HOST'] . $dir . '/web/index.php';
}
header('Location: ' . $target, true, 302);
exit;
