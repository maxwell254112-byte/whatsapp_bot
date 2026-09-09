<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/bootstrap.php';

$worker = authenticate_worker();
$body = json_body();

$jobId = (int)($body['job_id'] ?? 0);
$result = (string)($body['result'] ?? '');
$error = (string)($body['error'] ?? '');

if ($jobId <= 0) {
    json_error('job_id required', 422);
}

$out = report_job_result((int)$worker['id'], $jobId, $result, $error);
json_response($out['ok'], $out['message'], [
    'idempotent' => $out['idempotent'] ?? false,
], (int)($out['http'] ?? 400));
