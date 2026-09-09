<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/layout.php';
require_permission('manage_campaigns');

$userId = (int)current_user()['id'];

if (request_method() === 'POST') {
    require_csrf();
    require_permission('send_messages');
    $action = post_string('action');
    if ($action === 'create') {
        $contactIds = array_map('intval', $_POST['contact_ids'] ?? []);
        $groupIds = array_map('intval', $_POST['group_ids'] ?? []);
        $body = (string)($_POST['message_body'] ?? '');
        $templateId = post_int('template_id');
        if ($templateId > 0) {
            $t = db()->prepare('SELECT body FROM message_templates WHERE id=?');
            $t->execute([$templateId]);
            $row = $t->fetch();
            if ($row && trim($body) === '') {
                $body = $row['body'];
            }
        }
        $result = create_campaign([
            'name' => post_string('name'),
            'message_body' => $body,
            'template_id' => $templateId ?: null,
            'media_id' => post_int('media_id') ?: null,
            'contact_ids' => $contactIds,
            'group_ids' => $groupIds,
            'scheduled_at' => post_string('scheduled_at'),
        ], $userId);
        flash_set($result['ok'] ? 'success' : 'error', $result['message']);
    } elseif ($action === 'pause') {
        $r = pause_campaign(post_int('id'), $userId);
        flash_set($r['ok'] ? 'success' : 'error', $r['message']);
    } elseif ($action === 'resume') {
        $r = resume_campaign(post_int('id'), $userId);
        flash_set($r['ok'] ? 'success' : 'error', $r['message']);
    } elseif ($action === 'cancel') {
        $r = cancel_campaign(post_int('id'), $userId);
        flash_set($r['ok'] ? 'success' : 'error', $r['message']);
    } elseif ($action === 'send_now') {
        require_permission('send_messages');
        $contactId = post_int('contact_id');
        $body = (string)($_POST['message_body'] ?? '');
        $stmt = db()->prepare('SELECT * FROM contacts WHERE id=? AND is_active=1');
        $stmt->execute([$contactId]);
        $c = $stmt->fetch();
        if (!$c || $body === '') {
            flash_set('error', 'Contact and message required');
        } else {
            $max = setting_get_int('max_retry_attempts', 3);
            create_jobs_for_contacts([$c], $body, null, post_int('media_id') ?: null, null, $max);
            audit_log($userId, null, 'send_now', 'contact', (string)$contactId, []);
            flash_set('success', 'Message queued');
        }
    }
    redirect('admin/campaigns.php');
}

refresh_campaign_statuses();
$id = get_int('id');
$detail = $id ? fetch_campaign($id) : null;
$jobs = [];
if ($detail) {
    recalculate_campaign_stats($id);
    $detail = fetch_campaign($id);
    $st = db()->prepare('SELECT * FROM message_jobs WHERE campaign_id=? ORDER BY id DESC LIMIT 200');
    $st->execute([$id]);
    $jobs = $st->fetchAll();
}

$campaigns = db()->query('SELECT * FROM campaigns ORDER BY id DESC LIMIT 100')->fetchAll();
$contacts = db()->query('SELECT id, name, phone FROM contacts WHERE is_active=1 ORDER BY name LIMIT 1000')->fetchAll();
$groups = db()->query('SELECT id, name FROM contact_groups ORDER BY name')->fetchAll();
$templates = db()->query('SELECT id, name FROM message_templates ORDER BY name')->fetchAll();
$mediaFiles = db()->query('SELECT id, original_name FROM media_files ORDER BY id DESC LIMIT 100')->fetchAll();

render_header(t('nav_campaigns'), 'campaigns');
?>
<section class="panel">
  <h2>Create campaign</h2>
  <p class="help">Large sends should be consent-based. Confirm recipients before queueing.</p>
  <form method="post" onsubmit="return confirm('Queue this campaign? Confirm consent-based recipients.');">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="create">
    <label>Name</label><input name="name" required maxlength="160">
    <label>Template (optional)</label>
    <select name="template_id">
      <option value="0">— none —</option>
      <?php foreach ($templates as $t): ?><option value="<?= (int)$t['id'] ?>"><?= e($t['name']) ?></option><?php endforeach; ?>
    </select>
    <label>Message body</label>
    <textarea name="message_body" placeholder="Hello {{name}}..."></textarea>
    <label>Media (optional)</label>
    <select name="media_id">
      <option value="0">— none —</option>
      <?php foreach ($mediaFiles as $m): ?><option value="<?= (int)$m['id'] ?>"><?= e($m['original_name']) ?></option><?php endforeach; ?>
    </select>
    <label>Schedule (app timezone <?= e((string)app_config('app', 'timezone')) ?>, leave empty = now)</label>
    <input type="datetime-local" name="scheduled_at">
    <div class="grid grid-2">
      <div>
        <label>Contacts</label>
        <select name="contact_ids[]" multiple size="8">
          <?php foreach ($contacts as $c): ?>
            <option value="<?= (int)$c['id'] ?>"><?= e($c['name'] . ' · ' . $c['phone']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div>
        <label>Groups</label>
        <select name="group_ids[]" multiple size="8">
          <?php foreach ($groups as $g): ?>
            <option value="<?= (int)$g['id'] ?>"><?= e($g['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
    </div>
    <div class="btn-row"><button class="btn" type="submit">Create &amp; queue</button></div>
  </form>
</section>

<section class="panel">
  <h2>Send now (single)</h2>
  <form method="post">
    <?= csrf_field() ?><input type="hidden" name="action" value="send_now">
    <label>Contact</label>
    <select name="contact_id" required>
      <?php foreach ($contacts as $c): ?>
        <option value="<?= (int)$c['id'] ?>"><?= e($c['name'] . ' · ' . $c['phone']) ?></option>
      <?php endforeach; ?>
    </select>
    <label>Message</label><textarea name="message_body" required></textarea>
    <div class="btn-row"><button class="btn" type="submit">Queue message</button></div>
  </form>
</section>

<section class="panel">
  <h2>Campaign list</h2>
  <table>
    <thead><tr><th>ID</th><th>Name</th><th>Status</th><th>Progress</th><th>Scheduled</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($campaigns as $c): ?>
      <tr>
        <td><a href="campaigns.php?id=<?= (int)$c['id'] ?>"><?= (int)$c['id'] ?></a></td>
        <td><?= e($c['name']) ?></td>
        <td><span class="badge"><?= e($c['status']) ?></span></td>
        <td><?= (int)$c['sent_count'] ?> sent / <?= (int)$c['failed_count'] ?> fail / <?= (int)$c['pending_count'] ?> pend / <?= (int)$c['total_count'] ?> total</td>
        <td><?= e(utc_to_local($c['scheduled_at'])) ?></td>
        <td class="btn-row">
          <?php if (in_array($c['status'], ['queued','running'], true)): ?>
          <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="pause"><input type="hidden" name="id" value="<?= (int)$c['id'] ?>"><button class="btn btn-secondary" type="submit">Pause</button></form>
          <?php endif; ?>
          <?php if ($c['status'] === 'paused'): ?>
          <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="resume"><input type="hidden" name="id" value="<?= (int)$c['id'] ?>"><button class="btn btn-secondary" type="submit">Resume</button></form>
          <?php endif; ?>
          <?php if (!in_array($c['status'], ['completed','cancelled'], true)): ?>
          <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="cancel"><input type="hidden" name="id" value="<?= (int)$c['id'] ?>"><button class="btn btn-danger" data-confirm="Cancel campaign?" type="submit">Cancel</button></form>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</section>

<?php if ($detail): ?>
<section class="panel">
  <h2>Campaign #<?= (int)$detail['id'] ?> · <?= e($detail['name']) ?></h2>
  <p class="help">Status: <?= e($detail['status']) ?> · Jobs below (max 200)</p>
  <table>
    <thead><tr><th>Job</th><th>Phone</th><th>Status</th><th>Attempts</th><th>Error</th><th>Updated</th></tr></thead>
    <tbody>
    <?php foreach ($jobs as $j): ?>
      <tr>
        <td><?= (int)$j['id'] ?></td>
        <td><?= e($j['phone_normalized']) ?></td>
        <td><?= e($j['status']) ?></td>
        <td><?= (int)$j['attempts'] ?>/<?= (int)$j['max_attempts'] ?></td>
        <td><?= e((string)$j['last_error']) ?></td>
        <td><?= e(utc_to_local($j['updated_at'])) ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</section>
<?php endif; ?>
<?php render_footer(); ?>
