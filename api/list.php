<?php
declare(strict_types=1);

/**
 * Lista katalogu (bez pola opis — treść opisu leci w szczegółach książki).
 * Filtry i sortowanie budowane przez lista_zapytanie() (białe listy + parametry).
 * Do każdego rekordu doklejamy tagi, półki i aktywne wypożyczenie
 * (zapytania zbiorcze, nie N+1).
 *
 * GET /api/list.php?q=...&status=...&autor=...&rok=...&wydawca=...&jezyk=...
 *                  &tag=fantastyka,horror&polka=3&wypozyczone=tak
 *                  &format=twarda&szerokosc=standard
 *                  &dodane_od=RRRR-MM-DD&dodane_do=RRRR-MM-DD
 *                  &sort=tytul|wypozyczone&kier=asc|desc
 */

require_once __DIR__ . '/../config.php';

auth_require();

$filtry = [
    'q'           => (string) ($_GET['q'] ?? ''),
    'status'      => (string) ($_GET['status'] ?? ''),
    'autor'       => (string) ($_GET['autor'] ?? ''),
    'rok'         => (string) ($_GET['rok'] ?? ''),
    'wydawca'     => (string) ($_GET['wydawca'] ?? ''),
    'jezyk'       => (string) ($_GET['jezyk'] ?? ''),
    'format'      => (string) ($_GET['format'] ?? ''),
    'szerokosc'   => (string) ($_GET['szerokosc'] ?? ''),
    'ocenione'    => (string) ($_GET['ocenione'] ?? ''),
    'tag'         => (string) ($_GET['tag'] ?? ''),
    'polka'       => (string) ($_GET['polka'] ?? ''),
    'wypozyczone' => (string) ($_GET['wypozyczone'] ?? ''),
    'dodane_od'   => (string) ($_GET['dodane_od'] ?? ''),
    'dodane_do'   => (string) ($_GET['dodane_do'] ?? ''),
];
// Puste stringi = brak filtra
$filtry = array_filter($filtry, static fn ($v) => $v !== '');

$sort = (string) ($_GET['sort'] ?? 'tytul');
$kier = (string) ($_GET['kier'] ?? 'asc');
$plan = lista_zapytanie($filtry, $sort === SORT_WYPOZYCZENIE ? 'tytul' : $sort, $kier);

$sql = 'SELECT id, isbn, tytul, podtytul, autor, rok, wydawca, liczba_stron,
               format, szerokosc, ocena, jezyk,
               oryginalny_tytul, okladka, zdjecie, status, notatki, zrodlo,
               data_dodania, data_edycji
        FROM bib_ksiazki
        WHERE user_id = ?';
if ($plan['warunki'] !== []) {
    $sql .= ' AND ' . implode(' AND ', $plan['warunki']);
}

// Sortowanie po dacie wypożyczenia wymaga innej tabeli — podczepiamy kolumnę
// skalarną (dla wypożyczonych = data, dla reszty = NULL) i sortujemy po niej.
if ($sort === SORT_WYPOZYCZENIE) {
    $kSql = strtoupper($kier) === 'DESC' ? 'DESC' : 'ASC';
    $sql = 'SELECT k.*, (SELECT MAX(wy.data_wypozyczenia) FROM bib_wypozyczenia wy'
        . ' WHERE wy.ksiazka_id = k.id) AS data_wypozyczenia_sort'
        . ' FROM bib_ksiazki k WHERE k.user_id = ?';
    if ($plan['warunki'] !== []) {
        $sql .= ' AND ' . implode(' AND ', str_replace('bib_ksiazki.', 'k.', $plan['warunki']));
    }
    $sql .= " ORDER BY (data_wypozyczenia_sort IS NULL), data_wypozyczenia_sort {$kSql}, k.id ASC";
} else {
    $sql .= ' ORDER BY ' . $plan['sortowanie'] . ', id ASC';
}

$stmt = db()->prepare($sql);
$stmt->execute(array_merge([auth_user_id()], $plan['parametry']));
$ksiazki = $stmt->fetchAll();

// Tagi, półki i aktywne wypożyczenia dla całego wyniku (mapa id → dane)
$idKsiazek = array_map('intval', array_column($ksiazki, 'id'));
$tagi = [];
$polki = [];
$wypozyczenia = [];
if ($idKsiazek !== []) {
    $ph = implode(',', array_fill(0, count($idKsiazek), '?'));

    $stTag = db()->prepare(
        "SELECT kt.ksiazka_id, t.nazwa FROM bib_ksiazka_tagi kt
         JOIN bib_tagi t ON t.id = kt.tag_id
         WHERE kt.ksiazka_id IN ($ph) ORDER BY t.nazwa ASC"
    );
    $stTag->execute($idKsiazek);
    foreach ($stTag->fetchAll() as $r) {
        $tagi[(int) $r['ksiazka_id']][] = (string) $r['nazwa'];
    }

    $stPolka = db()->prepare(
        "SELECT kp.ksiazka_id, p.id, p.nazwa FROM bib_ksiazka_polk kp
         JOIN bib_polk p ON p.id = kp.polka_id
         WHERE kp.ksiazka_id IN ($ph) ORDER BY p.nazwa ASC"
    );
    $stPolka->execute($idKsiazek);
    foreach ($stPolka->fetchAll() as $r) {
        $polki[(int) $r['ksiazka_id']][] = ['id' => (int) $r['id'], 'nazwa' => (string) $r['nazwa']];
    }

    $stWyp = db()->prepare(
        "SELECT id, ksiazka_id, osoba, data_wypozyczenia, data_zwrotu, uwagi
         FROM bib_wypozyczenia
         WHERE data_zwrotu IS NULL AND ksiazka_id IN ($ph)"
    );
    $stWyp->execute($idKsiazek);
    foreach ($stWyp->fetchAll() as $r) {
        $wypozyczenia[(int) $r['ksiazka_id']] = [
            'id'         => (int) $r['id'],
            'osoba'      => (string) $r['osoba'],
            'od'         => wypozyczenie_data_skrot((string) $r['data_wypozyczenia']),
            'uwagi'      => $r['uwagi'] !== null ? (string) $r['uwagi'] : null,
        ];
    }
}

foreach ($ksiazki as &$k) {
    $id = (int) $k['id'];
    $k['tagi'] = $tagi[$id] ?? [];
    $k['polki'] = $polki[$id] ?? [];
    $k['wypozyczona'] = $wypozyczenia[$id] ?? null;
    unset($k['data_wypozyczenia_sort']);
}
unset($k);

json_out(['ksiazki' => $ksiazki]);
