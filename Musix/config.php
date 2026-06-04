<?php
declare(strict_types=1);
require_once __DIR__ . '/db.php';

$notice = null;
$err = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $library_path          = trim((string)($_POST['library_path']          ?? ''));
        $external_player_name  = trim((string)($_POST['external_player_name']  ?? ''));
        $external_player_path  = trim((string)($_POST['external_player_path']  ?? ''));
        $external_player_user  = trim((string)($_POST['external_player_user']  ?? ''));
        $display_env           = trim((string)($_POST['display_env']           ?? ''));
        $xauthority_path       = trim((string)($_POST['xauthority_path']       ?? ''));
        $allow_external_launch = isset($_POST['allow_external_launch']) ? '1' : '0';

        // About-page fields. Empty strings are allowed (about.php falls back
        // to defaults when a value is missing or empty).
        $program_name = trim((string)($_POST['program_name'] ?? ''));
        $version      = trim((string)($_POST['version']      ?? ''));
        $github_url   = trim((string)($_POST['github_url']   ?? ''));
        $author_name  = trim((string)($_POST['author_name']  ?? ''));
        $author_email = trim((string)($_POST['author_email'] ?? ''));

        if ($library_path !== '') {
            if (!file_exists($library_path)) {
                throw new RuntimeException(
                    "Path not visible to PHP: $library_path. "
                  . "Either it doesn't exist, or the user PHP runs as ("
                  . (function_exists('posix_geteuid') ? posix_getpwuid(posix_geteuid())['name'] : 'unknown')
                  . ") can't traverse the parent folders. See the hint below the field."
                );
            }
            if (!is_dir($library_path)) {
                throw new RuntimeException("Path exists but is not a directory: $library_path");
            }
            if (!is_readable($library_path)) {
                throw new RuntimeException(
                    "Path exists but PHP can't read it. PHP is running as user '"
                  . (function_exists('posix_geteuid') ? posix_getpwuid(posix_geteuid())['name'] : 'unknown')
                  . "'. Make sure that user has +rx on $library_path and every folder above it."
                );
            }
        }

        cfg_set('library_path',          $library_path);
        cfg_set('external_player_name',  $external_player_name);
        cfg_set('external_player_path',  $external_player_path);
        cfg_set('external_player_user',  $external_player_user);
        cfg_set('display_env',           $display_env);
        cfg_set('xauthority_path',       $xauthority_path);
        cfg_set('allow_external_launch', $allow_external_launch);

        cfg_set('program_name', $program_name);
        cfg_set('version',      $version);
        cfg_set('github_url',   $github_url);
        cfg_set('author_name',  $author_name);
        cfg_set('author_email', $author_email);

        $notice = 'Configuration saved.';
        // Reload cache
        header('Location: config.php?saved=1');
        exit;
    } catch (Throwable $e) {
        $err = $e->getMessage();
    }
}

if (isset($_GET['saved'])) $notice = 'Configuration saved.';

$page = 'config';
$title = 'Musix — Config';
require __DIR__ . '/header.php';
?>

<h1>Configuration</h1>

<?php if ($notice): ?><div class="notice ok"><?= h($notice) ?></div><?php endif; ?>
<?php if ($err):    ?><div class="notice err"><?= h($err) ?></div><?php endif; ?>

<form method="post" class="card">
    <div class="form-row">
        <label for="library_path">Music library path</label>
        <input type="text" id="library_path" name="library_path"
               value="<?= h(cfg('library_path')) ?>"
               placeholder="/home/Mike/Music">
        <div class="hint">
            Absolute path to the folder that contains your music. Required before scanning.<br>
            PHP currently runs as <code><?= h(function_exists('posix_geteuid') ? posix_getpwuid(posix_geteuid())['name'] : 'unknown') ?></code>.
            That user needs <code>+rx</code> on the library folder and every parent.
        </div>
    </div>

    <div class="form-row">
        <label for="external_player_name">External player name</label>
        <input type="text" id="external_player_name" name="external_player_name"
               value="<?= h(cfg('external_player_name')) ?>" placeholder="VLC">
    </div>

    <div class="form-row">
        <label for="external_player_path">External player executable</label>
        <input type="text" id="external_player_path" name="external_player_path"
               value="<?= h(cfg('external_player_path')) ?>" placeholder="vlc">
        <div class="hint">Command name (e.g. <code>vlc</code>, <code>mpv</code>) or absolute path to the binary.</div>
    </div>

    <div class="form-row">
        <label for="external_player_user">Run external player as user</label>
        <input type="text" id="external_player_user" name="external_player_user"
               value="<?= h(cfg('external_player_user')) ?>" placeholder="Mike">
        <div class="hint">
            Leave blank to launch as the PHP user
            (<code><?= h(function_exists('posix_geteuid') ? posix_getpwuid(posix_geteuid())['name'] : 'unknown') ?></code>).
            If you set a different user (e.g. <code>Mike</code>), the launch is wrapped in
            <code>sudo&nbsp;-n&nbsp;-u&nbsp;&lt;user&gt;</code> with their <code>DISPLAY</code> /
            <code>XAUTHORITY</code> / <code>XDG_RUNTIME_DIR</code> set so audio &amp; video work.
            You'll need a passwordless sudoers entry — see <code>play_external.php</code> output for the exact line.
        </div>
    </div>

    <div class="form-row">
        <label for="display_env">DISPLAY</label>
        <input type="text" id="display_env" name="display_env"
               value="<?= h(cfg('display_env')) ?>" placeholder=":0.0">
        <div class="hint">
            Match the value of <code>echo $DISPLAY</code> from your normal terminal session.
            Defaults to <code>:0.0</code> if blank.
        </div>
    </div>

    <div class="form-row">
        <label for="xauthority_path">XAUTHORITY</label>
        <input type="text" id="xauthority_path" name="xauthority_path"
               value="<?= h(cfg('xauthority_path')) ?>" placeholder="/home/Mike/.Xauthority">
        <div class="hint">
            Match <code>echo $XAUTHORITY</code> from your terminal. Defaults to
            <code>~&lt;target user&gt;/.Xauthority</code> if blank.
        </div>
    </div>

    <div class="form-row">
        <label>
            <input type="checkbox" name="allow_external_launch" value="1"
                   <?= cfg('allow_external_launch') === '1' ? 'checked' : '' ?>>
            Allow launching the external player from the web UI
        </label>
        <div class="hint">
            When enabled, the "Open in <?= h(cfg('external_player_name', 'external player')) ?>" buttons will
            spawn the player on the server (i.e. this machine). Off by default for safety.
        </div>
    </div>

    <h2 style="margin-top:24px;">About page</h2>
    <p class="muted" style="margin-top:0;">
        These values render on <a href="about.php">about.php</a>. Blank
        fields fall back to sensible defaults so a fresh install still has
        something to show.
    </p>

    <div class="form-row">
        <label for="program_name">Program name</label>
        <input type="text" id="program_name" name="program_name"
               value="<?= h(cfg('program_name')) ?>" placeholder="Musix">
    </div>

    <div class="form-row">
        <label for="version">Version</label>
        <input type="text" id="version" name="version"
               value="<?= h(cfg('version')) ?>" placeholder="0.1.0">
    </div>

    <div class="form-row">
        <label for="github_url">GitHub / support URL</label>
        <input type="text" id="github_url" name="github_url"
               value="<?= h(cfg('github_url')) ?>"
               placeholder="https://github.com/your/repo">
    </div>

    <div class="form-row">
        <label for="author_name">Author name</label>
        <input type="text" id="author_name" name="author_name"
               value="<?= h(cfg('author_name')) ?>" placeholder="Mike">
    </div>

    <div class="form-row">
        <label for="author_email">Author email</label>
        <input type="email" id="author_email" name="author_email"
               value="<?= h(cfg('author_email')) ?>"
               placeholder="you@example.com">
    </div>

    <div class="toolbar">
        <button type="submit">Save</button>
        <a class="btn ghost" href="artists.php">Cancel</a>
        <span class="spacer"></span>
        <a class="btn ghost" href="scan.php">Scan library now</a>
        <a class="btn ghost" href="extract_covers.php">Extract embedded covers</a>
    </div>
</form>

<?php require __DIR__ . '/footer.php';
