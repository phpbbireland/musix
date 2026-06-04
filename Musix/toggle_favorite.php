<?php
declare(strict_types=1);
require_once __DIR__ . '/db.php';

header('Content-Type: application/json');

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
    // Atomic flip: 0 -> 1, 1 -> 0. Avoids a read-then-write race.
    $upd = db()->prepare(
        "UPDATE tracks
         SET is_favorite = 1 - is_favorite
         WHERE id = :id"
    );
    $upd->execute([':id' => $id]);

    if ($upd->rowCount() === 0) {
        http_response_code(404);
        echo json_encode(['error' => 'Track not found']);
        exit;
    }

    $sel = db()->prepare("SELECT is_favorite FROM tracks WHERE id = :id");
    $sel->execute([':id' => $id]);
    $state = (int)$sel->fetchColumn();

    echo json_encode([
        'ok'          => true,
        'id'          => $id,
        'is_favorite' => $state,
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
