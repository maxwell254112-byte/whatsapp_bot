<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/layout.php';
require_permission('manage_templates');

$userId = (int)current_user()['id'];
if (request_method() === 'POST') {
    require_csrf();
    $action = post_string('action');
    if ($action === 'save') {
        $id = post_int('id');
        $name = post_string('name');
        $body = (string)($_POST['body'] ?? '');
        $bad = find_unsupported_variables($body);
        if ($name === '' || $body === '') {
            flash_set('error', 'Name and body required');
        } elseif ($bad) {
            flash_set('error', 'Unsupported variables: ' . implode(', ', $bad));
        } elseif ($id > 0) {
            db()->prepare('UPDATE message_templates SET name=?, body=?, updated_at=UTC_TIMESTAMP() WHERE id=?')->execute([$name, $body, $id]);
            flash_set('success', 'Template updated');
        } else {
            try {
                db()->prepare('INSERT INTO message_templates (name, body, created_by, created_at, updated_at) VALUES (?, ?, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP())')
                    ->execute([$name, $body, $userId]);
                flash_set('success', 'Template created');
            } catch (PDOException $e) {
                flash_set('error', 'Name already exists');
            }
        }
    } elseif ($action === 'delete') {
        db()->prepare('DELETE FROM message_templates WHERE id=?')->execute([post_int('id')]);
        flash_set('success', 'Deleted');
    } elseif ($action === 'preview') {
        $preview = render_template((string)($_POST['body'] ?? ''), [
            'name' => post_string('preview_name', 'Ali'),
            'phone' => post_string('preview_phone', '+60123456789'),
            'company' => post_string('preview_company', 'Acme'),
        ]);
        flash_set('success', 'Preview: ' . $preview);
    }
    redirect('admin/templates.php');
}

$templates = db()->query('SELECT * FROM message_templates ORDER BY id DESC')->fetchAll();
render_header(t('nav_templates'), 'templates');
?>
<section class="panel">
  <h2>Create / edit</h2>
  <p class="help">Supported variables: {{name}}, {{phone}}, {{company}}. No code execution.</p>
  <form method="post">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="save">
    <input type="hidden" name="id" value="0">
    <label>Name</label><input name="name" required maxlength="120">
    <label>Body</label><textarea name="body" required placeholder="Hello {{name}}, ..."></textarea>
    <div class="btn-row"><button class="btn" type="submit">Save template</button></div>
  </form>
</section>
<section class="panel">
  <h2>Existing</h2>
  <table>
    <thead><tr><th>Name</th><th>Body</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($templates as $t): ?>
      <tr>
        <td><?= e($t['name']) ?></td>
        <td><pre style="white-space:pre-wrap;margin:0;font:inherit"><?= e($t['body']) ?></pre></td>
        <td>
          <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int)$t['id'] ?>">
          <button class="btn btn-danger" data-confirm="Delete?" type="submit">Delete</button></form>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</section>
<?php render_footer(); ?>
