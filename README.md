# Biblioteka

Domowy katalog książek jako PWA. Dwa równoległe zbiory: **księgozbiór papierowy**
i **ebooki**. Katalog papierowy opisuje fizyczne egzemplarze, ebooki — tylko
metadane plików: same pliki zostają na urządzeniu użytkownika, na serwer trafia
wyłącznie miniatura okładki.

Jedno hasło do całej aplikacji, dane w jednej bazie MySQL, brak kont użytkowników.

## Stos

- PHP 8 (bez frameworka i bez Composera), PDO + prepared statements
- MySQL 8 / InnoDB, schemat w `schema.sql` (i automatycznie w `config.php`)
- frontend: ES modules, bez bundlera i bez npm
- PWA: `manifest.webmanifest` + `sw.js` (cache powłoki, API zawsze z sieci)

## Funkcje

**Katalog papierowy**

- dodawanie książki przez skan ISBN (kamera albo ręcznie) — metryczka z Biblioteki
  Narodowej, nowego katalogu BN, Google Books i Open Library, scalana w jeden rekord
  i cache'owana w bazie
- formularz z metryczkami, okładką pobieraną ze źródeł i zdjęciem własnego egzemplarza
- tagi i półki (wiele do wielu), statusy czytania, ocena
- wypożyczenia z historią (kto, kiedy, do kiedy wróciło)
- szukanie, filtry, sortowanie, paginacja

**Ebooki**

- osobna trasa `#/ebooki` i osobny formularz — ebook nie jest egzemplarzem papierowym
- import całego folderu w przeglądarce: metadane czytane z EPUB (OPF),
  FB2, MOBI/AZW3 (PalmDB + EXTH), PDF (XMP albo `/Info`), CBZ (z nazwy pliku)
- deduplikacja po SHA-256 pliku, potem po ISBN, potem po (tytuł, autor)
- formaty: `epub`, `pdf`, `mobi`, `azw3`, `fb2`, `cbz`, `inne`
- opcjonalne powiązanie ebooka z papierowym egzemplarzem
- okładki skalowane lokalnie do 400 px i wysyłane jako WebP

**Wspólne**

- statystyki w dwóch osobnych sekcjach („Papier", „Ebooki") — bez sumowania krzyżowego
- eksport/import backupu: JSON `biblioteka-eksport/v2` (v1 dalej się wczytuje),
  CSV z kolumną `typ`, ZIP z okładkami i zdjęciami
- trzy motywy, tryb offline dla powłoki aplikacji

## Wymagania

- PHP 8.1+ z rozszerzeniami `pdo_mysql`, `json`, `curl` (opcjonalnie, szybszy lookup)
  i `zip` (tylko eksport ZIP)
- MySQL 8 (albo MariaDB zgodna ze składnią z `schema.sql`)
- HTTPS — bez tego przeglądarka nie zainstaluje PWA ani nie da dostępu do kamery
- katalog `uploads/` zapisywalny przez PHP

## Instalacja — wariant A: kreator w przeglądarce (polecany)

1. Wgraj pliki na hosting (katalog dokumentów domeny).
2. Utwórz bazę danych w panelu hostingu i nadaj użytkownikowi do niej uprawnienia
   (kreator utworzy bazę sam tylko wtedy, gdy użytkownik ma prawo `CREATE`).
3. Otwórz `https://twoja-domena/install.php` i wypełnij formularz: dane bazy
   oraz hasło do aplikacji (minimum 8 znaków).
4. Kreator sprawdzi wymagania, wczyta `schema.sql`, zapisze `config.php`
   i utworzy `install.lock` — po instalacji sam odmawia dalszej pracy.
5. **Usuń `install.php`** z serwera i zaloguj się do aplikacji.

Jeśli katalog nie pozwala PHP zapisywać plików, kreator pokaże gotową treść
`config.php` do wgrania ręcznie (tabele i tak zostaną utworzone).

## Instalacja — wariant B: ręcznie

1. Wgraj pliki na hosting.
2. Skopiuj konfigurację i uzupełnij ją:

   ```bash
   cp config.example.php config.php
   ```

   W `config.php` wypełnij `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER`, `DB_PASS`
   oraz `APP_PASSWORD`. Opcjonalnie `GOOGLE_BOOKS_KEY`. Plik nigdy nie trafia do
   repozytorium.
3. Tabele utworzy aplikacja przy pierwszym połączeniu — albo wczytaj schemat:

   ```bash
   mysql -u UZYTKOWNIK -p NAZWA_BAZY < schema.sql
   ```

4. Nadaj PHP prawo zapisu do `uploads/` (powstają tam `okladki/`,
   `okladki-ebooki/` i `zdjecia/`).
5. Otwórz aplikację, zaloguj się hasłem z `APP_PASSWORD` i dodaj do ekranu
   głównego (PWA wymaga HTTPS).

## Instalacja — wariant C: Docker

```bash
cp .env.example .env     # ustaw hasła
docker compose up -d --build
```

Aplikacja: `http://localhost:8080` (port zmienia `BIB_PORT`). Konfiguracja
powstaje ze zmiennych `BIB_*` przy pierwszym starcie, start czeka na gotowość
bazy i sam zakłada tabele. Dane MySQL i `uploads/` leżą na wolumenach, więc
`docker compose down` ich nie kasuje (kasuje dopiero `down -v`).

To wariant do własnego serwera albo do wypróbowania aplikacji na komputerze —
bez HTTPS PWA nie da się zainstalować, a kamera skanera nie dostanie zgody
(poza `localhost`, które przeglądarki traktują jako bezpieczne).

## Aktualizacja

Podmiana plików + podbicie `APP_VERSION` w `config.php` (widoczne w stopce,
zgodne z `CHANGELOG.md`). Service worker sam wyrzuci stary cache. Docker:

```bash
git pull && docker compose up -d --build
```

## Struktura katalogów

```
.
├── index.html              # jedyny HTML, resztę renderuje JS
├── install.php             # kreator instalacji (usuń po instalacji)
├── manifest.webmanifest    # PWA
├── sw.js                   # service worker: cache powłoki
├── config.example.php      # wzór konfiguracji (kopiowany do config.php)
├── config.php              # lokalne dane bazy i hasło — poza repo
├── schema.sql              # schemat bazy
├── CHANGELOG.md            # historia zmian, numeracja X.Y.Z
├── THIRD-PARTY.md          # biblioteki zewnętrzne i ich licencje
├── Dockerfile              # obraz php:8.3-apache + pdo_mysql
├── docker-compose.yml      # aplikacja + mysql:8.0
├── .env.example            # wzór zmiennych dla Dockera
├── docker/
│   ├── entrypoint.sh       # katalogi, konfiguracja z env, tabele, Apache
│   └── init.php            # generowanie config.php i oczekiwanie na bazę
├── css/
│   └── style.css           # style i motywy
├── js/
│   ├── app.js              # start, logowanie, router (#/katalog, #/ebooki, …)
│   ├── api.js              # wspólny klient fetch
│   ├── ui.js               # nawigacja, komunikaty, motywy
│   ├── katalog.js          # lista i filtry katalogu papierowego
│   ├── forma.js            # formularz i szczegóły (papier + ebook)
│   ├── ebooki.js           # lista ebooków
│   ├── ebook-import.js     # import folderu i czytanie metadanych plików
│   ├── statystyki.js       # sekcje „Papier" / „Ebooki"
│   ├── skaner.js           # skaner kodów z kamery
│   ├── dekodery.js         # dekodowanie obrazu z kamery
│   └── vendor/             # zxing, html5-qrcode, fflate (bez npm)
├── api/                    # endpointy JSON; poza login/logout/stan wymagają sesji
│   ├── login.php, logout.php, stan.php
│   ├── list.php, get.php, save.php, delete.php, lookup.php
│   ├── polki.php, tagi.php, wypozyczenia.php, photo.php
│   ├── ebooki.php, ebook.php, ebook-save.php, ebook-delete.php
│   ├── ebook-okladka.php, ebook-import.php
│   └── stats.php, export.php, import.php
├── lib/
│   └── logika.php          # logika czysta: ISBN, scalanie źródeł, slugi
├── assets/                 # logo, ikony PWA, font Manrope (self-hosted)
└── uploads/                # okładki, okładki ebooków, zdjęcia (poza repo)
```

## Kopia zapasowa

`api/export.php` zwraca `?format=json` (round-trip, na tym opiera się
przywracanie), `?format=csv` (do arkusza) albo `?format=zip` (JSON + CSV +
pliki graficzne). Import przyjmuje JSON v1 i v2.

## Licencja

MIT — szczegóły w pliku [`LICENSE`](LICENSE).

Copyright (c) 2026 Zibi Bade (zbind.pl).

Możesz tego używać, zmieniać i wdrażać bez pytania, także komercyjnie — warunek
jest jeden: zachowaj informację o autorze. Aplikacja jest dostarczana „tak jak
jest", bez gwarancji; kopie zapasowe katalogu robisz na własną odpowiedzialność
(patrz `api/export.php`).

Biblioteki z `js/vendor/` i font Manrope mają własne licencje — wykaz w
[`THIRD-PARTY.md`](THIRD-PARTY.md). Licencja MIT nie obejmuje nazwy ani logo
projektu.
