<?php
declare(strict_types=1);
require_once __DIR__ . '/db.php';

// One page, two tabs. ?view=added shows newly-imported tracks, ?view=played
// shows recently-listened ones. Each tab caps at LIMIT rows.
$view = ($_GET['view'] ?? 'added') === 'played' ? 'played' : 'added';
$LIMIT = 100;

if ($view === 'played') {
    // Skip never-played rows. NULL last_played would otherwise sort to the
    // end and dilute the list.
    $sql = "SELECT t.id, t.track_name, t.duration, t.play_count, t.last_played,
                   t.is_favorite, t.file_path,
                   al.id AS album_id, al.album_name,
                   ar.id AS artist_id, ar.name AS artist_name
            FROM tracks t
            JOIN albums  al ON al.id = t.album_id
            JOIN artists ar ON ar.id = t.artist_id
            WHERE t.last_played IS NOT NULL AND t.excluded = 0
            ORDER BY t.last_played DESC
            LIMIT $LIMIT";
    $emptyMsg = 'Nothing has been played yet. Hit Play on any track to start filling this list.';
    $heading  = 'Recently played';
    $sortCol  = 'last_played';
} else {
    $sql = "SELECT t.id, t.track_name, t.duration, t.play_count, t.last_played,
                   t.is_favorite, t.file_path, t.added_at,
                   al.id AS album_id, al.album_name,
                   ar.id AS artist_id, ar.name AS artist_name
            FROM tracks t
            JOIN albums  al ON al.id = t.album_id
            JOIN artists ar ON ar.id = t.artist_id
            WHERE t.excluded = 0
            ORDER BY t.added_at DESC, t.id DESC
            LIMIT $LIMIT";
    $emptyMsg = 'Library is empty. Run a scan to populate it.';
    $heading  = 'Recently added';
    $sortCol  = 'added_at';
}

$tracks = db()->query($sql)->fetchAll();

$allowExt  = cfg('allow_external_launch') === '1';
$extName   = cfg('external_player_name', 'External Player');
$browserOk = ['mp3', 'ogg', 'oga'];

$page  = 'recent';
$title = 'Musix — ' . $heading;
require __DIR__ . '/header.php';
?>

<h1>Recent</h1>

<div class="toolbar" role="tablist">
    <a class="btn small <?= $view === 'added'  ? '' : 'ghost' ?>" href="recent.php?view=added">Recently added</a>
    <a class="btn small <?= $view === 'played' ? '' : 'ghost' ?>" href="recent.php?view=played">Recently played</a>
</div>

<h2 style="margin-top:8px;"><?= h($heading) ?></h2>

<?php if (!$tracks): ?>
    <div class="card muted"><?= h($emptyMsg) ?></div>
<?php else: ?>
    <p class="muted">
        Top <?= count($tracks) ?> by <?= h($sortCol) ?>
    </p>

    <table id="tracks-table">
        <thead>
            <tr>
                <th class="fav"></th>
                <th>Track</th>
                <th>Artist</th>
                <th>Album</th>
                <th class="num">Time</th>
                <th class="num" title="Times this track has finished playing">Plays</th>
                <th class="num"><?= $view === 'played' ? 'Last played' : 'Added' ?></th>
                <th class="actions">Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($tracks as $t):
                $ext = strtolower(pathinfo((string)$t['file_path'], PATHINFO_EXTENSION));
                $extWarn = $ext !== '' && !in_array($ext, $browserOk, true);
                $stamp = $view === 'played' ? $t['last_played'] : ($t['added_at'] ?? null);
            ?>
                <tr data-track-id="<?= (int)$t['id'] ?>"
                    data-stream="stream.php?id=<?= (int)$t['id'] ?>"
                    data-title="<?= h($t['track_name']) ?>"
                    data-artist="<?= h($t['artist_name']) ?>"
                    data-album="<?= h($t['album_name']) ?>">
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
                    <td>
                        <a href="albums.php?artist_id=<?= (int)$t['artist_id'] ?>">
                            <?= h($t['artist_name']) ?>
                        </a>
                    </td>
                    <td>
                        <a href="tracks.php?album_id=<?= (int)$t['album_id'] ?>">
                            <?= h($t['album_name']) ?>
                        </a>
                    </td>
                    <td class="num"><?= h(format_duration($t['duration'] !== null ? (int)$t['duration'] : null)) ?></td>
                    <td class="num play-count"
                        title="<?= $t['last_played'] ? 'Last played: ' . h($t['last_played']) : 'Never played' ?>">
                        <?= (int)$t['play_count'] ?>
                    </td>
                    <td class="num muted" style="white-space:nowrap;">
                        <?= $stamp ? h($stamp) : '' ?>
                    </td>
                    <td class="actions">
                        <button type="button" class="btn small play-btn">Play</button>
                        <?php if ($allowExt): ?>
                            <a class="btn small ghost"
                               href="play_external.php?id=<?= (int)$t['id'] ?>"
                               target="_blank" rel="noopener">
                                Open in <?= h($extName) ?>
                            </a>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>

    <div class="now-playing" id="now-playing">
        <div class="np-title" id="np-title">Nothing playing</div>
        <div class="np-sub" id="np-sub">Click <em>Play</em> on a track to start.</div>
        <audio id="audio" controls preload="none"></audio>
        <div class="toolbar" style="margin-top:10px;">
            <button type="button" class="btn small ghost" id="prev-btn">Previous</button>
            <button type="button" class="btn small ghost" id="next-btn">Next</button>
        </div>
    </div>

    <script>
    (function () {
        const audio = document.getElementById('audio');
        const npT   = document.getElementById('np-title');
        const npS   = document.getElementById('np-sub');
        const rows  = Array.from(document.querySelectorAll('#tracks-table tbody tr'));
        let currentIndex = -1;

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

        rows.forEach((row, i) => {
            const playBtn = row.querySelector('.play-btn');
            if (playBtn) playBtn.addEventListener('click', () => playIndex(i));
            const favBtn = row.querySelector('.fav-btn');
            if (favBtn) favBtn.addEventListener('click', () => toggleFav(row));
        });

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
        tr.playing td:nth-child(2)::before { content: "▶ "; color: var(--accent); }
    </style>
<?php endif; ?>

<?php require __DIR__ . '/footer.php';
