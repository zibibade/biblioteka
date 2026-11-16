<?php
declare(strict_types=1);

/**
 * Start kontenera: konfiguracja z zmiennych środowiskowych + utworzenie tabel.
 *
 * Uruchamiane przez docker/entrypoint.sh. Nie robi nic, jeśli config.php już
 * istnieje (wolumen z konfiguracją) — wtedy tylko czeka na bazę i dopina tabele.
 */

require_once __DIR__ . '/../lib/konfiguracja.php';

$katalog = dirname(__DIR__);
$config = $katalog . '/config.php';

/** @return string wartość zmiennej środowiskowej albo wartość domyślna */
function env(string $nazwa, string $domyslna = ''): string
{
    $v = getenv($nazwa);
    return $v === false || $v === '' ? $domyslna : (string) $v;
}

if (!file_exists($config)) {
    $brakuje = [];
    foreach (['BIB_DB_HOST', 'BIB_DB_NAME', 'BIB_DB_USER', 'BIB_APP_PASSWORD'] as $zmienna) {
        if (env($zmienna) === '') {
            $brakuje[] = $zmienna;
        }
    }
    if ($brakuje !== []) {
        // Bez konfiguracji i bez zmiennych środowiskowych zostaje kreator w przeglądarce.
        echo "[biblioteka] Brak zmiennych " . implode(', ', $brakuje) . " — konfiguracji nie tworzę.\n";
        echo "[biblioteka] Otwórz /install.php w przeglądarce i wypełnij kreator.\n";
        exit(0);
    }

    $tresc = bib_zbuduj_config($katalog . '/config.example.php', [
        'APP_VERSION'      => env('BIB_APP_VERSION', '1.1.0'),
        'DB_HOST'          => env('BIB_DB_HOST'),
        'DB_PORT'          => env('BIB_DB_PORT', '3306'),
        'DB_NAME'          => env('BIB_DB_NAME'),
        'DB_USER'          => env('BIB_DB_USER'),
        'DB_PASS'          => env('BIB_DB_PASS'),
        'APP_PASSWORD'     => env('BIB_APP_PASSWORD'),
        'GOOGLE_BOOKS_KEY' => env('BIB_GOOGLE_KEY'),
    ]);

    if (file_put_contents($config, $tresc) === false) {
        fwrite(STDERR, "Nie mogę zapisać config.php w {$katalog}.\n");
        exit(1);
    }
    // Plik powstaje jako root (entrypoint), a czyta go Apache jako www-data.
    @chown($config, 'www-data');
    @chgrp($config, 'www-data');
    chmod($config, 0640);
    echo "[biblioteka] config.php utworzony ze zmiennych środowiskowych\n";
}

// Baza startuje równolegle z aplikacją — czekamy, aż odpowie.
require_once $config;                      // definiuje db() i stałe (tylko raz)
$proby = (int) env('BIB_DB_PROBY', '40');
$pdo = null;
for ($i = 1; $i <= $proby; $i++) {
    try {
        $pdo = db();                            // tworzy bazę (jeśli wolno) i tabele
        break;
    } catch (Throwable $e) {
        if ($i === $proby) {
            fwrite(STDERR, "[biblioteka] baza nie odpowiada po {$proby} próbach: " . $e->getMessage() . "\n");
            exit(1);
        }
        sleep(2);
    }
}

$ile = count($pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN));
echo "[biblioteka] baza gotowa, tabel: {$ile}\n";
