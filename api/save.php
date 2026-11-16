<?php
declare(strict_types=1);

/**
 * Zapis książki: utworzenie albo aktualizacja (podgląd metryczki z edycją
 * przed zapisem — dane przychodzą już przefiltrowane przez użytkownika).
 * Tagi i półki to osobne relacje (wiele-do-wielu) zapisywane po wierszu.
 *
 * POST /api/save.php  { id?, tytul, autor, isbn, ..., tagi?: [...], polki?: [id] }
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

['dane' => $dane, 'bledy' => $bledy] = ksiazka_waliduj($wejscie);
if ($bledy !== []) {
    json_fail(implode(' ', $bledy));
}

$userId = auth_user_id();
$id = (int) ($wejscie['id'] ?? 0);

if ($id > 0) {
    // Istniejący rekord — tylko właściciel może zaktualizować
    $st = db()->prepare('SELECT id FROM bib_ksiazki WHERE id = ? AND user_id = ?');
    $st->execute([$id, $userId]);
    if ($st->fetch() === false) {
        json_fail('Nie znaleziono książki.', 404);
    }
    ksiazka_zaktualizuj($id, $dane, $userId);
    tagi_ksiazki_zapisz($id, $wejscie['tagi'] ?? [], $userId);
    polki_ksiazki_zapisz($id, $wejscie['polki'] ?? [], $userId);
    json_out(['id' => $id, 'ok' => true]);
}

$noweId = ksiazka_wstaw($dane, $userId, (string) $dane['zrodlo']);
tagi_ksiazki_zapisz($noweId, $wejscie['tagi'] ?? [], $userId);
polki_ksiazki_zapisz($noweId, $wejscie['polki'] ?? [], $userId);

json_out(['id' => $noweId, 'ok' => true]);