<?php
declare(strict_types=1);

function json_response(bool $success, string $message, mixed $data = null, int $http = 200): never
{
    http_response_code($http);
    header('Content-Type: application/json; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    echo json_encode([
        'success' => $success,
        'message' => $message,
        'data' => $data,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

function json_success(string $message = 'OK', mixed $data = null, int $http = 200): never
{
    json_response(true, $message, $data, $http);
}

function json_error(string $message, int $http = 400, mixed $data = null): never
{
    json_response(false, $message, $data, $http);
}
