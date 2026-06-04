<?php
// install.php — one-click DB setup. Open this once in your browser, then delete (or leave it).

declare(strict_types=1);
require __DIR__ . '/db_config.php';

$messages = [];
$ok = true;

try {
    $pdo = new PDO(
        'sqlite:' . DB_PATH,
        null,
        null,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
    $pdo->exec('PRAGMA foreign_keys = ON');

    $sqlPath = __DIR__ . '/setup.sql';
    if (!is_readable($sqlPath)) {
        throw new RuntimeException('setup.sql not found next to install.php');
    }
    $sql = file_get_contents($sqlPath);

    // Split on semicolons at line ends; ignores semicolons inside strings (which we don't use here).
    $statements = array_filter(array_map('trim', preg_split('/;\s*[\r\n]+/', $sql)));
    foreach ($statements as $stmt) {
        // Strip leading -- comment lines so a statement that starts with a
        // comment block still runs (otherwise we'd skip it as comment-only).
        $stmt = preg_replace('/^(\s*--[^\n]*\n)+/', '', $stmt);
        $stmt = trim($stmt);
        if ($stmt === '') continue;
        $pdo->exec($stmt);
        $messages[] = 'OK: ' . substr(preg_replace('/\s+/', ' ', $stmt), 0, 80) . '...';
    }
    $messages[] = 'SQLite database is ready (' . basename(DB_PATH) . ').';
} catch (Throwable $e) {
    $ok = false;
    $messages[] = 'ERROR: ' . $e->getMessage();
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Musix — Install</title>
<link rel="stylesheet" href="style.css">
</head>
<body>
<div class="container">
    <h1>Musix Install</h1>
    <p>Status: <strong class="<?= $ok ? 'ok' : 'err' ?>"><?= $ok ? 'Success' : 'Failed' ?></strong></p>
    <pre><?php foreach ($messages as $m) echo htmlspecialchars($m) . "\n"; ?></pre>
    <?php if ($ok): ?>
        <p>Next: <a href="config.php">open the config page</a> and set your library path.</p>
        <p class="muted">Once everything works you can delete <code>install.php</code>.</p>
    <?php else: ?>
        <p class="muted">Check that the folder is writable (the SQLite file is created here), then reload.</p>
    <?php endif; ?>
</div>
</body>
</html>
