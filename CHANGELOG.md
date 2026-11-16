# CHANGELOG

Konwencja: numeracja **X.Y.Z** — wpis przy **każdej** zmianie, nawet kosmetycznej.
Wersja z tej listy musi zgadzać się z `APP_VERSION` w `config.php` (widoczna w stopce).

## 1.1.0 (2026-11-16)

**Instalacja bez edytowania plików.** Dwie nowe drogi uruchomienia aplikacji,
obie kończą się tą samą konfiguracją i tym samym schematem bazy — bez
`cp config.example.php` i bez wczytywania `schema.sql` z linii poleceń.

1. **`install.php` — kreator w przeglądarce.** Sprawdza wymagania (PHP 8.1,
   `pdo_mysql`, `json`, opcjonalnie `curl`/`zip`, zapisywalność katalogu),
   przyjmuje dane bazy i hasło do aplikacji, testuje połączenie, tworzy
   brakującą bazę (jeśli użytkownik ma do tego prawo), wczytuje `schema.sql`,
   zapisuje `config.php` i tworzy `install.lock`. Po instalacji sam odmawia
   dalszej pracy, więc nie da się go użyć do przejęcia aplikacji. Gdy katalog
   nie jest zapisywalny, pokazuje gotową treść `config.php` do wgrania ręcznie.

2. **Docker: `docker-compose.yml` + `Dockerfile` + `docker/init.php`.**
   Obraz `php:8.3-apache` z `pdo_mysql` i `zip`, obok kontener `mysql:8.0`.
   Konfiguracja powstaje ze zmiennych środowiskowych (`BIB_*`), start czeka na
   gotowość bazy i sam zakłada tabele. `uploads/` i dane MySQL leżą na
   wolumenach; port zewnętrzny ustawia `BIB_PORT` (domyślnie 8080).

3. **`db()` nie wywala się na braku uprawnień `CREATE DATABASE`.** Na hostingu
   współdzielonym baza zwykle istnieje, a użytkownik nie ma prawa jej tworzyć —
   teraz taki błąd jest ignorowany, a istnienie bazy weryfikuje `USE`.

4. **Wspólna logika konfiguracji: `lib/konfiguracja.php`.** Podmiana
   placeholderów w `config.example.php` istnieje w jednym miejscu i jest
   używana zarówno przez `install.php`, jak i przez start kontenera.

## 1.0.0 (2026-11-16)

**Pierwsze wydanie produkcyjne.** Wersja domyka plan funkcjonalny (katalog
papierowy + ebooki) i porządkuje projekt pod publikację jako samodzielna
aplikacja do postawienia na własnym hostingu.

1. **`config.example.php` odtworzony z bieżącego `config.php`.** Poprzedni wzór
   pochodził z 0.4.0 — brakowało w nim 12 funkcji (import, tagi, półki,
   wypożyczenia, scalanie metryczki) i 3 tabel ebooków, więc instalacja z niego
   kończyła się błędem. Teraz wzór ma komplet 31 funkcji i 12 tabel.

2. **Blokada brute-force przywrócona we wzorze.** `login_blocked()` w
   `config.example.php` zwracała `false` (pozostałość po testach), przy
   `LOGIN_MAX_ATTEMPTS = 99999`. Teraz liczy nieudane próby z
   `bib_login_attempts` w oknie 15 minut, limit 5 na adres IP — jak w kodzie
   produkcyjnym.

3. **Żadnych danych wdrożeniowych w repozytorium.** Usunięte z drzewa pliki
   robocze (instrukcje zadań, specyfikacje etapów, notatki agenta) razem
   z lokalnymi ścieżkami, adresami hostingu i danymi dostępowymi. Wzór
   konfiguracji nie zawiera niczego poza placeholderami `UZUPELNIJ`.

4. **Porządki wersjonowania.** `APP_VERSION` i cache service workera podbite
   do 1.0.0, dodane `.gitattributes` (vendory i fonty poza statystyką języka)
   oraz `THIRD-PARTY.md` z licencjami bibliotek w `js/vendor/`.

5. **Licencja MIT** (`LICENSE`, copyright 2026 Zibi Bade / zbind.pl). Kod wolno
   używać, zmieniać i wdrażać, także komercyjnie, pod warunkiem zachowania
   informacji o autorze; bez gwarancji. Nazwa i logo nie są licencjonowane.

## 0.29.0 (2026-11-14)

**Drugi katalog: ebooki.** Obok księgozbioru papierowego dochodzi równoległy
katalog plików elektronicznych. Najważniejsze założenie: **na serwer nie
wchodzi ani jeden ebook** — katalogowane są wyłącznie metadane, a jedynym
plikiem, jaki opuszcza urządzenie, jest miniatura okładki. Katalog papierowy
zostaje bez zmian (wszystko addytywne, nowe tabele przez
`CREATE TABLE IF NOT EXISTS`).

1. **Osobna trasa `#/ebooki`** w routerze (`js/app.js`) i link „Ebooki"
   w nawigacji (`js/ui.js`), zaraz obok „Katalog". Dwa zbiory, dwie trasy —
   wspólna lista filtrowałaby jabłka z gruszkami, a ebook nie jest
   egzemplarzem papierowym i odwrotnie.

2. **Widok listy `js/ebooki.js`** — siatka kart jak w katalogu papierowym,
   filtry (szukaj po tytule/podtytule/autorze/ISBN/opisie, status, format,
   tag, półka), sortowanie (`dodania|tytul|autor|rok`, także malejąco)
   i paginacja. Statusy te same co przy papierze, żeby jedno pojęcie nie
   znaczyło dwóch rzeczy w jednej aplikacji.

3. **Import folderu `js/ebook-import.js`** — `<input webkitdirectory>` bierze
   cały katalog, format rozpoznajemy po rozszerzeniu, a metadane czytamy
   w przeglądarce: EPUB przez `META-INF/container.xml` → plik `.opf`
   (dc:title, dc:creator, dc:publisher, dc:date, dc:language,
   dc:identifier, dc:description + okładka z manifestu), FB2 z
   `description/title-info`, MOBI/AZW3 z nagłówka PalmDB i rekordów EXTH
   (100/101/103/104/503), PDF z XMP albo `/Info`, CBZ z nazwy pliku.
   Gdy nic nie wyjdzie — tytuł z nazwy pliku („Autor - Tytuł",
   „Tytuł (Autor)").

4. **Deduplikacja po SHA-256 całego pliku**, liczbona w przeglądarce
   (`crypto.subtle.digest`), dalej w kolejności hash → ISBN → (tytuł, autor).
   Pozycje idą partiami po 25, na końcu ekran raportu: dodane, pominięte
   duplikaty, błędy z powodem. Pliki **>100 MB pomijamy** — hash wymaga
   wczytania całości do pamięci, a telefon nie jest do tego stworzony;
   trafiają do raportu jako „pominięte", nie jako błąd.

5. **Okładki** są jedynym plikiem wychodzącym z urządzenia: skalowanie do
   400 px i konwersja do WebP robią się lokalnie, do `api/ebook-okladka.php`
   idzie już miniatura (max 2 MB, jpeg/png/webp). Reszta pliku zostaje u
   użytkownika — hash i nazwa wystarczą, żeby wiedzieć, co to za plik.

6. **Formularz `js/forma.js`** obsługuje ebooka tymi samymi komponentami pól
   co papier, plus pole `format` (`epub|pdf|mobi|azw3|fb2|cbz|inne`) i wybór
   powiązanej książki papierowej z podpowiedziami z `api/list.php`. Powiązanie
   to jedna kolumna `powiazana_ksiazka_id`, więc papier zostaje nietknięty.

7. **Statystyki i backup bez sumowania krzyżowego**: `api/stats.php`
   i `js/statystyki.js` mają dwie osobne sekcje „Papier" i „Ebooki" (razem,
   statusy, autorzy, formaty, tagi). Eksport to `biblioteka-eksport/v2`
   z nową gałęzią `ebooki` (z tagami i półkami), CSV dostaje kolumnę `typ`,
   a import przyjmuje v1 i v2 — stary plik musi się dalej wczytywać.

8. **Service worker i vendor**: `CACHE` podbite do `biblioteka-v0.29.0`,
   w powłoce lądują `./js/ebooki.js`, `./js/ebook-import.js`
   i `./js/vendor/fflate.min.js`. fflate 0.8.2 (UMD, ~32 KB) jest potrzebny
   tylko do rozpakowania EPUB, więc `ebook-import.js` wciąga go **leniwie**,
   przy pierwszym EPUB-ie — bez tego import z plików innych niż EPUB
   pobierałby bibliotekę na darmo.

Weryfikacja: `php -l config.php`, `node --check sw.js`, `node --check
js/ebooki.js`, `node --check js/ebook-import.js` — czysto;
`js/vendor/fflate.min.js` 32 KB i zawiera `unzipSync`.

## 0.26.0 (2026-10-04)

**Trzy poprawki nawigacji i układu zgłoszone przez właściciela („raczej
wizualne, jeżeli chodzi o komfort pracy").** Żadna nie rusza danych.

### 1. Wiersz ISBN: **Skanuj po lewej**, Szukaj po prawej

Skan to pierwszy krok przy dodawaniu, a przycisk po lewej wypełnia pas palca
na telefonie. Kolejność zmieniona **w HTML**, nie przez `order` w CSS — reguła
`order` przy kolejnym przestawieniu znaczników zrobiłaby dokładnie
odwrotność zamierzonej. Do „Szukaj" doszła ikona lupy (skan już miał aparat),
żeby dwa przyciski w rzędzie były tak samo rozpoznawalne wizualnie.

### 2. Przycisk zapisu na górze po odczycie książki

Do 0.25.0 blok `.forma-akcje` był **wyłącznie na dole** formularza, a skan to
najczęstszy scenariusz — trzeba było przescrollować całą metrykę, żeby
zapisać. Teraz po udanym odczycie blok wjeżdża w `#slot-akcji-gora`,
umieszczony między wierszem ISBN a polem Tytuł.

Przenosimy **węzeł**, nie kopiujemy przycisku: dwa „Zapisz w katalogu" na
ekranie to zaproszenie do podwójnego zapisania (test pilnuje, że jest
dokładnie jeden). Puste miejsce ma `display: none` — inaczej pusty `<div>`
dodałby pustą przerwę w `.forma` (kolumna flex z `gap`).

Stan „akcje na górze” wynika wyłącznie z tego, że slot nie jest pusty
(selektor `#slot-akcji-gora:not(:empty)`) — **żadnej klasy w JS**, którą
trzeba pamiętać (id i klasa miały tę samą nazwę, co zapraszało pomyłkę).

Świadomie **bez `position: sticky`**: pasek przyklejony pod sticky nagłówkiem
(`z-index: 30`) chowałby się za nim. Nie ma go też w trybie edycji — tam
formularz jest krótszy i nagłówek nie jest potrzebny.

### 3. „Dodaj książkę" na karcie książki

Kolejność w nagłówku: **Wróć do katalogu → Dodaj książkę → Edytuj → Usuń**
(„Dodaj" dokładnie tam, gdzie prosił właściciel — między powrotem a
przyciskami zarządzania). Przycisk prowadzi na nową trasę `#/dodaj/<id>`,
 która otwiera formularz dodawania **wypełniony metryką tej książki**;
 zapis idzie jako nowy wpis (bez `id`), więc powstaje drugi egzemplarz, a nie
 kopia. Zamiast `#/dodaj?ksiazka=N`, bo router czyta sam `hash` i
 dopasowanie z parametrami wymagałoby zmiany wszystkich porównań.

Formularz mówi wprost, skąd wzięła metryka — żeby nie było wątpliwości, że
to nowa książka.

### Weryfikacja

`ui_test13.js` **19/19** — realna przeglądarka, backend podszyty przez
`fetch` (lokalnie nie ma MySQL), więc test sprawdza DOM i kliknięcia, nie
założenia o kodzie:

- kolejność Skanuj/Szukaj w DOM **i na osi X** (nie tylko kolejność znaczników);
- przed odczytem górne miejsce puste i niewidoczne, akcje na dole;
- po odczycie: akcje przed polem Tytuł **oraz mieszczą się w pierwszym
  widoku** — to dosłowne kryterium zgłoszenia („żeby nie trzeba było
  scrollować”), a nie moja interpretacja;
- dokładnie jeden przycisk zapisu;
- karta: kolejność czterech przycisków w DOM i na osi X;
- kliknięcie „Dodaj książkę” → formularz z metryką, komunikat o pochodzeniu,
  a `save.php` **bez `id`** (potwierdzone w przechwyconym ciele żądania);
- edycja nie psuje się: bez wiersza lookupu, „Zapisz zmiany", akcje na dole.

+ 137/137 PHPUnit.

## 0.25.0 (2026-10-04)

**Właściciel: „zeskanowanych jest coraz więcej polskich książek, ale nie
wszystkie; Biblioteka Narodowa twierdzi, że powinna mieć wszystko z rynku —
zweryfikuj, do jakich baz mamy w ogóle dostęp”.**

Audyt przeprowadzony pomiarem, nie przeczytaniem dokumentacji: **109 ISBN-ów**
przez prawdziwe funkcje produkcyjne (`lib/lookup_zrodla.php`) — 41 bestsellerów
rynkowych z lubimyczytac.pl (próba **niezależna** od BN, więc nie jest krzyżowa)
+ 68 ISBN-ów z katalogu BN.

| źródło | trafień | unikalnych | średni czas |
|---|---|---|---|
| `data.bn.org.pl` (BN, stare API) | 103 (94,5 %) | **0** | 82 ms |
| `katalogi.bn.org.pl` Primo (nowy katalog BN) | 103 (94,5 %) | **0** | 331 ms |
| Google Books | **0 (0 %)** | 0 | 416 ms |
| Open Library | 33 (30,3 %) | **4** | 708 ms |

### Co się okazało

1. **Oba wejścia BN mają identyczne pokrycie** — 103/103, zero unikalnych
   trafień w obie strony. Primo to czysty koszt ~330 ms na każdy skan.
2. **Google Books nie zna polskich wydań.** Operator `isbn:` zwraca
   `totalItems=0` **zawsze** — także dla tytułów, które Google ma
   (`intitle:Drogi do nowoczesnej Europy` → 1 wynik, `isbn:9788301123451` → 0),
   a zwykłe wyszukiwanie po samym ISBN też 0 dla polskich. 0 % na 109 ISBN-ach.
3. **Okładki daje wyłącznie Open Library** — BN nie zwraca `okladka_url`.
4. **Braki to luki BN, nie błąd kodu.** Dwa bestsellery spoza próbki:
   `9788380082533` (Osobliwy dom pani Peregrine) i `9788380311015`
   (Jeździec miedziany). BN ma oba **tykuły** (36+ rekordów po tytule), ale pod
   **innymi ISBN-ami innych wydań** (`9788372786463` Media Rodzina 2012,
   `9788382658033` Must Read 2024). Sprawdzone na 3 zakładkach katalogu ×
   3 operatorach (`isbn,exact`, `isbn,contains`, `any,contains`) — zero.
   **Pokrycie BN jest per ISBN (wydanie), nie per tytuł**: nowe nakłady tej
   samej książki w nim często nie ma.

### Co zmieniono

- Nowa kolejność: **BN → Open Library → katalog BN (tylko gdy te dwa nie
  dały wyniku)**. Google wypada z wywołań.
- Funkcja `google_ksiazka()` zostaje w kodzie z pełnym opisem pomiaru —
  przywraca się jedną zmianą, gdyby Google wrócił z polskimi wydaniami.
  Token `google` zostaje w `KSIAZKA_ZRODLA`, bo w bazie są stare wiersze:
  bez niego walidacja odrzuciłaby je i zapisała „ręcznie".
- Komunikat przy braku mówi wprost, że sprawdziliśmy BN (oba katalogi) i
  Open Library oraz że zwykle BN ma książkę pod numerem innego wydania —
  inaczej brak wyglądał jak awaria aplikacji.

### Dowód, że pokrycie nie spadło

Porównanie obu strategii na tych samych 109 ISBN-ach
(`probe_parowosc.php`):

```
stara kolejność (4 źródła) : 107 trafień
nowa kolejność  (2 + zapas) : 107 trafień
UTRACONE: 0   ZYSKANE: 0
czas: 161 792 ms → 80 811 ms   (połowina)
```

Testy: 137/137 PHPUnit (2 nowe: token `google` zostaje na liście mimo
wyłączenia z łańcucha, oraz kombinacje `zrodlo` dla nowej kolejności —
`brak` celowo **nie** jest na liście, bo to etykieta cache'a lookupu, a nie
źródło zapisanej książki).

## 0.24.1 (2026-10-04)

**Właściciel: „tym razem zeskanowało szybko i od razu, więc działa — ale jak
tylko otworzę skaner przy dodawaniu kolejnej książki, automatycznie zaczytuje
się poprzedni kod”.**

### Przyczyna: kontekst canvasu zapamiętany w zmiennej modułowej

`dekodery.js` trzymał `kontekst` (2D) na poziomie modułu. Kanvas z poprzedniej
sesji **wciąż zawiera w sobie ostatnią klatkę z odczytanym kodem**, więc przy
otwarciu kolejnego skanera dekoder czytał stary obraz i natychmiast zgłaszał
tę samą książkę. Kanvasu z bieżącej sesji nie było w ogóle używane.

Naprawa: `kontekstCanvasa(canvas)` bierze kontekst zawsze z aktualnie podanego
canvasa. `getContext` na tym samym canvasie i tak zwraca ten sam obiekt, więc
nic nie tracimy, a stan nie przeżywa między sesjami.

**Dowód, nie zgadywanie:** test `skaner_test12.js` (6/6) uruchamia dwie sesje
w jednej stronie i przełącza kod w kadrze między nimi. Ze starym kodem sesja 2
czytała kod poprzedniej sesji (4/6); z nowym — właściwy (6/6).

### Druga rzecz: ZXing nie resetuje stanu między klatkami

`MultiFormatReader.decode()` **nie woła `reset()`** — dokumentacja zaleca
`decodeWithState()` plus ręczny `reset()` przy skanowaniu ciągłym. Bez tego
czytniki 1D gromadzą wewnętrzny stan z poprzedniego kadru. Dodane
`czyt.reset()` przed każdym dekodowaniem.

### Pułapki w samym teście (ta sama klasa błędów co wcześniej)

- `#wynik` w stronie laboratoryjnej **nie zeruje się między sesjami**, więc
  `waitForFunction` wracał natychmiast na wartości z poprzedniej sesji i test
  „czyta nowy kod” przechodził, nie sprawdzając niczego.
- Kod EAN-13 w teście miał **złą sumę kontrolną** — ZXing odrzuca taki symbol
  słusznie, a w testzie wyglądało to jak „leak stanu”.
- Kontrolna: naprawę weryfikuję przez **przywrócenie starego kodu** i sprawdzenie,
  że test pada. Dopiero wtedy twierdzę, że przyczyna była zła.

Weryfikacja: `skaner_test12.js` **6/6** + pozostałe zestawy bez regresji
= **174 testy JS** (16+32+12+16+14+7+5+28+15+10+6+11) + 135/135 PHPUnit.

## 0.24.0 (2026-10-04)

**Właściciel po 0.23.3: „nadal detekcje są zero, może zamiast robić własny kod
znajdziemy jakiś w Internecie, np. Quagga2 z GitHuba”.**

Racja: dekodera nie było w naszym kodzie — skaner prosił przeglądarkę
o `window.BarcodeDetector` (Chrome na Androidzie robi to przez ML Kit),
a gdy go nie było, włączał html5-qrcode. **Nie kontrolowaliśmy dekodera,
więc nie dało się go przetestować poza telefonem właściciela.** W Chromium
nie ma `BarcodeDetector` w żadnej konfiguracji, więc przez siedem wersji
testy zastępowały go atrapą i badały nieistniejący kod.

### ZXing zamiast `BarcodeDetector`

`js/vendor/zxing.min.js` (`@zxing/library` 0.21.3, MIT, 336 kB), UMD,
globalne `ZXing`. Wybór: **Quagga2 to fork ZXinga** — tej samej rodziny, na
której opiera się html5-qrcode, który już mieliśmy. ZXing daje niskopoziomowe
API (podajemy kanvas, dostajemy tekst), więc pętla, latarka, powiększenie
i podgląd zostają bez zmian; Quagga2 jest ~2 × większe, nie ma czystego API
dla kanvasa i samo przejmuje strumień wideo. html5-qrcode zostaje jako
**ścieżka zapasowa**, gdyby plik ZXinga się nie wczytał.

Zysk poza „działa”: **prawdziwe dekodowanie da się teraz testować lokalnie.**

### Zmierzone ograniczenie ZXinga: czyta tylko środek obrazu

720×1280, EAN-13, czysty obraz:

| położenie kodu | wynik |
|---|---|
| y = 250…970 | czyta |
| y = 20…170 | **nie czyta** |
| y ≥ 1000 | **nie czyta** |

Nie pomogło: `HybridBinarizer`, `GlobalHistogramBinarizer`, `TRY_HARDER`,
obraz odwrócony. Czytał zawsze po przycięciu kadru do połowy zawierającej
kod. Tolerancja zmierzona: **±25 % wysokości kadru** — czytniki ZXinga
przeszukują wiersze od pionowego środku na zewnątrz, więc kod przy krawędzi
wypada, a głęboka biel wokół psuje binaryzację.

Dlatego `dekodery.js` dzieli kadr na **4 nakładające się pasy po 35 %
wysokości**, sprawdzając je od środka (najczęstszy traf) na zewnątrz.
Sprawdzone na **wszystkich** pozycjach kodu i przy wysokości paska od 8 %
do 45 % kadru — nie pominęło ani jednej (`probe_pasy3.js`).

### Pozostałe zmiany

- `WYMIAR_DETEKTORA` **1280 → 960 px**: zmierzono, że ZXing czyta ten sam
  EAN-13 już przy **1 px na moduł** (kod szerokości 117 px), więc zapas
  1280 px był marnowany, a każdy piksel w pasie to koszt dekodowania.
- Dekoder dostaje **całą ramkę** (nie wycinek 92 % × 60 % jak do 0.23.3).
- Diagnostyka: `Ścieżka: ZXing · ZXing 0.21.3 · kadr … · próby N ·
  czytanie NN ms · puste N · …`. Czas czytania jest nowy i potrzebny —
  ZXing dekoduje w JS, więc na wolnym telefonie jedna klatka to setki ms.
- ODSLUP detekcji 110 → 200 ms (przejście po 4 pasach kosztuje więcej).
- Deduplikacja klatek po `presentedFrames` z `requestVideoFrameCallback`.
  Pierwsza wersja (0.24.0 w trakcie) robiła to po `video.mediaTime` i **pętla
  stała martwa** (`próby 0`) — `mediaTime` dla strumienia z aparatu nie rośnie.
  Wyłapane przez test, który wreszcie czyta prawdziwe kadry.
- Podpis podglądu: „To dokładnie widzi skaner” → „Tak widzi skaner”
  (pasy sięgają całej ramki, więc stare zdanie było nieprawdziwe).

### Testy

- `dekoder_test.js` **11/11** — dekodery na statycznym canvasie: środek,
  dół, góra kadru, 2 px na moduł, wersja biblioteki, zakres pasów.
- `skaner_test11.js` **15/15** — prawdziwy kod w strumieniu wideo: 5 pozycji
  pionowych (y = 0,05…0,95), 2 px i 3 px na moduł, kadry pionowe
  i poziome, pusty kadr bez fałszywego odczytu.
- `skaner_test11b.js` **10/10** — kod obcy (poprawny EAN-13, nie 978/979):
  dekoder czyta, walidacja odrzuca, komunikat widoczny, liczniki rosną.
- `skaner_test9.js` **28/28** — 22 przypadki parsowania odczytu + kontrakt
  komunikatu. Od 0.24.0 **nie podmienia dekodera**: robiła to atrapa
  `BarcodeDetector`, której aplikacja już nie używa.
- `skaner_test10.js` **usunięty** — testował rotację wycinków, której już
  nie ma (zastąpiły ją pasy w module dekodera; pokrywają ją `dekoder_test`
  i `skaner_test11`).
- Razem: **168 testów JS** (16+32+12+16+14+7+5+28+15+10+11) + 135/135 PHPUnit.

### Pułapki z testów (warte zapisania)

- Własny generator EAN-13 w teście (tabele L/G) **mylił parzystości lewej
  połowy** — kod był niepoprawny i dekoder (słusznie) go odrzucał. Jeden
  generator (`kody.js`, zweryfikowany z bwip-js), wstrzykiwany do strony.
- `canvas.captureStream()` emituje klatkę **tylko gdy zawartość się zmieni**.
  Nierepowywalne rysowanie = brak nowych klatek = dekoder dostaje obraz
  sprzed pierwszego malowania. W testach rysujemy 2×2 px w rogu co klatkę.
- Canvas wstrzykiwany skryptem startowym musi być podłączany **po**
  powstaniu dokumentu (`document.body` i `document.documentElement` są wtedy
  `null`) — inaczej `getUserMedia` działa, a nic nie widać.
- Sekcja „kod obcy” powtarzała się w `skaner_test11.js` mimo że identyczny
  skrypt w osobnym procesie czytał się 14/14. Nie umiem wskazać wyścigu,
  więc **wydzieliłem ją** do `skaner_test11b.js` zamiast zostawić fałszywie
  czerwony test albo go po cichu skasować.

## 0.23.3 (2026-10-04)

**Właściciel, po 0.23.2: „próby rosną, odczyty cały czas zero”.**

To pierwszy twardy sygnał z telefonu. Oznacza: pętla działa, dekoder
dostaje kadry i **w żadnym nie znajduje kodu**. Zamiast kolejnej hipotezy —
naprawione dwie konkretne wady, które obie pasowały do tych liczników.

### 1. Pełnego kadru nie skanował skaner NIGDY

`kadrDetektora()` przyjmował skalę i za każdym razem wycinał
**92 % szerokości × 60 % wysokości** — czyli **odcinał 35 % góry i dołu**.
Wariant „cały obraz” to było 100 % × **65 %**, raz na cztery detekcje.
Żadna detekcja nie objęła całej ramki.

Kód leżący choćby centymetr niżej środka wypadał z pola widzenia dekodera,
a użytkownik widział „nie czyta” bez informacji, że patrzymy wyłącznie
na środek. Własne pomiary z 0.23.0 mówiły przy tym, że rozdzielczość
nie jest wąskim gardłem — więc obcinanie było niepotrzebne i szkodliwe.

Teraz kolejne detekcje patrzą na **rotujące wycinki**:

| wycinek | po co |
|---|---|
| **100 % × 100 %** | nic poza kadrem nie jest pominięte |
| 92 % × 60 % | dotychczasowy „wycinek ramki” = to, co pokazuje podgląd |
| 60 % × 45 % | moduł kodu dostaje ~1,5 × więcej pikseli |
| 40 % × 30 % | ~2,3 × więcej — kod na grzbiecie bywa ułamkiem kadru |

Cały kadr pada raz na cztery detekcje (odstęp ~110 ms), więc pełne pokrycie
wraca w ~440 ms.

Ten sam błąd był na ścieżce zapasowej: `qrbox` w html5-qrcode to
96 % × 72 % — pole przeszukiwane też było obcięte. Teraz niemal cały kadr.

### 2. `catch` w pętli połykał wyjątki — „nie czyta” mogło być awarią

```js
} catch { /* klatka nie gotowa — ignorujemy */ }
```

`detect()` rzucający wyjątek wyglądał **dokładnie** tak jak `detect()`
zwracający pustkę. Do wiersza diagnostyki doszedł licznik `AWARIE <n>
(<nazwa>)` — np. `AWARIE 27 (InvalidStateError)`. Podobnie `puste <n>`
oddziela „kadry przeszły, kodu nie było” od awarii.

Nowy wiersz pod kadrem:

```
Ścieżka: natywna · kadr 720×1280 · wycinek cały kadr · próby 37 · puste 37 · odczyty 0 · odrzucone 0
```

Weryfikacja: **14/14** w nowym zestawie, na prawdziwym kodzie EAN-13
(bwip-js) wstrzykniętym do wideo **pionowego 720×1280, u dołu kadru —
poza wycinkiem 92 % × 60 %**. Bez rotacji kod nie byłby czytany nigdy.
Kontrola rotacji wymaga świadomego utrudnienia testu: dekoder nie
odpowiada przez pierwsze 4 kadry, bo moduł zamyka się po pierwszym
trafieniu, a pełny kadr akurat bywa pierwszy — bez tego test przechodził,
nie widząc trzech pozostałych wycinków.
+ pozostałe zestawy bez regresji = **152 testy JS** + 135/135 PHPUnit.

## 0.23.2 (2026-10-03)

**Właściciel: podgląd jest już wyraźny, ale skaner nadal nie czyta.**

Wersja 0.23.0 udowodniła, że formaty kodów blokowały odczyt. Skoro to nie
wystarczyło, trzeba przestać zgadywać i zmierzyć, co się dzieje na telefonie,
którego nie mam. Do tej pory „nie czyta” mogło znaczyć trzy różne rzeczy,
a wszystkie wyglądały identycznie — skaner po prostu milczał.

### 1. Cichy odrzut odczytanego kodu — główna zmiana

`isbnZKodu()` wymagała, by **cała** odczytana wartość była dokładnie
13-cyfrowa, zaczynała się od 978/979 i miała poprawną sumę kontrolną.
`wykryto()` zwracało wtedy `return false` **bez jednego piksela informacji**.
Efekt: dekoder mógł czytać kod w kółko, a użytkownik widział dokładnie to
samo co przy braku odczytu.

Teraz rozdzielamy przypadki, które realnie trafiają się na kodzie z książki:

- **dopisek tekstu** — `ISBN978…`, `isbn978…` (Code-128 na grzbietach);
- **ITF-14** — `1` + EAN-13, kod na kartonie zbiorczym;
- **doklejony kod ceny EAN-5** — `978…` + 5 cyfr;
- **ISBN-10** (10 cyfr, ostatnia może być `X`) → przeliczany na ISBN-13
  przez prefiks `978`, bo starsze książki bywają opisane Code-128 z
  dziesięciocyfrowym numerem.

ISBN-10 szukamy **wyłącznie w całości wartości**, nigdy ze środka dłuższego
ciągu cyfr — inaczej przypadkowy numer (IMEI, numer partii) przechodziłby
sumę kontrolną z szansą 1/11.

### 2. Widoczny sygnał „odczytano, ale to nie ISBN”

Osobny, bursztynowy komunikat pod podpowiednią o ramce — pojawia się **od
razu**, nie po 3,5 s. Bez niej „trafiam w kod, ale walidacja odrzuca” i
„nie trafiam w kod” były nierozróżnialne.

### 3. Diagnostyka na urządzeniu (pod kadrem)

```
Ścieżka: natywna · kadr 1280×720 · próby 33 · odczyty 33 · odrzucone 33 · ostatni: „5901234123457”
```

Rozdziela trzy stany, które z zewnątrz wyglądają identycznie:

| objaw | znaczy |
|---|---|
| `próby = 0` | pętla detekcji nie rusza / nie ta ścieżka |
| `próby rosną`, `odczyty = 0` | dekoder nie daje rady z obrazem |
| `odczyty rosną`, `odrzucone rosną` | kod czytany, ale walidacja odrzuca |

Na ścieżce html5-qrcode `próby` liczy klatki bez kodu (biblioteka nie
podaje własnych prób) — rosnące zero znaczy, że pętla leży.

Reguła `.modal > .skaner-odczyt`, nie sama klasa: `.modal > p` (0,1,1)
wygrywało z `.skaner-odczyt` (0,1,0) i zabierało bursztynowy kolor —
pierwsza wersja sygnału wyglądała jak zwykły podpis.

Weryfikacja: **36/36** w nowym zestawie (22 przypadki parsowania + 14
sprawdzeń sygnału i liczników, w tym kontrola bursztynowego koloru na
żywym elemencie) + 16 + 32 + 12 + 16 + 14 + 7 + 5 = **138 testów JS**
+ 135/135 PHPUnit.

## 0.23.1 (2026-10-03)

**Jakość okienka „To dokładnie widzi skaner”** (zgłoszenie właściciela:
„jakość jest po prostu tragiczna”). Nie estetyka — to było narzędzie
diagnostyczne, z którego nie dało się nic odczytać.

Przyczyna konkretna: canvas podglądu miał **sztywne `width=128 height=48`**,
a CSS (`width: 100%`) rozciągał go na całą szerokość okna. Kadr źródłowy ma
do **1280 px**, więc lądował w 128×48, tracąc ~95 % pikseli, dodatkowo bez
zachowania proporcji względem tego, co mieściło się w oknie — a potem był
jeszcze powiększany przez CSS. Efekt: rozmyte, rozciągnięte plamy, po których
nie dało się ocenić, czy dekoder ma szansę przeczytać kod.

Poprawka (`dopasujCel()` w `podgladDetektora()`):

- bufor liczony z **faktycznej szerokości okna × gęstość ekranu**
  (`devicePixelRatio`, limit 3), a nie zapisany na sztywno;
- **nigdy szerszy niż sam kadr** — powiększanie i tak by rozmyło;
- proporcje brane z kadru, więc obraz **nie jest rozciągany**;
- `image-rendering: pixelated` — rozmiar bufora jest zawsze ≤ szerokości
  urządzeniowej, więc CSS może tylko powiększać; wygładzanie rozmazywałoby
  krawędzie pasków, a to jedyna rzecz, po której widać czytelność kodu.

Zmierzone na symulowanym telefonie (390 px, `deviceScaleFactor: 3`):
**6 144 → 123 540 pikseli** (20×), stosunek bufor/wyświetlanie = 1,0,
proporcje zgodne. Pętla odświeżania bez zmian (250 ms).

Weryfikacja: **14/14** w zestawie podglądu (4 nowe: bufor mieści się w oknie,
bufor nie węższy od okna, proporcje nie rozciągnięte, bufor wykorzystuje
gęstość ekranu przy `dpr=3` — ta ostatnia wyłapuje cofnięcie limitu gęstości)
+ pozostałe zestawy bez regresji (16 + 32 + 12 + 16 + 7 + 5 = **102 testy JS**)
+ 135/135 PHPUnit.

## 0.23.0 (2026-10-03)

**Znaleziona przyczyna: skaner czytał tylko `ean_13` i `ean_8`.** Prawdziwy
`BarcodeDetector` listy formatów **nie ignoruje** — kod Code-128, ITF czy UPC
na grzbiecie książki nie mógł zostać odczytany **nigdy**, niezależnie od kadru,
powiększenia i jakości obrazu. Pięć poprzednich wersji (0.18–0.22) szukało
przyczyny w obrazie i aparacie — a część książek po prostu nie miała kodu w
formacie, którego szukaliśmy.

Potwierdzone testem, nie przypuszczeniem (patrz niżej): ten sam kadr, dwie
listy formatów — wąska daje `0` wyników i `odrzucony format=CODE_128`, szeroka
zwraca poprawny ISBN. Aplikacja end-to-end czyta teraz Code-128 z ISBN.

- **`FORMATY_SKANERA`** — obie ścieżki (natywna i html5-qrcode) dostają szeroką
  listę: EAN-13/8, UPC-A/E, Code-128/39/93, ITF, Codabar, QR, Data Matrix,
  RSS-14. Wynik i tak musi przejść walidację ISBN, więc dodatkowe formaty
  zwiększają szansę trafienia, a nie ryzyko fałszywego odczytu. Konstruktor z
  zapasem na listę minimalną (`nowyDetektor()`), żeby przeglądarka bez
  któregoś formatu nie straciła skanera. Na ścieżce html5 klucze bierzone z
  enuma biblioteki z `.filter(Boolean)`, żeby brakujący nie wywalił startu.
- **Latarka na Firefoksie** (zgłoszenie): Firefox nie zna `torch` w ogóle —
  zmierzone: `applyConstraints` rzuca `OverconstrainedError`. Nasz kod to
  poprawnie wykrywał, ale ustawiał `disabled` — **wyłączny przycisk nie
  reaguje na dotyk**, a na telefonie nie widać też `title`, więc wyglądał na
  zepsuty. Teraz przycisk jest klikalny i po dotknięciu pokazuje wyjaśnienie
  (`.skaner-latarka[data-brak]`, `aria-disabled`, toast).

**Przełom w testowaniu — dotąd nie było możliwe sprawdzenie realnego odczytu:**

Przez cały projekt **żaden test nie weryfikował faktycznego odczytu kodu
kreskowego**: Chromium z Playwrightem nie ma `BarcodeDetector` (brak w
konfiguracji flag), a atrapa kamery pokazywała szum. Co najważniejsze,
wcześniej podmienialiśmy strumień na `canvas.captureStream()` — ale Chrome
dostarcza klatki z canvasa **dopiero gdy ten się zmienia**, więc bez pętli
`requestAnimationFrame` wideo zostawało z `readyState = 0` i żaden dekoder nie
dostawał obrazu, choć strumień deklarował `live`. To mogło też maskować błędy
wcześniejszych testów.

Teraz:

1. **Prawdziwy kod w strumieniu** — własny generator EAN-13 wg tablic GS1
   (`kody.js`), skrzyżowo zweryfikowany z bwip-js: obie implementacje dają
   **identyczne 95 modułów**. Kod jest rysowany na canvasie, który staje się
   kamerą.
2. **Kontrola negatywna** — pusty (biały) kadr nie daje odczytu. Bez niej
   pozytywny test nic by nie udowadniał.
3. **Polyfill `BarcodeDetector`** na `scanFileV2` z ZXinga odtwarza ścieżkę
   NATYWNĄ (tą używa Chrome na Androidzie). Ważne: `scanFileV2` **ignoruje**
   `formatsToScan` (zmierzone), a prawdziwy `BarcodeDetector` nie — dlatego
   polyfill sam odfiltruje wynik po `result.format.formatName`, inaczej test
   bramki formatów dałby fałszywy wynik.
4. **Progi wytrzymałości** — syntetyczny kod czyta się nawet przy 2 px na
   moduł, kontraście 0,2, źródle 640×480 i przechyleniu 20° (35° już nie).
   To **odrzuca hipotezę**, że kadr, rozdzielczość i wielkość kodu są
   wąskim gardłem — i kieruje podejrzenie tam, gdzie rzeczywiście było:
   na format.

Weryfikacja: **98 testów JS** (16 + 32 + 12 + 16 + 10 + 7 + 5, w tym 7 nowych
na realny odczyt i 5 na formaty) + **135/135 PHPUnit**.

## 0.22.0 (2026-10-03)

Skaner: **powiększenie + proporcje kadru** (odczyt wciąż „przypadkowy”), oraz
**wersja aplikacji w stopce na telefonie** zamiast skrótów klawiaturowych
(prośba właściciela).

**Odczyt — trzy powody, dla których kod bywa nieczytelny, mimo że aparat
systemowy czyta bez problemu:**

1. **Użytkownik celował na ślepo.** Telefon trzymany pionowo daje strumień
   pionowy, a modal był poziomy; przy `object-fit: cover` użytkownik widział
   wąski pas kadru, a reszta była przycięta. Teraz `dopasujPowierzchnie()`
   ustawia proporcje podglądu z faktycznego `videoWidth/videoHeight`
   (`aspect-ratio` ustawiane po starcie strumienia, aktualizowane na `resize`).
2. **Kod był za mały.** Kod na grzbiecie to ułamek szerokości kadru, a aparat
   systemowy ma zoom — strona do 0.21.0 nie miała żadnego. Teraz
   **przyciski `−` / poziom / `+` oraz szczypanie dwoma palcami**, zakres z
   `getCapabilities().zoom` (gdy aparat go nie raportuje, próbujemy do 4× i
   nie zmieniamy poziomu, jeśli aparat odrzuci). Poziom pokazany liczbą
   (`1×`, `1,4×`), kafelki 44 px zgodnie z `--tap`.
3. Pozostałe usprawnienia z 0.21.0 (maksymalna rozdzielczość, wycinek,
   detekcja na pełnym obrazie co czwartą klatkę) działają.

Pasek narzędzi: powiększenie dostało **osobny, wyśrodkowany rząd** — w jednym
rzędzie z latarką i „Anuluj" przycisk łamał się na 390 px i „Anuluj" spadał
pod wiersz.

**Stopka na telefonie:** skróty klawiaturowe (`n`, `/`, `s`) są bezużyteczne bez
klawiatury fizycznej, a wersja i tak znikała z nagłówka poniżej 560 px
(`.wersja { display: none }`) — na telefonie nie było jak sprawdzić, co jest
wgrane. Teraz `.skroty-mobile` pokazuje wersję w miejscu skrótów
(`@media (max-width: 560px)`); na desktopie skróty zostają, a stopka z wersją
jest ukryta.

Weryfikacja: **16/16** nowych testów (zoom widoczny, start 1×, cel 44 px,
podnosi i cofa aparat, aparat bez zoomu nie kłamie, proporcje powierzchni
≈ proporcje strumienia, Latarka i Anuluj w jednym rzędzie, nic poza ekranem,
stopka na telefonie i desktopie) + **15/15**, **32/32**, **12/12** poprzednich
zestawów bez regresji + 135/135 PHPUnit.

**Stan wiedzy o odczycie — uczciwie:** pięć wersji skanera, pięć różnych
przyczyn naprawionych, a odczyt nadal nie jest pewny. **Nie umiem tego
zweryfikować automatycznie**: Chromium z Playwrighta nie ma `BarcodeDetector`
w żadnej konfiguracji flag, więc dekodera używanego przez Chrome na Androidzie
nigdy nie odpaliłem. To, co potwierdziłem, to logika i geometria — nie
faktyczny odczyt kodu kreskowego. Powiększenie jest tu celowo **narzędziem
dla użytkownika**, nie tylko zmianą kodu: jeśli ono nie pomoże, to informacja
diagnostyczna sama w sobie — znaczy, że problem leży w dekodatorze, a nie
w kadrze.

## 0.21.0 (2026-10-03)

Skaner po zgłoszeniu z telefonu: **„jak zezwalam na aparat, znika guzik do latarki"**
i **„kod w aparacie systemowym czyta się bez problemu”** (uwagi właściciela).

Ta druga uwaga jest rozstrzygająca: **kod fizycznie się czyta, światło jest
w porządku, aparat umie** — problem leży w naszej detekcji, nie w książce ani
w oświetleniu.

**Latarka — błąd popełniony przez nas samych.** Do 0.20.0 przycisk pokazywaliśmy
**wyłącznie gdy `'torch' in track.getCapabilities()`**. Chrome na Androidzie tej
właściwości często **nie raportuje w ogóle**, więc przycisk po prostu znikał i
latarki nie dało się włączyć w skanerze — można było tylko ręcznie, w aparacie
systemowym. Zmierzone na atrapie odwzorowującej Androida (brak klucza `torch`
w `getCapabilities()`, `applyConstraints` przyjmuje latarkę):

| | 0.20.0 | 0.21.0 |
|---|---|---|
| `getCapabilities()` ma `torch` | przycisk widoczny | widoczny, próbuje |
| **brak `torch` (Android)** | **przycisk znika** | **widoczny i próbuje** |
| włączenie odmówione | — | widoczny, wyłączony, z `title` |

Funkcja `latarkaDostepna()` **usunięta**. Jedyną próbą jest samo włączenie
(`latarkaUstaw`), bo tylko wyjątek z `applyConstraints` jest wiarygodnym
sygnałem porażki. W jej miejsce `mozliwosci()` — surowy odczyt możliwości,
potrzebny niżej do rozdzielczości i focus.

**Odczyt kodów — trzy zmiany, wszystkie związane z liczbą pikseli na kod:**

- **Podnosimy rozdzielczość do maksimum** (`maksymalnaRozdzielczosc()`). Czytelność
  EAN-13 zależy od pikseli **na sam kod**, nie od wielkości podglądu. Kod na
  grzbiecie książki trzymanej w wyciągniętej ręce bywa w kadrze ledwie ~150 px
  szerokości i detektor go nie czyta — a aparat systemowy to samo ujęcie robi
  w rozdzielczości pełnego sensora i czyta bez problemu. `getUserMedia` bez
  jasnego żądania często zwraca rozdzielczość niższą niż dostępna, więc po
  starcie prosimy ścieżkę wideo o maksimum zgłoszone w `getCapabilities()`.
  Użyte `ideal`, nie `exact` (ten potrafi odrzucić całe żądanie).
  **Przy okazji: regresja własna z 0.19.0** — obniżyłem wtedy żądanie z
  1920×1080 do 1280×720 „bo detektor i tak zmniejsza obraz”. To było zgadywanie
  bez pomiaru; teraz bierzemy maksimum.
- **Ostrość ciągła** (`focusMode: 'continuous'`), gdy aparat umie. W aparacie
  systemowym można dotknąć i wyostrzyć, a strona nie ma takiej możliwości.
- **Co czwartą klatkę detekcja na pełnym obrazie**, nie tylko na wycinku —
  książka trzymana z boku wychodziła poza ramkę. Wycinek (92 × 60 % kadru)
  nadal jest domyślny, bo przybliżony kod czyta się lepiej.
- Podpowiedź zmieniona na „Przyłóż książkę tak, by kod wypełniał ramkę —
  bliżej aparatu”, bo problemem często jest odległość, nie sprzęt.

**Zmiana zachowania świadoma i zapisana:** przycisk latarki **nie znika już
nigdy** poza sytuacją, gdy aparat naprawdę nie ma kamery. Wcześniejsze testy
Playwright kodowały dawną regułę („brak `torch` w capabilities → przycisk
znikaj”) i wymagały korekty — to był błąd, nie kontrakt.

Weryfikacja: **12/12** nowych testów (latarka bez raportowanego `torch`, aparat
odmawiający, podniesienie rozdzielczości, podpowiedź, ramka, odmowa z 0.20.0),
**15/15** i **32/32** poprzednich zestawów (korygowane o nowe zachowanie),
135/135 PHPUnit.

**Do potwierdzenia ręcznie na telefonie:** rzeczywista czytelność. Chromium z
Playwrighta nie ma `BarcodeDetector` w żadnej konfiguracji flag, więc **ścieżka
natywna — ta, którą używa Chrome na Androidzie — nigdy nie odpaliła się
w testach**. Podnoszenie rozdzielczości i wycinek są sprawdzone jako kod i
geometria, nie jako odczyt kodu kreskowego.

## 0.20.0 (2026-10-03)

Skaner: **„Przeglądarka Chrome nie może zezwolić na uruchomienie aparatu na tej stronie"** (uwaga właściciela).

**Przyczyna zgłoszonego komunikatu — zmierzona, nie zgadnięta.** Aplikacja nie
potrafiła rozpoznać przyczyny odmowy, bo `html5-qrcode` rzuca **zwykłym
stringiem**, nie obiektem błędu. Pomiar:

```
navigator.mediaDevices.getUserMedia → throw NotAllowedError
html5Skaner.start()  rzuca:  "Error getting userMedia, error = NotAllowedError: …"
typeof wyrzuconej wartości === 'string'   (brak .name, Object.keys → litery)
```

Do 0.19.0 `aparatOpis()` czytał `err.name` i przy nieznanej wartości pokazywał
„Bez opisu” — czyli dokładnie sytuację, w której Chrome odmawia. Rozpoznanie
błędu działało tylko na ścieżce natywnej, a ta nieuruchamia się bez
`BarcodeDetector`.

**Zmienione:**
- **`aparatOpis()` rozpoznaje nazwę przyczyny także w tekście błędu**
  (lista `NotAllowedError` / `NotFoundError` / `NotReadableError` / …), a przy
  nieznanej pokazuje surowy komunikat biblioteki zamiast „Bez opisu”.
  Rozróżniane przyczyny: brak zgody, brak aparatu, aparat zajęty, brak HTTPS,
  brak `mediaDevices` — każda z konkretną instrukcją naprawy.
- **Błąd pokazywany W MODALU, nie toastem, i bez zamykania okna.** Wcześniej
  modal znikał, zostawał sam komunikat i zero możliwości naprawy. Teraz są dwa
  wyjścia: **„Spróbuj ponownie”** (po zmianie ustawień Chrome) i **„Wpisz ISBN”**.
- **`startAparatu()` jako osobna funkcja** — ponowienie działa w tym samym
  modalu, bez zamykania i otwierania od nowa (co gubiło stan formularza).
- **Wklejanie ISBN** (`wklejZamknij()`) — pole na ISBN z walidacją
  (`isbnZKodu`), przyciski Cofnij / Znajdź książkę, obsługa Enter. Kod z
  grzbietu książki można wkleić ze sklepu internetowego, więc ręczne wpisanie
  nie musi być wklejaniem z pamięci. Zły kod nie przechodzi dalej — pokazuje
  komunikat pod polem.

**Błędy znalezione przy okazji (oba zmierzone, nie zgadnięte):**
1. **„Spróbuj ponownie” w ogóle nie działał.** `startAparatu()` szukał kontenera
   po `#skaner-powierzchnia`, a
   `html5-qrcode` przy starcie zmienia `id` na `skaner-html5` — po pierwszym
   uruchomieniu selektor nic nie znajdował i ponowienie wywalało się na
   `removeAttribute` (`TypeError: Cannot read properties of null`). Teraz
   element trzymany jest w zmiennej, a nie szukany po `id`.
2. **Podpowiedź i przycisk latarki zostawały po błędzie**, choć podglądu nie ma
   — `pokazBlad()` je usuwa, a `startAparatu()` przy ponownej próbie odtwarza
   (sprawdzone: po naprawieniu ustawień podpowiedź i podgląd wracają).

Weryfikacja: **32/32 testy Playwright** (5 rodzajów odmowy aparatu × modal,
przyczyna, instrukcja, oba wyjścia, brak błędów JS; wklejanie ISBN od złego do
poprawnego kodu; ponowienie z odzyskaniem i z naprawionym ustawieniem) +
**14/14 testów skanera z 0.19.0** bez regresji + 135/135 PHPUnit.

**Nadal do potwierdzenia ręcznie:** sama zgoda Chrome. Komunikat „nie zezwala”
oznacza najczęściej zapamiętaną odmowę — przeglądarka nie pyta drugi raz.
Naprawa po stronie użytkownika: klocka przy adresie → „Ustawienia witryny” →
aparat: „Zezwól” (albo `chrome://settings/content/camera` i usunięcie hosta
z listy zablokowanych). Aplikacja pokazuje tę instrukcję w modalu.

## 0.19.0 (2026-10-03)

Skaner po zgłoszeniu z serwera: **„nie da się załadować latarki"** i **„ciężko łapią”** (uwagi właściciela z testów na telefonie).

**Latarka — błąd potwierdzony testem, nie zgadnięciem.** Wersja 0.18.0 sprawdzała
`track.getSettings().torch === true`, żeby „nie ufać samemu wywołaniu”. Pomiar na
atrape pokazał, że to zabijało działającą latarkę:

```
applyConstraints({advanced:[{torch:true}]}) → brak wyjątku  (latarka ZAPALONA)
getSettings()                                 → { width, height, deviceId }   ← bez torch
moja logika 0.18.0: (getSettings().torch ?? null) === true  →  false
→ komunikat „Aparat nie dał zapalić latarki”
```

Wiele aparatów (Android/Chrome) przyjmuje `applyConstraints` bez wyjątku i naprawdę
zapala latarkę, ale **w ogóle nie raportuje klucza `torch` w `getSettings()`**. Jedynym
wiarygodnym sygnałem porażki jest wyjątek.

- `latarkaUstaw()` zwraca teraz `'ok' | 'brak-latarki' | 'blad-aparatu'` i
  **nie żąda potwierdzenia w `getSettings()`**. Próbuje po kolei `applyConstraints`
  ścieżki wideo → `html5Skaner.applyVideoConstraints()` → jawne `applyConstraints`
  bez `advanced`. Komunikat błędu różni się zależnie od tego, co się stało.
- **Latarka zapala się od razu przy otwarciu skanera** — skanuje się zwykle w
  słabym świetle (grzbiet książki, piwnica, wieczór), więc pytanie „czy światło
  włączyć” tylko opóźniało odczyt. Wyłączenie zostaje użytkownikowi.
  Gdy aparat tego nie potrafi, przycisk zostaje wciśnięty z `title`, a nie udaje
  działającego.

**Odczyt kodów — trzy zmiany, każda mierzalna:**

- **Detektor dostaje wycinek kadru, nie cały strumień** (`wycinaj()` + `canvas`).
  Kod na grzbiecie to ułamek szerokości obrazu, a im więcej tła wokół, tym mniej
  pikseli zostaje na sam kod. Wycinamy środek (92 × 60 % kadru) i oddajemy
  detektorowi w naturalnej skali.
- **`requestVideoFrameCallback` zamiast zgadywania odstępu** — pętla dostaje
  klatkę dokładnie wtedy, gdy ją aparat dostarczy, zamiast liczyć „co 120 ms”
  na ślepo. W Safari (brak tego API) wpada na timer. Odstęp detekcji 110 ms.
- **Ramka celu poszerzona z 76 × 32 % do 88 × 46 % kadru** — przy węższej ramce
  kod trzymany bokiem książki wypadał z pola przeszukiwanego przez detektor.
  Przyciemnienie dookoła kieruje wzrok na ramkę.

**Regresja wprowadzona i wycofana w trakcie tej samej pracy (zapisane, bo łatwo
o nią znowu):** próbowałem zmienić `facingMode: 'environment'` na
`facingMode: { ideal: 'environment' }` w `html5Skaner.start()`. **html5-qrcode tego
nie przyjmuje** — wymaga stringa albo `{ exact: ... }` i rzuca
`'facingMode' should be string or object with exact as key`, co wyłącza całą
ścieżkę zapasową (Safari/iOS). Obiekt `ideal` jest poprawny tylko w natywnym
`getUserMedia`. Zmierzone warianty: A oryginał OK, B `{ideal}` WYJĄTEK,
C `qrbox` jako funkcja OK, D `aspectRatio: undefined` OK, E wszystko naraz WYJĄTEK.
Na ścieżce natywnej `width/height` z `ideal` (nie `exact`) — `exact` potrafi
odrzucić całe żądanie i wywrócić aparatem.

**Do poprawki świadomie:** `qrbox` na ścieżce html5-qrcode poszerzony z
86 × 34 % do 96 × 72 % kontenera. Skoro biblioteka sama decyduje, co czyta,
nie możemy jej zrobić wycinka — dajemy jej więcej materiału. Zmiany w tej
drodze **nie da się zweryfikować automatycznie**: Chromium z Playwrighta nie ma
`BarcodeDetector` w żadnej konfiguracji flag, więc ścieżka natywna (czyli ta,
którą używa Chrome na Androidzie) nigdy nie odpaliła się w testach. Weryfikacja
objęła logikę latarki, geometrię i brak błędów JS — 14/14.

Testy: 135/135 PHPUnit bez zmian, 14/14 Playwright.

## 0.18.0 (2026-10-03)

Skaner: **latarka** + naprawa „skanuje słabo" (uwaga właściciela).

**Odpowiedź na pytanie „czyje to oprogramowanie":** detektor **nie jest nasz**.
Są dwie ścieżki, obie cudze: natywne `BarcodeDetector` wbudowane w Chrome/Androida
oraz zewnętrzna biblioteka **html5-qrcode** (375 KB, `js/vendor/`, ścieżka
zapasowa dla Safari/iOS). Nasz kod to modal, filtr prefiksu 978/979 i pętla
wywołująca detekcję co 120 ms.

**Przyczyny słabego odczytu (namierzone w przeglądarce, nie zgadnięte):**
1. **`<video>` nie miał kompletnie żadnej reguły CSS.** Przeglądarka pokazywała
   podgląd w domyślnych 300×150 px wewnątrz modala o szerokości 420 px.
   Detektor kodów pracuje na pikselach — mały rozmazany kadr oznaczał, że kod
   książki nie wchodził w pole widzenia.
2. **html5-qrcode wstawia `<video>` z atrybutem `style="width: 306px"`**, który
   bije każdą regułę z arkusza. Reguła musi mieć `!important` — inaczej naprawa
   z punktu 1 nie działa na iOS w ogóle.
3. **Brak ramki celu** i brak latarki — przy skanowaniu kodu z grzbietu książki
   w słabym świetle to najbardziej potrzebna funkcja, a jej nie było.

**Dodane:**
- **Latarka** (`latarkaPodepnij()`, `latarkaUstaw()`, `latarkaDostepna()`) —
  przycisk pojawia się **dopiero gdy aparat deklaruje latarkę**
  (`track.getCapabilities().torch`), więc nie pokazujemy martwego przycisku na
  aparatach jej niemających. Obsługa obu ścieżek: `track.applyConstraints()`
  (natywna) z powrotem na `html5Skaner.applyVideoConstraints()` (fallback).
  Po kliknięciu **nie ufamy samemu wywołaniu** — pytamy aparat
  (`getSettings().torch`) i przycisk zmienia stan tylko gdy potwierdził.
  Latarka gaszona przy zamknięciu modala, także przy zamknięciu przez kliknięcie
  w tło. Stan włączona niesie klasa `.wlaczony` (obwódka ikony), nie sam kolor —
  zgodnie z DESIGN.md. Własna ikona SVG `latarka` (siatka 24, grubość 1.75).
- **Podgląd aparatu na całą szerokość modala** — `.skaner` 520 px,
  `.skaner-powierzchnia` `aspect-ratio: 16 / 10`, `object-fit: cover`,
  zaokrąglenie `--r-powierzchnia`, tło `#05070c`.
- **Ramka celu** `.skaner-cel` — 76 × 32 % kadru na środku, z przyciemnieniem
  dookoła (`box-shadow: 0 0 0 100vmax`), wskazuje miejsce, gdzie trzymać kod.
  Wyłączono biblioteczną `#qr-shaded-region` (dwie ramki wyglądają jak błąd).
- **Wyższa rozdzielczość aparatu** na ścieżce natywnej — `width: ideal 1920`,
  `height: ideal 1080`; detektor bazowy startuje od niskiej i w małym kadrze nie
  czyta gęstych kodów. Pętla detekcji z 200 → 120 ms.
- **`qrbox` liczony z rozmiaru konteneru** (86 × 34 % zamiast sztywnych
  240 × 140 px) — na telefonie stała wartość była mniejsza niż kod na grzbiecie.

**Naprawione:**
- **`cleanup` był nadpisywany dwiema gałęziami** — pierwsza wersja nigdy się nie
  wykonywała, więc część zasobów zostawała niezamknięta. Teraz jedna funkcja
  zbierająca kroki (`doSprzatania`), odwracana przy sprzątaniu, a krok gaszenia
  latarki dokładany dokładnie raz.
- **html5-qrcode czyści zawartość kontenera przy starcie** i kasował naszą
  ramkę celu — dlatego ramka jest wstawiana dopiero po `start()` (`ramkaCel()`),
  a nie razem z HTML modala.
- **„Anuluj" skakał pod lewą krawędź**, gdy aparat nie miał latarki
  (`justify-content: space-between` z jednym dzieckiem) — zastąpione
  `margin-left: auto` na grupie przycisków.

Weryfikacja: **19/19 testów w Chromium** z atrapowym aparatem (Playwright) —
geometria podglądu i realne klatki (strumień 640×480, `readyState ≥ 2`) na
390 × 844 i 1280 × 900, latarka włącza i wyłącza (potwierdzone na ścieżce
wideo), brak przycisku przy aparacie bez latarki, zamknięcie modala zostawia zero
`<video>` i zero przycisków w DOM, brak błędów JS. Testy PHP 135/135 bez zmian.
Plik testowy nie trafił do repo.

## 0.17.0 (2026-10-03)

Lookup ISBN — naprawa „nie znajduje mi wszystkich książek" (research po polskich
bazach: BN data API, nowy katalog BN na Ex Libris Primo, Open Library, Google
Books, Polona, Wolne Lektury, NUKAT, Empik/Lubimyczytać/Książki.pl, WorldCat,
Crossref/DataCite/OpenAlex, Internet Archive).

**Przyczyna (pomiar, nie zgadywanie):** skaner kodów zwraca zawsze **EAN-13
z prefiksem 978**, a Biblioteka Narodowa ma polskie książki sprzed 2007 r.
zindeksowane **wyłącznie po ISBN-10** (ISBN-13 obowiązuje w Polsce od 2007).
Lookup pytał tylko tę formę, którą dostał, więc praktycznie cała tania
klasyka z lat 90. wyglądała na „nieznaną".

Na próbce 34 starszych polskich ISBN-10 (symulacja skanu):

| wariant łańcucha | trafienia |
|---|---|
| dotychczas (BN po ISBN-13 + Open Library) | 10/34 |
| + próba drugiej formy ISBN | **34/34** |

**Zmienione:**
- **`isbn_na13()` / `isbn_na10()` / `isbn_warianty()`** (`lib/logika.php`) —
  konwersja obu form ze sprawdzeniem sumy kontrolnej. `isbn_na10()` zwraca
  `null` dla prefiksu **979** (polskie ISBN po 2007 r.): w starym schemacie
  nie było miejsca na kolejną grupę, więc odwrotna konwersja nie istnieje.
- **Lookup iteruje po wariantach** (`api/lookup.php`) — każde źródło pyta
  najpierw o ISBN z podanej postaci, potem o drugą formę. Pole `isbn` w
  zapisanej książce to zawsze ISBN z kodu/skanu, nie wariantu z którego
  pochodziła metryczka.
- **Nowe źródło: katalog Biblioteki Narodowej na Ex Libris Primo**
  (`bn_primo_ksiazka()`, `katalogi.bn.org.pl/primaws/rest/pub/pnxs`,
  `q=isbn,exact,<ISBN>`) — bez klucza i bez limitu zapytań. Ten sam
  indeks co dotychczasowy `data.bn.org.pl`, ale innym wejściem: znajduje
  starsze książki **po ISBN-13**, których `data.bn.org.pl` nie zna
  (22/22 trafień na współczesnych ISBN-13 w pomiarze). Kolejność źródeł:
  BN → **katalog BN** → Google Books → Open Library. W UI: „Katalog
  Biblioteki Narodowej" (`bnk`).
- **Wybór rodzaju materiału** (`bib_kind_ranga()`, `bib_najlepszy_rekord()`,
  `bib_najlepszy_rekord_primo()`) — dla ISBN znanego BN z kilku postaci
  bierzemy **druk**, nie audiobook ani e-book (słowo kluczowe sprawdzamy
  po polsku i angielsku, bo strefa `/institutions/` używa angielskich
  wartości, a `/networks/` polskich). Bez tego np. dla ISBN Bieguni
  z 2019 metryczka opisywała „1 płyta audio (CD)".
- **Liczba stron tylko z druku** — `300|a` przy audiobooku opisuje nośnik
  („1 płyta audio (CD) : 13 godz."), więc `liczba_stron` zostaje `null`,
  a scalanie dopisze ją z innego źródła. Dotyczy BN i nowego katalogu.
  Przy okazji ten sam parser (`bib_strony_z_formatu()`) zastąpił w mapperze
  BN „pierwszą liczbę z 300|a” — przy „2 t. w 1 wol. (615 s.) : il. ; 24 cm.”
  dawała **2 strony zamiast 615**.
- **Mapowanie nowego katalogu** (`bn_primo_na_wspolne()` w `lib/logika.php`) —
  rekord jest opisany hasłowo, nie MARC-em: liczba stron z `format`
  („208, [4] s. ; 20 cm.”), wydawca z `lds30` (bez miasta), rok z `sort`
  (`creationdate` bywa „copyri”, czyli sama rezerwacja miejsca na datę),
  tytuł z `sort.title` (czysty, bez dopisku „/ Autor”), autor z `creator`
  po obcięciu roli doklejonej po nazwisku („Sapkowski, Andrzej (1948- ) Autor”).
- **TTL dla „brak" w cache** (`LOOKUP_CACHE_BRAK_DNI` = 30 dni, `config.php`) —
  wpis „nie znaleziono" leży w `bib_isbn_cache` bezterminowo, więc książka,
  której źródła nie znały w dniu poprowadzenia, nigdy by się już nie odnalazła,
  nawet po poprawce źródeł. Odczyt cache omija wpisy starsze niż TTL; zapis
  przeszedł na `INSERT … ON DUPLICATE KEY UPDATE`, bo stary wpis zostaje
  w bazie i blokowałby ponowne zapisanie.
- Rozróżnienie „nikt nie zna ISBN” od „wszystkie źródła padły" — status 503
  (komunikat „bazy nie odpowiadają”, bez zatrwaływania cache) tylko wtedy,
  gdy **żadne** zapytanie nie wróciło 200. Wcześniej wystarczyło, by jeden
  z czterech źródeł zawiódł.

**Sprawdzone i odrzucone** (żeby nie wracać do nich przy kolejnych wersjach):
Google Books ma wyczerpany limit dobowy i operator `isbn:` zwraca 0 wyników
nawet z ważnym kluczem — polskich wydań praktycznie nie ma, źródło zostaje
rezerwowe, dodatkowo odpytywane tylko dla pierwszego wariantu; NUKAT —
`katalogi.nukat.edu.pl` nie istnieje, żywy katalog za bot-gatem, bez API;
Polona — stare `/api/search/` zwraca 404, nowe API nie indeksuje ISBN;
Crossref, DataCite, OpenAlex, Internet Archive — zero trafień na polskie ISBN
handlowe; Empik, Lubimyczytać, Książki.pl — brak otwartego API (403/401).

**Uwaga operacyjna:** `covers.openlibrary.org` odpowiada HTTP 200 z 43-bajtowym
GIF-iem 1×1 zamiast 404 — istniejący `okladka_pobierz()` odsiewa to po MIME.

- **Biała lista `zrodlo` generowana z tokenów** (`bib_zrodla_dozwolone()`,
  `KSIAZKA_ZRODLA`) zamiast ręcznej listy kombinacji. Przy dodaniu `bnk`
  ręczna enumeracja łatwo przegapiłaby jedną kombinację — a biała lista
  odcina cokolwiek nieznanego do `recznie`, więc pominięcie oznaczałoby
  ciche utracenie informacji o źródle. Najdłuższa wartość to 16 znaków,
  mieści się w `VARCHAR(20)`.

Testy: 135/135 (478 asercji), +22 nowe na warianty ISBN, wybór rodzaju
materiału, mapowanie nowego katalogu, czyszczenie wydawcy i białą listę
źródeł. PHP 8.5.11, PHPUnit 13.3.6. Pomiar na żywych źródłach: **9/9**
kontrolnych ISBN — Solaris 1994, Lalka 1994 (raz po ISBN-13 ze skanu i raz
wpisanej ręcznie po ISBN-10), Pan Tadeusz 1994, Mały kraj 2021, Bieguni 2019
(BN zna tylko audiobook), Pan Tadeusz e-book 2018, Sanatorium e-book 2017,
Wroniec (audiobook) — pełna metryczka z BN w 9/9.

## 0.16.0 (2026-10-02)

Kolumna zamiast rozlewania — układ na szerokich ekranach (uwaga właściciela:
„na dużych ekranach rozlewa się po całej szerokości ekranu").

**Zmienione:**
- Cały widok (`#app`: nagłówek, pasek narzędzi, panel filtrów, pasek regału,
  treść, karta, formularz, statystyki) siedzi teraz w **jednej kolumnie
  maksymalnie `--max-kolumna` = 1280 px, wyśrodkowanej** (`max-width` +
  `margin-inline: auto`). Powyżej 1280 px boki oddychają ciemnym tłem,
  poniżej nic się nie zmienia (1280 i mniej = dotychczasowy wygląd).
- Modale (`.modal-overlay`) i toasty pozostają `position: fixed`, więc wciąż
  otwierają się na środku okna — niezależnie od szerokości kolumny.

Weryfikacja: 1920×1080 (kolumna 1280, lewy margines 320), 1440×900 (80),
1280×800 (0 — bez zmian), 390×844 (390 — bez zmian), brak poziomego
przewijania; 116 testów PHP bez zmian.

## 0.15.0 (2026-10-02)

Poprawki układu w wersji mobilnej (uwagi właściciela).

**Zmienione:**
- **„Do przeczytania" przeniesiony z nagłówka do panelu filtrów** — skrót do
  widoku (z ikoną zakładki) jest teraz ostatnim elementem panelu filtrów, tuż
  obok „Wyczyść filtry". Nagłówek ma o jedną ikonę mniej na głównym widoku.
- **Pasek narzędzi: „Dodaj książkę" po lewej, „Filtry" i przełącznik widoku
  po prawej** (druga strona rzędu) — elementy zamienione kolejnością w DOM.
  Przycisk Dodaj jest **większy i szerszy** (`min-height: 48px`,
  `padding: 0 24px`, `font-size: 1rem`) i na stałe pokazuje etykietę, także
  na telefonie (wcześniej zwijał się do samej ikony 44×44).
- **„Powrót do katalogu" zawsze po LEWEJ stronie** — przycisk dostał klasę
  `.powrot-lewy` (`margin-right: auto` w `.naglowek-akcje`), a `.naglowek-akcje`
  rozciąga się na resztę nagłówka (`flex: 1 1 auto`) kosztem rosnącego `h1`.
  Dotyczy formularza dodawania/edycji i statystyk.
- **Ekran książki: powrót w tym samym kontenerze co „Edytuj" i „Usuń"**, na
  tej samej wysokości i po lewej — zamiast osobnego wiersza `.detal-powrot`
  pod nagłówkiem (reguła CSS usunięta, znacznik przeniesiony do `.naglowek-akcje`).

Weryfikacja: render na 390×844 i 1280×900, 116 testów PHP bez zmian.

## 0.14.0 (2026-10-02)

Poprawki po przeglądzie 0.13.0 (uwagi właściciela).

**Naprawione:**
- **„Powrót do katalogu" wyświetlał `${powrotKatalog()}` zamiast przycisku** —
  wywołanie było włożone w napis w cudzysłowie, więc nie było interpolowane.
  Dotyczyło ekranu książki (ładowanie i edycja) oraz statystyk.
- **Większość pól tekstowych w formularzu była biała** — nowy selektor CSS
  wymieniał konkretne `input[type=…]`, a pola formularza nie mają atrybutu
  `type` i wymykały się stylowaniu (domyślne jasne tło przeglądarki).
  Teraz: `input:not([type=checkbox]):not([type=radio]):not([type=file])`.
- **Przycisk „Filtry" — tekst wychodził poza obręb** — miał klasę `.ikona`
  (sztywne 44×44) przy etykiecie tekstowej. Ma `.przycisk-pusty`.
- **Statystyki: paski wychodziły poza kartę** (rok wydania) — maksimum
  liczone z pierwszego wiersza listy (sortowanie po roku, nie po liczbie),
  więc pasek potrafił mieć 300% szerokości. Teraz `Math.max` po całej
  liście + clamping do 100% i `overflow: hidden` na kolumnie paska.
- **Zostałe glify Unicode** (`←`, `▤`, `⇄`) w paskach kontekstowych i
  pustych stanach → ikony SVG.

**Zmienione (mniej informacji w widoku listy):**
- Tagi **usunięte z wiersza listy** (zostają w siatce i na karcie).
- Statusy w liście: **sama ikona** (ptaszek / kropeczka / strzałeczki) —
  tekst zostaje tylko przy „W trakcie", gdzie sama ikona nie mówi nic
  o postępie. Wypożyczenie: same strzałeczki, nazwisko w `title`.
  Wariant listy ma `aria-label`, bo bez tekstu ikona potrzebuje nazwy.
- **ISBN: pole na całą szerokość**, a „Szukaj" i „Skanuj" dzielą rząd
  na pół (`.pole-isbn`).
- **Wiersz listy zjeżdżał do dwóch rzędów**, gdy książka była jednocześnie
  wypożyczona i miała status — cztery elementy siatki przy trzech kolumnach.
  Obie odznaki są teraz w jednym kontenerze `.wiersz-stany`.

Weryfikacja: render na 390×844 i 1280×900, 0 glifów w roli ikon,
116 testów PHP bez zmian.

## 0.13.0 (2026-10-02)

Przebudowa wizualna całej aplikacji — nowy świat „Rytm kodu".

**Przebudowano (system wizualny, patrz `DESIGN.md`):**
- **Paleta**: jedna rodzina barw — chłodna czerń przesunięta w błękit
  (`#080b12` → `#18212f`) i jeden akcent `#3b82f6`. Bez czystej szarości,
  bez cieni jako głównego separatora (włosowe obramowania 1 px).
- **Typografia**: Manrope **self-hosted** (40 KB, oś wagi 400–800) zamiast
  Google Fonts — PWA działa bez sieci zewnętrznych; `preload` wariantów
  latin i latin-ext; `tabular-nums` wszędzie, gdzie liczby się porównują.
- **Ikony**: własny zestaw 20 ikon SVG (siatka 24, kreska 1.75, zaokrąglone
  końce). **Wszystkie glify Unicode i emoji wycofane** z ról ikonowych.
  Globalny bezpiecznik `svg { width: 1em; height: 1em }` — bez niego ikona
  w panelu wypożyczenia rozpychała panel na pół strony.
- **Sygnatura „Rytm kodu"**: pasek regału pod paskiem narzędzi to prawdziwy
  pasek kodowy — liczba modułów = liczba książek; nad każdą kartą katalogu
  biegnie pasek modułów, którego długość niesie ocenę. Pasek rysuje się
  od lewej przy pierwszym renderze (420 ms, exponential ease-out,
  `prefers-reduced-motion` → bez animacji).
- **Powłoka przeglądarki w systemie**: zaznaczenie, widoczny fokus, paski
  przewijania, filtr ikony kalendarza, cyfry tabularne.
- Odznaki stanu: kolorowy `border-left` (an typozorzec „side-tab") zastąpiony
  **ikoną + tekstem** — stan niesie kształt, kolor tylko wzmianka. Toasty
  analogicznie.

**Poprawione przy okazji:**
- **Nagłówek wychodził poza ekran na telefonie** — „Wyloguj" był ucięty przy
  390 px. Akcje trafiły do jednego kontenera `.naglowek-akcje`, który zawija się
  razem; na ≤ 560 px nagłówek ma dwa rzędy, wersja jest ukryta, a przycisk Dodaj
  to sama ikona.
- **Chip półki rozciągał się na całą szerokość karty** — trafiał bezpośrednio do
  flexowego `.karta-tresc` bez kontenera `.chip-row` (i mimo `flex: 0 0 auto`
  rozciągał się). Teraz oba typy chipów są w tym samym kontenerze.
- Przycisk Dodaj lądował w trzecim wierszu paska — wyszukiwanie zajmuje całą
  szerokość, narzędzia jeden rząd.
- Odznaki w siatce wyrównane do doła (pierwsza odznaka `margin-top: auto`),
  dzięki czemu tytuły nie skaczą przy różnej liczbie tagów.
- Placeholder okładki bez przekątnych pasów (gładka powierzchnia + pasek u góry).

**Bez zmian:** wszystkie funkcje, zachowanie i teksty. Nadal ciemny motyw i
niebieski akcent — ustalenie właściciela z rozmowy potwierdzone jako wiążące.

**Detektor antywzorców:** uruchomiony na `css/style.css` — dwa ostrzeżenia
`side-tab` i jedno advisory `codex-grid-background`; ostrzeżenia usunięte,
advisory przyjęte świadomie (opisane w `DESIGN.md`).

**Testy:** 116 (346 asercje) PHP bez zmian. Weryfikacja na żywym serwerze:
65 ikon SVG, 0 glifów Unicode w interfejsie, 0 błędów JS, render sprawdzony
na 390×844 i 1280×900 (`.impeccable/review/`).

## 0.12.0 (2026-10-02)

Statystyki katalogu + skróty klawiaturowe i kontekstowe puste stany.

**Dodano:**
- `api/stats.php` + widok **#/statystyki**: liczba książek, stron łącznie, średnia
  ocena, aktywne wypożyczenia (kafelki), podział na statusy czytania, rozkład
  lat wydań, najczęstssi autorzy, najczęstsze tagi (klikalne — filtrują
  katalog) i sekcja „czeka na uzupełnienie" (bez okładki / oceny / opisu).
  Wielkości tysięczne skracane („12,4 tys."), żeby kafelki się mieściły.
- **Skróty klawiaturowe** (widok katalogu): <kbd>n</kbd> nowa książka,
  <kbd>/</kbd> skup kursor na wyszukiwaniu, <kbd>s</kbd> statystyki — podpowiedź
  w stopce katalogu. Ignorowane w polach tekstowych, przy modyfikatorach
  (Ctrl+R) i przy otwartym modalu/dropdownie.
- **Widok „do przeczytania"** (#/do-przeczytania) — katalog z wymuszonym
  statusem (filtr ukryty, jak w widoku półki), przycisk w nagłówku.
- **Puste stany zależne od kontekstu** — zamiast „katalog jest pusty" w widoku
  „do przeczytania" / „wypożyczone" / pustej półki mamy właściwy komunikat
  z powrotem do katalogu.
- Przyciski nawigacji w nagłówku katalogu: „do przeczytania" i „statystyki".
- `js/statystyki.js` dodany do powłoki w `sw.js`.

**Bez zmian:** schemat bazy — same zapytania agregujące (i tak wszystko liczy się
po stronie SQL). Statystyki nie respektują filtrów katalogu, liczą cały katalog.

**Naprawione:**
- `tylkoStatus`/`tylkoAktywne` w `renderLista()` odwoływały się do `const`
  zadeklarowanego wewnątrz `katalog()` → ReferenceError przy pustym wyniku
  („tylkoStatus is not defined"). Kontekst widoku trzymany jest teraz w
  module (`kontekstWidoku`).
- Średnia ocena wyświetlała się jako „4.14" zamiast „4,14" (format polski).
- Widok statystyk na desktopie zajmował 673 px z 1280 — `.tresc` centruje
  zawartość; dodany wariant `.tresc-szeroka` (max 1200 px, rozciągnięte).
- **`format: 'miękka'` z polskim znakiem było odrzucane przez walidację**
  (słowniki trzymane są bez polskich znaków). Nowy `slug_polska()`
  normalizuje wejście; użyty też w filtrach listy.

**Dane przykładowe:** 10 książek z prawdziwą metryczką (Solaris ×2, Cyberiada,
Ferdydurke ×2, Lód, Mały Książę, Pan Tadeusz, Lalka, Ziemia obiecana) —
ISBN-y zweryfikowane sumą kontrolną, metryczka z BN/Google/Open Library,
2 półki (Saloner, Na biurku), 7 tagów, oceny, formaty, 1 aktywne wypożyczenie.
Dzięki temu statystyki i filtry mają realne dane do pokazania. Do wyczyszczenia:
wyeksportuj je (ZIP) i usuń rekordy, jeśli nie chcesz ich zostawić.

**Testy:** 116 (346 asercje) — nowe testy `slug_polska()`; Playwright na żywym
serwerze **28/28** (statystyki na telefonie i desktopie, klik w tag → filtr,
widok do-przeczytania, skróty klawiaturowe, puste stany w czterech kontekstach).

## 0.11.0 (2026-10-02)

Oceny gwiazdkami 1–5.

**Dodano:**
- Kolumna `ocena` (TINYINT, NULL = nie oceniona), migracja przez
  `db_kolumna_dodaj()`.
- **Gwiazdki w formularzu** (`gwiazdki()` / `gwiazdkiPodlacz()` w `js/ui.js`) —
  klik w 4. gwiazdkę zaznacza 1–4; klik w tę samą czyści, obok pojawia się
  „✕". Stan w `data-wartosc`.
- **Gwiazdki na karcie książki klikalne** i zapisują od razu (jak status
  czytania) — bez wchodzenia w edycję. W katalogu **readonly** (same gwiazdki,
  mniejsze, brak klikania).
- Filtry katalogu: **Ocena** (wszystkie / ocenione / bez oceny) i sortowanie
  „Ocena: najwyższa / najniższa".
- Eksport i import przenoszą ocenę.

**Naprawione:**
- **Klasa `jest-zaznaczona` trafiała jako osobny atrybut HTML** zamiast klasy
  (`class="..."` + `jest-zaznaczona` obok) — po renderze z serwera gwiazdki
  wyglądały, jak nieocenione, mimo poprawnej oceny w bazie. Segmenty formatu
  były zrobione dobrze, tylko gwiazdki miały błąd.
- `ocena: 0` z UI było odrzucane przez walidację — `niepuste()` traktuje "0"
  jako niepusty string. Teraz 0 jest obsłużone ręcznie jako „brak oceny".
- **Sortowanie po kolumnach nullable przy `ASC` lądowało na NULL-ach na
  początku listy** (MySQL traktuje NULL jako najmniejszą wartość) — czyli
  „sortuj po ocenie rosnąco" dawałoby nieocenione książki pierwsze, a nie
  ostatnie. Dokładamy `ocena IS NULL ASC` dla kolumn `ocena` i `data_edycji`.

**Testy:** 113 (336 asercje) — nowe `tests/OcenaTest.php` (walidacja zakresu,
0/pusta/smieci, sortowanie z NULL na końcu, filtr, import). Na żywym serwerze:
Playwright **18/19** — klikanie, toggle, trwałość po odświeżeniu, sortowanie
z nieocenionymi na końcu, filtry, readonly w katalogu, eksport → import.

## 0.10.0 (2026-10-02)

Metryczka egzemplarza: format oprawy i szerokość.

**Dodano:**
- Kolumny `format` (`twarda` / `miekka`) i `szerokosc` (`waski` /
  `standard` / `szeroki`) — **wartości egzemplarza, nie książki**: ten sam
  tytuł ma wydania twarde i miękkie, a każde jest osobnym wierszem katalogu.
  Migracja przez `db_kolumna_dodaj()` (kolumny nullable — stare rekordy bez
  tych wartości działają).
- Formularz: wybór **przyciskami-segmentami**, nie dropdownem — na telefonie
  od razu widać wszystkie opcje (`segmentyFormat()` w `js/ui.js`, stan w
  `data-wartosc`, rola `radiogroup`). Opcja „— brak —" czyści wartość.
- Karta książki: wiersze „Format" i „Szerokość" w metryczce (puste przy
  starych rekordach, bo `metryka` odrzuca puste wartości).
- Filtry katalogu: **Format** i **Szerokość** (dokładne, po wartości).
- Eksport JSON/CSV/ZIP i import przenoszą te pola (round-trip).

**Naprawione:**
- **Lista kolumn do zapisu była sklejana w trzech miejscach** (INSERT i UPDATE
  w `save.php` plus własny INSERT w imporcie). Nowe `format`/`szerokosc`
  trafiły do dwóch, a import o nich zapomniał — przywrócenie z pliku gubiło
  format. Teraz jedno źródło: `ksiazka_kolumny_db()` + `ksiazka_wstaw()` +
  `ksiazka_zaktualizuj()` w `config.php`; `save.php` i import korzystają z nich.
  Kolejne nowe pole dopisujemy w jednym miejscu.

**Bez zmian:** `.pole` nie ma nowego typu pola, więc wygląd segmentów to
jeden blok w `css/style.css` (`.segmenty`, `.segment`, `.segment.jest`).

**Testy:** 99 (303 asercje) — nowe `tests/EgzemplarzTest.php` (walidacja obu
pól, wielkość liter, odrzucanie śmieci, filtry, import). Na żywym serwerze:
API **19/19** (walidacja, zapis/odczyt, filtry, eksport → import round-trip) oraz
UI **18/18** (segmenty, zaznaczanie, `aria-checked`, edycja, czyszczenie,
filtry).

## 0.9.0 (2026-10-02)

Import z pliku eksportu (round-trip) — pełne odtwarzanie katalogu.

**Dodano:**
- `api/import.php` — import **własnego** eksportu JSON
  (`biblioteka-eksport/v1`; cudzy plik dostaje czytelny komunikat, nie ma
  parsera pod obce formaty).
- **Dwa tryby**:
  - *Scal z katalogiem* — dodaje tylko brakujące egzemplarze, dopasowując po
    ISBN (normalizowanym), a bez ISBN po tytule + roku. Istniejące nietknięte.
  - *Przywróć* — najpierw czyści katalog (książki, tagi, półki, wypożyczenia),
    potem wczytuje plik. Wymaga **podwójnego potwierdzenia** (bez niego serwer
    zwraca 428).
- Odtwarzane są **tagi, półki (po nazwach — identyfikatory nie są przenośne
  między instalacjami) i historia wypożyczeń** (powiązana po kluczu egzemplarza).
- UI: wybór pliku → podgląd zawartości (liczba książek, wypożyczeń, tagów,
  półek, data eksportu) → przyciski skal/przywróć; wynik i lista pominiętych
  tytułów zostają w modalu (bez systemowego `alert`).

**Naprawione:**
- **Zagnieżdżone linki w kartach katalogu** — chipy tagów i półek były `<a>`
  wewnątrz `<a class="karta">` / `<a class="wiersz">`, czyli niepoprawny HTML.
  Parser wywędzał link z wnętrza i DOM dostawał bzdury: w siatce chipy lądowały
  **obok** kart, nie w nich. Teraz na karcie/wierszu chip to `<span>` z
  `data-tag` (klik i Enter obsługuje katalog, `stopPropagation` żeby nie
  odziedziczyć nawigacji po karcie), a link `<a>` został tylko tam, gdzie nie
  ma nadrzędnego linku (kartka książki). Nowy wariant `polkaZwykla()`.
- `polki_nazwy_normalizuj()` — nowa funkcja; nazwy półek z eksportu nie są
  rzutowane na int jak `id_listy_normalizuj()` (dałoby 0 i gubiłoby powiązanie).
  Trzyma pierwszą pisownię, tak jak tagi.
- `import_katalog_wyczysc()` kasował tabele powiązań po `user_id`, którego tam
  nie ma (właścicielem jest książka) → SQL 1054 i import „przywróć” padał
  z 500. Powiązane znikają kaskadą razem z książkami.
- `.user.ini` — nadpisanie pliku przy dodaniu opcache **zgubiło limity uploadu**
  (`upload_max_filesize`, `post_max_size`…). Przywrócone i podbity
  `post_max_size` (import idzie jako treść POST-a).

**Bezpieczeństwo:** limit 5000 rekordów na plik, 8 MB na treść, wejście
przechodzi przez `ksiazka_waliduj()` (jak formularz), import w transakcji per
egzemplarz.

**Testy:** 88 (278 asercje) — nowe `tests/ImportTest.php` (rozpoznanie formatu,
limity, klucze dopasowania, mapa wypożyczeń, rozpakowanie rekordu, nazwy półek).
Na żywym serwerze: API **35/35** (pełny round-trip: eksport → wyczyszczenie →
przywrócenie → porównanie pól, tagi, półki, wypożyczenia, walidacja śmieci w
pliku) oraz UI **19/19** (wybór pliku, podgląd, śmieci, obcy format,
podwójne potwierdzenie, scal bez duplikatów).

## 0.8.0 (2026-10-02)

Wypożyczenia z historią — kto, od kiedy, kiedy zwrócił.

**Dodano:**
- Tabela `bib_wypozyczenia` (osoba, data wypozyczenia, data zwrotu, uwagi).
  Wiersz zostaje po zwrocie — to historia. `data_zwrotu` NULL = wciąż
  wypożyczone. Usunięcie egzemplarza sprząta wypożyczenia (FK `ON DELETE CASCADE`).
- `api/wypozyczenia.php`: GET (lista aktywnych albo historia jednej książki),
  POST `wypozycz` / `zwroc` / `usun`. Jedna książka = najwyżej jedno aktywne
  wypożyczenie (pilnuje koda — UNIQUE z NULL nie działa w MySQL).
- **Panel wypożyczenia na karcie książki**: stan (kto, od kiedy, uwagi),
  przycisk „Wypożycz komuś" (modal z osobą i uwagami), „Zaznacz jako zwróconą",
  zwinięta **historia** zwrotów z możliwością usunięcia wpisu.
- **Odznaka „⇄ ktoś"** na kartach katalogu (siatka i lista).
- Widok **„#/wypozyczone"** — lista wszystkich aktywnych wypożyczeń.
- Filtry: **Wypożyczenie** (wszystkie / wypożyczone / na regale) oraz
  sortowanie **„Najdawniej wypożyczone"**.
- Eksport JSON/CSV/ZIP zawiera historię wypożyczeń (powiązaną po ISBN —
  identyfikatory książek nie są przenośne między instalacjami).

**Naprawione:**
- `wypozyczenie_data()` przepuszczał regexem daty nieistniejące
  („2026-13-45") — dodany `checkdate()`.

**Świadomie poza zakresem:** oczekiwana data zwrotu (decyzja użytkownika) —
wypożyczenie kończy się wtedy, kiedy klikniesz „Zaznacz jako zwróconą”.

**Testy:** 74 (233 asercje) — nowe `tests/WypozyczeniaTest.php` (daty,
walidacja, opisy, filtr) + test filtra w `lista_zapytanie()`.

## 0.7.0 (2026-10-02)

Tagi i półki — dwie relacje wiele-do-wielu obok metryczki.

**Dodano:**
- **Tagi** — słownik `bib_tagi` + powiązania `bib_ksiazka_tagi`. W formularzu pole
  tekstowe „po przecinku" (maks. 12, 40 znaków) z podpowiedziami z istniejących
  tagów; na karcie i w katalogu klikalne chipy `#fantastyka` — klik dodaje tag
  do filtra. Duplikaty bez rozróżniania wielkości liter („SciFi" = „scifi").
- **Półki** — słownik `bib_polk` + powiązania `bib_ksiazka_polk`, własny widok
  `#/polka/<id>` (nagłówek z nazwą i opisem, „← Wszystkie książki"). Zarządzanie
  przyciskiem „▤" w nagłówku katalogu: dodanie, usuwanie (książki zostają).
  Usunięcie półki zdejmuje ją z książek (FK `ON DELETE CASCADE`).
- Filtry katalogu: **Tagi** (AND — wiele tagów zawęża) i **Półka** (własny
  dropdown, `polka=<id>`).
- Sortowanie **„Ostatnio zmieniane"** (`data_edycji`).
- Wyszukiwarka szuka też w **podtytule, opisie, notatkach i wydawcy** (7 pól
  zamiast 3) — zmiana placeholdera na „Szukaj: tytuł, autor, ISBN, notatki…".
- Licznik wyników nad listą („128 książek").
- Eksport JSON/CSV/ZIP zawiera tagi i półki (w CSV jako tekst po przecinku,
  w JSON także słowniki) — import round-trip będzie je odtwarzał.
- `api/tagi.php` (lista, usunięcie), `api/polki.php` (lista, zapis, usunięcie).

**Naprawione:**
- `tagi_do_zapisu()` nie deduplikował po kluczu — ta sama etykieta podana
  dwukrotnie (lub różną wielkością liter) dwa razy trafiała do powiązań
  książka↔tag. Teraz jeden wpis na klucz (`tests/TagiTest.php`).
- **`.user.ini`: `opcache.validate_timestamps=1`** — hosting trzymał opcache
  w trybie bez sprawdzania znaczników czasu, przez co wgrany poprawiony
  `config.php` był wciąż wykonywany w starej wersji (500 na `save.php`
  z tagami mimo identycznego pliku na serwerze). Bez tego każda poprawka
  PHP wymagałaby grzebania w panelu hostingu.
- `tagi_ksiazki_zapisz()` / `polki_ksiazki_zapisz()` przyjmują `mixed`
  (formularz wysyła tagi jako tekst, półki jako listę).

**Baza:** `bib_tagi`, `bib_polk`, `bib_ksiazka_tagi`, `bib_ksiazka_polk` tworzone
przez `db_tabele()` przy pierwszym połączeniu — migracja nie wymaga ręcznych
operacji. Powiązania znikają razem z książką (kaskada).

**Świadomie poza zakresem:** miejsce na półce (kolejność na regale) — osobny
pomysł, wymaga innego modelu niż tag/półka.

**Testy:** 57 (194 asercje) — nowe `tests/TagiTest.php` (normalizacja, limity,
duplikaty, deduplikacja klucza, walidacja nazwy półki) + filtry tagów/półek
w `ListaTest.php`. Testy funkcjonalne na hostingu: Playwright **30/30**
(tworzenie półki, zapis z tagami, deduplikacja, chipy, filtr tagów i półek,
widok `#/polka/<id>`, szukanie w notatkach, eksport z tagami i półkami,
usuwanie półki przy zachowaniu książek, sprzątanie danych).

## 0.6.0 (2026-10-02)

Poprawki stylu UI: rozwijane menu w szacie serwisu, zwijane filtry, pełna
szerokość karty książki, przycisk powrotu.

**Dodano:**
- `wybieracz()` w `js/ui.js` — własny dropdown (przycisk + lista) w szacie
  serwisu, **zastępuje wszystkie natywne `<select>`**, które na telefonie
  otwierają systemowe okienko: sortowanie, filtr statusu (katalog), status
  czytania (karta książki i formularz). Odczyt wartości: `dataset.wartosc`,
  zamknięcie po kliknięciu w tło / Escape, tylko jedno menu naraz.
- **Przycisk „← Katalog" na karcie książki** (nad okładką, pełna szerokość
  gridu) — wcześniej powrót był możliwy tylko logo w nagłówku lub przyciskiem
  wstecz telefonu.
- Własny przycisk „📷 Dodaj zdjęcie" zamiast natywnego inputa plikowego
  („Choose File" z systemowego okienka).

**Zmieniono:**
- **Filtry faktycznie zwijane**: `.panel-filtrow[hidden] { display: none }` —
  wcześniejsze `display: grid` nadpisywało atrybut `hidden`, więc panel filtrów
  był zawsze rozwinięty (i zajmował pasek 193 px na telefonie).
- **Sortowanie przeniesione z paska do panelu filtrów** — pasek narzędzi to
  teraz tylko: szukaj + Filtry (z strzałką ▾ i licznikiem) + przełącznik widoku
  + „Dodaj książkę"; parametry (sortowanie, status, autor…) widoczne dopiero
  po kliknięciu „Filtry".
- **Karta książki na pełną szerokość**: usunięty `max-width: 900px` z centrowaniem,
  jedna kolumna do 860 px (telefony w poziomie też), okładka na całą szerokość
  telefonu (było 210 px), zniesiony limit 320 px na polach (status/notatki).
- `sw.js`: cache `biblioteka-v0.6.0` (PWA dostaje nowe pliki CSS/JS).

**Testy:** PHP 34 (144 asercje) bez zmian — zakres tylko JS/CSS.

## 0.5.0 (2026-10-02)

Lookup: **Biblioteka Narodowa jako pierwsze źródło** (BN → Google Books → Open Library).

**Dodano:**
- `bn_ksiazka()` w `lib/lookup_zrodla.php` — API `data.bn.org.pl`
  (`/api/institutions/bibs.json?isbnIssn=…&limit=1`, bez klucza, bez limitu).
- `bn_na_wspolne()` w `lib/logika.php` — mapper rekordu BN (MARC): tytuł 245|a/b,
  autorzy 100 + 700 (z ról tylko autorstwo — bez tłumaczy/redaktorów, nazwiska
  w naturalnej kolejności „Imię Nazwisko”), rok (publicationYear / 260|c),
  wydawca 260|b, liczba stron z 300|a, opis 520|a (rzadkość → zwykle null),
  tytuł oryginału 246, ISBN z zapytania lub 020|a. **BN nie ma okładek**
  → `okladka_url` = null i uzupełnia je Google/OL.
- Helpery: `bn_pola()`, `bn_pole()`, `bn_czysc_tytul()`, `bn_czysc_wydawce()`,
  `bn_naturalnie()`.

**Zmieniono:**
- `ksiazka_scal()` → wariantywna: kolejność wywołania = priorytet
  (`ksiazka_scal($bn, $google, $ol)`); dalsze źródła uzupełniają tylko puste pola.
- `api/lookup.php`: kolejność BN → Google → OL, `zrodlo` z prefiksem `bn`
  (np. `bn+google+ol`); 503, gdy **żadne** z trzech źródeł nie odpowie.
- Cache „brak” zapisywany dopiero gdy **wszystkie trzy** źródła odpowiedziały 200
  — przy awarii jednego źródła brak nie jest cache’owany (kolejne skanowanie pyta ponownie).
- Biała lista `zrodlo` w walidacji: + `bn`, `bn+google`, `bn+ol`, `bn+google+ol`.
- Frontend: czytelne nazwy źródeł („Biblioteka Narodowa + Google Books”).

**Testy:** 34 (144 asercje) — +3 na mapper BN, +1 merge 3-źródeł.

## 0.4.1 (2026-10-02)

Na czas testów: **blokada logowania po nieudanych próbach wyłączona**.

**Zmieniono:**
- `login_blocked()` w `config.php` i `config.example.php` zwraca `false`
  (zamiast liczyć `bib_login_attempts` ≥ `LOGIN_MAX_ATTEMPTS`). Stałe
  `LOGIN_MAX_ATTEMPTS = 99999` i `LOGIN_ATTEMPT_WINDOW` pozostają — przywrócenie
  = wklejenie oryginalnej funkcji z tego wpisu:

  ```php
  function login_blocked(string $key): bool
  {
      $stmt = db()->prepare('SELECT COUNT(*) FROM bib_login_attempts WHERE klucz = ? AND created_at > ?');
      $stmt->execute([$key, date('Y-m-d H:i:s', time() - LOGIN_ATTEMPT_WINDOW)]);
      return (int) $stmt->fetchColumn() >= LOGIN_MAX_ATTEMPTS;
  }
  ```

- Przed włączeniem blokady wyczyścić stare rekordy: `DELETE FROM bib_login_attempts`.

## 0.4.0 (2026-10-01)

Lookup naprawiony pod realia hostingowe (diagnoza na żywym serwerze).

**Naprawiono:**
- **Open Library**: stare `api/books` zwraca 404 wszędzie (endpoint wycofany) —
  źródło przepięte na `search.json?isbn=…&fields=…`. Nowy mapping
  `ol_na_wspolne` (dokument z `docs`: `author_name`, `first_publish_year`,
  `publisher`, `number_of_pages_median`, `language`, `cover_i` → okładka
  `covers.openlibrary.org/b/id/…-L.jpg`). `search.json` nie zwraca opisów —
  `opis` nadal wyłącznie z Google. Testy przepisane + nowy przypadek pustych
  tablic/braku okładki (razem 30).
- **Google Books bez klucza = HTTP 429 globalnie** (wyczerpana doba kwota
  publicznego projektu Google’a — nie tylko z IP hostingu). Lookup traktuje
  to jak niedostępne źródło, nie jak „brak książki”.
- `http_pobierz_json()` zwraca kod HTTP; lookup rozróżnia „źródło odpowiedziało:
  nie zna tej książki” (cache’ujemy „brak”) od „źródło nie odpowiedziało”
  (nie cache’ujemy — wcześniejszy błąd HTTP zapisywał się w cache jako
  trwały „brak”).
- Gdy **oba** źródła nie odpowiadają → HTTP 503 z komunikatem dla użytkownika
  (toast na froncie), bez zapisu do cache.
- `GOOGLE_BOOKS_KEY` w `config.php` czeka na własny klucz (opcjonalnie) —
  wtedy Google wraca jako pierwsze źródło.

## 0.3.1 (2026-10-01)

Prefiks tabel od początku istnienia projektu i limity uploadu — szykowanie pod deploy.

**Zmieniono:**
- Tabele Biblioteki dostały prefiks `bib_`: `bib_ksiazki`, `bib_isbn_cache`,
  `bib_sesje`, `bib_login_attempts` (SQL w `config*` i `api/*`; klucze JSON
  API i nazwy zmiennych bez zmian). Powód: aplikacja może dzielić **jedną bazę
  MySQL** z innym oprogramowaniem na tym samym hostingu, a sąsiedni projekt
  używa już tabel `sesje` (z FK do `users` i kolumną `created_at`, których
  w Bibliotece nie ma) oraz `login_attempts` (wspólny limit prób logowania =
  blokada wzajemna). Prefiks eliminuje kolizję na starcie.
- Dodano `.user.ini` (limity wgrywania zdjęć: upload 12M, post 128M)
  oraz `uploads/.htaccess` (`Options -Indexes`, ukrywanie katalogu ze zdjęciami).

## 0.3.0 (2026-10-01)

Skaner kodów, zdjęcia egzemplarzy i eksport danych.

**Dodano:**
- `js/skaner.js`: aparat w modalu — natywne `BarcodeDetector` (Chrome/Android),
  fallback `html5-qrcode` z `js/vendor/` (Safari/iOS, bez builda). Skanowanie
  rozpoznaje wyłącznie EAN-13 z prefiksem 978/979 i poprawną sumą kontrolną
  (obce kody odrzucone, dalej skanujemy). Wykrycie ISBN → automatyczny lookup →
  podgląd metryczki przed zapisem.
- `api/photo.php`: upload zdjęcia własnego egzemplarza (JPG/PNG/WEBP, 10 MB,
  walidacja MIME przez fileinfo, nazwa `bin2hex(random_bytes(16))`, podmiana
  ze skasowaniem starego pliku) + usuwanie zdjęcia.
- Szczegóły książki: podgląd zdjęcia, aparat z `capture="environment"`,
  usuwanie z potwierdzeniem.
- `api/export.php`: **JSON** (round-trip `biblioteka-eksport/v1` — baza pod
  „przywrócenie z eksportu"), **CSV** (delimiter `;`, UTF-8 z BOM — Excel),
  **ZIP** (JSON + CSV + wszystkie okładki i zdjęcia + README); przycisk ⬇
  w nagłówku katalogu z modalem wyboru formatu.
- Logika CSV (`csv_wartosc`, `csv_linia`) + testy: razem 29.
- Nowe stałe konfiguracji: `ZDJECIA_DIR`, `ZDJECIA_URL`.

## 0.2.0 (2026-10-01)

Widok katalogu — pierwszy działający ekran po zalogowaniu.

**Dodano:**
- Katalog: siatka okładek ↔ lista wierszowa (przełącznik), placeholder z
  inicjałami gdy brak okładki, lazy loading obrazów.
- Zwijany panel filtrów: status, autor, rok, wydawca, język, zakres dat
  dodania + szybkie szukanie (tytuł/autor/ISBN) — każdy filtr po stronie
  serwera (białe listy + SQL parametryzowane).
- Sortowanie: tytuł/autor/rok/strony/data dodania, kierunek A→Z / odwrotnie,
  domyślnie tytuł A→Z; filtr-sort-widok zapisywane w localStorage.
- Szczegóły książki: metryczka, opis, zmiana statusu „z marszu", edycja
  notatek, edycja i trwałe usuwanie z modalem potwierdzenia.
- Formularz dodawania: lookup ISBN → **podgląd metryczki z edycją przed
  zapisem**, ostrzeżenie „masz już N egzemplarzy" przed dopisaniem kopii,
  tryb ręczny gdy bazy nie znają ISBN.
- Bezpieczeństwo: `zrodlo` z białej listy w walidacji (test), escaping XSS
  wszystkich danych z API, 401 z dowolnego endpointu → ekran logowania.
- Testy: +1, razem 26 (whitelist `zrodlo`).

## 0.1.1 (2026-10-01)

**Zmiana planu:**
- Import danych: zamiast parsera pod plik z innego programu (użytkownik nie
  dostarczy pliku) — wyłącznie **przywrócenie z eksportu** (round-trip w
  formacie naszego JSON/CSV). Bez migracji z cudzych systemów; ewentualny
  uniwersalny CSV-Importer → później, gdy będzie realna potrzeba.

## 0.1.0 (2026-10-01)

Szkielet projektu — API-first PWA, pierwszy commit.

**Dodano:**
- Schemat bazy tworzony automatycznie przez `db()`: `ksiazki` (jeden wiersz =
  jeden fizyczny egzemplarz), `isbn_cache`, `sesje`, `login_attempts` +
  migracje `SHOW COLUMNS`.
- Logowanie jednym hasłem, sesja cookie **24 h**, limit nieudanych prób (5 / 15 min).
- Lookup ISBN przez proxy: cache w bazie → Google Books → Open Library
  (Google pierwszeństwo, OL uzupełnia tylko puste pola), okładki pobierane
  na hosting.
- Endpointy: `stan`, `login`, `logout`, `lookup`, `list` (filtry + sortowanie
  z białych list), `get`, `save` (walidacja), `delete` (trwałe, z potwierdzeniem
  po stronie UI).
- Logika czysta w `lib/logika.php` + testy PHPUnit: ISBN (10/13 ze sumami
  kontrolnymi), mappingi API, merge źródeł, walidacja rekordu, budowniczy
  zapytania listy.
- PWA: `manifest.webmanifest`, service worker (cache powłoki, API zawsze z sieci),
  logo SVG „B" z motywem książki + ikony PNG (192/512/maskable).
- UI: logowanie i ekran startowy, motyw ciemny, akcent niebieski, krój Manrope.
- Git lokalny (bez GitHuba), `.gitignore` na `config.php`/`.env`/`uploads/`.

**W budowie (kolejne wersje):**
- `api/photo.php` i `api/export.php` → 501 (zdjęcie egzemplarza, eksport
  JSON+CSV+okładki).
- Widok katalogu: siatka okładek / lista, zwijany panel filtrów, sortowanie
  z zapamiętywaniem (localStorage), szczegóły książki, edycja.
- Skanowanie kodu (BarcodeDetector + fallback ZXing), podgląd metryczki
  przed zapisem, tryb ręczny przy nietrafieniu w bazy.
- Import CSV/JSON (po dostarczeniu pliku wzorcowego przez użytkownika).
