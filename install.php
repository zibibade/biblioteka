<?php
declare(strict_types=1);

/**
 * Biblioteka — instalator w przeglądarce.
 *
 * Kreator: sprawdza wymagania, łączy się z MySQL, tworzy tabele, zapisuje
 * config.php i blokuje się plikiem install.lock. Po udanej instalacji możesz
 * (a nawet powinieneś) usunąć ten plik — aplikacja go nie potrzebuje.
 *
 * Instalator nie robi nic, jeśli config.php albo install.lock już istnieją.
 */

const BIB_WERSJA = '1.1.0';
const BIB_CONFIG = __DIR__ . '/config.php';
const BIB_WZOR = __DIR__ . '/config.example.php';
const BIB_SCHEMAT = __DIR__ . '/schema.sql';
const BIB_LOCK = __DIR__ . '/install.lock';

require_once __DIR__ . '/lib/konfiguracja.php';

header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store');
header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');

function h(mixed $s): string
{
    return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
}

/** Tabela wymagań: [nazwa, stan czytelny, czy OK, czy obowiązkowe] */
function bib_wymagania(): array
{
    $w = [];
    $w[] = ['PHP 8.1+', PHP_VERSION, version_compare(PHP_VERSION, '8.1.0', '>='), true];

    $rozszerzenia = [
        'json'      => [true,  'odpowiedzi API'],
        'pdo_mysql' => [true,  'połączenie z bazą'],
        'curl'      => [false, 'szybszy lookup ISBN (jest zapas w file_get_contents)'],
        'zip'       => [false, 'eksport ZIP z okładkami (JSON i CSV działają bez niego)'],
    ];
    foreach ($rozszerzenia as $nazwa => [$obowiazkowe, $opis]) {
        $jest = extension_loaded($nazwa);
        $w[] = ["rozszerzenie <code>$nazwa</code>", $jest ? 'włączone' : 'brak — ' . $opis, $jest, $obowiazkowe];
    }

    $w[] = ['<code>config.example.php</code>', file_exists(BIB_WZOR) ? 'jest' : 'brak pliku', file_exists(BIB_WZOR), true];
    $w[] = ['<code>schema.sql</code>', file_exists(BIB_SCHEMAT) ? 'jest' : 'brak pliku', file_exists(BIB_SCHEMAT), true];
    $w[] = [
        'katalog aplikacji zapisywalny',
        is_writable(__DIR__) ? 'tak' : 'nie — config.php trzeba będzie wgrać ręcznie',
        is_writable(__DIR__),
        false,
    ];
    $w[] = [
        'katalog <code>uploads/</code>',
        is_dir(__DIR__ . '/uploads') && is_writable(__DIR__ . '/uploads')
            ? 'istnieje i jest zapisywalny'
            : (is_writable(__DIR__) ? 'zostanie utworzony' : 'brak — utwórz go ręcznie'),
        is_writable(__DIR__) || (is_dir(__DIR__ . '/uploads') && is_writable(__DIR__ . '/uploads')),
        false,
    ];

    return $w;
}

function bib_wymagania_ok(array $w): bool
{
    foreach ($w as [, , $ok, $obowiazkowe]) {
        if (!$ok && $obowiazkowe) {
            return false;
        }
    }
    return true;
}

/** Połączenie z MySQL; bez nazwy bazy, gdy jej jeszcze nie ma. */
function bib_polacz(string $host, int $port, string $baza, string $user, string $haslo): PDO
{
    $dsn = sprintf('mysql:host=%s;port=%d;charset=utf8mb4', $host, $port);
    if ($baza !== '') {
        $dsn .= ';dbname=' . $baza;
    }
    return new PDO($dsn, $user, $haslo, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_TIMEOUT => 5,
    ]);
}

/** Dzieli schema.sql na pojedyncze instrukcje (nasz plik nie ma średników w napisach). */
function bib_instrukcje(string $sql): array
{
    $sql = preg_replace('/^\s*--.*$/m', '', $sql);
    $czesci = [];
    foreach (explode(';', (string) $sql) as $czesc) {
        $czesc = trim($czesc);
        if ($czesc !== '') {
            $czesci[] = $czesc;
        }
    }
    return $czesci;
}

function bib_tabele(PDO $pdo): array
{
    $st = $pdo->query('SHOW TABLES');
    return array_map('strval', $st->fetchAll(PDO::FETCH_COLUMN));
}

// ------------------------------------------------------------------ stan

$wymagania = bib_wymagania();
$wymaganiaOk = bib_wymagania_ok($wymagania);
$juzZainstalowane = file_exists(BIB_CONFIG) || file_exists(BIB_LOCK);

$bledy = [];
$sukces = null;
$recznieConfig = null;

$pola = [
    'db_host'      => $_POST['db_host'] ?? 'localhost',
    'db_port'      => $_POST['db_port'] ?? '3306',
    'db_name'      => trim((string) ($_POST['db_name'] ?? '')),
    'db_user'      => trim((string) ($_POST['db_user'] ?? '')),
    'db_pass'      => (string) ($_POST['db_pass'] ?? ''),
    'app_password' => (string) ($_POST['app_password'] ?? ''),
    'google_key'   => trim((string) ($_POST['google_key'] ?? '')),
];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$juzZainstalowane && $wymaganiaOk) {
    $powtorzenie = (string) ($_POST['app_password2'] ?? '');

    if ($pola['db_host'] === '') {
        $bledy[] = 'Podaj adres serwera bazy danych (na większości hostingów: localhost).';
    }
    if (!preg_match('/^\d{1,5}$/', $pola['db_port'])) {
        $bledy[] = 'Port bazy musi być liczbą (domyślnie 3306).';
    }
    if ($pola['db_name'] === '') {
        $bledy[] = 'Podaj nazwę bazy danych.';
    }
    if ($pola['db_user'] === '') {
        $bledy[] = 'Podaj nazwę użytkownika bazy danych.';
    }
    if (mb_strlen($pola['app_password']) < 8) {
        $bledy[] = 'Hasło do aplikacji musi mieć co najmniej 8 znaków.';
    }
    if ($pola['app_password'] !== $powtorzenie) {
        $bledy[] = 'Hasła do aplikacji nie są identyczne.';
    }
    if ($pola['app_password'] === 'UZUPELNIJ' || $pola['db_pass'] === 'UZUPELNIJ') {
        $bledy[] = 'Wartości „UZUPELNIJ" to placeholdery — podaj prawdziwe dane.';
    }

    if ($bledy === []) {
        $nazwaBazy = str_replace('`', '', $pola['db_name']);
        $pdo = null;

        try {
            $pdo = bib_polacz($pola['db_host'], (int) $pola['db_port'], $nazwaBazy, $pola['db_user'], $pola['db_pass']);
        } catch (PDOException $e) {
            $komunikat = $e->getMessage();
            // 1049 = nie ma bazy; 1044 = brak uprawnień do bazy (tak MySQL odpowiada
            // też wtedy, gdy baza nie istnieje). 1045 (złe hasło) NIE jest tu łapane.
            $brakBazy = str_contains($komunikat, 'Unknown database')
                || str_contains($komunikat, '1049')
                || str_contains($komunikat, '1044');

            if (!$brakBazy) {
                $bledy[] = 'Nie udało się połączyć z bazą: ' . $komunikat;
            } else {
                // Bazy nie ma — próbujemy ją utworzyć (na hostingu zwykle brak uprawnień).
                try {
                    $bezBazy = bib_polacz($pola['db_host'], (int) $pola['db_port'], '', $pola['db_user'], $pola['db_pass']);
                    $bezBazy->exec(
                        "CREATE DATABASE IF NOT EXISTS `{$nazwaBazy}` "
                        . 'CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'
                    );
                    $pdo = bib_polacz($pola['db_host'], (int) $pola['db_port'], $nazwaBazy, $pola['db_user'], $pola['db_pass']);
                } catch (PDOException $e2) {
                    $bledy[] = 'Baza „' . $nazwaBazy . '" nie istnieje albo użytkownik „' . $pola['db_user']
                        . '" nie ma do niej uprawnień. W panelu hostingu utwórz bazę o tej nazwie i przypisz '
                        . 'do niej tego użytkownika (zwykle jednym kliknięciem „dodaj użytkownika do bazy"), '
                        . 'potem wróć tutaj.';
                }
            }
        }

        if ($pdo instanceof PDO) {
            try {
                $przed = bib_tabele($pdo);
                foreach (bib_instrukcje((string) file_get_contents(BIB_SCHEMAT)) as $instrukcja) {
                    $pdo->exec($instrukcja);
                }
                $po = bib_tabele($pdo);

                $brakujace = array_diff(
                    ['bib_ksiazki', 'bib_ebooki', 'bib_sesje', 'bib_login_attempts', 'bib_isbn_cache', 'bib_tagi', 'bib_polk', 'bib_wypozyczenia'],
                    $po
                );
                if ($brakujace !== []) {
                    throw new RuntimeException('Po wczytaniu schematu brakuje tabel: ' . implode(', ', $brakujace));
                }

                $noweTabele = array_diff($po, $przed);

                // config.php
                $config = bib_zbuduj_config(BIB_WZOR, [
                    'APP_VERSION'      => BIB_WERSJA,
                    'DB_HOST'          => $pola['db_host'],
                    'DB_PORT'          => $pola['db_port'],
                    'DB_NAME'          => $pola['db_name'],
                    'DB_USER'          => $pola['db_user'],
                    'DB_PASS'          => $pola['db_pass'],
                    'APP_PASSWORD'     => $pola['app_password'],
                    'GOOGLE_BOOKS_KEY' => $pola['google_key'],
                ]);
                if (preg_match("/^const \w+ = 'UZUPELNIJ';$/m", $config) === 1) {
                    throw new RuntimeException('Wzór konfiguracji zawiera nieuzupełnione placeholdery.');
                }

                $zapisany = false;
                if (is_writable(__DIR__)) {
                    $zapisany = @file_put_contents(BIB_CONFIG, $config) !== false;
                    if ($zapisany) {
                        @chmod(BIB_CONFIG, 0640);
                    }
                }

                // katalogi na pliki
                foreach (['uploads', 'uploads/okladki', 'uploads/okladki-ebooki', 'uploads/zdjecia'] as $katalog) {
                    $sciezka = __DIR__ . '/' . $katalog;
                    if (!is_dir($sciezka)) {
                        @mkdir($sciezka, 0775, true);
                    }
                }
                $htaccess = __DIR__ . '/uploads/.htaccess';
                if (!file_exists($htaccess)) {
                    @file_put_contents($htaccess, "Options -Indexes\n");
                }

                if ($zapisany) {
                    @file_put_contents(BIB_LOCK, gmdate('c') . " instalacja zakończona\n");
                    $sukces = [
                        'baza'   => $nazwaBazy,
                        'tabele' => count($po),
                        'nowe'   => count($noweTabele),
                    ];
                } else {
                    $recznieConfig = $config;
                    $bledy[] = 'Tabele są gotowe, ale katalog aplikacji nie pozwala zapisać config.php. '
                        . 'Skopiuj poniższą treść do pliku config.php w katalogu aplikacji.';
                }
            } catch (Throwable $e) {
                $bledy[] = 'Błąd podczas tworzenia tabel: ' . $e->getMessage();
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="pl" data-motyw="rytm">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>Instalacja — Biblioteka</title>
<style>
    :root {
        color-scheme: dark;
        --bg: #0b1220;
        --pow: #131c2e;
        --linia: #24314a;
        --tekst: #e7edf7;
        --tekst-2: #a7b4cc;
        --akcent: #3b82f6;
        --ok: #34d399;
        --blad: #f87171;
        --ostrzezenie: #fbbf24;
    }
    * { box-sizing: border-box; }
    body {
        margin: 0;
        padding: 32px 16px 64px;
        background: var(--bg);
        color: var(--tekst);
        font: 15px/1.55 system-ui, -apple-system, "Segoe UI", Roboto, Arial, sans-serif;
    }
    .kolumna { max-width: 760px; margin: 0 auto; }
    h1 { font-size: 1.5rem; margin: 0 0 4px; }
    h2 { font-size: 1.05rem; margin: 28px 0 10px; color: var(--tekst); }
    p { margin: 8px 0; color: var(--tekst-2); }
    .podtytul { color: var(--tekst-2); margin-bottom: 24px; }
    .karta {
        background: var(--pow);
        border: 1px solid var(--linia);
        border-radius: 12px;
        padding: 20px;
        margin: 16px 0;
    }
    table { width: 100%; border-collapse: collapse; font-size: 0.92rem; }
    th, td { text-align: left; padding: 7px 8px; border-bottom: 1px solid var(--linia); vertical-align: top; }
    th { color: var(--tekst-2); font-weight: 600; }
    td.stan { white-space: nowrap; }
    .tak { color: var(--ok); }
    .nie { color: var(--blad); }
    .ostrzez { color: var(--ostrzezenie); }
    label { display: block; margin: 14px 0 6px; font-size: 0.92rem; color: var(--tekst-2); }
    input {
        width: 100%;
        padding: 10px 12px;
        background: #0e1729;
        border: 1px solid var(--linia);
        border-radius: 8px;
        color: var(--tekst);
        font: inherit;
    }
    input:focus { outline: 2px solid var(--akcent); outline-offset: 1px; }
    .para { display: grid; grid-template-columns: 2fr 1fr; gap: 12px; }
    .przycisk {
        margin-top: 22px;
        width: 100%;
        padding: 13px 18px;
        background: var(--akcent);
        border: 0;
        border-radius: 10px;
        color: #fff;
        font: inherit;
        font-weight: 700;
        cursor: pointer;
    }
    .przycisk:hover { background: #4c8bf5; }
    .komunikat { padding: 12px 14px; border-radius: 10px; margin: 10px 0; border: 1px solid; }
    .komunikat.blad { border-color: var(--blad); background: rgba(248, 113, 113, 0.1); color: #fecaca; }
    .komunikat.ok { border-color: var(--ok); background: rgba(52, 211, 153, 0.1); color: #bbf7d0; }
    .komunikat.ostrzezenie { border-color: var(--ostrzezenie); background: rgba(251, 191, 36, 0.1); color: #fde68a; }
    code, pre { font-family: ui-monospace, "SF Mono", Menlo, Consolas, monospace; }
    code { background: #0e1729; padding: 1px 5px; border-radius: 4px; font-size: 0.88em; }
    pre {
        background: #0e1729;
        border: 1px solid var(--linia);
        border-radius: 10px;
        padding: 14px;
        overflow-x: auto;
        font-size: 0.8rem;
        line-height: 1.45;
        max-height: 420px;
    }
    ul { color: var(--tekst-2); padding-left: 20px; }
    a { color: var(--akcent); }
    .stopka { margin-top: 40px; font-size: 0.85rem; color: var(--tekst-2); }
    @media (max-width: 560px) { .para { grid-template-columns: 1fr; } }
</style>
</head>
<body>
<div class="kolumna">

<h1>Biblioteka — instalacja</h1>
<p class="podtytul">Wersja <?= h(BIB_WERSJA) ?>. Kreator utworzy tabele, zapisze konfigurację i zablokuje się po zakończeniu.</p>

<?php if ($juzZainstalowane): ?>
    <div class="komunikat ostrzezenie">
        Aplikacja jest już zainstalowana (istnieje <code>config.php</code> lub <code>install.lock</code>).
        Instalator nie będzie nic zmieniał.
    </div>
    <div class="karta">
        <h2>Co dalej</h2>
        <ul>
            <li>Wejdź do <a href="./">aplikacji</a> i zaloguj się hasłem ustawionym podczas instalacji.</li>
            <li>Usuń z serwera plik <code>install.php</code> — nie jest już potrzebny.</li>
            <li>Zmiana danych bazy albo hasła: edytuj <code>config.php</code>.</li>
        </ul>
    </div>
<?php else: ?>

    <?php if ($sukces !== null): ?>
        <div class="komunikat ok">
            <strong>Gotowe.</strong> Baza „<?= h($sukces['baza']) ?>” ma <?= (int) $sukces['tabele'] ?> tabel
            (<?= (int) $sukces['nowe'] ?> utworzonych teraz). Konfiguracja zapisana w <code>config.php</code>.
        </div>
        <div class="karta">
            <h2>Zostały trzy kroki</h2>
            <ul>
                <li><a href="./">Otwórz aplikację</a> i zaloguj się hasłem, które właśnie ustawiłeś.</li>
                <li><strong>Usuń plik <code>install.php</code></strong> z serwera.</li>
                <li>Ustaw cron kopii zapasowej bazy (patrz README) — eksport z aplikacji to nie backup.</li>
            </ul>
            <p>Na telefonie: otwórz aplikację w Chrome i wybierz „Dodaj do ekranu głównego". Wymaga HTTPS.</p>
        </div>
    <?php else: ?>

        <?php foreach ($bledy as $blad): ?>
            <div class="komunikat blad"><?= h($blad) ?></div>
        <?php endforeach; ?>

        <?php if ($recznieConfig !== null): ?>
            <div class="karta">
                <h2>config.php do wgrania ręcznego</h2>
                <pre><?= h($recznieConfig) ?></pre>
                <p>Po wgraniu tego pliku odśwież stronę — instalator wykryje konfigurację i zakończy pracę.</p>
            </div>
        <?php endif; ?>

        <div class="karta">
            <h2>Wymagania</h2>
            <table>
                <thead>
                    <tr><th>Element</th><th>Stan</th><th>Wymagane</th></tr>
                </thead>
                <tbody>
                <?php foreach ($wymagania as [$nazwa, $stan, $ok, $obowiazkowe]): ?>
                    <tr>
                        <td><?= $nazwa ?></td>
                        <td class="stan <?= $ok ? 'tak' : ($obowiazkowe ? 'nie' : 'ostrzez') ?>"><?= h($stan) ?></td>
                        <td><?= $obowiazkowe ? 'tak' : 'nie' ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <?php if (!$wymaganiaOk): ?>
                <p class="nie">Uzupełnij pozycje oznaczone jako wymagane, potem odśwież stronę.</p>
            <?php endif; ?>
        </div>

        <?php if ($wymaganiaOk): ?>
        <form method="post" class="karta" autocomplete="off">
            <h2>Baza danych</h2>
            <p>Dane z panelu hostingu. Baza musi istnieć albo użytkownik musi mieć prawo ją utworzyć.</p>

            <div class="para">
                <div>
                    <label for="db_host">Serwer bazy (host)</label>
                    <input id="db_host" name="db_host" value="<?= h($pola['db_host']) ?>" required>
                </div>
                <div>
                    <label for="db_port">Port</label>
                    <input id="db_port" name="db_port" value="<?= h($pola['db_port']) ?>" inputmode="numeric" required>
                </div>
            </div>

            <label for="db_name">Nazwa bazy</label>
            <input id="db_name" name="db_name" value="<?= h($pola['db_name']) ?>" required>

            <label for="db_user">Użytkownik bazy</label>
            <input id="db_user" name="db_user" value="<?= h($pola['db_user']) ?>" required>

            <label for="db_pass">Hasło do bazy (puste, jeśli użytkownik go nie ma)</label>
            <input id="db_pass" name="db_pass" type="password" value="">

            <h2>Dostęp do aplikacji</h2>
            <p>Jedno hasło do całej aplikacji — nie ma kont użytkowników. Minimum 8 znaków.</p>

            <div class="para">
                <div>
                    <label for="app_password">Hasło do aplikacji</label>
                    <input id="app_password" name="app_password" type="password" minlength="8" required>
                </div>
                <div>
                    <label for="app_password2">Powtórz hasło</label>
                    <input id="app_password2" name="app_password2" type="password" minlength="8" required>
                </div>
            </div>

            <h2>Opcjonalnie</h2>
            <label for="google_key">Klucz Google Books API (bez niego lookup ISBN działa z niższym limitem)</label>
            <input id="google_key" name="google_key" value="<?= h($pola['google_key']) ?>">

            <button class="przycisk" type="submit">Zainstaluj</button>
        </form>
        <?php endif; ?>

    <?php endif; ?>
<?php endif; ?>

<p class="stopka">Biblioteka <?= h(BIB_WERSJA) ?> · licencja MIT · autor: Zibi Bade (zbind.pl)</p>
</div>
</body>
</html>
