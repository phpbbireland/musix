<?php
declare(strict_types=1);
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/id3.php';

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) { http_response_code(400); exit; }

// Block excluded (soft-deleted) tracks from streaming — if the user
// "deleted" it, refusing to serve it matches the rest of the UI.
$stmt = db()->prepare("SELECT file_path FROM tracks WHERE id = :id AND excluded = 0");
$stmt->execute([':id' => $id]);
$path = $stmt->fetchColumn();

if (!$path || !is_file($path) || !is_readable($path)) {
    http_response_code(404);
    exit('Track file not found.');
}

// Constrain to library_path so we don't serve arbitrary files
$libraryPath = (string)cfg('library_path', '');
if ($libraryPath !== '') {
    $real = realpath($path);
    $libReal = realpath($libraryPath);
    if (!$real || !$libReal || !str_starts_with($real, rtrim($libReal, '/') . '/')) {
        http_response_code(403);
        exit('Forbidden.');
    }
}

$ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
$mime = match ($ext) {
    'mp3'        => 'audio/mpeg',
    'flac'       => 'audio/flac',
    'ogg', 'oga' => 'audio/ogg',
    'wav'        => 'audio/wav',
    'm4a'        => 'audio/mp4',
    default      => 'application/octet-stream',
};

$size = filesize($path);

// FLAC fix-up: some FLACs in the wild have an ID3v2 tag prepended (Mp3tag
// and friends like to do this even though it's not in the FLAC spec). Most
// browser decoders refuse anything that doesn't start with 'fLaC' magic at
// byte 0 — symptoms: duration column shows empty AND the track won't play.
// We strip the prefix on the wire so the browser sees a clean stream. The
// Range / Content-Length we expose to the browser are measured against the
// "virtual" stripped file, but we add $prefix when seeking the real one.
$prefix = $ext === 'flac' ? \Musix\Id3\flac_id3v2_prefix_size($path) : 0;
if ($prefix >= $size) $prefix = 0;       // defensive — bad header, serve as-is
$effectiveSize = $size - $prefix;

$start = 0;
$end = $effectiveSize - 1;
$status = 200;

if (isset($_SERVER['HTTP_RANGE']) && preg_match('/bytes=(\d+)-(\d*)/', $_SERVER['HTTP_RANGE'], $m)) {
    $start = (int)$m[1];
    if ($m[2] !== '') $end = (int)$m[2];
    if ($start > $end || $end >= $effectiveSize) {
        header("Content-Range: bytes */$effectiveSize");
        http_response_code(416);
        exit;
    }
    $status = 206;
}

http_response_code($status);
header("Content-Type: $mime");
header("Accept-Ranges: bytes");
header("Content-Length: " . ($end - $start + 1));
if ($status === 206) header("Content-Range: bytes $start-$end/$effectiveSize");
header("Cache-Control: public, max-age=3600");

$fh = fopen($path, 'rb');
if (!$fh) { http_response_code(500); exit; }
// Map the browser's "virtual" offset back onto the real file by adding the
// prefix length we trimmed off.
fseek($fh, $start + $prefix);

$bufSize = 8192;
$remaining = $end - $start + 1;
while (!feof($fh) && $remaining > 0 && !connection_aborted()) {
    $chunk = fread($fh, min($bufSize, $remaining));
    if ($chunk === false) break;
    echo $chunk;
    @flush();
    $remaining -= strlen($chunk);
}
fclose($fh);
