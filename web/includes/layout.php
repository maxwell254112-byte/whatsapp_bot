<?php
declare(strict_types=1);

function render_header(string $title, string $active = ''): void
{
    $user = current_user();
    $flash = flash_get();
    $lang = current_lang();
    $cssHref = asset_url('assets/css/app.css');
    $jsHref = asset_url('assets/js/app.js');
    $GLOBALS['_wabot_js_href'] = $jsHref;

    $adminBase = asset_url('admin');
    ?>
<!DOCTYPE html>
<html lang="<?= e($lang === 'zh' ? 'zh-CN' : 'en') ?>">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <base href="<?= e(rtrim(web_base_url(), '/') . '/') ?>">
  <title><?= e($title) ?> · <?= e(t('app_name')) ?></title>
  <link rel="stylesheet" href="<?= e($cssHref) ?>">
</head>
<body>
<?php if ($user): ?>
<aside class="sidebar">
  <div class="brand"><?= e(t('brand')) ?></div>
  <?= lang_switcher_html() ?>
  <nav>
    <a class="<?= $active === 'dashboard' ? 'active' : '' ?>" href="<?= e($adminBase) ?>/dashboard.php"><?= e(t('nav_dashboard')) ?></a>
    <a class="<?= $active === 'contacts' ? 'active' : '' ?>" href="<?= e($adminBase) ?>/contacts.php"><?= e(t('nav_contacts')) ?></a>
    <a class="<?= $active === 'groups' ? 'active' : '' ?>" href="<?= e($adminBase) ?>/groups.php"><?= e(t('nav_groups')) ?></a>
    <a class="<?= $active === 'templates' ? 'active' : '' ?>" href="<?= e($adminBase) ?>/templates.php"><?= e(t('nav_templates')) ?></a>
    <a class="<?= $active === 'campaigns' ? 'active' : '' ?>" href="<?= e($adminBase) ?>/campaigns.php"><?= e(t('nav_campaigns')) ?></a>
    <a class="<?= $active === 'media' ? 'active' : '' ?>" href="<?= e($adminBase) ?>/media.php"><?= e(t('nav_media')) ?></a>
    <a class="<?= $active === 'workers' ? 'active' : '' ?>" href="<?= e($adminBase) ?>/workers.php"><?= e(t('nav_workers')) ?></a>
    <a class="<?= $active === 'logs' ? 'active' : '' ?>" href="<?= e($adminBase) ?>/logs.php"><?= e(t('nav_logs')) ?></a>
    <a class="<?= $active === 'settings' ? 'active' : '' ?>" href="<?= e($adminBase) ?>/settings.php"><?= e(t('nav_settings')) ?></a>
    <a href="<?= e($adminBase) ?>/logout.php"><?= e(t('nav_logout')) ?></a>
  </nav>
  <div class="sidebar-user"><?= e($user['display_name'] ?: $user['username']) ?> · <?= e($user['role']) ?></div>
</aside>
<main class="content">
  <header class="page-header">
    <h1><?= e($title) ?></h1>
  </header>
  <?php if ($flash): ?>
    <div class="flash flash-<?= e($flash['type']) ?>"><?= e($flash['message']) ?></div>
  <?php endif; ?>
<?php else: ?>
<main class="auth-wrap">
<?php endif;
}

function render_footer(): void
{
    $jsHref = (string)($GLOBALS['_wabot_js_href'] ?? asset_url('assets/js/app.js'));
    ?>
</main>
<script src="<?= e($jsHref) ?>"></script>
</body>
</html>
    <?php
}
