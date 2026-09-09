<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/layout.php';
require_permission('manage_contacts');

if (request_method() === 'POST') {
    require_csrf();
    $action = post_string('action');
    $userId = (int)current_user()['id'];

    if ($action === 'create' || $action === 'update') {
        $name = post_string('name');
        $phoneRaw = post_string('phone');
        $company = post_string('company');
        $notes = post_string('notes');
        $norm = normalize_phone($phoneRaw);
        if (!$norm['ok']) {
            flash_set('error', $norm['error']);
            redirect('admin/contacts.php');
        }
        try {
            if ($action === 'create') {
                $stmt = db()->prepare(
                    'INSERT INTO contacts (name, phone, phone_normalized, company, notes, is_active, created_by, created_at, updated_at)
                     VALUES (?, ?, ?, ?, ?, 1, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP())'
                );
                $stmt->execute([$name, $norm['phone'], $norm['normalized'], $company, $notes, $userId]);
                audit_log($userId, null, 'contact_created', 'contact', (string)db()->lastInsertId(), []);
                flash_set('success', 'Contact created');
            } else {
                $id = post_int('id');
                $stmt = db()->prepare(
                    'UPDATE contacts SET name=?, phone=?, phone_normalized=?, company=?, notes=?, updated_at=UTC_TIMESTAMP() WHERE id=?'
                );
                $stmt->execute([$name, $norm['phone'], $norm['normalized'], $company, $notes, $id]);
                audit_log($userId, null, 'contact_updated', 'contact', (string)$id, []);
                flash_set('success', 'Contact updated');
            }
        } catch (PDOException $e) {
            flash_set('error', ((int)$e->errorInfo[1] === 1062) ? 'Duplicate phone number' : 'Save failed');
        }
    } elseif ($action === 'toggle') {
        $id = post_int('id');
        db()->prepare('UPDATE contacts SET is_active = IF(is_active=1,0,1), updated_at=UTC_TIMESTAMP() WHERE id=?')->execute([$id]);
        flash_set('success', 'Contact status updated');
    } elseif ($action === 'delete') {
        $id = post_int('id');
        db()->prepare('DELETE FROM contacts WHERE id=?')->execute([$id]);
        audit_log($userId, null, 'contact_deleted', 'contact', (string)$id, []);
        flash_set('success', 'Contact deleted');
    } elseif ($action === 'import_csv') {
        if (!isset($_FILES['csv']) || $_FILES['csv']['error'] !== UPLOAD_ERR_OK) {
            flash_set('error', 'CSV upload failed');
            redirect('admin/contacts.php');
        }
        $fh = fopen($_FILES['csv']['tmp_name'], 'rb');
        if (!$fh) {
            flash_set('error', 'Cannot read CSV');
            redirect('admin/contacts.php');
        }
        $header = fgetcsv($fh);
        if (!$header) {
            fclose($fh);
            flash_set('error', 'Empty CSV');
            redirect('admin/contacts.php');
        }
        $header = array_map(static fn($h) => strtolower(trim((string)$h)), $header);
        $idx = [
            'name' => array_search('name', $header, true),
            'phone' => array_search('phone', $header, true),
            'company' => array_search('company', $header, true),
        ];
        if ($idx['phone'] === false) {
            fclose($fh);
            flash_set('error', 'CSV must include phone column');
            redirect('admin/contacts.php');
        }
        $ok = 0; $fail = 0;
        $pdo = db();
        $pdo->beginTransaction();
        try {
            $ins = $pdo->prepare(
                'INSERT INTO contacts (name, phone, phone_normalized, company, notes, is_active, created_by, created_at, updated_at)
                 VALUES (?, ?, ?, ?, \'\', 1, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP())
                 ON DUPLICATE KEY UPDATE name=VALUES(name), phone=VALUES(phone), company=VALUES(company), updated_at=UTC_TIMESTAMP()'
            );
            while (($row = fgetcsv($fh)) !== false) {
                if (count($row) === 1 && trim((string)$row[0]) === '') {
                    continue;
                }
                $phoneRaw = (string)($row[$idx['phone']] ?? '');
                // Strip CSV formula injection prefixes on export later; sanitize name/company
                $name = sanitize_csv_field((string)($idx['name'] !== false ? ($row[$idx['name']] ?? '') : ''));
                $company = sanitize_csv_field((string)($idx['company'] !== false ? ($row[$idx['company']] ?? '') : ''));
                if (strlen($name) > 160 || strlen($company) > 160 || strlen($phoneRaw) > 40) {
                    $fail++;
                    continue;
                }
                $norm = normalize_phone($phoneRaw);
                if (!$norm['ok']) {
                    $fail++;
                    continue;
                }
                $ins->execute([$name, $norm['phone'], $norm['normalized'], $company, $userId]);
                $ok++;
            }
            $pdo->commit();
            audit_log($userId, null, 'contacts_imported', 'contact', '', ['ok' => $ok, 'fail' => $fail]);
            flash_set('success', "Imported/updated {$ok}, skipped {$fail}");
        } catch (Throwable $e) {
            $pdo->rollBack();
            flash_set('error', 'Import failed');
        }
        fclose($fh);
    }
    redirect('admin/contacts.php');
}

if (get_string('export') === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="contacts.csv"');
    $out = fopen('php://output', 'wb');
    fputcsv($out, ['name', 'phone', 'company', 'is_active']);
    foreach (db()->query('SELECT name, phone, company, is_active FROM contacts ORDER BY id') as $row) {
        $name = $row['name'];
        if ($name !== '' && in_array($name[0], ['=', '+', '-', '@'], true)) {
            $name = "'" . $name;
        }
        fputcsv($out, [$name, $row['phone'], $row['company'], $row['is_active']]);
    }
    fclose($out);
    exit;
}

$q = get_string('q');
if ($q !== '') {
    $stmt = db()->prepare('SELECT * FROM contacts WHERE name LIKE ? OR phone LIKE ? OR company LIKE ? ORDER BY id DESC LIMIT 500');
    $like = '%' . $q . '%';
    $stmt->execute([$like, $like, $like]);
    $contacts = $stmt->fetchAll();
} else {
    $contacts = db()->query('SELECT * FROM contacts ORDER BY id DESC LIMIT 500')->fetchAll();
}

render_header(t('nav_contacts'), 'contacts');
?>
<section class="panel">
  <h2>Add contact</h2>
  <form method="post" class="grid grid-2">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="create">
    <div>
      <label>Name</label><input name="name" maxlength="160">
      <label>Phone</label><input name="phone" required maxlength="32" placeholder="+60123456789">
    </div>
    <div>
      <label>Company</label><input name="company" maxlength="160">
      <label>Notes</label><textarea name="notes"></textarea>
    </div>
    <div class="btn-row"><button class="btn" type="submit">Save</button></div>
  </form>
</section>

<section class="panel">
  <h2>CSV import / export</h2>
  <p class="help">Columns: name, phone, company. Formula-like values are neutralized on export.</p>
  <form method="post" enctype="multipart/form-data" class="btn-row">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="import_csv">
    <input type="file" name="csv" accept=".csv,text/csv" required>
    <button class="btn" type="submit">Import</button>
    <a class="btn btn-secondary" href="contacts.php?export=csv">Export</a>
  </form>
</section>

<section class="panel">
  <h2>Directory</h2>
  <form method="get" class="btn-row">
    <input name="q" value="<?= e($q) ?>" placeholder="Search..." style="max-width:280px">
    <button class="btn btn-secondary" type="submit">Search</button>
  </form>
  <table>
    <thead><tr><th>Name</th><th>Phone</th><th>Company</th><th>Active</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($contacts as $c): ?>
      <tr>
        <td><?= e($c['name']) ?></td>
        <td><?= e($c['phone']) ?></td>
        <td><?= e($c['company']) ?></td>
        <td><?= (int)$c['is_active'] ? 'yes' : 'no' ?></td>
        <td class="btn-row">
          <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="toggle"><input type="hidden" name="id" value="<?= (int)$c['id'] ?>"><button class="btn btn-secondary" type="submit">Toggle</button></form>
          <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int)$c['id'] ?>"><button class="btn btn-danger" type="submit" data-confirm="Delete contact?">Delete</button></form>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</section>
<?php render_footer(); ?>
