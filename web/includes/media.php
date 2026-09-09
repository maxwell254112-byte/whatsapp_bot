<?php
declare(strict_types=1);

const ALLOWED_MEDIA = [
    'image/jpeg' => 'jpg',
    'image/png' => 'png',
    'image/webp' => 'webp',
    'image/gif' => 'gif',
    'application/pdf' => 'pdf',
    'application/msword' => 'doc',
    'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
];

function media_upload_dir(): string
{
    $rel = (string)app_config('app', 'uploads_dir', 'uploads/media');
    $path = WABOT_ROOT . '/' . trim($rel, '/');
    if (!is_dir($path)) {
        mkdir($path, 0750, true);
    }
    return $path;
}

function store_uploaded_media(array $file, int $userId): array
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        return ['ok' => false, 'message' => 'Upload failed'];
    }
    $max = (int)app_config('app', 'max_upload_bytes', setting_get_int('max_upload_bytes', 10485760));
    if (($file['size'] ?? 0) <= 0 || $file['size'] > $max) {
        return ['ok' => false, 'message' => 'Invalid file size'];
    }

    $tmp = (string)$file['tmp_name'];
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = (string)$finfo->file($tmp);
    if (!isset(ALLOWED_MEDIA[$mime])) {
        return ['ok' => false, 'message' => 'MIME type not allowed'];
    }
    $ext = ALLOWED_MEDIA[$mime];
    $orig = basename((string)$file['name']);
    $origExt = strtolower(pathinfo($orig, PATHINFO_EXTENSION));
    if ($origExt !== $ext && !($mime === 'image/jpeg' && in_array($origExt, ['jpg', 'jpeg'], true))) {
        return ['ok' => false, 'message' => 'Extension mismatch'];
    }

    $stored = bin2hex(random_bytes(16)) . '.' . $ext;
    $dest = media_upload_dir() . DIRECTORY_SEPARATOR . $stored;
    if (!move_uploaded_file($tmp, $dest)) {
        return ['ok' => false, 'message' => 'Could not store file'];
    }
    @chmod($dest, 0640);

    // Re-validate MIME after move
    $mime2 = (string)$finfo->file($dest);
    if ($mime2 !== $mime) {
        @unlink($dest);
        return ['ok' => false, 'message' => 'Post-store MIME validation failed'];
    }

    $checksum = hash_file('sha256', $dest) ?: '';
    $stmt = db()->prepare(
        'INSERT INTO media_files (original_name, stored_name, mime_type, extension, size_bytes, checksum_sha256, uploaded_by, created_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP())'
    );
    $stmt->execute([$orig, $stored, $mime, $ext, (int)$file['size'], $checksum, $userId]);
    $id = (int)db()->lastInsertId();
    audit_log($userId, null, 'media_uploaded', 'media', (string)$id, ['mime' => $mime]);
    return ['ok' => true, 'id' => $id, 'stored_name' => $stored];
}

function media_absolute_path(array $media): string
{
    $name = basename((string)$media['stored_name']);
    return media_upload_dir() . DIRECTORY_SEPARATOR . $name;
}

function fetch_media(int $id): ?array
{
    $stmt = db()->prepare('SELECT * FROM media_files WHERE id = ?');
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    return $row ?: null;
}
