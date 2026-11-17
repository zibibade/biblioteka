# Współpraca

Projekt jest mały i celowo taki zostaje: jedno hasło, brak kont użytkowników, brak chmury.
Najchętniej przyjmuję poprawki, które nie dokładają infrastruktury.

## Zanim napiszesz kod

1. Otwórz issue i opisz problem albo pomysł. Dla błędów podaj wersję, środowisko i kroki
   do powtórzenia — jeden krótki opis wystarczy.
2. Poczekaj na odpowiedź. Nie chcę, żeby ktoś poświęcił weekend na funkcję, której nie przyjmę.

## Środowisko

Nie ma `npm`, nie ma Composera, nie ma etapu budowania. Do uruchomienia wystarczy PHP i MySQL:

```bash
cp .env.example .env      # ustaw hasła
docker compose up -d --build
# http://localhost:8080
```

Alternatywnie, bez Dockera: wgraj pliki na hosting z PHP 8.1+ i MySQL 8, otwórz `install.php`.

## Zasady kodu

- **PHP 8.1+, bez frameworka.** PDO z zapytaniami przygotowanymi — bez wyjątków.
- **Każdy endpoint zmieniający stan musi mieć `WHERE ... AND user_id = ?`.** To kręgosłup
  bezpieczeństwa tej aplikacji.
- **Frontend: ES modules, bez bundlera.** Nowa zależność JS tylko jako plik w `js/vendor/`
  z wpisem w `THIRD-PARTY.md`.
- **Zero zewnętrznych CDN i fontów.** Aplikacja ma działać offline jako PWA.
- **Teksty interfejsu po polsku**, identyfikatory w kodzie bez polskich znaków
  (`chce_przeczytac`, `w_trakcie`, `przeczytane`).
- **`config.example.php` generuj z `config.php`** (podmień wartości na `UZUPELNIJ`), kiedy
  dodajesz nową stałą — inaczej wzór się rozjeżdża. Ten sam plik używają `install.php`
  i `docker/init.php`.
- **Nowe pole w bazie**: kolumna w `schema.sql` **oraz** migracja `db_kolumna_dodaj()`
  w `config.php`, plus obsługa w `ksiazka_kolumny_db()` / `ebook_waliduj()` — nie dopisuj
  kolumn w trzech miejscach po kolei.

## Zanim wyślesz PR

- `php -l` na każdym zmienionym pliku PHP, `node --check` na każdym JS. To samo robi CI,
  ale szybciej zobaczyć to lokalnie.
- Zmiana schematu: wczytaj `schema.sql` na czystą bazę i sprawdź, czy aplikacja wstaje
  (`install.php` kończy się komunikatem „Gotowe”).
- **Wpis w `CHANGELOG.md`** i podbicie `APP_VERSION` — każda zmiana, nawet kosmetyczna.
- Jeśli zmieniasz pliki powłoki (`index.html`, `css/`, `js/`), podbij też `CACHE` w `sw.js`.
  Bez tego użytkownicy zostaną na starym cache.

## Zgłaszanie bezpieczeństwa

Nie zakładaj publicznego issue. Napisz na `kontakt@zbind.pl` i opisz problem; odpowiem
i uzgodnimy publikację po naprawie.
