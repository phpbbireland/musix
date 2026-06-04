<?php
declare(strict_types=1);
require_once __DIR__ . '/db.php';

/*
 * Two modes:
 *   - albums.php              → library-wide overview, every album grouped by artist
 *   - albums.php?artist_id=N  → per-artist tile grid (legacy view, kept so old
 *                               links from artists.php / albums_by_genre.php
 *                               keep working)
 */

$artistId = (int)($_GET['artist_id'] ?? 0);
$page     = 'albums';

if ($artistId > 0) {

    // ---------- Per-artist grid (unchanged behaviour) ----------
    $artist = db()->prepare("SELECT id, name FROM artists WHERE id = :id");
    $artist->execute([':id' => $artistId]);
    $artist = $artist->fetch();
    if (!$artist) { header('Location: albums.php'); exit; }

    $albums = db()->prepare(
        "SELECT al.id, al.album_name, al.year, al.cover_art,
                COUNT(t.id) AS track_count,
                COALESCE(SUM(t.duration), 0) AS total_duration
         FROM albums al
         LEFT JOIN tracks t ON t.album_id = al.id AND t.excluded = 0
         WHERE al.artist_id = :a
         GROUP BY al.id, al.album_name, al.year, al.cover_art
         ORDER BY al.year, al.album_name"
    );
    $albums->execute([':a' => $artistId]);
    $albums = $albums->fetchAll();

    $title = 'Musix — ' . $artist['name'];
    require __DIR__ . '/header.php';
    ?>
    <div class="crumbs">
        <a href="albums.php">Albums</a> / <?= h($artist['name']) ?>
    </div>

    <h1><?= h($artist['name']) ?></h1>

    <?php if (!$albums): ?>
        <div class="card muted">No albums yet for this artist.</div>
    <?php else: ?>
        <div class="grid">
            <?php foreach ($albums as $al): ?>
                <div class="tile">
                    <a class="cover-link" href="tracks.php?album_id=<?= (int)$al['id'] ?>">
                        <?php if (!empty($al['cover_art'])): ?>
                            <img class="cover" loading="lazy" alt=""
                                 src="cover.php?album_id=<?= (int)$al['id'] ?>">
                        <?php else: ?>
                            <div class="cover cover-placeholder" aria-hidden="true">&#9834;</div>
                        <?php endif; ?>
                    </a>
                    <a class="title" href="tracks.php?album_id=<?= (int)$al['id'] ?>">
                        <?= h($al['album_name']) ?>
                    </a>
                    <div class="sub">
                        <?= $al['year'] ? (int)$al['year'] . ' &bull; ' : '' ?>
                        <?= (int)$al['track_count'] ?> tracks
                        <?php if ((int)$al['total_duration'] > 0): ?>
                            &bull; <?= h(format_duration((int)$al['total_duration'])) ?>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
    <?php

} else {

    // ---------- Library-wide overview, grouped by artist ----------
    //
    // Sort applies at two levels: the order of artists down the page, and the
    // order of albums within each artist. They share the same axis so a single
    // dropdown drives both.
    //   year  → artists by earliest album year (chronological tour);
    //           albums within by year
    //   name  → artists alphabetical (the classic look);
    //           albums within by name
    //   genre → artists by their dominant genre (most-frequent across all
    //           their tracks); albums within by their own representative
    //           genre, then year
    // Missing keys (NULL year, NULL genre) always sink to the end.
    $sort = $_GET['sort'] ?? 'genre';
    if (!in_array($sort, ['year', 'name', 'genre'], true)) $sort = 'year';

    // ---- Step 1: per-artist metadata for sorting ----------------------------
    // NULLIF(year, 0) so the stray "year = 0" rows in albums don't dominate
    // the chronological view (1 such row at last count). MIN ignores NULLs,
    // so an artist's only 0-year album still produces a NULL earliest_year
    // and gets pushed to the end of year-sort.
    $artists = db()->query(
        "SELECT a.id, a.name,
                (SELECT MIN(NULLIF(al2.year, 0))
                   FROM albums al2
                  WHERE al2.artist_id = a.id) AS earliest_year,
                (SELECT g.name
                   FROM tracks t2
                   JOIN genres g  ON g.id  = t2.genre_id
                   JOIN albums al3 ON al3.id = t2.album_id
                  WHERE al3.artist_id = a.id AND t2.excluded = 0
                  GROUP BY g.id, g.name
                  ORDER BY COUNT(*) DESC, MIN(t2.id)
                  LIMIT 1) AS dominant_genre
         FROM artists a
         WHERE EXISTS (SELECT 1 FROM albums al WHERE al.artist_id = a.id)"
    )->fetchAll();

    // Sort artists in PHP — keeps the SQL boring and the comparator easy to
    // tweak. NULL keys go to the end; secondary tiebreak is always alphabetical.
    usort($artists, function ($x, $y) use ($sort) {
        if ($sort === 'year') {
            $xn = $x['earliest_year'] === null;
            $yn = $y['earliest_year'] === null;
            if ($xn !== $yn) return $xn ? 1 : -1;
            if (!$xn && (int)$x['earliest_year'] !== (int)$y['earliest_year']) {
                return (int)$x['earliest_year'] <=> (int)$y['earliest_year'];
            }
            return strcasecmp($x['name'], $y['name']);
        }
        if ($sort === 'genre') {
            $xn = empty($x['dominant_genre']);
            $yn = empty($y['dominant_genre']);
            if ($xn !== $yn) return $xn ? 1 : -1;
            $c = strcasecmp((string)$x['dominant_genre'], (string)$y['dominant_genre']);
            if ($c !== 0) return $c;
            return strcasecmp($x['name'], $y['name']);
        }
        return strcasecmp($x['name'], $y['name']);  // sort=name
    });

    // ---- Step 2: one query for all albums, bucketed by artist --------------
    // The within-artist sort still happens in SQL — fast and avoids dragging
    // year/genre comparison logic into PHP twice.
    $inner = match ($sort) {
        'name'  => 'al.album_name, al.year',
        // "(col IS NULL)" trick pushes NULLs to the end regardless of ASC/DESC.
        'genre' => '(genre_name IS NULL), genre_name, (al.year IS NULL OR al.year = 0), al.year, al.album_name',
        default => '(al.year IS NULL OR al.year = 0), al.year, al.album_name',
    };

    // Representative genre per album = the genre held by the *most* of the
    // album's non-excluded tracks. Picks the majority on mixed albums and
    // collapses to the single-genre answer when every track agrees. Ties
    // broken by the smallest matching track id so the result is deterministic.
    // (Albums don't have their own genre column — only tracks do.)
    $albumRows = db()->query(
        "SELECT al.id, al.album_name, al.year, al.cover_art, al.artist_id,
                (SELECT g.name
                   FROM tracks t2
                   JOIN genres g ON g.id = t2.genre_id
                  WHERE t2.album_id = al.id AND t2.excluded = 0
                  GROUP BY g.id, g.name
                  ORDER BY COUNT(*) DESC, MIN(t2.id)
                  LIMIT 1) AS genre_name
         FROM albums al
         ORDER BY al.artist_id, $inner"
    )->fetchAll();

    // Bucket albums by artist_id so the render loop can pull a list per artist
    // in O(1).
    $byArtist = [];
    foreach ($albumRows as $r) {
        $byArtist[(int)$r['artist_id']][] = $r;
    }

    // ---- Step 3: assemble in the chosen artist order -----------------------
    $grouped = [];
    foreach ($artists as $a) {
        $aid = (int)$a['id'];
        if (empty($byArtist[$aid])) continue;  // belt + braces; EXISTS already filtered
        $grouped[$aid] = [
            'artist_id'      => $aid,
            'artist_name'    => $a['name'],
            'albums'         => $byArtist[$aid],
            'dominant_genre' => $a['dominant_genre'],
            'earliest_year'  => $a['earliest_year'],
        ];
    }

    // Album/artist counts for the toolbar summary.
    $totalAlbums = count($albumRows);

    $title = 'Musix — Albums';
    require __DIR__ . '/header.php';
    ?>

    <h1>Albums</h1>

    <form class="toolbar" method="get">
        <label class="muted" for="sort-sel" style="margin-bottom:0;">Sort albums by</label>
        <select id="sort-sel" name="sort" onchange="this.form.submit()" style="max-width:180px;">
            <option value="year"  <?= $sort === 'year'  ? 'selected' : '' ?>>Year</option>
            <option value="name"  <?= $sort === 'name'  ? 'selected' : '' ?>>Album name</option>
            <option value="genre" <?= $sort === 'genre' ? 'selected' : '' ?>>Genre</option>
        </select>
        <noscript><button type="submit">Apply</button></noscript>
        <span class="spacer"></span>
        <span class="muted">
            <?= $totalAlbums ?> album<?= $totalAlbums === 1 ? '' : 's' ?>
            &bull;
            <?= count($grouped) ?> artist<?= count($grouped) === 1 ? '' : 's' ?>
        </span>
    </form>

    <?php if (!$grouped): ?>
        <div class="card muted">
            No albums yet.
            <?php if (cfg('library_path') === ''): ?>
                <a href="config.php">Set a library path</a> and then run a scan.
            <?php else: ?>
                <a href="scan.php">Run a scan</a> to populate the library.
            <?php endif; ?>
        </div>
    <?php else: ?>
        <?php foreach ($grouped as $g): ?>
            <section class="artist-group">
                <h2 class="artist-heading">
                    <a href="albums.php?artist_id=<?= (int)$g['artist_id'] ?>"><?= h($g['artist_name']) ?></a>
                    <span class="muted">
                        &middot; <?= count($g['albums']) ?> album<?= count($g['albums']) === 1 ? '' : 's' ?>
                        <?php
                            // Echo the sort key responsible for this artist's
                            // position in the page, so the active sort is
                            // visible at a glance.
                            if ($sort === 'year'  && !empty($g['earliest_year'])) {
                                echo ' &middot; from ' . (int)$g['earliest_year'];
                            } elseif ($sort === 'genre' && !empty($g['dominant_genre'])) {
                                echo ' &middot; ' . h($g['dominant_genre']);
                            }
                        ?>
                    </span>
                </h2>
                <div class="album-row">
                    <?php foreach ($g['albums'] as $al): ?>
                        <?php
                            // Treat 0 as missing — only one such row exists,
                            // but printing "(0)" would be embarrassing.
                            $showYear = !empty($al['year']) && (int)$al['year'] > 0;
                            $titleAttr = $al['album_name']
                                . ($showYear ? ' (' . (int)$al['year'] . ')' : '')
                                . (!empty($al['genre_name']) ? ' — ' . $al['genre_name'] : '');
                        ?>
                        <a class="album-thumb" href="tracks.php?album_id=<?= (int)$al['id'] ?>"
                           title="<?= h($titleAttr) ?>">
                            <?php if (!empty($al['cover_art'])): ?>
                                <img class="cover" loading="lazy" alt=""
                                     src="cover.php?album_id=<?= (int)$al['id'] ?>">
                            <?php else: ?>
                                <div class="cover cover-placeholder" aria-hidden="true">&#9834;</div>
                            <?php endif; ?>
                            <div class="album-thumb-name"><?= h($al['album_name']) ?></div>
                            <?php
                                // Meta line: year + representative genre when
                                // each is available. Shown in every sort mode
                                // so the picked genre is always visible — the
                                // pick (majority of tracks) can be inspected
                                // without flipping the sort dropdown.
                                $bits = [];
                                if ($showYear)                 $bits[] = (int)$al['year'];
                                if (!empty($al['genre_name'])) $bits[] = $al['genre_name'];
                            ?>
                            <?php if ($bits): ?>
                                <div class="album-thumb-meta"><?= h(implode(' • ', $bits)) ?></div>
                            <?php endif; ?>
                        </a>
                    <?php endforeach; ?>
                </div>
            </section>
        <?php endforeach; ?>
    <?php endif; ?>
    <?php
}

require __DIR__ . '/footer.php';
