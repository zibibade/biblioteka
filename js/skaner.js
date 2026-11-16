/* Biblioteka — skaner kodów kreskowych.
   Ścieżka 1: natywne BarcodeDetector (Chrome/Android).
   Ścieżka 2: html5-qrcode z js/vendor (Safari/iOS, brak API).
   Książkowy kod na grzbiecie to EAN-13 z prefiksem 978/979 = ISBN-13.

   Detektor nie jest nasz — to API przeglądarki albo zewnętrzna biblioteka.
   Nasz kod to modal, filtr 978/979 i pętla wywołująca detekcję. */

import { komunikat, ikona, esc } from './ui.js';
import { dekodujKadr, zaladujDekoder, WERSJA_DEKODERA } from './dekodery.js';

let cleanup = null;      // bieżące zamknięcie kamery
let html5Zaladowana = false;
let html5Skaner = null;  // instancja biblioteki (potrzebna do latarki)

/** Czy 13-cyfrowy ciąg to książkowy ISBN (prefiks 978/979 + suma kontrolna). */
function isbn13(i) {
    if (!/^97[89]\d{10}$/.test(i)) {
        return null;
    }
    let suma = 0;
    for (let n = 0; n < 13; n++) {
        suma += Number(i[n]) * (n % 2 === 0 ? 1 : 3);
    }
    return suma % 10 === 0 ? i : null;
}

/**
 * ISBN-10 → ISBN-13 (prefiks 978, ta sama treść).
 *
 * Starsze książki bywają opisane Code-128 z dziesięciocyfrowym numerem,
 * a nasz warunek kończył się wtedy na `97[89]\d{10}` — poprawny numer
 * wyglądał jak brak odczytu.
 */
function isbn10Na13(i) {
    if (!/^\d{9}[\dX]$/.test(i)) {
        return null;
    }
    let suma = 0;
    for (let n = 0; n < 10; n++) {
        suma += (n === 9 && i[n] === 'X' ? 10 : Number(i[n])) * (10 - n);
    }
    if (suma % 11 !== 0) {
        return null;
    }
    const rdzen = '978' + i.slice(0, 9);
    let kontrolna = 0;
    for (let n = 0; n < 12; n++) {
        kontrolna += Number(rdzen[n]) * (n % 2 === 0 ? 1 : 3);
    }
    return rdzen + String((10 - (kontrolna % 10)) % 10);
}

/**
 * Odczytany kod → ISBN-13 albo `null`.
 *
 * Do 0.23.2 funkcja wymagała, by **cała** wartość była dokładnie 13-cyfrowa
 * z prefiksem 978/979. Wszystko inne kończyło się cichym `null`, a wywołujący
 * (`wykryto`) zwracał wtedy `false` bez komunikatu — skaner milczał
 * identycznie jak wtedy, gdy dekoder nic nie widział. Rozdzielamy teraz
 * przypadki, które realnie trafiają się na kodzie z książki:
 *
 * 1. cała wartość to ISBN-13;
 * 2. ISBN schowany w dłuższym odczycie — dopisek „ISBN…”, ITF-14
 *    (jedynka + EAN-13 na kartonie zbiorczym), doklejony kod ceny EAN-5;
 * 3. ISBN-10 jako cała wartość (albo za dopiskiem „ISBN”) → przeliczony.
 *
 * Punkt 3 jest celowo wąski: ISBN-10 szukamy tylko w całości wartości,
 * nigdy ze środka dłuższego ciągu cyfr — inaczej przypadkowy numer
 * (IMEI, numer partii) przechodziłby sumę kontrolną z szansą 1/11.
 */
export function isbnZKodu(raw) {
    const i = String(raw ?? '')
        .replace(/[\s-]/g, '')
        .toUpperCase();

    const caly = isbn13(i);
    if (caly !== null) {
        return caly;
    }

    const rx = /97[89]\d{10}/g;
    let fragment;
    while ((fragment = rx.exec(i)) !== null) {
        const v = isbn13(fragment[0]);
        if (v !== null) {
            return v;
        }
    }

    const dziesiatka = i.match(/^(?:ISBN)?(\d{9}[\dX])$/);
    if (dziesiatka !== null) {
        return isbn10Na13(dziesiatka[1]);
    }

    return null;
}

/* ------------------------------------------------------------- latarka */

/**
 * Najdłuższy bok obrazu podawanego detektorowi.
 *
 * Detektory kodów wewnętrznie zmniejszają obraz do własnego limitu
 * (~1000–2000 px). Podanie 3500-px wycinka nie daje więcej informacji —
 * tylko tyle, ile i tak zostanie z niego wzięte, a kod zajmuje wtedy
 * mniejszą część wejścia i czyta się **gorzej**. Mniejszy obraz jest też
 * dużo szybszy na telefonie.
 */
const WYMIAR_DETEKTORA = 960;

/**
 * Kadry przekazywane dekoderowi.
 *
 * Do 0.23.3 detektor dostawał wyłącznie środek kadru (92 % × 60 %), a wariant
 * „cały obraz” to był 100 % × 65 % raz na cztery detekcje — pełnego kadru nie
 * skanował nigdy. Teraz dekoder dostaje **całą ramkę**, a podział na pasy robi
 * ZXing w `dekodery.js` (tam jest powód: czytniki ZXinga szukają od pionowego
 * środka obrazu na zewnątrz i kodu przy krawędzi nie widzą).
 *
 * `KADR_PODGLADU` to wycinek pokazywany użytkownikowi — ten sam co poprzednio,
 * żeby nie zmieniać wyglądu podglądu.
 */
const KADR_DEKODERA = { szer: 1.00, wys: 1.00, nazwa: 'cały kadr' };
const KADR_PODGLADU = { szer: 0.92, wys: 0.60, nazwa: 'środek 92 %' };

/**
 * Diagnostyka na urządzeniu.
 *
 * Skaner „nie czytał” przez sześć wersji, a ja nie miałem żadnego sygnału
 * z telefonu użytkownika — zgadywałem między kadrem, formatem i dekoderem.
 * Te liczniki rozdzielają stany, które z zewnątrz wyglądają identycznie:
 *
 * - **proby = 0** → pętla detekcji nie rusza (albo nie ta ścieżka);
 * - **proby rosną, puste = próby** → dekoder dostaje kadry, ale w żadnym
 *   pasie nie znajduje kodu (problem z obrazem, kadrem albo formatem);
 * - **awarie rosną** → dekodowanie rzuca wyjątek; do 0.23.2 `catch` był
 *   pusty i gubił go, więc wyglądało to jak brak odczytu;
 * - **odczyty rosną, odrzucone rosną** → kod jest czytany, ale nie przechodzi
 *   walidacji ISBN (np. ITF-14 albo Code-128 z dopiskiem „ISBN”) — a to
 *   dotąd kończyło się **cichym** `return false`, identycznie jak brak kodu.
 * - **`czytanie NN ms`** → koszt jednej próby na tym urządzeniu. ZXing
 *   dekoduje w JS, więc na wolnym telefonie jedna klatka potrafi trwać
 *   300 ms; bez tego licznika nie da się odróżnić „nie czyta” od „czyta,
 *   ale za wolno, żeby nadążyć”.
 *
 * Tekst pokazuje się razem z podglądem kadru, czyli dokładnie wtedy, gdy
 * odczyt nie udaje się przez 3,5 s.
 */
const diag = {
    sciezka: '', proby: 0, odczyty: 0, odrzucone: 0, ostatni: '',
    puste: 0, awarie: 0, ostatniBlad: '', czas: 0,
};

function diagReset() {
    diag.sciezka = '';
    diag.proby = 0;
    diag.odczyty = 0;
    diag.odrzucone = 0;
    diag.ostatni = '';
    diag.puste = 0;
    diag.awarie = 0;
    diag.ostatniBlad = '';
    diag.czas = 0;
}

function diagTekst(video) {
    const rozd = (video && video.videoWidth)
        ? `${video.videoWidth}×${video.videoHeight}`
        : '—';
    const czesci = [
        `Ścieżka: ${diag.sciezka || '—'}`,
    ];
    if (diag.sciezka === 'ZXing') {
        czesci.push(`ZXing ${WERSJA_DEKODERA}`);
    }
    czesci.push(`kadr ${rozd}`);
    czesci.push(`próby ${diag.proby}`);
    if (diag.czas > 0) {
        czesci.push(`czytanie ${diag.czas} ms`);
    }
    czesci.push(`puste ${diag.puste}`);
    czesci.push(`odczyty ${diag.odczyty}`);
    czesci.push(`odrzucone ${diag.odrzucone}`);
    if (diag.awarie > 0) {
        czesci.push(`AWARIE ${diag.awarie}${diag.ostatniBlad ? ` (${diag.ostatniBlad})` : ''}`);
    }
    if (diag.ostatni) {
        czesci.push(`ostatni: „${diag.ostatni}”`);
    }
    return czesci.join(' · ');
}

/** Ścieżka wideo z podglądu (oba tryby: natywny i html5-qrcode). */
function torczSciezka(video) {
    const torby = video?.srcObject?.getVideoTracks?.() ?? [];
    return torby[0] ?? null;
}

/**
 * Czy aparat w ogóle ujawnia właściwości (nie „czy ma latarkę").
 *
 * Do 0.20.0 istniała tu funkcja `latarkaDostepna()` sprawdzająca
 * `'torch' in getCapabilities()` i przycisk latarki był pokazywany tylko gdy
 * to zachodziło. Na Androidzie Chrome właściwości często nie raportuje, więc
 * **przycisk znikał i latarki nie dało się włączyć w skanerze**. Funkcja
 * została usunięta — jedyną próbą jest samo włączenie (`latarkaUstaw`), bo
 * tylko wyjątek z `applyConstraints` jest wiarygodnym sygnałem porażki.
 *
 * Zostawiamy tu sam odczyt możliwości, bo przyda się do dobierania rozdzielczości
 * i trybu focus (`mozliwosci()` zamiast `getCapabilities()`).
 */
function mozliwosci(video) {
    const torcz = torczSciezka(video);
    if (!torcz || typeof torcz.getCapabilities !== 'function') {
        return {};
    }
    try {
        return torcz.getCapabilities() ?? {};
    } catch {
        return {};
    }
}

/**
 * Włącza/wyłącza latarkę.
 *
 * Dwie pułapki, obie zmierzone na urządzeniu:
 *
 * 1. **Nie bramkujemy latarki przez `getCapabilities()`** — Chrome na Androidzie
 *    często w ogóle nie raportuje tej właściwości, więc przycisk znikał i
 *    latarki nie dało się włączyć w skanerze (tylko ręcznie, w aparacie
 *    systemowym).
 * 2. **Nie żądamy potwierdzenia w `getSettings().torch`** — wiele aparatów
 *    przyjmuje `applyConstraints` bez wyjątku i naprawdę zapala latarkę, ale
 *    klucza nie zwraca.
 *
 * Jedynym wiarygodnym sygnałem porażki jest **wyjątek** z `applyConstraints`.
 *
 * @returns {Promise<'ok'|'brak-latarki'|'blad-aparatu'>}
 */
async function latarkaUstaw(video, wlaczona) {
    const torcz = torczSciezka(video);
    if (!torcz) {
        return 'brak-latarki';
    }
    const bledy = [];
    try {
        await torcz.applyConstraints({ advanced: [{ torch: wlaczona }] });
        return 'ok';
    } catch (e) {
        bledy.push(e);
    }
    // Ścieżka html5-qrcode: ta sama ścieżka, ale przez jej własne API
    if (html5Skaner && typeof html5Skaner.applyVideoConstraints === 'function') {
        try {
            await html5Skaner.applyVideoConstraints({
                torch: wlaczona,
                advanced: [{ torch: wlaczona }],
            });
            return 'ok';
        } catch (e) {
            bledy.push(e);
        }
    }
    // Czasem pomaga jawne ustawienie bez advanced
    try {
        await torcz.applyConstraints({ torch: wlaczona });
        return 'ok';
    } catch (e) {
        bledy.push(e);
    }
    return 'blad-aparatu';
}

/* ------------------------------------------------------------------- UI */

/** Dynamiczne wczytanie fallbacku (bez builda — zwykły UMD z vendor/). */
function zaladuzHtml5() {
    if (html5Zaladowana) {
        return Promise.resolve();
    }
    return new Promise((resolve, reject) => {
        const s = document.createElement('script');
        s.src = 'js/vendor/html5-qrcode.min.js';
        s.onload = () => {
            html5Zaladowana = true;
            resolve();
        };
        s.onerror = () => reject(new Error('Nie udało się wczytać biblioteki skanera.'));
        document.head.appendChild(s);
    });
}

/**
 * Podpina latarkę. Przycisk pojawia się tylko na aparatach, które ją deklarują.
 * Latarka zapala się **od razu** — skanuje się zwykle w słabym świetle (grzbiet
 * książki, piwnica, wieczór), więc pytanie „czy światło włączyć" tylko opóźnia
 * odczyt. Wyłączenie zostaje użytkownikowi.
 *
 * Stan „włączona” niesie klasa .wlaczony na przycisku (obwódka ikony),
 * nie sam kolor — zgodnie z DESIGN.md.
 */
async function latarkaPodepnij(overlay, video, cleanupRejestruj) {
    const przycisk = overlay.querySelector('[data-latarka]');
    if (!przycisk) {
        return;
    }
    let wlaczona = false;
    let gasimyJuz = false;      // krok „zgaś" dokładamy do cleanup tylko raz

    // UWAGA: przycisk pokazujemy **bez sprawdzania `getCapabilities().torch`**
    // (wolne 0.20.0 robiło to i przycisk znikał na Androidzie, gdzie Chrome
    // właściwości nie raportuje — latarki dało się włączyć tylko ręcznie
    // w aparacie systemowym). Jedyną próbą jest samo włączenie; jeśli aparat
    // naprawdę nie ma latarki, przycisk zostaje wyłączony z wyjaśnieniem.
    const startowy = await latarkaUstaw(video, true);
    if (startowy === 'ok') {
        przycisk.hidden = false;
        wlaczona = true;
        przycisk.classList.add('wlaczony');
        przycisk.setAttribute('aria-pressed', 'true');
        overlay.querySelector('.skaner-powierzchnia')?.classList.add('skaner-torcz');
        gasimyJuz = true;
        cleanupRejestruj(() => latarkaUstaw(video, false));
    } else if (startowy === 'brak-latarki') {
        przycisk.hidden = true;
        return;
    } else {
        // Aparat odmówił (np. Firefoks nie zna w ogóle `torch` — mierzone:
        // `OverconstrainedError`). Przycisk zostaje WIDOCZNY, ale **bez
        // atrybutu `disabled`**: wyłączony przycisk nie reaguje na dotyk, więc
        // na telefonie wyglądał na zepsuty (zgłoszenie właściciela). Zamiast
        // tego dostaje `aria-disabled` i po dotknięciu mówi, dlaczego nie działa.
        przycisk.hidden = false;
        przycisk.dataset.brak = '1';
        przycisk.setAttribute('aria-disabled', 'true');
        przycisk.title = 'Ta przeglądarka nie obsługuje latarki';
    }

    przycisk.addEventListener('click', async () => {
        if (przycisk.dataset.brak === '1') {
            komunikat(
                'Ta przeglądarka nie obsługuje latarki — użyj aparatu systemowego '
                + 'albo innej przeglądarki.',
                'blad'
            );
            return;
        }
        przycisk.disabled = true;
        const nastepna = !wlaczona;
        const wynik = await latarkaUstaw(video, nastepna);
        przycisk.disabled = false;
        if (wynik !== 'ok') {
            komunikat(
                nastepna
                    ? 'Ten aparat nie pozwala zapalić latarki.'
                    : 'Nie udało się zgasić latarki — wyłącz ją w aparacie.',
                'blad'
            );
            return;
        }
        wlaczona = nastepna;
        przycisk.classList.toggle('wlaczony', wlaczona);
        przycisk.setAttribute('aria-pressed', String(wlaczona));
        overlay.querySelector('.skaner-powierzchnia')
            ?.classList.toggle('skaner-torcz', wlaczona);
        if (!gasimyJuz) {
            gasimyJuz = true;
            cleanupRejestruj(() => latarkaUstaw(video, false));
        }
    });
}

/**
 * Prosi aparat o najwyższą rozdzielczość, jaką potrafi.
 *
 * Kluczowe dla odczytu EAN-13: czytelność zależy od liczby pikseli **na sam
 * kod**, a nie na wielkości podglądu. Kod na grzbiecie książki trzymanej
 * w wyciągniętej ręce bywa w kadrze 720p ledwie 150 px szerokości i detektor
 * go nie czyta — w aparacie systemowym to samo zdjęcie wychodzi czytelne, bo
 * aparat robi je w rozdzielczości pełnego sensora.
 *
 * Dlatego prosimy o `ideal` (nie `exact`, które odrzuca całe żądanie), a po
 * starcie podnosimy do maksimum zgłoszonego przez ścieżkę wideo. `getUserMedia`
 * często zwraca rozdzielczość niższą niż dostępna, gdy nie ma jasnego żądania.
 */
async function maksymalnaRozdzielczosc(video) {
    const torcz = torczSciezka(video);
    if (!torcz) {
        return;
    }
    const moz = mozliwosci(video);
    const maksSzer = Number(moz.width?.max ?? 0);
    const maksWys = Number(moz.height?.max ?? 0);
    if (!maksSzer || maksSzer <= video.videoWidth) {
        return;
    }
    // Nie pchamy `exact` — czasem kończy się to wywróceniem aparatem w bok.
    await torcz.applyConstraints({
        width: { ideal: maksSzer },
        height: { ideal: maksWys || undefined },
    }).catch(() => {
        /* aparat zostaje na tej rozdzielczości, którą dał */
    });

    // Ostrość ciągła, gdy aparat to umie — na grzbiecie książki aparat
    // systemowy pozwala dotknąć i wyostrzyć, a strona nie ma takiej możliwości.
    try {
        await torcz.applyConstraints({
            advanced: [{ focusMode: 'continuous' }],
        });
    } catch {
        /* aparat nie ma trybu ciągłego — zostaje domyślny */
    }
}

/**
 * Dopasowuje powierzchnię podglądu do proporcji strumienia i podpina
 * powiększenie.
 *
 * **Proportcje:** aparat w telefonie trzymanym pionowo daje strumień pionowy,
 * a modał jest poziomy. Przy `object-fit: cover` użytkownik widział wąski pas
 * kadru, a reszta była przycięta — celował więc na ślepo, w miejsce, którego
 * nie widział. Ustawiamy proporcje z faktycznego `videoWidth/videoHeight`.
 *
 * **Powiększenie:** kod na grzbiecie książki trzymanej w wyciągniętej ręce to
 * w kadrze ułamek szerokości i detektor go nie czyta. Aparat systemowy ma
 * zoom, a strona nie — przyciski dają to samo, bez zależności od sprzętu.
 *
 * @returns {Promise<void>}
 */
async function dopasujPowierzchnie(overlay, video, cleanupRejestruj) {
    const powierzchnia = overlay.querySelector('.skaner-powierzchnia');
    const ustawProporcje = () => {
        const w = video.videoWidth;
        const h = video.videoHeight;
        if (w > 0 && h > 0) {
            powierzchnia.style.aspectRatio = `${w} / ${h}`;
        }
    };
    ustawProporcje();
    // Aparat może zmienić orientację w trakcie skanowania
    video.addEventListener('resize', ustawProporcje);
    cleanupRejestruj(() => video.removeEventListener('resize', ustawProporcje));

    // --- powiększenie ---
    const panel = overlay.querySelector('[data-zoom]');
    if (!panel) {
        return;
    }
    const torcz = torczSciezka(video);
    const zoomMozliwosci = mozliwosci(video).zoom;
    const maksZoom = Number(zoomMozliwosci?.max ?? 0);
    // Niektóre aparaty nie raportują możliwości, a mimo to przyjmują
    // `advanced: [{ zoom }]` — wtedy próbujemy do 4× i uznajemy, że nie ma.
    const zakres = { min: Number(zoomMozliwosci?.min ?? 1), max: maksZoom || 4, krok: Number(zoomMozliwosci?.step ?? 0.1) };
    let zoom = 1;

    const wyswietl = () => {
        // „1×”, „1,4×”, „2×” — bez „,0”, które nic nie wnosi
        const zaokr = Math.round(zoom * 10) / 10;
        const tekst = Number.isInteger(zaokr) ? String(zaokr) : zaokr.toFixed(1).replace('.', ',');
        overlay.querySelector('[data-zoom-poziom]').textContent = `${tekst}×`;
    };
    const ustawZoom = async (wartosc) => {
        const nowy = Math.min(Math.max(wartosc, zakres.min), zakres.max);
        if (!torcz || nowy === zoom) {
            return;
        }
        try {
            await torcz.applyConstraints({ advanced: [{ zoom: nowy }] });
            zoom = nowy;
            wyswietl();
        } catch {
            /* aparat nie przyjął — zostaje poprzedni poziom */
        }
    };

    panel.hidden = false;
    wyswietl();
    overlay.querySelector('[data-zoom-wiecej]')
        .addEventListener('click', () => ustawZoom(zoom + zakres.krok * 2));
    overlay.querySelector('[data-zoom-mniej]')
        .addEventListener('click', () => ustawZoom(zoom - zakres.krok * 2));

    // Szczypanie — naturalny gest na telefonie; bez niego powiększanie
    // przyciskami jest wolne przy powrocie do odległego kadru.
    let szpoczek = null;
    powierzchnia.addEventListener('touchstart', (e) => {
        if (e.touches.length === 2) {
            szpoczek = Math.hypot(
                e.touches[0].clientX - e.touches[1].clientX,
                e.touches[0].clientY - e.touches[1].clientY
            );
        }
    }, { passive: true });
    powierzchnia.addEventListener('touchmove', async (e) => {
        if (szpoczek === null || e.touches.length !== 2) {
            return;
        }
        const teraz = Math.hypot(
            e.touches[0].clientX - e.touches[1].clientX,
            e.touches[0].clientY - e.touches[1].clientY
        );
        if (teraz <= 0) {
            return;
        }
        await ustawZoom(zoom * (teraz / szpoczek));
        szpoczek = teraz;
    }, { passive: true });
    powierzchnia.addEventListener('touchend', () => {
        szpoczek = null;
    }, { passive: true });
}

/**

/**
 * Podgląd tego, co widzi dekoder — wycinek kadru, przeskalowany do
 * `WYMIAR_DETEKTORA`. ZXing dostaje całą ramkę i dzieli ją na pasy, więc ten
 * środkowy pas to dokładnie to, na co patrzy w pierwszej kolejności;
 * kolejne pasy sięgają góry i dołu. Działa dla obu ścieżek, bo bazą jest
 * sam `<video>`.
 */
function kadrDetektora(video, kadr, wycinek = KADR_PODGLADU) {
    const w = video.videoWidth;
    const h = video.videoHeight;
    if (w === 0 || h === 0) {
        return false;
    }
    const wycSzer = Math.max(1, Math.round(w * wycinek.szer));
    const wycWys = Math.max(1, Math.round(h * wycinek.wys));
    const wycX = Math.round((w - wycSzer) / 2);
    const wycY = Math.round((h - wycWys) / 2);
    // Detektor wewnętrznie zmniejsza obraz do swojego limitu (~1000–2000 px),
    // więc podanie 3500-px wycinka nie daje więcej informacji — tylko tyle,
    // ile i tak zostanie wzięte, a kod zajmuje mniejszą część wejścia i czyta
    // się gorzej. Mniejszy obraz jest też dużo szybszy na telefonie.
    const skala = Math.min(1, WYMIAR_DETEKTORA / Math.max(wycSzer, wycWys));
    const celSzer = Math.max(1, Math.round(wycSzer * skala));
    const celWys = Math.max(1, Math.round(wycWys * skala));
    if (kadr.width !== celSzer || kadr.height !== celWys) {
        kadr.width = celSzer;
        kadr.height = celWys;
    }
    kadr.getContext('2d').drawImage(
        video, wycX, wycY, wycSzer, wycWys, 0, 0, celSzer, celWys
    );
    return true;
}

/**
 * Po ~3,5 s bez odczytu pokazuje **to, co widzi dekodator**, i aktualizuje to
 * co klatkę. Na drodze „wszystko działa” jest zbędny, a gdy nie działa,
 * odróżnia „kod nieczytelny w kadrze” (odblask, folia, uszkodzenie) od
 * „kadr zły” (kod poza wycinkiem, za daleko). Dotąd zgadywaliśmy w ciemno.
 *
 * Zwykły modal się w tym czasie zamyka (`wykryto` → `zakoncz`), więc licznik
 * sam zatrzymuje się po trafieniu — nie trzeba go sprzątać.
 */
function podgladDetektora(overlay, video, cleanupRejestruj) {
    const fig = overlay.querySelector('[data-podglad]');
    const cel = overlay.querySelector('[data-podglad-canvas]');
    if (!fig || !cel) {
        return;
    }
    const kadr = document.createElement('canvas');
    /**
     * Rozmiar **pikseli** podglądu.
     *
     * Do 0.23.1 canvas miał sztywne `width=128 height=48`, a CSS rozciągał
     * go na całą szerokość okna — kadr z 1280 px lądował w 128×48 bez
     * zachowania proporcji, potem był jeszcze powiększany. Obraz wychodził
     * rozmyty i rozciągnięty, więc „to dokładnie widzi skaner” nie nadawał
     * się do oceny (zgłoszenie właściciela). Teraz: tyle pikseli, ile
     * realnie mieści się w oknie, przemnożone przez gęstość ekranu, ale
     * nigdy więcej niż ma sam kadr — powiększanie i tak by rozmyło.
     */
    const dopasujCel = () => {
        const szerCss = cel.getBoundingClientRect().width
            || fig.clientWidth
            || 300;
        // Gęstość ekranu: telefon ma dpr 2–3, więc 1 px CSS = 2–3 px bufora.
        // Bez tego bufor byłby węższy od okna i CSS musiałby go powiększać.
        // Limit 3 ochrania przed dziwnymi wartościami; i tak dodatkowo
        // ograniczamy szerokością samego kadru (niżej).
        const dpr = Math.min(window.devicePixelRatio || 1, 3);
        let celSzer = Math.max(1, Math.round(szerCss * dpr));
        if (kadr.width > 0) {
            celSzer = Math.min(celSzer, kadr.width);
        }
        const celWys = (kadr.width > 0 && kadr.height > 0)
            ? Math.max(1, Math.round(celSzer * kadr.height / kadr.width))
            : Math.max(1, Math.round(celSzer * 0.5));
        if (cel.width !== celSzer || cel.height !== celWys) {
            cel.width = celSzer;
            cel.height = celWys;
        }
    };
    const tekstDiag = fig.querySelector('[data-diag]');
    const start = setTimeout(() => {
        fig.hidden = false;
        const odswiez = () => {
            if (!document.body.contains(cel)) {
                return;
            }
            if (kadrDetektora(video, kadr)) {
                dopasujCel();   // proporcje zgodne z kadrem, nie rozciąganie
                cel.getContext('2d').drawImage(kadr, 0, 0, cel.width, cel.height);
            }
            if (tekstDiag) {
                // Co 250 ms, razem z kadrem — liczniki rosną w tle,
                // niezależnie od tego, jak często odświeżamy DOM.
                tekstDiag.textContent = diagTekst(video);
            }
        };
        odswiez();
        const timer = setInterval(odswiez, 250);
        cleanupRejestruj(() => clearInterval(timer));
    }, 3500);
    cleanupRejestruj(() => clearTimeout(start));
}

/** Pusta ramka celu — biblioteka html5-qrcode czyści kontener przy starze,
 *  więc ramkę trzeba umieścić dopiero po `start()`. */
function ramkaCel() {
    const el = document.createElement('div');
    el.className = 'skaner-cel';
    return el;
}

/* --------------------------------------------------- diagnoza aparatu */

/**
 * Wyjaśnienie błędu aparatu. Wersja przed 0.20.0 mówiła jedno zdanie
 * („Aparat niedostępny”), przez co nie dało się odróżnić: brak zgody,
 * zablokowana zgoda na stałe w Chrome, aparat zajęty inną aplikacją,
 * czy w ogóle brak kamery w urządzeniu. Każda z tych rzeczy wymaga
 * innego działania użytkownika.
 */
function aparatOpis(err) {
    // html5-qrcode rzuca **zwykłym stringiem**, nie obiektem błędu — nazwa
    // przyczyny zostaje tylko w tekście („Error getting userMedia, error =
    // NotAllowedError: …”). Bez rozpoznania jej z tekstu pokazywaliśmy
    // bezużyteczne „Bez opisu” zamiast instrukcji naprawy.
    const surowy = typeof err === 'string' ? err : (err?.message ?? '');
    const nazwa = (err?.name ?? '')
        || (['NotAllowedError', 'PermissionDeniedError', 'SecurityError',
             'NotFoundError', 'DevicesNotFoundError', 'OverconstrainedError',
             'NotReadableError', 'TrackStartError']
            .find((n) => surowy.includes(n)) ?? '');

    if (!window.isSecureContext) {
        return {
            tytul: 'Ta strona nie może używać aparatu.',
            tekst: 'Aparat działa tylko na adresie z HTTPS. Ten komunikat '
                 + 'oznacza, że strona została otwarta bez szyfrowania.',
        };
    }
    if (!navigator.mediaDevices?.getUserMedia) {
        return {
            tytul: 'Ta przeglądarka nie udostępnia aparatu.',
            tekst: 'Wbudowane API kamery jest niedostępne — np. strefa czasowa '
                 + 'prywatności albo strona otwarta bez HTTPS.',
        };
    }
    if (nazwa === 'NotAllowedError' || nazwa === 'PermissionDeniedError'
        || nazwa === 'SecurityError') {
        return {
            tytul: 'Przeglądarka nie zezwala na aparat.',
            tekst: 'Kliknij klockę przy adresie (albo ikonę aparatu w pasku), '
                 + 'wejdź w „Ustawienia witryny” i ustaw aparat na „Zezwól”. '
                 + 'Przeglądarka zapamiętuje odmowę — pytania nie powtórzy, '
                 + 'trzeba ją zmienić ręcznie. Alternatywnie wklej ISBN.',
        };
    }
    if (nazwa === 'NotFoundError' || nazwa === 'DevicesNotFoundError'
        || nazwa === 'OverconstrainedError') {
        return {
            tytul: 'Nie znaleziono aparatu.',
            tekst: 'To urządzenie nie ma kamery albo przeglądarka nie ma do niej '
                 + 'dostępu — np. zezwolenie na poziomie systemu zostało odjęte.',
        };
    }
    if (nazwa === 'NotReadableError' || nazwa === 'TrackStartError') {
        return {
            tytul: 'Aparat jest zajęty.',
            tekst: 'Inna aplikacja używa kamery — zamknij wideorozmowę '
                 + 'albo inną kartę, która może korzystać z aparatu.',
        };
    }
    return {
        tytul: 'Nie udało się uruchomić aparatu.',
        tekst: surowy || 'Bez opisu.',
    };
}

/**
 * Wklejany ISBN — wyjście z sytuacji bezczynnej. Kod z grzbietu książki
 * można skopiować ze sklepu internetowego, więc ręczne wpisanie nie musi
 * być wklejaniem z pamięci.
 */
export function wklejZamknij(overlay, onIsbn, zakonczGlowne, startAparatu) {
    const blad = overlay.querySelector('[data-blad]');
    if (!blad) {
        return;
    }
    blad.remove();
    const pole = document.createElement('div');
    pole.className = 'skaner-wklej';
    pole.innerHTML = `
        <label class="pole" for="f-isbn-skaner">Wklej albo wpisz ISBN</label>
        <input id="f-isbn-skaner" placeholder="9788301123456"
               inputmode="numeric" autocomplete="off">
        <p class="skaner-wklej-podpowiedz">Kod z grzbietu książki można wkleić
           ze sklepu internetowego — nie musisz go pamiętać.</p>
        <div class="modal-przyciski">
            <button class="przycisk-pusty" data-cofnij>Cofnij</button>
            <button class="przycisk" data-zatwierdz>Znajdź książkę</button>
        </div>`;
    overlay.querySelector('.modal.skaner').appendChild(pole);
    const input = pole.querySelector('input');
    input.focus();
    const bladPola = document.createElement('p');
    bladPola.className = 'skaner-wklej-blad';
    bladPola.hidden = true;
    pole.appendChild(bladPola);
    const zatwierdz = async () => {
        const isbn = isbnZKodu(input.value);
        if (isbn === null) {
            bladPola.textContent = 'To nie jest poprawny kod książkowy ISBN.';
            bladPola.hidden = false;
            input.focus();
            return;
        }
        bladPola.hidden = true;
        await zakonczGlowne();
        onIsbn(isbn);
    };
    input.addEventListener('input', () => {
        bladPola.hidden = true;
    });
    pole.querySelector('[data-zatwierdz]').addEventListener('click', zatwierdz);
    pole.querySelector('[data-cofnij]').addEventListener('click', () => {
        pole.remove();
        startAparatu();
    });
    input.addEventListener('keydown', (e) => {
        if (e.key === 'Enter') {
            zatwierdz();
        }
    });
}

/** Otwiera modal ze skanerem; onIsbn wywoływane z poprawnym ISBN-13. */
export async function skanujKod(onIsbn) {
    const overlay = document.createElement('div');
    overlay.className = 'modal-overlay';
    overlay.innerHTML = `
        <div class="modal skaner">
            <h3>Skanuj kod kreskowy</h3>
            <div class="skaner-powierzchnia" id="skaner-powierzchnia"></div>
            <p class="status-lookup">Przyłóż książkę tak, by kod wypełniał ramkę — bliżej aparatu.</p>
            <p class="skaner-odczyt" data-odczyt hidden></p>
            <figure class="skaner-podglad" data-podglad hidden>
                <canvas data-podglad-canvas width="128" height="48"></canvas>
                <figcaption>Tak widzi skaner</figcaption>
                <p class="skaner-diag" data-diag aria-live="off"></p>
            </figure>
            <div class="skaner-zoom" data-zoom hidden>
                <button type="button" class="przycisk-pusty" data-zoom-mniej
                        aria-label="Oddal">${ikona('minus')}</button>
                <output class="skaner-zoom-poziom" data-zoom-poziom aria-live="off">1×</output>
                <button type="button" class="przycisk-pusty" data-zoom-wiecej
                        aria-label="Przybliż">${ikona('plus')}</button>
            </div>
            <div class="skaner-narzedzia">
                <button type="button" class="przycisk-pusty skaner-latarka"
                        data-latarka hidden aria-pressed="false">
                    ${ikona('latarka', 'ikona')}<span>Latarka</span>
                </button>
                <div class="modal-przyciski">
                    <button class="przycisk-pusty" data-anuluj>Anuluj</button>
                </div>
            </div>
        </div>`;
    document.body.appendChild(overlay);
    diagReset();

    // Trzymamy element w zmiennej — patrz komentarz przy `startAparatu()`.
    const powierzchnia = overlay.querySelector('.skaner-powierzchnia');
    const modalSkanera = overlay.querySelector('.modal.skaner');

    // Jedna funkcja sprzątająca — wszystkie ścieżki dopisują do niej to, co
    // trzeba odkręcić (wcześniej `cleanup` był nadpisywany przez dwie gałęzie,
    // przez co część zasobów zostawała nie zamknięta).
    const doSprzatania = [];
    cleanup = async () => {
        const biezace = doSprzatania.splice(0);
        for (const krok of biezace.reverse()) {
            try {
                await krok();
            } catch { /* aparat już zamknięty */ }
        }
    };
    html5Skaner = null;

    const zakoncz = async () => {
        const biezacy = cleanup;
        cleanup = null;
        overlay.remove();
        if (biezacy) {
            await biezacy();
        }
    };
    overlay.querySelector('[data-anuluj]').addEventListener('click', zakoncz);
    overlay.addEventListener('click', (e) => {
        if (e.target === overlay) {
            zakoncz();
        }
    });

    /**
     * Błąd aparatu pokazujemy W MODALU, nie toastem i nie zamykając okno —
     * wcześniej modal znikał i użytkownik został z samym komunikatem,
     * bez możliwości naprawienia (spróbowania po zmianie ustawień Chrome
     * albo wpisania ISBN).
     */
    const pokazBlad = (err) => {
        console.warn('[biblioteka] aparat:', err?.name ?? err, err?.message ?? '');
        const opis = aparatOpis(err);
        powierzchnia.setAttribute('data-ukryte', '');
        // Podpowiedź o ramce i latarka dotyczą podglądu, którego nie ma —
        // zostawiałyby elementy nieaktualne i mylące.
        overlay.querySelector('.status-lookup')?.remove();
        overlay.querySelector('[data-latarka]')?.remove();
        const odczyt = overlay.querySelector('[data-odczyt]');
        if (odczyt) {
            odczyt.hidden = true;
            odczyt.textContent = '';
        }
        const stary = overlay.querySelector('[data-blad]');
        if (stary) {
            stary.remove();
        }
        const blad = document.createElement('div');
        blad.className = 'skaner-blad';
        blad.setAttribute('data-blad', '');
        blad.innerHTML = `
            <p class="skaner-blad-tytul">${esc(opis.tytul)}</p>
            <p class="skaner-blad-tekst">${esc(opis.tekst)}</p>
            <div class="modal-przyciski">
                <button class="przycisk-pusty" data-wklej>Wpisz ISBN</button>
                <button class="przycisk" data-ponow>Spróbuj ponownie</button>
            </div>`;
        modalSkanera.appendChild(blad);
        blad.querySelector('[data-wklej]')
            .addEventListener('click', () => wklejZamknij(overlay, onIsbn, zakoncz, startAparatu));
        blad.querySelector('[data-ponow]')
            .addEventListener('click', () => {
                blad.remove();
                startAparatu();
            });
    };

    /**
     * Widoczna informacja „kod odczytany, ale odrzucony”.
     *
     * Bez niej taki skan wyglądał dokładnie tak samo jak brak odczytu,
     * więc użytkownik nie miał żadnego sygnału, że dekoder pracuje,
     * tylko walidacja nie przyjmuje wyniku. To jedyna różnica między
     * „nie trafia w kod” a „trafia, ale nie ten numer” — i była niewidoczna.
     */
    const pokazOdczyt = (tekst) => {
        const el = overlay.querySelector('[data-odczyt]');
        if (!el) {
            return;
        }
        if (el.textContent !== tekst) {
            el.textContent = tekst;   // html5-qrcode woła to kilka razy na sekundę
        }
        el.hidden = false;
    };
    const ukryjOdczyt = () => {
        const el = overlay.querySelector('[data-odczyt]');
        if (el) {
            el.hidden = true;
            el.textContent = '';
        }
    };

    const wykryto = async (raw) => {
        diag.odczyty += 1;
        diag.ostatni = String(raw ?? '').trim().slice(0, 24);
        const isbn = isbnZKodu(raw);
        if (isbn === null) {
            // Obcy kod (mleko z lodówki) albo nasz ISBN w kształcie, którego
            // nie umiemy rozpoznać. Do 0.23.2 było to ciche `return false`,
            // więc taki skan wyglądał dokładnie tak samo jak „dekoder nic
            // nie widzi” — a to była jedna z dwóch realnych przyczyn.
            diag.odrzucone += 1;
            pokazOdczyt(`Odczytano „${diag.ostatni}” — to nie jest ISBN. Szukam dalej…`);
            return false;
        }
        ukryjOdczyt();
        await zakoncz();
        onIsbn(isbn);
        return true;
    };

    // Wywołanie aparatu jest osobną funkcją, żeby „Spróbuj ponownie”
    // mogła powtórzyć je w tym samym modalu — bez zamykania i otwierania
    // od nowa (co gubiło stan formularza i wyglądało jak awaria aplikacji).
    const startAparatu = async () => {
        overlay.querySelector('[data-blad]')?.remove();
        overlay.querySelector('.skaner-wklej')?.remove();
        // Referencja do elementu, NIE szukanie po `id`: html5-qrcode zmienia
        // `id` kontenera na `skaner-html5` przy starcie, więc po pierwszym
        // uruchomieniu `#skaner-powierzchnia` przestaje istnieć i ponowienie
        // wywalało się na `removeAttribute` (zmierzono).
        powierzchnia.removeAttribute('data-ukryte');
        powierzchnia.classList.add('skaner-wczytywanie');
        ukryjOdczyt();   // po „Spróbuj ponownie” zostawał z poprzedniej próby
        // Przy ponownej próbie przywracamy podpowiedź i przycisk latarki —
        // pokazBlad() je usuwał.
        if (!overlay.querySelector('.status-lookup')) {
            const st = document.createElement('p');
            st.className = 'status-lookup';
            st.textContent = 'Przyłóż książkę tak, by kod wypełniał ramkę — bliżej aparatu.';
            modalSkanera.insertBefore(st, overlay.querySelector('.skaner-narzedzia'));
        }
        if (!overlay.querySelector('[data-latarka]')) {
            const lat = document.createElement('button');
            lat.type = 'button';
            lat.className = 'przycisk-pusty skaner-latarka';
            lat.setAttribute('data-latarka', '');
            lat.setAttribute('aria-pressed', 'false');
            lat.hidden = true;   // latarkaPodepnij() odsłoni tylko gdy aparat ma
            lat.innerHTML = ikona('latarka', 'ikona') + '<span>Latarka</span>';
            overlay.querySelector('.skaner-narzedzia').prepend(lat);
        }

        // --- ścieżka 1: ZXing w JS (domyślna, wszystkie przeglądarki) ---
        // Dekoder ładujemy PRZED aparatem: jeśli pliku nie ma (stary serwis
        // bez skryptu w cache), lecimy na zapas html5-qrcode zamiast
        // pokazywać użytkownikowi pusty podgląd.
        let maZxing = false;
        if (navigator.mediaDevices?.getUserMedia) {
            try {
                await zaladujDekoder();
                maZxing = true;
            } catch (err) {
                console.warn('[biblioteka] ZXing:', err);
                pokazBlad(new Error('Nie udało się wczytać dekodera kodów.'));
                return;
            }
        }
        if (maZxing) {
            diag.sciezka = 'ZXing';
            try {
                // Rozdzielczość `ideal`, nie `exact` — aparat zwykle dobierze
                // najbliższą dostępną, a `exact` potrafi odrzucić całe
                // żądanie i wywrócić aparatem w bok (na pionowy).
                const stream = await navigator.mediaDevices.getUserMedia({
                    video: {
                        facingMode: { ideal: 'environment' },
                        width: { ideal: 1280 },
                        height: { ideal: 720 },
                    },
                });
                powierzchnia.classList.remove('skaner-wczytywanie');
                doSprzatania.push(() => stream.getTracks().forEach((t) => t.stop()));
                const video = document.createElement('video');
                video.muted = true;
                video.playsInline = true;
                video.srcObject = stream;
                powierzchnia.appendChild(video);
                powierzchnia.appendChild(ramkaCel());
                await video.play();
                await maksymalnaRozdzielczosc(video);
                await dopasujPowierzchnie(overlay, video, (krok) => doSprzatania.push(krok));

                await latarkaPodepnij(overlay, video, (krok) => doSprzatania.push(krok));

                                // Kadr trafia do dekodera w całości — podział na pasy robi
                // ZXing (`dekodery.js`), bo czytniki ZXinga szukają od
                // pionowego środka obrazu i kodu przy krawędzi nie widzą.
                const kadr = document.createElement('canvas');
                // Podgląd tego, co widzi dekodator — pokazuje się, gdy przez
                // 3,5 s nic nie odczytano (moduł sam się zamyka po trafieniu).
                podgladDetektora(overlay, video, (krok) => doSprzatania.push(krok));

                let praca = true;
                doSprzatania.push(() => {
                    praca = false;
                });

                // `requestVideoFrameCallback` daje klatkę dokładnie wtedy, gdy
                // ją aparat dostarczy — bez zgadywania odstępu czasu. Gdy
                // niedostępne (Safari), wpada na timer.
                //
                // UWAGA: pierwsza wersja tego kodu (0.24.0, wykryta testem)
                // deduplikował klatki po `video.mediaTime` i pętla stała
                // martwa (`próby 0`) — `mediaTime` dla strumienia z aparatu
                // nie rośnie, bo nie ma osi czasu. Licznik klatek to
                // `presentedFrames` z metadanych callbacka; gdy callback nie
                // jest dostępny, nie deduplikujemy w ogóle.
                const nastepnaKlatka = video.requestVideoFrameCallback
                    ? (cb) => video.requestVideoFrameCallback((now, meta) => cb(now, meta))
                    : (cb) => setTimeout(() => cb(performance.now(), null), 120);

                // Dekoder po 0.24.0 jest ZXingiem w JS: przejście po wszystkich
                // pasach kosztuje kilkadziesiąt–kilkaset ms, więc odstęp 110 ms
                // z czasów `BarcodeDetector` tylko by wąził gardło.
                const ODSLUP_MS = 200;
                let ostatniaDetekcja = 0;
                let ostatniaKlatka = null;
                const petla = async (teraz, meta) => {
                    if (!praca || !document.body.contains(video)) {
                        return;
                    }
                    try {
                        const klatka = meta?.presentedFrames ?? null;
                        const nowaKlatka = klatka === null || klatka !== ostatniaKlatka;
                        if (nowaKlatka && teraz - ostatniaDetekcja >= ODSLUP_MS) {
                            ostatniaKlatka = klatka;
                            ostatniaDetekcja = teraz;
                            if (kadrDetektora(video, kadr, KADR_DEKODERA)) {
                                diag.proby += 1;
                                const wynik = await dekodujKadr(kadr);
                                diag.czas = wynik.ms;
                                if (wynik.tekst === null) {
                                    diag.puste += 1;
                                } else if (await wykryto(wynik.tekst)) {
                                    return;
                                }
                            }
                        }
                    } catch (err) {
                        // Do 0.23.2 `catch` był pusty i gubił wyjątki z
                        // dekodera. Eksternie wyglądało to tak samo jak
                        // „dekoder nie znalazł kodu”, a to zupełnie inna
                        // przyczyna.
                        diag.awarie += 1;
                        diag.ostatniBlad = String(err?.name ?? err ?? '?');
                        console.warn('[biblioteka] dekodowanie:', err);
                    }
                    nastepnaKlatka(petla);
                };
                nastepnaKlatka(petla);
                return;
            } catch (err) {
                powierzchnia.classList.remove('skaner-wczytywanie');
                pokazBlad(err);
                return;
            }
        }

        // --- ścieżka zapasowa: html5-qrcode (gdy ZXing się nie wczytał) ---
        diag.sciezka = 'html5 (zapas)';
        try {
            await zaladuzHtml5();
            if (powierzchnia.id !== 'skaner-html5') {
                powierzchnia.id = 'skaner-html5';   // kontener biblioteki
            }
            html5Skaner = new window.Html5Qrcode('skaner-html5');
            doSprzatania.push(async () => {
                await html5Skaner.stop();
                html5Skaner.clear();
                html5Skaner = null;
            });
            await html5Skaner.start(
                // UWAGA: html5-qrcode NIE przyjmuje `facingMode: { ideal: ... }` —
                // wymaga stringa albo `{ exact: ... }` i rzuca wyjątkiem
                // („'facingMode' should be string or object with exact as key"),
                // co wyłącza całą ścieżkę zapasową (Safari/iOS).
                // Obiekt `ideal` jest poprawny tylko w natywnym getUserMedia.
                { facingMode: 'environment' },
                {
                    fps: 25,
                    // `qrbox` to pole przeszukiwane przez bibliotekę. Do 0.23.2
                    // było 96 % × 72 % mniejszego boku, czyli zawsze obcięte —
                    // ten sam błąd co na ścieżce natywnej: kod poza polem
                    // przeszukiwania wygląda jak „nie czyta”. Teraz niemal cały
                    // kadr; biblioteka sama przycina, gdy pole jest za duże.
                    qrbox: ( szerokosc, wysokosc ) => ({
                        width: Math.round(szerokosc * 0.98),
                        height: Math.round(wysokosc * 0.98),
                    }),
                    // Szerokie formaty jak na ścieżce natywnej — do 0.23.0
                    // były tylko EAN-13/EAN_8, a `BarcodeDetector` (i ZXing w
                    // ścieżce żywej) formatów nie ignoruje: kod Code-128 lub
                    // ITF nigdy by nie przeszedł. Klucze bierzemy z enuma
                    // biblioteki, żeby brakujący nie wywalił starcia.
                    formatsToScan: [
                        'EAN_13', 'EAN_8', 'UPC_A', 'UPC_E',
                        'CODE_128', 'CODE_39', 'CODE_93', 'ITF', 'CODABAR',
                        'QR_CODE', 'DATA_MATRIX', 'RSS_14',
                    ].map((k) => window.Html5QrcodeSupportedFormats[k]).filter(Boolean),
            },
                (raw) => {
                    wykryto(raw);           // bez await — callback html5-qrcode
                },
                // Biblioteka nie liczy prób po stronie użytkownika — jedyne,
                // co dostajemy, to klatka bez kodu. Liczymy je jako `próby`:
                // rosnące zero przy żądającym obrazie znaczy, że pętla leży.
                () => { diag.proby += 1; }
            );
            powierzchnia.classList.remove('skaner-wczytywanie');
            // Latarka w trybie html5-qrcode: ścieżka wideo leży w <video>,
            // który biblioteka wstrzykuje do naszego kontenera.
            const video = powierzchnia.querySelector('video');
            if (video) {
                // Biblioteka prosi o rozdzielczość domyślną, a detektor
                // potrzebuje pikseli na sam kod — podniesienie działa na obu
                // ścieżkach (iOS też startuje nisko).
                await maksymalnaRozdzielczosc(video);
                await dopasujPowierzchnie(overlay, video, (krok) => doSprzatania.push(krok));
                podgladDetektora(overlay, video, (krok) => doSprzatania.push(krok));
                await latarkaPodepnij(overlay, video, (krok) => doSprzatania.push(krok));
            }
            // Biblioteka czyści zawartość kontenera przy starcie, więc nasza
            // ramka celu zniknęła razem z nią — wstawiamy ją z powrotem
            // (kontrola nad wyglądem zostaje po naszej stronie, biblioteczna
            // jest wyłączona w arkuszu przez #qr-shaded-region).
            powierzchnia.appendChild(overlay.querySelector('.skaner-cel') ?? ramkaCel());
        } catch (err) {
            powierzchnia.classList.remove('skaner-wczytywanie');
            pokazBlad(err);
        }
    };

    startAparatu();
}