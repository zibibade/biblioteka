<?php
declare(strict_types=1);

/**
 * Statystyki katalogu — jedno zapytanie agregujące na rekord + kilka zapytań
 * grupujących. Bez N+1: każde zapytanie zwraca gotowe wiersze dla widoku.
 *
 * Katalogi liczymy osobno: papier i ebooki nie są sumowane, bo to dwa
 * niezależne zbiory (ebook nie jest egzemplarzem papierowym i odwrotnie).
 *
 * GET /api/stats.php → {papier:{...}, ebooki:{...}}
 */

require_once __DIR__ . '/../config.php';

auth_require();

$userId = auth_user_id();
$pdo = db();

/* ================================================================= PAPIER */

/* ------------------------------------------------------------- podsumowanie */

// SUM(liczba_stron) pomija NULL-y; COALESCE daje 0 zamiast NULL przy pustym
// katalogu (w PHP null w json_encode to null, a w JS wygodniej liczbę).
$st = $pdo->prepare(
    'SELECT COUNT(*) AS ksiazki,
            COALESCE(SUM(liczba_stron), 0) AS stron,
            COUNT(liczba_stron) AS z_stronami,
            ROUND(AVG(ocena), 2) AS srednia_ocena,
            COUNT(ocena) AS ocenionych,
            COUNT(okladka) AS z_okladka,
            COUNT(DISTINCT autor) AS autorow,
            MIN(rok) AS najstarszy,
            MAX(rok) AS najnowszy
     FROM bib_ksiazki WHERE user_id = ?'
);
$st->execute([$userId]);
$suma = $st->fetch() ?: [];

/* --------------------------------------------------- podział na statusy */

$st = $pdo->prepare('SELECT status, COUNT(*) AS ile FROM bib_ksiazki
                     WHERE user_id = ? GROUP BY status');
$st->execute([$userId]);
$statusy = [];
foreach ($st->fetchAll() as $r) {
    $statusy[(string) $r['status']] = (int) $r['ile'];
}

/* ------------------------------------------------------ wypożyczenia */

$st = $pdo->prepare(
    'SELECT COUNT(*) AS razem,
            SUM(CASE WHEN data_zwrotu IS NULL THEN 1 ELSE 0 END) AS aktywne
     FROM bib_wypozyczenia WHERE user_id = ?'
);
$st->execute([$userId]);
$wyp = $st->fetch() ?: [];

/* ------------------------------------------------------------- rozkład lat */

// GROUP BY rok — rok trzymany jako tekst, więc ORDER BY rok+0 (numerycznie).
// NULL i puste lata pomijamy, bo nie są „rozkładem wydań".
$st = $pdo->prepare(
    "SELECT rok, COUNT(*) AS ile FROM bib_ksiazki
     WHERE user_id = ? AND rok IS NOT NULL AND rok <> ''
     GROUP BY rok ORDER BY rok + 0 DESC"
);
$st->execute([$userId]);
$lata = $st->fetchAll();

/* --------------------------------------------------------- top autorzy */

$st = $pdo->prepare(
    "SELECT autor, COUNT(*) AS ile FROM bib_ksiazki
     WHERE user_id = ? AND autor <> ''
     GROUP BY autor ORDER BY ile DESC, autor ASC LIMIT 10"
);
$st->execute([$userId]);
$autorzy = $st->fetchAll();

/* -------------------------------------------------------------- tagi */

$st = $pdo->prepare(
    'SELECT t.nazwa, COUNT(kt.ksiazka_id) AS ile FROM bib_tagi t
     LEFT JOIN bib_ksiazka_tagi kt ON kt.tag_id = t.id
     WHERE t.user_id = ?
     GROUP BY t.id, t.nazwa HAVING ile > 0
     ORDER BY ile DESC, t.nazwa ASC LIMIT 12'
);
$st->execute([$userId]);
$tagi = $st->fetchAll();

/* ----------------------------------------------------- do uzupełnienia */

// Książki wymagające ręcznej poprawy: brak okładki, brak oceny, brak opisu.
$st = $pdo->prepare(
    'SELECT
        SUM(CASE WHEN okladka IS NULL THEN 1 ELSE 0 END) AS bez_okladki,
        SUM(CASE WHEN ocena IS NULL THEN 1 ELSE 0 END) AS bez_oceny,
        SUM(CASE WHEN opis IS NULL OR opis = "" THEN 1 ELSE 0 END) AS bez_opisu,
        SUM(CASE WHEN zdjecie IS NULL THEN 1 ELSE 0 END) AS bez_zdjecia
     FROM bib_ksiazki WHERE user_id = ?'
);
$st->execute([$userId]);
$braki = $st->fetch() ?: [];

/* ================================================================= EBOOKI */

/* ------------------------------------------------------------- podsumowanie */

// Te same miary co dla papieru, żeby obie sekcje dały się czytać obok siebie.
$st = $pdo->prepare(
    'SELECT COUNT(*) AS razem,
            COALESCE(SUM(liczba_stron), 0) AS stron,
            COUNT(liczba_stron) AS z_stronami,
            ROUND(AVG(ocena), 2) AS srednia_ocena,
            COUNT(ocena) AS ocenionych,
            COUNT(okladka) AS z_okladka,
            COUNT(DISTINCT autor) AS autorow,
            MIN(rok) AS najstarszy,
            MAX(rok) AS najnowszy
     FROM bib_ebooki WHERE user_id = ?'
);
$st->execute([$userId]);
$sumaEb = $st->fetch() ?: [];

/* --------------------------------------------------- podział na statusy */

$st = $pdo->prepare('SELECT status, COUNT(*) AS ile FROM bib_ebooki
                     WHERE user_id = ? GROUP BY status');
$st->execute([$userId]);
$statusyEb = [];
foreach ($st->fetchAll() as $r) {
    $statusyEb[(string) $r['status']] = (int) $r['ile'];
}

/* ----------------------------------------------------- podział na formaty */

// Format jest cechą tylko ebooka, więc osobne zapytanie zamiast kolumny w sumie.
$st = $pdo->prepare('SELECT format, COUNT(*) AS ile FROM bib_ebooki
                     WHERE user_id = ? GROUP BY format ORDER BY ile DESC, format ASC');
$st->execute([$userId]);
$formatyEb = $st->fetchAll();

/* ------------------------------------------------------------- rozkład lat */

$st = $pdo->prepare(
    "SELECT rok, COUNT(*) AS ile FROM bib_ebooki
     WHERE user_id = ? AND rok IS NOT NULL AND rok <> ''
     GROUP BY rok ORDER BY rok + 0 DESC"
);
$st->execute([$userId]);
$lataEb = $st->fetchAll();

/* --------------------------------------------------------- top autorzy */

$st = $pdo->prepare(
    "SELECT autor, COUNT(*) AS ile FROM bib_ebooki
     WHERE user_id = ? AND autor <> ''
     GROUP BY autor ORDER BY ile DESC, autor ASC LIMIT 10"
);
$st->execute([$userId]);
$autorzyEb = $st->fetchAll();

/* -------------------------------------------------------------- tagi */

// Tagi ebooków siedzą w bib_ebook_tagi — własna tabela, więc własne złączenie.
$st = $pdo->prepare(
    'SELECT t.nazwa, COUNT(et.ebook_id) AS ile FROM bib_tagi t
     LEFT JOIN bib_ebook_tagi et ON et.tag_id = t.id
     WHERE t.user_id = ?
     GROUP BY t.id, t.nazwa HAVING ile > 0
     ORDER BY ile DESC, t.nazwa ASC LIMIT 12'
);
$st->execute([$userId]);
$tagiEb = $st->fetchAll();

/* ----------------------------------------------------- do uzupełnienia */

// Ebook nie ma zdjęcia egzemplarza, więc bez_zdjecia tu nie występuje.
$st = $pdo->prepare(
    'SELECT
        SUM(CASE WHEN okladka IS NULL THEN 1 ELSE 0 END) AS bez_okladki,
        SUM(CASE WHEN ocena IS NULL THEN 1 ELSE 0 END) AS bez_oceny,
        SUM(CASE WHEN opis IS NULL OR opis = "" THEN 1 ELSE 0 END) AS bez_opisu
     FROM bib_ebooki WHERE user_id = ?'
);
$st->execute([$userId]);
$brakiEb = $st->fetch() ?: [];

/* ================================================================== WYNIK */

json_out([
    'papier' => [
        'suma' => [
            'ksiazki'      => (int) ($suma['ksiazki'] ?? 0),
            'stron'        => (int) ($suma['stron'] ?? 0),
            'z_stronami'   => (int) ($suma['z_stronami'] ?? 0),
            'srednia_ocena' => $suma['srednia_ocena'] !== null ? (float) $suma['srednia_ocena'] : null,
            'ocenionych'   => (int) ($suma['ocenionych'] ?? 0),
            'z_okladka'    => (int) ($suma['z_okladka'] ?? 0),
            'autorow'      => (int) ($suma['autorow'] ?? 0),
            'najstarszy'   => $suma['najstarszy'] !== null ? (string) $suma['najstarszy'] : null,
            'najnowszy'    => $suma['najnowszy'] !== null ? (string) $suma['najnowszy'] : null,
        ],
        'statusy'  => $statusy,
        'wypozyczenia' => [
            'razem'   => (int) ($wyp['razem'] ?? 0),
            'aktywne' => (int) ($wyp['aktywne'] ?? 0),
        ],
        'lata'     => $lata,
        'autorzy'  => $autorzy,
        'tagi'     => $tagi,
        'braki'    => [
            'bez_okladki' => (int) ($braki['bez_okladki'] ?? 0),
            'bez_oceny'   => (int) ($braki['bez_oceny'] ?? 0),
            'bez_opisu'   => (int) ($braki['bez_opisu'] ?? 0),
            'bez_zdjecia' => (int) ($braki['bez_zdjecia'] ?? 0),
        ],
    ],
    'ebooki' => [
        'suma' => [
            'razem'        => (int) ($sumaEb['razem'] ?? 0),
            'stron'        => (int) ($sumaEb['stron'] ?? 0),
            'z_stronami'   => (int) ($sumaEb['z_stronami'] ?? 0),
            'srednia_ocena' => $sumaEb['srednia_ocena'] !== null ? (float) $sumaEb['srednia_ocena'] : null,
            'ocenionych'   => (int) ($sumaEb['ocenionych'] ?? 0),
            'z_okladka'    => (int) ($sumaEb['z_okladka'] ?? 0),
            'autorow'      => (int) ($sumaEb['autorow'] ?? 0),
            'najstarszy'   => $sumaEb['najstarszy'] !== null ? (string) $sumaEb['najstarszy'] : null,
            'najnowszy'    => $sumaEb['najnowszy'] !== null ? (string) $sumaEb['najnowszy'] : null,
        ],
        'statusy'  => $statusyEb,
        'formaty'  => $formatyEb,
        'lata'     => $lataEb,
        'autorzy'  => $autorzyEb,
        'tagi'     => $tagiEb,
        'braki'    => [
            'bez_okladki' => (int) ($brakiEb['bez_okladki'] ?? 0),
            'bez_oceny'   => (int) ($brakiEb['bez_oceny'] ?? 0),
            'bez_opisu'   => (int) ($brakiEb['bez_opisu'] ?? 0),
        ],
    ],
]);
