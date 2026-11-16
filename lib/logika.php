<?php
declare(strict_types=1);

/**
 * Biblioteka — logika czysta (bez bazy, bez HTTP, bez sesji).
 * Wszystko stąd da się przetestować w PHPUnit lokalnie, bez MySQL.
 */

/** Pola metryczki wspólne dla API zewnętrznych i rekordu w bazie. */
const KSIAZKA_POLA = [
    'tytul', 'podtytul', 'autor', 'isbn', 'rok', 'wydawca',
    'liczba_stron', 'opis', 'jezyk', 'oryginalny_tytul', 'okladka_url',
];

/** Tokeny źródeł lookupu w kolejności scalania (tożsamość z `api/lookup.php`). */
const KSIAZKA_ZRODLA = ['bn', 'bnk', 'google', 'ol'];

/**
 * Wszystkie poprawne wartości pola `zrodlo`: „recznie" + każda niepusta
 * kombinacja tokenów w kolejności KSIAZKA_ZRODLA (2^4 = 15 wariantów).
 */
function bib_zrodla_dozwolone(): array
{
    $dozwolone = ['recznie'];
    $liczba = count(KSIAZKA_ZRODLA);
    for ($mask = 1; $mask < (1 << $liczba); $mask++) {
        $czesci = [];
        for ($i = 0; $i < $liczba; $i++) {
            if (($mask & (1 << $i)) !== 0) {
                $czesci[] = KSIAZKA_ZRODLA[$i];
            }
        }
        $dozwolone[] = implode('+', $czesci);
    }
    return $dozwolone;
}

/* ================================================================ ISBN */

/** Usuwa myślniki/spacje i ujednolica wielkość liter („978-83-01-12345-6" → „9788301123456"). */
function isbn_normalizuj(string $isbn): string
{
    return strtoupper((string) preg_replace('/[\s\-]/u', '', trim($isbn)));
}

/**
 * Klucz bez polskich znaków: „Miękka" / „MIĘKKA" → „miekka". Używane dla
 * wartości słownikowych (format, szerokość), gdzie w bazie trzymamy klucze
 * ASCII, a użytkownik w formularzu widzi polskie nazwy.
 */
function slug_polska(string $v): string
{
    $mapa = [
        'ą' => 'a', 'ć' => 'c', 'ę' => 'e', 'ł' => 'l', 'ń' => 'n',
        'ó' => 'o', 'ś' => 's', 'ź' => 'z', 'ż' => 'z',
    ];
    $v = mb_strtolower(trim($v));
    return strtr($v, $mapa);
}

/** Suma kontrolna EAN-13 (ISBN-13). */
function isbn13_poprawny(string $isbn): bool
{
    if (preg_match('/^\d{13}$/', $isbn) !== 1) {
        return false;
    }
    $suma = 0;
    for ($i = 0; $i < 13; $i++) {
        $suma += (int) $isbn[$i] * ($i % 2 === 0 ? 1 : 3);
    }
    return $suma % 10 === 0;
}

/** Suma kontrolna ISBN-10 (ostatnia cyfra może być „X" = 10). */
function isbn10_poprawny(string $isbn): bool
{
    if (preg_match('/^\d{9}[\dX]$/', $isbn) !== 1) {
        return false;
    }
    $suma = 0;
    for ($i = 0; $i < 10; $i++) {
        $c = $isbn[$i];
        $waga = 10 - $i;
        $suma += ($c === 'X' ? 10 : (int) $c) * $waga;
    }
    return $suma % 11 === 0;
}

/** Czy (znormalizowany) ISBN jest poprawny —13- lub 10-cyfrowy ze zgodną sumą kontrolną. */
function isbn_poprawny(string $isbn): bool
{
    $i = isbn_normalizuj($isbn);
    return isbn13_poprawny($i) || isbn10_poprawny($i);
}

/** ISBN-10 → ISBN-13 (rdzeń dostaje prefiks 978). Null przy błędnym ISBN-10. */
function isbn_na13(string $isbn): ?string
{
    $i = isbn_normalizuj($isbn);
    if (!isbn10_poprawny($i)) {
        return null;
    }
    $rdzen = '978' . substr($i, 0, 9);
    $suma = 0;
    for ($p = 0; $p < 12; $p++) {
        $suma += (int) $rdzen[$p] * ($p % 2 === 0 ? 1 : 3);
    }
    return $rdzen . (string) ((10 - $suma % 10) % 10);
}

/**
 * ISBN-13 → ISBN-10. Tylko dla prefiksu 978 — 979 (polskie ISBN po 2007 r.)
 * nie ma odpowiednika 10-cyfrowego, bo w starym schemacie nie było miejsca
 * na kolejnych grupę.
 */
function isbn_na10(string $isbn): ?string
{
    $i = isbn_normalizuj($isbn);
    if (!isbn13_poprawny($i) || strncmp($i, '978', 3) !== 0) {
        return null;
    }
    $rdzen = substr($i, 3, 9);
    $suma = 0;
    for ($p = 0; $p < 9; $p++) {
        $suma += (int) $rdzen[$p] * (10 - $p);
    }
    $reszta = (11 - $suma % 11) % 11;
    return $rdzen . ($reszta === 10 ? 'X' : (string) $reszta);
}

/**
 * Warianty ISBN do odpytania w bazach: najpierw ten podany, potem druga forma.
 *
 * Skaner zawsze zwraca EAN-13 z prefiksem 978 (jedyny zapisany na grzbiecie),
 * a Biblioteka Narodowa ma starsze polskie książki wyłącznie po ISBN-10 —
 * ISBN-13 w Polsce obowiązuje dopiero od 2007 r. Bez tej drugiej próby
 * praktycznie cała tania klasyka z lat 90. wyglądała na „nieznaną”.
 */
function isbn_warianty(string $isbn): array
{
    $i = isbn_normalizuj($isbn);
    if ($i === '' || !isbn_poprawny($i)) {
        return $i === '' ? [] : [$i];
    }
    $drugi = strlen($i) === 13 ? isbn_na10($i) : isbn_na13($i);
    return ($drugi === null || $drugi === $i) ? [$i] : [$i, $drugi];
}

/* ==================================================== mappingi z API */

/**
 * Google Books (volumeInfo) → pola wspólne.
 * @param array $vi     element volumeInfo z API
 * @param string $isbn  ISBN użyty do zapytania (fallback, gdy API nie zwróciło identyfikatora)
 */
function google_na_wspolne(array $vi, string $isbn = ''): array
{
    $ident = null;
    foreach (($vi['industryIdentifiers'] ?? []) as $id) {
        if (($id['type'] ?? '') === 'ISBN_13') {
            $ident = isbn_normalizuj((string) ($id['identifier'] ?? ''));
            break;
        }
        if (($id['type'] ?? '') === 'ISBN_10') {
            $ident = isbn_normalizuj((string) ($id['identifier'] ?? ''));
        }
    }

    $rok = null;
    $pd = (string) ($vi['publishedDate'] ?? '');
    if (preg_match('/^(\d{4})/', $pd, $m) === 1) {
        $rok = $m[1];
    }

    $okladka = $vi['imageLinks']['thumbnail']
        ?? $vi['imageLinks']['smallThumbnail']
        ?? null;
    if (is_string($okladka)) {
        $okladka = str_replace('http://', 'https://', $okladka);
        $okladka = (string) preg_replace('/(&|\?)zoom=\d+/', '', $okladka);
    }

    $opis = isset($vi['description']) && is_string($vi['description'])
        ? trim(html_entity_decode(strip_tags($vi['description']), ENT_QUOTES | ENT_HTML5))
        : null;

    return [
        'tytul'            => niepuste($vi['title'] ?? null),
        'podtytul'         => niepuste($vi['subtitle'] ?? null),
        'autor'            => niepuste(isset($vi['authors']) && is_array($vi['authors'])
                                  ? implode(', ', array_filter($vi['authors'], 'is_string')) : null),
        'isbn'             => niepuste($ident ?? ($isbn !== '' ? isbn_normalizuj($isbn) : null)),
        'rok'              => niepuste($rok),
        'wydawca'          => niepuste($vi['publisher'] ?? null),
        'liczba_stron'     => isset($vi['pageCount']) && (int) $vi['pageCount'] > 0
                                  ? (int) $vi['pageCount'] : null,
        'opis'             => niepuste($opis),
        'jezyk'            => niepuste($vi['language'] ?? null),
        'oryginalny_tytul' => niepuste($vi['originalTitle'] ?? null),
        'okladka_url'      => niepuste($okladka),
    ];
}

/**
 * Open Library (search.json, dokument z „docs”) → pola wspólne.
 * @param array $d    pierwszy dokument wyniku search.json
 * @param string $isbn ISBN użyty do zapytania (fallback)
 */
function ol_na_wspolne(array $d, string $isbn = ''): array
{
    $autorzy = $d['author_name'] ?? null;
    $wydawcy = $d['publisher'] ?? null;
    $jezyki  = $d['language'] ?? null;

    $okladka = null;
    if (isset($d['cover_i']) && (int) $d['cover_i'] > 0) {
        $okladka = 'https://covers.openlibrary.org/b/id/' . (int) $d['cover_i'] . '-L.jpg';
    }

    return [
        'tytul'            => niepuste($d['title'] ?? null),
        'podtytul'         => niepuste($d['subtitle'] ?? null),
        'autor'            => niepuste(is_array($autorzy)
                              ? implode(', ', array_filter($autorzy, 'is_string')) : null),
        'isbn'             => niepuste($isbn !== '' ? isbn_normalizuj($isbn) : null),
        'rok'              => niepuste($d['first_publish_year'] ?? null),
        'wydawca'          => niepuste(is_array($wydawcy) && $wydawcy !== []
                              ? (string) reset($wydawcy) : null),
        'liczba_stron'     => isset($d['number_of_pages_median'])
                              && (int) $d['number_of_pages_median'] > 0
                                  ? (int) $d['number_of_pages_median'] : null,
        'opis'             => null,   // search.json nie zwraca opisów
        'jezyk'            => niepuste(is_array($jezyki) && $jezyki !== []
                              ? (string) reset($jezyki) : null),
        'oryginalny_tytul' => niepuste($d['original_title'] ?? null),
        'okladka_url'      => niepuste($okladka),
    ];
}

/* ============================================ Biblioteka Narodowa (BN) */

/** Rekordy danego tagu MARC z odpowiedzi BN (pole „marc”). */
function bn_pola(array $marc, string $tag): array
{
    $out = [];
    foreach (($marc['fields'] ?? []) as $f) {
        if (is_array($f) && isset($f[$tag]) && is_array($f[$tag])) {
            $out[] = $f[$tag];
        }
    }
    return $out;
}

/** Pierwsza niepusta wartość podpola w rekordzie MARC albo null. */
function bn_pole(array $rekord, string $podpole): ?string
{
    foreach (($rekord['subfields'] ?? []) as $sf) {
        if (is_array($sf) && isset($sf[$podpole])) {
            $s = trim((string) $sf[$podpole]);
            if ($s !== '') {
                return $s;
            }
        }
    }
    return null;
}

/** Tytuł z BN bez końcowych „/”, „:”, „,” (245 bywa „Tytuł : podtytuł /”). */
function bn_czysc_tytul(?string $t): ?string
{
    if ($t === null) {
        return null;
    }
    $t = rtrim(trim($t), " /:;,");
    return $t === '' ? null : $t;
}

/** „Wydawnictwo Literackie,” → „Wydawnictwo Literackie”. */
function bn_czysc_wydawce(?string $w): ?string
{
    if ($w === null) {
        return null;
    }
    $w = rtrim(trim($w), " /;,");
    return $w === '' ? null : $w;
}

/** „Woolf, Virginia” → „Virginia Woolf” (tylko przy jednym przecinku —
 *  przy więcej zostawiamy jak jest, żeby nie psuć złożonych nazwisk). */
function bn_naturalnie(string $a): string
{
    if (substr_count($a, ',') !== 1) {
        return $a;
    }
    [$nazwisko, $imie] = array_map('trim', explode(',', $a, 2));
    return $imie !== '' ? $imie . ' ' . $nazwisko : $a;
}

/**
 * Biblioteka Narodowa (data.bn.org.pl, rekord z polem „marc”) → pola wspólne.
 * BN nie ma okładek ani opisów (520 zdarza się rzadko) — te pola zostają
 * null i uzupełniają Google / Open Library.
 *
 * @param array  $b     rekord z API BN (bibs[0])
 * @param string $isbn  ISBN użyty do zapytania (fallback: 020|a)
 */
function bn_na_wspolne(array $b, string $isbn = ''): array
{
    $marc = isset($b['marc']) && is_array($b['marc']) ? $b['marc'] : [];

    // Tytuł 245|a / podtytuł 245|b
    $tytul = null;
    $podtytul = null;
    $r245 = bn_pola($marc, '245')[0] ?? null;
    if ($r245 !== null) {
        $tytul = bn_czysc_tytul(bn_pole($r245, 'a'));
        $podtytul = bn_czysc_tytul(bn_pole($r245, 'b'));
    }
    if ($tytul === null) {
        // fallback bez MARC: płaskie pole bywa złożone („Tytuł / Oryginał”)
        $flat = niepuste($b['title'] ?? null);
        if ($flat !== null) {
            $flat = explode(' / ', $flat)[0];
        }
        $tytul = bn_czysc_tytul($flat);
    }

    // Autorzy: 100 + 700, ale tylko role autorstwa (bez tłumaczy/redaktorów)
    $autorzy = [];
    foreach (bn_pola($marc, '100') as $r) {
        $a = niepuste(bn_pole($r, 'a'));
        if ($a !== null) {
            $autorzy[] = bn_naturalnie($a);
        }
    }
    foreach (bn_pola($marc, '700') as $r) {
        $rola = niepuste(bn_pole($r, 'e'));
        if ($rola !== null && preg_match('/autor/i', $rola) !== 1) {
            continue;   // Tłumaczenie, Redakcja, Fot., Wydawca…
        }
        $a = niepuste(bn_pole($r, 'a'));
        if ($a !== null) {
            $autorzy[] = bn_naturalnie($a);
        }
    }
    $autorzy = array_values(array_unique($autorzy));

    // Rok wydania: płaskie publicationYear, w zapasie 260|c
    $rok = niepuste($b['publicationYear'] ?? null);
    $rok = ($rok !== null && preg_match('/\d{4}/', $rok, $m) === 1) ? $m[0] : null;
    if ($rok === null) {
        foreach (bn_pola($marc, '260') as $r) {
            $c = niepuste(bn_pole($r, 'c'));
            if ($c !== null && preg_match('/\d{4}/', $c, $m) === 1) {
                $rok = $m[0];
                break;
            }
        }
    }

    // Wydawca 260|b (płaskie pole BN bywa zdublowane — MARC pierwszy)
    $wydawca = null;
    foreach (bn_pola($marc, '260') as $r) {
        $wydawca = bn_czysc_wydawce(bn_pole($r, 'b'));
        if ($wydawca !== null) {
            break;
        }
    }
    if ($wydawca === null) {
        $wydawca = bn_czysc_wydawce(niepuste($b['publisher'] ?? null));
    }

    // Liczba stron z 300|a. Ten sam parser co dla nowego katalogu BN — samo
    // „pierwsza liczba” dawało 2 zamiast 615 przy „2 t. w 1 wol. (615 s.)”.
    // Tylko dla druku: przy audiobooku 300|a opisuje nośnik („1 płyta audio
    // (CD) : 13 godz.”), a my katalogujemy fizyczny egzemplarz.
    $liczba = null;
    $jestDrukiem = bib_kind_ranga(is_string($b['kind'] ?? null) ? $b['kind'] : null) === 0;
    if ($jestDrukiem) {
        foreach (bn_pola($marc, '300') as $r) {
            $liczba = bib_strony_z_formatu(bn_pole($r, 'a'));
            if ($liczba !== null) {
                break;
            }
        }
    }

    // Opis: 520|a (streszczenie) — BN prawie nie ma, zwykle null → Google
    $opis = null;
    foreach (bn_pola($marc, '520') as $r) {
        $opis = niepuste(bn_pole($r, 'a'));
        if ($opis !== null) {
            break;
        }
    }

    // Tytuł oryginału: 246 z podpolem i „… oryg …”
    $oryg = null;
    foreach (bn_pola($marc, '246') as $r) {
        $i = niepuste(bn_pole($r, 'i'));
        if ($i !== null && preg_match('/oryg/i', $i) === 1) {
            $o = niepuste(bn_pole($r, 'a'));
            if ($o !== null) {
                $oryg = bn_czysc_tytul($o);
                break;
            }
        }
    }

    // ISBN: zapytanie, w zapasie pierwszy 020|a (musi przejść sumę kontrolną)
    $isbnWynik = $isbn !== '' ? isbn_normalizuj($isbn) : null;
    if ($isbnWynik === null || !isbn_poprawny($isbnWynik)) {
        $isbnWynik = null;
        foreach (bn_pola($marc, '020') as $r) {
            $a = niepuste(bn_pole($r, 'a'));
            if ($a !== null) {
                $kandydat = isbn_normalizuj($a);
                if (isbn_poprawny($kandydat)) {
                    $isbnWynik = $kandydat;
                    break;
                }
            }
        }
    }

    return [
        'tytul'            => $tytul,
        'podtytul'         => $podtytul,
        'autor'            => $autorzy !== [] ? implode(', ', $autorzy) : null,
        'isbn'             => $isbnWynik,
        'rok'              => $rok,
        'wydawca'          => $wydawca,
        'liczba_stron'     => $liczba,
        'opis'             => $opis,
        'jezyk'            => niepuste($b['language'] ?? null),
        'oryginalny_tytul' => $oryg,
        'okladka_url'      => null,   // BN nie udostępnia okładek
    ];
}

/* =============================================== BN — nowy katalog (Primo) */

/**
 * Ranga rodzaju materiału przy wyborze rekordu. Szukamy fizycznego egzemplarza
 * (papier), więc książka wygrywa z e-bookiem i audiobookiem; „null" (rekord
 * bez rodzaju) traktujemy jak książkę, bo część opisów jest niepełna.
 *
 * Słowa kluczowe są po polsku w strefie /networks/ i po angielsku w /institutions/
 * oraz w odpowiedzi Primo — dlatego sprawdzamy oba.
 */
function bib_kind_ranga(?string $kind): int
{
    $k = mb_strtolower((string) $kind);
    if ($k === '') {
        return 0;
    }
    // Kolejność ma znaczenie: „e-book” i „audiobook” zawierają w sobie
    // słowo „book”, więc formaty trzeba rozpoznać PRZED drukiem.
    if (str_contains($k, 'e-book') || str_contains($k, 'e_book') || str_contains($k, 'elektronic')) {
        return 1;
    }
    if (str_contains($k, 'audio') || str_contains($k, 'nagran')) {
        return 3;
    }
    if (str_contains($k, 'książ') || str_contains($k, 'book') || str_contains($k, 'monograf')) {
        return 0;
    }
    return 2;   // czasopismo, mapa, nuty, film…
}

/** Rekord o najlepszym rodzaju materiału z listy (null gdy pusta). */
function bib_najlepszy_rekord(array $rekordy): ?array
{
    $najlepszy = null;
    $ranga = PHP_INT_MAX;
    foreach ($rekordy as $r) {
        if (!is_array($r)) {
            continue;
        }
        $rk = bib_kind_ranga(is_string($r['kind'] ?? null) ? $r['kind'] : null);
        if ($rk < $ranga) {
            $ranga = $rk;
            $najlepszy = $r;
        }
    }
    return $najlepszy;
}

/**
 * Jak wyżej, ale dla odpowiedzi Primo. Rekord opisany jest w pnx.display,
 * a pnx.sort ma wyłączoną formę pól („Lalka /” zamiast „Lalka / Bolesław Prus.”)
 * oraz czysty rok — doklejamy go pod kluczem „sort”.
 */
function bib_najlepszy_rekord_primo(array $dokumenty): ?array
{
    $najlepszy = null;
    $ranga = PHP_INT_MAX;
    foreach ($dokumenty as $doc) {
        $pnx = is_array($doc['pnx'] ?? null) ? $doc['pnx'] : null;
        if ($pnx === null || !is_array($pnx['display'] ?? null)) {
            continue;
        }
        $display = $pnx['display'];
        $rk = bib_kind_ranga(is_string($display['type'][0] ?? null) ? $display['type'][0] : null);
        if ($rk < $ranga) {
            $ranga = $rk;
            $najlepszy = $display;
            if (is_array($pnx['sort'] ?? null)) {
                $najlepszy['sort'] = $pnx['sort'];
            }
        }
    }
    return $najlepszy;
}

/** Kod języka z katalogu („pol" / „eng") → pełna nazwa jak w polskim BN. */
function bib_jezyk(string $kod): ?string
{
    $map = [
        'pol' => 'polski', 'polski' => 'polski',
        'eng' => 'angielski', 'ang' => 'angielski',
        'fre' => 'francuski', 'fra' => 'francuski',
        'ger' => 'niemiecki', 'deu' => 'niemiecki',
        'rus' => 'rosyjski', 'spa' => 'hiszpanski', 'ita' => 'wloski',
    ];
    $k = mb_strtolower(trim($kod));
    return $map[$k] ?? (niepuste($kod));
}

/**
 * Liczba stron z opisu wydawniczego („208, [4] s. ; 20 cm." → 208,
 * „2 t. w 1 wol. (615 s.)" → 615). Wymiary i liczby porządkowe (numery
 * arabskie w nawiasie kwadratowym, „t.", „wol.") odpadają.
 */
function bib_strony_z_formatu(?string $format): ?int
{
    if ($format === null) {
        return null;
    }
    $s = preg_replace('/\[[^\]]*\]/u', '', $format);   // numery arabskie przed rokiem
    $s = preg_split('/\d+\s*cm\b/u', (string) $s)[0];  // wymiary od „cm”
    if (preg_match('/(\d+)\s*,?\s*(?:stron|str\.?|s\.)/u', (string) $s, $m) === 1) {
        $n = (int) $m[1];
        return ($n >= 1 && $n <= 99999) ? $n : null;
    }
    return null;
}

/** „Kraków : Wydaw. Literackie, (Krak. : DNar.).” → „Wydawnictwo Literackie”. */
function bib_czysc_wydawce_prix(string $w): ?string
{
    $w = preg_replace('/\([^()]*\)/u', ' ', $w);              // dopisek z drukarni
    $w = (string) preg_replace('/^\s*[^:]*?:/u', '', $w);    // „Kraków : …" → „…"
    $w = preg_replace('/\s*:\s*/u', ' ', (string) $w);        // „Wydaw. : X” → „Wydaw. X”
    $w = (string) preg_replace('/\s+/u', ' ', $w);
    $w = rtrim(trim($w), " \t\n\r\0\x0B/,;:-.");             // „Wydaw. Literackie, ." → „Wydaw. Literackie”
    $w = trim($w);
    return $w === '' ? null : $w;
}

/** Role katalogowe doklejane po nazwisku w display.creator BN. */
const BIB_ROLE_KREJONE = [
    'autor', 'aut.', 'tłum.', 'tlum.', 'tłumacz', 'red.', 'redaktor',
    'przekł.', 'przekl.', 'współautor', 'wspolautor', 'ilustr.',
];

/**
 * „Lem, Stanisław (1921-2006)” → „Stanisław Lem”.
 * BN dokleja po datach rolę („Sapkowski, Andrzej (1948- ) Autor”), więc
 * ostatnie słowa-rolu odcinamy.
 */
function bib_autor_z_displayu(string $a): string
{
    $a = trim((string) preg_replace('/\([^()]*\)/u', ' ', $a));   // daty życia
    $a = trim((string) preg_replace('/\s+/u', ' ', $a));
    $a = trim((string) preg_replace(
        '/\s+(?:' . implode('|', array_map('preg_quote', BIB_ROLE_KREJONE)) . ')$/iu',
        '',
        $a
    ));
    return bn_naturalnie($a);
}

/**
 * Nowy katalog Biblioteki Narodowej (Ex Libris Primo, katalogi.bn.org.pl) →
 * pola wspólne. Rekord jest już opisany hasłowo, więc nie ma tu MARC-a —
 * liczba stron siedzi w „format", a czysty wydawca w „lds30".
 *
 * @param array  $rekord  pnx.display z odpowiedzi Primo
 * @param string $isbn    ISBN użyty do zapytania (fallback dla brakujących pól)
 */
function bn_primo_na_wspolne(array $rekord, string $isbn = ''): array
{
    // Tytuł: sort.title jest czystszy („Lalka /”), display.title bywa z dopiskiem
    // („Lalka / Bolesław Prus.”). Podtytuł po „: ” zostawiamy osobno.
    $suro = $rekord['sort']['title'][0] ?? ($rekord['title'][0] ?? null);
    $suro = is_string($suro) ? bn_czysc_tytul($suro) : null;
    if ($suro === null) {
        $suro = bn_czysc_tytul(niepuste($rekord['title'][0] ?? null));
    }
    $tytul = $suro;
    $podtytul = null;
    if ($suro !== null && str_contains($suro, ' : ')) {
        [$t, $p] = array_map('trim', explode(' : ', $suro, 2));
        if (bn_czysc_tytul($t) !== null) {
            $tytul = bn_czysc_tytul($t);
            $podtytul = bn_czysc_tytul($p);
        }
    }

    // Autor: display.creator bywa „Lem, Stanisław (1921-2006)$$QLem, Stanisław…”
    $autor = null;
    foreach ((array) ($rekord['creator'] ?? []) as $c) {
        if (!is_string($c) || $c === '') {
            continue;
        }
        $c = explode('$$', $c)[0];              // odciąć wyświetlaną formę $$
        $autor = bib_autor_z_displayu($c);
        break;
    }

    // Wydawca: lds30 to nazwa bez miasta, display.publisher z „Kraków : …”
    $wydawca = bib_czysc_wydawce_prix((string) ($rekord['lds30'][0] ?? ''))
        ?? bib_czysc_wydawce_prix((string) ($rekord['publisher'][0] ?? ''));

    // Rok: creationdate bywa „copyri” (copyright) albo „[2007]”
    $rok = null;
    foreach ([$rekord['sort']['creationdate'][0] ?? null, $rekord['creationdate'][0] ?? null] as $r) {
        if (is_string($r) && preg_match('/(\d{4})/', $r, $m) === 1) {
            $rok = $m[1];
            break;
        }
    }

    // Język: „pol” / „polski”
    $jezyk = null;
    $kodJ = $rekord['language'][0] ?? null;
    if (is_string($kodJ)) {
        $jezyk = bib_jezyk($kodJ);
    }

    // Opis: lds31 to uwagi katalogowe („Wyd. wg PIW, 1953.”), nie streszczenie
    $opis = null;

    $isbnWynik = $isbn !== '' && isbn_poprawny($isbn) ? isbn_normalizuj($isbn) : null;

    return [
        'tytul'            => $tytul,
        'podtytul'         => $podtytul,
        'autor'            => $autor,
        'isbn'             => $isbnWynik,
        'rok'              => $rok,
        'wydawca'          => $wydawca,
        'liczba_stron'     => bib_kind_ranga(is_string($rekord['type'][0] ?? null) ? $rekord['type'][0] : null) === 0
                                ? bib_strony_z_formatu(
                                    is_string($rekord['format'][0] ?? null) ? $rekord['format'][0] : null)
                                : null,
        'opis'             => $opis,
        'jezyk'            => $jezyk,
        'oryginalny_tytul' => null,
        'okladka_url'      => null,   // katalog BN nie udostępnia okładek
    ];
}

/** Wartość z API → string albo null (żeby „" i puste tablice nie udawały danych). */
function niepuste(mixed $v): ?string
{
    if ($v === null || is_array($v)) {
        return null;
    }
    $s = trim((string) $v);
    return $s === '' ? null : $s;
}

/* ================================================================ merge */

/**
 * Scalanie metryczki: kolejne źródła uzupełniają tylko puste pola.
 * Kolejność wywołania = priorytet: BN → Google → Open Library.
 * Zwraca null, gdy żadne źródło nie trafiło.
 */
function ksiazka_scal(?array ...$zrodla): ?array
{
    $aktywne = array_values(array_filter($zrodla, static fn (?array $z) => $z !== null));
    if ($aktywne === []) {
        return null;
    }

    $out = [];
    foreach (KSIAZKA_POLA as $pole) {
        $wartosc = null;
        foreach ($aktywne as $z) {
            $w = $z[$pole] ?? null;
            if ($w !== null && $w !== '') {
                $wartosc = $w;
                break;
            }
        }
        $out[$pole] = ($wartosc === null || $wartosc === '') ? null : $wartosc;
    }
    return $out;
}

/* =========================================================== walidacja */

/**
 * Walidacja rekordu przed zapisem. Zwraca ['dane' => wyczyszczone, 'bledy' => [komunikaty]].
 * Nie woła json_fail — ścieżkę błędu obsługuje endpoint (json_fail kończy proces).
 */
function ksiazka_waliduj(array $in): array
{
    $statusy = defined('STATUSY') ? STATUSY : ['chce_przeczytac', 'w_trakcie', 'przeczytane'];
    $bledy = [];

    $tytul = trim((string) ($in['tytul'] ?? ''));
    if ($tytul === '') {
        $bledy[] = 'Tytuł jest wymagany.';
    } elseif (mb_strlen($tytul) > 500) {
        $bledy[] = 'Tytuł jest za długi (max 500 znaków).';
    }

    $autor = trim((string) ($in['autor'] ?? ''));
    if (mb_strlen($autor) > 300) {
        $bledy[] = 'Autor jest za długi (max 300 znaków).';
    }

    $isbn = niepuste($in['isbn'] ?? null);
    if ($isbn !== null) {
        $isbn = isbn_normalizuj($isbn);
        if (!isbn_poprawny($isbn)) {
            $bledy[] = 'Nieprawidłowy ISBN (zła suma kontrolna).';
        }
    }

    $rok = niepuste($in['rok'] ?? null);
    if ($rok !== null && preg_match('/^\d{4}$/', $rok) !== 1) {
        $bledy[] = 'Rok wydania musi mieć format RRRR.';
    }

    $strony = niepuste($in['liczba_stron'] ?? null);
    if ($strony !== null) {
        if (preg_match('/^\d+$/', $strony) !== 1 || (int) $strony < 1 || (int) $strony > 99999) {
            $bledy[] = 'Liczba stron musi być liczbą od 1 do 99999.';
        } else {
            $strony = (int) $strony;
        }
    }

    // Ocena własna 1–5; pusta = nie oceniona (NULL). Sortowanie po niej
    // korzysta z kolumny, więc biała lista sortowania ma 'ocena' dopiero
    // od teraz (kolumna dodana w migracji 0.11.0).
    // UI wysyła 0 dla „brak oceny" — niepuste() traktuje "0" jako niepusty
    // string, więc 0 obsługujemy ręcznie (inaczej walidacja by je odrzuciła).
    $ocenaSurowa = $in['ocena'] ?? null;
    $ocena = niepuste($ocenaSurowa);
    if ($ocena === '0') {
        $ocena = null;
    }
    if ($ocena !== null) {
        if (preg_match('/^\d+$/', $ocena) !== 1) {
            $bledy[] = 'Ocena musi być liczbą od 1 do 5.';
            $ocena = null;
        } else {
            $ocena = (int) $ocena;
            if ($ocena < 1 || $ocena > 5) {
                $bledy[] = 'Ocena musi być liczbą od 1 do 5.';
                $ocena = null;
            }
        }
    }

    // Metryczka egzemplarza: format i szerokość oprawy (nie wartości książki —
    // ten sam tytuł ma wydania twarde i miękkie, a każdy to osobny egzemplarz)
    $formaty = ['twarda', 'miekka'];
    $format = niepuste($in['format'] ?? null);
    if ($format !== null) {
        $format = slug_polska($format);
        if (!in_array($format, $formaty, true)) {
            $bledy[] = 'Nieznany format egzemplarza.';
            $format = null;
        }
    }

    $szerokosci = ['waski', 'standard', 'szeroki'];
    $szerokosc = niepuste($in['szerokosc'] ?? null);
    if ($szerokosc !== null) {
        $szerokosc = slug_polska($szerokosc);
        if (!in_array($szerokosc, $szerokosci, true)) {
            $bledy[] = 'Nieznana szerokość oprawy.';
            $szerokosc = null;
        }
    }

    $status = trim((string) ($in['status'] ?? 'chce_przeczytac'));
    if (!in_array($status, $statusy, true)) {
        $bledy[] = 'Nieznany status czytania.';
    }

    // zrodlo tylko z białej listy (pole kontrolne, nie wolny tekst użytkownika).
    // Lista generowana z tokenów, bo po dodaniu katalogu BN (bnk) ręczna
    // enumeracja kombinacji łatwo przegapiłaaby jedną kombinację.
    $zrodlo = niepuste($in['zrodlo'] ?? null) ?? 'recznie';
    if (!in_array($zrodlo, bib_zrodla_dozwolone(), true)) {
        $zrodlo = 'recznie';
    }

    $poleTekstowe = [
        'podtytul' => 500, 'wydawca' => 200, 'jezyk' => 20,
        'oryginalny_tytul' => 500, 'opis' => 20000, 'notatki' => 10000,
    ];
    $dane = [
        'tytul' => $tytul,
        'autor' => $autor,
        'isbn'  => $isbn,
        'rok'   => $rok,
        'liczba_stron' => $strony,
        'format' => $format,
        'szerokosc' => $szerokosc,
        'ocena' => $ocena,
        'status' => $status,
        'zrodlo' => $zrodlo,
    ];
    foreach ($poleTekstowe as $p => $max) {
        $v = niepuste($in[$p] ?? null);
        if ($v !== null && mb_strlen($v) > $max) {
            $bledy[] = sprintf('Pole „%s” jest za długie (max %d znaków).', $p, $max);
        }
        $dane[$p] = $v;
    }

    // Ścieżki plików: tylko „uploads/.../<losowa-nazwa>.jpg" albo sama nazwa
    // pliku — cokolwiek innego (path traversal, URL-e) odrzucamy.
    foreach (['okladka', 'zdjecie'] as $p) {
        $v = niepuste($in[$p] ?? null);
        if ($v !== null
            && preg_match('#^(?:[a-z0-9_]+/)*[a-f0-9]{32}\.(?:jpg|png|webp)$#', $v) !== 1) {
            $bledy[] = 'Nieprawidłowa ścieżka pliku.';
            $v = null;
        }
        $dane[$p] = $v;
    }

    return ['dane' => $dane, 'bledy' => $bledy];
}

/* ======================================================== tagi i półki */

/** Limity tagów i półek (jedna książka / cały katalog). */
const TAG_MAX_DLUGOSC = 40;
const TAG_MAX_KSIAZEK = 12;
const POLKA_MAX_DLUGOSC = 60;
const POLKA_MAX_OPIS = 200;
const IMPORT_MAX_REKORDY = 5000;   // limit jednego pliku (ochrona serwera)

/** Format eksportu rozpoznawany przez import. */
const IMPORT_FORMAT = 'biblioteka-eksport/v1';

/**
 * Rozpoznanie pliku eksportu. Zwraca ['ok' => bool, 'blad' => ?string,
 * 'ksiazki' => [], 'wypozyczenia' => [], 'slowowniki' => []].
 * Import przyjmuje TYLKO własny eksport (decyzja: bez parsera pod cudzy plik),
 * więc sprawdzamy znacznik formatu — cudzy JSON dostanie czytelny komunikat.
 */
function import_rozpoznaj(mixed $dane): array
{
    if (!is_array($dane)) {
        return ['ok' => false, 'blad' => 'Plik nie zawiera poprawnego JSON-a.'];
    }
    if (!isset($dane['format']) || (string) $dane['format'] !== IMPORT_FORMAT) {
        return ['ok' => false, 'blad' => sprintf(
            'Nieznany format pliku. Oczekiwany eksport Biblioteki (%s), a w pliku jest: %s.',
            IMPORT_FORMAT,
            isset($dane['format']) ? (string) $dane['format'] : 'brak'
        )];
    }
    if (!isset($dane['ksiazki']) || !is_array($dane['ksiazki'])) {
        return ['ok' => false, 'blad' => 'Eksport nie zawiera listy książek.'];
    }
    if (count($dane['ksiazki']) > IMPORT_MAX_REKORDY) {
        return ['ok' => false, 'blad' => sprintf(
            'Plik ma %d rekordów — maksimum w jednym imporcie to %d.',
            count($dane['ksiazki']),
            IMPORT_MAX_REKORDY
        )];
    }

    return [
        'ok'          => true,
        'blad'        => null,
        'wersja'      => (string) ($dane['wersja'] ?? ''),
        'data'        => (string) ($dane['data'] ?? ''),
        'ksiazki'     => array_values(array_filter($dane['ksiazki'], 'is_array')),
        'wypozyczenia' => isset($dane['wypozyczenia']) && is_array($dane['wypozyczenia'])
            ? array_values(array_filter($dane['wypozyczenia'], 'is_array'))
            : [],
        'slowowniki'  => isset($dane['slowowniki']) && is_array($dane['slowowniki'])
            ? $dane['slowowniki'] : [],
    ];
}

/**
 * Klucz dopasowania egzemplarza przy imporcie: ISBN jeśli jest, inaczej tytuł
 * + rok. Ten sam algorytm musi działać w eksporcie („do czego dopasować
 * wypożyczenia") i przy scalaniu z istniejącym katalogiem.
 */
function import_klucz(array $ksiazka): string
{
    $isbn = isbn_normalizuj((string) ($ksiazka['isbn'] ?? ''));
    if ($isbn !== '') {
        return 'isbn:' . $isbn;
    }
    $tytul = mb_strtolower(trim(preg_replace('/\s+/u', ' ', (string) ($ksiazka['tytul'] ?? '')) ?? ''));
    $rok = trim((string) ($ksiazka['rok'] ?? ''));
    return 'tytul:' . $tytul . '|' . $rok;
}

/** Wypożyczenia z eksportu → klucz egzemplarza (to samo co import_klucz). */
function import_wypozyczenia_mapa(array $wypozyczenia): array
{
    $mapa = [];
    foreach ($wypozyczenia as $w) {
        if (!is_array($w)) {
            continue;
        }
        $klucz = import_klucz($w);
        $mapa[$klucz][] = $w;
    }
    return $mapa;
}

/**
 * Nazwy półek z eksportu → lista nazw (eksport trzyma nazwy, nie identyfikatory —
 * identyfikatory nie są przenośne między instalacjami).
 */
function polki_nazwy_normalizuj(mixed $wejscie): array
{
    if ($wejscie === null || $wejscie === '') {
        return [];
    }
    if (is_string($wejscie)) {
        $wejscie = explode(',', $wejscie);
    }
    if (!is_array($wejscie)) {
        return [];
    }
    $out = [];
    foreach ($wejscie as $v) {
        if (is_array($v)) {
            // rekord z list.php: ['id' => …, 'nazwa' => …]
            $v = $v['nazwa'] ?? null;
        }
        if (!is_scalar($v)) {
            continue;
        }
        $nazwa = trim(preg_replace('/\s+/u', ' ', (string) $v) ?? '');
        if ($nazwa === '' || mb_strlen($nazwa) > POLKA_MAX_DLUGOSC) {
            continue;
        }
        $klucz = mb_strtolower($nazwa);
        if (!isset($out[$klucz])) {
            $out[$klucz] = $nazwa;   // wygrywa pierwsza pisownia (jak w tagach)
        }
    }
    return array_values($out);
}

/**
 * Książka z eksportu → rekord gotowy do walidacji przez ksiazka_waliduj().
 * Przenosimy tylko pola, które znamy (obce pola z pliku pomijamy), a tagi,
 * półki i wypożyczenia odkładamy pod klucz dopasowania.
 */
function import_ksiazke_rozpakuj(array $rekord, array $wypPoKluczu): array
{
    $wejscie = [];
    foreach (KSIAZKA_POLA as $pole) {
        // KSIAZKA_POLA zawiera okladka_url, którego w rekordzie nie ma —
        // ścieżkę pliku bierzemy z pola okladka
        if ($pole === 'okladka_url') {
            continue;
        }
        if (array_key_exists($pole, $rekord)) {
            $wejscie[$pole] = $rekord[$pole];
        }
    }
    foreach (['okladka', 'zdjecie', 'status', 'notatki', 'zrodlo',
              'format', 'szerokosc', 'ocena'] as $pole) {
        if (array_key_exists($pole, $rekord)) {
            $wejscie[$pole] = $rekord[$pole];
        }
    }

    return [
        'wejscie'   => $wejscie,
        'tagi'      => tagi_normalizuj($rekord['tagi'] ?? null)['tagi'],
        'polki'     => polki_nazwy_normalizuj($rekord['polki'] ?? null),
        'wypozyczenia' => $wypPoKluczu[import_klucz($rekord)] ?? [],
    ];
}

/**
 * Normalizacja listy tagów z wejścia: obcięcie, złożenie spacji, usunięcie
 * pustych, duplikatów (bez rozróżniania wielkości liter) i limitów.
 * Zwraca ['tagi' => string[], 'bledy' => string[]].
 */
function tagi_normalizuj(mixed $wejscie): array
{
    $bledy = [];
    if ($wejscie === null || $wejscie === '') {
        return ['tagi' => [], 'bledy' => $bledy];
    }
    if (is_string($wejscie)) {
        // Formularz: tagi po przecinku
        $wejscie = explode(',', $wejscie);
    }
    if (!is_array($wejscie)) {
        return ['tagi' => [], 'bledy' => ['Nieprawidłowa lista tagów.']];
    }

    $tagi = [];
    foreach ($wejscie as $surowy) {
        if (!is_scalar($surowy)) {
            continue;
        }
        $nazwa = trim(preg_replace('/\s+/u', ' ', (string) $surowy) ?? '');
        if ($nazwa === '') {
            continue;
        }
        if (mb_strlen($nazwa) > TAG_MAX_DLUGOSC) {
            $bledy[] = sprintf('Tag „%s” jest za długi (max %d znaków).', mb_substr($nazwa, 0, 20) . '…', TAG_MAX_DLUGOSC);
            continue;
        }
        $klucz = mb_strtolower($nazwa);
        if (!isset($tagi[$klucz])) {
            $tagi[$klucz] = $nazwa;   // wygrywa pierwsza pisownia
        }
    }
    if (count($tagi) > TAG_MAX_KSIAZEK) {
        $bledy[] = sprintf('Maksymalnie %d tagów na książkę.', TAG_MAX_KSIAZEK);
        $tagi = array_slice($tagi, 0, TAG_MAX_KSIAZEK, true);
    }

    return ['tagi' => array_values($tagi), 'bledy' => $bledy];
}

/**
 * Tagi do zapisu w bazie: nazwy → [ ['nazwa' => …, 'klucz' => …], … ].
 * `klucz` to małe litery — dzięki niemu „SciFi” i „scifi” to jeden tag
 * (UNIQUE na (user_id, nazwa)). Deduplikacja po kluczu: bez niej ta sama
 * etykieta podana dwukrotnie (lub różną wielkością liter) trafiłaby do
 * powiązań dwa razy.
 */
function tagi_do_zapisu(array $tagi): array
{
    $out = [];
    foreach ($tagi as $nazwa) {
        $nazwa = trim((string) $nazwa);
        $klucz = mb_strtolower($nazwa);
        if ($nazwa === '' || isset($out[$klucz])) {
            continue;
        }
        $out[$klucz] = ['nazwa' => $nazwa, 'klucz' => $klucz];
    }
    return array_values($out);
}

/** Walidacja nazwy półki (pusta nazwa = błąd; długość = błąd). */
function polka_nazwa_waliduj(string $nazwa): array
{
    $nazwa = trim(preg_replace('/\s+/u', ' ', $nazwa) ?? '');
    if ($nazwa === '') {
        return ['nazwa' => null, 'blad' => 'Nazwa półki jest wymagana.'];
    }
    if (mb_strlen($nazwa) > POLKA_MAX_DLUGOSC) {
        return ['nazwa' => null, 'blad' => sprintf('Nazwa półki jest za długa (max %d znaków).', POLKA_MAX_DLUGOSC)];
    }
    return ['nazwa' => $nazwa, 'blad' => null];
}

/** Lista identyfikatorów (półki) z wejścia — tylko dodatnie inty, unikalne. */
function id_listy_normalizuj(mixed $wejscie): array
{
    if (is_string($wejscie)) {
        $wejscie = array_filter(explode(',', $wejscie), static fn ($v) => trim($v) !== '');
    }
    if (!is_array($wejscie)) {
        return [];
    }
    $out = [];
    foreach ($wejscie as $v) {
        $i = (int) $v;
        if ($i > 0 && !in_array($i, $out, true)) {
            $out[] = $i;
        }
    }
    return $out;
}

/* ============================================================ wypożyczenia */

/** Limity pól wypożyczenia. */
const WYPOZYCZENIE_OSOBA_MAX = 120;
const WYPOZYCZENIE_UWAGI_MAX = 500;

/**
 * RRRR-MM-DD (lub RRRR-MM-DD GG:MM) → pełny znacznik czasu, albo null.
 * Sprawdzamy też realność daty (regex przepuszcza „2026-13-45").
 */
function wypozyczenie_data(string $data): ?string
{
    $data = trim($data);
    if (preg_match('/^(\d{4})-(\d{2})-(\d{2})( \d{2}:\d{2}(:\d{2})?)?$/', $data, $m) !== 1) {
        return null;
    }
    if (checkdate((int) $m[2], (int) $m[3], (int) $m[1]) === false) {
        return null;
    }
    if (($m[4] ?? '') === '') {
        return $data . ' 00:00:00';
    }
    $czas = ltrim($m[4]);
    return strlen($czas) === 5 ? $data . ':00' : $data;
}

/**
 * Walidacja wypożyczenia. Zwraca ['dane' => …, 'bledy' => …].
 * Osoba jest wymagana (nie wypożyczamy „w powietrza"), pusta data zwrotu
 * oznacza „wciąż wypożyczone” (NULL w bazie).
 */
function wypozyczenie_waliduj(array $in): array
{
    $bledy = [];

    $osoba = trim(preg_replace('/\s+/u', ' ', (string) ($in['osoba'] ?? '')) ?? '');
    if ($osoba === '') {
        $bledy[] = 'Podaj, komu wypożyczasz książkę.';
    } elseif (mb_strlen($osoba) > WYPOZYCZENIE_OSOBA_MAX) {
        $bledy[] = sprintf('Nazwa jest za długa (max %d znaków).', WYPOZYCZENIE_OSOBA_MAX);
    }

    $uwagi = niepuste($in['uwagi'] ?? null);
    if ($uwagi !== null && mb_strlen($uwagi) > WYPOZYCZENIE_UWAGI_MAX) {
        $bledy[] = sprintf('Uwagi są za długie (max %d znaków).', WYPOZYCZENIE_UWAGI_MAX);
    }

    $dataWyp = wypozyczenie_data((string) ($in['data_wypozyczenia'] ?? ''));
    if ($dataWyp === null) {
        $dataWyp = date('Y-m-d H:i:s');
    }

    $dataZwrotu = null;
    $surowyZwrot = niepuste($in['data_zwrotu'] ?? null);
    if ($surowyZwrot !== null) {
        $dataZwrotu = wypozyczenie_data((string) $surowyZwrot);
        if ($dataZwrotu === null) {
            $bledy[] = 'Data zwrotu musi mieć format RRRR-MM-DD.';
        } elseif ($dataZwrotu < $dataWyp) {
            $bledy[] = 'Data zwrotu nie może być wcześniejsza niż data wypożyczenia.';
        }
    }

    return [
        'dane' => [
            'ksiazka_id'        => (int) ($in['ksiazka_id'] ?? 0),
            'osoba'             => $osoba,
            'data_wypozyczenia' => $dataWyp,
            'data_zwrotu'       => $dataZwrotu,
            'uwagi'             => $uwagi,
        ],
        'bledy' => $bledy,
    ];
}

/** Data do wyświetlenia: „2026-10-02 14:30:00” → „02.10.2026”. */
function wypozyczenie_data_skrot(string $data): string
{
    if (preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $data, $m) === 1) {
        return $m[3] . '.' . $m[2] . '.' . $m[1];
    }
    return $data;
}

/** Czytelny opis wypożyczenia (karta książki, lista, komunikaty). */
function wypozyczenie_opis(array $w): string
{
    $kto = trim((string) ($w['osoba'] ?? ''));
    $opis = $kto !== '' ? $kto : 'ktoś';
    $od = wypozyczenie_data((string) ($w['data_wypozyczenia'] ?? ''));
    if ($od !== null) {
        $opis .= ' (od ' . wypozyczenie_data_skrot($od) . ')';
    }
    return $opis;
}

/**
 * Wartość filtra „wypożyczenie”: 'tak' = tylko wypożyczone, 'nie' = tylko
 * te na regale, '' = bez filtra (filtrowanie po innej tabeli, nie po kolumnie).
 */
function wypozyczone_filtr(mixed $wartosc): string
{
    $v = mb_strtolower(trim((string) (is_scalar($wartosc) ? $wartosc : '')));
    return in_array($v, ['tak', 'nie'], true) ? $v : '';
}

/* ======================================================== filtry + sortowanie */

/** Wartość do CSV (delimiter „;" jak w polskim Excelu; cudzysłowy gdy trzeba). */
function csv_wartosc(mixed $v): string
{
    if ($v === null) {
        return '';
    }
    $s = (string) $v;
    if (preg_match('/[;"\n\r]/', $s) === 1) {
        $s = '"' . str_replace('"', '""', $s) . '"';
    }
    return $s;
}

/** Jedna linia CSV: wartości rozdzielone „;", escape przez cudzysłów. */
function csv_linia(array $wartosci): string
{
    return implode(';', array_map('csv_wartosc', $wartosci));
}

/** Dopuszczalne pola sortowania → kolumny SQL (biała lista — nie wpinamy wejścia w SQL). */
function lista_sortowania(): array
{
    return [
        'tytul'   => 'tytul',
        'autor'   => 'autor',
        'rok'     => 'rok',
        'strony'  => 'liczba_stron',
        'dodane'  => 'data_dodania',
        'edycji'  => 'data_edycji',
        'ocena'   => 'ocena',
    ];
}

/** Sortowanie po dacie wypożyczenia — specjalne, bo to inna tabela. */
const SORT_WYPOZYCZENIE = 'wypozyczone';

/**
 * Budowa warunków filtrowania + sortowania dla listy katalogu.
 * Zwraca ['warunki' => fragmenty SQL z ?, 'parametry' => [wartości], 'sortowanie' => "kolumna KIER"].
 * Wejście użytkownika trafia TYLKO do parametrów; nazwy kolumn z białej listy.
 *
 * Filtry: q (tytuł/autor/isbn/podtytuł/opis/notatki/wydawca), status, autor, rok,
 *         wydawca, jezyk, tag (nazwa), polka (id), dodane_od, dodane_do (ZZZZ-MM-DD).
 */
function lista_zapytanie(array $f, string $sort = 'tytul', string $kier = 'asc'): array
{
    $warunki = [];
    $parametry = [];

    $q = trim((string) ($f['q'] ?? ''));
    if ($q !== '') {
        $like = '%' . $q . '%';
        // Opis i notatki też przeszukiwane — często szukamy własnych notatek.
        $warunki[] = '(tytul LIKE ? OR podtytul LIKE ? OR autor LIKE ? OR isbn LIKE ?'
            . ' OR opis LIKE ? OR notatki LIKE ? OR wydawca LIKE ?)';
        array_push($parametry, $like, $like, $like, $like, $like, $like, $like);
    }

    foreach (['status' => '=', 'rok' => '=', 'jezyk' => '=',
             'format' => '=', 'szerokosc' => '='] as $pole => $op) {
        $v = niepuste($f[$pole] ?? null);
        if ($v !== null) {
            if ($pole === 'format' || $pole === 'szerokosc') {
                // słowniki trzymane są bez polskich znaków
                $v = slug_polska($v);
            }
            $warunki[] = sprintf('%s %s ?', $pole, $op);
            $parametry[] = $v;
        }
    }

    // Ocena: 'tak' = tylko ocenione, 'nie' = tylko nieocenione (NULL)
    $ocen = mb_strtolower(trim((string) ($f['ocenione'] ?? '')));
    if ($ocen === 'tak') {
        $warunki[] = 'ocena IS NOT NULL';
    } elseif ($ocen === 'nie') {
        $warunki[] = 'ocena IS NULL';
    }
    foreach (['autor' => 'autor', 'wydawca' => 'wydawca'] as $pole => $kolumna) {
        $v = niepuste($f[$pole] ?? null);
        if ($v !== null) {
            $warunki[] = $kolumna . ' LIKE ?';
            $parametry[] = '%' . $v . '%';
        }
    }

    // Tagi: rekord musi mieć WSZYSTKIE wskazane tagi (AND na EXISTS) —
    // wiele haseł po przecinku to zawężenie, nie rozszerzenie.
    $tagi = tagi_normalizuj($f['tag'] ?? null)['tagi'];
    foreach ($tagi as $tag) {
        $warunki[] = 'EXISTS (SELECT 1 FROM bib_ksiazka_tagi kt'
            . ' JOIN bib_tagi t ON t.id = kt.tag_id'
            . ' WHERE kt.ksiazka_id = bib_ksiazki.id AND t.nazwa = ?)';
        $parametry[] = mb_strtolower($tag);
    }

    // Półka: rekord musi należeć do wskazanej półki (jedna wystarczy).
    $polki = id_listy_normalizuj($f['polka'] ?? null);
    if ($polki !== []) {
        $w = [];
        foreach ($polki as $id) {
            $w[] = 'EXISTS (SELECT 1 FROM bib_ksiazka_polk kp'
                . ' WHERE kp.ksiazka_id = bib_ksiazki.id AND kp.polka_id = ?)';
            $parametry[] = $id;
        }
        $warunki[] = '(' . implode(' OR ', $w) . ')';
    }

    // Wypożyczenie: EXISTS na aktywnej pożyczce (data_zwrotu IS NULL)
    $wyp = wypozyczone_filtr($f['wypozyczone'] ?? null);
    if ($wyp === 'tak') {
        $warunki[] = 'EXISTS (SELECT 1 FROM bib_wypozyczenia wy'
            . ' WHERE wy.ksiazka_id = bib_ksiazki.id AND wy.data_zwrotu IS NULL)';
    } elseif ($wyp === 'nie') {
        $warunki[] = 'NOT EXISTS (SELECT 1 FROM bib_wypozyczenia wy'
            . ' WHERE wy.ksiazka_id = bib_ksiazki.id AND wy.data_zwrotu IS NULL)';
    }
    $od = niepuste($f['dodane_od'] ?? null);
    if ($od !== null && preg_match('/^\d{4}-\d{2}-\d{2}$/', $od) === 1) {
        $warunki[] = 'data_dodania >= ?';
        $parametry[] = $od . ' 00:00:00';
    }
    $do = niepuste($f['dodane_do'] ?? null);
    if ($do !== null && preg_match('/^\d{4}-\d{2}-\d{2}$/', $do) === 1) {
        $warunki[] = 'data_dodania <= ?';
        $parametry[] = $do . ' 23:59:59';
    }

    $sortMapa = lista_sortowania();
    $kolumna = $sortMapa[$sort] ?? $sortMapa['tytul'];
    $kierunki = ['asc', 'desc'];
    $kier = in_array(strtolower($kier), $kierunki, true) ? strtolower($kier) : 'asc';
    // Rok i liczba stron trzymane jako tekst — sortujemy cyfrowo
    $sortSql = ($kolumna === 'rok' || $kolumna === 'liczba_stron')
        ? sprintf('%s + 0 %s', $kolumna, strtoupper($kier))
        : sprintf('%s %s', $kolumna, strtoupper($kier));

    // Kolumny, które bywają NULL (ocena, data edycji), przy sortowaniu
    // „rosnąco" muszą iść NA KOŃCU — inaczej nieocenione lądowałyby na
    // początku listy (MySQL traktuje NULL jako najmniejszą wartość).
    $nullable = ['ocena', 'data_edycji'];
    if ($kier === 'asc' && in_array($kolumna, $nullable, true)) {
        $sortSql = sprintf('%s IS NULL ASC, %s', $kolumna, $sortSql);
    }

    return ['warunki' => $warunki, 'parametry' => $parametry, 'sortowanie' => $sortSql];
}

/* ============================================================== ebooki */

/**
 * Formaty plików katalogu ebooków. Wartości spoza listy trafiają do „inne” —
 * lepiej pokazać nieznany plik w katalogu niż go zgubić.
 */
const EBOOK_FORMATY = ['epub', 'pdf', 'mobi', 'azw3', 'fb2', 'cbz', 'inne'];

/** Źródła rekordu ebooka: ręcznie, import folderu, skan okładki. */
const EBOOK_ZRODLA = ['recznie', 'import', 'skan'];

/** Ile rekordów na stronę listy (spójne z limitem endpointu). */
const EBOOK_NA_STRONE = 24;

/** Hash pliku to SHA-256 — zawsze 64 znaki hex. */
const EBOOK_HASH_DLUGOSC = 64;

// Opis i notatki to TEXT, ale metadane EPUB-a potrafią mieć dziesiątki tysięcy
// znaków; dłuższy ogon tylko zaśmieca bazę i odpowiedzi API.
const EBOOK_OPIS_MAX = 20000;
const EBOOK_NOTATKI_MAX = 10000;

/**
 * Format pliku → klucz ze słownika. Import i systemy plików podają „.EPUB”,
 * „ PDF ” albo „Azw3”; wszystkie sprowadzamy do jednej postaci.
 */
function ebook_normalizuj_format(string $f): string
{
    $f = ltrim(mb_strtolower(trim($f)), '.');
    return in_array($f, EBOOK_FORMATY, true) ? $f : 'inne';
}

/**
 * Walidacja rekordu ebooka. Zwraca ['dane' => wyczyszczone, 'bledy' => [komunikaty]].
 * Długości pól wynikają z bib_ebooki. $slowniki pozwala podać własne listy
 * (np. statusy z konfiguracji) bez zależności od stałych globalnych.
 */
function ebook_waliduj(array $in, array $slowniki = []): array
{
    $statusy = $slowniki['statusy']
        ?? (defined('STATUSY') ? STATUSY : ['chce_przeczytac', 'w_trakcie', 'przeczytane']);
    $bledy = [];

    $tytul = trim((string) ($in['tytul'] ?? ''));
    if ($tytul === '') {
        $bledy[] = 'Tytuł jest wymagany.';
    } elseif (mb_strlen($tytul) > 500) {
        $bledy[] = 'Tytuł jest za długi (max 500 znaków).';
    }

    // Autor w bib_ebooki ma DEFAULT "", więc brak autora nie jest błędem.
    $autor = trim((string) ($in['autor'] ?? ''));
    if (mb_strlen($autor) > 300) {
        $bledy[] = 'Autor jest za długi (max 300 znaków).';
    }

    // ISBN z metadanych ebooka bywa niepełny lub zły — normalizujemy, ale nie
    // sprawdzamy sumy kontrolnej: odrzucenie po checksumie gubiłoby książki
    // przy imporcie, a ISBN nie jest tu kluczem katalogu.
    $isbn = niepuste($in['isbn'] ?? null);
    if ($isbn !== null) {
        $isbn = isbn_normalizuj($isbn);
        if ($isbn === '') {
            $isbn = null;
        } elseif (strlen($isbn) > 20) {
            $bledy[] = 'ISBN jest za długi (max 20 znaków).';
        }
    }

    // Rok trzymamy jako tekst (VARCHAR 10), bo dc:date bywa pełną datą.
    $rok = niepuste($in['rok'] ?? null);
    if ($rok !== null && mb_strlen($rok) > 10) {
        $bledy[] = 'Rok jest za długi (max 10 znaków).';
    }

    $strony = niepuste($in['liczba_stron'] ?? null);
    if ($strony !== null) {
        if (preg_match('/^\d+$/', $strony) !== 1 || (int) $strony < 1 || (int) $strony > 65535) {
            $bledy[] = 'Liczba stron musi być liczbą od 1 do 65535.';
        } else {
            $strony = (int) $strony;
        }
    }

    // Ocena 1–5; 0 i puste znaczą „nie oceniono” (NULL) — tak wysyła formularz.
    $ocena = niepuste($in['ocena'] ?? null);
    if ($ocena === '0') {
        $ocena = null;
    }
    if ($ocena !== null) {
        if (preg_match('/^\d+$/', $ocena) !== 1) {
            $bledy[] = 'Ocena musi być liczbą od 1 do 5.';
            $ocena = null;
        } else {
            $ocena = (int) $ocena;
            if ($ocena < 1 || $ocena > 5) {
                $bledy[] = 'Ocena musi być liczbą od 1 do 5.';
                $ocena = null;
            }
        }
    }

    $status = trim((string) ($in['status'] ?? 'chce_przeczytac'));
    if (!in_array($status, $statusy, true)) {
        $bledy[] = 'Nieznany status czytania.';
    }

    $format = ebook_normalizuj_format((string) ($in['format'] ?? 'epub'));

    // Brak powiązania = null; cokolwiek innego niż dodatnie id odrzucamy.
    $powiazana = null;
    $pow = $in['powiazana_ksiazka_id'] ?? null;
    if ($pow !== null && $pow !== '' && $pow !== 0 && $pow !== '0') {
        if (!is_numeric($pow) || (int) $pow < 1) {
            $bledy[] = 'Powiązana książka musi mieć poprawne id.';
        } else {
            $powiazana = (int) $pow;
        }
    }

    $hash = niepuste($in['hash_pliku'] ?? null);
    if ($hash !== null) {
        $hash = mb_strtolower($hash);
        if (preg_match('/^[a-f0-9]{' . EBOOK_HASH_DLUGOSC . '}$/', $hash) !== 1) {
            $bledy[] = 'Hash pliku musi być 64-znakowym SHA-256.';
            $hash = null;
        }
    }

    // Źródło tylko z białej listy — pole kontrolne, nie tekst użytkownika.
    $zrodlo = niepuste($in['zrodlo'] ?? null) ?? 'recznie';
    if (!in_array($zrodlo, EBOOK_ZRODLA, true)) {
        $zrodlo = 'recznie';
    }

    $dane = [
        'tytul'  => $tytul,
        'autor'  => $autor,
        'isbn'   => $isbn,
        'rok'    => $rok,
        'liczba_stron' => $strony,
        'ocena'  => $ocena,
        'status' => $status,
        'format' => $format,
        'zrodlo' => $zrodlo,
        'hash_pliku' => $hash,
        'powiazana_ksiazka_id' => $powiazana,
    ];

    foreach (['podtytul' => 500, 'wydawca' => 200, 'jezyk' => 20, 'oryginalny_tytul' => 500] as $p => $max) {
        $v = niepuste($in[$p] ?? null);
        if ($v !== null && mb_strlen($v) > $max) {
            $bledy[] = sprintf('Pole „%s” jest za długie (max %d znaków).', $p, $max);
        }
        $dane[$p] = $v;
    }

    // Opis/notatki obcinamy zamiast odrzucać — przy imporcie utrata ogona
    // opisu nie jest powodem, żeby pominąć całą książkę.
    $opis = niepuste($in['opis'] ?? null);
    $dane['opis'] = $opis !== null ? mb_substr($opis, 0, EBOOK_OPIS_MAX) : null;
    $notatki = niepuste($in['notatki'] ?? null);
    $dane['notatki'] = $notatki !== null ? mb_substr($notatki, 0, EBOOK_NOTATKI_MAX) : null;

    // Nazwa pliku to tylko etykieta (VARCHAR 255) — dłuższą obcinamy, bo
    // systemy plików potrafią dać więcej, a to nie jest klucz tożsamości.
    $nazwaPliku = niepuste($in['nazwa_pliku'] ?? null);
    $dane['nazwa_pliku'] = $nazwaPliku !== null ? mb_substr($nazwaPliku, 0, 255) : null;

    // Folder to etykieta podana przez użytkownika (nie ścieżka dyskowa).
    $folder = niepuste($in['folder'] ?? null);
    if ($folder !== null && mb_strlen($folder) > 120) {
        $bledy[] = 'Nazwa folderu jest za długa (max 120 znaków).';
    }
    $dane['folder'] = $folder;

    // Ścieżka okładki: tylko nazwa pliku z uploads/ (32 hex + dozwolone
    // rozszerzenie) — path traversal i URL-e odrzucamy jak przy książkach.
    $okladka = niepuste($in['okladka'] ?? null);
    if ($okladka !== null
        && preg_match('#^(?:[a-z0-9_]+/)*[a-f0-9]{32}\.(?:jpg|png|webp)$#', $okladka) !== 1) {
        $bledy[] = 'Nieprawidłowa ścieżka okładki.';
        $okladka = null;
    }
    $dane['okladka'] = $okladka;

    // Tagi normalizujemy od razu; półki zostawiamy surowe, bo formularz
    // wysyła id, a import/backup nazwy — rozstrzyga to ebook_zapisz().
    $tagi = tagi_normalizuj($in['tagi'] ?? null);
    $bledy = array_merge($bledy, $tagi['bledy']);
    $dane['tagi'] = $tagi['tagi'];
    $dane['polki'] = $in['polki'] ?? [];

    return ['dane' => $dane, 'bledy' => $bledy];
}

/**
 * Zapis ebooka: INSERT (bez $id) albo UPDATE w zakresie właściciela.
 * Tagi i półki są nadpisywane w całości — formularz zawsze przysyła pełną listę.
 * Rzuca RuntimeException, gdy $id nie należy do użytkownika (nie ruszamy
 * wtedy powiązań cudzego ebooka).
 */
function ebook_zapisz(PDO $pdo, int $userId, array $dane, ?int $id = null): int
{
    // Kolejność = kolejność kolumn w bib_ebooki (bez id/user_id/dat).
    $kolumny = [
        'isbn', 'tytul', 'podtytul', 'autor', 'rok', 'wydawca', 'liczba_stron',
        'jezyk', 'oryginalny_tytul', 'opis', 'okladka', 'format', 'status',
        'ocena', 'notatki', 'hash_pliku', 'nazwa_pliku', 'folder',
        'powiazana_ksiazka_id', 'zrodlo',
    ];

    $pdo->beginTransaction();
    try {
        if ($id === null) {
            $sql = sprintf(
                'INSERT INTO bib_ebooki (user_id, %s) VALUES (%s)',
                implode(', ', $kolumny),
                implode(', ', array_fill(0, count($kolumny) + 1, '?'))
            );
            $wartosci = [$userId];
            foreach ($kolumny as $k) {
                $wartosci[] = $dane[$k] ?? null;
            }
            $pdo->prepare($sql)->execute($wartosci);
            $id = (int) $pdo->lastInsertId();
        } else {
            // Jawne sprawdzenie właściciela: samo WHERE w UPDATE nie wystarczy,
            // bo powiązania tagów/półek nie znają user_id.
            $st = $pdo->prepare('SELECT id FROM bib_ebooki WHERE id = ? AND user_id = ?');
            $st->execute([$id, $userId]);
            if ($st->fetchColumn() === false) {
                throw new RuntimeException('Nie znaleziono ebooka.');
            }

            $sql = sprintf(
                'UPDATE bib_ebooki SET %s, data_edycji = NOW() WHERE id = ? AND user_id = ?',
                implode(', ', array_map(static fn (string $k): string => "{$k} = ?", $kolumny))
            );
            $wartosci = [];
            foreach ($kolumny as $k) {
                $wartosci[] = $dane[$k] ?? null;
            }
            $wartosci[] = $id;
            $wartosci[] = $userId;
            $pdo->prepare($sql)->execute($wartosci);
        }

        // Tagi: brakujące etykiety zakładamy w słowniku (wspólnym z papierem),
        // powiązania wymieniamy. Deduplikacja i limity siedzą w tagi_normalizuj.
        $tagi = tagi_do_zapisu(tagi_normalizuj($dane['tagi'] ?? null)['tagi']);
        $pdo->prepare('DELETE FROM bib_ebook_tagi WHERE ebook_id = ?')->execute([$id]);
        $znajdzTag = $pdo->prepare('SELECT id FROM bib_tagi WHERE user_id = ? AND nazwa = ?');
        $dodajTag  = $pdo->prepare('INSERT IGNORE INTO bib_tagi (user_id, nazwa) VALUES (?, ?)');
        $linkTag   = $pdo->prepare('INSERT IGNORE INTO bib_ebook_tagi (ebook_id, tag_id) VALUES (?, ?)');
        foreach ($tagi as $t) {
            $znajdzTag->execute([$userId, $t['klucz']]);
            $tagId = $znajdzTag->fetchColumn();
            if ($tagId === false) {
                $dodajTag->execute([$userId, $t['klucz']]);
                $tagId = (int) $pdo->lastInsertId();
            }
            $linkTag->execute([$id, (int) $tagId]);
        }

        // Półki: formularz daje id, import/backup nazwy. Rozpoznajemy po tym,
        // czy każda wartość jest liczbą; nazwy zakładamy „po drodze” jak przy
        // papierze (polki_id_z_nazw robi to na db(), tu mamy wstrzyknięte PDO).
        $idPolk = [];
        $listaPolk = $dane['polki'] ?? [];
        if (is_string($listaPolk)) {
            $listaPolk = array_filter(explode(',', $listaPolk), static fn ($v) => trim($v) !== '');
        }
        if (is_array($listaPolk) && $listaPolk !== []) {
            $tylkoId = true;
            foreach ($listaPolk as $p) {
                if (!is_scalar($p) || preg_match('/^\d+$/', trim((string) $p)) !== 1) {
                    $tylkoId = false;
                    break;
                }
            }
            if ($tylkoId) {
                $idPolk = id_listy_normalizuj($listaPolk);
            } else {
                $znajdzPolke = $pdo->prepare('SELECT id FROM bib_polk WHERE user_id = ? AND nazwa = ?');
                $dodajPolke  = $pdo->prepare('INSERT IGNORE INTO bib_polk (user_id, nazwa) VALUES (?, ?)');
                foreach (polki_nazwy_normalizuj($listaPolk) as $nazwa) {
                    $klucz = mb_strtolower($nazwa);
                    $znajdzPolke->execute([$userId, $klucz]);
                    $polkaId = $znajdzPolke->fetchColumn();
                    if ($polkaId === false) {
                        $dodajPolke->execute([$userId, $klucz]);
                        $polkaId = (int) $pdo->lastInsertId();
                    }
                    $idPolk[] = (int) $polkaId;
                }
            }
        }

        $pdo->prepare('DELETE FROM bib_ebook_polk WHERE ebook_id = ?')->execute([$id]);
        if ($idPolk !== []) {
            // SELECT z user_id — cudze półki nie wejdą do powiązań.
            $sql = sprintf(
                'INSERT IGNORE INTO bib_ebook_polk (ebook_id, polka_id)
                 SELECT ?, id FROM bib_polk WHERE user_id = ? AND id IN (%s)',
                implode(',', array_fill(0, count($idPolk), '?'))
            );
            $pdo->prepare($sql)->execute(array_merge([$id, $userId], $idPolk));
        }

        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }

    return $id;
}

/**
 * Lista ebooków z filtrami jak w katalogu papierowym. Zawsze w zakresie
 * user_id. Zwraca także numer strony i liczbę stron, żeby endpoint nie
 * powtarzał arytmetyki paginacji.
 */
function ebook_lista(PDO $pdo, int $userId, array $filtr): array
{
    $warunki = ['user_id = ?'];
    $parametry = [$userId];

    $q = trim((string) ($filtr['q'] ?? ''));
    if ($q !== '') {
        $like = '%' . $q . '%';
        // Opis też przeszukujemy — często szukamy własnych notatek/opisów.
        $warunki[] = '(tytul LIKE ? OR podtytul LIKE ? OR autor LIKE ? OR isbn LIKE ? OR opis LIKE ?)';
        array_push($parametry, $like, $like, $like, $like, $like);
    }

    $status = niepuste($filtr['status'] ?? null);
    if ($status !== null) {
        $warunki[] = 'status = ?';
        $parametry[] = $status;
    }

    // Nieznany format ignorujemy zamiast pokazywać pustą listę.
    $format = mb_strtolower(trim((string) ($filtr['format'] ?? '')));
    if ($format !== '' && in_array($format, EBOOK_FORMATY, true)) {
        $warunki[] = 'format = ?';
        $parametry[] = $format;
    }

    // Tagi: muszą być WSZYSTKIE wskazane (AND) — zawężenie, nie rozszerzenie.
    foreach (tagi_normalizuj($filtr['tag'] ?? null)['tagi'] as $tag) {
        $warunki[] = 'EXISTS (SELECT 1 FROM bib_ebook_tagi et'
            . ' JOIN bib_tagi t ON t.id = et.tag_id'
            . ' WHERE et.ebook_id = bib_ebooki.id AND t.nazwa = ?)';
        $parametry[] = mb_strtolower($tag);
    }

    // Półka: wystarczy jedna z wskazanych (OR), jak przy papierze.
    $polki = id_listy_normalizuj($filtr['polka'] ?? null);
    if ($polki !== []) {
        $w = [];
        foreach ($polki as $polkaId) {
            $w[] = 'EXISTS (SELECT 1 FROM bib_ebook_polk ep'
                . ' WHERE ep.ebook_id = bib_ebooki.id AND ep.polka_id = ?)';
            $parametry[] = $polkaId;
        }
        $warunki[] = '(' . implode(' OR ', $w) . ')';
    }

    $where = ' WHERE ' . implode(' AND ', $warunki);

    // Sortowanie z białej listy; sufiks „_desc” oznacza malejąco. Domyślnie
    // najnowsze nabytki — po imporcie folderu to najbardziej użyteczny widok.
    $sort = mb_strtolower(trim((string) ($filtr['sort'] ?? 'dodania_desc')));
    $kier = 'ASC';
    if (str_ends_with($sort, '_desc')) {
        $kier = 'DESC';
        $sort = substr($sort, 0, -5);
    }
    $sortMapa = ['dodania' => 'data_dodania', 'tytul' => 'tytul', 'autor' => 'autor', 'rok' => 'rok'];
    $kolumna = $sortMapa[$sort] ?? $sortMapa['dodania'];
    // Rok jest tekstem — sortujemy cyfrowo, żeby „9” nie wypadło po „10”.
    $order = $kolumna === 'rok' ? "rok + 0 {$kier}" : "{$kolumna} {$kier}";

    $st = $pdo->prepare('SELECT COUNT(*) FROM bib_ebooki' . $where);
    $st->execute($parametry);
    $razem = (int) $st->fetchColumn();

    // LIMIT/OFFSET wstawiamy jako inty po walidacji — nie pochodzą wprost z wejścia.
    $strona = max(1, (int) ($filtr['strona'] ?? 1));
    $limit = EBOOK_NA_STRONE;
    $offset = ($strona - 1) * $limit;

    // Bez opisu — lista kart go nie pokazuje, a TEXT podnosi odpowiedź.
    $sql = 'SELECT id, isbn, tytul, podtytul, autor, rok, wydawca, liczba_stron,
                   jezyk, oryginalny_tytul, okladka, format, status, ocena,
                   hash_pliku, nazwa_pliku, folder, powiazana_ksiazka_id, zrodlo,
                   data_dodania, data_edycji
            FROM bib_ebooki' . $where
            . ' ORDER BY ' . $order . ', id ASC'
            . sprintf(' LIMIT %d OFFSET %d', $limit, $offset);
    $st = $pdo->prepare($sql);
    $st->execute($parametry);
    $ebooki = $st->fetchAll();

    // Tagi i półki jednym zapytaniem na całą stronę (nie N+1).
    $ids = array_map('intval', array_column($ebooki, 'id'));
    $tagi = [];
    $polkiPo = [];
    if ($ids !== []) {
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $st = $pdo->prepare(
            "SELECT et.ebook_id, t.nazwa FROM bib_ebook_tagi et
             JOIN bib_tagi t ON t.id = et.tag_id
             WHERE et.ebook_id IN ($ph) ORDER BY t.nazwa ASC"
        );
        $st->execute($ids);
        foreach ($st->fetchAll() as $r) {
            $tagi[(int) $r['ebook_id']][] = (string) $r['nazwa'];
        }

        $st = $pdo->prepare(
            "SELECT ep.ebook_id, p.id, p.nazwa FROM bib_ebook_polk ep
             JOIN bib_polk p ON p.id = ep.polka_id
             WHERE ep.ebook_id IN ($ph) ORDER BY p.nazwa ASC"
        );
        $st->execute($ids);
        foreach ($st->fetchAll() as $r) {
            $polkiPo[(int) $r['ebook_id']][] = ['id' => (int) $r['id'], 'nazwa' => (string) $r['nazwa']];
        }
    }
    foreach ($ebooki as &$e) {
        $eid = (int) $e['id'];
        $e['tagi'] = $tagi[$eid] ?? [];
        $e['polki'] = $polkiPo[$eid] ?? [];
    }
    unset($e);

    return [
        'ebooki' => $ebooki,
        'razem'  => $razem,
        'strona' => $strona,
        'stron'  => max(1, (int) ceil($razem / $limit)),
    ];
}

/**
 * Szczegół ebooka w zakresie właściciela. Zwraca null, gdy rekord nie istnieje
 * albo należy do kogoś innego. Powiązana książka też jest sprawdzana po
 * user_id — FK nie pilnuje właściciela, tylko istnienie wiersza.
 */
function ebook_szczegol(PDO $pdo, int $userId, int $id): ?array
{
    $st = $pdo->prepare('SELECT * FROM bib_ebooki WHERE id = ? AND user_id = ?');
    $st->execute([$id, $userId]);
    $wiersz = $st->fetch();
    if ($wiersz === false) {
        return null;
    }

    $st = $pdo->prepare(
        'SELECT t.nazwa FROM bib_ebook_tagi et
         JOIN bib_tagi t ON t.id = et.tag_id
         WHERE et.ebook_id = ? ORDER BY t.nazwa ASC'
    );
    $st->execute([$id]);
    $tagi = array_map('strval', $st->fetchAll(PDO::FETCH_COLUMN));

    $st = $pdo->prepare(
        'SELECT p.id, p.nazwa FROM bib_ebook_polk ep
         JOIN bib_polk p ON p.id = ep.polka_id
         WHERE ep.ebook_id = ? ORDER BY p.nazwa ASC'
    );
    $st->execute([$id]);
    $polki = $st->fetchAll();

    $powiazana = null;
    if ($wiersz['powiazana_ksiazka_id'] !== null) {
        $st = $pdo->prepare('SELECT id, tytul, autor FROM bib_ksiazki WHERE id = ? AND user_id = ?');
        $st->execute([(int) $wiersz['powiazana_ksiazka_id'], $userId]);
        $p = $st->fetch();
        if ($p !== false) {
            $powiazana = [
                'id'    => (int) $p['id'],
                'tytul' => (string) $p['tytul'],
                'autor' => (string) $p['autor'],
            ];
        }
    }

    return ['ebook' => $wiersz, 'tagi' => $tagi, 'polki' => $polki, 'powiazana' => $powiazana];
}

/** Usunięcie ebooka właściciela. Powiązania tagów/półek znikają kaskadą FK. */
function ebook_usun(PDO $pdo, int $userId, int $id): bool
{
    $st = $pdo->prepare('DELETE FROM bib_ebooki WHERE id = ? AND user_id = ?');
    $st->execute([$id, $userId]);
    return $st->rowCount() > 0;
}

/**
 * Deduplikacja importu: najpierw hash pliku (najpewniejszy — ten sam plik),
 * potem ISBN (z wariantami 10/13), na końcu tytuł + autor po trim. Zwraca
 * ['id' => int, 'powod' => 'hash|isbn|tytul'] albo null.
 */
function ebook_duplikat(PDO $pdo, int $userId, array $poz): ?array
{
    $hash = niepuste($poz['hash_pliku'] ?? null);
    if ($hash !== null) {
        $hash = mb_strtolower($hash);
        if (preg_match('/^[a-f0-9]{' . EBOOK_HASH_DLUGOSC . '}$/', $hash) === 1) {
            $st = $pdo->prepare('SELECT id FROM bib_ebooki WHERE user_id = ? AND hash_pliku = ? LIMIT 1');
            $st->execute([$userId, $hash]);
            $id = $st->fetchColumn();
            if ($id !== false) {
                return ['id' => (int) $id, 'powod' => 'hash'];
            }
        }
    }

    $isbn = niepuste($poz['isbn'] ?? null);
    if ($isbn !== null) {
        // Ten sam tytuł bywa zapisany raz jako ISBN-10, raz jako ISBN-13;
        // isbn_warianty() zwraca obie formy (albo samą podaną, gdy jest błędna).
        $warianty = isbn_warianty(isbn_normalizuj($isbn));
        if ($warianty !== []) {
            $ph = implode(',', array_fill(0, count($warianty), '?'));
            $st = $pdo->prepare(
                "SELECT id FROM bib_ebooki WHERE user_id = ? AND isbn IN ($ph) LIMIT 1"
            );
            $st->execute(array_merge([$userId], $warianty));
            $id = $st->fetchColumn();
            if ($id !== false) {
                return ['id' => (int) $id, 'powod' => 'isbn'];
            }
        }
    }

    $tytul = trim((string) ($poz['tytul'] ?? ''));
    $autor = trim((string) ($poz['autor'] ?? ''));
    if ($tytul !== '') {
        $st = $pdo->prepare(
            'SELECT id FROM bib_ebooki WHERE user_id = ? AND tytul = ? AND autor = ? LIMIT 1'
        );
        $st->execute([$userId, $tytul, $autor]);
        $id = $st->fetchColumn();
        if ($id !== false) {
            return ['id' => (int) $id, 'powod' => 'tytul'];
        }
    }

    return null;
}
