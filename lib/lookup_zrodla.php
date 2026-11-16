<?php
declare(strict_types=1);

/**
 * Biblioteka — źródła zewnętrzne lookupu (tu jest HTTP; czysty mapping
 * siedzi w lib/logika.php i jest testowany osobno).
 *
 * Każde źródło zwraca ['kod' => int HTTP (0 = błąd sieci), 'wspolne' => ?array]:
 *   kod 200 + tablica = trafienie,
 *   kod 200 + null    = źródło działa, ale nie zna tej książki (można cache'ować „brak"),
 *   kod inny / 0      = źródło nie odpowiada (NIE cache'ować „braku").
 *
 * Wszystkie źródła dostają po JEDNYM wariancie ISBN (13- i 10-cyfrowym):
 * skaner zawsze czyta EAN-13, a starsze polskie książki BN ma tylko po ISBN-10.
 */

/** Biblioteka Narodowa (data.bn.org.pl): rekordy bibliograficzne po ISBN —
 *  polska podstawa lookupu. Bez klucza. Rekordy po `limit`, wybieramy
 *  najlepszy rodzaj materiału (książka > e-book > audiobook). */
function bn_ksiazka(string $isbn): array
{
    $url = 'https://data.bn.org.pl/api/institutions/bibs.json?isbnIssn='
         . rawurlencode($isbn) . '&limit=10';
    $r = http_pobierz_json($url);
    if ($r['kod'] !== 200 || $r['dane'] === null) {
        return ['kod' => $r['kod'], 'wspolne' => null];
    }
    $rekord = bib_najlepszy_rekord((array) ($r['dane']['bibs'] ?? []));
    return ['kod' => 200, 'wspolne' => $rekord !== null ? bn_na_wspolne($rekord, $isbn) : null];
}

/** Nowy katalog Biblioteki Narodowej (Ex Libris Primo, katalogi.bn.org.pl):
 *  ten sam indeks co wyżej, ale innym wejściem — znajduje też starsze książki
 *  po ISBN-13, których data.bn.org.pl nie zna. Bez klucza i bez limitu. */
function bn_primo_ksiazka(string $isbn): array
{
    $url = 'https://katalogi.bn.org.pl/primaws/rest/pub/pnxs?lang=pl&limit=10&offset=0'
         . '&scope=MyInstitution&sort=rank&tab=LibraryCatalog'
         . '&vid=48OMNIS_NLOP:48OMNIS_NLOP&skipDelivery=Y'
         . '&q=' . rawurlencode('isbn,exact,' . $isbn);
    $r = http_pobierz_json($url);
    if ($r['kod'] !== 200 || $r['dane'] === null) {
        return ['kod' => $r['kod'], 'wspolne' => null];
    }
    $rekord = bib_najlepszy_rekord_primo((array) ($r['dane']['docs'] ?? []));
    return ['kod' => 200, 'wspolne' => $rekord !== null ? bn_primo_na_wspolne($rekord, $isbn) : null];
}

/** Google Books: szukaj po ISBN.
 *
 *  **WYŁĄCZONE z łańcucha od 0.25.0 — zmierzone jako martwe dla polskich książek.**
 *  Klucz jest skonfigurowany i API odpowiada HTTP 200, ale:
 *
 *  - operator `isbn:` zwraca `totalItems=0` **zawsze** — także dla tytułów,
 *    które Google ma (`intitle:Drogi do nowoczesnej Europy` → 1 wynik,
 *    `isbn:9788301123451` → 0 wyników);
 *  - zwykłe wyszukiwanie po samym ISBN (`q=9788380082533`) → 0 wyników
 *    dla polskich wydań.
 *
 *  Pomiar na 109 ISBN-ach (41 bestsellerów rynkowych + 68 z katalogu BN):
 *  **0 trafień (0 %)**, 416 ms zmarnowane na każdy lookup. Funkcja zostaje
 *  w kodzie, żeby dało się ją przywrócić jedną zmianą w `api/lookup.php`,
 *  gdyby Google wrócił z indeksowaniem polskich wydań. */
function google_ksiazka(string $isbn): array
{
    $url = 'https://www.googleapis.com/books/v1/volumes?q=isbn:' . rawurlencode($isbn);
    if (GOOGLE_BOOKS_KEY !== '') {
        $url .= '&key=' . rawurlencode(GOOGLE_BOOKS_KEY);
    }
    $r = http_pobierz_json($url);
    if ($r['kod'] !== 200 || $r['dane'] === null) {
        return ['kod' => $r['kod'], 'wspolne' => null];
    }
    $items = $r['dane']['items'] ?? [];
    if (!is_array($items) || $items === []) {
        return ['kod' => 200, 'wspolne' => null];
    }
    $vi = $items[0]['volumeInfo'] ?? [];
    return ['kod' => 200, 'wspolne' => is_array($vi) ? google_na_wspolne($vi, $isbn) : null];
}

/** Open Library: search.json po ISBN (stare api/books zwróciło 404 — wycofane). */
function ol_ksiazka(string $isbn): array
{
    $url = 'https://openlibrary.org/search.json?isbn=' . rawurlencode($isbn)
         . '&limit=1'
         . '&fields=title,subtitle,author_name,first_publish_year,publisher,'
         . 'number_of_pages_median,language,cover_i,original_title';
    $r = http_pobierz_json($url);
    if ($r['kod'] !== 200 || $r['dane'] === null) {
        return ['kod' => $r['kod'], 'wspolne' => null];
    }
    $docs = $r['dane']['docs'] ?? [];
    if (!is_array($docs) || $docs === []) {
        return ['kod' => 200, 'wspolne' => null];
    }
    $doc = $docs[0];
    return ['kod' => 200, 'wspolne' => is_array($doc) ? ol_na_wspolne($doc, $isbn) : null];
}