<?php
declare(strict_types=1);

/**
 * Lista katalogu ebooków. Cała logika filtrów, sortowania i paginacji siedzi
 * w ebook_lista() — endpoint tylko zbiera parametry GET i zwraca gotowy JSON.
 *
 * GET /api/ebooki.php?q=...&status=...&format=...&tag=...&polka=...
 *                     &sort=dodania|tytul|autor|rok[ _desc]&strona=1
 */

require_once __DIR__ . '/../config.php';

auth_require();

$metoda = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($metoda !== 'GET') {
    json_fail('Metoda niedozwolona.', 405);
}

$filtry = [
    'q'      => (string) ($_GET['q'] ?? ''),
    'status' => (string) ($_GET['status'] ?? ''),
    'format' => (string) ($_GET['format'] ?? ''),
    'tag'    => (string) ($_GET['tag'] ?? ''),
    'polka'  => (string) ($_GET['polka'] ?? ''),
    'sort'   => (string) ($_GET['sort'] ?? 'dodania_desc'),
    'strona' => (int) ($_GET['strona'] ?? 1),
];

json_out(ebook_lista(db(), auth_user_id(), $filtry));
