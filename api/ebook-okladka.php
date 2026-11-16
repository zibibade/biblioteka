<?php
declare(strict_types=1);

/**
 * Miniatura okładki ebooka. Do serwera trafia tylko obrazek — plik ebooka
 * nigdy nie opuszcza maszyny użytkownika.
 *
 * POST /api/ebook-okladka.php  multipart: id, okladka
 */

require_once __DIR__ . '/../config.php';

// Stałe lokalne: spec S4 nie pozwala ruszać config.php, a katalog jest
// używany wyłącznie przez ten endpoint.
const EBOOK_OKLADKI_DIR = UPLOAD_DIR . '/okladki-ebooki';
const EBOOK_OKLADKI_URL = UPLOAD_URL . '/okladki-ebooki';
const EBOOK_OKLADKA_BYTES = 2 * 1024 * 1024;
const EBOOK_OKLADKA_MIME = [
    'image/jpeg' => 'jpg',
    'image/png'  => 'png',
    'image/webp' => 'webp',
];

auth_require();
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    json_fail('Metoda niedozwolona.', 405);
}

$id = (int) ($_POST['id'] ?? 0);
if ($id < 1) {
    json_fail('Brak identyfikatora ebooka.');
}

$userId = auth_user_id();
$st = db()->prepare('SELECT id, okladka FROM bib_ebooki WHERE id = ? AND user_id = ?');
$st->execute([$id, $userId]);
$wiersz = $st->fetch();
if ($wiersz === false) {
    json_fail('Nie znaleziono ebooka.', 404);
}

// Tylko nazwa pliku — katalog bierzemy ze stałej, żeby ścieżka z bazy
// nie mogła wskazać niczego poza uploads/.
$staraOkladka = $wiersz['okladka'] !== null ? basename((string) $wiersz['okladka']) : null;

$plik = $_FILES['okladka'] ?? null;
$kodBledu = is_array($plik) ? (int) ($plik['error'] ?? UPLOAD_ERR_NO_FILE) : -1;
if (!is_array($plik) || $kodBledu !== UPLOAD_ERR_OK) {
    json_fail('Nie przesłano okładki (kod błędu uploadu: ' . $kodBledu . ').');
}
if ((int) $plik['size'] <= 0 || (int) $plik['size'] > EBOOK_OKLADKA_BYTES) {
    json_fail('Okładka jest pusta albo za duża (max 2 MB).');
}

// MIME z zawartości, nie z nagłówka żądania — rozszerzenie z nazwy pliku
// użytkownika nic nie gwarantuje.
$finfo = new finfo(FILEINFO_MIME_TYPE);
$mime = (string) $finfo->file((string) $plik['tmp_name']);
if (!isset(EBOOK_OKLADKA_MIME[$mime])) {
    json_fail('Niedozwolony format okładki (JPG, PNG lub WEBP).');
}

if (!is_dir(EBOOK_OKLADKI_DIR) && !@mkdir(EBOOK_OKLADKI_DIR, 0775, true)) {
    json_fail('Nie można utworzyć katalogu na okładki.', 500);
}

// Katalog jest zapisywalny dla PHP, więc blokujemy wykonanie skryptów
// i podgląd zawartości — tak jak w uploads/.htaccess.
$htaccess = EBOOK_OKLADKI_DIR . '/.htaccess';
if (!is_file($htaccess)) {
    @file_put_contents($htaccess, "Require all denied\nphp_flag engine off\n");
}

$nazwa = bin2hex(random_bytes(16)) . '.' . EBOOK_OKLADKA_MIME[$mime];
if (!move_uploaded_file((string) $plik['tmp_name'], EBOOK_OKLADKI_DIR . '/' . $nazwa)) {
    json_fail('Nie udało się zapisać okładki.', 500);
}

// Starą miniaturę kasujemy dopiero po udanym zapisie nowej
if ($staraOkladka !== null && is_file(EBOOK_OKLADKI_DIR . '/' . $staraOkladka)) {
    @unlink(EBOOK_OKLADKI_DIR . '/' . $staraOkladka);
}

db()->prepare('UPDATE bib_ebooki SET okladka = ?, data_edycji = NOW() WHERE id = ? AND user_id = ?')
    ->execute([EBOOK_OKLADKI_URL . '/' . $nazwa, $id, $userId]);

json_out(['ok' => true, 'okladka' => EBOOK_OKLADKI_URL . '/' . $nazwa]);
