<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/bootstrap.php';

$worker = authenticate_worker();

if (($worker['whatsapp_status'] ?? '') !== 'connected') {
    json_error('WhatsApp not connected', 409, ['whatsapp_status' => $worker['whatsapp_status']]);
}

$job = claim_next_job((int)$worker['id']);
if (!$job) {
    json_success('No jobs', null, 200);
}

$mediaUrl = null;
$mediaMeta = null;
if (!empty($job['media_id'])) {
    $mediaUrl = rtrim(web_base_url(), '/') . '/api/worker/media.php?id=' . (int)$job['media_id'];
    $mediaMeta = fetch_media((int)$job['media_id']);
}

json_success('Job claimed', [
    'job' => [
        'id' => (int)$job['id'],
        'campaign_id' => $job['campaign_id'] ? (int)$job['campaign_id'] : null,
        'phone' => $job['phone_normalized'],
        'recipient_name' => $job['recipient_name'],
        'message_body' => $job['message_body'],
        'media_id' => $job['media_id'] ? (int)$job['media_id'] : null,
        'media_url' => $mediaUrl,
        'media_extension' => $mediaMeta['extension'] ?? null,
        'media_mime' => $mediaMeta['mime_type'] ?? null,
        'attempts' => (int)$job['attempts'],
        'max_attempts' => (int)$job['max_attempts'],
    ],
]);
