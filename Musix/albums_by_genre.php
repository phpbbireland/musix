<?php
declare(strict_types=1);
require_once __DIR__ . '/db.php';

$genreId = (int)($_GET['genre_id'] ?? 0);

$page  = 'genres';

if ($genreId > 0) {
    // Single-genre view: tile grid of every album that has at least one
    // track tagged with this genre. Mirror albums.php's layout.
    $genre = db()->prepare("SELECT id, name FROM genres WHERE id = :id");
    $genre->execute([':id' => $genreId]);
    $genre = $genre->fetch();
    if (!$genre) { header('Location: albums_by_genre.php'); exit; }

    // Album rows. Track count + total duration are full-album numbers (not
    // restricted to the genre) — that matches expectations from albums.php
    // and avoids surprising "23/45 tracks" displays once you click in.
    $albums = db()->prepare(
        "SELECT al.id, al.album_name, al.year, al.cover_art,
                a.name AS artist_name, a.id AS artist_id,
                (SELECT COUNT(*)            FROM tracks t2 WHERE t2.album_id = al.id AND t2.excluded = 0) AS track_count,
                (SELECT COALESCE(SUM(t2.duration),0) FROM tracks t2 WHERE t2.album_id = al.id AND t2.excluded = 0) AS total_duration
         FROM albums al
         JOIN artists a ON a.id = al.artist_id
         WHERE al.id IN (SELECT DISTINCT album_id FROM tracks WHERE genre_id = :g AND excluded = 0)
         ORDER BY a.name, al.year, al.album_name"
    );
    $albums->execute([':g' => $genreId]);
    $albums = $albums->fetchAll();

    $title = 'Musix — ' . $genre['name'];
    require __DIR__ . '/header.php';
    ?>
    <div class="crumbs">
        <a href="albums_by_genre.php">Genres</a> / <?= h($genre['name']) ?>
    </div>

    <h1><?= h($genre['name']) ?></h1>
    <p class="muted"><?= count($albums) ?> album<?= count($albums) === 1 ? '' : 's' ?></p>

    <?php if (!$albums): ?>
        <div class="card muted">No albums tagged with this genre.</div>
    <?php else: ?>
        <div class="grid">
            <?php foreach ($albums as $al): ?>
                <div class="tile">
                    <a class="cover-link" href="tracks.php?album_id=<?= (int)$al['id'] ?>">
                        <?php if (!empty($al['cover_art'])): ?>
                            <img class="cover" loading="lazy" alt=""
                                 src="cover.php?album_id=<?= (int)$al['id'] ?>">
                        <?php else: ?>
                            <div class="cover cover-placeholder" aria-hidden="true">♪</div>
                        <?php endif; ?>
                    </a>
                    <a class="title" href="tracks.php?album_id=<?= (int)$al['id'] ?>">
                        <?= h($al['album_name']) ?>
                    </a>
                    <div class="sub">
                        <a href="albums.php?artist_id=<?= (int)$al['artist_id'] ?>"><?= h($al['artist_name']) ?></a>
                        <?= $al['year'] ? ' • ' . (int)$al['year'] : '' ?>
                    </div>
                    <div class="sub">
                        <?= (int)$al['track_count'] ?> tracks
                        <?php if ((int)$al['total_duration'] > 0): ?>
                            • <?= h(format_duration((int)$al['total_duration'])) ?>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
    <?php
} else {
    // Index view: list every genre with album + track counts. Genres with
    // zero tagged tracks (orphan rows) are filtered out so the list stays
    // useful — they'd typically only appear if you scanned, deleted tracks,
    // and never re-pruned.
    $genres = db()->query(
        "SELECT g.id, g.name,
                COUNT(DISTINCT t.album_id) AS album_count,
                COUNT(t.id) AS track_count
         FROM genres g
         LEFT JOIN tracks t ON t.genre_id = g.id AND t.excluded = 0
         GROUP BY g.id, g.name
         HAVING track_count > 0
         ORDER BY g.name"
    )->fetchAll();

    $title = 'Musix — Genres';
    require __DIR__ . '/header.php';
    ?>
    <h1>Genres</h1>

    <?php if (!$genres): ?>
        <div class="card muted">
            No genres yet. <a href="scan.php">Run a scan</a> to populate from tag data.
        </div>
    <?php else: ?>
        <p class="muted"><?= count($genres) ?> genres</p>
        <table>
            <thead>
                <tr><th>Genre</th><th class="num">Albums</th><th class="num">Tracks</th></tr>
            </thead>
            <tbody>
                <?php foreach ($genres as $g): ?>
                    <tr>
                        <td><a href="albums_by_genre.php?genre_id=<?= (int)$g['id'] ?>"><?= h($g['name']) ?></a></td>
                        <td class="num"><?= (int)$g['album_count'] ?></td>
                        <td class="num"><?= (int)$g['track_count'] ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
    <?php
}

require __DIR__ . '/footer.php';
