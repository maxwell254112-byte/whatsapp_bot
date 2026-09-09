<?php
declare(strict_types=1);

const CAMPAIGN_TRANSITIONS = [
    'draft' => ['queued', 'cancelled'],
    'queued' => ['running', 'paused', 'cancelled'],
    'running' => ['paused', 'completed', 'cancelled'],
    'paused' => ['queued', 'cancelled'],
    'completed' => [],
    'cancelled' => [],
];

function can_transition_campaign(string $from, string $to): bool
{
    return in_array($to, CAMPAIGN_TRANSITIONS[$from] ?? [], true);
}

function recalculate_campaign_stats(?int $campaignId): void
{
    if (!$campaignId) {
        return;
    }
    $stmt = db()->prepare(
        "UPDATE campaigns c
         SET
           total_count = (SELECT COUNT(*) FROM message_jobs j WHERE j.campaign_id = c.id),
           sent_count = (SELECT COUNT(*) FROM message_jobs j WHERE j.campaign_id = c.id AND j.status = 'sent'),
           failed_count = (SELECT COUNT(*) FROM message_jobs j WHERE j.campaign_id = c.id AND j.status = 'failed'),
           pending_count = (SELECT COUNT(*) FROM message_jobs j WHERE j.campaign_id = c.id AND j.status IN ('pending','scheduled')),
           processing_count = (SELECT COUNT(*) FROM message_jobs j WHERE j.campaign_id = c.id AND j.status = 'processing'),
           cancelled_count = (SELECT COUNT(*) FROM message_jobs j WHERE j.campaign_id = c.id AND j.status = 'cancelled'),
           updated_at = UTC_TIMESTAMP()
         WHERE c.id = ?"
    );
    $stmt->execute([$campaignId]);
}

function refresh_campaign_statuses(): void
{
    // Mark completed when no open jobs remain
    db()->exec(
        "UPDATE campaigns c
         SET status = 'completed', completed_at = UTC_TIMESTAMP(), updated_at = UTC_TIMESTAMP()
         WHERE c.status IN ('queued','running')
           AND c.total_count > 0
           AND NOT EXISTS (
             SELECT 1 FROM message_jobs j
             WHERE j.campaign_id = c.id AND j.status IN ('pending','scheduled','processing')
           )"
    );
}

function create_campaign(array $data, int $userId): array
{
    $name = trim((string)($data['name'] ?? ''));
    $body = (string)($data['message_body'] ?? '');
    $templateId = !empty($data['template_id']) ? (int)$data['template_id'] : null;
    $mediaId = !empty($data['media_id']) ? (int)$data['media_id'] : null;
    $contactIds = array_map('intval', $data['contact_ids'] ?? []);
    $groupIds = array_map('intval', $data['group_ids'] ?? []);
    $scheduledLocal = trim((string)($data['scheduled_at'] ?? ''));

    if ($name === '' || $body === '') {
        return ['ok' => false, 'message' => 'Name and message required'];
    }
    $unsupported = find_unsupported_variables($body);
    if ($unsupported) {
        return ['ok' => false, 'message' => 'Unsupported variables: ' . implode(', ', $unsupported)];
    }

    $scheduledUtc = null;
    if ($scheduledLocal !== '') {
        try {
            $scheduledUtc = local_to_utc(strlen($scheduledLocal) === 16 ? $scheduledLocal . ':00' : $scheduledLocal);
        } catch (Throwable $e) {
            return ['ok' => false, 'message' => 'Invalid schedule datetime'];
        }
    }

    $contacts = load_contacts_for_campaign($contactIds, $groupIds);
    if (!$contacts) {
        return ['ok' => false, 'message' => 'No active contacts selected'];
    }

    $maxAttempts = setting_get_int('max_retry_attempts', 3);
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare(
            'INSERT INTO campaigns (name, status, message_body, template_id, media_id, scheduled_at, total_count, pending_count, created_by, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP())'
        );
        $initialStatus = 'queued';
        $stmt->execute([
            $name,
            $initialStatus,
            $body,
            $templateId,
            $mediaId,
            $scheduledUtc,
            count($contacts),
            count($contacts),
            $userId,
        ]);
        $campaignId = (int)$pdo->lastInsertId();
        create_jobs_for_contacts($contacts, $body, $campaignId, $mediaId, $scheduledUtc, $maxAttempts);
        recalculate_campaign_stats($campaignId);
        $pdo->commit();
        audit_log($userId, null, 'campaign_created', 'campaign', (string)$campaignId, ['recipients' => count($contacts)]);
        return ['ok' => true, 'message' => 'Campaign created', 'id' => $campaignId];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('create_campaign: ' . $e->getMessage());
        return ['ok' => false, 'message' => 'Failed to create campaign'];
    }
}

function load_contacts_for_campaign(array $contactIds, array $groupIds): array
{
    $pdo = db();
    $map = [];

    if ($contactIds) {
        $in = implode(',', array_fill(0, count($contactIds), '?'));
        $stmt = $pdo->prepare("SELECT * FROM contacts WHERE is_active = 1 AND id IN ($in)");
        $stmt->execute($contactIds);
        foreach ($stmt->fetchAll() as $row) {
            $map[(int)$row['id']] = $row;
        }
    }

    if ($groupIds) {
        $in = implode(',', array_fill(0, count($groupIds), '?'));
        $stmt = $pdo->prepare(
            "SELECT c.* FROM contacts c
             INNER JOIN contact_group_members m ON m.contact_id = c.id
             WHERE c.is_active = 1 AND m.group_id IN ($in)"
        );
        $stmt->execute($groupIds);
        foreach ($stmt->fetchAll() as $row) {
            $map[(int)$row['id']] = $row;
        }
    }

    return array_values($map);
}

function pause_campaign(int $campaignId, int $userId): array
{
    $c = fetch_campaign($campaignId);
    if (!$c) {
        return ['ok' => false, 'message' => 'Not found'];
    }
    if (!can_transition_campaign($c['status'], 'paused')) {
        return ['ok' => false, 'message' => 'Invalid transition'];
    }
    db()->prepare("UPDATE campaigns SET status = 'paused', updated_at = UTC_TIMESTAMP() WHERE id = ?")->execute([$campaignId]);
    audit_log($userId, null, 'campaign_paused', 'campaign', (string)$campaignId, []);
    return ['ok' => true, 'message' => 'Paused'];
}

function resume_campaign(int $campaignId, int $userId): array
{
    $c = fetch_campaign($campaignId);
    if (!$c) {
        return ['ok' => false, 'message' => 'Not found'];
    }
    if (!can_transition_campaign($c['status'], 'queued')) {
        return ['ok' => false, 'message' => 'Invalid transition'];
    }
    db()->prepare("UPDATE campaigns SET status = 'queued', updated_at = UTC_TIMESTAMP() WHERE id = ?")->execute([$campaignId]);
    audit_log($userId, null, 'campaign_resumed', 'campaign', (string)$campaignId, []);
    return ['ok' => true, 'message' => 'Resumed'];
}

function cancel_campaign(int $campaignId, int $userId): array
{
    $c = fetch_campaign($campaignId);
    if (!$c) {
        return ['ok' => false, 'message' => 'Not found'];
    }
    if (!can_transition_campaign($c['status'], 'cancelled')) {
        return ['ok' => false, 'message' => 'Invalid transition'];
    }
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $pdo->prepare("UPDATE campaigns SET status = 'cancelled', completed_at = UTC_TIMESTAMP(), updated_at = UTC_TIMESTAMP() WHERE id = ?")
            ->execute([$campaignId]);
        $pdo->prepare(
            "UPDATE message_jobs SET status = 'cancelled', updated_at = UTC_TIMESTAMP()
             WHERE campaign_id = ? AND status IN ('pending','scheduled')"
        )->execute([$campaignId]);
        recalculate_campaign_stats($campaignId);
        $pdo->commit();
        audit_log($userId, null, 'campaign_cancelled', 'campaign', (string)$campaignId, []);
        return ['ok' => true, 'message' => 'Cancelled'];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        return ['ok' => false, 'message' => 'Cancel failed'];
    }
}

function fetch_campaign(int $id): ?array
{
    $stmt = db()->prepare('SELECT * FROM campaigns WHERE id = ?');
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    return $row ?: null;
}
