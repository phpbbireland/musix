<?php
/**
 * JSON POST endpoint that updates a single track's metadata in the DB.
 *
 * Fields: track_name, artist (name), album (name), year, track_no, genre (name),
 * duration (m:ss, h:mm:ss, or plain seconds).
 *
 * Semantics: each edit reassigns this single track's artist_id / album_id /
 * genre_id, upserting the artist/album/genre rows as needed via the unique
 * key INSERT … ON DUPLICATE KEY pattern. Other tracks of the same album are
 * not affected. If artist or album changes, the old rows stay in place even
 * if they end up with zero tracks (orphan-row GC is a separate concern).
 *
 * Does NOT rewrite tags back to the file in v1 — DB only.
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
if ($trackId <= 0) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Missing track id']);
    exit;
}

$trackName  = trim((string)($_POST['track_name'] ?? ''));
$artistName = trim((string)($_POST['artist']     ?? ''));
$albumName  = trim((string)($_POST['album']      ?? ''));
$genreName  = trim((string)($_POST['genre']      ?? ''));
$yearRaw     = trim((string)($_POST['year']       ?? ''));
$trackNoRaw  = trim((string)($_POST['track_no']   ?? ''));
$durationRaw = trim((string)($_POST['duration']   ?? ''));

if ($trackName === '' || $artistName === '' || $albumName === '') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Track, artist and album are required']);
    exit;
}

$year = null;
if ($yearRaw !== '') {
    $y = (int)$yearRaw;
    if ($y >= 1 && $y <= 9999) {
        $year = $y;
    } else {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Year must be 1-9999 or blank']);
        exit;
    }
}

$trackNo = null;
if ($trackNoRaw !== '') {
    $n = (int)$trackNoRaw;
    if ($n >= 1 && $n <= 999) $trackNo = $n;
}

// Duration accepts plain seconds ("225"), m:ss ("3:45"), or h:mm:ss
// ("1:02:03"). Blank means "leave NULL". Cap at 24 hours just to keep the
// column honest — anything longer is almost certainly a typo.
$duration = null;
if ($durationRaw !== '') {
    if (preg_match('/^\d+$/', $durationRaw)) {
        $duration = (int)$durationRaw;
    } elseif (preg_match('/^(\d+):(\d{1,2})$/', $durationRaw, $m)) {
        $secs = (int)$m[2];
        if ($secs > 59) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'Seconds must be 0-59']);
            exit;
        }
        $duration = ((int)$m[1]) * 60 + $secs;
    } elseif (preg_match('/^(\d+):(\d{1,2}):(\d{1,2})$/', $durationRaw, $m)) {
        $mins = (int)$m[2];
        $secs = (int)$m[3];
        if ($mins > 59 || $secs > 59) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'Minutes and seconds must be 0-59']);
            exit;
        }
        $duration = ((int)$m[1]) * 3600 + $mins * 60 + $secs;
    } else {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Duration must be m:ss, h:mm:ss, or seconds']);
        exit;
    }
    if ($duration < 0 || $duration > 86400) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Duration out of range (0-24h)']);
        exit;
    }
}

// Cap to match column widths.
$trackName  = mb_substr($trackName,  0, 255);
$artistName = mb_substr($artistName, 0, 255);
$albumName  = mb_substr($albumName,  0, 255);
$genreName  = mb_substr($genreName,  0, 100);

$db = db();
try {
    $db->beginTransaction();

    // Verify the track exists and grab the current album_id so the client
    // can decide whether the row should be removed from the current page.
    $cur = $db->prepare("SELECT album_id FROM tracks WHERE id = :id");
    $cur->execute([':id' => $trackId]);
    $row = $cur->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        $db->rollBack();
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'Track not found']);
        exit;
    }
    $previousAlbumId = (int)$row['album_id'];

    // Upsert artist by name (unique key on artists.name). SQLite's ON CONFLICT
    // does not refresh last_insert_rowid(), so insert-or-ignore then SELECT.
    $upArtist = $db->prepare(
        "INSERT INTO artists (name) VALUES (:n) ON CONFLICT(name) DO NOTHING"
    );
    $upArtist->execute([':n' => $artistName]);
    $selArtist = $db->prepare("SELECT id FROM artists WHERE name = :n");
    $selArtist->execute([':n' => $artistName]);
    $artistId = (int)$selArtist->fetchColumn();

    // Upsert album by (artist_id, album_name) — that's the unique key.
    $upAlbum = $db->prepare(
        "INSERT INTO albums (artist_id, album_name) VALUES (:a, :n)
         ON CONFLICT(artist_id, album_name) DO NOTHING"
    );
    $upAlbum->execute([':a' => $artistId, ':n' => $albumName]);
    $selAlbum = $db->prepare(
        "SELECT id FROM albums WHERE artist_id = :a AND album_name = :n"
    );
    $selAlbum->execute([':a' => $artistId, ':n' => $albumName]);
    $albumId = (int)$selAlbum->fetchColumn();

    // Upsert genre — null when blank.
    $genreId = null;
    if ($genreName !== '') {
        $upGenre = $db->prepare(
            "INSERT INTO genres (name) VALUES (:n) ON CONFLICT(name) DO NOTHING"
        );
        $upGenre->execute([':n' => $genreName]);
        $selGenre = $db->prepare("SELECT id FROM genres WHERE name = :n");
        $selGenre->execute([':n' => $genreName]);
        $genreId = (int)$selGenre->fetchColumn();
        if ($genreId <= 0) $genreId = null;
    }

    $upTrack = $db->prepare(
        "UPDATE tracks
            SET track_name = :tn,
                track_no   = :no,
                year       = :yr,
                duration   = :du,
                artist_id  = :ar,
                album_id   = :al,
                genre_id   = :ge
          WHERE id = :id"
    );
    $upTrack->execute([
        ':tn' => $trackName,
        ':no' => $trackNo,
        ':yr' => $year,
        ':du' => $duration,
        ':ar' => $artistId,
        ':al' => $albumId,
        ':ge' => $genreId,
        ':id' => $trackId,
    ]);

    $db->commit();

    echo json_encode([
        'ok' => true,
        'track' => [
            'id'         => $trackId,
            'track_name' => $trackName,
            'track_no'   => $trackNo,
            'year'       => $year,
            'duration'   => $duration,
            'artist_id'  => $artistId,
            'artist'     => $artistName,
            'album_id'   => $albumId,
            'album'      => $albumName,
            'genre_id'   => $genreId,
            'genre'      => $genreName !== '' ? $genreName : null,
        ],
        'moved_album' => $albumId !== $previousAlbumId,
    ]);
} catch (Throwable $e) {
    if ($db->inTransaction()) $db->rollBack();
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
