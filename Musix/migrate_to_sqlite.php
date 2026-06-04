<?php
/**
 * migrate_to_sqlite.php — one-time data migration for the Musix desktop copy.
 *
 * Reads the existing MariaDB database used by the XAMPP web copy and copies
 * every row into a fresh local SQLite file (musix.sqlite) next to this script.
 *
 * The MariaDB database is only READ — nothing is changed there. After this
 * runs, the desktop copy talks only to SQLite and the two installs are
 * fully independent.
 *
 * Run it once, either from a terminal:
 *     php migrate_to_sqlite.php
 * or by opening it in your browser:
 *     http://127.0.0.1:8675/migrate_to_sqlite.php
 *
 * Safe to re-run: any existing musix.sqlite is backed up first, then rebuilt.
 */
declare(strict_types=1);

// --- MariaDB connection (the XAMPP web copy's database — read only) ---------
$MYSQL_HOST = '127.0.0.1';
$MYSQL_PORT = 3306;
$MYSQL_NAME = 'musix';
$MYSQL_USER = 'root';
$MYSQL_PASS = '';

$cli = (PHP_SAPI === 'cli');
$nl  = $cli ? "\n" : "<br>\n";
if (!$cli) { header('Content-Type: text/html; charset=utf-8'); echo "<pre>"; }

function out(string $msg): void { global $nl; echo $msg . $nl; flush(); }
function fail(string $msg): never { out('ERROR: ' . $msg); exit(1); }

$sqlitePath = __DIR__ . '/musix.sqlite';
$schemaPath = __DIR__ . '/setup.sql';

out('Musix — MariaDB to SQLite migration');
out('-----------------------------------');

// --- connect to MariaDB ------------------------------------------------------
try {
    $myDsn = sprintf(
        'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
        $MYSQL_HOST, $MYSQL_PORT, $MYSQL_NAME
    );
    $my = new PDO($myDsn, $MYSQL_USER, $MYSQL_PASS, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);
    out('Connected to MariaDB (' . $MYSQL_NAME . ').');
} catch (Throwable $e) {
    fail('could not connect to MariaDB: ' . $e->getMessage());
}

// --- back up any existing SQLite file ---------------------------------------
if (is_file($sqlitePath)) {
    $backup = $sqlitePath . '.bak-' . date('Ymd-His');
    if (!rename($sqlitePath, $backup)) {
        fail('could not back up existing ' . basename($sqlitePath));
    }
    out('Existing database backed up to ' . basename($backup));
}
// Also clear stale WAL/SHM side-files so the new DB starts clean.
foreach (['-wal', '-shm'] as $suffix) {
    if (is_file($sqlitePath . $suffix)) { @unlink($sqlitePath . $suffix); }
}

// --- create the SQLite database and load the schema --------------------------
if (!is_file($schemaPath)) { fail('schema file not found: ' . $schemaPath); }

try {
    $lite = new PDO('sqlite:' . $sqlitePath, null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);
    $lite->exec('PRAGMA foreign_keys = OFF');
} catch (Throwable $e) {
    fail('could not create SQLite file: ' . $e->getMessage());
}

$schema = (string)file_get_contents($schemaPath);
foreach (preg_split('/;\s*[\r\n]+/', $schema) as $stmt) {
    // Strip leading -- comment lines so a statement preceded by a comment
    // block (like the header at the top of setup.sql) still runs. Skipping
    // any chunk that merely *starts* with "--" would drop the first table.
    $stmt = preg_replace('/^(\s*--[^\n]*\n)+/', '', (string)$stmt);
    $stmt = trim($stmt);
    if ($stmt === '') { continue; }
    try {
        $lite->exec($stmt);
    } catch (Throwable $e) {
        fail('schema statement failed: ' . $e->getMessage() . $nl . $stmt);
    }
}
out('SQLite schema created.');

// --- copy each table, in foreign-key dependency order ------------------------
$tables = ['artists', 'genres', 'albums', 'tracks', 'playlists', 'playlist_tracks', 'config'];
$total  = 0;

$lite->beginTransaction();
foreach ($tables as $table) {
    $rows = $my->query("SELECT * FROM `$table`")->fetchAll();
    if (!$rows) { out(sprintf('  %-16s 0 rows', $table)); continue; }

    $cols      = array_keys($rows[0]);
    $colList   = '`' . implode('`, `', $cols) . '`';
    $placHold  = implode(', ', array_fill(0, count($cols), '?'));
    // INSERT OR REPLACE: the schema load seeds `config` with 4 default rows,
    // so a plain INSERT would collide on the primary key. The MariaDB copy is
    // the source of truth, so its values overwrite the seeds. All other tables
    // start empty after the schema load, making this equivalent to a plain
    // INSERT for them.
    $insert    = $lite->prepare("INSERT OR REPLACE INTO `$table` ($colList) VALUES ($placHold)");

    foreach ($rows as $row) {
        $insert->execute(array_values($row));
    }
    $count  = count($rows);
    $total += $count;
    out(sprintf('  %-16s %d rows', $table, $count));
}
$lite->commit();

// --- verify foreign keys hold ------------------------------------------------
$lite->exec('PRAGMA foreign_keys = ON');
$violations = $lite->query('PRAGMA foreign_key_check')->fetchAll();
if ($violations) {
    out($nl . 'WARNING: ' . count($violations) . ' foreign-key violation(s) found.');
} else {
    out($nl . 'Foreign-key check passed.');
}

out('Migrated ' . $total . ' rows in total.');
out('Done. The desktop copy now uses ' . basename($sqlitePath) . '.');
if (!$cli) { echo "</pre>"; }
