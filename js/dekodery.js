/* Dekoder kodów kreskowych — ZXing-js (`@zxing/library`, MIT).
 *
 * DO 0.23.3 dekodera nie było w naszym kodzie w ogóle: skaner prosił
 * przeglądarkę o `window.BarcodeDetector` (Chrome na Androidzie robi to
 * przez ML Kit), a gdy go nie było, włączał html5-qrcode. Na telefonie
 * właściciela licznik `odczyty` trwał w zerach przez sześć wersji, a ja nie
 * miałem jak sprawdzić, czy problem leży w obrazie, kadze, formacie czy
 * walidacji — bo dekodera nie kontrolowaliśmy.
 *
 * ZXing wgrywamy sami (`js/vendor/zxing.min.js`, UMD, globalne `ZXing`),
 * więc:
 *
 * 1. **działa wszędzie** — Firefox, Safari, iOS — bez `BarcodeDetector`
 *    i bez html5-qrcode na ścieżce głównej;
 * 2. **jest testowalny lokalnie.** Do 0.23.3 dekodera nie dało się sprawdzić
 *    poza telefonem właściciela (w Chromium nie ma `BarcodeDetector`, a
 *    testy zastępowały go atrapą). Teraz dekodujemy prawdziwe kadry
 *    w Playwright i widać, czy kod się czyta;
 * 3. **wiemy, czego on chce** — patrz `PASY` niżej, czyli jedyna naprawa
 *    po stronie dekodowania, jakiej wymagał ten silnik.
 *
 * ## Zmierzone na ZXing 0.21.3 (720×1280, EAN-13, czysty obraz)
 *
 * | położenie kodu | wynik          |
 * |----------------|----------------|
 * | y = 250…970    | czyta          |
 * | y = 20…170     | **nie czyta**  |
 * | y ≥ 1000       | **nie czyta**  |
 *
 * Kod w górnej albo dolnej części kadru nie był czytany **żadnym**
 * wariantem: `HybridBinarizer`, `GlobalHistogramBinarizer`, `TRY_HARDER`,
 * obraz odwrócony. Czytał natomiast zawsze po przycięciu kadru do
 * połowy zawierającej kod — a to jest dokładnie ta sama operacja.
 *
 * Tło: czytniki ZXinga przeszukują wiersze **od pionowego środka obrazu**
 * na zewnątrz, więc kod leżący przy krawędzi zostaje pominięty, a głęboka
 * biel wokół niego psuje binaryzację. Tolerancja zmierzona na
 * wysokości kadru: ±25 %.
 *
 * Dlatego `PASY`: zamiast podawać dekoderowi cały kadr, ciągamy go na
 * pięć nakładających się pasów i każdemu dajemy szansę. Sprawdzone na
 * wszystkich pozycjach kodu i przy wysokości paska od 8 % do 45 % kadru —
 * 4 × 35 % nie pominęło ani jednej (`/tmp/opencode/probe_pasy3.js`).
 */

/** Wersja biblioteki — pokazywana w diagnostyce na urządzeniu. */
export const WERSJA_DEKODERA = '0.21.3';

/**
 * Pasy przeszukiwane po kolei, od najbardziej prawdopodobnego.
 *
 * Kolejność jest celowa: pierwszy pas zawiera środek kadru, więc w zwykłym
 * przypadku (kod w centrum) trafiamy po jednym `decode()` i pętla kończy
 * się szybko; kolejne pasy sięgają góry i dołu.
 */
export const PASY = [
    { y: 0.217, h: 0.35 },   // środek — najczęstszy traf
    { y: 0.433, h: 0.35 },   // środek–dół
    { y: 0.000, h: 0.35 },   // góra
    { y: 0.650, h: 0.35 },   // dół
];

const SRC = 'js/vendor/zxing.min.js';

let wczytywanie = null;

/**
 * Wczytuje bibliotekę (raz na stronę). Zwraca globalne `ZXing` albo
 * rzuca — wtedy wołający przełącza się na zapasową ścieżkę html5-qrcode.
 */
export function zaladujDekoder() {
    if (window.ZXing) {
        return Promise.resolve(window.ZXing);
    }
    if (!wczytywanie) {
        wczytywanie = new Promise((resolve, reject) => {
            const s = document.createElement('script');
            s.src = SRC;
            s.onload = () => (window.ZXing
                ? resolve(window.ZXing)
                : reject(new Error('zxing.min.js wczytany, ale bez globalnego ZXing')));
            s.onerror = () => reject(new Error('nie udało się wczytać dekodera kodów'));
            document.head.appendChild(s);
        }).catch((err) => {
            wczytywanie = null;   // nie zapamiętujemy porażki — powtórka spróbuje znowu
            throw err;
        });
    }
    return wczytywanie;
}

let czytelnik = null;
const buforow = new Map();

function czytelnikZXing(Z) {
    if (!czytelnik) {
        czytelnik = new Z.MultiFormatReader();
        // Lista formatów jest szeroka celowo: wynik i tak musi przejść
        // walidację ISBN, więc każdy dodatkowy format tylko zwiększa szansę
        // trafienia, a nie ryzyko fałszywego odczytu.
        czytelnik.setHints(new Map([[Z.DecodeHintType.POSSIBLE_FORMATS, [
            Z.BarcodeFormat.EAN_13, Z.BarcodeFormat.EAN_8,
            Z.BarcodeFormat.UPC_A, Z.BarcodeFormat.UPC_E,
            Z.BarcodeFormat.CODE_128, Z.BarcodeFormat.CODE_39,
            Z.BarcodeFormat.CODE_93, Z.BarcodeFormat.ITF,
            Z.BarcodeFormat.CODABAR, Z.BarcodeFormat.QR_CODE,
            Z.BarcodeFormat.DATA_MATRIX, Z.BarcodeFormat.RSS_14,
        ]]]));
    }
    return czytelnik;
}

/**
 * Kontekst 2D **zawsze bierzemy z aktualnie podanego canvasa**.
 *
 * Do 0.24.0 kontekst był zapamiętany w zmiennej modułowej. Canvas z
 * poprzedniej sesji wciąż zawiera w sobie ostatnią klatkę z kodem, więc przy
 * otwarciu kolejnego skanera dekoder czytał **stary obraz** i natychmiast
 * zgłaszał tę samą książkę (zgłoszenie właściciela). `getContext` na tym
 * samym canvasie i tak zwraca ten sam obiekt, więc nic nie tracimy.
 */
function kontekstCanvasa(canvas) {
    return canvas.getContext('2d', { willReadFrequently: true });
}

/**
 * RGBA → luminancja 1 bajt na piksel, czyli dokładnie to, czego wymaga
 * `ZXing.RGBLuminanceSource` (mierzone: podanie RGBA albo `Int32Array`
 * kończy się wyjątkiem).
 */
function naLuminancje(rgba, w, h) {
    const lum = new Uint8ClampedArray(w * h);
    let suma = 0;
    for (let i = 0, j = 0; i < lum.length; i++, j += 4) {
        const v = (rgba[j] * 77 + rgba[j + 1] * 150 + rgba[j + 2] * 29) >> 8;
        lum[i] = v;
        suma += v;
    }
    return { lum, srednia: suma / lum.length };
}

function pasBufor(w, wys) {
    const klucz = `${w}x${wys}`;
    let b = buforow.get(klucz);
    if (!b) {
        b = new Uint8ClampedArray(w * wys);
        buforow.set(klucz, b);
    }
    return b;
}

/**
 * Czyta kod z kanvasa.
 *
 * @param {HTMLCanvasElement} canvas obraz kadru (cała ramka)
 * @returns {Promise<null|{tekst: string, ms: number, pas: number}>}
 *          `null` gdy w żadnym pasie nie było kodu.
 */
export async function dekodujKadr(canvas) {
    const Z = await zaladujDekoder();
    const w = canvas.width;
    const h = canvas.height;
    if (!w || !h) {
        return null;
    }
    const kontekst = kontekstCanvasa(canvas);
    const t0 = performance.now();

    const { lum, srednia } = naLuminancje(
        kontekst.getImageData(0, 0, w, h).data, w, h
    );
    const czyt = czytelnikZXing(Z);
    // ZXing NIE resetuje stanu czytników między wywołaniami `decode()`
    // (udokumentowane: do skanowania ciągłego jest `decodeWithState()`
    // + ręczne `reset()`). Bez tego czytniki 1D gromadzą wewnętrzny stan
    // z poprzedniego kadru — niepożądanymi odczytami i gorszą wydajnością.
    czyt.reset();

    // Kadr wyraźnie ciemny → prawdopodobnie białe paski na czarnym tle.
    // Taki druk zdarza się na okładkach. Sprawdzamy tylko wtedy, bo w
    // zwykłym przypadku to tylko dodatkowy przebieg bez sensu.
    const odwracac = srednia < 100;

    for (let i = 0; i < PASY.length; i++) {
        const { y: fy, h: fh } = PASY[i];
        const yA = Math.max(0, Math.round(fy * h));
        const wys = Math.min(h - yA, Math.round(fh * h));
        if (wys < 8) {
            continue;
        }
        const bufor = pasBufor(w, wys);
        bufor.set(lum.subarray(yA * w, (yA + wys) * w));
        for (const odwrócony of (odwracac ? [false, true] : [false])) {
            const zrodlo = new Z.RGBLuminanceSource(bufor, w, wys);
            const baza = odwrócony ? new Z.InvertedLuminanceSource(zrodlo) : zrodlo;
            const bitmapa = new Z.BinaryBitmap(new Z.HybridBinarizer(baza));
            try {
                const wynik = czyt.decode(bitmapa);
                return {
                    tekst: wynik.getText(),
                    ms: Math.round(performance.now() - t0),
                    pas: i,
                };
            } catch {
                // brak kodu w tym pasie — norma, idziemy do następnego
            }
        }
    }
    return { tekst: null, ms: Math.round(performance.now() - t0), pas: -1 };
}