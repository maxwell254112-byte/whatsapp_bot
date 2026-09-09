<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/bootstrap.php';

$worker = authenticate_worker();
$body = json_body();

$whatsapp = (string)($body['whatsapp_status'] ?? $worker['whatsapp_status']);
$allowedWa = ['unknown', 'qr_required', 'connected', 'disconnected', 'error'];
if (!in_array($whatsapp, $allowedWa, true)) {
    $whatsapp = 'unknown';
}

$stmt = db()->prepare(
    'UPDATE workers SET
       status = \'online\',
       whatsapp_status = ?,
       last_heartbeat_at = UTC_TIMESTAMP(),
       hostname = COALESCE(?, hostname),
       os_info = COALESCE(?, os_info),
       browser = COALESCE(?, browser),
       python_version = COALESCE(?, python_version),
       worker_version = COALESCE(?, worker_version),
       last_error = ?,
       updated_at = UTC_TIMESTAMP()
     WHERE id = ?'
);
$stmt->execute([
    $whatsapp,
    isset($body['hostname']) ? substr((string)$body['hostname'], 0, 120) : null,
    isset($body['os_info']) ? substr((string)$body['os_info'], 0, 255) : null,
    isset($body['browser']) ? substr((string)$body['browser'], 0, 120) : null,
    isset($body['python_version']) ? substr((string)$body['python_version'], 0, 40) : null,
    isset($body['worker_version']) ? substr((string)$body['worker_version'], 0, 40) : null,
    isset($body['last_error']) ? substr((string)$body['last_error'], 0, 2000) : null,
    (int)$worker['id'],
]);

mark_stale_workers_offline();
recover_stale_jobs();
activate_due_scheduled_jobs();
refresh_campaign_statuses();

json_success('Heartbeat OK', [
    'worker_id' => (int)$worker['id'],
    'server_time_utc' => now_utc(),
    'rate_limits' => [
        'min_delay_seconds' => setting_get_int('min_delay_seconds', 3),
        'max_delay_seconds' => setting_get_int('max_delay_seconds', 8),
        'max_messages_per_batch' => setting_get_int('max_messages_per_batch', 20),
        'pause_between_batches_seconds' => setting_get_int('pause_between_batches_seconds', 60),
    ],
]);
