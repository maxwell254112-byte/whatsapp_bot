<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/layout.php';

if (current_user()) {
    redirect('admin/dashboard.php');
}

$error = '';
if (request_method() === 'POST') {
    require_csrf();
    $result = login_user(post_string('username'), (string)($_POST['password'] ?? ''));
    if ($result['ok']) {
        redirect('admin/dashboard.php');
    }
    $error = $result['message'];
}

render_header(t('login'));
?>
<div class="auth-card">
  <div style="display:flex;justify-content:space-between;align-items:center;gap:0.5rem;margin-bottom:0.5rem;">
    <h1 style="margin:0;"><?= e(t('app_name')) ?></h1>
    <?= lang_switcher_html() ?>
  </div>
  <p class="disclaimer"><?= e(t('disclaimer')) ?></p>
  <div class="flash flash-success">
    <?= e(t('account')) ?>: <strong>admin</strong><br>
    <?= e(t('password')) ?>: <strong>admin123</strong>
  </div>
  <?php if ($error): ?><div class="flash flash-error"><?= e($error) ?></div><?php endif; ?>
  <form method="post" autocomplete="off">
    <?= csrf_field() ?>
    <label for="username"><?= e(t('username')) ?></label>
    <input id="username" name="username" required maxlength="64" value="admin" autofocus>
    <label for="password"><?= e(t('password')) ?></label>
    <input id="password" type="password" name="password" required value="admin123">
    <div class="btn-row">
      <button class="btn" type="submit"><?= e(t('sign_in')) ?></button>
    </div>
  </form>
</div>
<?php render_footer(); ?>
