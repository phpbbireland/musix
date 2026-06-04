<?php
declare(strict_types=1);
require_once __DIR__ . '/db.php';

/*
 * Plain alphabetical list of every artist. The covered-tile browsing layout
 * lives on albums.php now (no params → grouped overview). This page is the
 * fast index — click an artist to drop into their album grid.
 */

$q = trim((string)($_GET['q'] ?? ''));

$sql = "SELECT a.id, a.name,
               COUNT(DISTINCT al.id) AS album_count,
               COUNT(DISTINCT t.id)  AS track_count
        FROM artists a
        LEFT JOIN albums al ON al.artist_id = a.id
        LEFT JOIN tracks t  ON t.artist_id  = a.id AND t.excluded = 0";
$params = [];
if ($q !== '') {
    $sql .= " WHERE a.name LIKE :q";
    $params[':q'] = '%' . $q . '%';
}
$sql .= " GROUP BY a.id, a.name ORDER BY a.name";

$stmt = db()->prepare($sql);
$stmt->execute($params);
$artists = $stmt->fetchAll();

$page  = 'artists';
$title = 'Musix — Artists';
require __DIR__ . '/header.php';
?>

<h1>Artists</h1>

<form class="toolbar" method="get">
    <input type="text" name="q" value="<?= h($q) ?>" placeholder="Search artists..." style="max-width:320px;">
    <button type="submit">Search</button>
    <?php if ($q !== ''): ?><a class="btn ghost" href="artists.php">Clear</a><?php endif; ?>
    <span class="spacer"></span>
    <a class="btn ghost" href="scan.php">Scan library</a>
</form>

<?php if (!$artists): ?>
    <div class="card muted">
        No artists yet.
        <?php if (cfg('library_path') === ''): ?>
            <a href="config.php">Set a library path</a> and then run a scan.
        <?php else: ?>
            <a href="scan.php">Run a scan</a> to populate the library.
        <?php endif; ?>
    </div>
<?php else: ?>
    <p class="muted"><?= count($artists) ?> artist<?= count($artists) === 1 ? '' : 's' ?></p>
    <table>
        <thead>
            <tr>
                <th>Artist</th>
                <th class="num">Albums</th>
                <th class="num">Tracks</th>
            </tr>
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

<?php require __DIR__ . '/footer.php';
