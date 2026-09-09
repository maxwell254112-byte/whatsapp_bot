<?php
declare(strict_types=1);

const WABOT_LANGS = ['en', 'zh'];

function current_lang(): string
{
    $lang = (string)($_SESSION['lang'] ?? 'en');
    return in_array($lang, WABOT_LANGS, true) ? $lang : 'en';
}

function set_lang(string $lang): void
{
    if (in_array($lang, WABOT_LANGS, true)) {
        $_SESSION['lang'] = $lang;
    }
}

function wabot_handle_lang_switch(): void
{
    $lang = get_string('lang');
    if ($lang !== '' && in_array($lang, WABOT_LANGS, true)) {
        set_lang($lang);
        // Redirect to same path without lang query to avoid resubmit
        $uri = (string)($_SERVER['REQUEST_URI'] ?? '/');
        $path = strtok($uri, '?') ?: $uri;
        $query = [];
        parse_str((string)parse_url($uri, PHP_URL_QUERY), $query);
        unset($query['lang']);
        $qs = http_build_query($query);
        header('Location: ' . $path . ($qs !== '' ? '?' . $qs : ''));
        exit;
    }
}

/** @return array<string, array<string, string>> */
function wabot_translations(): array
{
    static $t = null;
    if ($t !== null) {
        return $t;
    }
    $t = [
        'en' => [
            'app_name' => 'WhatsApp Bot Control Panel',
            'brand' => 'WA Control',
            'nav_dashboard' => 'Dashboard',
            'nav_contacts' => 'Contacts',
            'nav_groups' => 'Groups',
            'nav_templates' => 'Templates',
            'nav_campaigns' => 'Campaigns',
            'nav_media' => 'Media',
            'nav_workers' => 'Workers',
            'nav_logs' => 'Logs',
            'nav_settings' => 'Settings',
            'nav_logout' => 'Logout',
            'login' => 'Login',
            'username' => 'Username',
            'password' => 'Password',
            'sign_in' => 'Sign in',
            'account' => 'Account',
            'disclaimer' => 'Consent-based internal messaging only. Uses unofficial WhatsApp Web automation. Delivery is at-least-once with external uncertainty — not exactly-once. Do not use for spam or ban evasion.',
            'queue' => 'Queue',
            'processing' => 'Processing',
            'sent_24h' => 'Sent (24h)',
            'failed_24h' => 'Failed (24h)',
            'workers' => 'Workers',
            'name' => 'Name',
            'status' => 'Status',
            'whatsapp' => 'WhatsApp',
            'heartbeat' => 'Heartbeat',
            'no_workers' => 'No workers registered',
            'recent_campaigns' => 'Recent campaigns',
            'sent' => 'Sent',
            'failed' => 'Failed',
            'no_campaigns' => 'No campaigns',
            'recent_activity' => 'Recent activity',
            'time' => 'Time',
            'event' => 'Event',
            'message' => 'Message',
            'lang_en' => 'EN',
            'lang_zh' => '中文',
            'language' => 'Language',
            'err_invalid_credentials' => 'Invalid credentials',
            'err_too_many_attempts' => 'Too many attempts. Try again later.',
            'err_account_disabled' => 'Account disabled',
            'err_account_locked' => 'Account temporarily locked',
        ],
        'zh' => [
            'app_name' => 'WhatsApp 机器人控制面板',
            'brand' => 'WA 控制台',
            'nav_dashboard' => '仪表盘',
            'nav_contacts' => '联系人',
            'nav_groups' => '分组',
            'nav_templates' => '模板',
            'nav_campaigns' => '活动',
            'nav_media' => '媒体',
            'nav_workers' => 'Worker',
            'nav_logs' => '日志',
            'nav_settings' => '设置',
            'nav_logout' => '退出登录',
            'login' => '登录',
            'username' => '用户名',
            'password' => '密码',
            'sign_in' => '登录',
            'account' => '账号',
            'disclaimer' => '仅用于基于同意的内部消息。使用非官方 WhatsApp Web 自动化。投递为至少一次，存在外部不确定性——非恰好一次。请勿用于垃圾信息或规避封禁。',
            'queue' => '队列',
            'processing' => '处理中',
            'sent_24h' => '已发送（24小时）',
            'failed_24h' => '失败（24小时）',
            'workers' => 'Worker',
            'name' => '名称',
            'status' => '状态',
            'whatsapp' => 'WhatsApp',
            'heartbeat' => '心跳',
            'no_workers' => '尚未注册 Worker',
            'recent_campaigns' => '最近活动',
            'sent' => '已发送',
            'failed' => '失败',
            'no_campaigns' => '暂无活动',
            'recent_activity' => '最近动态',
            'time' => '时间',
            'event' => '事件',
            'message' => '消息',
            'lang_en' => 'EN',
            'lang_zh' => '中文',
            'language' => '语言',
            'err_invalid_credentials' => '账号或密码错误',
            'err_too_many_attempts' => '尝试次数过多，请稍后再试。',
            'err_account_disabled' => '账号已禁用',
            'err_account_locked' => '账号暂时锁定',
        ],
    ];
    return $t;
}

function t(string $key, ?string $fallback = null): string
{
    $all = wabot_translations();
    $lang = current_lang();
    return $all[$lang][$key] ?? $all['en'][$key] ?? ($fallback ?? $key);
}

function lang_switcher_html(bool $absoluteAdmin = false): string
{
    $cur = current_lang();
    $enClass = $cur === 'en' ? 'active' : '';
    $zhClass = $cur === 'zh' ? 'active' : '';
    return '<div class="lang-switch" aria-label="' . e(t('language')) . '">'
        . '<a class="' . $enClass . '" href="?lang=en">' . e(t('lang_en')) . '</a>'
        . '<a class="' . $zhClass . '" href="?lang=zh">' . e(t('lang_zh')) . '</a>'
        . '</div>';
}
