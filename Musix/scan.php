<?php
declare(strict_types=1);
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/id3.php';

set_time_limit(0);
ignore_user_abort(false);

$page = 'config';
$title = 'Musix — Scan';

$libraryPath = (string)cfg('library_path', '');
$run = isset($_GET['run']) && $_GET['run'] === '1';

require __DIR__ . '/header.php';
?>

<h1>Scan Library</h1>

<?php if ($libraryPath === ''): ?>
    <div class="notice err">No library path configured. <a href="config.php">Set one in Config</a> first.</div>
<?php elseif (!is_dir($libraryPath)): ?>
    <div class="notice err">Library path is not a directory: <?= h($libraryPath) ?></div>
<?php else: ?>
    <p class="muted">Library: <code><?= h($libraryPath) ?></code></p>

    <?php if (!$run): ?>
        <p>This walks the library folder, reads tags from <code>.mp3</code>, <code>.flac</code>, <code>.ogg</code>
           files, and inserts/updates <strong>artists</strong>, <strong>albums</strong>, and <strong>tracks</strong>.</p>
        <p>Existing rows are kept; tracks already in the DB (matched by file path) are skipped.</p>
        <a class="btn" href="scan.php?run=1">Start scan</a>
        <a class="btn ghost" href="config.php">Back to config</a>
    <?php else: ?>
        <pre><?php
            ob_implicit_flush(true);
            @ob_end_flush();

            $stats = ['files' => 0, 'inserted' => 0, 'skipped' => 0, 'backfilled' => 0, 'errors' => 0];
            $exts = ['mp3', 'flac', 'ogg', 'oga'];

            // SQLite has no LAST_INSERT_ID(id) trick: ON CONFLICT DO UPDATE does
            // not refresh last_insert_rowid(). So we insert-or-ignore, then look
            // the id up explicitly with a companion SELECT.
            $insertArtist = db()->prepare(
                "INSERT INTO artists (name) VALUES (:n)
                 ON CONFLICT(name) DO NOTHING"
            );
            $artistIdByName = db()->prepare("SELECT id FROM artists WHERE name = :n");
            $insertAlbum = db()->prepare(
                "INSERT INTO albums (artist_id, album_name, year) VALUES (:a, :n, :y)
                 ON CONFLICT(artist_id, album_name) DO UPDATE SET
                     year = COALESCE(excluded.year, year)"
            );
            $albumIdByKey = db()->prepare(
                "SELECT id FROM albums WHERE artist_id = :a AND album_name = :n"
            );
            $insertTrack = db()->prepare(
                "INSERT INTO tracks (album_id, artist_id, genre_id, track_name, track_no, year, bitrate, duration, file_path)
                 VALUES (:al, :ar, :ge, :tn, :no, :yr, :br, :du, :fp)
                 ON CONFLICT(file_path) DO UPDATE SET
                     track_name = excluded.track_name,
                     track_no   = COALESCE(excluded.track_no,  track_no),
                     year       = COALESCE(excluded.year,      year),
                     bitrate    = COALESCE(excluded.bitrate,   bitrate),
                     genre_id   = COALESCE(excluded.genre_id,  genre_id),
                     duration   = COALESCE(excluded.duration,  duration)"
            );
            // Backfill query: only fill columns that are currently NULL, so a
            // re-scan populates the new fields without trampling user edits.
            $backfillTrack = db()->prepare(
                "UPDATE tracks SET
                    year     = COALESCE(year,     :yr),
                    bitrate  = COALESCE(bitrate,  :br),
                    genre_id = COALESCE(genre_id, :ge),
                    duration = COALESCE(duration, :du)
                 WHERE id = :id"
            );
            $existsTrack    = db()->prepare("SELECT id, album_id, year, bitrate, genre_id, duration FROM tracks WHERE file_path = :fp LIMIT 1");
            $getAlbumCover  = db()->prepare("SELECT cover_art FROM albums WHERE id = :id");
            $setAlbumCover  = db()->prepare("UPDATE albums SET cover_art = :c WHERE id = :id");

            // Genre upsert helper. Memoized — the library has at most a few
            // dozen distinct genres, so we hit the DB once per unique value.
            $insertGenre = db()->prepare(
                "INSERT INTO genres (name) VALUES (:n)
                 ON CONFLICT(name) DO NOTHING"
            );
            $genreIdByName = db()->prepare("SELECT id FROM genres WHERE name = :n");
            $genreCache = [];
            $getOrCreateGenreId = function (?string $name) use ($insertGenre, $genreIdByName, &$genreCache): ?int {
                if ($name === null) return null;
                $name = trim($name);
                if ($name === '') return null;
                // Cap at 100 chars to match the column. Clip rather than reject.
                if (strlen($name) > 100) $name = substr($name, 0, 100);
                $key = mb_strtolower($name);
                if (array_key_exists($key, $genreCache)) return $genreCache[$key];
                $insertGenre->execute([':n' => $name]);
                $genreIdByName->execute([':n' => $name]);
                $id = (int)$genreIdByName->fetchColumn();
                return $genreCache[$key] = ($id > 0 ? $id : null);
            };

            // Per-scan memo so we don't re-glob every track's folder for the same album
            $albumsCoverChecked = [];

            // Find a folder image for an album. Priority on common names, then any image.
            $findAlbumCover = function (string $folder): ?string {
                if (!is_dir($folder)) return null;
                $files = @scandir($folder);
                if (!$files) return null;
                $images = [];
                foreach ($files as $f) {
                    if ($f === '.' || $f === '..') continue;
                    if (!preg_match('/\.(jpe?g|png|gif)$/i', $f)) continue;
                    if (!is_file($folder . '/' . $f)) continue;
                    $images[] = $f;
                }
                if (!$images) return null;
                foreach (['cover', 'folder', 'front', 'album', 'albumart'] as $pref) {
                    foreach ($images as $img) {
                        if (preg_match('/^' . $pref . '\.(jpe?g|png|gif)$/i', $img)) {
                            return $folder . '/' . $img;
                        }
                    }
                }
                sort($images, SORT_NATURAL | SORT_FLAG_CASE);
                return $folder . '/' . $images[0];
            };

            $rdi = new RecursiveDirectoryIterator($libraryPath, FilesystemIterator::SKIP_DOTS);
            $rii = new RecursiveIteratorIterator($rdi);

            foreach ($rii as $file) {
                if (!$file->isFile()) continue;
                $ext = strtolower($file->getExtension());
                if (!in_array($ext, $exts, true)) continue;

                $stats['files']++;
                $fp = $file->getPathname();

                try {
                    $albumIdForCover = null;

                    $existsTrack->execute([':fp' => $fp]);
                    $existingRow = $existsTrack->fetch(PDO::FETCH_ASSOC);

                    if ($existingRow !== false) {
                        $stats['skipped']++;
                        $albumIdForCover = (int)$existingRow['album_id'];

                        // Backfill: if year/bitrate/genre_id/duration are still
                        // NULL (e.g. rows scanned before #1 schema patch, or
                        // FLACs that failed to parse before the ID3v2-prefix
                        // fix), re-read tags and patch them in. Costs one tag
                        // read per track but only writes if there's something
                        // to fill.
                        $needs = $existingRow['year']     === null
                              || $existingRow['bitrate']  === null
                              || $existingRow['genre_id'] === null
                              || $existingRow['duration'] === null;
                        if ($needs) {
                            $tags = \Musix\Id3\read_tags($fp);
                            $genreId = $getOrCreateGenreId($tags['genre'] ?? null);
                            $backfillTrack->execute([
                                ':yr' => $tags['year']     ?? null,
                                ':br' => $tags['bitrate']  ?? null,
                                ':ge' => $genreId,
                                ':du' => $tags['duration'] ?? null,
                                ':id' => (int)$existingRow['id'],
                            ]);
                            if ($backfillTrack->rowCount() > 0) {
                                $stats['backfilled']++;
                            }
                        }
                    } else {
                        $tags = \Musix\Id3\read_tags($fp);

                        // Fall back to folder/filename when tags missing
                        $artistName = $tags['artist'] ?: basename(dirname($fp, 2));
                        $albumName  = $tags['album']  ?: basename(dirname($fp));
                        $trackName  = $tags['title']  ?: pathinfo($fp, PATHINFO_FILENAME);

                        if ($artistName === '' || $artistName === '.') $artistName = 'Unknown Artist';
                        if ($albumName  === '' || $albumName  === '.') $albumName  = 'Unknown Album';
                        if ($trackName  === '') $trackName  = pathinfo($fp, PATHINFO_FILENAME);

                        $insertArtist->execute([':n' => $artistName]);
                        $artistIdByName->execute([':n' => $artistName]);
                        $artistId = (int)$artistIdByName->fetchColumn();

                        $insertAlbum->execute([
                            ':a' => $artistId,
                            ':n' => $albumName,
                            ':y' => $tags['year'],
                        ]);
                        $albumIdByKey->execute([':a' => $artistId, ':n' => $albumName]);
                        $albumId = (int)$albumIdByKey->fetchColumn();

                        $genreId = $getOrCreateGenreId($tags['genre'] ?? null);

                        $insertTrack->execute([
                            ':al' => $albumId,
                            ':ar' => $artistId,
                            ':ge' => $genreId,
                            ':tn' => $trackName,
                            ':no' => $tags['track_no'],
                            ':yr' => $tags['year'],
                            ':br' => $tags['bitrate'],
                            ':du' => $tags['duration'],
                            ':fp' => $fp,
                        ]);
                        $stats['inserted']++;
                        echo "+ " . h($artistName) . " / " . h($albumName) . " / " . h($trackName) . "\n";

                        $albumIdForCover = $albumId;
                    }

                    // Cover art: only check each album once per scan, and only fill if empty.
                    // Order: folder image first (cheaper, often higher-res); embedded
                    // APIC/PICTURE second (covers albums whose folders have no image).
                    if ($albumIdForCover && !isset($albumsCoverChecked[$albumIdForCover])) {
                        $albumsCoverChecked[$albumIdForCover] = true;
                        $getAlbumCover->execute([':id' => $albumIdForCover]);
                        $existing = (string)$getAlbumCover->fetchColumn();
                        if ($existing === '' || !is_file($existing)) {
                            $coverPath = $findAlbumCover(dirname($fp));
                            if ($coverPath) {
                                $setAlbumCover->execute([':c' => $coverPath, ':id' => $albumIdForCover]);
                                echo "  ↳ cover [" . $albumIdForCover . "]: " . h(basename($coverPath)) . "\n";
                                $stats['covers'] = ($stats['covers'] ?? 0) + 1;
                            } else {
                                $picture = \Musix\Id3\extract_picture($fp);
                                if ($picture !== null) {
                                    $cacheDir = __DIR__ . '/cache/covers';
                                    if (!is_dir($cacheDir)) @mkdir($cacheDir, 0775, true);
                                    if (is_dir($cacheDir) && is_writable($cacheDir)) {
                                        $cachePath = $cacheDir . '/' . $albumIdForCover . '.' . $picture['ext'];
                                        $bytes = @file_put_contents($cachePath, $picture['data']);
                                        if ($bytes !== false && $bytes >= 16) {
                                            @chmod($cachePath, 0664);
                                            $setAlbumCover->execute([':c' => $cachePath, ':id' => $albumIdForCover]);
                                            echo "  ↳ cover [" . $albumIdForCover . "] (embedded): " . h(basename($cachePath)) . "\n";
                                            $stats['covers_embedded'] = ($stats['covers_embedded'] ?? 0) + 1;
                                        }
                                    }
                                }
                            }
                        }
                    }
                } catch (Throwable $e) {
                    $stats['errors']++;
                    echo "! " . h($fp) . ": " . h($e->getMessage()) . "\n";
                }

                if ($stats['files'] % 25 === 0) {
                    @flush();
                }
            }

            echo "\nDone. files=" . $stats['files']
                . " inserted=" . $stats['inserted']
                . " skipped=" . $stats['skipped']
                . " covers="  . ($stats['covers'] ?? 0)
                . " covers_embedded=" . ($stats['covers_embedded'] ?? 0)
                . " errors="  . $stats['errors'] . "\n";
        ?></pre>
        <p><a class="btn" href="artists.php">View artists</a> <a class="btn ghost" href="config.php">Back to config</a></p>
    <?php endif; ?>
<?php endif; ?>

<?php require __DIR__ . '/footer.php';
