<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/layout.php';
require_permission('manage_workers');

$userId = (int)current_user()['id'];
$newToken = null;

if (request_method() === 'POST') {
    require_csrf();
    $action = post_string('action');
    if ($action === 'register') {
        $r = register_worker(post_string('name'), $userId);
        flash_set($r['ok'] ? 'success' : 'error', $r['message']);
        if ($r['ok']) {
            $newToken = $r['token'];
            $_SESSION['_shown_worker_token'] = $r['token'];
        }
    } elseif ($action === 'regenerate') {
        $r = regenerate_worker_token(post_int('id'), $userId);
        flash_set($r['ok'] ? 'success' : 'error', $r['message']);
        if ($r['ok']) {
            $newToken = $r['token'];
            $_SESSION['_shown_worker_token'] = $r['token'];
        }
    } elseif ($action === 'disable') {
        db()->prepare("UPDATE workers SET is_enabled=0, status='disabled', updated_at=UTC_TIMESTAMP() WHERE id=?")->execute([post_int('id')]);
        audit_log($userId, post_int('id'), 'worker_disabled', 'worker', (string)post_int('id'), []);
        flash_set('success', 'Worker disabled');
        redirect('admin/workers.php');
    } elseif ($action === 'enable') {
        db()->prepare("UPDATE workers SET is_enabled=1, status='offline', updated_at=UTC_TIMESTAMP() WHERE id=?")->execute([post_int('id')]);
        audit_log($userId, post_int('id'), 'worker_enabled', 'worker', (string)post_int('id'), []);
        flash_set('success', 'Worker enabled');
        redirect('admin/workers.php');
    } else {
        redirect('admin/workers.php');
    }
}

mark_stale_workers_offline();
$workers = db()->query('SELECT * FROM workers ORDER BY id DESC')->fetchAll();
if ($newToken === null && !empty($_SESSION['_shown_worker_token'])) {
    $newToken = $_SESSION['_shown_worker_token'];
    unset($_SESSION['_shown_worker_token']);
}

render_header(t('nav_workers'), 'workers');
?>
<?php if ($newToken): ?>
<section class="panel">
  <h2>Worker token (shown once)</h2>
  <p class="help">Copy into worker config.ini now. It is stored hashed and will not be displayed again.</p>
  <div class="token-once"><?= e($newToken) ?></div>
</section>
<?php endif; ?>

<section class="panel">
  <h2>Register worker</h2>
  <form method="post">
    <?= csrf_field() ?><input type="hidden" name="action" value="register">
    <label>Name</label><input name="name" required maxlength="120" placeholder="office-pc-1">
    <div class="btn-row"><button class="btn" type="submit">Register</button></div>
  </form>
</section>

<section class="panel">
  <h2>Workers</h2>
  <table>
    <thead>
      <tr>
        <th>ID</th><th>Name</th><th>Status</th><th>WhatsApp</th><th>Heartbeat</th>
        <th>Browser</th><th>Version</th><th>Token prefix</th><th>Job</th><th></th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($workers as $w): ?>
      <tr>
        <td><?= (int)$w['id'] ?></td>
        <td><?= e($w['name']) ?></td>
        <td><span class="badge <?= $w['status']==='online'?'badge-ok':($w['status']==='disabled'?'badge-bad':'badge-warn') ?>"><?= e($w['status']) ?></span></td>
        <td><?= e($w['whatsapp_status']) ?></td>
        <td><?= e(utc_to_local($w['last_heartbeat_at'])) ?></td>
        <td><?= e((string)$w['browser']) ?></td>
        <td><?= e((string)$w['worker_version']) ?></td>
        <td><?= e($w['token_prefix']) ?>…</td>
        <td><?= e((string)$w['current_job_id']) ?></td>
        <td class="btn-row">
          <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="regenerate"><input type="hidden" name="id" value="<?= (int)$w['id'] ?>">
            <button class="btn btn-secondary" data-confirm="Regenerate token? Old token stops working." type="submit">Regen token</button></form>
          <?php if ((int)$w['is_enabled']): ?>
          <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="disable"><input type="hidden" name="id" value="<?= (int)$w['id'] ?>">
            <button class="btn btn-danger" type="submit">Disable</button></form>
          <?php else: ?>
          <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="enable"><input type="hidden" name="id" value="<?= (int)$w['id'] ?>">
            <button class="btn" type="submit">Enable</button></form>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</section>
<?php render_footer(); ?>
