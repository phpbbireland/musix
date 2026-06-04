<?php
declare(strict_types=1);
require_once __DIR__ . '/db.php';

// Inline create form posts back to this same page.
$err = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'create') {
    $name = trim((string)($_POST['name'] ?? ''));
    if ($name === '') {
        $err = 'Playlist needs a name.';
    } elseif (strlen($name) > 255) {
        $err = 'Name too long (255 max).';
    } else {
        try {
            $stmt = db()->prepare("INSERT INTO playlists (name) VALUES (:n)");
            $stmt->execute([':n' => $name]);
            $newId = (int)db()->lastInsertId();
            header('Location: playlist.php?id=' . $newId);
            exit;
        } catch (PDOException $e) {
            // 1062 = duplicate-key on uniq_playlist_name
            $err = ($e->errorInfo[1] ?? 0) === 1062
                ? 'A playlist with that name already exists.'
                : 'Could not create: ' . $e->getMessage();
        }
    }
}

$lists = db()->query(
    "SELECT p.id, p.name, p.created_at,
            COUNT(pt.track_id)              AS track_count,
            COALESCE(SUM(t.duration), 0)    AS total_seconds
     FROM playlists p
     LEFT JOIN playlist_tracks pt ON pt.playlist_id = p.id
     LEFT JOIN tracks t           ON t.id = pt.track_id AND t.excluded = 0
     GROUP BY p.id, p.name, p.created_at
     ORDER BY p.name"
)->fetchAll();

$page  = 'playlists';
$title = 'Musix — Playlists';
require __DIR__ . '/header.php';
?>

<h1>Playlists</h1>

<?php if ($err): ?>
    <div class="notice err"><?= h($err) ?></div>
<?php endif; ?>

<form class="toolbar" method="post" action="playlists.php">
    <input type="hidden" name="action" value="create">
    <input type="text" name="name"
           placeholder="New playlist name..."
           required maxlength="255"
           style="max-width:320px;">
    <button type="submit">Create</button>
</form>

<?php if (!$lists): ?>
    <div class="card muted">
        No playlists yet. Create one above, or use the &#10133; button next to a
        track on any album page to add it to a new playlist.
    </div>
<?php else: ?>
    <table>
        <thead>
            <tr>
                <th>Name</th>
                <th class="num">Tracks</th>
                <th class="num">Total</th>
                <th class="num">Created</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($lists as $l): ?>
            <tr>
                <td>
                    <a href="playlist.php?id=<?= (int)$l['id'] ?>"><?= h($l['name']) ?></a>
                </td>
                <td class="num"><?= (int)$l['track_count'] ?></td>
                <td class="num"><?= h(format_duration((int)$l['total_seconds'])) ?></td>
                <td class="num muted" style="white-space:nowrap;">
                    <?= h($l['created_at']) ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
<?php endif; ?>

<?php require __DIR__ . '/footer.php';
