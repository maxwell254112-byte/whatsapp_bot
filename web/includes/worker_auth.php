<?php
declare(strict_types=1);

function bearer_token_from_request(): ?string
{
    $header = (string)($_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
    if (preg_match('/^Bearer\s+(\S+)$/i', $header, $m)) {
        return $m[1];
    }
    return null;
}

function authenticate_worker(): array
{
    $token = bearer_token_from_request();
    if ($token === null || strlen($token) < 32) {
        json_error('Unauthorized', 401);
    }
    $hash = hash_token($token);
    $stmt = db()->prepare('SELECT * FROM workers WHERE token_hash = ? LIMIT 1');
    $stmt->execute([$hash]);
    $worker = $stmt->fetch();
    if (!$worker) {
        json_error('Unauthorized', 401);
    }
    if (!(int)$worker['is_enabled'] || $worker['status'] === 'disabled') {
        json_error('Worker disabled', 403);
    }
    return $worker;
}

function register_worker(string $name, int $userId): array
{
    $name = trim($name);
    if ($name === '' || strlen($name) > 120) {
        return ['ok' => false, 'message' => 'Invalid worker name'];
    }
    $token = random_token(32);
    $hash = hash_token($token);
    $prefix = substr($token, 0, 8);
    try {
        $stmt = db()->prepare(
            'INSERT INTO workers (name, token_hash, token_prefix, status, is_enabled, created_at, updated_at)
             VALUES (?, ?, ?, \'offline\', 1, UTC_TIMESTAMP(), UTC_TIMESTAMP())'
        );
        $stmt->execute([$name, $hash, $prefix]);
        $id = (int)db()->lastInsertId();
        audit_log($userId, $id, 'worker_registered', 'worker', (string)$id, ['prefix' => $prefix]);
        return ['ok' => true, 'id' => $id, 'token' => $token, 'message' => 'Store this token now; it will not be shown again.'];
    } catch (PDOException $e) {
        if ((int)$e->errorInfo[1] === 1062) {
            return ['ok' => false, 'message' => 'Worker name already exists'];
        }
        throw $e;
    }
}

function regenerate_worker_token(int $workerId, int $userId): array
{
    $token = random_token(32);
    $hash = hash_token($token);
    $prefix = substr($token, 0, 8);
    $stmt = db()->prepare(
        'UPDATE workers SET token_hash = ?, token_prefix = ?, updated_at = UTC_TIMESTAMP() WHERE id = ?'
    );
    $stmt->execute([$hash, $prefix, $workerId]);
    if ($stmt->rowCount() === 0) {
        return ['ok' => false, 'message' => 'Worker not found'];
    }
    audit_log($userId, $workerId, 'worker_token_regenerated', 'worker', (string)$workerId, ['prefix' => $prefix]);
    return ['ok' => true, 'token' => $token, 'message' => 'Store this token now; it will not be shown again.'];
}

function mark_stale_workers_offline(): void
{
    $hb = setting_get_int('worker_heartbeat_timeout_seconds', 90);
    db()->exec(
        "UPDATE workers
         SET status = 'offline', updated_at = UTC_TIMESTAMP()
         WHERE is_enabled = 1
           AND status = 'online'
           AND (last_heartbeat_at IS NULL OR last_heartbeat_at < (UTC_TIMESTAMP() - INTERVAL {$hb} SECOND))"
    );
}
