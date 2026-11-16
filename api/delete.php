<?php
declare(strict_types=1);

/**
 * Trwałe usunięcie książki (bez kosza — z potwierdzeniem po stronie UI,
 * zapasem jest eksport danych).
 *
 * POST /api/delete.php  { "id": 123 }
 */

require_once __DIR__ . '/../config.php';

auth_require();
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    json_fail('Metoda niedozwolona.', 405);
}

$wejscie = json_decode((string) file_get_contents('php://input'), true);
$id = is_array($wejscie) ? (int) ($wejscie['id'] ?? 0) : 0;
if ($id < 1) {
    json_fail('Brak identyfikatora książki.');
}

$stmt = db()->prepare('DELETE FROM bib_ksiazki WHERE id = ? AND user_id = ?');
$stmt->execute([$id, auth_user_id()]);
if ($stmt->rowCount() === 0) {
    json_fail('Nie znaleziono książki.', 404);
}

json_out(['ok' => true]);
