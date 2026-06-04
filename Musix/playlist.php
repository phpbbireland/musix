<?php
declare(strict_types=1);
require_once __DIR__ . '/db.php';

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) { header('Location: playlists.php'); exit; }

// Inline action handlers (delete + rename), then redirect back. Keeps the
// page idempotent — a refresh after an action doesn't repeat it.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'delete') {
        $stmt = db()->prepare("DELETE FROM playlists WHERE id = :id");
        $stmt->execute([':id' => $id]);
        // playlist_tracks rows are cleaned up by FK CASCADE.
        header('Location: playlists.php');
        exit;
    }

    if ($action === 'rename') {
        $name = trim((string)($_POST['name'] ?? ''));
        if ($name !== '' && strlen($name) <= 255) {
            try {
                $upd = db()->prepare("UPDATE playlists SET name = :n WHERE id = :id");
                $upd->execute([':n' => $name, ':id' => $id]);
            } catch (PDOException $e) {
                // Duplicate name — silently ignore for now (UX nicety later).
            }
        }
        header('Location: playlist.php?id=' . $id);
        exit;
    }
}

$pl = db()->prepare("SELECT id, name, created_at FROM playlists WHERE id = :id");
$pl->execute([':id' => $id]);
$pl = $pl->fetch();
if (!$pl) { header('Location: playlists.php'); exit; }

$tracks = db()->prepare(
    "SELECT pt.position,
            t.id, t.track_name, t.duration, t.play_count, t.last_played,
            t.is_favorite, t.file_path,
            al.id AS album_id, al.album_name,
            ar.id AS artist_id, ar.name AS artist_name
     FROM playlist_tracks pt
     JOIN tracks  t  ON t.id  = pt.track_id
     JOIN albums  al ON al.id = t.album_id
     JOIN artists ar ON ar.id = t.artist_id
     WHERE pt.playlist_id = :id AND t.excluded = 0
     ORDER BY pt.position, t.id"
);
$tracks->execute([':id' => $id]);
$tracks = $tracks->fetchAll();

$totalSeconds = 0;
foreach ($tracks as $t) $totalSeconds += (int)$t['duration'];

$allowExt  = cfg('allow_external_launch') === '1';
$extName   = cfg('external_player_name', 'External Player');
$browserOk = ['mp3', 'ogg', 'oga'];

$page  = 'playlists';
$title = 'Musix — ' . $pl['name'];
require __DIR__ . '/header.php';
?>

<div class="crumbs">
    <a href="playlists.php">Playlists</a> /
    <?= h($pl['name']) ?>
</div>

<h1><?= h($pl['name']) ?></h1>
<p class="muted">
    <?= count($tracks) ?> tracks
    <?= $totalSeconds > 0 ? ' • ' . h(format_duration($totalSeconds)) : '' ?>
    • created <?= h($pl['created_at']) ?>
</p>

<div class="toolbar">
    <details style="display:inline-block;">
        <summary class="btn small ghost" style="cursor:pointer; list-style:none;">Rename</summary>
        <form method="post" action="playlist.php?id=<?= (int)$id ?>"
              style="margin-top:8px; display:flex; gap:8px; align-items:center;">
            <input type="hidden" name="action" value="rename">
            <input type="text" name="name" value="<?= h($pl['name']) ?>"
                   required maxlength="255" style="max-width:320px;">
            <button type="submit" class="btn small">Save</button>
        </form>
    </details>
    <span class="spacer"></span>
    <form method="post" action="playlist.php?id=<?= (int)$id ?>"
          onsubmit="return confirm('Delete this playlist? Tracks themselves are not deleted.');"
          style="margin:0;">
        <input type="hidden" name="action" value="delete">
        <button type="submit" class="btn small ghost">Delete playlist</button>
    </form>
</div>

<?php if (!$tracks): ?>
    <div class="card muted">
        Empty. Add tracks from any album page using the &#10133; button next to Play.
    </div>
<?php else: ?>
    <table id="tracks-table" data-playlist-id="<?= (int)$id ?>">
        <thead>
            <tr>
                <th class="num">#</th>
                <th class="fav"></th>
                <th>Track</th>
                <th>Artist</th>
                <th>Album</th>
                <th class="num">Time</th>
                <th class="num" title="Times this track has finished playing">Plays</th>
                <th class="actions">Actions</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($tracks as $t):
            $ext = strtolower(pathinfo((string)$t['file_path'], PATHINFO_EXTENSION));
            $extWarn = $ext !== '' && !in_array($ext, $browserOk, true);
        ?>
            <tr data-track-id="<?= (int)$t['id'] ?>"
                data-stream="stream.php?id=<?= (int)$t['id'] ?>"
                data-title="<?= h($t['track_name']) ?>"
                data-artist="<?= h($t['artist_name']) ?>"
                data-album="<?= h($t['album_name']) ?>">
                <td class="num"><?= (int)$t['position'] ?></td>
                <td class="fav">
                    <button type="button"
                            class="fav-btn<?= (int)$t['is_favorite'] ? ' is-fav' : '' ?>"
                            aria-pressed="<?= (int)$t['is_favorite'] ? 'true' : 'false' ?>"
                            title="<?= (int)$t['is_favorite'] ? 'Unfavourite' : 'Favourite' ?>">
                        <?= (int)$t['is_favorite'] ? '★' : '☆' ?>
                    </button>
                </td>
                <td>
                    <?= h($t['track_name']) ?>
                    <?php if ($ext !== ''): ?>
                        <span class="ext<?= $extWarn ? ' ext-warn' : '' ?>"
                              title="<?= $extWarn
                                  ? h(strtoupper($ext) . ' may not stream in the browser — open in the external player')
                                  : h(strtoupper($ext)) ?>"
                        ><?= h(strtoupper($ext)) ?></span>
                    <?php endif; ?>
                </td>
                <td><a href="albums.php?artist_id=<?= (int)$t['artist_id'] ?>"><?= h($t['artist_name']) ?></a></td>
                <td><a href="tracks.php?album_id=<?= (int)$t['album_id'] ?>"><?= h($t['album_name']) ?></a></td>
                <td class="num"><?= h(format_duration($t['duration'] !== null ? (int)$t['duration'] : null)) ?></td>
                <td class="num play-count"
                    title="<?= $t['last_played'] ? 'Last played: ' . h($t['last_played']) : 'Never played' ?>">
                    <?= (int)$t['play_count'] ?>
                </td>
                <td class="actions">
                    <button type="button" class="btn small play-btn">Play</button>
                    <button type="button" class="btn small ghost remove-btn"
                            title="Remove from this playlist">&minus;</button>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>

    <div class="now-playing" id="now-playing">
        <div class="np-title" id="np-title">Nothing playing</div>
        <div class="np-sub" id="np-sub">Click <em>Play</em> to start the playlist.</div>
        <audio id="audio" controls preload="none"></audio>
        <div class="toolbar" style="margin-top:10px;">
            <button type="button" class="btn small ghost" id="prev-btn">Previous</button>
            <button type="button" class="btn small ghost" id="next-btn">Next</button>
        </div>
    </div>

    <script>
    (function () {
        const playlistId = document.getElementById('tracks-table').dataset.playlistId;
        const audio = document.getElementById('audio');
        const npT   = document.getElementById('np-title');
        const npS   = document.getElementById('np-sub');
        let rows = Array.from(document.querySelectorAll('#tracks-table tbody tr'));
        let currentIndex = -1;

        function refreshRows() {
            rows = Array.from(document.querySelectorAll('#tracks-table tbody tr'));
        }

        function playIndex(i) {
            if (i < 0 || i >= rows.length) return;
            const row = rows[i];
            currentIndex = i;
            audio.src = row.dataset.stream;
            audio.play().catch(() => {});
            npT.textContent = row.dataset.title;
            npS.textContent = row.dataset.artist + ' — ' + row.dataset.album;
            rows.forEach(r => r.classList.remove('playing'));
            row.classList.add('playing');
        }

        function bumpPlay(row) {
            const id = row.dataset.trackId;
            if (!id) return;
            const fd = new FormData();
            fd.append('id', id);
            fetch('bump_play.php', { method: 'POST', body: fd })
                .then(r => r.ok ? r.json() : null)
                .then(data => {
                    if (!data || !data.ok) return;
                    const cell = row.querySelector('.play-count');
                    if (cell) {
                        cell.textContent = data.play_count;
                        if (data.last_played) {
                            cell.setAttribute('title', 'Last played: ' + data.last_played);
                        }
                    }
                })
                .catch(() => {});
        }

        function toggleFav(row) {
            const id = row.dataset.trackId;
            if (!id) return;
            const fd = new FormData();
            fd.append('id', id);
            fetch('toggle_favorite.php', { method: 'POST', body: fd })
                .then(r => r.ok ? r.json() : null)
                .then(data => {
                    if (!data || !data.ok) return;
                    const btn = row.querySelector('.fav-btn');
                    if (!btn) return;
                    const fav = data.is_favorite === 1;
                    btn.classList.toggle('is-fav', fav);
                    btn.textContent = fav ? '★' : '☆';
                    btn.setAttribute('aria-pressed', fav ? 'true' : 'false');
                    btn.setAttribute('title', fav ? 'Unfavourite' : 'Favourite');
                })
                .catch(() => {});
        }

        function removeFromPlaylist(row) {
            const trackId = row.dataset.trackId;
            if (!trackId) return;
            const fd = new FormData();
            fd.append('playlist_id', playlistId);
            fd.append('track_id', trackId);
            fetch('playlist_remove.php', { method: 'POST', body: fd })
                .then(r => r.ok ? r.json() : null)
                .then(data => {
                    if (!data || !data.ok) return;
                    const wasPlaying = row.classList.contains('playing');
                    const idxBefore = rows.indexOf(row);
                    row.remove();
                    refreshRows();
                    if (wasPlaying) {
                        currentIndex = Math.min(idxBefore, rows.length) - 1;
                    } else if (idxBefore < currentIndex) {
                        currentIndex--;
                    }
                })
                .catch(() => {});
        }

        function wireRow(row) {
            const playBtn = row.querySelector('.play-btn');
            if (playBtn) playBtn.addEventListener('click', () => playIndex(rows.indexOf(row)));
            const favBtn = row.querySelector('.fav-btn');
            if (favBtn) favBtn.addEventListener('click', () => toggleFav(row));
            const remBtn = row.querySelector('.remove-btn');
            if (remBtn) remBtn.addEventListener('click', () => removeFromPlaylist(row));
        }
        rows.forEach(wireRow);

        document.getElementById('prev-btn').addEventListener('click', () => playIndex(currentIndex - 1));
        document.getElementById('next-btn').addEventListener('click', () => playIndex(currentIndex + 1));

        audio.addEventListener('ended', () => {
            if (currentIndex >= 0 && currentIndex < rows.length) {
                bumpPlay(rows[currentIndex]);
            }
            playIndex(currentIndex + 1);
        });
    })();
    </script>
    <style>
        tr.playing { background: var(--panel-2); }
        tr.playing td:nth-child(3)::before { content: "▶ "; color: var(--accent); }
    </style>
<?php endif; ?>

<?php require __DIR__ . '/footer.php';
