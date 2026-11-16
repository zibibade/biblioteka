/* Biblioteka — wspólne elementy UI: escape, okładki, badge'e, modal, toasty. */

export const STATUSY = {
    chce_przeczytac: 'Chcę przeczytać',
    w_trakcie: 'W trakcie',
    przeczytane: 'Przeczytane',
};

export let WERSJA = '';

export function wersjaUstaw(w) {
    WERSJA = w ? `v${w}` : '';
}

/** Escape danych z API/wpisanych przez użytkownika (XSS). */
export function esc(v) {
    return String(v ?? '').replace(/[&<>"']/g, (c) => ({
        '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;',
    }[c]));
}

/** Inicjały tytułu na miejsce brakującej okładki. */
export function inicjaly(tytul) {
    const slowa = String(tytul || '?').trim().split(/\s+/).slice(0, 2);
    return slowa.map((s) => s[0] || '').join('').toUpperCase();
}

/** Okładka książki: <img> albo placeholder z inicjałami. */
export function okladkaHTML(k) {
    if (k.okladka) {
        return `<img class="okladka" src="${esc(k.okladka)}" alt="" loading="lazy">`;
    }
    return `<div class="okladka-miejsce" aria-hidden="true">${esc(inicjaly(k.tytul))}</div>`;
}

/**
 * Odznaka statusu. Stan niesie IKONA (kształt), nie sam kolor — kolor
 * jest tylko wzmianką, bo w słabym świetle telefonu różnice barw znikają.
 */
const IKONA_STATUSU = {
    chce_przeczytac: 'pusty',
    w_trakcie: 'czytam',
    przeczytane: 'check',
};

export function badgeStatus(status) {
    return `<span class="badge badge--${esc(status)}">`
        + `${ikona(IKONA_STATUSU[status] ?? 'pusty')}`
        + `${esc(STATUSY[status] ?? status)}</span>`;
}

/* ============================================================== ikony */

/*
 * Własny zestaw SVG: jedna grubość kreski, zaokrąglone końce, siatka 24.
 * Glify Unicode jako ikony wyglądają jak skróty wklejone z innej czcionki —
 * najtańszy sygnał, że interfejs był złożony, a nie zaprojektowany.
 */
const IKONY = {
    szukaj: '<circle cx="11" cy="11" r="7"/><path d="M20 20l-3.6-3.6"/>',
    plus: '<path d="M12 5v14M5 12h14"/>',
    minus: '<path d="M5 12h14"/>',
    powiekszenie: '<circle cx="11" cy="11" r="7"/><path d="M20 20l-3.6-3.6M8.5 11h5M11 8.5v5"/>',
    strzalkaLewo: '<path d="M15 5l-7 7 7 7"/>',
    strzalkaDol: '<path d="M6 9l6 6 6-6"/>',
    polka: '<path d="M4 7h16M4 12h16M4 17h10"/>',
    wykres: '<path d="M4 19V5"/><path d="M4 19h16"/><path d="M8 15v-4M12 15V8M16 15v-6"/>',
    pobierz: '<path d="M12 4v11"/><path d="M8 11l4 4 4-4"/><path d="M5 19h14"/>',
    wczytaj: '<path d="M12 19V8"/><path d="M8 12l4-4 4 4"/><path d="M5 5h14"/>',
    plik: '<path d="M14 4H7a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V9z"/><path d="M14 4v5h5"/>',
    aparat: '<path d="M4 8h3l1.5-2h7L17 8h3a1 1 0 0 1 1 1v9a1 1 0 0 1-1 1H4a1 1 0 0 1-1-1V9a1 1 0 0 1 1-1z"/><circle cx="12" cy="13" r="3.5"/>',
    latarka: '<path d="M18 6c0 2-2 3-2 5v9a1 1 0 0 1-1 1h-2a1 1 0 0 1-1-1v-9c0-2-2-3-2-5V2h8z"/><path d="M6 6h12M6 10h12"/>',
    wymiana: '<path d="M4 8h13l-3-3"/><path d="M20 16H7l3 3"/>',
    lista: '<path d="M4 7h16M4 12h16M4 17h16"/>',
    siatka: '<path d="M4 5h7v6H4zM13 5h7v6h-7zM4 13h7v6H4zM13 13h7v6h-7z"/>',
    filtr: '<path d="M4 6h16"/><path d="M7 12h10"/><path d="M10 18h4"/>',
    krzyz: '<path d="M6 6l12 12M18 6L6 18"/>',
    gwiazdka: '<path d="M12 4l2.3 5.1 5.7.6-4.3 3.8 1.2 5.5L12 16.2 7.1 19l1.2-5.5L4 9.7l5.7-.6z" fill="currentColor" stroke="none"/>',
    zakladka: '<path d="M7 4h10a1 1 0 0 1 1 1v15l-6-3.5L6 20V5a1 1 0 0 1 1-1z"/>',
    check: '<path d="M5 12.5l4.5 4.5L19 7"/>',
    pusty: '<circle cx="12" cy="12" r="3.5"/>',
    ksiezyc: '<path d="M20 14.5A8.5 8.5 0 0 1 9.5 4 8.5 8.5 0 1 0 20 14.5z"/>',
    narzedzia: '<path d="M14.7 6.3a1 1 0 0 0 0 1.4l1.6 1.6a1 1 0 0 0 1.4 0l3.77-3.77a6 6 0 0 1-7.94 7.94l-6.91 6.91a2.12 2.12 0 0 1-3-3l6.91-6.91a6 6 0 0 1 7.94-7.94l-3.76 3.76z"/>',
};

/** Ikona SVG w rozmiarze dopasowanym do kontenera. */
export function ikona(nazwa, klasa = '') {
    const sciezki = IKONY[nazwa];
    if (!sciezki) {
        return '';
    }
    return `<svg class="${esc(klasa)}" viewBox="0 0 24 24" fill="none"
        stroke="currentColor" stroke-width="1.75" stroke-linecap="round"
        stroke-linejoin="round" aria-hidden="true" focusable="false">${sciezki}</svg>`;
}

/* ======================================================= tagi i półki */

/*
 * UWAGA na zagnieżdżanie: <a> wewnątrz <a> jest niepoprawnym HTML-em — parser
 * wywędzi link z wnętrza, a DOM dostaje bzdury (chip wychodzi poza kartę,
 * a w siatce zostaje przeniesiony obok siatki). Dlatego:
 *   - na karcie/wierszu katalogu chip to `<span>` z data-atrybutami,
 *     klik obsługuje katalog (delegacja na #zawartosc),
 *   - link `<a>` używamy tylko tam, gdzie nie ma nadrzędnego linku
 *     (kartka książki, widok pełny).
 */

/** Chip tagu bez linku — dla kart i wierszy katalogu. */
export function chipTag(nazwa) {
    return `<span class="chip" data-tag="${esc(nazwa)}" role="button" tabindex="0"
        title="Filtruj: ${esc(nazwa)}">#${esc(nazwa)}</span>`;
}

/** Wiersz chipów z tagami książki (pusty → pusty string). */
export function tagiHTML(tagi = []) {
    if (!tagi || !tagi.length) {
        return '';
    }
    return `<div class="chip-row">${tagi.map(chipTag).join('')}</div>`;
}

/** Odznaka półki — link do widoku półki (kartka książki, nie karta katalogu). */
export function polkaBadge(polka) {
    return `<a class="chip chip--polka" href="#/polka/${esc(polka.id)}"
        title="Półka: ${esc(polka.nazwa)}">${ikona('polka')} ${esc(polka.nazwa)}</a>`;
}

/** Wariant półki bez linku — dla kart i wierszy katalogu. */
export function polkaZwykla(polka) {
    return `<span class="chip chip--polka"
        title="Półka: ${esc(polka.nazwa)}">${ikona('polka')} ${esc(polka.nazwa)}</span>`;
}

/** Odznaka „wypożyczona" na karcie w katalogu (kto i od kiedy). */
export function badgeWypozyczona(w) {
    if (!w) {
        return '';
    }
    return `<span class="badge badge--wypozyczona"
        title="Wypożyczona: ${esc(w.osoba)} (od ${esc(w.od)})">${ikona('wymiana')}${esc(w.osoba)}</span>`;
}

/**
 * Wariant listy: w wierszu liczy się miejsce — stan niesie sama ikona
 * (ptaszek / kropeczka / strzałeczki), tekst zostaje tylko przy „w trakcie",
 * bo tam sama ikona nie mówi nic o postępie.
 */
export function badgeStatusLista(status) {
    if (status === 'w_trakcie') {
        return badgeStatus(status);
    }
    const nazwa = STATUSY[status] ?? status;
    return `<span class="badge badge--${esc(status)} badge--ikona"
        title="${esc(nazwa)}" aria-label="${esc(nazwa)}">`
        + `${ikona(IKONA_STATUSU[status] ?? 'pusty')}</span>`;
}

/** Wariant listy dla wypożyczenia: same strzałeczki (nazwisko w title). */
export function badgeWypozyczonaLista(w) {
    if (!w) {
        return '';
    }
    return `<span class="badge badge--wypozyczona badge--ikona"
        title="Wypożyczona: ${esc(w.osoba)} (od ${esc(w.od)})"
        aria-label="Wypożyczona: ${esc(w.osoba)}">${ikona('wymiana')}</span>`;
}

/**
 * Pole „tagi" w formularzu: text input + podpowiedzi istniejących tagów
 * (datalist). Wartość wraca jako tekst po przecinkach — normalizuje serwer.
 */
export function poleTagow(id, wartosc, podpowiedzi = []) {
    const lista = (podpowiedzi ?? []).filter(Boolean);
    return `
        <label class="pole">
            <span>Tagi <em>(po przecinku, maks. 12)</em></span>
            <input id="${esc(id)}" type="text" value="${esc(wartosc)}"
                   list="${esc(id)}-lista" placeholder="fantastyka, polska, na prezent"
                   autocomplete="off">
            <datalist id="${esc(id)}-lista">
                ${lista.map((t) => `<option value="${esc(t)}"></option>`).join('')}
            </datalist>
        </label>`;
}

/** Pole „półki" w formularzu: własne checkboxy (natywne wyglądają jak okienka systemowe). */
export function polePolk(polki = [], zaznaczone = []) {
    const wybrane = new Set((zaznaczone ?? []).map(String));
    if (!polki || !polki.length) {
        return `
            <div class="pole">
                <span>Półki</span>
                <p class="pole-pusto">Nie masz jeszcze półek — dodaj w katalogu przyciskiem „Półki”.</p>
            </div>`;
    }
    return `
        <div class="pole">
            <span>Półki</span>
            <div class="checkboxy" role="group" aria-label="Półki">
                ${polki.map((p) => `
                    <label class="checkbox">
                        <input type="checkbox" name="polka" value="${esc(p.id)}"
                               ${wybrane.has(String(p.id)) ? 'checked' : ''}>
                        <span>${esc(p.nazwa)}<em>${esc(p.ile ?? 0)}</em></span>
                    </label>`).join('')}
            </div>
        </div>`;
}

/** Odczyt zaznaczonych półek z grupy checkboxów. */
export function polkiZaznaczone(kontener) {
    return Array.from(kontener.querySelectorAll('input[name="polka"]:checked'))
        .map((i) => Number(i.value));
}

/* =============================================== metryczka egzemplarza */

export const FORMATY = {
    twarda: 'Twarda',
    miekka: 'Miękka',
};
export const SZEROKOSCI = {
    waski: 'Wąski',
    standard: 'Standard',
    szeroki: 'Szeroki',
};

/**
 * Format egzemplarza jako przyciski-segmenty (nie dropdown — na telefonie
 * segmenty są wygodniejsze i od razu widać wszystkie opcje).
 * Stan w data-wartosc; wybór obsługuje rodzic (segmenty mają role="radio").
 */
export function segmentyFormat(id, wartosc, opcje) {
    return `<span class="segmenty" id="${esc(id)}" data-wartosc="${esc(wartosc ?? '')}"
        role="radiogroup" aria-label="Format egzemplarza">
        ${Object.entries(opcje).map(([w, etykieta]) => `
            <button type="button" class="segment${w === wartosc ? ' jest' : ''}"
                    role="radio" aria-checked="${w === wartosc}"
                    data-wartosc="${esc(w)}">${esc(etykieta)}</button>`).join('')}
        <button type="button" class="segment segment--pusty${wartosc ? '' : ' jest'}"
                role="radio" aria-checked="${!wartosc}"
                data-wartosc="">— brak —</button>
    </span>`;
}

/** Podpięcie kliknięć w grupie segmentów (delegacja — grupa jest przerysowywana). */
export function segmentyPodlacz(kontener, onChange) {
    kontener.addEventListener('click', (e) => {
        const przycisk = e.target.closest('.segment');
        if (!przycisk) {
            return;
        }
        const w = przycisk.dataset.wartosc;
        kontener.dataset.wartosc = w;
        kontener.querySelectorAll('.segment').forEach((s) => {
            const zazn = s === przycisk;
            s.classList.toggle('jest', zazn);
            s.setAttribute('aria-checked', String(zazn));
        });
        onChange?.(w);
    });
}

/** Czytelna etykieta formatu (metryczka, komunikaty). */
export function formatEtykieta(format) {
    return FORMATY[format] ?? '';
}

/** Czytelna etykieta szerokości. */
export function szerokoscEtykieta(szerokosc) {
    return SZEROKOSCI[szerokosc] ?? '';
}

/* ============================================================== oceny */

/**
 * Gwiazdki 1–5. `readonly` (katalog) to sama informacja bez klikania;
 * klik w formularzu/kartce ustawia ocenę (klik w tę samą gwiazdkę czyści).
 * Stan w data-wartosc na kontenerze.
 */
export function gwiazdki(id, ocena, { readonly = false, maly = false } = {}) {
    const o = Number(ocena) || 0;
    return `<span class="gwiazdki${maly ? ' gwiazdki--maly' : ''}" id="${esc(id)}"
        data-wartosc="${o}" role="${readonly ? 'img' : 'radiogroup'}"
        ${readonly ? `aria-label="Ocena ${o} z 5"` : 'aria-label="Ocena własna"'} title="${o > 0 ? `Ocena: ${o}/5` : 'Bez oceny'}">
        ${[1, 2, 3, 4, 5].map((n) => `
            <button type="button"
                class="${readonly ? 'gwiazdka' : 'gwiazdka przycisk'}${o >= n ? ' jest-zaznaczona' : ''}"
                ${readonly ? 'tabindex="-1"' : ''} role="${readonly ? '' : 'radio'}"
                ${readonly ? '' : `aria-checked="${n === o}"`}
                data-ocena="${n}"
                aria-label="${n} ${n === 1 ? 'gwiazdka' : (n < 5 ? 'gwiazdki' : 'gwiazdek')}">${ikona('gwiazdka')}</button>`).join('')}
        ${readonly || !o ? '' : `<button type="button" class="gwiazdka gwiazdka--wyczysc"
            data-ocena="0" title="Usuń ocenę" aria-label="Usuń ocenę">${ikona('krzyz')}</button>`}
    </span>`;
}

/** Podpięcie kliknięć w gwiazdkach (delegacja na kontener). */
export function gwiazdkiPodlacz(kontener, onChange) {
    kontener.addEventListener('click', (e) => {
        const przycisk = e.target.closest('[data-ocena]');
        if (!przycisk) {
            return;
        }
        const n = Number(przycisk.dataset.ocena);
        // Klik w tę samą gwiazdkę = wyczyść (toggle)
        const nowa = n === Number(kontener.dataset.wartosc) ? 0 : n;
        kontener.dataset.wartosc = nowa;
        kontener.querySelectorAll('[data-ocena]').forEach((s) => {
            const v = Number(s.dataset.ocena);
            if (v === 0) {
                return;
            }
            s.classList.toggle('jest-zaznaczona', v <= nowa);
            s.setAttribute('aria-checked', String(v === nowa));
        });
        onChange?.(nowa || null);
    });
}

/** Przycisk powrotu do katalogu (ikona strzałki, nie glif tekstowy). */
/* Powrot-lewy: w nagłówku ten przycisk zawsze stoi po LEWEJ stronie swojego
   kontenera (margin-right: auto), a nie przyklejony do prawej krawędzi. */
export function powrotKatalog(etykieta = 'Katalog') {
    return `<a class="przycisk-pusty powrot-lewy" href="#/katalog">${ikona('strzalkaLewo')} ${esc(etykieta)}</a>`;
}

/* ---------------------------------------------------------------- motywy */

/*
 * Motyw to jeden atrybut na <html> (data-motyw); CSS podstawia pod niego
 * komplet tokenów kolorów. Wybór pamiętamy w localStorage, a ten sam odczyt
 * siedzi inline w <head> index.html — dzięki temu po odświeżeniu nie mruga
 * motyw domyślny. Bez JS zostaje „rytm" z atrybutu w HTML.
 */
const MOTYWY = {
    rytm:      { nazwa: 'Rytm kodu', ikona: 'ksiezyc',   kolor: '#0B0F17' },
    linear:    { nazwa: 'Linear',    ikona: 'lista',     kolor: '#0e1013' },
    narzedzia: { nazwa: 'Narzędzia', ikona: 'narzedzia', kolor: '#1f2735' },
};

/* Kolejność w menu wyboru — jak w MOTYWY, ale nazwana, żeby render nie
   zależał od kolejności kluczy obiektu. */
const KOLEJNOSC_MOTYWOW = ['rytm', 'linear', 'narzedzia'];

const KLUCZ_MOTYWU = 'bib.motyw';

/** Klucz aktywnego motywu (nieznana wartość → domyślny „rytm"). */
export function motywAktualny() {
    const klucz = document.documentElement.dataset.motyw;
    return Object.hasOwn(MOTYWY, klucz) ? klucz : 'rytm';
}

/** Meta theme-color idzie za motywem — pasek przeglądarki i PWA. */
function motywMeta(klucz) {
    document.querySelector('meta[name="theme-color"]')
        ?.setAttribute('content', MOTYWY[klucz].kolor);
}

/** Odświeża wygląd wybieracza po zmianie motywu (nagłówek zostaje ten sam). */
function motywWybieraczOdswiez(klucz) {
    document.querySelectorAll('[data-motyw-przelacz]').forEach((przycisk) => {
        przycisk.innerHTML = ikona(MOTYWY[klucz].ikona);
        przycisk.setAttribute('aria-label', `Motyw: ${MOTYWY[klucz].nazwa} — wybierz motyw`);
        przycisk.setAttribute('title', `Motyw: ${MOTYWY[klucz].nazwa}`);
    });
    document.querySelectorAll('[data-motyw-option]').forEach((opcja) => {
        opcja.setAttribute('aria-selected', String(opcja.dataset.motywOption === klucz));
    });
}

/** Ustawia motyw, zapisuje wybór i odświeża wygląd wybieracza. */
export function motywUstaw(klucz) {
    const wybrany = Object.hasOwn(MOTYWY, klucz) ? klucz : 'rytm';
    document.documentElement.dataset.motyw = wybrany;
    try {
        localStorage.setItem(KLUCZ_MOTYWU, wybrany);
    } catch { /* tryb prywatny: motyw działa do zamknięcia karty */ }
    motywMeta(wybrany);
    motywWybieraczOdswiez(wybrany);
}

/**
 * Wybieracz motywu (menu z 3 opcjami) — naglowek() wstawia go po prawej.
 * Lista używa klasy .wybieracz-lista, więc wygląda jak reszta dropdownów.
 * Obsługę kliknięć trzyma delegacja na document, bo widoki przerysowują
 * nagłówek (listener przypięty do przycisku przepadłby razem z nim).
 */
export function wybieraczMotywy() {
    const klucz = motywAktualny();
    return `<div class="motyw-menu">
        <button type="button" class="ikona motyw-przelacz" data-motyw-przelacz
            aria-haspopup="listbox" aria-expanded="false"
            aria-label="Motyw: ${esc(MOTYWY[klucz].nazwa)} — wybierz motyw"
            title="Motyw: ${esc(MOTYWY[klucz].nazwa)}">${ikona(MOTYWY[klucz].ikona)}</button>
        <ul class="wybieracz-lista motyw-lista" role="listbox" aria-label="Motyw" hidden>
            ${KOLEJNOSC_MOTYWOW.map((k) => `<li role="option" tabindex="-1"
                data-motyw-option="${k}" aria-selected="${k === klucz}">${ikona(MOTYWY[k].ikona)}<span>${esc(MOTYWY[k].nazwa)}</span></li>`).join('')}
        </ul>
    </div>`;
}

/** Zamyka menu motywu (i zwraca fokus na przycisk, jeśli podano). */
function motywMenuZamknij(fokus = false) {
    document.querySelectorAll('.motyw-lista').forEach((lista) => {
        if (lista.hidden) {
            return;
        }
        lista.hidden = true;
        const przycisk = lista.parentElement.querySelector('[data-motyw-przelacz]');
        przycisk?.setAttribute('aria-expanded', 'false');
        if (fokus) {
            przycisk?.focus();
        }
    });
}

document.addEventListener('click', (e) => {
    const opcja = e.target.closest('[data-motyw-option]');
    if (opcja) {
        e.preventDefault();
        motywUstaw(opcja.dataset.motywOption);
        motywMenuZamknij(true);
        return;
    }
    const przycisk = e.target.closest('[data-motyw-przelacz]');
    if (przycisk) {
        e.stopPropagation();
        const lista = przycisk.parentElement.querySelector('.motyw-lista');
        const bylaOtwarta = !lista.hidden;
        motywMenuZamknij();
        if (!bylaOtwarta) {
            lista.hidden = false;
            przycisk.setAttribute('aria-expanded', 'true');
            lista.querySelector('[aria-selected="true"]')?.focus();
        }
        return;
    }
    motywMenuZamknij();   // klik poza menu zamyka listę
});

/* Klawiatura: Escape zamyka, strzałki chodzą po opcjach, Enter/Spacja wybiera.
   (Enter i Spacja na samym przycisku działają natywnie — to <button>.) */
document.addEventListener('keydown', (e) => {
    const opcja = e.target.closest?.('[data-motyw-option]');
    if (opcja) {
        const opcje = [...opcja.parentElement.querySelectorAll('[data-motyw-option]')];
        const i = opcje.indexOf(opcja);
        if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
            e.preventDefault();
            opcje[(i + (e.key === 'ArrowDown' ? 1 : -1) + opcje.length) % opcje.length].focus();
        } else if (e.key === 'Enter' || e.key === ' ') {
            e.preventDefault();
            motywUstaw(opcja.dataset.motywOption);
            motywMenuZamknij(true);
        } else if (e.key === 'Escape') {
            e.preventDefault();
            motywMenuZamknij(true);
        }
        return;
    }
    if (e.key === 'Escape') {
        motywMenuZamknij();
    }
});

/* Logo jako inline SVG (a nie <img>), bo kolor ma brać z AKTYWNEGO motywu:
   `currentColor` dziedziczy --akcent z `.naglowek .logo`, a `--logo-jasny`
   rozjaśnia strony książki. Ten sam rysunek leży w assets/logo.svg — tam
   kolory są neutralne, bo favicon renderuje się poza dokumentem. */
const LOGO_SVG = `<svg viewBox="0 0 512 512" role="img" aria-hidden="true" focusable="false">
    <rect width="512" height="512" rx="112" fill="#0E1526"/>
    <path fill="currentColor" fill-rule="evenodd" d="
        M150 84 H272
        C325 84 356 112 356 154
        C356 183 339 205 313 214
        C346 222 367 247 367 282
        C367 330 332 362 278 362
        H150 Z
        M204 136 H266
        C290 136 302 146 302 165
        C302 184 290 194 266 194
        H204 Z
        M204 244 H276
        C302 244 315 255 315 277
        C315 299 302 310 276 310
        H204 Z"/>
    <path style="fill: var(--logo-jasny, currentColor)" d="M64 404 C124 380 200 380 248 402 L248 470 C200 448 124 448 64 472 Z"/>
    <path style="fill: var(--logo-jasny, currentColor)" d="M448 404 C388 380 312 380 264 402 L264 470 C312 448 388 448 448 472 Z"/>
</svg>`;

/**
 * Główna nawigacja: katalog papierowy obok ebooków. Aktywny link dostaje
 * akcentową klasę `chip`, nieaktywny wyciszoną `chip--polka` — bez nowych
 * reguł w CSS, a stan niosą też `aria-current`.
 * Znacznik `.chip-row` to istniejący układ flex z odstępami.
 */
export function nawigacja() {
    const h = location.hash || '#/';
    // Wszystko poza trasami ebooków (karta książki, półka, formularze) należy
    // do katalogu papierowego — inaczej link gasłby na podstronach.
    const ebook = /^#\/ebook/.test(h);
    const link = (href, etykieta, aktywny) => `<a class="chip${aktywny ? '' : ' chip--polka'}"
            href="${href}"${aktywny ? ' aria-current="page"' : ''}>${etykieta}</a>`;
    return `<nav class="nawigacja chip-row" aria-label="Katalogi">
        ${link('#/katalog', 'Katalog', !ebook)}
        ${link('#/ebooki', 'Ebooki', ebook)}
    </nav>`;
}

/** Nagłówek ekranu: logo + tytuł + nawigacja + wersja + przyciski po prawej. */
export function naglowek(przyciski = '') {
    return `<header class="naglowek">
        <a class="logo" href="#/katalog" aria-label="Katalog">${LOGO_SVG}</a>
        <h1>Biblioteka</h1>
        ${nawigacja()}
        <span class="wersja">${WERSJA}</span>
        <div class="naglowek-akcje">${przyciski}${wybieraczMotywy()}</div>
    </header>`;
}

/** Modal potwierdzenia → Promise<boolean> (klik w tło = anuluj). */
export function potwierdz(tytul, tresc, etykietaTak = 'Tak', etykietaNie = 'Anuluj') {
    return new Promise((resolve) => {
        const overlay = document.createElement('div');
        overlay.className = 'modal-overlay';
        overlay.innerHTML = `
            <div class="modal" role="dialog" aria-modal="true">
                <h3>${esc(tytul)}</h3>
                <p>${esc(tresc)}</p>
                <div class="modal-przyciski">
                    <button class="przycisk-pusty" data-nie>${esc(etykietaNie)}</button>
                    <button class="przycisk" data-tak>${esc(etykietaTak)}</button>
                </div>
            </div>`;
        const zamknij = (wynik) => {
            overlay.remove();
            resolve(wynik);
        };
        overlay.querySelector('[data-tak]').addEventListener('click', () => zamknij(true));
        overlay.querySelector('[data-nie]').addEventListener('click', () => zamknij(false));
        overlay.addEventListener('click', (e) => {
            if (e.target === overlay) {
                zamknij(false);
            }
        });
        document.body.appendChild(overlay);
        overlay.querySelector('[data-tak]').focus();
    });
}

/** Modal z dowolną liczbą opcji → Promise(wartość | null przy anulowaniu). */
export function opcje(tytul, tresc, lista) {
    return new Promise((resolve) => {
        const overlay = document.createElement('div');
        overlay.className = 'modal-overlay';
        overlay.innerHTML = `
            <div class="modal" role="dialog" aria-modal="true">
                <h3>${esc(tytul)}</h3>
                <p>${esc(tresc)}</p>
                <div class="modal-przyciski modal-opcje">
                    ${lista.map((o) => `<button class="${esc(o.klasa ?? 'przycisk-pusty')}"
                        data-wartosc="${esc(o.wartosc)}">${esc(o.etykieta)}</button>`).join('')}
                </div>
            </div>`;
        const zamknij = (w) => {
            overlay.remove();
            resolve(w);
        };
        overlay.querySelectorAll('[data-wartosc]').forEach((b) => {
            b.addEventListener('click', () => zamknij(b.dataset.wartosc));
        });
        overlay.addEventListener('click', (e) => {
            if (e.target === overlay) {
                zamknij(null);
            }
        });
        document.body.appendChild(overlay);
    });
}

/* ============================================================ wypożyczenia */

/** Panel wypożyczenia na karcie książki: stan + akcje + historia zwrotów. */
export function panelWypozyczenia(k) {
    const aktywne = k.wypozyczona ?? null;
    const historia = (k.wypozyczenia ?? []).filter((w) => !w.aktywne);
    const dzis = new Date().toISOString().slice(0, 10);

    return `
        <div class="panel-wypozyczenia${aktywne ? ' jest' : ''}">
            <span class="etykieta">Wypożyczenie</span>
            ${aktywne ? `
                <p class="wypozyczona-info">
                    <b>${ikona('wymiana')} ${esc(aktywne.osoba)}</b>
                    <span>od ${esc(aktywne.od ?? '')}</span>
                    ${aktywne.uwagi ? `<span class="wypozyczona-uwagi">${esc(aktywne.uwagi)}</span>` : ''}
                </p>
                <div class="detal-akcje">
                    <button class="przycisk" id="zwroc">${ikona('wczytaj')} Zaznacz jako zwróconą</button>
                </div>`
                : `<p class="pole-pusto">Na regale — dostępna.</p>
                <div class="detal-akcje">
                    <button class="przycisk-pusty" id="wypozycz">${ikona('wymiana')} Wypożycz komuś</button>
                </div>`}
            ${historia.length ? `
                <details class="historia">
                    <summary>Historia (${historia.length})</summary>
                    <ul>
                        ${historia.map((w) => `
                            <li>
                                <span>${esc(w.osoba)}</span>
                                <span>${esc(w.od ?? '')} → ${esc(w.do ?? '?')}</span>
                                <button class="ikona ikona-mala" data-usun-wyp="${esc(w.id)}"
                                        title="Usuń wpis z historii"
                                        aria-label="Usuń wpis historii">${ikona('krzyz')}</button>
                            </li>`).join('')}
                    </ul>
                </details>` : ''}
        </div>`;
}

/** Modal wypożyczenia: komu + uwagi. */
export function oknoWypozyczenia(ksiazka) {
    return new Promise((resolve) => {
        const overlay = document.createElement('div');
        overlay.className = 'modal-overlay';
        overlay.innerHTML = `
            <div class="modal" role="dialog" aria-modal="true">
                <h3>Wypożycz „${esc(ksiazka.tytul)}”</h3>
                <label class="pole">
                    <span>Komu *</span>
                    <input id="wyp-osoba" maxlength="120" autocomplete="off"
                           placeholder="np. Anna Kowalska" required autofocus>
                </label>
                <label class="pole">
                    <span>Uwagi (opcjonalnie)</span>
                    <input id="wyp-uwagi" maxlength="500" autocomplete="off"
                           placeholder="np. na 2 tygodnie, poleci mailem">
                </label>
                <div class="modal-przyciski">
                    <button class="przycisk-pusty" data-nie>Anuluj</button>
                    <button class="przycisk" data-tak>Wypożycz</button>
                </div>
            </div>`;
        const zakoncz = (wynik) => {
            overlay.remove();
            resolve(wynik);
        };
        overlay.querySelector('[data-tak]').addEventListener('click', () => {
            zakoncz({
                osoba: overlay.querySelector('#wyp-osoba').value,
                uwagi: overlay.querySelector('#wyp-uwagi').value,
            });
        });
        overlay.querySelector('[data-nie]').addEventListener('click', () => zakoncz(null));
        overlay.addEventListener('click', (e) => {
            if (e.target === overlay) {
                zakoncz(null);
            }
        });
        document.body.appendChild(overlay);
        overlay.querySelector('#wyp-osoba').focus();
    });
}

/* ============================================ własny dropdown (bez <select>) */

let wybieraczGlobalnie = false;

/**
 * Rozwijane menu w szacie serwisu — zamiast natywnego <select>, który na
 * telefonie otwiera systemowe okienko. Wartość trzymamy w data-wartosc
 * (odczyt: getElementById(id).dataset.wartosc), etykieta w przycisku.
 *
 * @param string   id       element-kontener, np. <span class="wybieracz" id="sort">
 * @param array    opcje    [[wartosc, etykieta], ...]
 * @param string   wartosc  bieżąca wartość
 * @param ?Function onChange wywoływana po wyborze (wartosc)
 */
export function wybieracz(id, opcje, wartosc, onChange = null) {
    const korzen = document.getElementById(id);
    if (!korzen) {
        return;
    }
    korzen.classList.add('wybieracz');
    korzen.dataset.wartosc = wartosc;
    const etykieta = (w) => opcje.find(([v]) => v === w)?.[1] ?? '';

    korzen.innerHTML = `
        <button type="button" class="wybieracz-przycisk" aria-haspopup="listbox" aria-expanded="false">
            <span class="wybieracz-wartosc">${esc(etykieta(wartosc))}</span>
            <span class="wybieracz-strzalka" aria-hidden="true">${ikona('strzalkaDol')}</span>
        </button>
        <ul class="wybieracz-lista" role="listbox" hidden>
            ${opcje.map(([v, t]) => `<li role="option" data-wartosc="${esc(v)}"${
                v === wartosc ? ' aria-selected="true"' : ''}>${esc(t)}</li>`).join('')}
        </ul>`;

    const przycisk = korzen.querySelector('.wybieracz-przycisk');
    const lista = korzen.querySelector('.wybieracz-lista');

    const zamknij = () => {
        lista.hidden = true;
        przycisk.setAttribute('aria-expanded', 'false');
    };
    const otworz = () => {
        zamknijListy(lista);   // tylko jedno menu naraz
        lista.hidden = false;
        przycisk.setAttribute('aria-expanded', 'true');
    };

    przycisk.addEventListener('click', (e) => {
        e.stopPropagation();    // nie zamykaj zaraz po otwarciu (globalny sluchacz)
        if (lista.hidden) {
            otworz();
        } else {
            zamknij();
        }
    });
    lista.querySelectorAll('[data-wartosc]').forEach((li) => {
        li.addEventListener('click', (e) => {
            // Bez zablokowania obu rzeczy klik z <label> obejście: stopPropagation
            // nie wystarcza, bo Chrome i tak wykonuje domyślne przekazanie kliknięcia
            // z <label> do przycisku → menu otwierałoby się z powrotem.
            e.stopPropagation();
            e.preventDefault();
            const w = li.dataset.wartosc;
            korzen.dataset.wartosc = w;
            korzen.querySelector('.wybieracz-wartosc').textContent = etykieta(w);
            lista.querySelectorAll('[role="option"]').forEach((o) => o.removeAttribute('aria-selected'));
            li.setAttribute('aria-selected', 'true');
            zamknij();
            onChange?.(w);
        });
    });

    // Zamkanie po kliknięciu w tło / Escape — jedno globalne (rejestrowane raz,
    // bo widoki są przerysowywane przez innerHTML)
    if (!wybieraczGlobalnie) {
        wybieraczGlobalnie = true;
        document.addEventListener('click', () => zamknijListy());
        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape') {
                zamknijListy();
            }
        });
    }
}

/** Zamknij wszystkie listy dropdownów (albo wszystkie poza wskazaną).
 *  Menu motywu ma własną obsługę (delegacja + fokus), więc je pomijamy. */
function zamknijListy(oprocz = null) {
    document.querySelectorAll('.wybieracz-lista').forEach((l) => {
        if (l !== oprocz && !l.hidden && !l.classList.contains('motyw-lista')) {
            l.hidden = true;
            l.previousElementSibling?.setAttribute('aria-expanded', 'false');
        }
    });
}

/** Przelotny komunikat na dole ekranu. */
export function komunikat(tekst, typ = '') {
    const el = document.createElement('div');
    el.className = 'toast' + (typ ? ` toast--${typ}` : '');
    el.setAttribute('role', 'status');
    // Ikona zamiast kolorowego paska: w słabym świetle sam kolor nie wystarczy
    const znak = typ === 'ok' ? 'check' : (typ === 'blad' ? 'krzyz' : '');
    el.innerHTML = `${ikona(znak)}<span>${esc(tekst)}</span>`;
    document.body.appendChild(el);
    setTimeout(() => el.remove(), 3500);
}

/** Jedno źródło prawdy dla filtrów (katalog je czyta i zapisuje). */
export const DOMYSLNE_FILTRY = {
    q: '', status: '', tag: '', polka: '', wypozyczone: '',
    format: '', szerokosc: '', ocenione: '',
    autor: '', rok: '', wydawca: '', jezyk: '', dodane_od: '', dodane_do: '',
};
