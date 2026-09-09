<?php
declare(strict_types=1);

function e(?string $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function app_config(string $section, string $key, mixed $default = null): mixed
{
    global $CONFIG;
    return $CONFIG[$section][$key] ?? $default;
}

/** URL path to the web root, e.g. /whatsapp_bot/web (no trailing slash). */
function web_base_path(): string
{
    $script = str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? '/index.php'));
    $dir = str_replace('\\', '/', dirname($script));
    if (str_ends_with($dir, '/admin')) {
        $dir = dirname($dir);
    }
    if (str_ends_with($dir, '/worker')) {
        $dir = dirname($dir);
    }
    if (str_ends_with($dir, '/api')) {
        $dir = dirname($dir);
    }
    if ($dir === '/' || $dir === '\\' || $dir === '.') {
        return '';
    }
    return rtrim($dir, '/');
}

/** Scheme + host + web path from the current HTTP request. */
function request_public_url(): string
{
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || ((string)($_SERVER['SERVER_PORT'] ?? '') === '443')
        || (strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https');
    $scheme = $https ? 'https' : 'http';
    $host = (string)($_SERVER['HTTP_HOST'] ?? 'localhost');
    return $scheme . '://' . $host . web_base_path();
}

/**
 * Public web root URL (no trailing slash).
 * Ignores localhost base_url when the real request host is not local (common cPanel misconfig).
 */
function web_base_url(): string
{
    $fromRequest = request_public_url();
    $configured = rtrim((string)app_config('app', 'base_url', ''), '/');
    if ($configured === '' || str_contains($configured, 'YOUR_DOMAIN')) {
        return $fromRequest;
    }

    $cfgHost = strtolower((string)(parse_url($configured, PHP_URL_HOST) ?? ''));
    $reqHost = strtolower(explode(':', (string)($_SERVER['HTTP_HOST'] ?? 'localhost'))[0]);
    $localHosts = ['localhost', '127.0.0.1'];
    if (in_array($cfgHost, $localHosts, true) && !in_array($reqHost, $localHosts, true)) {
        return $fromRequest;
    }

    return $configured;
}

function asset_url(string $relativePath): string
{
    $relativePath = ltrim(str_replace('\\', '/', $relativePath), '/');
    $base = web_base_path();
    // Path-absolute URLs always use the browser's current host (never localhost from config).
    // Prefer PHP fallback for css/js so hosts that 403 static files still work.
    if ($relativePath === 'assets/css/app.css') {
        return $base . '/asset.php?f=css/app.css';
    }
    if ($relativePath === 'assets/js/app.js') {
        return $base . '/asset.php?f=js/app.js';
    }
    return $base . '/' . $relativePath;
}

function now_utc(): string
{
    return gmdate('Y-m-d H:i:s');
}

function app_tz(): DateTimeZone
{
    return new DateTimeZone((string)app_config('app', 'timezone', 'Asia/Kuala_Lumpur'));
}

/** Convert user/local datetime string to UTC MySQL datetime. */
function local_to_utc(string $localDatetime): string
{
    $dt = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $localDatetime, app_tz())
        ?: DateTimeImmutable::createFromFormat('Y-m-d\TH:i', $localDatetime, app_tz())
        ?: DateTimeImmutable::createFromFormat('Y-m-d H:i', $localDatetime, app_tz());
    if (!$dt) {
        throw new InvalidArgumentException('Invalid datetime');
    }
    return $dt->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
}

/** Convert UTC MySQL datetime to app timezone display. */
function utc_to_local(?string $utcDatetime, string $format = 'Y-m-d H:i:s'): string
{
    if ($utcDatetime === null || $utcDatetime === '') {
        return '';
    }
    $dt = new DateTimeImmutable($utcDatetime, new DateTimeZone('UTC'));
    return $dt->setTimezone(app_tz())->format($format);
}

function client_ip(): string
{
    return substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45);
}

function user_agent(): string
{
    return substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255);
}

function redirect(string $path): never
{
    if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://')) {
        header('Location: ' . $path);
        exit;
    }

    $base = rtrim(web_base_url(), '/');
    $path = ltrim($path, '/');
    // Support legacy callers like admin/dashboard.php
    header('Location: ' . $base . '/' . $path);
    exit;
}

function request_method(): string
{
    return strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
}

function post_string(string $key, string $default = ''): string
{
    return isset($_POST[$key]) ? trim((string)$_POST[$key]) : $default;
}

function post_int(string $key, int $default = 0): int
{
    return isset($_POST[$key]) ? (int)$_POST[$key] : $default;
}

function get_int(string $key, int $default = 0): int
{
    return isset($_GET[$key]) ? (int)$_GET[$key] : $default;
}

function get_string(string $key, string $default = ''): string
{
    return isset($_GET[$key]) ? trim((string)$_GET[$key]) : $default;
}

function flash_set(string $type, string $message): void
{
    $_SESSION['_flash'] = ['type' => $type, 'message' => $message];
}

function flash_get(): ?array
{
    if (!isset($_SESSION['_flash'])) {
        return null;
    }
    $flash = $_SESSION['_flash'];
    unset($_SESSION['_flash']);
    return $flash;
}

function json_body(): array
{
    $raw = file_get_contents('php://input');
    if ($raw === false || $raw === '') {
        return [];
    }
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

function random_token(int $bytes = 32): string
{
    return bin2hex(random_bytes($bytes));
}

function hash_token(string $token): string
{
    return hash('sha256', $token);
}

/** Neutralize spreadsheet formula injection on CSV fields. */
function sanitize_csv_field(string $v): string
{
    $v = trim($v);
    if ($v !== '' && in_array($v[0], ['=', '+', '-', '@'], true)) {
        $v = "'" . $v;
    }
    return mb_substr($v, 0, 160);
}
