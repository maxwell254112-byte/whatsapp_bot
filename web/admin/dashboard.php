<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/layout.php';
require_login();

mark_stale_workers_offline();
recover_stale_jobs();
refresh_campaign_statuses();

$stats = [
    'queue' => (int)db()->query("SELECT COUNT(*) FROM message_jobs WHERE status IN ('pending','scheduled')")->fetchColumn(),
    'processing' => (int)db()->query("SELECT COUNT(*) FROM message_jobs WHERE status = 'processing'")->fetchColumn(),
    'sent_today' => (int)db()->query("SELECT COUNT(*) FROM message_jobs WHERE status = 'sent' AND sent_at > (UTC_TIMESTAMP() - INTERVAL 1 DAY)")->fetchColumn(),
    'failed_today' => (int)db()->query("SELECT COUNT(*) FROM message_jobs WHERE status = 'failed' AND updated_at > (UTC_TIMESTAMP() - INTERVAL 1 DAY)")->fetchColumn(),
];
$workers = db()->query('SELECT * FROM workers ORDER BY id DESC LIMIT 10')->fetchAll();
$campaigns = db()->query("SELECT * FROM campaigns ORDER BY id DESC LIMIT 8")->fetchAll();
$recent = db()->query('SELECT * FROM message_logs ORDER BY id DESC LIMIT 15')->fetchAll();

render_header(t('nav_dashboard'), 'dashboard');
?>
<div class="grid grid-4">
  <div class="stat"><div class="label"><?= e(t('queue')) ?></div><div class="value"><?= $stats['queue'] ?></div></div>
  <div class="stat"><div class="label"><?= e(t('processing')) ?></div><div class="value"><?= $stats['processing'] ?></div></div>
  <div class="stat"><div class="label"><?= e(t('sent_24h')) ?></div><div class="value"><?= $stats['sent_today'] ?></div></div>
  <div class="stat"><div class="label"><?= e(t('failed_24h')) ?></div><div class="value"><?= $stats['failed_today'] ?></div></div>
</div>

<div class="grid grid-2">
  <section class="panel">
    <h2><?= e(t('workers')) ?></h2>
    <table>
      <thead><tr><th><?= e(t('name')) ?></th><th><?= e(t('status')) ?></th><th><?= e(t('whatsapp')) ?></th><th><?= e(t('heartbeat')) ?></th></tr></thead>
      <tbody>
      <?php foreach ($workers as $w): ?>
        <tr>
          <td><?= e($w['name']) ?></td>
          <td><span class="badge <?= $w['status'] === 'online' ? 'badge-ok' : 'badge-warn' ?>"><?= e($w['status']) ?></span></td>
          <td><?= e($w['whatsapp_status']) ?></td>
          <td><?= e(utc_to_local($w['last_heartbeat_at'])) ?></td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$workers): ?><tr><td colspan="4"><?= e(t('no_workers')) ?></td></tr><?php endif; ?>
      </tbody>
    </table>
  </section>
  <section class="panel">
    <h2><?= e(t('recent_campaigns')) ?></h2>
    <table>
      <thead><tr><th><?= e(t('name')) ?></th><th><?= e(t('status')) ?></th><th><?= e(t('sent')) ?></th><th><?= e(t('failed')) ?></th></tr></thead>
      <tbody>
      <?php foreach ($campaigns as $c): ?>
        <tr>
          <td><a href="campaigns.php?id=<?= (int)$c['id'] ?>"><?= e($c['name']) ?></a></td>
          <td><?= e($c['status']) ?></td>
          <td><?= (int)$c['sent_count'] ?>/<?= (int)$c['total_count'] ?></td>
          <td><?= (int)$c['failed_count'] ?></td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$campaigns): ?><tr><td colspan="4"><?= e(t('no_campaigns')) ?></td></tr><?php endif; ?>
      </tbody>
    </table>
  </section>
</div>

<section class="panel">
  <h2><?= e(t('recent_activity')) ?></h2>
  <table>
    <thead><tr><th><?= e(t('time')) ?></th><th><?= e(t('event')) ?></th><th><?= e(t('status')) ?></th><th><?= e(t('message')) ?></th></tr></thead>
    <tbody>
    <?php foreach ($recent as $r): ?>
      <tr>
        <td><?= e(utc_to_local($r['created_at'])) ?></td>
        <td><?= e($r['event_type']) ?></td>
        <td><?= e($r['status']) ?></td>
        <td><?= e($r['message']) ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</section>
<?php render_footer(); ?>
