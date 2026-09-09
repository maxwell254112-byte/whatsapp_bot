<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/layout.php';
require_permission('manage_settings');

$userId = (int)current_user()['id'];
$editable = [
    'default_country_code',
    'min_delay_seconds',
    'max_delay_seconds',
    'max_messages_per_batch',
    'max_messages_per_hour',
    'max_messages_per_day',
    'pause_between_batches_seconds',
    'max_retry_attempts',
    'worker_heartbeat_timeout_seconds',
    'stale_job_timeout_seconds',
    'session_lifetime_seconds',
    'max_upload_bytes',
];

if (request_method() === 'POST') {
    require_csrf();
    $action = post_string('action');
    if ($action === 'save_settings') {
        foreach ($editable as $key) {
            if (isset($_POST[$key])) {
                $val = trim((string)$_POST[$key]);
                if ($key !== 'default_country_code' && !ctype_digit($val)) {
                    flash_set('error', "Invalid value for {$key}");
                    redirect('admin/settings.php');
                }
                setting_set($key, $val, $userId);
            }
        }
        audit_log($userId, null, 'settings_updated', 'system_settings', '', []);
        flash_set('success', 'Settings saved');
    } elseif ($action === 'change_password') {
        $current = (string)($_POST['current_password'] ?? '');
        $new = (string)($_POST['new_password'] ?? '');
        if (strlen($new) < 10) {
            flash_set('error', 'New password must be at least 10 characters');
        } else {
            $stmt = db()->prepare('SELECT password_hash FROM users WHERE id=?');
            $stmt->execute([$userId]);
            $row = $stmt->fetch();
            if (!$row || !password_verify($current, $row['password_hash'])) {
                flash_set('error', 'Current password incorrect');
            } else {
                $hash = password_hash($new, PASSWORD_DEFAULT);
                db()->prepare('UPDATE users SET password_hash=?, updated_at=UTC_TIMESTAMP() WHERE id=?')->execute([$hash, $userId]);
                audit_log($userId, null, 'password_changed', 'user', (string)$userId, []);
                flash_set('success', 'Password changed');
            }
        }
    }
    redirect('admin/settings.php');
}

$settings = settings_all();
render_header(t('nav_settings'), 'settings');
?>
<section class="panel">
  <h2>Rate limits &amp; recovery</h2>
  <p class="help">Rate limits do not guarantee WhatsApp account safety. Server enforces hourly/daily sent caps across workers.</p>
  <form method="post">
    <?= csrf_field() ?><input type="hidden" name="action" value="save_settings">
    <?php foreach ($editable as $key): ?>
      <label><?= e($key) ?></label>
      <input name="<?= e($key) ?>" value="<?= e((string)($settings[$key] ?? '')) ?>" required>
    <?php endforeach; ?>
    <div class="btn-row"><button class="btn" type="submit">Save</button></div>
  </form>
</section>
<section class="panel">
  <h2>Change password</h2>
  <form method="post">
    <?= csrf_field() ?><input type="hidden" name="action" value="change_password">
    <label>Current password</label><input type="password" name="current_password" required>
    <label>New password</label><input type="password" name="new_password" required minlength="10">
    <div class="btn-row"><button class="btn" type="submit">Update password</button></div>
  </form>
</section>
<section class="panel">
  <h2>Disclaimer</h2>
  <p class="help"><?= e((string)($settings['disclaimer_ack'] ?? '')) ?></p>
</section>
<?php render_footer(); ?>
