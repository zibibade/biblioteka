<?php
declare(strict_types=1);

/**
 * Biblioteka — konfiguracja i funkcje wspólne.
 *
 * Ten plik to WZÓR z placeholderami. Skopiuj go do config.php i uzupełnij
 * wartości oznaczone 'UZUPELNIJ'. Plik config.php NIGDY nie trafia do
 * repozytorium (patrz .gitignore) — trzyma hasła bazy i dostęp do aplikacji.
 */

/* -------------------------------------------------------------- wersja */
// Podbijaj X.Y.Z przy KAŻDEJ zmianie (nawet kosmetycznej) + wpis w CHANGELOG.md
const APP_VERSION = '1.1.1';

/* --------------------------------------------------- baza danych (MySQL) */
const DB_HOST = 'localhost';
const DB_PORT = '3306';
const DB_NAME = 'UZUPELNIJ';
const DB_USER = 'UZUPELNIJ';
const DB_PASS = 'UZUPELNIJ';

/* ------------------------------------------------------------- dostęp */
// Jedno hasło do całej aplikacji; sesja cookie ważna 24 godziny
const APP_PASSWORD = 'UZUPELNIJ';
const AUTH_COOKIE = 'biblioteka_sess';
const SESSION_TTL = 24 * 3600;
const LOGIN_MAX_ATTEMPTS = 5;
const LOGIN_ATTEMPT_WINDOW = 15 * 60;

/* ------------------------------------------------- Google Books API (opc.) */
// Klucz darmowy z Google Cloud Console. Trzymaj TYLKO tutaj — nigdy w gicie.
// Bez klucza lookup działa, ale z niższym limitem zapytań.
const GOOGLE_BOOKS_KEY = '';

/* ------------------------------------------------------- lookup / cache */
// Ile dni w bazie leży wpis „nie znaleziono tego ISBN”. Po tym czasie
// skanowanie pyta znowu — inaczej książka, której źródła nie znały w dniu
// poprowadzenia, nigdy by się nie odnalazła. 0 = brak TTL (zachowanie stare).
const LOOKUP_CACHE_BRAK_DNI = 30;

/* ------------------------------------------------------------ uploady */
const UPLOAD_DIR = __DIR__ . '/uploads';
const UPLOAD_URL = 'uploads';
const OKLADKI_DIR = UPLOAD_DIR . '/okladki';
const OKLADKI_URL = UPLOAD_URL . '/okladki';
const ZDJECIA_DIR = UPLOAD_DIR . '/zdjecia';
const ZDJECIA_URL = UPLOAD_URL . '/zdjecia';
const MAX_PHOTO_BYTES = 10 * 1024 * 1024; // 10 MB na zdjęcie egzemplarza
const ALLOWED_PHOTO_MIME = [
    'image/jpeg' => 'jpg',
    'image/png' => 'png',
    'image/webp' => 'webp',
];

/* ----------------------------------------------------------- domenowe */
// Statusy czytania (identyfikatory bez polskich znaków — konwencja workspace)
const STATUSY = ['chce_przeczytac', 'w_trakcie', 'przeczytane'];

require_once __DIR__ . '/lib/logika.php';

/* ============================================================ odpowiedzi */

function json_out(mixed $data, int $httpCode = 200): never
{
    http_response_code($httpCode);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function json_fail(string $error, int $httpCode = 400): never
{
    json_out(['blad' => $error], $httpCode);
}

/* =========================================================== autoryzacja */

/** Czy żądanie idzie po HTTPS (liczymy też nagłówek reverse proxy). */
function auth_is_https(): bool
{
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
}

/** Opcje cookie sesji (wspólne dla ustawiania i kasowania). */
function auth_cookie_options(int $expires): array
{
    return [
        'expires'  => $expires,
        'path'     => '/',
        'httponly' => true,
        'samesite' => 'Lax',
        'secure'   => auth_is_https(),
    ];
}

/** Token cookie ma kształt 64 znaków hex — odrzucamy śmieci zanim pójdziemy do bazy. */
function auth_cookie_plausible(string $token): bool
{
    return preg_match('/^[a-f0-9]{64}$/', $token) === 1;
}

/**
 * Aktywna sesja z bazy albo null. Token trzymamy wyłącznie jako hash
 * (wyciek bazy nie daje możliwości przejęcia sesji). Sesja wygasa
 * po SESSION_TTL (24 h) — bez przedłużania (jak w serwisie rowerowym).
 */
function auth_sesja(): ?array
{
    static $cache = null;
    static $loaded = false;
    if ($loaded) {
        return $cache;
    }
    $loaded = true;

    $token = (string) ($_COOKIE[AUTH_COOKIE] ?? '');
    if ($token === '' || !auth_cookie_plausible($token)) {
        return null;
    }
    $stmt = db()->prepare('SELECT id, user_id, expires_at FROM bib_sesje WHERE token_hash = ?');
    $stmt->execute([hash('sha256', $token)]);
    $row = $stmt->fetch();
    if (!$row) {
        return null;
    }
    if ((string) $row['expires_at'] <= date('Y-m-d H:i:s')) {
        db()->prepare('DELETE FROM bib_sesje WHERE id = ?')->execute([(int) $row['id']]);
        return null;
    }
    $cache = $row;
    return $cache;
}

function auth_is_authenticated(): bool
{
    return auth_sesja() !== null;
}

/** user_id bieżącego użytkownika (na razie zawsze 1 — konta dopiero w v2). */
function auth_user_id(): int
{
    $s = auth_sesja();
    return $s !== null ? (int) $s['user_id'] : 1;
}

/** Guard endpointów API — przerywa żądanie 401, gdy brak sesji. */
function auth_require(): void
{
    if (!auth_is_authenticated()) {
        json_fail('Brak autoryzacji — zaloguj się.', 401);
    }
}

/** Czy klucz logowania przekroczył limit nieudanych prób?
 *  Liczymy wpisy z okna LOGIN_ATTEMPT_WINDOW (15 min) — po tym czasie stare
 *  próby przestają blokować (sprząta je auth_login przed sprawdzeniem).
 *  Limit: LOGIN_MAX_ATTEMPTS = 5 na adres IP. */
function login_blocked(string $key): bool
{
    $stmt = db()->prepare('SELECT COUNT(*) FROM bib_login_attempts WHERE klucz = ? AND created_at > ?');
    $stmt->execute([$key, date('Y-m-d H:i:s', time() - LOGIN_ATTEMPT_WINDOW)]);
    return (int) $stmt->fetchColumn() >= LOGIN_MAX_ATTEMPTS;
}

/**
 * Logowanie jednym hasłem. Zwraca null przy sukcesie albo komunikat błędu
 * (uniwersalny — nie zdradzamy, czy hasło się zgadza co do znaku).
 */
function auth_login(string $haslo): ?string
{
    $key = (string) ($_SERVER['REMOTE_ADDR'] ?? '');

    db()->exec('DELETE FROM bib_sesje WHERE expires_at < NOW()');
    db()->prepare('DELETE FROM bib_login_attempts WHERE created_at < ?')
        ->execute([date('Y-m-d H:i:s', time() - LOGIN_ATTEMPT_WINDOW)]);
    if (login_blocked($key)) {
        return 'Zbyt wiele nieudanych prób — spróbuj ponownie za kilka minut.';
    }

    if (APP_PASSWORD === 'UZUPELNIJ' || !hash_equals(APP_PASSWORD, $haslo)) {
        db()->prepare('INSERT INTO bib_login_attempts (klucz) VALUES (?)')->execute([$key]);
        return 'Nieprawidłowe hasło.';
    }
    db()->prepare('DELETE FROM bib_login_attempts WHERE klucz = ?')->execute([$key]);

    $token = bin2hex(random_bytes(32));
    db()->prepare('INSERT INTO bib_sesje (token_hash, user_id, expires_at) VALUES (?, 1, ?)')
        ->execute([hash('sha256', $token), date('Y-m-d H:i:s', time() + SESSION_TTL)]);
    setcookie(AUTH_COOKIE, $token, auth_cookie_options(time() + SESSION_TTL));
    return null;
}

/** Wylogowanie — kasuje sesję z bazy i czyści cookie. */
function auth_logout(): void
{
    $token = (string) ($_COOKIE[AUTH_COOKIE] ?? '');
    if ($token !== '' && auth_cookie_plausible($token)) {
        db()->prepare('DELETE FROM bib_sesje WHERE token_hash = ?')->execute([hash('sha256', $token)]);
    }
    setcookie(AUTH_COOKIE, '', auth_cookie_options(time() - 3600));
}

/* ================================================================ baza */

/** @return PDO połączenie z bazą (auto-tworzenie bazy, tabel i migracji) */
function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $dsn = sprintf('mysql:host=%s;port=%s;charset=utf8mb4', DB_HOST, DB_PORT);
    $pdo = new PDO($dsn, DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $pdo->exec(sprintf(
        'CREATE DATABASE IF NOT EXISTS `%s` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci',
        str_replace('`', '', DB_NAME)
    ));
    $pdo->exec(sprintf('USE `%s`', str_replace('`', '', DB_NAME)));

    db_tabele($pdo);
    db_migracje($pdo);

    return $pdo;
}

/** Tworzenie schematu przy pierwszym połączeniu (brak osobnego pliku SQL). */
function db_tabele(PDO $pdo): void
{
    // Sesje logowania (token trzymany jako hash;24 h ważności)
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS bib_sesje (
            id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
            token_hash CHAR(64) NOT NULL,
            user_id    INT UNSIGNED NOT NULL DEFAULT 1,
            expires_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uq_token (token_hash),
            KEY idx_expires (expires_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );

    // Limit nieudanych prób logowania (klucz = adres IP)
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS bib_login_attempts (
            id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
            klucz      VARCHAR(160) NOT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_klucz_czas (klucz, created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );

    // Katalog — jeden wiersz = jeden fizyczny egzemplarz
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS bib_ksiazki (
            id               INT UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id          INT UNSIGNED NOT NULL DEFAULT 1,
            isbn             VARCHAR(20) NULL,
            tytul            VARCHAR(500) NOT NULL,
            podtytul         VARCHAR(500) NULL,
            autor            VARCHAR(300) NOT NULL DEFAULT "",
            rok              VARCHAR(10) NULL,
            wydawca          VARCHAR(200) NULL,
            liczba_stron     SMALLINT UNSIGNED NULL,
            jezyk            VARCHAR(20) NULL,
            oryginalny_tytul VARCHAR(500) NULL,
            opis             TEXT NULL,
            okladka          VARCHAR(255) NULL,
            zdjecie          VARCHAR(255) NULL,
            status           VARCHAR(20) NOT NULL DEFAULT "chce_przeczytac",
            notatki          TEXT NULL,
            zrodlo           VARCHAR(20) NOT NULL DEFAULT "recznie",
            data_dodania     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            data_edycji      DATETIME NULL,
            PRIMARY KEY (id),
            KEY idx_user (user_id),
            KEY idx_isbn (isbn),
            KEY idx_tytul (tytul),
            KEY idx_autor (autor),
            KEY idx_status (status),
            KEY idx_dodania (data_dodania)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );

    // Cache lookupów ISBN — powtórny skan tej samej książki nie woła API zewnętrznych
    // (dane = JSON metryczki albo NULL = brak w bazach; wtedy też nie pytamy ponownie)
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS bib_isbn_cache (
            isbn    VARCHAR(20) NOT NULL,
            zrodlo  VARCHAR(20) NOT NULL DEFAULT "",
            dane    TEXT NULL,
            pobrano DATETIME NOT NULL,
            PRIMARY KEY (isbn)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );

    // Tagi — słownik etykiet (fantastyka, do przeczytania, pożyczone…).
    // Nazwa w małych literach: dzięki temu „SciFi” i „scifi” to jeden tag.
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS bib_tagi (
            id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id    INT UNSIGNED NOT NULL DEFAULT 1,
            nazwa      VARCHAR(60) NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_user_nazwa (user_id, nazwa)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );

    // Półki — nazwane grupy książek (własny widok listy, bez kolejności na regale)
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS bib_polk (
            id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id    INT UNSIGNED NOT NULL DEFAULT 1,
            nazwa      VARCHAR(80) NOT NULL,
            opis       VARCHAR(255) NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_user_nazwa (user_id, nazwa)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );

    // Powiązania książka↔tag / książka↔półka (wiele do wielu).
    // ON DELETE CASCADE — usunięcie książki sprząta powiązania automatycznie.
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS bib_ksiazka_tagi (
            ksiazka_id INT UNSIGNED NOT NULL,
            tag_id     INT UNSIGNED NOT NULL,
            PRIMARY KEY (ksiazka_id, tag_id),
            KEY idx_tag (tag_id),
            CONSTRAINT fk_kt_ksiazka FOREIGN KEY (ksiazka_id)
                REFERENCES bib_ksiazki (id) ON DELETE CASCADE,
            CONSTRAINT fk_kt_tag FOREIGN KEY (tag_id)
                REFERENCES bib_tagi (id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS bib_ksiazka_polk (
            ksiazka_id INT UNSIGNED NOT NULL,
            polka_id   INT UNSIGNED NOT NULL,
            PRIMARY KEY (ksiazka_id, polka_id),
            KEY idx_polka (polka_id),
            CONSTRAINT fk_kp_ksiazka FOREIGN KEY (ksiazka_id)
                REFERENCES bib_ksiazki (id) ON DELETE CASCADE,
            CONSTRAINT fk_kp_polka FOREIGN KEY (polka_id)
                REFERENCES bib_polk (id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );

    // Wypożyczenia z historią — wiersz to jedno wypożyczenie konkretnego egzemplarza.
    // data_zwrotu NULL = książka wciąż wypożyczona; po zwrocie wiersz zostaje
    // (historia). Jedna książka może mieć najwyżej jedno aktywne wypożyczenie —
    // pilnuje tego UNIQUE na (ksiazka_id, data_wypozyczenia) + kontrola w kodzie
    // (UNIQUE z NULL nie działa w MySQL, bo NULL ≠ NULL).
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS bib_wypozyczenia (
            id                INT UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id           INT UNSIGNED NOT NULL DEFAULT 1,
            ksiazka_id        INT UNSIGNED NOT NULL,
            osoba             VARCHAR(120) NOT NULL,
            data_wypozyczenia DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            data_zwrotu       DATETIME NULL,
            uwagi             VARCHAR(500) NULL,
            PRIMARY KEY (id),
            KEY idx_ksiazka (ksiazka_id),
            KEY idx_aktywne (data_zwrotu),
            KEY idx_osoba (osoba),
            CONSTRAINT fk_wyp_ksiazka FOREIGN KEY (ksiazka_id)
                REFERENCES bib_ksiazki (id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );

    // Ebooki — drugi, równoległy katalog; pliki zostają u użytkownika, tu tylko metadane.
    // hash_pliku (SHA-256) służy do deduplikacji przy imporcie folderu, powiazana_ksiazka_id
    // wiąże ebooka z papierowym egzemplarzem (SET NULL — katalog papierowy jest nadrzędny).
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS bib_ebooki (
            id                   INT UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id              INT UNSIGNED NOT NULL DEFAULT 1,
            isbn                 VARCHAR(20) NULL,
            tytul                VARCHAR(500) NOT NULL,
            podtytul             VARCHAR(500) NULL,
            autor                VARCHAR(300) NOT NULL DEFAULT "",
            rok                  VARCHAR(10) NULL,
            wydawca              VARCHAR(200) NULL,
            liczba_stron         SMALLINT UNSIGNED NULL,
            jezyk                VARCHAR(20) NULL,
            oryginalny_tytul     VARCHAR(500) NULL,
            opis                 TEXT NULL,
            okladka              VARCHAR(255) NULL,
            format               VARCHAR(10) NOT NULL DEFAULT "epub",
            status               VARCHAR(20) NOT NULL DEFAULT "chce_przeczytac",
            ocena                TINYINT UNSIGNED NULL,
            notatki              TEXT NULL,
            hash_pliku           CHAR(64) NULL,
            nazwa_pliku          VARCHAR(255) NULL,
            folder               VARCHAR(120) NULL,
            powiazana_ksiazka_id INT UNSIGNED NULL,
            zrodlo               VARCHAR(20) NOT NULL DEFAULT "recznie",
            data_dodania         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            data_edycji          DATETIME NULL,
            PRIMARY KEY (id),
            KEY idx_user (user_id),
            KEY idx_isbn (isbn),
            KEY idx_hash (hash_pliku),
            KEY idx_tytul (tytul),
            KEY idx_autor (autor),
            KEY idx_status (status),
            KEY idx_format (format),
            KEY idx_powiazana (powiazana_ksiazka_id),
            CONSTRAINT fk_eb_ksiazka FOREIGN KEY (powiazana_ksiazka_id)
                REFERENCES bib_ksiazki (id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );

    // Powiązania ebook↔tag / ebook↔półka — osobne tabele, bo katalogi są niezależne.
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS bib_ebook_tagi (
            ebook_id INT UNSIGNED NOT NULL,
            tag_id   INT UNSIGNED NOT NULL,
            PRIMARY KEY (ebook_id, tag_id),
            KEY idx_tag (tag_id),
            CONSTRAINT fk_et_ebook FOREIGN KEY (ebook_id)
                REFERENCES bib_ebooki (id) ON DELETE CASCADE,
            CONSTRAINT fk_et_tag FOREIGN KEY (tag_id)
                REFERENCES bib_tagi (id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS bib_ebook_polk (
            ebook_id INT UNSIGNED NOT NULL,
            polka_id INT UNSIGNED NOT NULL,
            PRIMARY KEY (ebook_id, polka_id),
            KEY idx_polka (polka_id),
            CONSTRAINT fk_ep_ebook FOREIGN KEY (ebook_id)
                REFERENCES bib_ebooki (id) ON DELETE CASCADE,
            CONSTRAINT fk_ep_polka FOREIGN KEY (polka_id)
                REFERENCES bib_polk (id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );
}

/** Migracje przez SHOW COLUMNS — dopisujemy brakujące kolumny (jak w serwisie). */
function db_migracje(PDO $pdo): void
{
    db_kolumna_dodaj($pdo, 'bib_ksiazki', 'zdjecie', 'VARCHAR(255) NULL AFTER okladka');
    db_kolumna_dodaj($pdo, 'bib_ksiazki', 'jezyk', 'VARCHAR(20) NULL AFTER liczba_stron');
    db_kolumna_dodaj($pdo, 'bib_ksiazki', 'oryginalny_tytul', 'VARCHAR(500) NULL AFTER jezyk');
    db_kolumna_dodaj($pdo, 'bib_ksiazki', 'notatki', 'TEXT NULL AFTER status');
    // Metryczka egzemplarza (nie książki): format i szerokość oprawy
    db_kolumna_dodaj($pdo, 'bib_ksiazki', 'format', 'VARCHAR(20) NULL AFTER liczba_stron');
    db_kolumna_dodaj($pdo, 'bib_ksiazki', 'szerokosc', 'VARCHAR(20) NULL AFTER format');
    // Ocena własna 1–5 (NULL = nie oceniona)
    db_kolumna_dodaj($pdo, 'bib_ksiazki', 'ocena', 'TINYINT UNSIGNED NULL AFTER szerokosc');
}

function db_kolumna_dodaj(PDO $pdo, string $tabela, string $kolumna, string $definicja): void
{
    $st = $pdo->prepare(sprintf('SHOW COLUMNS FROM `%s` LIKE ?', $tabela));
    $st->execute([$kolumna]);
    if ($st->fetch() === false) {
        $pdo->exec(sprintf('ALTER TABLE `%s` ADD COLUMN `%s` %s', $tabela, $kolumna, $definicja));
    }
}

/* ================================================== zapis rekordu (DB) */

/**
 * Kolumny metryczki wraz z kolejnością — JEDNO ŹRÓDŁO dla INSERT i UPDATE.
 * Wcześniej lista była sklejana w trzech miejscach (save.php: update i insert
 * oraz import) i każde nowe pole trzeba było dopisać trzykrotnie; za pierwszym
 * razem zapomniałem o imporcie i format przepadał przy przywracaniu.
 */
function ksiazka_kolumny_db(): array
{
    return [
        'isbn', 'tytul', 'podtytul', 'autor', 'rok', 'wydawca', 'liczba_stron',
        'format', 'szerokosc', 'ocena', 'jezyk', 'oryginalny_tytul', 'opis',
        'okladka', 'zdjecie', 'status', 'notatki',
    ];
}

/** Wstawienie nowego rekordu; zwraca id. */
function ksiazka_wstaw(array $dane, int $userId, string $zrodlo): int
{
    $kolumny = ksiazka_kolumny_db();
    $sql = sprintf(
        'INSERT INTO bib_ksiazki (user_id, %s, zrodlo) VALUES (%s)',
        implode(', ', $kolumny),
        implode(', ', array_fill(0, count($kolumny) + 2, '?'))
    );
    $wartosci = [$userId];
    foreach ($kolumny as $k) {
        $wartosci[] = $dane[$k] ?? null;
    }
    $wartosci[] = $zrodlo;

    db()->prepare($sql)->execute($wartosci);
    return (int) db()->lastInsertId();
}

/** Aktualizacja istniejącego rekordu (właściciel sprawdzany w zapytaniu). */
function ksiazka_zaktualizuj(int $id, array $dane, int $userId): void
{
    $kolumny = ksiazka_kolumny_db();
    $sql = sprintf(
        'UPDATE bib_ksiazki SET %s, data_edycji = NOW() WHERE id = ? AND user_id = ?',
        implode(', ', array_map(static fn ($k) => "{$k} = ?", $kolumny))
    );
    $wartosci = [];
    foreach ($kolumny as $k) {
        $wartosci[] = $dane[$k] ?? null;
    }
    $wartosci[] = $id;
    $wartosci[] = $userId;

    db()->prepare($sql)->execute($wartosci);
}

/* ========================================================== import (DB) */

/** Rozwiązuje nazwy półek na id (tworzy brakujące). Zwraca [klucz => id]. */
function polki_id_z_nazw(array $nazwy, int $userId): array
{
    $mapy = [];
    if ($nazwy === []) {
        return $mapy;
    }
    $pdo = db();
    $znajdz = $pdo->prepare('SELECT id FROM bib_polk WHERE user_id = ? AND nazwa = ?');
    $dodaj = $pdo->prepare('INSERT IGNORE INTO bib_polk (user_id, nazwa) VALUES (?, ?)');
    foreach (polki_nazwy_normalizuj($nazwy) as $nazwa) {
        $klucz = mb_strtolower($nazwa);
        $znajdz->execute([$userId, $klucz]);
        $id = $znajdz->fetchColumn();
        if ($id === false) {
            $dodaj->execute([$userId, $klucz]);
            $id = (int) $pdo->lastInsertId();
        }
        $mapy[] = (int) $id;
    }
    return $mapy;
}

/**
 * Import z eksportu (round-trip). $tryb: 'scal' (dodaje brakujące, pomija
 * istniejące) albo 'przywroc' (najpierw czyści katalog). Zwraca podsumowanie.
 */
function import_egzemplarza(array $wejscie, array $tagi, array $polki, array $wypozyczenia, int $userId): array
{
    ['dane' => $dane, 'bledy' => $bledy] = ksiazka_waliduj($wejscie);
    if ($bledy !== []) {
        return ['ok' => false, 'blad' => implode(' ', $bledy)];
    }

    $pdo = db();
    $id = ksiazka_wstaw($dane, $userId, (string) $dane['zrodlo']);

    tagi_ksiazki_zapisz($id, $tagi, $userId);
    $idPolk = polki_id_z_nazw($polki, $userId);
    if ($idPolk !== []) {
        polki_ksiazki_zapisz($id, $idPolk, $userId);
    }

    foreach ($wypozyczenia as $w) {
        ['dane' => $dw, 'bledy' => $b] = wypozyczenie_waliduj($w + ['ksiazka_id' => $id]);
        if ($b !== []) {
            continue;   // wadliwy wpis nie blokuje reszty importu
        }
        $pdo->prepare(
            'INSERT INTO bib_wypozyczenia
                (user_id, ksiazka_id, osoba, data_wypozyczenia, data_zwrotu, uwagi)
             VALUES (?, ?, ?, ?, ?, ?)'
        )->execute([
            $userId, $id, $dw['osoba'], $dw['data_wypozyczenia'], $dw['data_zwrotu'], $dw['uwagi'],
        ]);
    }

    return ['ok' => true, 'id' => $id, 'klucz' => import_klucz($wejscie)];
}

/**
 * Usunięcie wszystkich danych użytkownika (tryb „przywróć").
 * Tabele powiązań (bib_ksiazka_tagi, bib_ksiazka_polk) nie mają user_id —
 * są właścicielem książki, więc czyszczą się przez kaskadę FK razem z nią.
 * Kolejność ma znaczenie tylko dla czytelności wyniku, nie dla poprawności.
 */
function import_katalog_wyczysc(int $userId): array
{
    $pdo = db();
    $wyczyszczone = [];
    foreach (['bib_wypozyczenia', 'bib_ksiazki', 'bib_tagi', 'bib_polk'] as $tabela) {
        $n = $pdo->prepare("DELETE FROM `{$tabela}` WHERE user_id = ?");
        $n->execute([$userId]);
        $wyczyszczone[$tabela] = $n->rowCount();
    }
    return $wyczyszczone;
}

/* ====================================================== tagi i półki (DB) */

/**
 * Tagi książki: tworzymy brakujące w słowniku, nadpisujemy powiązania.
 * Tagi podajemy jako nazwy — brakujące zakładamy „po drodze" (nie ma osobnego
 * ekranu zarządzania tagami, to wygodniejsze przy jednym katalogu).
 */
function tagi_ksiazki_zapisz(int $ksiazkaId, mixed $nazwy, int $userId): void
{
    $tagi = tagi_do_zapisu(tagi_normalizuj($nazwy)['tagi']);
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $pdo->prepare('DELETE FROM bib_ksiazka_tagi WHERE ksiazka_id = ?')->execute([$ksiazkaId]);
        $znajdz = $pdo->prepare('SELECT id FROM bib_tagi WHERE user_id = ? AND nazwa = ?');
        $dodaj   = $pdo->prepare('INSERT IGNORE INTO bib_tagi (user_id, nazwa) VALUES (?, ?)');
        $link    = $pdo->prepare('INSERT IGNORE INTO bib_ksiazka_tagi (ksiazka_id, tag_id) VALUES (?, ?)');

        foreach ($tagi as $t) {
            $znajdz->execute([$userId, $t['klucz']]);
            $id = $znajdz->fetchColumn();
            if ($id === false) {
                $dodaj->execute([$userId, $t['klucz']]);
                $id = (int) $pdo->lastInsertId();
            }
            $link->execute([$ksiazkaId, (int) $id]);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

/** Półki książki: podajemy id (lista albo tekst po przecinku) — cudze pomijamy. */
function polki_ksiazki_zapisz(int $ksiazkaId, mixed $idPolk, int $userId): void
{
    $idPolk = id_listy_normalizuj($idPolk);
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $pdo->prepare('DELETE FROM bib_ksiazka_polk WHERE ksiazka_id = ?')->execute([$ksiazkaId]);
        if ($idPolk !== []) {
            $sql = sprintf(
                'INSERT IGNORE INTO bib_ksiazka_polk (ksiazka_id, polka_id)
                 SELECT ?, id FROM bib_polk WHERE user_id = ? AND id IN (%s)',
                implode(',', array_fill(0, count($idPolk), '?'))
            );
            $pdo->prepare($sql)->execute(array_merge([$ksiazkaId, $userId], $idPolk));
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

/** Tagi książki jako nazwy (małe litery — tak, jak w słowniku). */
function tagi_ksiazki_pobierz(int $ksiazkaId): array
{
    $st = db()->prepare(
        'SELECT t.nazwa FROM bib_ksiazka_tagi kt
         JOIN bib_tagi t ON t.id = kt.tag_id
         WHERE kt.ksiazka_id = ?
         ORDER BY t.nazwa ASC'
    );
    $st->execute([$ksiazkaId]);
    return array_map('strval', $st->fetchAll(PDO::FETCH_COLUMN));
}

/** Półki książki jako ['id' => int, 'nazwa' => string, 'opis' => ?string]. */
function polki_ksiazki_pobierz(int $ksiazkaId): array
{
    $st = db()->prepare(
        'SELECT p.id, p.nazwa, p.opis FROM bib_ksiazka_polk kp
         JOIN bib_polk p ON p.id = kp.polka_id
         WHERE kp.ksiazka_id = ?
         ORDER BY p.nazwa ASC'
    );
    $st->execute([$ksiazkaId]);
    return $st->fetchAll();
}

/** Słownik tagów z liczbą książek (do filtrów i podpowiedzi w formularzu). */
function tagi_slownik(int $userId): array
{
    $st = db()->prepare(
        'SELECT t.nazwa, COUNT(kt.ksiazka_id) AS ile FROM bib_tagi t
         LEFT JOIN bib_ksiazka_tagi kt ON kt.tag_id = t.id
         WHERE t.user_id = ?
         GROUP BY t.id, t.nazwa
         ORDER BY t.nazwa ASC'
    );
    $st->execute([$userId]);
    $out = [];
    foreach ($st->fetchAll() as $r) {
        $out[] = ['nazwa' => (string) $r['nazwa'], 'ile' => (int) $r['ile']];
    }
    return $out;
}

/** Lista półek z liczbą książek. */
function polki_slownik(int $userId): array
{
    $st = db()->prepare(
        'SELECT p.id, p.nazwa, p.opis, COUNT(kp.ksiazka_id) AS ile FROM bib_polk p
         LEFT JOIN bib_ksiazka_polk kp ON kp.polka_id = p.id
         WHERE p.user_id = ?
         GROUP BY p.id, p.nazwa, p.opis
         ORDER BY p.nazwa ASC'
    );
    $st->execute([$userId]);
    $out = [];
    foreach ($st->fetchAll() as $r) {
        $out[] = [
            'id'   => (int) $r['id'],
            'nazwa' => (string) $r['nazwa'],
            'opis'  => $r['opis'] !== null ? (string) $r['opis'] : null,
            'ile'  => (int) $r['ile'],
        ];
    }
    return $out;
}

/* ============================================================== HTTP */

/**
 * Pobranie JSON-a z zewnątrz z kodem HTTP (cURL, a bez niego file_get_contents).
 * Zwraca ['kod' => int (0 = błąd sieci), 'dane' => ?array] — lookup rozróżnia
 * „źródło odpowiedziało: brak" od „źródło nie odpowiadało" (nie kasujemy
 * wtedy „braku" w cache na stałe).
 */
function http_pobierz_json(string $url): array
{
    $kod = 0;
    $body = null;
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 8,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_USERAGENT      => 'Biblioteka/' . APP_VERSION,
        ]);
        $odp = curl_exec($ch);
        $kod = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($kod === 200 && is_string($odp)) {
            $body = $odp;
        }
    } else {
        $ctx = stream_context_create(['http' => [
            'timeout' => 8,
            'header'  => "User-Agent: Biblioteka/" . APP_VERSION . "\r\n",
        ]]);
        $odp = @file_get_contents($url, false, $ctx);
        if ($odp !== false) {
            $kod = 200;
            $body = $odp;
        }
    }
    if ($body === null) {
        return ['kod' => $kod, 'dane' => null];
    }
    $data = json_decode($body, true);
    return ['kod' => $kod, 'dane' => is_array($data) ? $data : null];
}

/** Prosty wariant: sam JSON albo null przy dowolnym błędzie (poza lookup). */
function http_get_json(string $url): ?array
{
    $r = http_pobierz_json($url);
    return $r['kod'] === 200 ? $r['dane'] : null;
}

/**
 * Pobranie okładki na hosting (odpowiednik cache lookupu — katalog nie zależy
 * od kaprysów API zewnętrznych). Zwraca względną ścieżkę URL albo null.
 */
function okladka_pobierz(string $url): ?string
{
    if ($url === '' || !preg_match('#^https?://#', $url)) {
        return null;
    }
    $url = str_replace('http://', 'https://', $url);

    $body = null;
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 8,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_USERAGENT      => 'Biblioteka/' . APP_VERSION,
        ]);
        $odp = curl_exec($ch);
        $kod = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($kod === 200 && is_string($odp)) {
            $body = $odp;
        }
    } else {
        $ctx = stream_context_create(['http' => ['timeout' => 8]]);
        $odp = @file_get_contents($url, false, $ctx);
        if ($odp !== false) {
            $body = $odp;
        }
    }
    if ($body === null || $body === '' || strlen($body) > 5 * 1024 * 1024) {
        return null;
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->buffer($body);
    if (!isset(ALLOWED_PHOTO_MIME[$mime])) {
        return null;
    }
    if (!is_dir(OKLADKI_DIR) && !@mkdir(OKLADKI_DIR, 0775, true)) {
        return null;
    }
    $nazwa = bin2hex(random_bytes(16)) . '.' . ALLOWED_PHOTO_MIME[$mime];
    if (@file_put_contents(OKLADKI_DIR . '/' . $nazwa, $body) === false) {
        return null;
    }
    return OKLADKI_URL . '/' . $nazwa;
}
