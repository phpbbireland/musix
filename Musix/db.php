<?php
declare(strict_types=1);

require_once __DIR__ . '/db_config.php';

function db(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $pdo = new PDO('sqlite:' . DB_PATH, null, null, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
        // Foreign keys are OFF by default in SQLite — turn them on per-connection
        // so the schema's ON DELETE CASCADE / SET NULL rules apply.
        $pdo->exec('PRAGMA foreign_keys = ON');
        // WAL gives better concurrency; busy_timeout avoids "database is locked".
        $pdo->exec('PRAGMA journal_mode = WAL');
        $pdo->exec('PRAGMA busy_timeout = 5000');
    }
    return $pdo;
}

function cfg(string $key, ?string $default = null): ?string {
    static $cache = null;
    if ($cache === null) {
        $cache = [];
        foreach (db()->query("SELECT `key`, `value` FROM config") as $r) {
            $cache[$r['key']] = $r['value'];
        }
    }
    return $cache[$key] ?? $default;
}

function cfg_set(string $key, string $value): void {
    $stmt = db()->prepare(
        "INSERT INTO config (`key`, `value`) VALUES (:k, :v)
         ON CONFLICT(`key`) DO UPDATE SET `value` = excluded.`value`"
    );
    $stmt->execute([':k' => $key, ':v' => $value]);
}

function h(?string $s): string {
    return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function format_duration(?int $sec): string {
    if ($sec === null || $sec < 0) return '';
    $m = intdiv($sec, 60);
    $s = $sec % 60;
    return sprintf('%d:%02d', $m, $s);
}
