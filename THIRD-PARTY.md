# Biblioteki zewnętrzne

Projekt nie ma etapu budowania ani `npm` — biblioteki frontendu leżą jako
gotowe pliki w `js/vendor/`. Poniżej ich wersje, autorzy i licencje.

| Plik | Projekt | Wersja | Licencja | Użycie |
|---|---|---|---|---|
| `js/vendor/zxing.min.js` | [@zxing/library](https://github.com/zxing-js/library) | 0.21.3 | Apache-2.0 | dekodowanie kodów kreskowych z kamery (EAN-13, EAN-8, Code-128, ITF, UPC) |
| `js/vendor/html5-qrcode.min.js` | [html5-qrcode](https://github.com/mebjas/html5-qrcode) | 2.3.x | Apache-2.0 | zapasowa ścieżka skanera, gdy przeglądarka nie ma `BarcodeDetector` |
| `js/vendor/fflate.min.js` | [fflate](https://github.com/101arrowz/fflate) | 0.8.x | MIT | rozpakowanie EPUB (ZIP) w przeglądarce przy imporcie ebooków |
| `assets/fonts/manrope-*.woff2` | [Manrope](https://github.com/davelab6/manrope) | — | SIL Open Font License 1.1 | krój interfejsu (self-hosted, bez CDN) |

Teksty licencji Apache-2.0 i MIT nie zmieniają licencji tego projektu — dotyczą
wyłącznie wymienionych plików. OFL-1.1 wymaga zachowania nazwy fontu przy
modyfikacji plików `.woff2`.

Biblioteki PHP nie są używane — backend to czysty PHP 8 z PDO, bez Composera.
