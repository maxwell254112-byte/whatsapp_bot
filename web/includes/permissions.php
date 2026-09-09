<?php
declare(strict_types=1);

const ALL_PERMISSIONS = [
    'manage_contacts',
    'manage_campaigns',
    'send_messages',
    'manage_workers',
    'manage_settings',
    'view_logs',
    'manage_templates',
    'manage_media',
];

function user_has_permission(string $permission): bool
{
    $user = current_user();
    if (!$user) {
        return false;
    }
    if (($user['role'] ?? '') === 'admin') {
        return true;
    }
    $perms = $user['permissions'] ?? [];
    return in_array($permission, $perms, true);
}

function require_permission(string $permission): void
{
    require_login();
    if (!user_has_permission($permission)) {
        if (wants_json()) {
            json_error('Forbidden', 403);
        }
        http_response_code(403);
        exit('Forbidden');
    }
}
