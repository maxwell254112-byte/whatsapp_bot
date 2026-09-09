<?php
declare(strict_types=1);

function audit_log(?int $userId, ?int $workerId, string $action, string $entityType = '', string $entityId = '', array $detail = []): void
{
    try {
        $stmt = db()->prepare(
            'INSERT INTO audit_logs (user_id, worker_id, action, entity_type, entity_id, ip_address, user_agent, detail_json, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP())'
        );
        $stmt->execute([
            $userId,
            $workerId,
            substr($action, 0, 80),
            substr($entityType, 0, 60),
            substr($entityId, 0, 60),
            client_ip(),
            user_agent(),
            $detail ? json_encode($detail, JSON_UNESCAPED_UNICODE) : null,
        ]);
    } catch (Throwable $e) {
        error_log('audit_log failed: ' . $e->getMessage());
    }
}

function message_log(?int $jobId, ?int $campaignId, ?int $workerId, string $phone, string $eventType, string $status, string $message = '', ?array $detail = null): void
{
    try {
        $stmt = db()->prepare(
            'INSERT INTO message_logs (job_id, campaign_id, worker_id, phone_normalized, event_type, status, message, detail_json, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP())'
        );
        $stmt->execute([
            $jobId,
            $campaignId,
            $workerId,
            substr($phone, 0, 32),
            substr($eventType, 0, 64),
            substr($status, 0, 32),
            substr($message, 0, 500),
            $detail ? json_encode($detail, JSON_UNESCAPED_UNICODE) : null,
        ]);
    } catch (Throwable $e) {
        error_log('message_log failed: ' . $e->getMessage());
    }
}
