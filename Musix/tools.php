<?php
/**
 * Tools page — utility actions for housekeeping the Musix install.
 *
 * Everything lives here behind ?action=… so there's one entry-point. The
 * destructive actions (empty_tables, reset) require a typed confirmation
 * token (CONFIRM_TOKEN) so a stray click can't blow away the library.
 *
 * Actions:
 *   index            — default listing
 *   backup_config    — download config table as INSERTs (.sql)
 *   backup_database  — download every table as INSERTs (.sql)
 *   empty_tables     — DELETE every data table, leave config intact
 *   reset            — drop every table + re-apply schema (setup.sql)
 *   optimize         — VACUUM + ANALYZE the SQLite database
 *
 * Backups are pure-PHP — we build the .sql dump ourselves rather than shelling
 * out to an external tool. This is the SQLite desktop copy of Musix.
 */
declare(strict_types=1);
require_once __DIR__ . '/db.php';

const CONFIRM_TOKEN = 'YES';

$action = (string)($_GET['action'] ?? 'index');
$db     = db();

// Tables that should survive an "empty" — the user's prefs stay put.
$KEEP_TABLES = ['config'];

/** Walks every base table in the current schema. */
function list_tables(PDO $db): array {
    $rows = $db->query(
        "SELECT name FROM sqlite_master
          WHERE type = 'table' AND name NOT LIKE 'sqlite_%'
          ORDER BY name"
    )->fetchAll(PDO::FETCH_NUM);
    return array_map(fn($r) => (string)$r[0], $rows);
}

/** Render a single CREATE TABLE + its INSERTs as a SQL string. */
function dump_table(PDO $db, string $table): string {
    $out  = "-- ----------------------------------------------------------\n";
    $out .= "-- Table: $table\n";
    $out .= "-- ----------------------------------------------------------\n";

    $stmt = $db->prepare(
        "SELECT sql FROM sqlite_master WHERE type = 'table' AND name = :n"
    );
    $stmt->execute([':n' => $table]);
    $createSql = $stmt->fetchColumn();
    if ($createSql) {
        $out .= "DROP TABLE IF EXISTS `$table`;\n";
        $out .= $createSql . ";\n\n";
    }

    // Stream rows one batch at a time so a giant tracks table doesn't OOM.
    $stmt = $db->query("SELECT * FROM `$table`");
    $cols = null;
    $batch = [];
    $BATCH_SIZE = 200;

    while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
        if ($cols === null) {
            $cols = array_keys($r);
        }
        $vals = array_map(function ($v) use ($db) {
            return $v === null ? 'NULL' : $db->quote((string)$v);
        }, array_values($r));
        $batch[] = '(' . implode(',', $vals) . ')';
        if (count($batch) >= $BATCH_SIZE) {
            $out .= 'INSERT INTO `' . $table . '` (`' . implode('`,`', $cols) . "`) VALUES\n";
            $out .= implode(",\n", $batch) . ";\n";
            $batch = [];
        }
    }
    if ($batch) {
        $out .= 'INSERT INTO `' . $table . '` (`' . implode('`,`', $cols) . "`) VALUES\n";
        $out .= implode(",\n", $batch) . ";\n";
    }
    $out .= "\n";
    return $out;
}

// --- backup_config ----------------------------------------------------------
if ($action === 'backup_config') {
    $sql = "-- Musix config backup — " . date('c') . "\n\n";
    $sql .= dump_table($db, 'config');
    header('Content-Type: application/sql');
    header('Content-Disposition: attachment; filename="musix-config-' . date('Ymd-His') . '.sql"');
    echo $sql;
    exit;
}

// --- backup_database --------------------------------------------------------
if ($action === 'backup_database') {
    header('Content-Type: application/sql');
    header('Content-Disposition: attachment; filename="musix-' . date('Ymd-His') . '.sql"');
    echo "-- Musix full backup — " . date('c') . "\n";
    echo "PRAGMA foreign_keys=OFF;\n";
    echo "BEGIN TRANSACTION;\n\n";
    foreach (list_tables($db) as $t) {
        echo dump_table($db, $t);
        @flush();
    }
    echo "COMMIT;\n";
    echo "PRAGMA foreign_keys=ON;\n";
    exit;
}

// --- empty_tables -----------------------------------------------------------
$notice = null; $err = null;
if ($action === 'empty_tables' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (($_POST['confirm'] ?? '') !== CONFIRM_TOKEN) {
        $err = 'Type ' . CONFIRM_TOKEN . ' in the confirm box first.';
    } else {
        try {
            $db->exec('PRAGMA foreign_keys=OFF');
            $emptied = [];
            foreach (list_tables($db) as $t) {
                if (in_array($t, $KEEP_TABLES, true)) continue;
                $db->exec("DELETE FROM `$t`");
                // Reset the AUTOINCREMENT-style rowid counter, the SQLite
                // equivalent of MySQL's TRUNCATE resetting AUTO_INCREMENT.
                $seq = $db->prepare("DELETE FROM sqlite_sequence WHERE name = :n");
                $seq->execute([':n' => $t]);
                $emptied[] = $t;
            }
            $db->exec('PRAGMA foreign_keys=ON');
            $notice = 'Emptied ' . count($emptied) . ' table' . (count($emptied) === 1 ? '' : 's') .
                      ': ' . implode(', ', $emptied) . '. Config preserved.';
        } catch (Throwable $e) {
            $err = $e->getMessage();
        }
    }
}

// --- reset ------------------------------------------------------------------
if ($action === 'reset' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (($_POST['confirm'] ?? '') !== CONFIRM_TOKEN) {
        $err = 'Type ' . CONFIRM_TOKEN . ' in the confirm box first.';
    } else {
        try {
            // 1) Snapshot config so it survives the schema rebuild.
            $configRows = $db->query("SELECT `key`, `value` FROM config")->fetchAll(PDO::FETCH_ASSOC);

            // 2) Drop every table.
            $db->exec('PRAGMA foreign_keys=OFF');
            foreach (list_tables($db) as $t) {
                $db->exec("DROP TABLE IF EXISTS `$t`");
            }

            // 3) Re-run setup.sql to recreate the schema. Split on ";\n" the
            // same way install.php does — PDO::exec runs one statement at a time.
            $sql = file_get_contents(__DIR__ . '/setup.sql');
            if ($sql === false) throw new RuntimeException('setup.sql not readable');

            foreach (preg_split('/;\s*[\r\n]+/', $sql) as $stmt) {
                $stmt = preg_replace('/^(\s*--[^\n]*\n)+/', '', $stmt);
                $stmt = trim($stmt);
                if ($stmt === '') continue;
                $db->exec($stmt);
            }
            $db->exec('PRAGMA foreign_keys=ON');

            // 4) Restore config rows.
            $ins = $db->prepare("INSERT INTO config (`key`, `value`) VALUES (:k, :v)
                                  ON CONFLICT(`key`) DO UPDATE SET `value` = excluded.`value`");
            foreach ($configRows as $r) {
                $ins->execute([':k' => $r['key'], ':v' => $r['value']]);
            }

            $notice = 'Reset complete. ' . count($configRows) . ' config row' .
                      (count($configRows) === 1 ? '' : 's') . ' restored. ' .
                      'Now re-scan from <a href="config.php">Config</a>.';
        } catch (Throwable $e) {
            $err = $e->getMessage();
        }
    }
}

// --- optimize ---------------------------------------------------------------
// Runs VACUUM + ANALYZE on the SQLite database. VACUUM rebuilds the file,
// reclaiming space freed by deleted rows and defragmenting it; ANALYZE refreshes
// the query planner's statistics. Both are non-destructive — no confirm token.
if ($action === 'optimize' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $sizeBefore = @filesize(DB_PATH) ?: 0;
        // VACUUM cannot run inside a transaction.
        $db->exec('VACUUM');
        $db->exec('ANALYZE');
        clearstatcache();
        $sizeAfter = @filesize(DB_PATH) ?: 0;
        $saved     = max(0, $sizeBefore - $sizeAfter);
        $notice = 'Database optimised. VACUUM rebuilt the file and ANALYZE ' .
                  'refreshed query statistics. Size: ' .
                  number_format($sizeBefore) . ' → ' . number_format($sizeAfter) .
                  ' bytes' . ($saved > 0 ? ' (' . number_format($saved) . ' bytes reclaimed).' : '.');
    } catch (Throwable $e) {
        $err = $e->getMessage();
    }
}

// --- render -----------------------------------------------------------------
$page  = 'tools';
$title = 'Musix — Tools';
require __DIR__ . '/header.php';
?>

<h1>Tools</h1>

<?php if ($notice): ?><div class="notice ok"><?= $notice ?></div><?php endif; ?>
<?php if ($err):    ?><div class="notice err"><?= h($err) ?></div><?php endif; ?>

<p class="muted">
    Housekeeping utilities. Destructive actions require typing
    <code><?= CONFIRM_TOKEN ?></code> as a confirm step.
</p>

<div class="card" style="max-width: 720px;">
    <h2 style="margin-top:0;">Cover art</h2>
    <p>
        Walks every album with an empty <code>cover_art</code>, tries up to
        three of its tracks, and writes the first usable embedded picture
        (MP3 APIC / FLAC PICTURE / OGG METADATA_BLOCK_PICTURE) into
        <code>cache/covers/&lt;album_id&gt;.&lt;ext&gt;</code>. Idempotent.
    </p>
    <div class="toolbar">
        <a class="btn" href="extract_covers.php">Extract embedded covers</a>
    </div>
</div>

<div class="card" style="max-width: 720px; margin-top:16px;">
    <h2 style="margin-top:0;">FLAC diagnostic</h2>
    <p>
        Some FLAC tracks won't play in the browser because their files have
        a non-spec <code>ID3v2</code> tag prepended — browsers (and our
        parser) expect <code>fLaC</code> magic at byte&nbsp;0. The
        diagnostic walks every FLAC with an empty <code>duration</code>
        and reports per file whether an ID3v2 prefix is the culprit.
        <code>stream.php</code> now strips that prefix on the wire, and
        re-running the library scan will repopulate <code>duration</code>
        once the parser sees clean FLAC headers.
    </p>
    <div class="toolbar">
        <a class="btn" href="diagnose_flac.php">Run FLAC diagnostic</a>
        <a class="btn ghost" href="scan.php?run=1">Re-scan library</a>
    </div>
</div>

<div class="card" style="max-width: 720px; margin-top:16px;">
    <h2 style="margin-top:0;">Optimise database</h2>
    <p>
        Runs <code>VACUUM</code> and <code>ANALYZE</code> on the SQLite
        database. <code>VACUUM</code> rebuilds the file, reclaiming space
        freed by deleted rows and defragmenting it; <code>ANALYZE</code>
        refreshes the query planner's statistics so lookups stay fast.
        Both are non-destructive — no rows are changed — so there's no
        confirm step.
    </p>
    <form method="post" action="tools.php?action=optimize">
        <div class="toolbar">
            <button type="submit" class="btn">Optimise database</button>
        </div>
    </form>
</div>

<div class="card" style="max-width: 720px; margin-top:16px;">
    <h2 style="margin-top:0;">Backups</h2>
    <p>
        SQL dumps you can re-import via
        <code>sqlite3 musix.sqlite &lt; file.sql</code>. Both backups include
        <code>DROP TABLE IF EXISTS</code> + <code>CREATE TABLE</code> +
        <code>INSERT</code> for a clean restore.
    </p>
    <div class="toolbar">
        <a class="btn ghost" href="tools.php?action=backup_config">Backup config table</a>
        <a class="btn ghost" href="tools.php?action=backup_database">Backup entire database</a>
    </div>
</div>

<div class="card" style="max-width: 720px; margin-top:16px;">
    <h2 style="margin-top:0;">Empty tables</h2>
    <p>
        Empties every data table — artists, albums, tracks,
        genres, playlists, playlist_tracks — but leaves <code>config</code>
        alone. Lets you rebuild the library from scratch via
        <a href="config.php">Scan library now</a> without losing your
        library path / external player settings.
    </p>
    <form method="post" action="tools.php?action=empty_tables">
        <div class="form-row">
            <label for="empty-confirm">Confirm</label>
            <input type="text" id="empty-confirm" name="confirm"
                   placeholder="Type <?= CONFIRM_TOKEN ?> to enable Empty"
                   autocomplete="off" style="max-width:280px;">
        </div>
        <div class="toolbar">
            <button type="submit" class="btn">Empty tables</button>
        </div>
    </form>
</div>

<div class="card" style="max-width: 720px; margin-top:16px;">
    <h2 style="margin-top:0;">Reset to default</h2>
    <p>
        Snapshots the <code>config</code> table, drops every table, re-runs
        <code>setup.sql</code> to rebuild the schema, then restores the
        config rows. Use this when the schema has drifted or you want a
        clean slate without losing your settings.
    </p>
    <form method="post" action="tools.php?action=reset">
        <div class="form-row">
            <label for="reset-confirm">Confirm</label>
            <input type="text" id="reset-confirm" name="confirm"
                   placeholder="Type <?= CONFIRM_TOKEN ?> to enable Reset"
                   autocomplete="off" style="max-width:280px;">
        </div>
        <div class="toolbar">
            <button type="submit" class="btn">Reset to default</button>
        </div>
    </form>
</div>

<?php require __DIR__ . '/footer.php';
