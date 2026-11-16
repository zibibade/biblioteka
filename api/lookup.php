<?php
declare(strict_types=1);

/**
 * Lookup ISBN przez proxy serwera: cache w bazie → Biblioteka Narodowa →
 * nowy katalog BN (Primo) → Google Books → Open Library.
 *
 * Każde źródło dostaje obie formy ISBN (13- i 10-cyfrową): skaner czyta
 * EAN-13 z prefiksem 978, a BN ma starsze polskie książki tylko po ISBN-10.
 * Kolejne źródła uzupełniają tylko puste pola (opis, okładka…). Okładka
 * pobierana na hosting (katalog nie zależy od API zewnętrznych).
 *
 * GET /api/lookup.php?isbn=9788301123456
 * Odpowiedź: { "dane": {...}|null, "zrodlo": "...", "z_cache": bool }
 * „dane": null = brak w bazach → frontend przechodzi w tryb ręczny.
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../lib/lookup_zrodla.php';

auth_require();

/** Zapis cache lookupu — INSERT z nadpisaniem, bo stary wpis po TTL zostaje. */
function cache_isbn_zapisz(string $isbn, string $zrodlo, ?string $dane): void
{
    db()->prepare(
        'INSERT INTO bib_isbn_cache (isbn, zrodlo, dane, pobrano) VALUES (?, ?, ?, NOW())
         ON DUPLICATE KEY UPDATE zrodlo = VALUES(zrodlo), dane = VALUES(dane), pobrano = NOW()'
    )->execute([$isbn, $zrodlo, $dane]);
}

$isbn = isbn_normalizuj((string) ($_GET['isbn'] ?? ''));
if ($isbn === '' || !isbn_poprawny($isbn)) {
    json_fail('Nieprawidłowy ISBN.');
}

$warianty = isbn_warianty($isbn);

// 1. Cache — powtórny skan tej samej książki nie woła zewnętrznych API.
//    Wpisy „brak” mają TTL (LOOKUP_CACHE_BRAK_DNI), bo po poprawkach źródeł
//    książka, która kiedyś się nie znalazła, może znajdzie się dziś.
//    TTL <= 0 wyłącza wygasanie (zachowanie sprzed 0.17.0).
$ttlBrak = defined('LOOKUP_CACHE_BRAK_DNI') ? (int) LOOKUP_CACHE_BRAK_DNI : 0;
if ($ttlBrak > 0) {
    $stmt = db()->prepare(
        'SELECT zrodlo, dane FROM bib_isbn_cache
          WHERE isbn = ? AND (zrodlo <> "brak" OR pobrano > ?)'
    );
    $stmt->execute([$isbn, date('Y-m-d H:i:s', time() - $ttlBrak * 86400)]);
} else {
    $stmt = db()->prepare('SELECT zrodlo, dane FROM bib_isbn_cache WHERE isbn = ?');
    $stmt->execute([$isbn]);
}
$wiersz = $stmt->fetch();
if ($wiersz !== false) {
    json_out([
        'dane'    => $wiersz['dane'] !== null ? json_decode((string) $wiersz['dane'], true) : null,
        'zrodlo'  => (string) $wiersz['zrodlo'],
        'z_cache' => true,
    ]);
}

// 2. Warianty ISBN × źródła, w kolejności taniej i skuteczniejszej.
//
//    Kolejność zmieniona w 0.25.0 na podstawie pomiaru na 109 ISBN-ach
//    (41 bestsellerów rynkowych + 68 z katalogu BN):
//
//    | źródło            | trafień   | unikalnych | czas   |
//    |-------------------|-----------|------------|--------|
//    | BN data.bn.org.pl | 103 (94 %) | 0          | 82 ms  |
//    | BN Primo          | 103 (94 %) | 0          | 331 ms |
//    | Open Library      | 33  (30 %) | 4          | 708 ms |
//    | Google Books      | 0   (0 %)  | 0          | 416 ms |
//
//    Wnioski z pomiaru, nie zgadywania:
//    * **oba wejścia BN mają identyczne pokrycie** (103/103, zero unikalnych
//      w obie strony) → Primo odpytujemy dopiero, gdy data.bn nie ma
//      odpowiedzi. Oszczędza ~330 ms na każdym trafionym skanie, a pokrycie
//      zostaje identyczne (zmierzone na tych samych 109 ISBN-ach).
//    * **Google Books nie zna polskich wydań.** Operator `isbn:` zwraca
//      `totalItems=0` nawet dla tytułów, które Google ma (`intitle:` → 1
//      wynik, `isbn:9788301123451` → 0), a zwykłe wyszukiwanie po samym
//      ISBN też 0 dla polskich wydań. 0 % na 109 ISBN-ach.
//    * **Open Library zostaje** — jedyna realna ratunka (4 unikalne trafienia)
//      i **jedyny, który daje okładki**: BN nie zwraca `okladka_url`.
//
//    „komplet” = na każde zapytanie, które faktycznie wysłaliśmy, padło 200;
//    „cosOdpowiedzialo” = choć raz 200 (nie mylimy „nikt nie zna ISBN”
//    z „wszyscy padli”).
$dane = null;
$zrodlo = '';
$komplet = true;
$cosOdpowiedzialo = false;
foreach ($warianty as $w) {
    $bn = bn_ksiazka($w);
    $o  = ol_ksiazka($w);
    $pk = ['kod' => 200, 'wspolne' => null];   // zapas, zapytamy w razie potrzeby

    foreach ([$bn['kod'], $o['kod']] as $kod) {
        if ($kod === 200) {
            $cosOdpowiedzialo = true;
        } else {
            $komplet = false;
        }
    }

    $scalone = ksiazka_scal($bn['wspolne'], null, null, $o['wspolne']);
    if ($scalone === null) {
        // BN (stare API) nie znalazło. Primo ma ten sam indeks, ale inne
        // wejście — warto o nie zapytać, gdyby kiedyś data.bn.org.pl
        // przestało odpowiadać.
        $pk = bn_primo_ksiazka($w);
        if ($pk['kod'] === 200) {
            $cosOdpowiedzialo = true;
        } else {
            $komplet = false;
        }
        $scalone = ksiazka_scal(null, $pk['wspolne'], null, null);
    }

    if ($scalone !== null) {
        $dane = $scalone;
        $dane['isbn'] = $isbn;   // ISBN fizycznego egzemplarza (ten ze skanu), nie wariantu
        $zrodlo = implode('+', array_filter([
            $bn['wspolne'] !== null ? 'bn' : '',
            $pk['wspolne'] !== null ? 'bnk' : '',
            $o['wspolne']  !== null ? 'ol' : '',
        ]));
        break;
    }
}

// Nikt nie odpowiedział → błąd tymczasowy (NIE cache’ujemy „braku”, bo to by
// zatruło cache na stałe przy chwilowej awarii albo limicie zapytań)
if ($dane === null && !$cosOdpowiedzialo) {
    json_fail('Bazy zewnętrzne nie odpowiadają. Spróbuj ponownie za chwilę.', 503);
}

if ($dane === null) {
    // „Brak” cache’ujemy dopiero przy pełnej odpowiedzi WSZYSTKICH źródeł —
    // jeśli któreś nie odpowiedziało, nie znamy odpowiedzi na pewno
    // i kolejne skanowanie zapyta ponownie (nie zatrworzamy cache).
    if ($komplet) {
        cache_isbn_zapisz($isbn, 'brak', null);
    }
    json_out(['dane' => null, 'zrodlo' => 'brak', 'z_cache' => false]);
}

// 3. Okładka na hosting
if (($dane['okladka_url'] ?? null) !== null) {
    $lokalna = okladka_pobierz((string) $dane['okladka_url']);
    if ($lokalna !== null) {
        $dane['okladka'] = $lokalna;
    }
}

cache_isbn_zapisz($isbn, $zrodlo, json_encode($dane, JSON_UNESCAPED_UNICODE));

json_out(['dane' => $dane, 'zrodlo' => $zrodlo, 'z_cache' => false]);