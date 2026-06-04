<?php
/**
 * One-shot cleanup for genres that landed in the table as bare numeric
 * strings ("13", "10", ...) before _decode_genre learned to map the
 * unparenthesised ID3v1 form. Idempotent — re-running after the rows
 * are gone is a no-op.
 *
 * For each numeric-named row:
 *   - resolve to the ID3v1 name
 *   - if that name already exists in genres, repoint tracks.genre_id
 *     to the existing row and DELETE the numeric row
 *   - otherwise just rename the numeric row in place
 *
 * Open http://localhost/musix/fix_numeric_genres.php once. Safe to delete
 * after the report shows zero remaining numeric rows.
 */
declare(strict_types=1);
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/id3.php';

$page  = 'config';
$title = 'Musix — Fix numeric genres';
require __DIR__ . '/header.php';

echo "<h1>Fix numeric-named genres</h1><pre>";

$genres = \Musix\Id3\ID3V1_GENRES;

$rows = db()->query("SELECT id, name FROM genres WHERE name <> '' AND name NOT GLOB '*[^0-9]*' ORDER BY CAST(name AS INTEGER)")->fetchAll(PDO::FETCH_ASSOC);

if (!$rows) {
    echo "Nothing to do — no numeric-named genres found.\n";
} else {
    db()->beginTransaction();
    try {
        $stats = ['merged' => 0, 'renamed' => 0, 'unmapped' => 0, 'tracks' => 0];

        $findByName = db()->prepare("SELECT id FROM genres WHERE name = :n LIMIT 1");
        $repoint    = db()->prepare("UPDATE tracks SET genre_id = :new WHERE genre_id = :old");
        $deleteRow  = db()->prepare("DELETE FROM genres WHERE id = :id");
        $rename     = db()->prepare("UPDATE genres SET name = :n WHERE id = :id");

        foreach ($rows as $r) {
            $idx = (int)$r['name'];
            $resolved = $genres[$idx] ?? null;
            if ($resolved === null) {
                echo "skip [{$r['id']}] '{$r['name']}' — not a known ID3v1 index\n";
                $stats['unmapped']++;
                continue;
            }

            $findByName->execute([':n' => $resolved]);
            $existingId = $findByName->fetchColumn();

            if ($existingId !== false && (int)$existingId !== (int)$r['id']) {
                $repoint->execute([':new' => (int)$existingId, ':old' => (int)$r['id']]);
                $moved = $repoint->rowCount();
                $deleteRow->execute([':id' => (int)$r['id']]);
                echo "merge [{$r['id']}] '{$r['name']}' → [{$existingId}] '{$resolved}' (moved {$moved} tracks)\n";
                $stats['merged']++;
                $stats['tracks'] += $moved;
            } else {
                $rename->execute([':n' => $resolved, ':id' => (int)$r['id']]);
                echo "rename [{$r['id']}] '{$r['name']}' → '{$resolved}'\n";
                $stats['renamed']++;
            }
        }

        db()->commit();
        echo "\nDone. merged={$stats['merged']} renamed={$stats['renamed']} "
           . "unmapped={$stats['unmapped']} tracks_moved={$stats['tracks']}\n";
    } catch (Throwable $e) {
        db()->rollBack();
        echo "\nROLLBACK: " . h($e->getMessage()) . "\n";
    }
}

echo "</pre>";
echo '<p><a class="btn" href="config.php">Back to config</a></p>';

require __DIR__ . '/footer.php';
