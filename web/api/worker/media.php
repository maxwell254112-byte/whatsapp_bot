<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/bootstrap.php';

$worker = authenticate_worker();
$mediaId = get_int('id');
if ($mediaId <= 0) {
    json_error('id required', 422);
}

$media = fetch_media($mediaId);
if (!$media) {
    json_error('Not found', 404);
}

$path = media_absolute_path($media);
if (!is_file($path)) {
    json_error('File missing', 404);
}

// Only allow download if worker currently owns a processing job that references this media,
// or any enabled worker may download referenced media for assigned jobs.
$stmt = db()->prepare(
    "SELECT id FROM message_jobs
     WHERE media_id = ? AND worker_id = ? AND status = 'processing'
     LIMIT 1"
);
$stmt->execute([$mediaId, (int)$worker['id']]);
if (!$stmt->fetch()) {
    json_error('Forbidden', 403);
}

header('Content-Type: ' . $media['mime_type']);
header('Content-Length: ' . (string)filesize($path));
header('Content-Disposition: attachment; filename="' . basename((string)$media['stored_name']) . '"');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');
readfile($path);
exit;
