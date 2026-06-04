<?php
/**
 * About page — small static-ish info card sourced from the config table.
 *
 * Fields: program_name, version, github_url, author_name, author_email.
 * Every value falls through `cfg($key, $default)` so a fresh install renders
 * sensible content without any DB seeding; edit the values from config.php to
 * override.
 */
declare(strict_types=1);
require_once __DIR__ . '/db.php';

$programName = cfg('program_name', 'Musix');
$version     = cfg('version',      '0.1.3');
$githubUrl   = cfg('github_url',   'https://github.com/phpbbireland/musix');
$authorName  = cfg('author_name',  "Michael O'Toole (Mioke)");
$authorEmail = cfg('author_email', 'phpbbireland@gmail.com');

// Library stats — handy at-a-glance numbers, all from existing tables.
$stats = [
    'artists'   => (int)db()->query("SELECT COUNT(*) FROM artists")->fetchColumn(),
    'albums'    => (int)db()->query("SELECT COUNT(*) FROM albums")->fetchColumn(),
    'tracks'    => (int)db()->query("SELECT COUNT(*) FROM tracks WHERE excluded = 0")->fetchColumn(),
    'excluded'  => (int)db()->query("SELECT COUNT(*) FROM tracks WHERE excluded = 1")->fetchColumn(),
    'playlists' => (int)db()->query("SELECT COUNT(*) FROM playlists")->fetchColumn(),
    'duration'  => (int)db()->query("SELECT COALESCE(SUM(duration),0) FROM tracks WHERE excluded = 0")->fetchColumn(),
];
// SUM(duration) is in seconds. Convert to a friendlier "Xd Yh Zm" string.
$durLabel = '';
if ($stats['duration'] > 0) {
    $s = $stats['duration'];
    $d = intdiv($s, 86400); $s -= $d * 86400;
    $h = intdiv($s, 3600);  $s -= $h * 3600;
    $m = intdiv($s, 60);
    $parts = [];
    if ($d) $parts[] = $d . 'd';
    if ($h) $parts[] = $h . 'h';
    if ($m) $parts[] = $m . 'm';
    $durLabel = implode(' ', $parts) ?: '0m';
}

$page  = 'about';
$title = 'About — ' . $programName;
require __DIR__ . '/header.php';
?>

<h1>About <?= h($programName) ?></h1>

<div class="card" style="max-width: 740px;">
    <table class="kv">
        <tr><th>Program</th><td><?= h($programName) ?></td></tr>
        <tr><th>Version</th><td><?= h($version) ?></td></tr>
        <tr><th>Repository</th>
            <td>
                <?php if ($githubUrl !== ''): ?>
                    <a href="<?= h($githubUrl) ?>" target="_blank" rel="noopener">
                        <?= h($githubUrl) ?>
                    </a>
                <?php else: ?>
                    <span class="muted">—</span>
                <?php endif; ?>
            </td>
        </tr>
        <tr><th>Author</th><td><?= h($authorName) ?></td></tr>
        <tr><th>Contact</th>
            <td>
                <?php if ($authorEmail !== ''): ?>
                    <a href="mailto:<?= h($authorEmail) ?>"><?= h($authorEmail) ?></a>
                <?php else: ?>
                    <span class="muted">—</span>
                <?php endif; ?>
            </td>
        </tr>
    </table>

    <p class="muted" style="margin-top:16px; font-size:13px;">
        Edit these values on the <a href="config.php">config page</a>.
    </p>
</div>

<h2 style="margin-top:24px;">Library</h2>

<div class="card" style="max-width: 740px;">
    <table class="kv">
        <tr><th>Artists</th>   <td><?= number_format($stats['artists']) ?></td></tr>
        <tr><th>Albums</th>    <td><?= number_format($stats['albums']) ?></td></tr>
        <tr><th>Tracks</th>    <td><?= number_format($stats['tracks']) ?></td></tr>
        <?php if ($stats['excluded'] > 0): ?>
            <tr><th>Hidden tracks</th><td><?= number_format($stats['excluded']) ?> (soft-deleted)</td></tr>
        <?php endif; ?>
        <tr><th>Playlists</th> <td><?= number_format($stats['playlists']) ?></td></tr>
        <tr><th>Total time</th><td><?= h($durLabel ?: '—') ?></td></tr>
    </table>
</div>

<h2 style="margin-top:24px;">Info</h2>

<div class="card" style="max-width: 740px;">
    <div>
        To launch the browser based application: /home/Mike/Music/Musix/start-musix.sh<br>
        Also available as Deb Package and Appimage<br>
        musix-desktop | Musix | other
    </div>
</div>


<style>
    table.kv { width: 100%; border-collapse: collapse; }
    table.kv th, table.kv td { padding: 6px 10px; text-align: left; vertical-align: top; }
    table.kv th { width: 140px; color: var(--muted); font-weight: 500; }
    table.kv tr + tr th, table.kv tr + tr td { border-top: 1px solid var(--border); }
</style>

<?php require __DIR__ . '/footer.php';
