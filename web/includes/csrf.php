<?php
declare(strict_types=1);

function csrf_token(): string
{
    $name = (string)app_config('app', 'csrf_token_name', 'csrf_token');
    if (empty($_SESSION[$name])) {
        $_SESSION[$name] = random_token(32);
    }
    return (string)$_SESSION[$name];
}

function csrf_field(): string
{
    $name = (string)app_config('app', 'csrf_token_name', 'csrf_token');
    return '<input type="hidden" name="' . e($name) . '" value="' . e(csrf_token()) . '">';
}

function require_csrf(): void
{
    if (request_method() === 'GET' || request_method() === 'HEAD' || request_method() === 'OPTIONS') {
        return;
    }
    $name = (string)app_config('app', 'csrf_token_name', 'csrf_token');
    $token = (string)($_POST[$name] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    $sessionToken = (string)($_SESSION[$name] ?? '');
    if ($token === '' || $sessionToken === '' || !hash_equals($sessionToken, $token)) {
        if (wants_json()) {
            json_error('Invalid CSRF token', 403);
        }
        http_response_code(403);
        exit('Invalid CSRF token');
    }
}
