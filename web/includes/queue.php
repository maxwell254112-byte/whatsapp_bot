<?php
declare(strict_types=1);

/**
 * Queue operations with atomic claiming and stale recovery.
 * Delivery semantics: at-least-once. Never claim exactly-once WhatsApp delivery.
 */

function recover_stale_jobs(): int
{
    $jobTimeout = setting_get_int('stale_job_timeout_seconds', 600);
    $hbTimeout = setting_get_int('worker_heartbeat_timeout_seconds', 90);

    // Requeue processing jobs whose claim is old AND owning worker heartbeat is stale/missing.
    // Ambiguous delivery window is logged; job may be retried (at-least-once).
    $sql = "
        UPDATE message_jobs j
        LEFT JOIN workers w ON w.id = j.worker_id
        SET j.status = IF(j.attempts >= j.max_attempts, 'failed', 'pending'),
            j.worker_id = NULL,
            j.claimed_at = NULL,
            j.last_error = IF(
              j.attempts >= j.max_attempts,
              CONCAT(COALESCE(j.last_error, ''), ' | stale recovery: max attempts'),
              CONCAT('stale recovery after worker silence; possible duplicate if WhatsApp already accepted')
            ),
            j.updated_at = UTC_TIMESTAMP()
        WHERE j.status = 'processing'
          AND j.claimed_at IS NOT NULL
          AND j.claimed_at < (UTC_TIMESTAMP() - INTERVAL {$jobTimeout} SECOND)
          AND (
            w.id IS NULL
            OR w.last_heartbeat_at IS NULL
            OR w.last_heartbeat_at < (UTC_TIMESTAMP() - INTERVAL {$hbTimeout} SECOND)
            OR w.is_enabled = 0
            OR w.status = 'disabled'
          )
    ";
    // Intervals are integers from settings — safe for interpolation after cast.
    $count = db()->exec($sql);
    return (int)$count;
}

function activate_due_scheduled_jobs(): int
{
    $stmt = db()->prepare(
        "UPDATE message_jobs
         SET status = 'pending', updated_at = UTC_TIMESTAMP()
         WHERE status = 'scheduled'
           AND scheduled_at IS NOT NULL
           AND scheduled_at <= UTC_TIMESTAMP()"
    );
    $stmt->execute();
    return $stmt->rowCount();
}

/**
 * Atomically claim next available job for a worker.
 * Uses transaction + row lock to prevent double claim.
 */
function claim_next_job(int $workerId): ?array
{
    recover_stale_jobs();
    activate_due_scheduled_jobs();
    refresh_campaign_statuses();

    // Server-side hourly/daily rate limits across all workers
    if (!server_rate_limit_allows()) {
        return null;
    }

    $pdo = db();
    $pdo->beginTransaction();
    try {
        $sel = $pdo->prepare(
            "SELECT j.*
             FROM message_jobs j
             LEFT JOIN campaigns c ON c.id = j.campaign_id
             WHERE j.status = 'pending'
               AND (j.scheduled_at IS NULL OR j.scheduled_at <= UTC_TIMESTAMP())
               AND (c.id IS NULL OR c.status IN ('queued','running'))
             ORDER BY j.id ASC
             LIMIT 1
             FOR UPDATE SKIP LOCKED"
        );
        $sel->execute();
        $job = $sel->fetch();
        if (!$job) {
            $pdo->commit();
            return null;
        }

        $upd = $pdo->prepare(
            "UPDATE message_jobs
             SET status = 'processing',
                 worker_id = ?,
                 claimed_at = UTC_TIMESTAMP(),
                 attempts = attempts + 1,
                 updated_at = UTC_TIMESTAMP()
             WHERE id = ? AND status = 'pending'"
        );
        $upd->execute([$workerId, (int)$job['id']]);
        if ($upd->rowCount() !== 1) {
            $pdo->rollBack();
            return null;
        }

        $w = $pdo->prepare('UPDATE workers SET current_job_id = ?, updated_at = UTC_TIMESTAMP() WHERE id = ?');
        $w->execute([(int)$job['id'], $workerId]);

        if (!empty($job['campaign_id'])) {
            $pdo->prepare(
                "UPDATE campaigns SET status = IF(status = 'queued', 'running', status),
                 started_at = IF(started_at IS NULL, UTC_TIMESTAMP(), started_at),
                 updated_at = UTC_TIMESTAMP()
                 WHERE id = ?"
            )->execute([(int)$job['campaign_id']]);
        }

        $pdo->commit();

        $fresh = $pdo->prepare('SELECT * FROM message_jobs WHERE id = ?');
        $fresh->execute([(int)$job['id']]);
        $claimed = $fresh->fetch() ?: null;

        if ($claimed) {
            message_log((int)$claimed['id'], $claimed['campaign_id'] ? (int)$claimed['campaign_id'] : null, $workerId, (string)$claimed['phone_normalized'], 'claimed', 'processing', 'Job claimed');
            audit_log(null, $workerId, 'job_claimed', 'message_job', (string)$claimed['id'], []);
            recalculate_campaign_stats($claimed['campaign_id'] ? (int)$claimed['campaign_id'] : null);
        }
        return $claimed;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

function server_rate_limit_allows(): bool
{
    $perHour = setting_get_int('max_messages_per_hour', 60);
    $perDay = setting_get_int('max_messages_per_day', 400);

    $h = db()->query("SELECT COUNT(*) FROM message_jobs WHERE status = 'sent' AND sent_at > (UTC_TIMESTAMP() - INTERVAL 1 HOUR)")->fetchColumn();
    if ((int)$h >= $perHour) {
        return false;
    }
    $d = db()->query("SELECT COUNT(*) FROM message_jobs WHERE status = 'sent' AND sent_at > (UTC_TIMESTAMP() - INTERVAL 1 DAY)")->fetchColumn();
    return (int)$d < $perDay;
}

/**
 * Report job result. Enforces worker ownership. Idempotent for duplicate reports.
 */
function report_job_result(int $workerId, int $jobId, string $result, string $errorMessage = ''): array
{
    $result = strtolower($result);
    if (!in_array($result, ['sent', 'failed'], true)) {
        return ['ok' => false, 'message' => 'Invalid result', 'http' => 422];
    }

    $pdo = db();
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare('SELECT * FROM message_jobs WHERE id = ? FOR UPDATE');
        $stmt->execute([$jobId]);
        $job = $stmt->fetch();
        if (!$job) {
            $pdo->rollBack();
            return ['ok' => false, 'message' => 'Job not found', 'http' => 404];
        }

        // Idempotent: already terminal with same outcome
        if ($job['status'] === 'sent' && $result === 'sent') {
            $pdo->commit();
            return ['ok' => true, 'message' => 'Already reported sent', 'http' => 200, 'idempotent' => true];
        }
        if ($job['status'] === 'failed' && $result === 'failed' && (int)$job['attempts'] >= (int)$job['max_attempts']) {
            $pdo->commit();
            return ['ok' => true, 'message' => 'Already reported failed', 'http' => 200, 'idempotent' => true];
        }
        if ($job['status'] === 'cancelled') {
            $pdo->rollBack();
            return ['ok' => false, 'message' => 'Job cancelled', 'http' => 409];
        }

        if ($job['status'] !== 'processing') {
            $pdo->rollBack();
            return ['ok' => false, 'message' => 'Job not in processing state', 'http' => 409];
        }

        if ((int)$job['worker_id'] !== $workerId) {
            $pdo->rollBack();
            return ['ok' => false, 'message' => 'Worker does not own this job', 'http' => 403];
        }

        if ($result === 'sent') {
            $pdo->prepare(
                "UPDATE message_jobs
                 SET status = 'sent', sent_at = UTC_TIMESTAMP(), last_error = NULL, updated_at = UTC_TIMESTAMP()
                 WHERE id = ? AND status = 'processing' AND worker_id = ?"
            )->execute([$jobId, $workerId]);
            message_log($jobId, $job['campaign_id'] ? (int)$job['campaign_id'] : null, $workerId, (string)$job['phone_normalized'], 'sent', 'sent', 'Reported sent');
        } else {
            $attempts = (int)$job['attempts'];
            $max = (int)$job['max_attempts'];
            $err = substr($errorMessage !== '' ? $errorMessage : 'Failed', 0, 2000);
            if ($attempts >= $max) {
                $pdo->prepare(
                    "UPDATE message_jobs
                     SET status = 'failed', last_error = ?, worker_id = NULL, claimed_at = NULL, updated_at = UTC_TIMESTAMP()
                     WHERE id = ? AND status = 'processing' AND worker_id = ?"
                )->execute([$err, $jobId, $workerId]);
                message_log($jobId, $job['campaign_id'] ? (int)$job['campaign_id'] : null, $workerId, (string)$job['phone_normalized'], 'failed_permanent', 'failed', $err);
            } else {
                $pdo->prepare(
                    "UPDATE message_jobs
                     SET status = 'pending', last_error = ?, worker_id = NULL, claimed_at = NULL, updated_at = UTC_TIMESTAMP()
                     WHERE id = ? AND status = 'processing' AND worker_id = ?"
                )->execute([$err, $jobId, $workerId]);
                message_log($jobId, $job['campaign_id'] ? (int)$job['campaign_id'] : null, $workerId, (string)$job['phone_normalized'], 'retry_queued', 'pending', $err);
            }
        }

        $pdo->prepare('UPDATE workers SET current_job_id = NULL, updated_at = UTC_TIMESTAMP() WHERE id = ? AND current_job_id = ?')
            ->execute([$workerId, $jobId]);

        $pdo->commit();

        if (!empty($job['campaign_id'])) {
            recalculate_campaign_stats((int)$job['campaign_id']);
            refresh_campaign_statuses();
        }

        return ['ok' => true, 'message' => 'OK', 'http' => 200];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

function create_jobs_for_contacts(array $contacts, string $messageBody, ?int $campaignId, ?int $mediaId, ?string $scheduledAtUtc, int $maxAttempts): int
{
    $pdo = db();
    $ins = $pdo->prepare(
        'INSERT INTO message_jobs
         (campaign_id, contact_id, phone, phone_normalized, recipient_name, message_body, media_id, status, scheduled_at, max_attempts, created_at, updated_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP())'
    );
    $count = 0;
    foreach ($contacts as $c) {
        $body = render_template($messageBody, [
            'name' => (string)($c['name'] ?? ''),
            'phone' => (string)($c['phone'] ?? ''),
            'company' => (string)($c['company'] ?? ''),
        ]);
        $status = ($scheduledAtUtc !== null && $scheduledAtUtc > now_utc()) ? 'scheduled' : 'pending';
        $ins->execute([
            $campaignId,
            $c['id'] ?? null,
            $c['phone'],
            $c['phone_normalized'],
            $c['name'] ?? '',
            $body,
            $mediaId,
            $status,
            $scheduledAtUtc,
            $maxAttempts,
        ]);
        $count++;
    }
    return $count;
}
