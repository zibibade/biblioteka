<?php
declare(strict_types=1);

/**
 * Import metadanych ebooków z folderu wskazanego w przeglądarce. Klient
 * wysyła tylko opis plików (hash, nazwa, format) — same pliki zostają na
 * jego dysku. Walidacja, deduplikacja i zapis siedzą w lib/logika.php.
 *
 * POST /api/ebook-import.php  { folder, pozycje: [ {tytul, autor, ..., hash_pliku, nazwa_pliku, format} ] }
 */

require_once __DIR__ . '/../config.php';

auth_require();
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    json_fail('Metoda niedozwolona.', 405);
}

$wejscie = json_decode((string) file_get_contents('php://input'), true);
if (!is_array($wejscie)) {
    json_fail('Oczekowano JSON.');
}

$pozycje = $wejscie['pozycje'] ?? null;
if (!is_array($pozycje)) {
    json_fail('Brak listy pozycji do importu.');
}

// Limit chroni jeden request przed setkami zapytań; JS wysyła partie po 25.
if (count($pozycje) > 500) {
    json_fail('Maksymalnie 500 pozycji na jedno żądanie.', 422);
}

// Folder to etykieta od użytkownika (np. „Ebooki"), nie ścieżka dyskowa.
$folder = niepuste($wejscie['folder'] ?? null);
if ($folder !== null && mb_strlen($folder) > 120) {
    json_fail('Nazwa folderu jest za długa (max 120 znaków).', 422);
}

$userId = auth_user_id();
$pdo = db();

// Endpoint ma wyrobić się w max_execution_time — przy 500 pozycjach zapis
// z tagami to wiele zapytań, więc po przekroczeniu budżetu przerywamy partię
// i oddajemy raport zamiast błędu 500 w połowie.
$start = microtime(true);
$limitS = (int) ini_get('max_execution_time');
$budzet = $limitS > 0 ? max(1, $limitS - 10) : 0;

$dodano = 0;
$duplikaty = [];
$bledy = [];
$nieprzetworzone = 0;

$powody = [
    'hash'  => 'Ten plik już jest w katalogu (ten sam hash).',
    'isbn'  => 'Książka z tym ISBN już jest w katalogu.',
    'tytul' => 'Ten sam tytuł i autor już są w katalogu.',
];

$numer = 0;
foreach ($pozycje as $poz) {
    if ($budzet > 0 && (microtime(true) - $start) > $budzet) {
        $nieprzetworzone = count($pozycje) - $numer;
        break;
    }
    $numer++;

    if (!is_array($poz)) {
        $bledy[] = ['tytul' => '?', 'powod' => 'Pozycja nie jest obiektem.'];
        continue;
    }

    $tytul = niepuste($poz['tytul'] ?? null) ?? '?';

    ['dane' => $dane, 'bledy' => $problemy] = ebook_waliduj($poz);
    if ($problemy !== []) {
        $bledy[] = ['tytul' => $tytul, 'powod' => implode(' ', $problemy)];
        continue;
    }

    // Import zawsze znaczy się źródłem, a folder bierzemy z żądania —
    // pojedyncza pozycja nie może podmienić etykiety partii.
    $dane['zrodlo'] = 'import';
    if ($folder !== null) {
        $dane['folder'] = $folder;
    }

    // FK pilnuje istnienia wiersza, nie właściciela — bez tego sprawdzenia
    // dałoby się podpiąć cudzą książkę papierową.
    if ($dane['powiazana_ksiazka_id'] !== null) {
        $st = $pdo->prepare('SELECT id FROM bib_ksiazki WHERE id = ? AND user_id = ?');
        $st->execute([(int) $dane['powiazana_ksiazka_id'], $userId]);
        if ($st->fetchColumn() === false) {
            $bledy[] = ['tytul' => $tytul, 'powod' => 'Nie znaleziono powiązanej książki.'];
            continue;
        }
    }

    $duplikat = ebook_duplikat($pdo, $userId, $dane);
    if ($duplikat !== null) {
        $duplikaty[] = [
            'tytul' => $tytul,
            'powod' => $powody[$duplikat['powod']] ?? 'Pozycja już jest w katalogu.',
        ];
        continue;
    }

    try {
        ebook_zapisz($pdo, $userId, $dane);
        $dodano++;
    } catch (Throwable $e) {
        // Błąd bazy przy jednej pozycji nie może wywalić całej partii.
        $bledy[] = ['tytul' => $tytul, 'powod' => 'Nie udało się zapisać: ' . $e->getMessage()];
    }
}

json_out([
    'ok'             => true,
    'dodano'         => $dodano,
    'pominietych'    => count($duplikaty),
    'bledow'         => count($bledy),
    'duplikaty'      => $duplikaty,
    'bledy'          => $bledy,
    'przerwano'      => $nieprzetworzone > 0,
    'nieprzetworzone' => $nieprzetworzone,
]);