<?php
declare(strict_types=1);

/**
 * Budowanie config.php ze wzoru (config.example.php).
 *
 * Wspólne dla instalatora w przeglądarce (`install.php`) i dla obrazu Dockera
 * (`docker/init-config.php`) — dzięki temu podmiana wartości istnieje w jednym
 * miejscu i oba wejścia dają identyczny plik konfiguracji.
 */

/**
 * @param array<string, string> $wartosci APP_VERSION, DB_HOST, DB_PORT, DB_NAME,
 *                                       DB_USER, DB_PASS, APP_PASSWORD, GOOGLE_BOOKS_KEY
 * @throws RuntimeException gdy wzór nie zawiera którejś ze stałych
 */
function bib_zbuduj_config(string $sciezkaWzoru, array $wartosci): string
{
    $tresc = @file_get_contents($sciezkaWzoru);
    if ($tresc === false || $tresc === '') {
        throw new RuntimeException("Nie mogę odczytać wzoru konfiguracji: {$sciezkaWzoru}");
    }

    foreach ($wartosci as $stala => $wartosc) {
        $bezpieczna = addcslashes((string) $wartosc, "\\'");
        $tresc = (string) preg_replace(
            "/^const {$stala} = '.*';$/m",
            "const {$stala} = '{$bezpieczna}';",
            $tresc,
            1,
            $ile
        );
        if ($ile !== 1) {
            throw new RuntimeException("Wzór konfiguracji nie zawiera stałej {$stala}.");
        }
    }

    return $tresc;
}
