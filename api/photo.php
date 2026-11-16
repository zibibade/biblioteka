<?php
declare(strict_types=1);

/**
 * Upload zdjęcia własnego egzemplarza (aparat telefonu).
 * POST multipart: plik + id książki. Opcjonalnie usun=1 — usuwa zdjęcie.
 *
 * POST /api/photo.php
 */

require_once __DIR__ . '/../config.php';

auth_require();
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    json_fail('Metoda niedozwolona.', 405);
}

$id = (int) ($_POST['id'] ?? 0);
if ($id < 1) {
    json_fail('Brak identyfikatora książki.');
}

$stmt = db()->prepare('SELECT id, zdjecie FROM bib_ksiazki WHERE id = ? AND user_id = ?');
$stmt->execute([$id, auth_user_id()]);
$wiersz = $stmt->fetch();
if ($wiersz === false) {
    json_fail('Nie znaleziono książki.', 404);
}

$stareZdjecie = $wiersz['zdjecie'] !== null ? basename((string) $wiersz['zdjecie']) : null;

// Usuwanie zdjęcia
if (isset($_POST['usun'])) {
    if ($stareZdjecie !== null && is_file(ZDJECIA_DIR . '/' . $stareZdjecie)) {
        @unlink(ZDJECIA_DIR . '/' . $stareZdjecie);
    }
    db()->prepare('UPDATE bib_ksiazki SET zdjecie = NULL, data_edycji = NOW() WHERE id = ? AND user_id = ?')
        ->execute([$id, auth_user_id()]);
    json_out(['ok' => true, 'zdjecie' => null]);
}

// --- walidacja uploadu ---
$plik = $_FILES['plik'] ?? null;
if (!is_array($plik) || ($plik['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
    json_fail('Nie przesłano pliku (kod błędu uploadu: ' . (int) ($plik['error'] ?? -1) . ').');
}
if ((int) $plik['size'] <= 0 || (int) $plik['size'] > MAX_PHOTO_BYTES) {
    json_fail('Plik jest pusty albo za duży (max ' . (int) (MAX_PHOTO_BYTES / 1024 / 1024) . ' MB).');
}

$finfo = new finfo(FILEINFO_MIME_TYPE);
$mime = (string) $finfo->file((string) $plik['tmp_name']);
if (!isset(ALLOWED_PHOTO_MIME[$mime])) {
    json_fail('Niedozwolony format pliku (JPG, PNG lub WEBP).');
}

if (!is_dir(ZDJECIA_DIR) && !@mkdir(ZDJECIA_DIR, 0775, true)) {
    json_fail('Nie można utworzyć katalogu na zdjęcia.', 500);
}

$nazwa = bin2hex(random_bytes(16)) . '.' . ALLOWED_PHOTO_MIME[$mime];
$cel = ZDJECIA_DIR . '/' . $nazwa;
if (!move_uploaded_file((string) $plik['tmp_name'], $cel)) {
    json_fail('Nie udało się zapisać pliku.', 500);
}

// Stare zdjęcie kasujemy dopiero po udanym zapisie nowego
if ($stareZdjecie !== null && is_file(ZDJECIA_DIR . '/' . $stareZdjecie)) {
    @unlink(ZDJECIA_DIR . '/' . $stareZdjecie);
}

db()->prepare('UPDATE bib_ksiazki SET zdjecie = ?, data_edycji = NOW() WHERE id = ? AND user_id = ?')
    ->execute([ZDJECIA_URL . '/' . $nazwa, $id, auth_user_id()]);

json_out(['ok' => true, 'zdjecie' => ZDJECIA_URL . '/' . $nazwa]);
