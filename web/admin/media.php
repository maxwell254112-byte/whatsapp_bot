<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/layout.php';
require_permission('manage_media');

$userId = (int)current_user()['id'];
if (request_method() === 'POST') {
    require_csrf();
    if (post_string('action') === 'upload') {
        $r = store_uploaded_media($_FILES['file'] ?? [], $userId);
        flash_set($r['ok'] ? 'success' : 'error', $r['ok'] ? 'Uploaded' : $r['message']);
    }
    redirect('admin/media.php');
}

if (get_string('download') !== '') {
    require_login();
    $media = fetch_media((int)get_string('download'));
    if (!$media) {
        http_response_code(404);
        exit('Not found');
    }
    $path = media_absolute_path($media);
    if (!is_file($path)) {
        http_response_code(404);
        exit('Missing');
    }
    header('Content-Type: ' . $media['mime_type']);
    header('Content-Disposition: attachment; filename="' . basename($media['original_name']) . '"');
    header('X-Content-Type-Options: nosniff');
    readfile($path);
    exit;
}

$files = db()->query('SELECT * FROM media_files ORDER BY id DESC LIMIT 200')->fetchAll();
render_header(t('nav_media'), 'media');
?>
<section class="panel">
  <h2>Upload</h2>
  <p class="help">Allowed: jpg, png, webp, gif, pdf, doc, docx. Stored name is randomized.</p>
  <form method="post" enctype="multipart/form-data">
    <?= csrf_field() ?><input type="hidden" name="action" value="upload">
    <input type="file" name="file" required>
    <div class="btn-row"><button class="btn" type="submit">Upload</button></div>
  </form>
</section>
<section class="panel">
  <h2>Files</h2>
  <table>
    <thead><tr><th>ID</th><th>Original</th><th>MIME</th><th>Size</th><th>Created</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($files as $f): ?>
      <tr>
        <td><?= (int)$f['id'] ?></td>
        <td><?= e($f['original_name']) ?></td>
        <td><?= e($f['mime_type']) ?></td>
        <td><?= (int)$f['size_bytes'] ?></td>
        <td><?= e(utc_to_local($f['created_at'])) ?></td>
        <td><a href="media.php?download=<?= (int)$f['id'] ?>">Download</a></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</section>
<?php render_footer(); ?>
