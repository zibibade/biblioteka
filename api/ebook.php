<?php
declare(strict_types=1);

/**
 * Szczegóły jednego ebooka: wiersz, tagi, półki i powiązana książka papierowa.
 * ebook_szczegol() zwraca null także wtedy, gdy rekord należy do kogoś innego —
 * dlatego brak i brak dostępu dają ten sam 404.
 *
 * GET /api/ebook.php?id=123
 */

require_once __DIR__ . '/../config.php';

auth_require();

$metoda = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($metoda !== 'GET') {
    json_fail('Metoda niedozwolona.', 405);
}

$id = (int) ($_GET['id'] ?? 0);
if ($id < 1) {
    json_fail('Brak identyfikatora ebooka.');
}

$wynik = ebook_szczegol(db(), auth_user_id(), $id);
if ($wynik === null) {
    json_fail('Nie znaleziono ebooka.', 404);
}

json_out($wynik);
