<?php
/**
 * JSON POST endpoint for removing a track.
 *
 * Inputs:  id (int), mode ("soft" or "hard")
 *
 * Soft delete (default): UPDATE tracks SET excluded = 1. The row stays in
 * the DB so play history, playlist links, etc. survive — every list view
 * filters on excluded = 0 so the user just stops seeing it.
 *
 * Hard delete: removes the audio file from disk (after a containment check
 * against the configured library_path so a malformed file_path can't trick
 * us into unlinking arbitrary files), then DELETEs the tracks row. ON
 * DELETE CASCADE on playlist_tracks takes care of the join row.
 */
declare(strict_types=1);
require_once __DIR__ . '/db.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'POST required']);
    exit;
}

$trackId = (int)($_POST['id'] ?? 0);
$mode    = (string)($_POST['mode'] ?? 'soft');
if ($trackId <= 0) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Missing track id']);
    exit;
}
if (!in_array($mode, ['soft', 'hard'], true)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'mode must be soft or hard']);
    exit;
}

$db = db();
try {
    $sel = $db->prepare("SELECT id, file_path FROM tracks WHERE id = :id");
    $sel->execute([':id' => $trackId]);
    $row = $sel->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'Track not found']);
        exit;
    }

    if ($mode === 'soft') {
        $up = $db->prepare("UPDATE tracks SET excluded = 1 WHERE id = :id");
        $up->execute([':id' => $trackId]);
        echo json_encode(['ok' => true, 'mode' => 'soft', 'id' => $trackId]);
        exit;
    }

    // Hard delete: file off disk + row out of DB.
    $file       = (string)$row['file_path'];
    $real       = $file !== '' ? realpath($file) : false;
    $libRoot    = realpath((string)cfg('library_path', ''));
    $fileGone   = false;
    $fileError  = null;

    // Containment: only unlink files that actually live inside the configured
    // library. A blank or missing-on-disk file_path is fine — we just skip
    // the unlink and proceed with the DB delete.
    if ($real !== false && is_file($real)) {
        if (!$libRoot || !str_starts_with($real, rtrim($libRoot, '/') . '/')) {
            http_response_code(403);
            echo json_encode(['ok' => false, 'error' => 'File path outside library — refusing to delete']);
            exit;
        }
        if (@unlink($real)) {
            $fileGone = true;
        } else {
            // Don't abort — let the user know the file lingered but still
            // remove the DB row so the library view is clean.
            $fileError = 'Could not unlink file (permissions?)';
        }
    }

    $del = $db->prepare("DELETE FROM tracks WHERE id = :id");
    $del->execute([':id' => $trackId]);

    echo json_encode([
        'ok'         => true,
        'mode'       => 'hard',
        'id'         => $trackId,
        'file_gone'  => $fileGone,
        'file_error' => $fileError,
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
