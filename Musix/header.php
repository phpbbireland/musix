<?php
require_once __DIR__ . '/db.php';
$page = $page ?? '';
$title = $title ?? 'Musix';
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= h($title) ?></title>
    <link rel="stylesheet" href="style.css">
    <script>
    // Apply the stored (or system-preferred) theme before first paint so
    // navigating between pages doesn't show a flash of the wrong theme.
    // Runs as early as possible, blocking, in <head>.
    (function () {
        var m = document.cookie.match(/(?:^|; )musix_theme=([^;]+)/);
        var t = m ? decodeURIComponent(m[1]) : '';
        if (t !== 'light' && t !== 'dark') {
            // No explicit choice — fall back to OS preference.
            t = window.matchMedia && window.matchMedia('(prefers-color-scheme: light)').matches
                ? 'light' : 'dark';
        }
        // We always set the attribute (rather than only on light) so the
        // toggle script knows the resolved mode without re-querying.
        document.documentElement.setAttribute('data-theme', t);
    })();
    </script>
</head>
<body>
<header class="topbar">
    <div class="brand"><a href="about.php" title="About"><?= h(cfg('program_name', 'Musix')) ?></a></div>
    <nav>
        <a href="artists.php"          class="<?= $page === 'artists'   ? 'active' : '' ?>">Artists</a>
        <a href="albums.php"           class="<?= $page === 'albums'    ? 'active' : '' ?>">Albums</a>
        <a href="albums_by_genre.php"  class="<?= $page === 'genres'    ? 'active' : '' ?>">Genres</a>
        <a href="recent.php"           class="<?= $page === 'recent'    ? 'active' : '' ?>">Recent</a>
        <a href="favorites.php" class="<?= $page === 'favorites' ? 'active' : '' ?>">Favourites</a>
        <a href="playlists.php" class="<?= $page === 'playlists' ? 'active' : '' ?>">Playlists</a>
        <a href="config.php"    class="<?= $page === 'config'    ? 'active' : '' ?>">Config</a>
        <a href="tools.php"     class="<?= $page === 'tools'     ? 'active' : '' ?>">Tools</a>
    </nav>
    <form class="topbar-search" method="get" action="search.php" role="search">
        <input type="text" name="q"
               value="<?= h($page === 'search' ? ($_GET['q'] ?? '') : '') ?>"
               placeholder="Search..."
               aria-label="Search">
    </form>
    <button type="button" id="theme-toggle" class="theme-toggle"
            title="Toggle light / dark / auto theme" aria-label="Toggle theme">◐</button>
</header>
<script>
// Wire up the theme toggle. Cycles auto → light → dark → auto. The
// "auto" state means no cookie — the head script will then re-detect
// the OS preference on next paint.
(function () {
    var btn = document.getElementById('theme-toggle');
    if (!btn) return;

    function readChoice() {
        var m = document.cookie.match(/(?:^|; )musix_theme=([^;]+)/);
        return m ? decodeURIComponent(m[1]) : 'auto';
    }
    function writeChoice(mode) {
        if (mode === 'auto') {
            // Clear the cookie. Max-Age=0 expires it immediately.
            document.cookie = 'musix_theme=; path=/; max-age=0; SameSite=Lax';
        } else {
            // 1-year cookie; per-browser, no server round-trip needed.
            document.cookie = 'musix_theme=' + mode + '; path=/; max-age=31536000; SameSite=Lax';
        }
    }
    function applyResolved(choice) {
        var resolved = choice;
        if (choice === 'auto') {
            resolved = window.matchMedia && window.matchMedia('(prefers-color-scheme: light)').matches
                ? 'light' : 'dark';
        }
        document.documentElement.setAttribute('data-theme', resolved);
    }
    function paintButton(choice) {
        // Glyph reflects the chosen mode, not the resolved one — so
        // the user can see what they picked.
        var glyph = choice === 'light' ? '☀' : choice === 'dark' ? '☾' : '◐';
        var label = choice === 'light' ? 'Theme: light (click for dark)'
                  : choice === 'dark'  ? 'Theme: dark (click for auto)'
                  :                      'Theme: auto (click for light)';
        btn.textContent = glyph;
        btn.title = label;
        btn.setAttribute('aria-label', label);
    }

    var choice = readChoice();
    paintButton(choice);

    btn.addEventListener('click', function () {
        choice = choice === 'auto'  ? 'light'
               : choice === 'light' ? 'dark'
               :                      'auto';
        writeChoice(choice);
        applyResolved(choice);
        paintButton(choice);
    });

    // If user is in "auto" mode and the OS theme flips while the page is
    // open, follow it live.
    if (window.matchMedia) {
        var mq = window.matchMedia('(prefers-color-scheme: light)');
        var listen = function () { if (readChoice() === 'auto') applyResolved('auto'); };
        if (mq.addEventListener) mq.addEventListener('change', listen);
        else if (mq.addListener) mq.addListener(listen);
    }
})();
</script>
<main class="container">
