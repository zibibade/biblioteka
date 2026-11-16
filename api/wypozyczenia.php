<?php
declare(strict_types=1);

/**
 * Wypożyczenia z historią. Wiersz = jedno wypożyczenie konkretnego egzemplarza;
 * `data_zwrotu` NULL oznacza „wciąż wypożyczone”. Po zwrocie wiersz zostaje.
 *
 * GET  /api/wypozyczenia.php                → aktywne wypożyczenia (lista)
 * GET  /api/wypozyczenia.php?ksiazka=12     → pełna historia jednej książki
 * POST /api/wypozyczenia.php { akcja: 'wypozycz', ksiazka_id, osoba, uwagi? }
 * POST /api/wypozyczenia.php { akcja: 'zwroc', id, data_zwrotu? }
 * POST /api/wypozyczenia.php { akcja: 'usun', id }
 */

require_once __DIR__ . '/../config.php';

auth_require();

$userId = auth_user_id();
$metoda = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($metoda === 'GET') {
    $ksiazkaId = (int) ($_GET['ksiazka'] ?? 0);
    $where = 'wy.user_id = ?';
    $parametry = [$userId];
    if ($ksiazkaId > 0) {
        $where .= ' AND wy.ksiazka_id = ?';
        $parametry[] = $ksiazkaId;
    }

    $st = db()->prepare(
        "SELECT wy.id, wy.ksiazka_id, wy.osoba, wy.data_wypozyczenia, wy.data_zwrotu,
                wy.uwagi, k.tytul, k.okladka, k.autor
         FROM bib_wypozyczenia wy
         JOIN bib_ksiazki k ON k.id = wy.ksiazka_id
         WHERE {$where}
         ORDER BY (wy.data_zwrotu IS NULL) DESC, wy.data_wypozyczenia DESC"
    );
    $st->execute($parametry);
    $lista = $st->fetchAll();
    foreach ($lista as &$w) {
        $w['od'] = wypozyczenie_data_skrot((string) $w['data_wypozyczenia']);
        $w['do'] = $w['data_zwrotu'] !== null
            ? wypozyczenie_data_skrot((string) $w['data_zwrotu']) : null;
        $w['aktywne'] = $w['data_zwrotu'] === null;
        $w['opis'] = wypozyczenie_opis($w);
    }
    unset($w);

    json_out(['wypozyczenia' => $lista]);
}

if ($metoda !== 'POST') {
    json_fail('Metoda niedozwolona.', 405);
}

$wejscie = json_decode((string) file_get_contents('php://input'), true);
if (!is_array($wejscie)) {
    json_fail('Oczekiwano JSON.');
}
$akcja = (string) ($wejscie['akcja'] ?? '');

/* ------------------------------------------------------------- zwrot */

if ($akcja === 'zwroc') {
    $id = (int) ($wejscie['id'] ?? 0);
    if ($id < 1) {
        json_fail('Brak identyfikatora wypożyczenia.');
    }
    $st = db()->prepare('SELECT * FROM bib_wypozyczenia WHERE id = ? AND user_id = ?');
    $st->execute([$id, $userId]);
    $w = $st->fetch();
    if ($w === false) {
        json_fail('Nie znaleziono wypożyczenia.', 404);
    }
    if ($w['data_zwrotu'] !== null) {
        json_fail('To wypożyczenie już ma datę zwrotu.');
    }
    $dataZwrotu = wypozyczenie_data((string) ($wejscie['data_zwrotu'] ?? '')) ?? date('Y-m-d H:i:s');
    if ($dataZwrotu < (string) $w['data_wypozyczenia']) {
        json_fail('Data zwrotu nie może być wcześniejsza niż data wypożyczenia.');
    }
    db()->prepare('UPDATE bib_wypozyczenia SET data_zwrotu = ? WHERE id = ? AND user_id = ?')
        ->execute([$dataZwrotu, $id, $userId]);
    json_out(['ok' => true]);
}

/* ------------------------------------------------------- usunięcie wpisu */

if ($akcja === 'usun') {
    $id = (int) ($wejscie['id'] ?? 0);
    if ($id < 1) {
        json_fail('Brak identyfikatora wypożyczenia.');
    }
    $st = db()->prepare('DELETE FROM bib_wypozyczenia WHERE id = ? AND user_id = ?');
    $st->execute([$id, $userId]);
    if ($st->rowCount() === 0) {
        json_fail('Nie znaleziono wypożyczenia.', 404);
    }
    json_out(['ok' => true]);
}

if ($akcja !== 'wypozycz') {
    json_fail('Nieznana akcja.');
}

/* ------------------------------------------------------------ wypożyczenie */

$ksiazkaId = (int) ($wejscie['ksiazka_id'] ?? 0);
if ($ksiazkaId < 1) {
    json_fail('Brak identyfikatora książki.');
}

// Książka musi istnieć i należeć do użytkownika
$st = db()->prepare('SELECT tytul FROM bib_ksiazki WHERE id = ? AND user_id = ?');
$st->execute([$ksiazkaId, $userId]);
if ($st->fetch() === false) {
    json_fail('Nie znaleziono książki.', 404);
}

// Jedna książka = najwyżej jedno aktywne wypożyczenie (UNIQUE z NULL nie działa
// w MySQL, więc pilnujemy tego w kodzie)
$st = db()->prepare('SELECT osoba FROM bib_wypozyczenia
                     WHERE ksiazka_id = ? AND user_id = ? AND data_zwrotu IS NULL');
$st->execute([$ksiazkaId, $userId]);
$aktywne = $st->fetchColumn();
if ($aktywne !== false) {
    json_fail(sprintf('Ta książka jest już wypożyczona (%s).', (string) $aktywne));
}

['dane' => $dane, 'bledy' => $bledy] = wypozyczenie_waliduj($wejscie + ['ksiazka_id' => $ksiazkaId]);
if ($bledy !== []) {
    json_fail(implode(' ', $bledy));
}

db()->prepare(
    'INSERT INTO bib_wypozyczenia
        (user_id, ksiazka_id, osoba, data_wypozyczenia, data_zwrotu, uwagi)
     VALUES (?, ?, ?, ?, ?, ?)'
)->execute([
    $userId, $dane['ksiazka_id'], $dane['osoba'], $dane['data_wypozyczenia'],
    $dane['data_zwrotu'], $dane['uwagi'],
]);

json_out(['id' => (int) db()->lastInsertId(), 'ok' => true]);
