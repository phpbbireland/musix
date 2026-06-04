<?php
declare(strict_types=1);
require_once __DIR__ . '/db.php';

header('Content-Type: application/json');

// Only POST — don't let bots warm play counts via prefetch / image loads.
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'POST only']);
    exit;
}

$id = (int)($_POST['id'] ?? 0);
if ($id <= 0) {
    http_response_code(400);
    echo json_encode(['error' => 'Bad id']);
    exit;
}

try {
    $stmt = db()->prepare(
        "UPDATE tracks
         SET play_count = play_count + 1,
             last_played = CURRENT_TIMESTAMP
         WHERE id = :id"
    );
    $stmt->execute([':id' => $id]);

    if ($stmt->rowCount() === 0) {
        http_response_code(404);
        echo json_encode(['error' => 'Track not found']);
        exit;
    }

    $row = db()->prepare(
        "SELECT play_count, last_played FROM tracks WHERE id = :id"
    );
    $row->execute([':id' => $id]);
    $r = $row->fetch();

    echo json_encode([
        'ok'          => true,
        'id'          => $id,
        'play_count'  => (int)$r['play_count'],
        'last_played' => $r['last_played'],
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
