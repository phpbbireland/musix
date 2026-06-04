<?php
/**
 * Walk every album whose cover_art is NULL/empty, try to extract embedded
 * album art from one of its tracks, save to cache/covers/<album_id>.<ext>,
 * and write that path back to albums.cover_art.
 *
 * Idempotent — re-runs skip albums that already have a real cover file
 * (the SELECT below filters NULL/empty, and if a previous run left a
 * non-existent path on disk we'd want to fix it; we treat that as
 * "still empty" via a defensive is_file check below).
 *
 * Tries up to 3 tracks per album: embedded art is usually consistent
 * across an album, but a couple of tracks at the front might be tagged
 * differently or corrupted.
 */
declare(strict_types=1);
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/id3.php';

set_time_limit(0);
ignore_user_abort(false);

$page  = 'config';
$title = 'Musix — Extract embedded covers';

$run = isset($_GET['run']) && $_GET['run'] === '1';
$cacheDir = __DIR__ . '/cache/covers';

// How many tracks to try per album before giving up.
const TRY_TRACKS_PER_ALBUM = 3;

$todoStmt = db()->prepare(
    "SELECT id, album_name FROM albums
     WHERE cover_art IS NULL OR cover_art = ''
     ORDER BY id"
);
$todoStmt->execute();
$todoCount = $todoStmt->rowCount();

require __DIR__ . '/header.php';
?>

<h1>Extract embedded album art</h1>

<?php if (!$run): ?>
    <p>This walks every album whose cover slot is empty, opens up to
       <?= TRY_TRACKS_PER_ALBUM ?> of its tracks, and tries to extract embedded art
       (ID3v2 APIC for MP3, PICTURE blocks for FLAC,
       <code>METADATA_BLOCK_PICTURE</code> for OGG). Found images are saved
       to <code>cache/covers/&lt;album_id&gt;.&lt;ext&gt;</code> and the path
       is written into <code>albums.cover_art</code>.</p>
    <p><strong><?= (int)$todoCount ?></strong> album<?= $todoCount === 1 ? '' : 's' ?> currently without cover art.</p>
    <p>Albums that already have a cover are skipped — the run is idempotent.</p>
    <p>
        <a class="btn" href="extract_covers.php?run=1">Run extraction</a>
        <a class="btn ghost" href="config.php">Back to config</a>
    </p>
<?php else: ?>
    <pre><?php
        ob_implicit_flush(true);
        @ob_end_flush();

        // Make sure cache/covers exists and is writable. mkdir is recursive
        // so it creates cache/ on the fly too.
        if (!is_dir($cacheDir)) {
            if (!@mkdir($cacheDir, 0775, true) && !is_dir($cacheDir)) {
                echo "ERROR: could not create " . h($cacheDir) . "\n";
                require __DIR__ . '/footer.php';
                exit;
            }
        }
        if (!is_writable($cacheDir)) {
            echo "ERROR: " . h($cacheDir) . " is not writable by the PHP user (" . h(get_current_user()) . ")\n";
            require __DIR__ . '/footer.php';
            exit;
        }

        $stats = ['examined' => 0, 'extracted' => 0, 'no_art' => 0, 'errors' => 0];

        $tracksStmt = db()->prepare(
            "SELECT id, file_path FROM tracks
             WHERE album_id = :a
               AND (excluded IS NULL OR excluded = 0)
             ORDER BY COALESCE(track_no, 9999), id
             LIMIT " . (int)TRY_TRACKS_PER_ALBUM
        );
        $updateAlbum = db()->prepare("UPDATE albums SET cover_art = :c WHERE id = :id");

        $albums = $todoStmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($albums as $row) {
            $albumId   = (int)$row['id'];
            $albumName = (string)$row['album_name'];
            $stats['examined']++;

            $tracksStmt->execute([':a' => $albumId]);
            $tracks = $tracksStmt->fetchAll(PDO::FETCH_ASSOC);

            $picture = null;
            foreach ($tracks as $t) {
                $path = (string)$t['file_path'];
                if (!is_file($path)) continue;
                try {
                    $picture = \Musix\Id3\extract_picture($path);
                } catch (Throwable $e) {
                    $stats['errors']++;
                    echo "! [" . $albumId . "] " . h(basename($path)) . ": " . h($e->getMessage()) . "\n";
                    continue;
                }
                if ($picture !== null) break;
            }

            if ($picture === null) {
                $stats['no_art']++;
                continue;
            }

            $coverPath = $cacheDir . '/' . $albumId . '.' . $picture['ext'];
            $bytes = @file_put_contents($coverPath, $picture['data']);
            if ($bytes === false || $bytes < 16) {
                $stats['errors']++;
                echo "! [" . $albumId . "] write failed: " . h($coverPath) . "\n";
                continue;
            }
            @chmod($coverPath, 0664);

            $updateAlbum->execute([':c' => $coverPath, ':id' => $albumId]);
            $stats['extracted']++;
            echo "+ [" . $albumId . "] " . h($albumName)
               . " -> " . h(basename($coverPath))
               . " (" . $picture['mime'] . ", " . $bytes . " bytes)\n";

            if ($stats['examined'] % 25 === 0) @flush();
        }

        echo "\nDone. examined=" . $stats['examined']
           . " extracted=" . $stats['extracted']
           . " no_art=" . $stats['no_art']
           . " errors=" . $stats['errors'] . "\n";
    ?></pre>
    <p>
        <a class="btn" href="artists.php">View artists</a>
        <a class="btn ghost" href="extract_covers.php">Run again</a>
        <a class="btn ghost" href="config.php">Back to config</a>
    </p>
<?php endif; ?>

<?php require __DIR__ . '/footer.php';
