<?php
declare(strict_types=1);

function wabot_session_start(array $CONFIG): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    $name = (string)($CONFIG['app']['session_name'] ?? 'WABOTSESSID');
    $secure = (bool)($CONFIG['app']['secure_cookies'] ?? false);
    $lifetime = 28800;
    try {
        if (function_exists('setting_get_cached')) {
            $lifetime = (int)setting_get_cached('session_lifetime_seconds', 28800);
        }
    } catch (Throwable $e) {
        $lifetime = 28800;
    }

    session_name($name);
    session_set_cookie_params([
        'lifetime' => $lifetime,
        'path' => '/',
        'secure' => $secure,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();

    if (!isset($_SESSION['_created_at'])) {
        $_SESSION['_created_at'] = time();
    }
    if (!isset($_SESSION['_last_activity'])) {
        $_SESSION['_last_activity'] = time();
    }

    if ((time() - (int)$_SESSION['_last_activity']) > $lifetime) {
        logout_user();
        return;
    }
    $_SESSION['_last_activity'] = time();
}

function current_user(): ?array
{
    return $_SESSION['user'] ?? null;
}

function require_login(): void
{
    if (!current_user()) {
        if (wants_json()) {
            json_error('Authentication required', 401);
        }
        redirect('index.php');
    }
}

function wants_json(): bool
{
    $accept = (string)($_SERVER['HTTP_ACCEPT'] ?? '');
    $uri = (string)($_SERVER['REQUEST_URI'] ?? '');
    return str_contains($accept, 'application/json') || str_contains($uri, '/api/');
}

function login_user(string $username, string $password): array
{
    $username = trim($username);
    $ip = client_ip();

    if ($username === '' || $password === '') {
        return ['ok' => false, 'message' => t('err_invalid_credentials')];
    }

    if (is_login_throttled($username, $ip)) {
        audit_log(null, null, 'login_throttled', 'user', $username, ['ip' => $ip]);
        return ['ok' => false, 'message' => t('err_too_many_attempts')];
    }

    record_login_attempt($username, $ip);

    $stmt = db()->prepare('SELECT * FROM users WHERE username = ? LIMIT 1');
    $stmt->execute([$username]);
    $user = $stmt->fetch();

    // Constant-time style: always verify against a dummy hash if user missing
    $hash = $user['password_hash'] ?? '$2y$10$usesomesillystringforexactlythis';
    $valid = password_verify($password, $hash);

    if (!$user || !$valid) {
        audit_log(null, null, 'login_failed', 'user', $username, ['ip' => $ip]);
        return ['ok' => false, 'message' => t('err_invalid_credentials')];
    }

    if (!(int)$user['is_active']) {
        audit_log((int)$user['id'], null, 'login_disabled', 'user', (string)$user['id'], []);
        return ['ok' => false, 'message' => t('err_account_disabled')];
    }

    if (!empty($user['locked_until']) && strtotime($user['locked_until'] . ' UTC') > time()) {
        return ['ok' => false, 'message' => t('err_account_locked')];
    }

    session_regenerate_id(true);
    $perms = json_decode((string)$user['permissions'], true);
    if (!is_array($perms)) {
        $perms = [];
    }

    $_SESSION['user'] = [
        'id' => (int)$user['id'],
        'username' => $user['username'],
        'display_name' => $user['display_name'],
        'role' => $user['role'],
        'permissions' => $perms,
    ];
    $_SESSION['_created_at'] = time();
    $_SESSION['_last_activity'] = time();

    $upd = db()->prepare('UPDATE users SET failed_login_attempts = 0, locked_until = NULL, last_login_at = UTC_TIMESTAMP() WHERE id = ?');
    $upd->execute([(int)$user['id']]);

    audit_log((int)$user['id'], null, 'login_success', 'user', (string)$user['id'], []);
    return ['ok' => true, 'message' => 'OK'];
}

function logout_user(): void
{
    $uid = current_user()['id'] ?? null;
    if ($uid) {
        audit_log((int)$uid, null, 'logout', 'user', (string)$uid, []);
    }
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'] ?? '', (bool)$p['secure'], (bool)$p['httponly']);
    }
    session_destroy();
}

function is_login_throttled(string $username, string $ip): bool
{
    $stmt = db()->prepare(
        'SELECT COUNT(*) FROM login_throttle
         WHERE username = ? AND ip_address = ?
           AND attempted_at > (UTC_TIMESTAMP() - INTERVAL 15 MINUTE)'
    );
    $stmt->execute([$username, $ip]);
    return (int)$stmt->fetchColumn() >= 10;
}

function record_login_attempt(string $username, string $ip): void
{
    $stmt = db()->prepare('INSERT INTO login_throttle (username, ip_address, attempted_at) VALUES (?, ?, UTC_TIMESTAMP())');
    $stmt->execute([$username, $ip]);
}
