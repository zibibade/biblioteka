<?php
declare(strict_types=1);

/**
 * Szczegóły jednej książki (z polem opis) + jej tagi i półki.
 * GET /api/get.php?id=123
 */

require_once __DIR__ . '/../config.php';

auth_require();

$id = (int) ($_GET['id'] ?? 0);
if ($id < 1) {
    json_fail('Brak identyfikatora książki.');
}

$stmt = db()->prepare('SELECT * FROM bib_ksiazki WHERE id = ? AND user_id = ?');
$stmt->execute([$id, auth_user_id()]);
$wiersz = $stmt->fetch();
if ($wiersz === false) {
    json_fail('Nie znaleziono książki.', 404);
}

$wiersz['tagi'] = tagi_ksiazki_pobierz($id);
$wiersz['polki'] = polki_ksiazki_pobierz($id);

// Historia wypożyczeń tej książki (najnowsze pierwsze) + aktywne (bez zwrotu)
$stW = db()->prepare(
    'SELECT id, osoba, data_wypozyczenia, data_zwrotu, uwagi
     FROM bib_wypozyczenia
     WHERE ksiazka_id = ? AND user_id = ?
     ORDER BY data_wypozyczenia DESC'
);
$stW->execute([$id, auth_user_id()]);
$historia = [];
$aktywne = null;
foreach ($stW->fetchAll() as $w) {
    $w['od'] = wypozyczenie_data_skrot((string) $w['data_wypozyczenia']);
    $w['do'] = $w['data_zwrotu'] !== null
        ? wypozyczenie_data_skrot((string) $w['data_zwrotu']) : null;
    $w['aktywne'] = $w['data_zwrotu'] === null;
    $w['opis'] = wypozyczenie_opis($w);
    $historia[] = $w;
    if ($w['aktywne']) {
        $aktywne = $w;
    }
}
$wiersz['wypozyczenia'] = $historia;
$wiersz['wypozyczona'] = $aktywne;

json_out(['ksiazka' => $wiersz]);
