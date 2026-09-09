<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/layout.php';
require_permission('view_logs');

$page = max(1, get_int('page', 1));
$per = 50;
$offset = ($page - 1) * $per;
$type = get_string('type', 'message');

if ($type === 'audit') {
    $total = (int)db()->query('SELECT COUNT(*) FROM audit_logs')->fetchColumn();
    $stmt = db()->prepare('SELECT * FROM audit_logs ORDER BY id DESC LIMIT ? OFFSET ?');
    $stmt->bindValue(1, $per, PDO::PARAM_INT);
    $stmt->bindValue(2, $offset, PDO::PARAM_INT);
    $stmt->execute();
    $rows = $stmt->fetchAll();
} else {
    $total = (int)db()->query('SELECT COUNT(*) FROM message_logs')->fetchColumn();
    $stmt = db()->prepare('SELECT * FROM message_logs ORDER BY id DESC LIMIT ? OFFSET ?');
    $stmt->bindValue(1, $per, PDO::PARAM_INT);
    $stmt->bindValue(2, $offset, PDO::PARAM_INT);
    $stmt->execute();
    $rows = $stmt->fetchAll();
}
$pages = max(1, (int)ceil($total / $per));

render_header(t('nav_logs'), 'logs');
?>
<section class="panel">
  <div class="btn-row">
    <a class="btn <?= $type==='message'?'':'btn-secondary' ?>" href="logs.php?type=message">Message logs</a>
    <a class="btn <?= $type==='audit'?'':'btn-secondary' ?>" href="logs.php?type=audit">Audit logs</a>
  </div>
  <table>
    <?php if ($type === 'audit'): ?>
      <thead><tr><th>Time</th><th>Action</th><th>Entity</th><th>User</th><th>Worker</th><th>IP</th></tr></thead>
      <tbody>
      <?php foreach ($rows as $r): ?>
        <tr>
          <td><?= e(utc_to_local($r['created_at'])) ?></td>
          <td><?= e($r['action']) ?></td>
          <td><?= e($r['entity_type'] . '#' . $r['entity_id']) ?></td>
          <td><?= e((string)$r['user_id']) ?></td>
          <td><?= e((string)$r['worker_id']) ?></td>
          <td><?= e($r['ip_address']) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    <?php else: ?>
      <thead><tr><th>Time</th><th>Job</th><th>Event</th><th>Status</th><th>Phone</th><th>Message</th></tr></thead>
      <tbody>
      <?php foreach ($rows as $r): ?>
        <tr>
          <td><?= e(utc_to_local($r['created_at'])) ?></td>
          <td><?= e((string)$r['job_id']) ?></td>
          <td><?= e($r['event_type']) ?></td>
          <td><?= e($r['status']) ?></td>
          <td><?= e($r['phone_normalized']) ?></td>
          <td><?= e($r['message']) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    <?php endif; ?>
  </table>
  <p class="help">Page <?= $page ?> / <?= $pages ?> (<?= $total ?> rows)
    <?php if ($page > 1): ?> · <a href="logs.php?type=<?= e($type) ?>&page=<?= $page-1 ?>">Prev</a><?php endif; ?>
    <?php if ($page < $pages): ?> · <a href="logs.php?type=<?= e($type) ?>&page=<?= $page+1 ?>">Next</a><?php endif; ?>
  </p>
</section>
<?php render_footer(); ?>
