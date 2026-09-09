<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/bootstrap.php';

$worker = authenticate_worker();
json_success('Authenticated', [
    'worker_id' => (int)$worker['id'],
    'name' => $worker['name'],
    'whatsapp_status' => $worker['whatsapp_status'],
    'api_version' => '1',
]);
