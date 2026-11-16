/* Biblioteka — katalog ebooków: siatka kart, filtry, paginacja.
   Stan filtrów trzymany w localStorage (tak jak katalog papierowy), żeby po
   powrocie do widoku użytkownik zastawał ten sam przegląd. */

import { api } from './api.js';
import {
    esc, ikona, okladkaHTML, badgeStatus, naglowek, komunikat,
    wybieracz, tagiHTML, polkaZwykla, gwiazdki, STATUSY, WERSJA,
} from './ui.js';
import { slownikTagi, slownikPolki, cacheWyczysc } from './katalog.js';

const KLUCZ = 'bib.ebooki';

/* Formaty plików — własny słownik, bo FORMATY z ui.js opisuje egzemplarz
   papierowy (twarda/miękka). Klucze muszą zgadzać się z EBOOK_FORMATY. */
const FORMATY_EBOOK = {
    epub: 'EPUB',
    pdf: 'PDF',
    mobi: 'MOBI',
    azw3: 'AZW3',
    fb2: 'FB2',
    cbz: 'CBZ',
    inne: 'Inne',
};

/* Wartości sortowania idą do API bez zmian: sufiks „_desc" odwraca kierunek,
   brak sufiksu = rosnąco. Nazwy muszą trafiać w białą listę w ebook_lista(). */
const SORTOWANIE = [
    ['dodania_desc', 'Ostatnio dodane'],
    ['dodania', 'Najdawniej dodane'],
    ['tytul', 'Tytuł A→Z'],
    ['tytul_desc', 'Tytuł Z→A'],
    ['autor', 'Autor A→Z'],
    ['autor_desc', 'Autor Z→A'],
    ['rok_desc', 'Rok: najnowsze'],
    ['rok', 'Rok: najstarsze'],
];

const DOMYSLNE_FILTRY = { q: '', status: '', format: '', tag: '', polka: '' };

let ustaw = { filtry: { ...DOMYSLNE_FILTRY }, sort: 'dodania_desc', strona: 1 };
let timerQ = null;

function wczytaj() {
    try {
        const j = JSON.parse(localStorage.getItem(KLUCZ) ?? 'null');
        if (j && typeof j === 'object') {
            ustaw = {
                filtry: { ...DOMYSLNE_FILTRY, ...(j.filtry ?? {}) },
                sort: typeof j.sort === 'string' ? j.sort : ustaw.sort,
                strona: Math.max(1, Number(j.strona) || 1),
            };
        }
    } catch { /* uszkodzony zapis — lecimy na domyślnych */ }
}

function zapisz() {
    localStorage.setItem(KLUCZ, JSON.stringify(ustaw));
}

function ileFiltrow() {
    return Object.values(ustaw.filtry).filter((v) => v !== '').length;
}

const etykietaFiltry = () =>
    `Filtry${ileFiltrow() > 0 ? ` <span class="kropka">•${ileFiltrow()}</span>` : ''}`
    + ` <span class="strzalka" aria-hidden="true">${ikona('strzalkaDol')}</span>`;

/** Odmiana rzeczownika po liczbie — „1 ebook", „3 ebooki", „12 ebooków". */
function odmianaEbook(n) {
    if (n === 1) {
        return 'ebook';
    }
    const reszta = n % 10;
    const dziesiatki = n % 100;
    if (reszta >= 2 && reszta <= 4 && (dziesiatki < 12 || dziesiatki > 14)) {
        return 'ebooki';
    }
    return 'ebooków';
}

/* ============================================================== widok */

export async function widokEbooki(korzen) {
    wczytaj();

    // Słowniki tagów i półek — te same co katalog papierowy (wspólne tabele)
    const [tagi, polki] = await Promise.all([slownikTagi(), slownikPolki()]);

    korzen.innerHTML = `
        ${naglowek(`
            <a class="ikona naglowe-ikona" href="#/statystyki" title="Statystyki"
                    aria-label="Statystyki">${ikona('wykres')}</a>
            <button class="przycisk-pusty" id="wyloguj" aria-label="Wyloguj"><span class="wyloguj-tekst">Wyloguj</span></button>`)}
        <div class="pasek">
            <input type="search" id="q" placeholder="Szukaj w ebookach…"
                   value="${esc(ustaw.filtry.q)}" aria-label="Szukaj">
            <a class="przycisk dodaj" href="#/ebook-dodaj" title="Dodaj ebooka">
                ${ikona('plus')}<span class="dodaj-tekst">Dodaj ebooka</span>
            </a>
            <button class="przycisk-pusty" id="import-folder" title="Zaimportuj folder z plikami">
                ${ikona('wczytaj')}<span class="dodaj-tekst">Importuj folder</span>
            </button>
            <button class="przycisk-pusty" id="przelacz-filtry" aria-expanded="false">${etykietaFiltry()}</button>
        </div>
        <div class="panel-filtrow" id="panel" hidden>
            <label>Sortowanie
                <span class="wybieracz" id="e-sort"></span>
            </label>
            <label>Status
                <span class="wybieracz" id="e-status"></span>
            </label>
            <label>Format
                <span class="wybieracz" id="e-format"></span>
            </label>
            <label>Półka
                <span class="wybieracz" id="e-polka"></span>
            </label>
            <label>Tag
                <input id="e-tag" value="${esc(ustaw.filtry.tag)}" list="e-tag-lista"
                       placeholder="po przecinku" autocomplete="off">
                <datalist id="e-tag-lista">
                    ${tagi.map((t) => `<option value="${esc(t.nazwa)}"></option>`).join('')}
                </datalist>
            </label>
            <button class="przycisk-pusty" id="wyczysc">Wyczyść filtry</button>
        </div>
        <div class="pasek-regal" id="regal">
            <span class="regal-liczba">—</span>
            <span class="regal-nazwa" id="regal-nazwa"></span>
        </div>
        <main class="tresc-lista" id="zawartosc-ebooki"><p class="ladowanie">Wczytywanie…</p></main>
        <nav class="pasek" id="paginacja" aria-label="Strony" hidden></nav>
        <p class="skroty-mobile" id="stopka-wersja"></p>`;

    document.getElementById('wyloguj').addEventListener('click', async () => {
        await api('logout.php', {});
        window.dispatchEvent(new Event('sesja-wygasla'));
    });

    const panel = document.getElementById('panel');
    document.getElementById('przelacz-filtry').addEventListener('click', (e) => {
        panel.hidden = !panel.hidden;
        e.currentTarget.setAttribute('aria-expanded', String(!panel.hidden));
    });

    // Skan folderu jest ciężki (fflate + parsery), a potrzebny rzadko — moduł
    // importu wchodzi dopiero po kliknięciu.
    document.getElementById('import-folder').addEventListener('click', async () => {
        try {
            const { importFolder } = await import('./ebook-import.js');
            await importFolder();
            odswiezEbooki();
        } catch (err) {
            komunikat(`Import niedostępny — ${err.message}`, 'blad');
        }
    });

    wybieracz('e-sort', SORTOWANIE, ustaw.sort, (w) => {
        ustaw.sort = w;
        ustaw.strona = 1;
        zapisz();
        zaladuj();
    });
    wybieracz('e-status', [['', 'wszystkie'], ...Object.entries(STATUSY)],
        ustaw.filtry.status, (w) => zmienFiltr('status', w));
    wybieracz('e-format', [['', 'wszystkie'], ...Object.entries(FORMATY_EBOOK)],
        ustaw.filtry.format, (w) => zmienFiltr('format', w));
    wybieracz('e-polka', [['', 'wszystkie'], ...polki.map((p) => [String(p.id), p.nazwa])],
        ustaw.filtry.polka, (w) => zmienFiltr('polka', w));

    document.getElementById('q').addEventListener('input', (e) => {
        clearTimeout(timerQ);
        timerQ = setTimeout(() => zmienFiltr('q', e.target.value), 300);
    });

    document.getElementById('e-tag').addEventListener('input', (e) => {
        clearTimeout(timerQ);
        timerQ = setTimeout(() => zmienFiltr('tag', e.target.value), 300);
    });

    document.getElementById('wyczysc').addEventListener('click', () => {
        ustaw.filtry = { ...DOMYSLNE_FILTRY };
        ustaw.strona = 1;
        zapisz();
        widokEbooki(korzen);
    });

    // Klik w chip tagu na karcie dokłada tag do filtra. Delegacja na kontener,
    // bo zawartość jest przerysowywana po każdym wczytaniu listy.
    const zawartoscEl = document.getElementById('zawartosc-ebooki');
    zawartoscEl.addEventListener('click', (e) => {
        const chip = e.target.closest('[data-tag]');
        if (!chip) {
            return;
        }
        // Chip siedzi w karcie, która sama jest linkiem — bez tego klik
        // przeniósłby do ebooka zamiast filtrować.
        e.preventDefault();
        e.stopPropagation();
        dodajTag(chip.dataset.tag);
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
        dodajTag(chip.dataset.tag);
    });

    document.getElementById('paginacja').addEventListener('click', (e) => {
        const przycisk = e.target.closest('[data-strona]');
        if (!przycisk || przycisk.disabled) {
            return;
        }
        ustaw.strona = Math.max(1, Number(przycisk.dataset.strona) || 1);
        zapisz();
        zaladuj();
        window.scrollTo(0, 0);
    });

    await zaladuj();

    const stopkaWersja = document.getElementById('stopka-wersja');
    if (stopkaWersja) {
        stopkaWersja.textContent = WERSJA ? `Biblioteka ${WERSJA}` : '';
    }
}

/** Zmiana filtra zawsze wraca na pierwszą stronę — inaczej strona 5
    zawężonego wyniku bywa pusta. */
function zmienFiltr(pole, wartosc) {
    ustaw.filtry[pole] = wartosc;
    ustaw.strona = 1;
    zapisz();
    const przycisk = document.getElementById('przelacz-filtry');
    if (przycisk) {
        przycisk.innerHTML = etykietaFiltry();
    }
    zaladuj();
}

function dodajTag(tag) {
    const obecne = String(ustaw.filtry.tag ?? '')
        .split(',')
        .map((t) => t.trim().toLowerCase())
        .filter(Boolean);
    if (!obecne.includes(tag)) {
        obecne.push(tag);
    }
    const pole = document.getElementById('e-tag');
    if (pole) {
        pole.value = obecne.join(',');
    }
    zmienFiltr('tag', obecne.join(','));
}

/** Odświeżenie z zewnątrz (okno importu dopisuje rekordy). */
export function odswiezEbooki() {
    if (document.getElementById('zawartosc-ebooki')) {
        cacheWyczysc();   // import mógł dodać nowe tagi do podpowiedzi
        zaladuj();
    }
}

/* ============================================================== dane */

async function zaladuj() {
    const zawartosc = document.getElementById('zawartosc-ebooki');
    if (!zawartosc) {
        return;
    }
    const z = new URLSearchParams();
    for (const [k, v] of Object.entries(ustaw.filtry)) {
        if (v !== '') {
            z.set(k, v);
        }
    }
    z.set('sort', ustaw.sort);
    z.set('strona', String(ustaw.strona));

    try {
        const dane = await api('ebooki.php?' + z.toString());
        if (dane === null) {
            return; // 401 → aplikacja obsłuży przez event sesji
        }
        // Numer strony bierzemy z odpowiedzi — serwer przycina zakres.
        ustaw.strona = Number(dane.strona) || 1;
        zawartosc.innerHTML = renderSiatka(dane.ebooki ?? []);
        odswiezPasek(Number(dane.razem) || 0);
        renderPaginacja(dane);

        const szybko = document.getElementById('wyczysc-szybko');
        if (szybko) {
            szybko.addEventListener('click', () => {
                ustaw.filtry = { ...DOMYSLNE_FILTRY };
                ustaw.strona = 1;
                zapisz();
                widokEbooki(document.getElementById('app'));
            });
        }
    } catch (err) {
        zawartosc.innerHTML =
            `<p class="pusty">Nie udało się wczytać ebooków — ${esc(err.message)}</p>`;
    }
}

function odswiezPasek(razem) {
    const regal = document.getElementById('regal');
    if (!regal) {
        return;
    }
    regal.querySelector('.regal-liczba').textContent = `${razem} ${odmianaEbook(razem)}`;
    // Nazwa kontekstu tylko przy zawężeniu — bez filtrów pasek byłby hałaśliwy
    const nazwa = document.getElementById('regal-nazwa');
    if (nazwa) {
        nazwa.textContent = ileFiltrow() > 0 ? 'wynik filtrów' : '';
    }
}

/** Odznaka „jest też papier" — sygnał, że ten tytuł stoi na regale. */
function badgePapier(powiazanaId) {
    if (!powiazanaId) {
        return '';
    }
    return `<span class="badge" title="Jest też w katalogu papierowym">
        ${ikona('zakladka')} papier</span>`;
}

function renderSiatka(lista) {
    if (lista.length === 0) {
        const pusteFiltry = ileFiltrow() > 0;
        return `<div class="pusty">
            <p>${pusteFiltry ? 'Żaden ebook nie pasuje do filtrów.' : 'Katalog ebooków jest pusty.'}</p>
            ${pusteFiltry
                ? '<button class="przycisk-pusty" id="wyczysc-szybko">Wyczyść filtry</button>'
                : '<a class="przycisk" href="#/ebook-dodaj">Dodaj pierwszego ebooka</a>'}
        </div>`;
    }

    return `<div class="siatka">${lista.map((e) => `
        <a class="karta" href="#/ebook/${esc(e.id)}" style="--ocena:${Number(e.ocena) || 0}">
            ${okladkaHTML(e)}
            <span class="karta-tresc">
                <span class="karta-tytul">${esc(e.tytul)}</span>
                <span class="karta-autor" title="${esc(e.autor || '—')}">${esc(e.autor || '—')}</span>
                ${tagiHTML(e.tagi)}
                <div class="chip-row">${(e.polki ?? []).map(polkaZwykla).join('')}</div>
                ${e.ocena ? gwiazdki(`eb-oc-${esc(e.id)}`, e.ocena, { readonly: true, maly: true }) : ''}
                <span class="badge" title="Format pliku">${esc(FORMATY_EBOOK[e.format] ?? e.format ?? '—')}</span>
                ${badgeStatus(e.status)}
                ${badgePapier(e.powiazana_ksiazka_id)}
            </span>
        </a>`).join('')}</div>`;
}

function renderPaginacja(dane) {
    const nav = document.getElementById('paginacja');
    if (!nav) {
        return;
    }
    const stron = Math.max(1, Number(dane.stron) || 1);
    const strona = Number(dane.strona) || 1;
    if (stron <= 1) {
        nav.hidden = true;
        nav.innerHTML = '';
        return;
    }
    nav.hidden = false;
    nav.innerHTML = `
        <button class="przycisk-pusty" data-strona="${strona - 1}"
                ${strona <= 1 ? 'disabled' : ''}>${ikona('strzalkaLewo')} Poprzednia</button>
        <span class="regal-liczba">Strona ${strona} z ${stron}</span>
        <button class="przycisk-pusty" data-strona="${strona + 1}"
                ${strona >= stron ? 'disabled' : ''}>Następna</button>`;
}
