<?php
declare(strict_types=1);
require_once __DIR__ . '/db.php';

$albumId = (int)($_GET['album_id'] ?? 0);
if ($albumId <= 0) { http_response_code(400); exit('Bad album_id'); }

$stmt = db()->prepare("SELECT cover_art FROM albums WHERE id = :id");
$stmt->execute([':id' => $albumId]);
$path = (string)$stmt->fetchColumn();

if ($path === '' || !is_file($path) || !is_readable($path)) {
    http_response_code(404);
    exit('No cover');
}

// Containment: cover_art must live inside library_path, our own cache/covers/
// folder, OR the XAMPP web copy's cache/covers/. The two installs share one
// database, so cover_art may point at art the web copy cached earlier.
$real       = realpath($path);
$libRoot    = realpath((string)cfg('library_path', ''));
$cacheRoot  = realpath(__DIR__ . '/cache/covers');
$xamppCache = realpath('/opt/lampp/htdocs/musix/cache/covers');

$ok = false;
foreach ([$libRoot, $cacheRoot, $xamppCache] as $root) {
    if ($real && $root && str_starts_with($real, rtrim($root, '/') . '/')) { $ok = true; break; }
}
if (!$ok) { http_response_code(403); exit('Forbidden'); }

$ext  = strtolower(pathinfo($real, PATHINFO_EXTENSION));
$mime = match ($ext) {
    'jpg', 'jpeg' => 'image/jpeg',
    'png'         => 'image/png',
    'gif'         => 'image/gif',
    'webp'        => 'image/webp',
    default       => 'application/octet-stream',
};

$mtime = (int)@filemtime($real) ?: time();
$etag  = '"' . md5($real . ':' . $mtime . ':' . filesize($real)) . '"';

// Conditional GET — browsers will cache covers aggressively if we set this up right.
$ifNoneMatch = $_SERVER['HTTP_IF_NONE_MATCH'] ?? '';
if ($ifNoneMatch && trim($ifNoneMatch) === $etag) {
    http_response_code(304);
    header('ETag: ' . $etag);
    exit;
}

header('Content-Type: ' . $mime);
header('Content-Length: ' . filesize($real));
header('ETag: ' . $etag);
header('Last-Modified: ' . gmdate('D, d M Y H:i:s', $mtime) . ' GMT');
header('Cache-Control: public, max-age=86400');
readfile($real);
