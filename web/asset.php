<?php
declare(strict_types=1);

/**
 * Fallback asset server for hosts that block direct static file access (403).
 * Usage: asset.php?f=css/app.css  or  asset.php?f=js/app.js
 */
$rel = (string)($_GET['f'] ?? '');
$rel = str_replace('\\', '/', $rel);
$rel = ltrim($rel, '/');

$allowed = [
    'css/app.css' => 'text/css; charset=utf-8',
    'js/app.js' => 'application/javascript; charset=utf-8',
];

if (!isset($allowed[$rel])) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Not found';
    exit;
}

$path = __DIR__ . '/assets/' . $rel;
if (!is_file($path)) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Not found';
    exit;
}

header('Content-Type: ' . $allowed[$rel]);
header('Cache-Control: public, max-age=86400');
header('X-Content-Type-Options: nosniff');
readfile($path);
