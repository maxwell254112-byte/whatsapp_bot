<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/layout.php';
require_permission('manage_contacts');

$userId = (int)current_user()['id'];
if (request_method() === 'POST') {
    require_csrf();
    $action = post_string('action');
    if ($action === 'create') {
        $name = post_string('name');
        if ($name === '') {
            flash_set('error', 'Name required');
        } else {
            try {
                db()->prepare('INSERT INTO contact_groups (name, description, created_by, created_at, updated_at) VALUES (?, ?, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP())')
                    ->execute([$name, post_string('description'), $userId]);
                flash_set('success', 'Group created');
            } catch (PDOException $e) {
                flash_set('error', 'Group name exists');
            }
        }
    } elseif ($action === 'delete') {
        db()->prepare('DELETE FROM contact_groups WHERE id=?')->execute([post_int('id')]);
        flash_set('success', 'Group deleted');
    } elseif ($action === 'add_member') {
        $gid = post_int('group_id');
        $cid = post_int('contact_id');
        try {
            db()->prepare('INSERT INTO contact_group_members (group_id, contact_id) VALUES (?, ?)')->execute([$gid, $cid]);
            flash_set('success', 'Member added');
        } catch (PDOException $e) {
            flash_set('error', 'Already a member or invalid IDs');
        }
    } elseif ($action === 'remove_member') {
        db()->prepare('DELETE FROM contact_group_members WHERE group_id=? AND contact_id=?')
            ->execute([post_int('group_id'), post_int('contact_id')]);
        flash_set('success', 'Member removed');
    }
    redirect('admin/groups.php');
}

$groups = db()->query('SELECT g.*, (SELECT COUNT(*) FROM contact_group_members m WHERE m.group_id=g.id) AS member_count FROM contact_groups g ORDER BY g.id DESC')->fetchAll();
$contacts = db()->query('SELECT id, name, phone FROM contacts WHERE is_active=1 ORDER BY name')->fetchAll();
$viewId = get_int('id');
$members = [];
if ($viewId) {
    $stmt = db()->prepare(
        'SELECT c.* FROM contacts c INNER JOIN contact_group_members m ON m.contact_id=c.id WHERE m.group_id=? ORDER BY c.name'
    );
    $stmt->execute([$viewId]);
    $members = $stmt->fetchAll();
}

render_header(t('nav_groups'), 'groups');
?>
<section class="panel">
  <h2>Create group</h2>
  <form method="post">
    <?= csrf_field() ?><input type="hidden" name="action" value="create">
    <label>Name</label><input name="name" required maxlength="120">
    <label>Description</label><input name="description" maxlength="255">
    <div class="btn-row"><button class="btn" type="submit">Create</button></div>
  </form>
</section>
<section class="panel">
  <h2>Groups</h2>
  <table>
    <thead><tr><th>Name</th><th>Members</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($groups as $g): ?>
      <tr>
        <td><a href="groups.php?id=<?= (int)$g['id'] ?>"><?= e($g['name']) ?></a></td>
        <td><?= (int)$g['member_count'] ?></td>
        <td>
          <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int)$g['id'] ?>">
          <button class="btn btn-danger" data-confirm="Delete group?" type="submit">Delete</button></form>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</section>
<?php if ($viewId): ?>
<section class="panel">
  <h2>Members</h2>
  <form method="post" class="btn-row">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="add_member">
    <input type="hidden" name="group_id" value="<?= $viewId ?>">
    <select name="contact_id" required>
      <?php foreach ($contacts as $c): ?>
        <option value="<?= (int)$c['id'] ?>"><?= e($c['name'] . ' · ' . $c['phone']) ?></option>
      <?php endforeach; ?>
    </select>
    <button class="btn" type="submit">Add</button>
  </form>
  <table>
    <thead><tr><th>Name</th><th>Phone</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($members as $m): ?>
      <tr>
        <td><?= e($m['name']) ?></td><td><?= e($m['phone']) ?></td>
        <td>
          <form method="post"><?= csrf_field() ?>
            <input type="hidden" name="action" value="remove_member">
            <input type="hidden" name="group_id" value="<?= $viewId ?>">
            <input type="hidden" name="contact_id" value="<?= (int)$m['id'] ?>">
            <button class="btn btn-secondary" type="submit">Remove</button>
          </form>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</section>
<?php endif; ?>
<?php render_footer(); ?>
