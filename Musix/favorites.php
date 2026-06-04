<?php
declare(strict_types=1);
require_once __DIR__ . '/db.php';

$tracks = db()->query(
    "SELECT t.id, t.track_name, t.duration, t.play_count, t.last_played, t.is_favorite,
            al.id AS album_id, al.album_name,
            ar.id AS artist_id, ar.name AS artist_name
     FROM tracks t
     JOIN albums  al ON al.id = t.album_id
     JOIN artists ar ON ar.id = t.artist_id
     WHERE t.is_favorite = 1 AND t.excluded = 0
     ORDER BY ar.name, al.album_name, COALESCE(t.track_no, 9999), t.track_name"
)->fetchAll();

$allowExt = cfg('allow_external_launch') === '1';
$extName  = cfg('external_player_name', 'External Player');

$page  = 'favorites';
$title = 'Musix — Favourites';
require __DIR__ . '/header.php';
?>

<h1>Favourites</h1>

<?php if (!$tracks): ?>
    <div class="card muted">
        No favourites yet. Click the ☆ next to a track on any album page to favourite it.
    </div>
<?php else: ?>
    <p class="muted"><?= count($tracks) ?> tracks</p>

    <table id="tracks-table">
        <thead>
            <tr>
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
            <?php foreach ($tracks as $t): ?>
                <tr data-track-id="<?= (int)$t['id'] ?>"
                    data-stream="stream.php?id=<?= (int)$t['id'] ?>"
                    data-title="<?= h($t['track_name']) ?>"
                    data-artist="<?= h($t['artist_name']) ?>"
                    data-album="<?= h($t['album_name']) ?>">
                    <td class="fav">
                        <button type="button"
                                class="fav-btn is-fav"
                                aria-pressed="true"
                                title="Unfavourite">★</button>
                    </td>
                    <td><?= h($t['track_name']) ?></td>
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
        let rows    = Array.from(document.querySelectorAll('#tracks-table tbody tr'));
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

        // On the favourites page, unfavouriting removes the row from the list.
        function toggleFav(row) {
            const id = row.dataset.trackId;
            if (!id) return;
            const fd = new FormData();
            fd.append('id', id);
            fetch('toggle_favorite.php', { method: 'POST', body: fd })
                .then(r => r.ok ? r.json() : null)
                .then(data => {
                    if (!data || !data.ok) return;
                    if (data.is_favorite === 0) {
                        // Removed from favourites: drop the row.
                        const wasPlaying = row.classList.contains('playing');
                        const idxBefore = rows.indexOf(row);
                        row.remove();
                        refreshRows();
                        if (wasPlaying) {
                            // We just dropped the currently-playing row;
                            // currentIndex now points at the next track in line.
                            currentIndex = Math.min(idxBefore, rows.length) - 1;
                        } else if (idxBefore < currentIndex) {
                            currentIndex--;
                        }
                    }
                })
                .catch(() => {});
        }

        function wireRow(row, i) {
            const playBtn = row.querySelector('.play-btn');
            if (playBtn) playBtn.addEventListener('click', () => {
                playIndex(rows.indexOf(row));
            });
            const favBtn = row.querySelector('.fav-btn');
            if (favBtn) favBtn.addEventListener('click', () => toggleFav(row));
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
        tr.playing td:nth-child(2)::before { content: "▶ "; color: var(--accent); }
    </style>
<?php endif; ?>

<?php require __DIR__ . '/footer.php';
