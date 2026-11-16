<?php
declare(strict_types=1);

/**
 * Wylogowanie — kasuje sesję z bazy i czyści cookie.
 * POST /api/logout.php
 */

require_once __DIR__ . '/../config.php';

auth_logout();
json_out(['ok' => true]);
