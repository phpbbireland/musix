<?php
declare(strict_types=1);
require_once __DIR__ . '/db.php';

$albumId = (int)($_GET['album_id'] ?? 0);
if ($albumId <= 0) { header('Location: artists.php'); exit; }

$album = db()->prepare(
    "SELECT al.id, al.album_name, al.year, al.cover_art, al.artist_id, a.name AS artist_name
     FROM albums al
     JOIN artists a ON a.id = al.artist_id
     WHERE al.id = :id"
);
$album->execute([':id' => $albumId]);
$album = $album->fetch();
if (!$album) { header('Location: artists.php'); exit; }

$tracks = db()->prepare(
    "SELECT t.id, t.track_name, t.track_no, t.year, t.bitrate, t.duration,
            t.file_path, t.play_count, t.last_played, t.is_favorite,
            g.name AS genre_name
     FROM tracks t
     LEFT JOIN genres g ON g.id = t.genre_id
     WHERE t.album_id = :a AND t.excluded = 0
     ORDER BY COALESCE(t.track_no, 9999), t.track_name"
);
$tracks->execute([':a' => $albumId]);
$tracks = $tracks->fetchAll();

// Existing playlists drive the "Add to playlist" picker dialog.
$playlists = db()->query("SELECT id, name FROM playlists ORDER BY name")->fetchAll();

// Genre list seeds the <datalist> in the tag editor so common genres are one
// click away. New genres typed in still upsert into the genres table.
$allGenres = db()->query("SELECT name FROM genres ORDER BY name")->fetchAll(PDO::FETCH_COLUMN);

$allowExt = cfg('allow_external_launch') === '1';
$extName  = cfg('external_player_name', 'External Player');

// Formats most browsers stream natively. FLAC/WAV/M4A work in modern
// Chromium + Firefox but Safari is patchy, and high-bitrate FLAC can stutter
// or refuse to play — so we flag those visually so the user knows to open
// in the external player.
$browserOk = ['mp3', 'ogg', 'oga'];

$page = 'artists';
$title = 'Musix — ' . $album['album_name'];
require __DIR__ . '/header.php';
?>

<div class="crumbs">
    <a href="artists.php">Artists</a> /
    <a href="albums.php?artist_id=<?= (int)$album['artist_id'] ?>"><?= h($album['artist_name']) ?></a> /
    <?= h($album['album_name']) ?>
</div>

<div class="album-header">
    <div class="cover-wrap">
        <?php if (!empty($album['cover_art'])): ?>
            <img id="cover-img" class="cover cover-lg" alt=""
                 src="cover.php?album_id=<?= (int)$album['id'] ?>">
        <?php else: ?>
            <div id="cover-img" class="cover cover-lg cover-placeholder" aria-hidden="true">♪</div>
        <?php endif; ?>
    </div>
    <div class="album-meta">
        <h1><?= h($album['album_name']) ?></h1>
        <p class="muted">
            <?= h($album['artist_name']) ?>
            <?= $album['year'] ? ' • ' . (int)$album['year'] : '' ?>
            • <?= count($tracks) ?> tracks
        </p>
        <div class="toolbar" style="margin-top:8px; gap:8px;">
            <button type="button" class="btn small ghost" id="cover-upload-btn"
                    title="Replace album cover (jpg/png/gif/webp, max 8 MB)">
                Upload cover
            </button>
            <?php
                // Quick "find me a cover" helper. Builds a Google Images
                // query from the album + artist and opens it in a new tab.
                // The user picks an image, saves it locally, then comes back
                // and clicks Upload cover. tbm=isch is Google's image-search
                // mode. The album name is quoted so distinctive titles pin
                // the search; the artist follows bare so Google can weight
                // both.
                $coverQuery = '"' . $album['album_name'] . '" '
                            . $album['artist_name'] . ' album cover';
            ?>
            <a class="btn small ghost"
               href="https://www.google.com/search?tbm=isch&amp;q=<?= h(urlencode($coverQuery)) ?>"
               target="_blank" rel="noopener"
               title="Open Google Images in a new tab — save a result then use Upload cover">
                Search Google for cover
            </a>
            <span id="cover-upload-msg" class="muted" style="font-size:12px;"></span>
            <input type="file" id="cover-upload-file" accept="image/jpeg,image/png,image/gif,image/webp" style="display:none;">
        </div>
    </div>
</div>

<script>
(function () {
    // Manual cover upload. Lives outside the tracks-only IIFE so it works on
    // empty albums too. POSTs the file to cover_upload.php; on success swaps
    // the cover element for a fresh <img> with a cache-busted URL so the
    // browser doesn't show the stale ETagged copy.
    const btn  = document.getElementById('cover-upload-btn');
    const file = document.getElementById('cover-upload-file');
    const msg  = document.getElementById('cover-upload-msg');
    const albumId = <?= (int)$album['id'] ?>;
    if (!btn || !file) return;

    btn.addEventListener('click', () => file.click());

    file.addEventListener('change', () => {
        if (!file.files || !file.files[0]) return;
        const f = file.files[0];

        // Match the server's 8 MB cap so we fail fast on obvious mistakes.
        if (f.size > 8 * 1024 * 1024) {
            msg.textContent = 'Too large (max 8 MB)';
            msg.classList.add('err');
            file.value = '';
            return;
        }

        const fd = new FormData();
        fd.append('album_id', albumId);
        fd.append('cover', f);

        msg.classList.remove('err');
        msg.textContent = 'Uploading...';
        btn.disabled = true;

        fetch('cover_upload.php', { method: 'POST', body: fd })
            .then(r => r.json().then(d => ({ ok: r.ok, data: d })))
            .then(({ ok, data }) => {
                btn.disabled = false;
                file.value = '';
                if (!ok || !data.ok) {
                    msg.textContent = (data && data.error) || 'Error';
                    msg.classList.add('err');
                    return;
                }
                // Swap the existing cover element for a fresh <img>. If we
                // were on a placeholder we drop the placeholder div; if we
                // already had an <img> we just replace it (cleaner than
                // mutating src and trying to clear a now-irrelevant cached
                // copy).
                const old = document.getElementById('cover-img');
                if (old) {
                    const img = document.createElement('img');
                    img.id = 'cover-img';
                    img.className = 'cover cover-lg';
                    img.alt = '';
                    img.src = data.cover_url;
                    old.replaceWith(img);
                }
                msg.textContent = 'Cover updated.';
                setTimeout(() => { msg.textContent = ''; }, 1500);
            })
            .catch(() => {
                btn.disabled = false;
                file.value = '';
                msg.textContent = 'Network error';
                msg.classList.add('err');
            });
    });
})();
</script>

<?php if (!$tracks): ?>
    <div class="card muted">No tracks in this album.</div>
<?php else: ?>
    <table id="tracks-table">
        <thead>
            <tr>
                <th class="num">#</th>
                <th class="fav"></th>
                <th>Track</th>
                <th class="num">Year</th>
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
                    data-artist="<?= h($album['artist_name']) ?>"
                    data-album="<?= h($album['album_name']) ?>"
                    data-track-no="<?= $t['track_no'] !== null ? (int)$t['track_no'] : '' ?>"
                    data-year="<?= $t['year'] !== null ? (int)$t['year'] : '' ?>"
                    data-duration="<?= $t['duration'] !== null ? (int)$t['duration'] : '' ?>"
                    data-genre="<?= h((string)($t['genre_name'] ?? '')) ?>">
                    <td class="num"><?= $t['track_no'] ? (int)$t['track_no'] : '' ?></td>
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
                        <?php if ($t['bitrate'] !== null): ?>
                            <span class="bitrate" title="<?= (int)$t['bitrate'] ?> kbps<?= $t['genre_name'] ? ' • ' . h($t['genre_name']) : '' ?>"><?= (int)$t['bitrate'] ?></span>
                        <?php endif; ?>
                    </td>
                    <td class="num"><?= $t['year'] ? (int)$t['year'] : '' ?></td>
                    <td class="num"><?= h(format_duration($t['duration'] !== null ? (int)$t['duration'] : null)) ?></td>
                    <td class="num play-count"
                        title="<?= $t['last_played'] ? 'Last played: ' . h($t['last_played']) : 'Never played' ?>">
                        <?= (int)$t['play_count'] ?>
                    </td>
                    <td class="actions">
                        <button type="button" class="btn small play-btn">Play</button>
                        <button type="button" class="btn small ghost add-pl-btn"
                                title="Add to playlist">&#10133;</button>
                        <button type="button" class="btn small ghost edit-btn"
                                title="Edit tags">&#9998;</button>
                        <button type="button" class="btn small ghost del-btn"
                                title="Delete track">&#128465;</button>
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

    <datalist id="genre-list">
        <?php foreach ($allGenres as $gn): ?>
            <option value="<?= h($gn) ?>"></option>
        <?php endforeach; ?>
    </datalist>

    <dialog id="del-dialog" class="pl-dialog">
        <form method="dialog">
            <h3 style="margin-top:0;">Delete track</h3>
            <p id="del-dialog-track" class="muted" style="margin-top:0;"></p>
            <p style="margin-top:0;">
                Hide this track from all views. The DB row stays (so play
                history and playlist links survive); every list filters on
                <code>excluded = 0</code>.
            </p>
            <label style="display:block; margin-bottom:12px;">
                <input type="checkbox" id="del-hard"> Also delete the audio
                file from disk <strong>(can't be undone)</strong>
            </label>
            <div id="del-msg" class="muted" style="min-height:1.4em; margin-bottom:8px;"></div>
            <div class="toolbar" style="justify-content:flex-end; gap:8px;">
                <button type="button" class="btn small ghost" id="del-cancel">Cancel</button>
                <button type="button" class="btn small" id="del-confirm">Delete</button>
            </div>
        </form>
    </dialog>

    <dialog id="edit-dialog" class="pl-dialog">
        <form method="dialog" id="edit-form">
            <h3 style="margin-top:0;">Edit track</h3>
            <p class="muted" style="margin-top:0; font-size:12px;">
                Editing artist or album moves only this track. Other tracks
                of the same album keep the old values.
            </p>

            <label for="edit-track-name">Track</label>
            <input type="text" id="edit-track-name" maxlength="255" required
                   style="width:100%; margin-bottom:8px;">

            <div style="display:grid; grid-template-columns: 1fr 1fr 1fr; gap:8px; margin-bottom:8px;">
                <div>
                    <label for="edit-track-no">#</label>
                    <input type="number" id="edit-track-no" min="1" max="999"
                           style="width:100%;">
                </div>
                <div>
                    <label for="edit-year">Year</label>
                    <input type="number" id="edit-year" min="1" max="9999"
                           style="width:100%;">
                </div>
                <div>
                    <label for="edit-duration" title="m:ss, h:mm:ss, or plain seconds">Time</label>
                    <input type="text" id="edit-duration" placeholder="m:ss"
                           pattern="\d+(:\d{1,2}){0,2}"
                           title="m:ss, h:mm:ss, or plain seconds"
                           style="width:100%;">
                </div>
            </div>

            <label for="edit-artist">Artist</label>
            <input type="text" id="edit-artist" maxlength="255" required
                   style="width:100%; margin-bottom:8px;">

            <label for="edit-album">Album</label>
            <input type="text" id="edit-album" maxlength="255" required
                   style="width:100%; margin-bottom:8px;">

            <label for="edit-genre">Genre</label>
            <input type="text" id="edit-genre" list="genre-list" maxlength="100"
                   style="width:100%; margin-bottom:12px;">

            <div id="edit-msg" class="muted" style="min-height:1.4em; margin-bottom:8px;"></div>

            <div class="toolbar" style="justify-content:flex-end; gap:8px;">
                <button type="button" class="btn small ghost" id="edit-cancel">Cancel</button>
                <button type="button" class="btn small" id="edit-save">Save</button>
            </div>
        </form>
    </dialog>

    <dialog id="pl-dialog" class="pl-dialog">
        <form method="dialog">
            <h3 style="margin-top:0;">Add to playlist</h3>
            <p class="muted" id="pl-dialog-track" style="margin-top:0;"></p>

            <?php if ($playlists): ?>
                <label for="pl-dialog-select">Existing playlist</label>
                <select id="pl-dialog-select" style="width:100%; margin-bottom:12px;">
                    <option value="">— pick one —</option>
                    <?php foreach ($playlists as $p): ?>
                        <option value="<?= (int)$p['id'] ?>"><?= h($p['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            <?php endif; ?>

            <label for="pl-dialog-new">…or create new</label>
            <input type="text" id="pl-dialog-new" maxlength="255"
                   placeholder="New playlist name"
                   style="width:100%; margin-bottom:12px;">

            <div id="pl-dialog-msg" class="muted" style="min-height:1.4em; margin-bottom:8px;"></div>

            <div class="toolbar" style="justify-content:flex-end; gap:8px;">
                <button type="button" class="btn small ghost" id="pl-dialog-cancel">Cancel</button>
                <button type="button" class="btn small" id="pl-dialog-add">Add</button>
            </div>
        </form>
    </dialog>

    <div class="now-playing" id="now-playing">
        <div class="np-title" id="np-title">Nothing playing</div>
        <div class="np-sub" id="np-sub">Click <em>Play</em> on a track to start.</div>
        <audio id="audio" controls preload="metadata"></audio>
        <div class="toolbar" style="margin-top:10px;">
            <button type="button" class="btn small ghost" id="prev-btn">Previous</button>
            <button type="button" class="btn small ghost" id="next-btn">Next</button>
            <?php if ($allowExt): ?>
                <span class="spacer"></span>
                <a class="btn small ghost" href="play_external.php?album_id=<?= (int)$album['id'] ?>" target="_blank" rel="noopener">
                    Open whole album in <?= h($extName) ?>
                </a>
            <?php endif; ?>
        </div>
    </div>

    <script>
    (function () {
        const audio  = document.getElementById('audio');
        const npT    = document.getElementById('np-title');
        const npS    = document.getElementById('np-sub');
        const rows   = Array.from(document.querySelectorAll('#tracks-table tbody tr'));
        let currentIndex = -1;

        // Load a track into the audio element + now-playing strip without
        // starting playback. Used on page open to preload the first track so
        // the native play/pause button has something to act on, and as a
        // building block for playIndex().
        function armTrack(i) {
            if (i < 0 || i >= rows.length) return;
            const row = rows[i];
            currentIndex = i;
            audio.src = row.dataset.stream;
            npT.textContent = row.dataset.title;
            npS.textContent = row.dataset.artist + ' — ' + row.dataset.album;
            rows.forEach(r => r.classList.remove('playing'));
            row.classList.add('playing');
        }

        function playIndex(i) {
            if (i < 0 || i >= rows.length) return;
            armTrack(i);
            audio.play().catch(() => {});
        }

        // Increment play_count + last_played, then refresh the row's count cell.
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
                .catch(() => { /* swallow — count bump isn't critical */ });
        }

        // Toggle favourite for the row's track. Updates DB then re-skins the button.
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

        // Playlist picker — single dialog reused by every row's + button.
        const dlg     = document.getElementById('pl-dialog');
        const dlgSel  = document.getElementById('pl-dialog-select');
        const dlgNew  = document.getElementById('pl-dialog-new');
        const dlgMsg  = document.getElementById('pl-dialog-msg');
        const dlgInfo = document.getElementById('pl-dialog-track');
        const dlgAdd  = document.getElementById('pl-dialog-add');
        const dlgCxl  = document.getElementById('pl-dialog-cancel');
        let pendingTrackId = null;

        function openPlDialog(row) {
            pendingTrackId = row.dataset.trackId;
            dlgInfo.textContent = row.dataset.title + ' — ' + row.dataset.artist;
            if (dlgSel) dlgSel.value = '';
            dlgNew.value = '';
            dlgMsg.textContent = '';
            dlgMsg.classList.remove('err');
            if (typeof dlg.showModal === 'function') {
                dlg.showModal();
            } else {
                // Fallback for ancient browsers — just prompt for a name.
                const name = prompt('New playlist name:');
                if (name) submitPlAdd({ new_playlist_name: name });
            }
        }

        function submitPlAdd(payload) {
            if (!pendingTrackId) return;
            const fd = new FormData();
            fd.append('track_id', pendingTrackId);
            for (const k in payload) fd.append(k, payload[k]);
            dlgAdd.disabled = true;
            dlgMsg.textContent = 'Adding...';
            fetch('playlist_add.php', { method: 'POST', body: fd })
                .then(r => r.json().then(d => ({ ok: r.ok, data: d })))
                .then(({ ok, data }) => {
                    dlgAdd.disabled = false;
                    if (!ok || !data.ok) {
                        dlgMsg.textContent = (data && data.error) || 'Error';
                        dlgMsg.classList.add('err');
                        return;
                    }
                    // Add the new playlist to the dropdown if it didn't exist before.
                    if (dlgSel && !Array.from(dlgSel.options).some(o => o.value == data.playlist_id)) {
                        const opt = document.createElement('option');
                        opt.value = data.playlist_id;
                        opt.textContent = data.playlist_name;
                        dlgSel.appendChild(opt);
                    }
                    dlgMsg.textContent = data.already
                        ? 'Already in "' + data.playlist_name + '"'
                        : 'Added to "' + data.playlist_name + '"';
                    setTimeout(() => { if (dlg.open) dlg.close(); }, 700);
                })
                .catch(() => {
                    dlgAdd.disabled = false;
                    dlgMsg.textContent = 'Network error';
                    dlgMsg.classList.add('err');
                });
        }

        if (dlgAdd) {
            dlgAdd.addEventListener('click', () => {
                const newName = dlgNew.value.trim();
                const plId    = dlgSel ? dlgSel.value : '';
                if (newName) {
                    submitPlAdd({ new_playlist_name: newName });
                } else if (plId) {
                    submitPlAdd({ playlist_id: plId });
                } else {
                    dlgMsg.textContent = 'Pick a playlist or enter a new name.';
                    dlgMsg.classList.add('err');
                }
            });
        }
        if (dlgCxl) dlgCxl.addEventListener('click', () => dlg.close());

        // -------- Tag editor --------
        // Inline modal lets the user fix track / artist / album / year / # /
        // genre without leaving the album view. POST goes to track_edit.php
        // which upserts artist+album+genre rows and UPDATEs the track. If the
        // album changed we drop the row from this view rather than leaving a
        // stale entry pointing at a different album.
        const edDlg   = document.getElementById('edit-dialog');
        const edTrack = document.getElementById('edit-track-name');
        const edNo    = document.getElementById('edit-track-no');
        const edYear  = document.getElementById('edit-year');
        const edDur   = document.getElementById('edit-duration');
        const edArt   = document.getElementById('edit-artist');
        const edAlb   = document.getElementById('edit-album');
        const edGen   = document.getElementById('edit-genre');

        // Render an integer seconds count as "m:ss" — mirrors db.php's
        // format_duration() so the editor and the table display match.
        function formatDuration(sec) {
            sec = parseInt(sec, 10);
            if (!Number.isFinite(sec) || sec < 0) return '';
            const m = Math.floor(sec / 60);
            const s = sec % 60;
            return m + ':' + (s < 10 ? '0' + s : s);
        }
        const edMsg   = document.getElementById('edit-msg');
        const edSave  = document.getElementById('edit-save');
        const edCxl   = document.getElementById('edit-cancel');
        let edRow = null;

        function openEditDialog(row) {
            edRow = row;
            edTrack.value = row.dataset.title || '';
            edNo.value    = row.dataset.trackNo || '';
            edYear.value  = row.dataset.year || '';
            edDur.value   = formatDuration(row.dataset.duration);
            edArt.value   = row.dataset.artist || '';
            edAlb.value   = row.dataset.album || '';
            edGen.value   = row.dataset.genre || '';
            edMsg.textContent = '';
            edMsg.classList.remove('err');
            if (typeof edDlg.showModal === 'function') {
                edDlg.showModal();
                setTimeout(() => edTrack.focus(), 0);
            }
        }

        // Patch the table row's visible cells + data-* attributes so the next
        // edit/play/fav action sees fresh values without a page reload.
        function applyTrackUpdate(row, t) {
            const cells = row.children;
            cells[0].textContent = t.track_no ? t.track_no : '';
            cells[3].textContent = t.year ? t.year : '';
            cells[4].textContent = formatDuration(t.duration);

            // Title cell has the track name as its leading text node, then an
            // ext span and an optional bitrate span. Replace just the first
            // text node so we don't disturb the spans.
            const titleCell = cells[2];
            const first = titleCell.firstChild;
            if (first && first.nodeType === Node.TEXT_NODE) {
                first.nodeValue = '\n                        ' + t.track_name + '\n                        ';
            }
            // Refresh the bitrate tooltip since the genre may have changed.
            const br = titleCell.querySelector('.bitrate');
            if (br) {
                const kbps = (br.getAttribute('title') || '').match(/^(\d+)\s*kbps/);
                const head = kbps ? kbps[1] + ' kbps' : br.textContent.trim() + ' kbps';
                br.setAttribute('title', head + (t.genre ? ' • ' + t.genre : ''));
            }

            row.dataset.title    = t.track_name;
            row.dataset.artist   = t.artist;
            row.dataset.album    = t.album;
            row.dataset.trackNo  = t.track_no || '';
            row.dataset.year     = t.year || '';
            row.dataset.duration = t.duration !== null && t.duration !== undefined ? t.duration : '';
            row.dataset.genre    = t.genre || '';

            // If this row is currently playing, refresh the now-playing strip.
            if (row.classList.contains('playing')) {
                npT.textContent = t.track_name;
                npS.textContent = t.artist + ' — ' + t.album;
            }
        }

        function submitEdit() {
            if (!edRow) return;
            const fd = new FormData();
            fd.append('id',         edRow.dataset.trackId);
            fd.append('track_name', edTrack.value.trim());
            fd.append('track_no',   edNo.value.trim());
            fd.append('year',       edYear.value.trim());
            fd.append('duration',   edDur.value.trim());
            fd.append('artist',     edArt.value.trim());
            fd.append('album',      edAlb.value.trim());
            fd.append('genre',      edGen.value.trim());

            edSave.disabled = true;
            edMsg.classList.remove('err');
            edMsg.textContent = 'Saving...';

            fetch('track_edit.php', { method: 'POST', body: fd })
                .then(r => r.json().then(d => ({ ok: r.ok, data: d })))
                .then(({ ok, data }) => {
                    edSave.disabled = false;
                    if (!ok || !data.ok) {
                        edMsg.textContent = (data && data.error) || 'Error';
                        edMsg.classList.add('err');
                        return;
                    }
                    if (data.moved_album) {
                        // Track no longer belongs on this page — drop it.
                        const idx = rows.indexOf(edRow);
                        if (idx >= 0) rows.splice(idx, 1);
                        edRow.remove();
                        edMsg.textContent = 'Moved to another album.';
                    } else {
                        applyTrackUpdate(edRow, data.track);
                        edMsg.textContent = 'Saved.';
                    }
                    setTimeout(() => { if (edDlg.open) edDlg.close(); }, 500);
                })
                .catch(() => {
                    edSave.disabled = false;
                    edMsg.textContent = 'Network error';
                    edMsg.classList.add('err');
                });
        }

        if (edSave) edSave.addEventListener('click', submitEdit);
        if (edCxl)  edCxl.addEventListener('click', () => edDlg.close());

        // -------- Delete track --------
        // Soft delete is the default (excluded=1). Hard delete unlinks the
        // file off disk after a server-side containment check against the
        // configured library_path.
        const delDlg   = document.getElementById('del-dialog');
        const delInfo  = document.getElementById('del-dialog-track');
        const delHard  = document.getElementById('del-hard');
        const delMsg   = document.getElementById('del-msg');
        const delOk    = document.getElementById('del-confirm');
        const delCxl   = document.getElementById('del-cancel');
        let delRow = null;

        function openDelDialog(row) {
            delRow = row;
            delInfo.textContent = row.dataset.title + ' — ' + row.dataset.artist;
            delHard.checked = false;
            delMsg.textContent = '';
            delMsg.classList.remove('err');
            if (typeof delDlg.showModal === 'function') delDlg.showModal();
        }

        function submitDelete() {
            if (!delRow) return;
            const fd = new FormData();
            fd.append('id', delRow.dataset.trackId);
            fd.append('mode', delHard.checked ? 'hard' : 'soft');
            delOk.disabled = true;
            delMsg.classList.remove('err');
            delMsg.textContent = 'Deleting...';
            fetch('track_delete.php', { method: 'POST', body: fd })
                .then(r => r.json().then(d => ({ ok: r.ok, data: d })))
                .then(({ ok, data }) => {
                    delOk.disabled = false;
                    if (!ok || !data.ok) {
                        delMsg.textContent = (data && data.error) || 'Error';
                        delMsg.classList.add('err');
                        return;
                    }
                    // Drop the row regardless of mode — soft-deleted tracks
                    // shouldn't be visible here either.
                    const idx = rows.indexOf(delRow);
                    if (idx >= 0) rows.splice(idx, 1);
                    delRow.remove();
                    delMsg.textContent = data.mode === 'hard'
                        ? (data.file_error ? 'DB row deleted; ' + data.file_error : 'Deleted from disk and DB.')
                        : 'Hidden from views.';
                    setTimeout(() => { if (delDlg.open) delDlg.close(); }, 600);
                })
                .catch(() => {
                    delOk.disabled = false;
                    delMsg.textContent = 'Network error';
                    delMsg.classList.add('err');
                });
        }

        if (delOk)  delOk.addEventListener('click', submitDelete);
        if (delCxl) delCxl.addEventListener('click', () => delDlg.close());

        rows.forEach((row, i) => {
            const playBtn = row.querySelector('.play-btn');
            playBtn.addEventListener('click', () => playIndex(i));
            const favBtn = row.querySelector('.fav-btn');
            if (favBtn) favBtn.addEventListener('click', () => toggleFav(row));
            const addBtn = row.querySelector('.add-pl-btn');
            if (addBtn) addBtn.addEventListener('click', () => openPlDialog(row));
            const edBtn  = row.querySelector('.edit-btn');
            if (edBtn) edBtn.addEventListener('click', () => openEditDialog(row));
            const dlBtn  = row.querySelector('.del-btn');
            if (dlBtn) dlBtn.addEventListener('click', () => openDelDialog(row));
        });

        document.getElementById('prev-btn').addEventListener('click', () => playIndex(currentIndex - 1));
        document.getElementById('next-btn').addEventListener('click', () => playIndex(currentIndex + 1));

        // Count only fully-listened tracks: bump on 'ended', then advance.
        audio.addEventListener('ended', () => {
            if (currentIndex >= 0 && currentIndex < rows.length) {
                bumpPlay(rows[currentIndex]);
            }
            playIndex(currentIndex + 1);
        });

        // Preload the first track so the native <audio> play/pause button
        // works out of the gate. preload="metadata" only fetches headers, not
        // the audio body, so this doesn't burn bandwidth — it just lets the
        // browser show duration and lets a click on the native ► do what
        // you'd expect (start playing track 1) without first clicking a row.
        if (rows.length > 0) armTrack(0);
        // When the user hits the native play button (rather than a row's
        // Play action), it'll just resume the armed track. The 'ended'
        // handler above will then advance to the next track normally.
    })();
    </script>
    <style>
        tr.playing { background: var(--panel-2); }
        tr.playing td:nth-child(3)::before { content: "▶ "; color: var(--accent); }
    </style>
<?php endif; ?>

<?php require __DIR__ . '/footer.php';
