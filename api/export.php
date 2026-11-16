<?php
declare(strict_types=1);

/**
 * Eksport katalogu — kopija danych użytkownika:
 *   ?format=json  — pełny JSON (format round-trip: na nim opiera się „przywrócenie z eksportu")
 *   ?format=csv   — CSV z BOM (Excel/Arkusz, delimiter „;")
 *   ?format=zip   — JSON + CSV + wszystkie okładki i zdjęcia egzemplarzy
 *
 * Od wersji v2 plik niesie oba katalogi: książki papierowe i ebooki.
 *
 * GET /api/export.php?format=json
 */

require_once __DIR__ . '/../config.php';

auth_require();

$format = (string) ($_GET['format'] ?? 'json');
if (!in_array($format, ['json', 'csv', 'zip'], true)) {
    json_fail('Nieznany format eksportu.');
}

$stmt = db()->prepare('SELECT * FROM bib_ksiazki WHERE user_id = ? ORDER BY tytul ASC, id ASC');
$stmt->execute([auth_user_id()]);
$ksiazki = $stmt->fetchAll();

// Tagi i półki doklejone do rekordów (ta sama mapa co w list.php — bez N+1)
$tagi = [];
$polki = [];
if ($ksiazki !== []) {
    $idKsiazek = array_map('intval', array_column($ksiazki, 'id'));
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
        "SELECT kp.ksiazka_id, p.nazwa FROM bib_ksiazka_polk kp
         JOIN bib_polk p ON p.id = kp.polka_id
         WHERE kp.ksiazka_id IN ($ph) ORDER BY p.nazwa ASC"
    );
    $stPolka->execute($idKsiazek);
    foreach ($stPolka->fetchAll() as $r) {
        $polki[(int) $r['ksiazka_id']][] = (string) $r['nazwa'];
    }
}

foreach ($ksiazki as &$k) {
    $id = (int) $k['id'];
    $k['tagi'] = $tagi[(string) $id] ?? [];
    $k['polki'] = $polki[(string) $id] ?? [];
}
unset($k);

// Ebooki — drugi, równoległy katalog. Bierzemy całe wiersze (round-trip ma
// odtworzyć rekord 1:1), a tagi i półki doklejamy tą samą techniką co przy
// papierze, czyli jednym zapytaniem na relację.
$stEbook = db()->prepare('SELECT * FROM bib_ebooki WHERE user_id = ? ORDER BY tytul ASC, id ASC');
$stEbook->execute([auth_user_id()]);
$ebooki = $stEbook->fetchAll();

$tagiE = [];
$polkiE = [];
if ($ebooki !== []) {
    $idEbookow = array_map('intval', array_column($ebooki, 'id'));
    $ph = implode(',', array_fill(0, count($idEbookow), '?'));

    $stTag = db()->prepare(
        "SELECT et.ebook_id, t.nazwa FROM bib_ebook_tagi et
         JOIN bib_tagi t ON t.id = et.tag_id
         WHERE et.ebook_id IN ($ph) ORDER BY t.nazwa ASC"
    );
    $stTag->execute($idEbookow);
    foreach ($stTag->fetchAll() as $r) {
        $tagiE[(int) $r['ebook_id']][] = (string) $r['nazwa'];
    }

    $stPolka = db()->prepare(
        "SELECT ep.ebook_id, p.nazwa FROM bib_ebook_polk ep
         JOIN bib_polk p ON p.id = ep.polka_id
         WHERE ep.ebook_id IN ($ph) ORDER BY p.nazwa ASC"
    );
    $stPolka->execute($idEbookow);
    foreach ($stPolka->fetchAll() as $r) {
        $polkiE[(int) $r['ebook_id']][] = (string) $r['nazwa'];
    }
}

foreach ($ebooki as &$e) {
    $id = (int) $e['id'];
    $e['tagi'] = $tagiE[(string) $id] ?? [];
    $e['polki'] = $polkiE[(string) $id] ?? [];
}
unset($e);

// Historia wypożyczeń — osobna sekcja eksportu. Wiązemy po ISBN (stabilny
// identyfikator egzemplarza), z tytułem jako zapasowym wskazaniem, bo po
// imporcie identyfikatory książek są nowe.
$stWyp = db()->prepare(
    'SELECT wy.ksiazka_id, wy.osoba, wy.data_wypozyczenia, wy.data_zwrotu, wy.uwagi,
            k.isbn, k.tytul
     FROM bib_wypozyczenia wy
     JOIN bib_ksiazki k ON k.id = wy.ksiazka_id
     WHERE wy.user_id = ? AND k.user_id = ?
     ORDER BY wy.data_wypozyczenia ASC'
);
$stWyp->execute([auth_user_id(), auth_user_id()]);
$wypozyczenia = $stWyp->fetchAll();
foreach ($wypozyczenia as &$w) {
    unset($w['ksiazka_id']);   // id nie jest przenośne między instalacjami
}
unset($w);

// Słowniki — import odtwarza nazwy, nie ich identyfikatory
$slowowniki = [
    'tagi'  => array_column(tagi_slownik(auth_user_id()), 'nazwa'),
    'polki' => array_column(polki_slownik(auth_user_id()), 'nazwa'),
];

$dzisiaj = date('Y-m-d');
$baza = 'biblioteka-eksport-' . $dzisiaj;

$metryczka = [
    'id', 'isbn', 'tytul', 'podtytul', 'autor', 'rok', 'wydawca', 'liczba_stron',
    'format', 'szerokosc', 'ocena',
    'jezyk', 'oryginalny_tytul', 'opis', 'okladka', 'zdjecie', 'status',
    'notatki', 'zrodlo', 'data_dodania', 'data_edycji',
];

function eksport_json(array $ksiazki, array $ebooki, array $slowowniki, array $wypozyczenia): string
{
    return json_encode([
        // v2: doszła gałąź `ebooki`; v1 czyta się dalej bez zmian, bo układ
        // pól książek i wypożyczeń jest ten sam (zmiana jest addytywna).
        'format'    => 'biblioteka-eksport/v2',
        'wersja'    => APP_VERSION,
        'data'      => date('c'),
        'liczba'    => count($ksiazki),
        'liczba_ebooki' => count($ebooki),
        'slowowniki' => $slowowniki,
        'ksiazki'   => $ksiazki,
        // Ebooki: całe wiersze + tagi/półki jako nazwy (identyfikatory nie są
        // przenośne między instalacjami).
        'ebooki'    => $ebooki,
        // Historia wypożyczeń — powiązana po ISBN (id książki nie jest
        // przenośne między instalacjami, więc import dopasuje po ISBN/tytule)
        'wypozyczenia' => $wypozyczenia,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
}

function eksport_csv(array $ksiazki, array $ebooki, array $metryczka, array $wypozyczenia): string
{
    // Ebooki jadą tymi samymi kolumnami co papier — CSV ma zostać jedną
    // tabelą dla arkusza, a nie drugim formatem round-tripu (od tego jest
    // JSON). Kolumna `typ` mówi, z którego katalogu jest wiersz; pola, których
    // dany katalog nie ma (np. szerokosc/zdjecie dla ebooka), zostają puste.
    $naglowki = array_merge(['typ'], $metryczka, ['tagi', 'polki']);
    $linie = [csv_linia($naglowki)];

    foreach ($ksiazki as $k) {
        $wartosci = array_map(static fn ($p) => $k[$p] ?? null, $metryczka);
        $wartosci[] = implode(',', $k['tagi'] ?? []);
        $wartosci[] = implode(',', $k['polki'] ?? []);
        array_unshift($wartosci, 'papier');
        $linie[] = csv_linia($wartosci);
    }

    foreach ($ebooki as $e) {
        $wartosci = array_map(static fn ($p) => $e[$p] ?? null, $metryczka);
        $wartosci[] = implode(',', $e['tagi'] ?? []);
        $wartosci[] = implode(',', $e['polki'] ?? []);
        array_unshift($wartosci, 'ebook');
        $linie[] = csv_linia($wartosci);
    }

    // Wypożyczenia w osobnym bloku (CSV ma jedną tabelę na arkusz)
    if ($wypozyczenia !== []) {
        $linie[] = '';
        $linie[] = csv_linia(['Wypożyczenia']);
        $linie[] = csv_linia(['isbn', 'tytul', 'osoba', 'data_wypozyczenia', 'data_zwrotu', 'uwagi']);
        foreach ($wypozyczenia as $w) {
            $linie[] = csv_linia([
                $w['isbn'] ?? '', $w['tytul'] ?? '', $w['osoba'] ?? '',
                $w['data_wypozyczenia'] ?? '', $w['data_zwrotu'] ?? '', $w['uwagi'] ?? '',
            ]);
        }
    }

    return implode("\n", $linie) . "\n";
}

function naglowek_pobranie(string $nazwa, string $mime): void
{
    header('Content-Type: ' . $mime . '; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $nazwa . '"');
    header('Cache-Control: no-store');
}

switch ($format) {
    case 'json':
        naglowek_pobranie($baza . '.json', 'application/json');
        echo eksport_json($ksiazki, $ebooki, $slowowniki, $wypozyczenia);
        exit;

    case 'csv':
        naglowek_pobranie($baza . '.csv', 'text/csv');
        echo "\xEF\xBB\xBF";                      // BOM — polskie znaki w Excelu
        echo eksport_csv($ksiazki, $ebooki, $metryczka, $wypozyczenia);
        exit;

    case 'zip':
        if (!class_exists('ZipArchive')) {
            json_fail('Serwer bez rozszerzenia zip — pobierz eksport jako JSON.', 501);
        }
        $zipTmp = tempnam(sys_get_temp_dir(), 'bib_eksport_');
        if ($zipTmp === false) {
            json_fail('Nie udało się utworzyć pliku tymczasowego.', 500);
        }
        $zip = new ZipArchive();
        if ($zip->open($zipTmp, ZipArchive::OVERWRITE) !== true) {
            json_fail('Nie udało się otworzyć archiwum.', 500);
        }
        $zip->addFromString('eksport.json', eksport_json($ksiazki, $ebooki, $slowowniki, $wypozyczenia));
        $zip->addFromString('eksport.csv', "\xEF\xBB\xBF" . eksport_csv($ksiazki, $ebooki, $metryczka, $wypozyczenia));

        // Okładki i zdjęcia wskazane przez rekordy trafiają do archiwum
        $pliczki = 0;
        foreach ($ksiazki as $k) {
            foreach (['okladka' => OKLADKI_DIR, 'zdjecie' => ZDJECIA_DIR] as $pole => $katalog) {
                $sciezka = (string) ($k[$pole] ?? '');
                if ($sciezka === '') {
                    continue;
                }
                $nazwa = basename($sciezka);
                if (is_file($katalog . '/' . $nazwa)) {
                    $zip->addFile($katalog . '/' . $nazwa, ($pole === 'okladka' ? 'okladki/' : 'zdjecia/') . $nazwa);
                    $pliczki++;
                }
            }
        }
        // Okładek ebooków celowo nie pakujemy: to miniatury generowane po
        // stronie klienta (WebP w uploads/okladki-ebooki/), a ich odtworzenie
        // wymagałoby dopasowania pliku do wiersza po imporcie — metadane
        // w eksport.json/csv są kompletne, więc w tym etapie wystarczą.
        $zip->addFromString('README.txt',
            "Eksport katalogu Biblioteka z {$dzisiaj} (wersja aplikacji " . APP_VERSION . ")\n" .
            "Rekordy: " . count($ksiazki) . ", ebooki: " . count($ebooki) .
            ", pliki graficzne: {$pliczki}, wypożyczenia: " . count($wypozyczenia) . ".\n" .
            "eksport.json — format biblioteka-eksport/v2 (round-trip, książki + ebooki),\n" .
            "eksport.csv — do arkusza kalkulacyjnego (delimiter ';', UTF-8 z BOM, kolumna 'typ').\n");
        $zip->close();

        naglowek_pobranie($baza . '.zip', 'application/zip');
        header('Content-Length: ' . filesize($zipTmp));
        readfile($zipTmp);
        @unlink($zipTmp);
        exit;
}
