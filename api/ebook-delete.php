<?php
declare(strict_types=1);

/**
 * Usunięcie ebooka. Powiązania tagów i półek znikają kaskadą FK,
 * papierowa książka zostaje nietknięta (powiązanie ma ON DELETE SET NULL).
 *
 * POST /api/ebook-delete.php  { id }
 */

require_once __DIR__ . '/../config.php';

auth_require();
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    json_fail('Metoda niedozwolona.', 405);
}

$wejscie = json_decode((string) file_get_contents('php://input'), true);
if (!is_array($wejscie)) {
    json_fail('Oczekiwano JSON.');
}

$id = (int) ($wejscie['id'] ?? 0);
if ($id < 1) {
    json_fail('Brak identyfikatora ebooka.');
}

if (!ebook_usun(db(), auth_user_id(), $id)) {
    json_fail('Nie znaleziono ebooka.', 404);
}

json_out(['ok' => true]);
