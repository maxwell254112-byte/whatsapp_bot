<?php
declare(strict_types=1);

function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    global $CONFIG;
    $host = (string)($CONFIG['database']['host'] ?? '127.0.0.1');
    $port = (int)($CONFIG['database']['port'] ?? 3306);
    $name = (string)($CONFIG['database']['name'] ?? 'whatsampp_bot');
    $user = (string)($CONFIG['database']['user'] ?? 'root');
    $pass = (string)($CONFIG['database']['pass'] ?? '123qwe');
    $charset = (string)($CONFIG['database']['charset'] ?? 'utf8mb4');

    $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=%s', $host, $port, $name, $charset);
    $pdo = new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    $pdo->exec("SET time_zone = '+00:00'");
    return $pdo;
}
