<?php
declare(strict_types=1);
require_once __DIR__ . '/db.php';

// JSON POST endpoint. Removes a track from a playlist.
// Required: playlist_id=<int>, track_id=<int>
// Returns:  { ok: true, removed: <0|1> } or { ok: false, error: "..." }
//
// We don't renumber the surviving rows' position values — gaps in the
// sequence don't break sort order, and avoiding the rewrite keeps the
// endpoint cheap and lock-free.

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'POST only']);
    exit;
}

$playlistId = (int)($_POST['playlist_id'] ?? 0);
$trackId    = (int)($_POST['track_id']    ?? 0);

if ($playlistId <= 0 || $trackId <= 0) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'playlist_id and track_id required']);
    exit;
}

try {
    $stmt = db()->prepare(
        "DELETE FROM playlist_tracks
         WHERE playlist_id = :p AND track_id = :t"
    );
    $stmt->execute([':p' => $playlistId, ':t' => $trackId]);

    echo json_encode([
        'ok'      => true,
        'removed' => $stmt->rowCount(),
    ]);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'db error: ' . $e->getMessage()]);
}
