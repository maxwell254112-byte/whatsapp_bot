<?php
declare(strict_types=1);

/**
 * Bootstrap: load config, set timezone, start secure session.
 */

const WABOT_ROOT = __DIR__ . '/..';
const WABOT_INCLUDES = __DIR__;

$configPath = WABOT_ROOT . '/config.local.ini';
if (!is_file($configPath)) {
    $configPath = WABOT_ROOT . '/config.example.ini';
}
if (!is_file($configPath)) {
    http_response_code(500);
    exit('Missing configuration. Copy config.example.ini to config.local.ini');
}

/** @var array<string, array<string, string>> $CONFIG */
$CONFIG = parse_ini_file($configPath, true, INI_SCANNER_TYPED);
if ($CONFIG === false) {
    http_response_code(500);
    exit('Invalid configuration file');
}

$appEnv = (string)($CONFIG['app']['env'] ?? 'development');
$displayErrors = (int)($CONFIG['app']['display_errors'] ?? 0);
ini_set('display_errors', $displayErrors && $appEnv !== 'production' ? '1' : '0');
ini_set('log_errors', (string)((int)($CONFIG['app']['log_errors'] ?? 1)));
error_reporting(E_ALL);

$timezone = (string)($CONFIG['app']['timezone'] ?? 'Asia/Kuala_Lumpur');
date_default_timezone_set($timezone);

require_once WABOT_INCLUDES . '/db.php';
require_once WABOT_INCLUDES . '/helpers.php';
require_once WABOT_INCLUDES . '/auth.php';
require_once WABOT_INCLUDES . '/csrf.php';
require_once WABOT_INCLUDES . '/permissions.php';
require_once WABOT_INCLUDES . '/audit.php';
require_once WABOT_INCLUDES . '/phone.php';
require_once WABOT_INCLUDES . '/template.php';
require_once WABOT_INCLUDES . '/settings.php';
require_once WABOT_INCLUDES . '/queue.php';
require_once WABOT_INCLUDES . '/campaigns.php';
require_once WABOT_INCLUDES . '/media.php';
require_once WABOT_INCLUDES . '/response.php';
require_once WABOT_INCLUDES . '/worker_auth.php';
require_once WABOT_INCLUDES . '/i18n.php';

wabot_session_start($CONFIG);
wabot_handle_lang_switch();

