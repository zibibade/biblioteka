/* Biblioteka — widok katalogu: siatka/lista, zwijane filtry, sortowanie.
   Ustawienia (filtry, sortowanie, widok) trzymane w localStorage. */

import { api } from './api.js';
import {
    esc, ikona, okladkaHTML, badgeStatus, badgeWypozyczona,
    badgeStatusLista, badgeWypozyczonaLista, naglowek, opcje, potwierdz,
    komunikat, wybieracz, tagiHTML, polkaZwykla, gwiazdki,
    FORMATY, SZEROKOSCI, STATUSY, DOMYSLNE_FILTRY, WERSJA,
} from './ui.js';

const KLUCZ = 'bib.ustawienia';

// Słowniki tagów i półek — cache w module (katalog przerysowywany jest często)
let cacheTagow = null;
let cachePolk = null;

export async function slownikTagi() {
    if (cacheTagow === null) {
        const d = await api('tagi.php');
        if (d === null) {
            return [];
        }
        cacheTagow = d.tagi ?? [];
    }
    return cacheTagow;
}

export async function slownikPolki() {
    if (cachePolk === null) {
        const d = await api('polki.php');
        if (d === null) {
            return [];
        }
        cachePolk = d.polki ?? [];
    }
    return cachePolk;
}

export function cacheWyczysc() {
    cacheTagow = null;
    cachePolk = null;
}

const SORTOWANIE = [
    ['tytul:asc', 'Tytuł A→Z'],
    ['tytul:desc', 'Tytuł Z→A'],
    ['autor:asc', 'Autor A→Z'],
    ['autor:desc', 'Autor Z→A'],
    ['rok:desc', 'Rok: najnowsze'],
    ['rok:asc', 'Rok: najstarsze'],
    ['strony:desc', 'Najwięcej stron'],
    ['strony:asc', 'Najmniej stron'],
    ['dodane:desc', 'Ostatnio dodane'],
    ['dodane:asc', 'Najdawniej dodane'],
    ['edycji:desc', 'Ostatnio zmieniane'],
    ['wypozyczone:desc', 'Najdawniej wypożyczone'],
    ['ocena:desc', 'Ocena: najwyższa'],
    ['ocena:asc', 'Ocena: najniższa'],
];

let ustaw = { filtry: { ...DOMYSLNE_FILTRY }, sort: 'tytul:asc', widok: 'siatka' };
let timerQ = null;

function wczytaj() {
    try {
        const j = JSON.parse(localStorage.getItem(KLUCZ) ?? 'null');
        if (j && typeof j === 'object') {
            ustaw = {
                filtry: { ...DOMYSLNE_FILTRY, ...(j.filtry ?? {}) },
                sort: typeof j.sort === 'string' ? j.sort : ustaw.sort,
                widok: j.widok === 'lista' ? 'lista' : 'siatka',
            };
        }
    } catch { /* uszkodzony zapis — lecimy na domyślnych */ }
}

function zapisz() {
    localStorage.setItem(KLUCZ, JSON.stringify(ustaw));
}

function ileFiltrow() {
    return Object.entries(ustaw.filtry)
        .filter(([k, v]) => k !== 'q' && v !== '').length;
}

/** „a, b" → ['a','b'] (ta sama normalizacja po stronie serwera). */
function tagi_naListe(tekst) {
    return String(tekst ?? '')
        .split(',')
        .map((t) => t.trim().toLowerCase())
        .filter(Boolean);
}

const etykietaFiltry = () =>
    `Filtry${ileFiltrow() > 0 ? ` <span class="kropka">•${ileFiltrow()}</span>` : ''}`
    + ` <span class="strzalka" aria-hidden="true">${ikona('strzalkaDol')}</span>`;

export async function katalog(el, opcjeWidoku = {}) {
    wczytaj();

    // Słowniki tagów i półek (jedno zapytanie każde, cache w module)
    const [slownikTagow, slownikPolk] = await Promise.all([slownikTagi(), slownikPolki()]);
    // Kontekst widoku: półka (własny nagłówek) albo lista aktywnych wypożyczeń
    const wPolce = opcjeWidoku.polka ?? null;
    polkaWymuszona = wPolce ? String(wPolce.id) : '';
    const tylkoAktywne = Boolean(opcjeWidoku.tylkoAktywne);
    wypozyczeniaWymuszone = tylkoAktywne ? 'tak' : '';
    // Widok „do przeczytania" — status wymuszony (jak półka)
    const tylkoStatus = opcjeWidoku.tylkoStatus ?? null;
    statusWymuszony = tylkoStatus ?? '';
    kontekstWidoku = { polka: wPolce, tylkoAktywne, tylkoStatus };

    el.innerHTML = `
        ${naglowek(`
            <a class="ikona naglowe-ikona" href="#/statystyki" title="Statystyki"
                    aria-label="Statystyki">${ikona('wykres')}</a>
            <button class="ikona naglowe-ikona" id="polki" title="Zarządzaj półkami"
                    aria-label="Zarządzaj półkami">${ikona('polka')}</button>
            <button class="ikona naglowe-ikona" id="eksport" title="Eksport danych"
                    aria-label="Eksport danych">${ikona('pobierz')}</button>
            <button class="przycisk-pusty" id="wyloguj" aria-label="Wyloguj"><span class="wyloguj-tekst">Wyloguj</span></button>`)}
        <div class="pasek">
            <input type="search" id="q" placeholder="Szukaj w katalogu…"
                   value="${esc(ustaw.filtry.q)}" aria-label="Szukaj">
            <a class="przycisk dodaj" href="#/dodaj" title="Dodaj książkę (n)">
                ${ikona('plus')}<span class="dodaj-tekst">Dodaj książkę</span>
            </a>
            <button class="przycisk-pusty" id="przelacz-filtry" aria-expanded="false">${etykietaFiltry()}</button>
            <button class="ikona" id="przelacz-widok"
                    title="${ustaw.widok === 'siatka' ? 'Widok listy' : 'Widok siatki'}">${ikona(ustaw.widok === 'siatka' ? 'lista' : 'siatka')}</button>
        </div>
        ${wPolce ? `<div class="pasek-polki">
            <a class="chip" href="#/katalog">${ikona('strzalkaLewo')} Wszystkie książki</a>
            <span class="pasek-polki-nazwa">${ikona('polka')} ${esc(wPolce.nazwa)}</span>
            ${wPolce.opis ? `<span class="pasek-polki-opis">${esc(wPolce.opis)}</span>` : ''}
        </div>` : ''}
        ${tylkoAktywne ? `<div class="pasek-polki">
            <a class="chip" href="#/katalog">${ikona('strzalkaLewo')} Wszystkie książki</a>
            <span class="pasek-polki-nazwa">${ikona('wymiana')} Wypożyczone</span>
            <span class="pasek-polki-opis">Książki poza domem</span>
        </div>` : ''}
        ${tylkoStatus ? `<div class="pasek-polki">
            <a class="chip" href="#/katalog">${ikona('strzalkaLewo')} Wszystkie książki</a>
            <span class="pasek-polki-nazwa">${ikona('czytam')} ${esc(STATUSY[tylkoStatus] ?? tylkoStatus)}</span>
            <span class="pasek-polki-opis">${tylkoStatus === 'chce_przeczytac'
                ? 'Czego jeszcze nie zacząłeś' : ''}</span>
        </div>` : ''}
        <div class="panel-filtrow" id="panel" hidden>
            <label>Sortowanie
                <span class="wybieracz" id="sort"></span>
            </label>
            <label>Status
                <span class="wybieracz" id="f-status"${tylkoStatus ? ' hidden' : ''}></span>
            </label>
            <label>Tagi
                <input id="f-tag" value="${esc(ustaw.filtry.tag)}" list="f-tag-lista"
                       placeholder="po przecinku" autocomplete="off">
                <datalist id="f-tag-lista">
                    ${slownikTagow.map((t) => `<option value="${esc(t.nazwa)}"></option>`).join('')}
                </datalist>
            </label>
            <label>Półka
                <span class="wybieracz" id="f-polka"${wPolce ? ' hidden' : ''}></span>
            </label>
            <label>Format
                <span class="wybieracz" id="f-format"></span>
            </label>
            <label>Szerokość
                <span class="wybieracz" id="f-szerokosc"></span>
            </label>
            <label>Ocena
                <span class="wybieracz" id="f-ocenione"></span>
            </label>
            <label>Wypożyczenie
                <span class="wybieracz" id="f-wypozyczone"${tylkoAktywne ? ' hidden' : ''}></span>
            </label>
            <label>Autor
                <input id="f-autor" value="${esc(ustaw.filtry.autor)}" placeholder="np. Lem">
            </label>
            <label>Rok
                <input id="f-rok" value="${esc(ustaw.filtry.rok)}" placeholder="np. 1990" inputmode="numeric">
            </label>
            <label>Wydawca
                <input id="f-wydawca" value="${esc(ustaw.filtry.wydawca)}" placeholder="np. ZNAK">
            </label>
            <label>Język
                <input id="f-jezyk" value="${esc(ustaw.filtry.jezyk)}" placeholder="np. pl">
            </label>
            <label>Dodano od
                <input type="date" id="f-dodane_od" value="${esc(ustaw.filtry.dodane_od)}">
            </label>
            <label>Dodano do
                <input type="date" id="f-dodane_do" value="${esc(ustaw.filtry.dodane_do)}">
            </label>
            <button class="przycisk-pusty" id="wyczysc">Wyczyść filtry</button>
            <a class="przycisk-pusty panel-szybki" href="#/do-przeczytania"
                    title="Widok „Chcę przeczytać”">${ikona('zakladka')} Do przeczytania</a>
        </div>
        <div class="pasek-regal" id="regal">
            <span class="regal-liczba">—</span>
            <span class="regal-nazwa" id="regal-nazwa"></span>
        </div>
        <main class="tresc-lista" id="zawartosc"><p class="ladowanie">Wczytywanie…</p></main>
        <p class="skroty">
            Skróty: <kbd>n</kbd> nowa książka · <kbd>/</kbd> szukaj · <kbd>s</kbd> statystyki
        </p>
        <p class="skroty-mobile" id="stopka-wersja"></p>`;

    document.getElementById('wyloguj').addEventListener('click', async () => {
        await api('logout.php', {});
        window.dispatchEvent(new Event('sesja-wygasla'));
    });

    document.getElementById('eksport').addEventListener('click', async () => {
        const wybor = await opcje(
            'Eksport katalogu',
            'Pobierz kopię swoich danych. ZIP zawiera JSON, CSV oraz wszystkie okładki i zdjęcia.',
            [
                { wartosc: 'zip', etykieta: 'ZIP (pełna kopia)', klasa: 'przycisk' },
                { wartosc: 'json', etykieta: 'JSON' },
                { wartosc: 'csv', etykieta: 'CSV' },
                { wartosc: 'import', etykieta: 'Importuj z pliku…' },
            ]
        );
        if (wybor === 'import') {
            oknoImportu(el, opcjeWidoku);
            return;
        }
        if (wybor) {
            location.href = 'api/export.php?format=' + wybor;
        }
    });

    const panel = document.getElementById('panel');
    document.getElementById('przelacz-filtry').addEventListener('click', (e) => {
        panel.hidden = !panel.hidden;
        e.currentTarget.setAttribute('aria-expanded', String(!panel.hidden));
    });

    document.getElementById('polki').addEventListener('click', () => oknoPolk(el, opcjeWidoku));

    document.getElementById('przelacz-widok').addEventListener('click', () => {
        ustaw.widok = ustaw.widok === 'siatka' ? 'lista' : 'siatka';
        zapisz();
        katalog(el);
    });

    // Własne dropdowny: sortowanie + filtr statusu (bez systemowych <select>)
    wybieracz('sort', SORTOWANIE, ustaw.sort, (w) => {
        ustaw.sort = w;
        zapisz();
        zaladuj();
    });
    wybieracz('f-status', [['', 'wszystkie'], ...Object.entries(STATUSY)],
        ustaw.filtry.status, () => przepiszFiltry());
    // Półka: „wszystkie" albo jedna wybrana (klik w chip przy karcie też filtruje)
    wybieracz('f-polka', [['', 'wszystkie'], ...slownikPolk.map((p) => [String(p.id), p.nazwa])],
        ustaw.filtry.polka, () => przepiszFiltry());
    // Wypożyczenie: wszystkie / wypożyczone / na regale
    wybieracz('f-wypozyczone', [['', 'wszystkie'], ['tak', 'wypożyczone'], ['nie', 'na regale']],
        ustaw.filtry.wypozyczone, () => przepiszFiltry());
    // Metryczka egzemplarza
    wybieracz('f-format', [['', 'wszystkie'], ...Object.entries(FORMATY)],
        ustaw.filtry.format, () => przepiszFiltry());
    wybieracz('f-szerokosc', [['', 'wszystkie'], ...Object.entries(SZEROKOSCI)],
        ustaw.filtry.szerokosc, () => przepiszFiltry());
    wybieracz('f-ocenione', [['', 'wszystkie'], ['tak', 'ocenione'], ['nie', 'bez oceny']],
        ustaw.filtry.ocenione, () => przepiszFiltry());

    document.getElementById('q').addEventListener('input', (e) => {
        clearTimeout(timerQ);
        timerQ = setTimeout(() => {
            ustaw.filtry.q = e.target.value;
            zapisz();
            zaladuj();
        }, 300);
    });

    // Klik (albo Enter/Spacja) w chip tagu na karcie → dodaje tag do filtra
    // kumulatywnie. Delegacja na #zawartosc: jego zawartość jest przerysowywana
    // przy każdym wczytaniu listy, więc słuchacz musi być na elemencie.
    const dodajTagZChipa = (chip) => {
        const obecne = tagi_naListe(ustaw.filtry.tag);
        const tag = chip.dataset.tag;
        if (!obecne.includes(tag)) {
            obecne.push(tag);
        }
        ustaw.filtry.tag = obecne.join(',');
        zapisz();
        katalog(el, opcjeWidoku);
    };
    const zawartoscEl = document.getElementById('zawartosc');
    zawartoscEl.addEventListener('click', (e) => {
        const chip = e.target.closest('[data-tag]');
        if (!chip) {
            return;
        }
        // Chip nie jest linkiem, więc bez tego klik odziedziczyłby nawigację
        // po karcie (karta sama jest linkiem do książki)
        e.preventDefault();
        e.stopPropagation();
        dodajTagZChipa(chip);
    });
    zawartoscEl.addEventListener('keydown', (e) => {
        if (e.key !== 'Enter' && e.key !== ' ') {
            return;
        }
        const chip = e.target.closest('[data-tag]');
        if (!chip) {
            return;
        }
        e.preventDefault();
        dodajTagZChipa(chip);
    });

    const przepiszFiltry = () => {
        ustaw.filtry.status = document.getElementById('f-status').dataset.wartosc;
        ustaw.filtry.polka = document.getElementById('f-polka').dataset.wartosc;
        ustaw.filtry.wypozyczone = document.getElementById('f-wypozyczone').dataset.wartosc;
        ustaw.filtry.format = document.getElementById('f-format').dataset.wartosc;
        ustaw.filtry.szerokosc = document.getElementById('f-szerokosc').dataset.wartosc;
        ustaw.filtry.ocenione = document.getElementById('f-ocenione').dataset.wartosc;
        for (const pole of ['tag', 'autor', 'rok', 'wydawca', 'jezyk', 'dodane_od', 'dodane_do']) {
            ustaw.filtry[pole] = document.getElementById(`f-${pole}`).value;
        }
        zapisz();
        document.getElementById('przelacz-filtry').innerHTML = etykietaFiltry();
        zaladuj();
    };
    for (const pole of ['tag', 'autor', 'rok', 'wydawca', 'jezyk', 'dodane_od', 'dodane_do']) {
        const input = document.getElementById(`f-${pole}`);
        input.addEventListener(pole.startsWith('dodane') ? 'change' : 'input', () => {
            if (pole.startsWith('dodane')) {
                przepiszFiltry();
            } else {
                clearTimeout(timerQ);
                timerQ = setTimeout(przepiszFiltry, 300);
            }
        });
    }
    for (const pole of ['format', 'szerokosc']) {
        ustaw.filtry[pole] = document.getElementById(`f-${pole}`).dataset.wartosc;
    }
    document.getElementById('wyczysc').addEventListener('click', () => {
        ustaw.filtry = { ...DOMYSLNE_FILTRY };
        zapisz();
        katalog(el);
    });

    await zaladuj();
    skrotyKlawiaturowe(el, opcjeWidoku);
    // Stopka na telefonie: skróty klawiaturowe są tam bezużyteczne (brak
    // klawiatury fizycznej), a wersja jest ukryta w nagłówku (.wersja
    // display:none poniżej 560 px). Dlatego wersja wchodzi na ich miejsce —
    // użytkownik zgłaszał, że na telefonie nie ma jak sprawdzić, co wgrał.
    const stopkaWersja = document.getElementById('stopka-wersja');
    if (stopkaWersja) {
        stopkaWersja.textContent = WERSJA ? `Biblioteka ${WERSJA}` : '';
    }
}

/**
 * Skróty klawiaturowe: „n" → dodaj książkę, „/" → skup kursor na wyszukiwaniu.
 * Ignorujemy, gdy fokus jest w polu tekstowym albo otwartym oknie (modal),
 * żeby nie przeszkadzały w pisaniu.
 */
function skrotyKlawiaturowe(el, opcjeWidoku) {
    const poleTekstowe = (e) => {
        const el2 = e.target;
        return el2 instanceof HTMLElement
            && (el2.tagName === 'INPUT' || el2.tagName === 'TEXTAREA'
                || el2.tagName === 'SELECT' || el2.isContentEditable);
    };
    document.onkeydown = (e) => {
        // nie przechwytujemy modyfikatorów (Ctrl+R = odświeżenie itd.)
        if (e.ctrlKey || e.metaKey || e.altKey) {
            return;
        }
        // otwarty modal / dropdown = klawiatura należy do okna
        if (document.querySelector('.modal-overlay') || document.querySelector('.wybieracz-lista:not([hidden])')) {
            return;
        }
        if (poleTekstowe(e)) {
            return;
        }
        if (e.key === 'n' || e.key === 'N') {
            e.preventDefault();
            location.hash = '#/dodaj';
        } else if (e.key === '/') {
            e.preventDefault();
            const q = document.getElementById('q');
            if (q) {
                q.focus();
                q.select();
            }
        } else if (e.key === 's' || e.key === 'S') {
            e.preventDefault();
            location.hash = '#/statystyki';
        }
    };
}

/** Filtry wymuszane przez widok (półka z URL, lista wypożyczeń) — mają
    pierwszeństwo nad zapisanymi w localStorage, inaczej dałoby się „wyjść”
    z widoku inną półką albo zerowym filtrem. */
let polkaWymuszona = '';
let wypozyczeniaWymuszone = '';
let statusWymuszony = '';
/** Kontekst widoku dla pustych stanów (renderLista czyta z modułu). */
let kontekstWidoku = { polka: null, tylkoAktywne: false, tylkoStatus: null };

async function zaladuj() {
    const zawartosc = document.getElementById('zawartosc');
    if (!zawartosc) {
        return;
    }
    const z = new URLSearchParams();
    for (const [k, v] of Object.entries(ustaw.filtry)) {
        if (v !== '') {
            z.set(k, v);
        }
    }
    // Widok półki ma pierwszeństwo nad zapisanym filtrem — inaczej dałoby się
    // „wyjść" z półka inną półką niż z jej paska.
    if (polkaWymuszona !== '') {
        z.set('polka', polkaWymuszona);
    }
    // Także widok wypożyczeń (wymuszony filtr „wypożyczone").
    if (wypozyczeniaWymuszone !== '') {
        z.set('wypozyczone', wypozyczeniaWymuszone);
    }
    if (statusWymuszony !== '') {
        z.set('status', statusWymuszony);
    }
    const [pole, kier] = ustaw.sort.split(':');
    z.set('sort', pole);
    z.set('kier', kier);

    try {
        const dane = await api('list.php?' + z.toString());
        if (dane === null) {
            return; // 401 → aplikacja obsłuży przez event sesji
        }
        zawartosc.innerHTML = renderLista(dane.ksiazki ?? []);
        odswiezRegal(dane.ksiazki ?? []);
        // Pusty stan z przyciskiem czyszczenia (bez location.reload)
        const szybko = document.getElementById('wyczysc-szybko');
        if (szybko) {
            szybko.addEventListener('click', () => {
                ustaw.filtry = { ...DOMYSLNE_FILTRY };
                zapisz();
                katalog(document.getElementById('app'));
            });
        }
    } catch (err) {
        zawartosc.innerHTML = `<p class="pusty">Nie udało się wczytać katalogu — ${esc(err.message)}</p>`;
    }
}

/**
 * Pasek regału: SAM NAPIS — liczba egzemplarzy po lewej, nazwa kontekstu
 * (półka / wypożyczone / do przeczytania) po prawej. Graficzna reprezentacja
 * liczby (moduły regału) została usunięta razem z ustawianiem `--moduly`.
 */
function odswiezRegal(ksiazki) {
    const regal = document.getElementById('regal');
    if (!regal) {
        return;
    }
    regal.querySelector('.regal-liczba').textContent =
        `${ksiazki.length} ${ksiazki.length === 1 ? 'egzemplarz' : 'egzemplarzy'}`;
    const nazwa = document.getElementById('regal-nazwa');
    if (nazwa) {
        nazwa.textContent = kontekstWidoku.polka
            ? kontekstWidoku.polka.nazwa
            : (kontekstWidoku.tylkoAktywne ? 'Wypożyczone'
                : (kontekstWidoku.tylkoStatus
                    ? (STATUSY[kontekstWidoku.tylkoStatus] ?? '') : ''));
    }
}

function renderLista(lista) {
    if (lista.length === 0) {
        // Pusty stan zależy od tego, gdzie jesteśmy — samo „katalog jest pusty"
        // w widoku „do przeczytania" jest mylące
        if (kontekstWidoku.tylkoStatus === 'chce_przeczytac') {
            return `<div class="pusty">
                <p>Wszystko przeczytane — lista „chcę przeczytać” jest pusta.</p>
                <a class="przycisk-pusty" href="#/katalog">${ikona('strzalkaLewo')} Wszystkie książki</a>
            </div>`;
        }
        if (kontekstWidoku.tylkoAktywne) {
            return `<div class="pusty">
                <p>Żadna książka nie jest wypożyczona — wszystko jest w domu.</p>
                <a class="przycisk-pusty" href="#/katalog">${ikona('strzalkaLewo')} Wszystkie książki</a>
            </div>`;
        }
        if (kontekstWidoku.polka) {
            return `<div class="pusty">
                <p>Półka „${esc(kontekstWidoku.polka.nazwa)}” jest jeszcze pusta.</p>
                <a class="przycisk-pusty" href="#/katalog">${ikona('strzalkaLewo')} Wszystkie książki</a>
            </div>`;
        }
        const pusteFiltry = ileFiltrow() > 0 || ustaw.filtry.q !== '';
        return `<div class="pusty">
            <p>${pusteFiltry ? 'Żadna książka nie pasuje do filtrów.' : 'Katalog jest pusty.'}</p>
            ${pusteFiltry
                ? '<button class="przycisk-pusty" id="wyczysc-szybko">Wyczyść filtry</button>'
                : '<a class="przycisk" href="#/dodaj">Dodaj pierwszą książkę</a>'}
        </div>`;
    }

    if (ustaw.widok === 'siatka') {
        return `<div class="siatka">${lista.map((k) => `
            <a class="karta" href="#/ksiazka/${k.id}" style="--ocena:${Number(k.ocena) || 0}">
                ${okladkaHTML(k)}
                <span class="karta-tresc">
                    <span class="karta-tytul">${esc(k.tytul)}</span>
                    <span class="karta-autor" title="${esc(k.autor || '—')}">${esc(k.autor || '—')}</span>
                    ${tagiHTML(k.tagi)}
                    <div class="chip-row">${(k.polki ?? []).map(polkaZwykla).join('')}</div>
                    ${k.ocena ? gwiazdki(`oc-${k.id}`, k.ocena, { readonly: true, maly: true }) : ''}
                    ${badgeStatus(k.status)}
                    ${badgeWypozyczona(k.wypozyczona)}
                </span>
            </a>`).join('')}</div>`;
    }

    return `<div class="lista">${lista.map((k) => `
        <a class="wiersz" href="#/ksiazka/${k.id}">
            ${okladkaHTML(k)}
            <span class="wiersz-info">
                <b title="${esc(k.tytul)}">${esc(k.tytul)}</b>
                <span>${esc(k.autor || '—')}${k.rok ? ` · ${esc(k.rok)}` : ''}</span>
            </span>
            <span class="wiersz-meta">
                ${(k.polki ?? []).map(polkaZwykla).join('')}
            </span>
            <span class="wiersz-stany">
                ${badgeWypozyczonaLista(k.wypozyczona)}
                ${badgeStatusLista(k.status)}
            </span>
        </a>`).join('')}</div>`;
}

/* ============================================================== import */

/**
 * Import z pliku JSON (round-trip własnego eksportu). Wybór pliku → podgląd
 * zawartości → „scal" (dodaje brakujące) albo „przywróć" (kasuje katalog
 * najpierw, więc pytamy dwa razy).
 */
function oknoImportu(el, opcjeWidoku) {
    const overlay = document.createElement('div');
    overlay.className = 'modal-overlay';
    overlay.innerHTML = `
        <div class="modal" role="dialog" aria-modal="true">
            <h3>Importuj katalog</h3>
            <p class="modal-opis">Wybierz plik <code>eksport.json</code> lub
                <code>biblioteka-eksport-*.json</code> pobrany z tej aplikacji.
                Odzyskasz książki, tagi, półki i historię wypożyczeń.</p>
            <div class="pole">
                <span>Plik JSON</span>
                <input type="file" id="import-plik" accept="application/json,.json" hidden>
                <button type="button" class="przycisk-pusty" id="import-wybierz">${ikona('plik')} Wybierz plik</button>
                <p class="pole-pusto" id="import-info">Nie wybrano pliku.</p>
            </div>
            <div id="import-podglad"></div>
            <div class="modal-przyciski">
                <button class="przycisk-pusty" data-zamknij>Anuluj</button>
                <button class="przycisk-pusty" id="import-scal" disabled>Scal z katalogiem</button>
                <button class="przycisk-niebezpieczny" id="import-przywroc" disabled>Przywróć (kasuje obecne)</button>
            </div>
        </div>`;
    document.body.appendChild(overlay);

    let tekstPliku = null;

    // Zamknięcie modalu. `odswiez` przerysowuje katalog — po imporcie robimy to
    // od razu (a nie dopiero przy zamkaniu), bo użytkownik często zostawia okno
    // otwarte, żeby sprawdzić wynik, i katalog pod spodem musi być aktualny.
    const zamknij = (odswiez = false) => {
        overlay.remove();
        if (odswiez) {
            cacheWyczysc();
            katalog(el, opcjeWidoku);
        }
    };
    overlay.querySelector('[data-zamknij]').addEventListener('click', () => zamknij(false));
    overlay.addEventListener('click', (e) => {
        if (e.target === overlay) {
            zamknij();
        }
    });

    // Podgląd od razu po wyborze pliku — user widzi co importuje, zanim kliknie
    const pokaz = () => {
        const info = overlay.querySelector('#import-info');
        const podglad = overlay.querySelector('#import-podglad');
        let j;
        try {
            j = JSON.parse(tekstPliku);
        } catch {
            info.textContent = 'To nie jest poprawny JSON.';
            podglad.innerHTML = '';
            overlay.querySelector('#import-scal').disabled = true;
            overlay.querySelector('#import-przywroc').disabled = true;
            return;
        }
        if (j?.format !== 'biblioteka-eksport/v1') {
            info.textContent = `Nieznany format pliku (${j?.format ?? 'brak'}) — importuj tylko pliki tej aplikacji.`;
            podglad.innerHTML = '';
            overlay.querySelector('#import-scal').disabled = true;
            overlay.querySelector('#import-przywroc').disabled = true;
            return;
        }

        const ksiazki = Array.isArray(j.ksiazki) ? j.ksiazki.length : 0;
        const wyp = Array.isArray(j.wypozyczenia) ? j.wypozyczenia.length : 0;
        const slow = j.slowowniki ?? {};
        info.textContent = `Plik z ${j.liczba ?? ksiazki} rekordami`;
        podglad.innerHTML = `
            <dl class="import-podsumowanie">
                <dt>Książki</dt><dd>${ksiazki}</dd>
                <dt>Wypożyczenia</dt><dd>${wyp}</dd>
                <dt>Tagów</dt><dd>${(slow.tagi ?? []).length}</dd>
                <dt>Półek</dt><dd>${(slow.polki ?? []).length}</dd>
                <dt>Eksport z</dt><dd>${esc(j.data ?? '?')}${j.wersja ? ` · v${esc(j.wersja)}` : ''}</dd>
            </dl>`;
        overlay.querySelector('#import-scal').disabled = false;
        overlay.querySelector('#import-przywroc').disabled = false;
    };

    overlay.querySelector('#import-wybierz').addEventListener('click', () => {
        overlay.querySelector('#import-plik').click();
    });
    overlay.querySelector('#import-plik').addEventListener('change', async (e) => {
        const plik = e.target.files?.[0];
        if (!plik) {
            return;
        }
        tekstPliku = await plik.text();
        overlay.querySelector('#import-info').textContent = plik.name;
        pokaz();
    });

    const wykonaj = async (tryb, potwierdzenie) => {
        try {
            const r = await api('import.php', {
                dane: tekstPliku,
                tryb,
                potwierdz: potwierdzenie,
            });
            if (r === null) {
                return;
            }
            // Szczegóły (pominięte tytuły, błędy) zostają w modalu — toast nie
            // udźwignie listy, a systemowy alert to dokładnie to, od czego
            // odchodzimy w tym interfejsie.
            const czesci = [`Dodano ${r.dodane}`];
            if (r.pominiete) {
                czesci.push(`pominięto ${r.pominiete} (już są w katalogu)`);
            }
            const szczegoly = [];
            if (r.pominiete_tytuly?.length) {
                szczegoly.push('Pominięte: ' + r.pominiete_tytuly.join(', ')
                    + (r.pominiete > r.pominiete_tytuly.length ? '…' : ''));
            }
            if (r.bledy?.length) {
                szczegoly.push('Błędy: ' + r.bledy.join(' | '));
            }
            overlay.querySelector('#import-info').textContent = czesci.join(', ');
            overlay.querySelector('#import-podglad').innerHTML = `
                <div class="import-wynik${r.bledy?.length ? ' jest-blad' : ''}">
                    <b>Import zakończony</b>
                    <p>Wczytano ${r.wczytano} rekordów z pliku, w tym
                       ${r.wypozyczenia} wypożyczeń.</p>
                    ${szczegoly.map((s) => `<p>${esc(s)}</p>`).join('')}
                </div>`;
            cacheWyczysc();
            // Katalog pod spodem musi pokazać to, co właśnie wjechało. Modal
            // siedzi na document.body, więc przerysowanie #app go nie rusza —
            // użytkownik może zostawić okno otwarte i widzieć wynik.
            await katalog(el, opcjeWidoku);
            if (r.bledy?.length) {
                komunikat(`Import: dodano ${r.dodane}, błędy: ${r.bledy.length}`, 'blad');
            } else {
                komunikat(`Import: dodano ${r.dodane}`, 'ok');
            }
        } catch (err) {
            overlay.querySelector('#import-info').textContent = '';
            overlay.querySelector('#import-podglad').innerHTML =
                `<div class="import-wynik jest-blad"><b>Import nie powiódł się</b><p>${esc(err.message)}</p></div>`;
            komunikat(err.message, 'blad');
        }
    };

    overlay.querySelector('#import-scal').addEventListener('click', () => wykonaj('scal', false));
    overlay.querySelector('#import-przywroc').addEventListener('click', async () => {
        const ok = await potwierdz(
            'Przywrócić katalog z pliku?',
            'Obecny katalog zostanie skasowany (książki, tagi, półki i wypożyczenia). '
            + 'Tej operacji nie da się cofnąć — jedynym zabezpieczeniem jest wcześniejszy eksport.',
            'Kasuj i przywracaj'
        );
        if (ok) {
            await wykonaj('przywroc', true);
        }
    });
}

/* ==================================================== zarządzanie półkami */

/**
 * Modal zarządzania półkami: lista z liczbą książek, tworzenie nowej, usuwanie.
 * Usunięcie półki zdejmuje ją z książek (FK w bazie), ale nie kasuje książek.
 */
async function oknoPolk(el, opcjeWidoku) {
    const polki = await slownikPolki();
    const overlay = document.createElement('div');
    overlay.className = 'modal-overlay';
    overlay.innerHTML = `
        <div class="modal modal-szerszy" role="dialog" aria-modal="true">
            <h3>Półki</h3>
            <p class="modal-opis">Półka to nazwana grupa książek z własnym widokiem.
                Miejsce na półce (kolejność na regale) dodamy osobno.</p>
            <ul class="lista-polk">
                ${polki.length ? polki.map((p) => `
                    <li>
                        <a href="#/polka/${esc(p.id)}" class="lista-polka-nazwa">${ikona('polka')} ${esc(p.nazwa)}</a>
                        <span class="lista-polka-ile">${esc(p.ile)}</span>
                        <button class="ikona ikona-mala" data-usun="${esc(p.id)}"
                                title="Usuń półkę" aria-label="Usuń półkę ${esc(p.nazwa)}">${ikona('krzyz')}</button>
                    </li>`).join('')
                    : '<li class="pole-pusto">Nie masz jeszcze półek.</li>'}
            </ul>
            <form class="dodaj-polka">
                <input type="text" id="nowa-polka" placeholder="Nazwa nowej półki"
                       maxlength="60" required autocomplete="off">
                <button class="przycisk" type="submit">Dodaj</button>
            </form>
            <div class="modal-przyciski">
                <button class="przycisk-pusty" data-zamknij>Zamknij</button>
            </div>
        </div>`;
    document.body.appendChild(overlay);

    const zamknij = () => {
        overlay.remove();
        katalog(el, opcjeWidoku);
    };
    overlay.querySelector('[data-zamknij]').addEventListener('click', zamknij);
    overlay.addEventListener('click', (e) => {
        if (e.target === overlay) {
            zamknij();
        }
    });

    overlay.querySelectorAll('[data-usun]').forEach((b) => {
        b.addEventListener('click', async () => {
            const nazwa = polki.find((p) => String(p.id) === b.dataset.usun)?.nazwa ?? '';
            const ok = await potwierdz(
                'Usunąć półkę?',
                `„${nazwa}” zniknie z książek, których dotyczy. Same książki zostaną w katalogu.`,
                'Usuń półkę'
            );
            if (!ok) {
                return;
            }
            try {
                await api('polki.php', { akcja: 'usun', id: Number(b.dataset.usun) });
                cacheWyczysc();
                komunikat('Półka usunięta', 'ok');
                overlay.remove();
                oknoPolk(el, opcjeWidoku);
            } catch (err) {
                komunikat(err.message, 'blad');
            }
        });
    });

    overlay.querySelector('.dodaj-polka').addEventListener('submit', async (e) => {
        e.preventDefault();
        const nazwa = overlay.querySelector('#nowa-polka').value;
        try {
            await api('polki.php', { akcja: 'zapisz', nazwa });
            cacheWyczysc();
            overlay.remove();
            oknoPolk(el, opcjeWidoku);
        } catch (err) {
            komunikat(err.message, 'blad');
        }
    });

    overlay.querySelector('#nowa-polka').focus();
}
