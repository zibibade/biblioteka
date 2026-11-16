<?php
declare(strict_types=1);

/**
 * Import katalogu z pliku JSON wyeksportowanego przez aplikację
 * (format `biblioteka-eksport/v1` albo `biblioteka-eksport/v2`).
 * Round-trip: książki, tagi, półki, słowniki i historia wypożyczeń;
 * w v2 dodatkowo ebooki (metadane + tagi + półki). Importujemy TYLKO
 * własny eksport — brak parsera pod cudzy plik.
 *
 * POST /api/import.php  { dane: <json w stringu>, tryb: 'scal'|'przywroc', potwierdz?: bool }
 */

require_once __DIR__ . '/../config.php';

// v2 to jedyna wersja, która niesie ebooki; import_rozpoznaj() (lib/logika.php)
// zna wyłącznie v1, dlatego wersję rozpoznajemy tutaj.
const EKSPORT_V2 = 'biblioteka-eksport/v2';

auth_require();
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    json_fail('Metoda niedozwolona.', 405);
}

$wejscie = json_decode((string) file_get_contents('php://input'), true);
if (!is_array($wejscie)) {
    json_fail('Oczekiwano JSON.');
}

$plik = $wejscie['dane'] ?? null;
if (is_string($plik)) {
    // UI wysyła zawartość pliku jako tekst (plik może być duży)
    if (strlen($plik) > 8 * 1024 * 1024) {
        json_fail('Plik jest za duży (maks. 8 MB).');
    }
    $plik = json_decode($plik, true);
}

$formatPliku = is_array($plik) ? (string) ($plik['format'] ?? '') : '';

if ($formatPliku === EKSPORT_V2) {
    $ebookiWejscia = $plik['ebooki'] ?? [];
    if (!is_array($ebookiWejscia)) {
        json_fail('Sekcja ebooków w pliku nie jest listą.');
    }
    if (count($ebookiWejscia) > IMPORT_MAX_REKORDY) {
        json_fail(sprintf(
            'Plik ma %d ebooków — maksimum w jednym imporcie to %d.',
            count($ebookiWejscia),
            IMPORT_MAX_REKORDY
        ));
    }

    // Reguły wspólne (limit książek, filtrowanie rekordów) są te same co dla
    // v1, więc rozpoznajemy kopię z etykietą v1 i dokładamy ebooki osobno.
    $kopia = $plik;
    $kopia['format'] = IMPORT_FORMAT;
    $r = import_rozpoznaj($kopia);
    $r['ebooki'] = array_values(array_filter($ebookiWejscia, 'is_array'));
} else {
    // v1 — dotychczasowa ścieżka bez zmian
    $r = import_rozpoznaj($plik);
}

if ($r['ok'] === false) {
    json_fail((string) $r['blad']);
}

$tryb = (string) ($wejscie['tryb'] ?? 'scal');
if (!in_array($tryb, ['scal', 'przywroc'], true)) {
    json_fail('Nieznany tryb importu.');
}

$userId = auth_user_id();
$pdo = db();

// Przywracanie kasuje katalog — wymaga jawnego potwierdzenia od UI
if ($tryb === 'przywroc') {
    if (($wejscie['potwierdz'] ?? false) !== true) {
        json_fail('Przywracanie wymaga potwierdzenia.', 428);
    }
    import_katalog_wyczysc($userId);
    // Ebooki mają własne tabele, więc import_katalog_wyczysc() ich nie rusza —
    // „przywróć" musi wyczyścić oba katalogi, inaczej stary katalog zostałby
    // obok wczytanego pliku. Powiązania tagów/półek znikają przez kaskadę FK.
    $pdo->prepare('DELETE FROM bib_ebooki WHERE user_id = ?')->execute([$userId]);
}

// Klucze egzemplarzy już obecnych — żeby „scal" ich nie dublował
$istniejace = [];
$st = $pdo->prepare('SELECT isbn, tytul, rok FROM bib_ksiazki WHERE user_id = ?');
$st->execute([$userId]);
foreach ($st->fetchAll() as $r0) {
    $istniejace[import_klucz($r0)] = true;
}

$wypPoKluczu = import_wypozyczenia_mapa($r['wypozyczenia']);

$dodane = 0;
$pominiete = 0;
$bledy = [];
$pominieteTytuly = [];

foreach ($r['ksiazki'] as $rekord) {
    $klucz = import_klucz($rekord);
    if ($tryb === 'scal' && isset($istniejace[$klucz])) {
        $pominiete++;
        if (count($pominieteTytuly) < 8) {
            $pominieteTytuly[] = (string) ($rekord['tytul'] ?? '?');
        }
        continue;
    }

    $rozpak = import_ksiazke_rozpakuj($rekord, $wypPoKluczu);
    $wynik = import_egzemplarza($rozpak['wejscie'], $rozpak['tagi'], $rozpak['polki'],
        $rozpak['wypozyczenia'], $userId);

    if ($wynik['ok'] === false) {
        $bledy[] = sprintf('„%s”: %s', (string) ($rekord['tytul'] ?? '?'), $wynik['blad']);
        if (count($bledy) >= 8) {
            $bledy[] = '…';
            break;
        }
        continue;
    }

    $dodane++;
    $istniejace[$klucz] = true;
}

/* ------------------------------------------------------------- ebooki (v2) */

// Katalog ebooków jest niezależny, więc raport liczy go osobnym licznikiem:
// `wczytano`/`dodane` dotyczą papieru, `wczytano_ebooki`/`dodane_ebooki` ebooków.
$dodaneEbooki = 0;
$pominieteEbooki = 0;
$bledyEbooki = [];
$pominieteEbookiTytuly = [];

// Pola znane z eksportu; obce klucze z pliku pomijamy, tak jak przy papierze.
$ebookPola = [
    'isbn', 'tytul', 'podtytul', 'autor', 'rok', 'wydawca', 'liczba_stron',
    'jezyk', 'oryginalny_tytul', 'opis', 'okladka', 'format', 'status', 'ocena',
    'notatki', 'hash_pliku', 'nazwa_pliku', 'folder', 'powiazana_ksiazka_id',
    'zrodlo', 'tagi', 'polki',
];

foreach ($r['ebooki'] ?? [] as $rekord) {
    $tytul = (string) ($rekord['tytul'] ?? '?');

    $ebookWejscie = [];
    foreach ($ebookPola as $pole) {
        if (array_key_exists($pole, $rekord)) {
            $ebookWejscie[$pole] = $rekord[$pole];
        }
    }

    ['dane' => $dane, 'bledy' => $problemy] = ebook_waliduj($ebookWejscie);
    if ($problemy !== []) {
        $bledyEbooki[] = sprintf('„%s”: %s', $tytul, implode(' ', $problemy));
        if (count($bledyEbooki) >= 8) {
            $bledyEbooki[] = '…';
            break;
        }
        continue;
    }

    // Id książki papierowej nie jest przenośne między instalacjami — bierzemy
    // powiązanie tylko wtedy, gdy taki egzemplarz należy do tego użytkownika
    // (FK pilnuje istnienia wiersza, nie właściciela).
    if ($dane['powiazana_ksiazka_id'] !== null) {
        $stPow = $pdo->prepare('SELECT id FROM bib_ksiazki WHERE id = ? AND user_id = ?');
        $stPow->execute([(int) $dane['powiazana_ksiazka_id'], $userId]);
        if ($stPow->fetchColumn() === false) {
            $dane['powiazana_ksiazka_id'] = null;
        }
    }

    // Dedup w kolejności hash → ISBN → tytuł+autor; przy „przywróć" katalog
    // był właśnie wyczyszczony, więc nie ma czego deduplikować.
    if ($tryb === 'scal') {
        $duplikat = ebook_duplikat($pdo, $userId, $dane);
        if ($duplikat !== null) {
            $pominieteEbooki++;
            if (count($pominieteEbookiTytuly) < 8) {
                $pominieteEbookiTytuly[] = $tytul;
            }
            continue;
        }
    }

    try {
        // ebook_zapisz() zakłada brakujące tagi/półki i pilnuje właściciela
        // wiersza — te same reguły co przy imporcie z folderu.
        ebook_zapisz($pdo, $userId, $dane);
        $dodaneEbooki++;
    } catch (Throwable $e) {
        // Błąd bazy przy jednej pozycji nie może wywalić całego importu.
        $bledyEbooki[] = sprintf('„%s”: %s', $tytul, $e->getMessage());
        if (count($bledyEbooki) >= 8) {
            $bledyEbooki[] = '…';
            break;
        }
    }
}

json_out([
    'ok'           => true,
    'tryb'         => $tryb,
    'dodane'       => $dodane,
    'pominiete'    => $pominiete,
    'pominiete_tytuly' => $pominieteTytuly,
    'bledy'        => $bledy,
    'wczytano'     => count($r['ksiazki']),
    'dodane_ebooki'    => $dodaneEbooki,
    'pominiete_ebooki' => $pominieteEbooki,
    'pominiete_ebooki_tytuly' => $pominieteEbookiTytuly,
    'bledy_ebooki'     => $bledyEbooki,
    'wczytano_ebooki'  => count($r['ebooki'] ?? []),
    'wypozyczenia' => count($r['wypozyczenia']),
]);
