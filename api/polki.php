<?php
declare(strict_types=1);

/**
 * Półki — nazwane grupy książek (własny widok listy). Miejsce na półce
 * (kolejność na regale) jest świadomie poza zakresem.
 *
 * GET  /api/polki.php                          → lista półek z liczbą książek
 * POST /api/polki.php { akcja: 'zapisz', nazwa, opis? }   → utworzenie/zmiana
 * POST /api/polki.php { akcja: 'usun',  id }
 */

require_once __DIR__ . '/../config.php';

auth_require();

$metoda = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($metoda === 'GET') {
    json_out(['polki' => polki_slownik(auth_user_id())]);
}

if ($metoda !== 'POST') {
    json_fail('Metoda niedozwolona.', 405);
}

$wejscie = json_decode((string) file_get_contents('php://input'), true);
if (!is_array($wejscie)) {
    json_fail('Oczekiwano JSON.');
}

$akcja = (string) ($wejscie['akcja'] ?? '');
$userId = auth_user_id();

if ($akcja === 'usun') {
    $id = (int) ($wejscie['id'] ?? 0);
    if ($id < 1) {
        json_fail('Brak identyfikatora półki.');
    }
    db()->prepare('DELETE FROM bib_polk WHERE id = ? AND user_id = ?')->execute([$id, $userId]);
    json_out(['ok' => true]);
}

if ($akcja !== 'zapisz') {
    json_fail('Nieznana akcja.');
}

$walidacja = polka_nazwa_waliduj((string) ($wejscie['nazwa'] ?? ''));
if ($walidacja['blad'] !== null) {
    json_fail((string) $walidacja['blad']);
}
$nazwa = (string) $walidacja['nazwa'];

$opis = niepuste($wejscie['opis'] ?? null);
if ($opis !== null && mb_strlen($opis) > POLKA_MAX_OPIS) {
    json_fail(sprintf('Opis półki jest za długi (max %d znaków).', POLKA_MAX_OPIS));
}

// Klucz porównawczy: małe litery, żeby „Saloner” i „saloner” to jedna półka
$klucz = mb_strtolower($nazwa);
$id = (int) ($wejscie['id'] ?? 0);

if ($id > 0) {
    $st = db()->prepare('SELECT id FROM bib_polk WHERE id = ? AND user_id = ?');
    $st->execute([$id, $userId]);
    if ($st->fetch() === false) {
        json_fail('Nie znaleziono półki.', 404);
    }
    try {
        db()->prepare('UPDATE bib_polk SET nazwa = ?, opis = ? WHERE id = ? AND user_id = ?')
            ->execute([$klucz, $opis, $id, $userId]);
    } catch (PDOException $e) {
        json_fail('Półka o tej nazwie już istnieje.');
    }
    json_out(['id' => $id, 'ok' => true]);
}

try {
    db()->prepare('INSERT INTO bib_polk (user_id, nazwa, opis) VALUES (?, ?, ?)')
        ->execute([$userId, $klucz, $opis]);
} catch (PDOException $e) {
    json_fail('Półka o tej nazwie już istnieje.');
}
json_out(['id' => (int) db()->lastInsertId(), 'ok' => true]);
