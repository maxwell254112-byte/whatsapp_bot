<?php
declare(strict_types=1);

function setting_get(string $key, mixed $default = null): mixed
{
    static $cache = [];
    if (array_key_exists($key, $cache)) {
        return $cache[$key];
    }
    try {
        $stmt = db()->prepare('SELECT setting_value FROM system_settings WHERE setting_key = ? LIMIT 1');
        $stmt->execute([$key]);
        $row = $stmt->fetch();
        if ($row) {
            $cache[$key] = $row['setting_value'];
            return $cache[$key];
        }
    } catch (Throwable $e) {
        error_log('setting_get: ' . $e->getMessage());
    }
    return $default;
}

function setting_get_cached(string $key, mixed $default = null): mixed
{
    return setting_get($key, $default);
}

function setting_get_int(string $key, int $default = 0): int
{
    return (int)setting_get($key, $default);
}

function setting_set(string $key, string $value, ?int $userId = null): void
{
    $stmt = db()->prepare(
        'INSERT INTO system_settings (setting_key, setting_value, updated_by, updated_at)
         VALUES (?, ?, ?, UTC_TIMESTAMP())
         ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_by = VALUES(updated_by), updated_at = UTC_TIMESTAMP()'
    );
    $stmt->execute([$key, $value, $userId]);
}

function settings_all(): array
{
    $rows = db()->query('SELECT setting_key, setting_value FROM system_settings ORDER BY setting_key')->fetchAll();
    $out = [];
    foreach ($rows as $row) {
        $out[$row['setting_key']] = $row['setting_value'];
    }
    return $out;
}
