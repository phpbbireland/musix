<?php
/**
 * FLAC diagnostic — walks every track with extension .flac that has a NULL
 * duration in the DB and reports, per file, why the parser bailed. The
 * common cause is a non-spec ID3v2 tag prepended to the FLAC stream.
 *
 * No DB writes; this is read-only inspection. To actually backfill the
 * missing durations, fix the parser (lib/id3.php, done) and re-run
 * scan.php — the existing-row backfill now includes `duration`.
 */
declare(strict_types=1);
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/id3.php';

$page  = 'tools';
$title = 'Musix — FLAC Diagnostic';

$limit = (int)($_GET['limit'] ?? 50);
if ($limit < 1)   $limit = 1;
if ($limit > 500) $limit = 500;

// Pull every visible FLAC row with NULL duration (the symptom).
$stmt = db()->prepare(
    "SELECT id, file_path
     FROM tracks
     WHERE LOWER(file_path) LIKE '%.flac'
       AND duration IS NULL
       AND excluded = 0
     ORDER BY id
     LIMIT $limit"
);
$stmt->execute();
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

$totalFlac = (int)db()->query(
    "SELECT COUNT(*) FROM tracks
     WHERE LOWER(file_path) LIKE '%.flac'"
)->fetchColumn();
$noDur = (int)db()->query(
    "SELECT COUNT(*) FROM tracks
     WHERE LOWER(file_path) LIKE '%.flac'
       AND duration IS NULL AND excluded = 0"
)->fetchColumn();

/**
 * Probe one file: open, read first 64 bytes, classify, try to find fLaC magic.
 * Returns an array with verdict + supporting facts.
 */
function probe_flac(string $path): array {
    $r = [
        'exists'      => false,
        'readable'    => false,
        'size'        => null,
        'head_hex'    => null,
        'head_ascii'  => null,
        'verdict'     => 'unknown',
        'detail'      => '',
        'prefix'      => 0,
        'magic_at'    => null,
    ];
    if (!file_exists($path)) { $r['verdict'] = 'missing'; return $r; }
    $r['exists'] = true;
    if (!is_readable($path)) { $r['verdict'] = 'unreadable'; return $r; }
    $r['readable'] = true;
    $r['size'] = @filesize($path);

    $fh = @fopen($path, 'rb');
    if (!$fh) { $r['verdict'] = 'fopen_fail'; return $r; }
    $head = fread($fh, 64);
    fclose($fh);
    if ($head === false || $head === '') { $r['verdict'] = 'empty_read'; return $r; }

    // Show first 16 bytes both hex and printable for at-a-glance reading.
    $first16 = substr($head, 0, 16);
    $r['head_hex'] = strtoupper(bin2hex($first16));
    $r['head_ascii'] = preg_replace('/[^\x20-\x7E]/', '.', $first16);

    if (substr($head, 0, 4) === 'fLaC') {
        $r['verdict'] = 'fLaC_at_0';
        $r['detail']  = 'Starts with fLaC magic — STREAMINFO likely missing or corrupt.';
        return $r;
    }

    if (substr($head, 0, 3) === 'ID3') {
        $prefix = \Musix\Id3\flac_id3v2_prefix_size($path);
        $r['prefix']  = $prefix;
        // Confirm fLaC sits right after the prefix.
        $fh2 = @fopen($path, 'rb');
        if ($fh2) {
            fseek($fh2, $prefix);
            $magic = fread($fh2, 4);
            fclose($fh2);
            if ($magic === 'fLaC') {
                $r['verdict']  = 'id3v2_prefix';
                $r['magic_at'] = $prefix;
                $r['detail']   = "ID3v2 prefix of {$prefix} bytes, fLaC follows.";
            } else {
                $r['verdict'] = 'id3v2_prefix_mismatch';
                $r['detail']  = "ID3v2 prefix of {$prefix} bytes claimed, but no fLaC at that offset (got: " . bin2hex((string)$magic) . ").";
            }
        }
        return $r;
    }

    $r['verdict'] = 'other_magic';
    $r['detail']  = 'First 4 bytes are not fLaC and not ID3 — file may be corrupt or misnamed.';
    return $r;
}

// Run all probes up front so we can summarise.
$results = [];
$counts  = ['id3v2_prefix' => 0, 'fLaC_at_0' => 0, 'other_magic' => 0, 'missing' => 0, 'unreadable' => 0, 'other' => 0];
foreach ($rows as $row) {
    $r = probe_flac((string)$row['file_path']);
    $r['id']        = (int)$row['id'];
    $r['file_path'] = (string)$row['file_path'];
    if (isset($counts[$r['verdict']])) $counts[$r['verdict']]++;
    else $counts['other']++;
    $results[] = $r;
}

require __DIR__ . '/header.php';
?>

<h1>FLAC diagnostic</h1>

<p class="muted">
    Of <?= number_format($totalFlac) ?> FLAC tracks in the library,
    <strong><?= number_format($noDur) ?></strong> have an empty
    <code>duration</code>. The most common cause is a non-spec
    <code>ID3v2</code> tag prepended to the FLAC stream — browsers refuse
    these because the file doesn't start with the <code>fLaC</code> magic
    bytes, which is the same reason our parser couldn't read them.
</p>

<div class="card" style="max-width:720px;">
    <h2 style="margin-top:0;">Summary of the first <?= count($results) ?> file<?= count($results) === 1 ? '' : 's' ?></h2>
    <table class="kv">
        <tr><th>ID3v2 prefix detected</th> <td><?= $counts['id3v2_prefix'] ?> — fixable: re-scan will populate duration, stream.php now strips the prefix.</td></tr>
        <tr><th>Starts with fLaC magic</th> <td><?= $counts['fLaC_at_0'] ?> — parser fault or unusual STREAMINFO (rare).</td></tr>
        <tr><th>Other / unknown magic</th>  <td><?= $counts['other_magic'] ?> — file may be corrupt or misnamed.</td></tr>
        <tr><th>Missing on disk</th>        <td><?= $counts['missing'] ?></td></tr>
        <tr><th>Unreadable</th>             <td><?= $counts['unreadable'] ?> — check PHP user has +r on the file.</td></tr>
        <?php if ($counts['other'] > 0): ?>
            <tr><th>Other</th><td><?= $counts['other'] ?></td></tr>
        <?php endif; ?>
    </table>
    <p class="muted" style="font-size:13px; margin-bottom:0;">
        Next step: visit <a href="scan.php?run=1">scan.php?run=1</a> — the
        existing-row backfill now refreshes <code>duration</code> when it's
        NULL, so the parser fix will populate the missing values without a
        full reset.
    </p>
</div>

<h2 style="margin-top:24px;">Per-file findings</h2>

<table>
    <thead>
        <tr>
            <th>ID</th>
            <th>File</th>
            <th>Size</th>
            <th>First 16 bytes (hex / ascii)</th>
            <th>Verdict</th>
        </tr>
    </thead>
    <tbody>
        <?php foreach ($results as $r): ?>
            <tr>
                <td class="num"><?= (int)$r['id'] ?></td>
                <td><code style="font-size:11px;"><?= h($r['file_path']) ?></code></td>
                <td class="num"><?= $r['size'] !== null ? number_format($r['size']) : '—' ?></td>
                <td>
                    <?php if ($r['head_hex']): ?>
                        <code style="font-size:11px;"><?= h($r['head_hex']) ?></code><br>
                        <code style="font-size:11px;"><?= h($r['head_ascii']) ?></code>
                    <?php else: ?>
                        <span class="muted">—</span>
                    <?php endif; ?>
                </td>
                <td>
                    <strong><?= h($r['verdict']) ?></strong>
                    <?php if ($r['detail']): ?>
                        <div class="muted" style="font-size:12px;"><?= h($r['detail']) ?></div>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
    </tbody>
</table>

<style>
    table.kv { width: 100%; border-collapse: collapse; }
    table.kv th, table.kv td { padding: 6px 10px; text-align: left; vertical-align: top; }
    table.kv th { width: 220px; color: var(--muted); font-weight: 500; }
    table.kv tr + tr th, table.kv tr + tr td { border-top: 1px solid var(--border); }
</style>

<?php require __DIR__ . '/footer.php';
