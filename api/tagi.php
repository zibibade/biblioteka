<?php
declare(strict_types=1);

/**
 * Tagi — słownik etykiet. Tagi zakładamy razem z zapisem książki, więc tu
 * tylko odczyt listy i usuwanie (z odjęciem powiązań).
 *
 * GET  /api/tagi.php                 → lista tagów z liczbą książek
 * POST /api/tagi.php { akcja: 'usun', nazwa }
 */

require_once __DIR__ . '/../config.php';

auth_require();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
    json_out(['tagi' => tagi_slownik(auth_user_id())]);
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    json_fail('Metoda niedozwolona.', 405);
}

$wejscie = json_decode((string) file_get_contents('php://input'), true);
if (!is_array($wejscie)) {
    json_fail('Oczekiwano JSON.');
}

if (($wejscie['akcja'] ?? '') !== 'usun') {
    json_fail('Nieznana akcja.');
}

$nazwa = mb_strtolower(trim((string) ($wejscie['nazwa'] ?? '')));
if ($nazwa === '') {
    json_fail('Brak nazwy tagu.');
}

$userId = auth_user_id();
$st = db()->prepare('SELECT id FROM bib_tagi WHERE user_id = ? AND nazwa = ?');
$st->execute([$userId, $nazwa]);
$id = $st->fetchColumn();
if ($id === false) {
    json_fail('Nie znaleziono tagu.', 404);
}

// Powiązania znikają razem z tagiem (FK ON DELETE CASCADE)
db()->prepare('DELETE FROM bib_tagi WHERE id = ? AND user_id = ?')->execute([(int) $id, $userId]);

json_out(['ok' => true]);
