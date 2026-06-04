<?php
declare(strict_types=1);
require_once __DIR__ . '/db.php';

$q     = trim((string)($_GET['q'] ?? ''));
$limit = 50;

$artists = $albums = $tracks = [];

if ($q !== '') {
    $like = '%' . $q . '%';

    // Artists matching by name. Counts let the row mirror artists.php.
    $stmt = db()->prepare(
        "SELECT a.id, a.name,
                COUNT(DISTINCT al.id) AS album_count,
                COUNT(DISTINCT t.id)  AS track_count
         FROM artists a
         LEFT JOIN albums al ON al.artist_id = a.id
         LEFT JOIN tracks t  ON t.artist_id  = a.id AND t.excluded = 0
         WHERE a.name LIKE :q
         GROUP BY a.id, a.name
         ORDER BY a.name
         LIMIT $limit"
    );
    $stmt->execute([':q' => $like]);
    $artists = $stmt->fetchAll();

    // Albums matching by album_name. Pull artist + track count for context.
    $stmt = db()->prepare(
        "SELECT al.id, al.album_name, al.year,
                ar.id AS artist_id, ar.name AS artist_name,
                COUNT(t.id) AS track_count
         FROM albums al
         JOIN artists ar ON ar.id = al.artist_id
         LEFT JOIN tracks t ON t.album_id = al.id AND t.excluded = 0
         WHERE al.album_name LIKE :q
         GROUP BY al.id, al.album_name, al.year, ar.id, ar.name
         ORDER BY ar.name, al.album_name
         LIMIT $limit"
    );
    $stmt->execute([':q' => $like]);
    $albums = $stmt->fetchAll();

    // Tracks matching by track_name. Carry album + artist for breadcrumbs.
    $stmt = db()->prepare(
        "SELECT t.id, t.track_name, t.duration, t.track_no, t.is_favorite,
                al.id AS album_id, al.album_name,
                ar.id AS artist_id, ar.name AS artist_name
         FROM tracks t
         JOIN albums  al ON al.id = t.album_id
         JOIN artists ar ON ar.id = t.artist_id
         WHERE t.track_name LIKE :q AND t.excluded = 0
         ORDER BY ar.name, al.album_name, COALESCE(t.track_no, 9999), t.track_name
         LIMIT $limit"
    );
    $stmt->execute([':q' => $like]);
    $tracks = $stmt->fetchAll();
}

$totalHits = count($artists) + count($albums) + count($tracks);

$page  = 'search';
$title = $q !== '' ? 'Musix — Search: ' . $q : 'Musix — Search';
require __DIR__ . '/header.php';
?>

<h1>Search</h1>

<form class="toolbar" method="get" action="search.php" role="search">
    <input type="text" name="q" value="<?= h($q) ?>"
           placeholder="Search artists, albums, tracks..."
           autofocus
           style="max-width:420px;">
    <button type="submit">Search</button>
    <?php if ($q !== ''): ?>
        <a class="btn ghost" href="search.php">Clear</a>
    <?php endif; ?>
</form>

<?php if ($q === ''): ?>
    <div class="card muted">Type something above and hit Search.</div>
<?php elseif ($totalHits === 0): ?>
    <div class="card muted">No matches for &ldquo;<?= h($q) ?>&rdquo;.</div>
<?php else: ?>
    <p class="muted">
        <?= $totalHits ?> match<?= $totalHits === 1 ? '' : 'es' ?> for &ldquo;<?= h($q) ?>&rdquo;
        <?php if (count($artists) >= $limit || count($albums) >= $limit || count($tracks) >= $limit): ?>
            (some sections capped at <?= $limit ?>)
        <?php endif; ?>
    </p>

    <?php if ($artists): ?>
        <h2>Artists (<?= count($artists) ?>)</h2>
        <table>
            <thead>
                <tr><th>Artist</th><th class="num">Albums</th><th class="num">Tracks</th></tr>
            </thead>
            <tbody>
                <?php foreach ($artists as $a): ?>
                    <tr>
                        <td><a href="albums.php?artist_id=<?= (int)$a['id'] ?>"><?= h($a['name']) ?></a></td>
                        <td class="num"><?= (int)$a['album_count'] ?></td>
                        <td class="num"><?= (int)$a['track_count'] ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>

    <?php if ($albums): ?>
        <h2>Albums (<?= count($albums) ?>)</h2>
        <table>
            <thead>
                <tr>
                    <th>Album</th>
                    <th>Artist</th>
                    <th class="num">Year</th>
                    <th class="num">Tracks</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($albums as $al): ?>
                    <tr>
                        <td><a href="tracks.php?album_id=<?= (int)$al['id'] ?>"><?= h($al['album_name']) ?></a></td>
                        <td><a href="albums.php?artist_id=<?= (int)$al['artist_id'] ?>"><?= h($al['artist_name']) ?></a></td>
                        <td class="num"><?= $al['year'] ? (int)$al['year'] : '' ?></td>
                        <td class="num"><?= (int)$al['track_count'] ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>

    <?php if ($tracks): ?>
        <h2>Tracks (<?= count($tracks) ?>)</h2>
        <table>
            <thead>
                <tr>
                    <th class="fav"></th>
                    <th>Track</th>
                    <th>Album</th>
                    <th>Artist</th>
                    <th class="num">Time</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($tracks as $t): ?>
                    <tr>
                        <td class="fav">
                            <?php if ((int)$t['is_favorite']): ?>
                                <span class="fav-btn is-fav"
                                      aria-label="Favourite"
                                      title="Favourite"
                                      style="cursor:default;">&#9733;</span>
                            <?php endif; ?>
                        </td>
                        <td><a href="tracks.php?album_id=<?= (int)$t['album_id'] ?>"><?= h($t['track_name']) ?></a></td>
                        <td><a href="tracks.php?album_id=<?= (int)$t['album_id'] ?>"><?= h($t['album_name']) ?></a></td>
                        <td><a href="albums.php?artist_id=<?= (int)$t['artist_id'] ?>"><?= h($t['artist_name']) ?></a></td>
                        <td class="num"><?= h(format_duration($t['duration'] !== null ? (int)$t['duration'] : null)) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
<?php endif; ?>

<?php require __DIR__ . '/footer.php';
