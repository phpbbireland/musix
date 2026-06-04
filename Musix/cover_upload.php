<?php
/**
 * JSON POST endpoint for manually uploading an album cover.
 *
 * Inputs:  album_id (int), cover (uploaded file).
 * Output:  { ok: bool, cover_url: 'cover.php?album_id=N&v=…' } | { ok: false, error: '…' }
 *
 * Behaviour:
 *   - Verifies the album exists.
 *   - Validates the upload: size cap, mime sniffed via finfo, extension drawn
 *     from the same jpg/png/gif/webp allow-list embedded extraction uses.
 *   - Removes any prior cache/covers/<album_id>.* (any extension) so an old
 *     png isn't left behind when the user uploads a jpg.
 *   - Moves the upload to cache/covers/<album_id>.<ext>, chmod 0664.
 *   - Updates albums.cover_art to the absolute path. cover.php already
 *     whitelists cache/covers/, so it serves immediately.
 *
 * The response includes cover_url with a cache-bust ?v=<mtime> param so the
 * browser fetches the new file even though the album_id is unchanged.
 */
declare(strict_types=1);
require_once __DIR__ . '/db.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'POST required']);
    exit;
}

$albumId = (int)($_POST['album_id'] ?? 0);
if ($albumId <= 0) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Missing album_id']);
    exit;
}

if (!isset($_FILES['cover']) || !is_array($_FILES['cover'])) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'No file uploaded']);
    exit;
}

$f = $_FILES['cover'];
if ($f['error'] !== UPLOAD_ERR_OK) {
    // Map the standard PHP upload error codes to readable strings.
    $msgs = [
        UPLOAD_ERR_INI_SIZE   => 'File exceeds upload_max_filesize',
        UPLOAD_ERR_FORM_SIZE  => 'File exceeds form MAX_FILE_SIZE',
        UPLOAD_ERR_PARTIAL    => 'Upload was interrupted',
        UPLOAD_ERR_NO_FILE    => 'No file was uploaded',
        UPLOAD_ERR_NO_TMP_DIR => 'Server has no tmp dir',
        UPLOAD_ERR_CANT_WRITE => 'Server could not write the upload',
        UPLOAD_ERR_EXTENSION  => 'A PHP extension blocked the upload',
    ];
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => $msgs[$f['error']] ?? 'Upload failed']);
    exit;
}

// 8 MB cap. Album art is small; anything bigger is almost certainly a mistake.
$MAX_SIZE = 8 * 1024 * 1024;
if ((int)$f['size'] > $MAX_SIZE) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'File too large (max 8 MB)']);
    exit;
}

// Verify the album exists before we touch the filesystem.
$alb = db()->prepare("SELECT id FROM albums WHERE id = :id");
$alb->execute([':id' => $albumId]);
if (!$alb->fetch()) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'error' => 'Album not found']);
    exit;
}

// Sniff the actual mime — don't trust the browser-supplied $f['type'].
$mime = '';
if (function_exists('finfo_open')) {
    $fi = finfo_open(FILEINFO_MIME_TYPE);
    if ($fi) {
        $mime = (string)finfo_file($fi, $f['tmp_name']);
        finfo_close($fi);
    }
}
if ($mime === '') {
    // Last-ditch fallback to mime_content_type for hosts without fileinfo.
    if (function_exists('mime_content_type')) $mime = (string)mime_content_type($f['tmp_name']);
}

$mimeToExt = [
    'image/jpeg' => 'jpg',
    'image/jpg'  => 'jpg',
    'image/pjpeg'=> 'jpg',
    'image/png'  => 'png',
    'image/gif'  => 'gif',
    'image/webp' => 'webp',
];
if (!isset($mimeToExt[$mime])) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => "Unsupported image type: " . ($mime ?: 'unknown')]);
    exit;
}
$ext = $mimeToExt[$mime];

// cache/covers must exist + be writable. The extract_covers / scan flows
// already create it, but a fresh install hitting upload first needs lazy mkdir.
$coversDir = __DIR__ . '/cache/covers';
if (!is_dir($coversDir)) {
    if (!@mkdir($coversDir, 0775, true) && !is_dir($coversDir)) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'Could not create cache/covers']);
        exit;
    }
}
if (!is_writable($coversDir)) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'cache/covers is not writable by ' . (function_exists('posix_getpwuid') ? (posix_getpwuid(posix_geteuid())['name'] ?? 'php') : 'php')]);
    exit;
}

// Wipe any prior cover for this album so we don't leave a stale .png behind
// when the user uploads a .jpg over the top.
foreach (glob($coversDir . '/' . $albumId . '.*') ?: [] as $old) {
    @unlink($old);
}

$dest = $coversDir . '/' . $albumId . '.' . $ext;

// move_uploaded_file enforces "this came from THIS request's upload" — safer
// than rename() against tmp_name path forgery.
if (!move_uploaded_file($f['tmp_name'], $dest)) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Could not save uploaded file']);
    exit;
}
@chmod($dest, 0664);

try {
    $up = db()->prepare("UPDATE albums SET cover_art = :p WHERE id = :id");
    $up->execute([':p' => $dest, ':id' => $albumId]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'DB update failed: ' . $e->getMessage()]);
    exit;
}

// Cache-bust on filemtime so the browser refetches the new image even though
// the cover.php URL hasn't changed.
$mt = @filemtime($dest) ?: time();
echo json_encode([
    'ok'        => true,
    'cover_url' => 'cover.php?album_id=' . $albumId . '&v=' . $mt,
    'mime'      => $mime,
    'ext'       => $ext,
    'size'      => (int)$f['size'],
]);
