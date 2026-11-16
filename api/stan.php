<?php
declare(strict_types=1);

/**
 * Stan aplikacji dla frontendu przy starcie — bez wymogu logowania.
 * GET /api/stan.php
 */

require_once __DIR__ . '/../config.php';

json_out([
    'zalogowany' => auth_is_authenticated(),
    'wersja'     => APP_VERSION,
    'statusy'    => STATUSY,
]);
