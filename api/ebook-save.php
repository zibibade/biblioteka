<?php
declare(strict_types=1);

/**
 * Zapis ebooka: utworzenie albo aktualizacja. Walidacja pól i relacje
 * (tagi, półki) siedzą w lib/logika.php — endpoint tylko tłumaczy wynik
 * na kody HTTP.
 *
 * POST /api/ebook-save.php  { id?, tytul, autor, isbn, format, ..., tagi?, polki? }
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

['dane' => $dane, 'bledy' => $bledy] = ebook_waliduj($wejscie);
if ($bledy !== []) {
    // 422, bo żądanie jest poprawnie zbudowane — odrzucają je reguły pól.
    json_fail(implode(' ', $bledy), 422);
}

$userId = auth_user_id();
$id = (int) ($wejscie['id'] ?? 0);

// FK pilnuje tylko istnienia wiersza, nie właściciela — cudze powiązanie
// dałoby dostęp do metadanych bez zgody user_id.
if ($dane['powiazana_ksiazka_id'] !== null) {
    $st = db()->prepare('SELECT id FROM bib_ksiazki WHERE id = ? AND user_id = ?');
    $st->execute([(int) $dane['powiazana_ksiazka_id'], $userId]);
    if ($st->fetchColumn() === false) {
        json_fail('Nie znaleziono powiązanej książki.', 422);
    }
}

try {
    $zapisanyId = ebook_zapisz(db(), $userId, $dane, $id > 0 ? $id : null);
} catch (RuntimeException $e) {
    // ebook_zapisz() rzuca tylko wtedy, gdy id nie należy do użytkownika
    json_fail($e->getMessage(), 404);
}

json_out(['id' => $zapisanyId, 'ok' => true]);
