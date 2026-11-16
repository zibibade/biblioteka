<?php
declare(strict_types=1);

/**
 * Logowanie jednym hasłem. Sesja cookie ważna 24 h.
 * POST /api/login.php  { "haslo": "..." }
 */

require_once __DIR__ . '/../config.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    json_fail('Metoda niedozwolona.', 405);
}

$wejscie = json_decode((string) file_get_contents('php://input'), true);
if (!is_array($wejscie)) {
    json_fail('Oczekiwano JSON.');
}

$blad = auth_login((string) ($wejscie['haslo'] ?? ''));
if ($blad !== null) {
    json_fail($blad, 401);
}

json_out(['ok' => true, 'wersja' => APP_VERSION]);
