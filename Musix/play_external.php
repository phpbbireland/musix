<?php
declare(strict_types=1);
require_once __DIR__ . '/db.php';

if (cfg('allow_external_launch') !== '1') {
    http_response_code(403);
    exit('External launch is disabled. Enable it in config.php.');
}

$playerCmd = trim((string)cfg('external_player_path', ''));
$playerName = (string)cfg('external_player_name', 'Player');
if ($playerCmd === '') {
    http_response_code(400);
    exit('No external player configured.');
}

$libraryPath = (string)cfg('library_path', '');
$libReal = $libraryPath !== '' ? realpath($libraryPath) : false;

$paths = [];

if (isset($_GET['id'])) {
    $stmt = db()->prepare("SELECT file_path FROM tracks WHERE id = :id AND excluded = 0");
    $stmt->execute([':id' => (int)$_GET['id']]);
    $p = $stmt->fetchColumn();
    if ($p) $paths[] = (string)$p;
} elseif (isset($_GET['album_id'])) {
    $stmt = db()->prepare(
        "SELECT file_path FROM tracks
         WHERE album_id = :a AND excluded = 0
         ORDER BY COALESCE(track_no, 9999), track_name"
    );
    $stmt->execute([':a' => (int)$_GET['album_id']]);
    $paths = array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN));
} else {
    http_response_code(400);
    exit('Pass id=<track> or album_id=<album>.');
}

if (!$paths) { http_response_code(404); exit('Nothing to play.'); }

// Constrain every path to be inside library_path
$safePaths = [];
foreach ($paths as $p) {
    $real = realpath($p);
    if ($real && (!$libReal || str_starts_with($real, rtrim($libReal, '/') . '/'))) {
        if (is_readable($real)) $safePaths[] = $real;
    }
}
if (!$safePaths) { http_response_code(404); exit('No valid files.'); }

// Build command. Each path quoted with escapeshellarg.
// Add VLC-specific flags to (a) force the Qt GUI as the only interface so the
// lua RC interface doesn't get loaded and immediately shut down on EOF, and
// (b) close VLC when the queue ends.
$playerArgs = '';
if (preg_match('/(^|\/)c?vlc$/', trim($playerCmd))) {
    $playerArgs = '-I qt --extraintf "" --play-and-exit ';
}
$args = array_map('escapeshellarg', $safePaths);
$rawCmd = escapeshellcmd($playerCmd) . ' ' . $playerArgs . implode(' ', $args);

// Who should the player run as?
$phpUser    = function_exists('posix_geteuid') ? posix_getpwuid(posix_geteuid())['name'] : null;
$targetUser = trim((string)cfg('external_player_user', '')) ?: ($phpUser ?: 'Mike');
$useSudo    = ($targetUser !== '' && $targetUser !== $phpUser);

// Resolve target user info so we can set HOME / XDG_RUNTIME_DIR correctly.
$targetUid  = null;
$targetHome = '/home/' . $targetUser;
if (function_exists('posix_getpwnam')) {
    $pw = posix_getpwnam($targetUser);
    if ($pw) {
        $targetUid  = (int)$pw['uid'];
        $targetHome = $pw['dir'] ?: $targetHome;
    }
}

$display    = (string)(cfg('display_env') ?: ':0.0');
$xauthority = (string)(cfg('xauthority_path') ?: ($targetHome . '/.Xauthority'));
$xdgRuntime = $targetUid !== null ? "/run/user/$targetUid" : '';
$logFile    = '/tmp/musix_external_player.log';

// Try to grab the target user's live session env from /proc — Qt apps need
// DBUS_SESSION_BUS_ADDRESS, sometimes WAYLAND_DISPLAY, GDK_BACKEND, etc.
// We pick a process owned by the target user that has DBUS set, and read its env.
$harvestedEnv = [];
if ($targetUid !== null) {
    $wantKeys = [
        'DBUS_SESSION_BUS_ADDRESS', 'XDG_SESSION_TYPE', 'XDG_SESSION_CLASS',
        'XDG_CURRENT_DESKTOP', 'WAYLAND_DISPLAY', 'GDK_BACKEND',
        'QT_QPA_PLATFORM', 'QT_QPA_PLATFORMTHEME',
        'PULSE_SERVER', 'GTK_IM_MODULE', 'XMODIFIERS', 'PATH', 'LANG', 'LC_ALL',
    ];
    foreach (glob('/proc/[0-9]*') ?: [] as $procDir) {
        $statusFile = $procDir . '/status';
        if (!is_readable($statusFile)) continue;
        $status = @file_get_contents($statusFile);
        if (!$status || !preg_match('/^Uid:\s+' . $targetUid . '\b/m', $status)) continue;
        $envBlob = @file_get_contents($procDir . '/environ');
        if (!$envBlob || !str_contains($envBlob, 'DBUS_SESSION_BUS_ADDRESS=')) continue;
        foreach (explode("\0", $envBlob) as $entry) {
            $eq = strpos($entry, '=');
            if ($eq === false) continue;
            $k = substr($entry, 0, $eq);
            if (in_array($k, $wantKeys, true)) {
                $harvestedEnv[$k] = substr($entry, $eq + 1);
            }
        }
        if ($harvestedEnv) break;
    }
}

// Reasonable fallback for DBUS if we couldn't read /proc env
if (!isset($harvestedEnv['DBUS_SESSION_BUS_ADDRESS']) && $xdgRuntime !== '') {
    $harvestedEnv['DBUS_SESSION_BUS_ADDRESS'] = 'unix:path=' . $xdgRuntime . '/bus';
}

$envBits = [
    'HOME='       . escapeshellarg($targetHome),
    'DISPLAY='    . escapeshellarg($display),
    'XAUTHORITY=' . escapeshellarg($xauthority),
];
if ($xdgRuntime !== '') $envBits[] = 'XDG_RUNTIME_DIR=' . escapeshellarg($xdgRuntime);
foreach ($harvestedEnv as $k => $v) {
    $envBits[] = $k . '=' . escapeshellarg($v);
}

$envPrefix = implode(' ', $envBits) . ' ';

// Always go through `env` so the VAR=value pairs are interpreted by env, not
// by whatever runs the command (nohup, sudo). `-u LD_LIBRARY_PATH -u LD_PRELOAD`
// strips XAMPP's injected lib paths so plugins load against system libs.
$envCmd = 'env -u LD_LIBRARY_PATH -u LD_PRELOAD ';
if ($useSudo) {
    $launchCmd = 'sudo -n -u ' . escapeshellarg($targetUser) . ' ' . $envCmd . $envPrefix . $rawCmd;
} else {
    $launchCmd = $envCmd . $envPrefix . $rawCmd;
}

$errorMsg = null;

if (stripos(PHP_OS_FAMILY, 'Windows') === 0) {
    pclose(popen('start /B "" ' . $rawCmd, 'r'));
} else {
    $harvestSummary = $harvestedEnv
        ? implode(' ', array_map(fn($k, $v) => "$k=" . (strlen($v) > 60 ? substr($v, 0, 57) . '...' : $v), array_keys($harvestedEnv), $harvestedEnv))
        : '(none harvested)';
    // Truncate the log on each launch so it doesn't grow forever; VLC then
    // appends its own stdout/stderr to this fresh file via `>>`.
    @file_put_contents(
        $logFile,
        "=== " . date('c') . " phpUser=$phpUser target=$targetUser sudo=" . ($useSudo ? 'yes' : 'no')
            . " DISPLAY=$display XAUTHORITY=$xauthority XDG_RUNTIME_DIR=$xdgRuntime\n"
            . "harvested: $harvestSummary\n"
            . "cmd: $launchCmd\n"
    );
    // setsid + </dev/null so VLC's CLI doesn't see EOF on stdin and shut itself down.
    @shell_exec('setsid nohup ' . $launchCmd . ' </dev/null >> ' . escapeshellarg($logFile) . ' 2>&1 &');
    usleep(400000);
    $procName = basename(trim($playerCmd));
    $check = trim((string)@shell_exec('pgrep -af ' . escapeshellarg($procName) . ' 2>/dev/null | head -3'));
    if ($check === '') {
        $errorMsg = "Tried to launch '$procName' but no matching process is running. See log below.";
    }
}

$logTail = is_readable($logFile) ? (string)@shell_exec('tail -n 30 ' . escapeshellarg($logFile)) : '';

?><!doctype html>
<html><head><meta charset="utf-8"><title>Launching <?= h($playerName) ?>...</title>
<link rel="stylesheet" href="style.css"></head>
<body>
<div class="container">
    <h1>Launching <?= h($playerName) ?>…</h1>
    <p class="muted">
        Command: <code><?= h($playerCmd) ?></code><br>
        PHP user: <code><?= h($phpUser ?? 'unknown') ?></code>
        &nbsp;→&nbsp; target user: <code><?= h($targetUser) ?></code>
        <?= $useSudo ? '(via <code>sudo -n -u</code>)' : '(direct)' ?><br>
        DISPLAY=<code><?= h($display) ?></code> &nbsp;
        XAUTHORITY=<code><?= h($xauthority) ?></code> &nbsp;
        XDG_RUNTIME_DIR=<code><?= h($xdgRuntime) ?></code>
    </p>
    <?php if ($errorMsg): ?>
        <div class="notice err"><?= h($errorMsg) ?></div>
        <?php if ($useSudo): ?>
            <p class="muted">Most likely you need a passwordless sudoers entry. As root, run
                <code>visudo -f /etc/sudoers.d/musix</code> and add:</p>
            <pre><?= h($phpUser ?? 'daemon') ?> ALL=(<?= h($targetUser) ?>) NOPASSWD: ALL</pre>
            <p class="muted">Then click the button again — no restart needed.</p>
        <?php else: ?>
            <p class="muted">If PHP runs as <code>daemon</code>, that user can't reach your audio/X session.
                Either set <em>Run external player as user</em> to <code>Mike</code> in
                <a href="config.php">config</a>, or change Apache's <code>User</code> in
                <code>/opt/lampp/etc/httpd.conf</code>.</p>
        <?php endif; ?>
    <?php endif; ?>
    <p>Files sent (<?= count($safePaths) ?>):</p>
    <pre><?php foreach ($safePaths as $p) echo h($p) . "\n"; ?></pre>
    <?php if ($logTail !== ''): ?>
        <p class="muted">Last log lines (<code><?= h($logFile) ?></code>):</p>
        <pre><?= h($logTail) ?></pre>
    <?php endif; ?>
    <p><a class="btn ghost" href="javascript:window.close()">Close</a></p>
</div>
</body></html>
