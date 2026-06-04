<?php
declare(strict_types=1);
require_once __DIR__ . '/db.php';

// JSON-only POST endpoint. Adds a track to a playlist.
//
// Two modes:
//   1. Existing playlist:   playlist_id=<int>, track_id=<int>
//   2. New playlist:        new_playlist_name=<string>, track_id=<int>
//
// Returns: { ok: true, playlist_id, playlist_name, position, already }
//          { ok: false, error: "..." }
//
// `already` is true if the track was already on the playlist (INSERT IGNORE
// hit the unique key) — the UI uses it to show "already in playlist" instead
// of "added".

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'POST only']);
    exit;
}

$trackId = (int)($_POST['track_id'] ?? 0);
if ($trackId <= 0) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'track_id required']);
    exit;
}

// Cheap sanity check that the track actually exists — keeps junk rows out of
// playlist_tracks if a stale page POSTs an id we've since deleted.
$chk = db()->prepare("SELECT id FROM tracks WHERE id = :id");
$chk->execute([':id' => $trackId]);
if (!$chk->fetch()) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'error' => 'track not found']);
    exit;
}

$playlistId   = (int)($_POST['playlist_id'] ?? 0);
$newName      = trim((string)($_POST['new_playlist_name'] ?? ''));

try {
    $pdo = db();
    $pdo->beginTransaction();

    // Resolve / create the playlist.
    if ($newName !== '') {
        if (strlen($newName) > 255) {
            $pdo->rollBack();
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'name too long (255 max)']);
            exit;
        }
        // Reuse an existing playlist with the same name rather than 1062-ing.
        // Unique index keeps us honest if there's a race.
        $find = $pdo->prepare("SELECT id, name FROM playlists WHERE name = :n");
        $find->execute([':n' => $newName]);
        $row = $find->fetch();
        if ($row) {
            $playlistId   = (int)$row['id'];
            $playlistName = $row['name'];
        } else {
            $ins = $pdo->prepare("INSERT INTO playlists (name) VALUES (:n)");
            $ins->execute([':n' => $newName]);
            $playlistId   = (int)$pdo->lastInsertId();
            $playlistName = $newName;
        }
    } elseif ($playlistId > 0) {
        $find = $pdo->prepare("SELECT name FROM playlists WHERE id = :id");
        $find->execute([':id' => $playlistId]);
        $row = $find->fetch();
        if (!$row) {
            $pdo->rollBack();
            http_response_code(404);
            echo json_encode(['ok' => false, 'error' => 'playlist not found']);
            exit;
        }
        $playlistName = $row['name'];
    } else {
        $pdo->rollBack();
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'playlist_id or new_playlist_name required']);
        exit;
    }

    // Next position = current MAX + 1 (0-based or 1-based both work; we go 1-based).
    $maxStmt = $pdo->prepare("SELECT COALESCE(MAX(position), 0) AS m FROM playlist_tracks WHERE playlist_id = :p");
    $maxStmt->execute([':p' => $playlistId]);
    $nextPos = (int)$maxStmt->fetch()['m'] + 1;

    // INSERT OR IGNORE so re-clicking the + button on the same track is a no-op
    // rather than a duplicate-key error. rowCount() tells us which path we took.
    $add = $pdo->prepare(
        "INSERT OR IGNORE INTO playlist_tracks (playlist_id, track_id, position)
         VALUES (:p, :t, :pos)"
    );
    $add->execute([
        ':p'   => $playlistId,
        ':t'   => $trackId,
        ':pos' => $nextPos,
    ]);
    $already = $add->rowCount() === 0;

    $pdo->commit();

    echo json_encode([
        'ok'            => true,
        'playlist_id'   => $playlistId,
        'playlist_name' => $playlistName,
        'position'      => $already ? null : $nextPos,
        'already'       => $already,
    ]);
} catch (PDOException $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'db error: ' . $e->getMessage()]);
}
