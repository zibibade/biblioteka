/* Biblioteka — widok statystyk katalogu (#/statystyki).

   Papier i ebooki to dwa niezależne zbiory, więc widok ma dwie osobne sekcje
   z własnymi kaflami i wykresami — bez sum krzyżowych (decyzja użytkownika). */

import { api } from './api.js';
import { esc, naglowek, powrotKatalog, STATUSY } from './ui.js';

/* Formaty plików — własny słownik, bo FORMATY z ui.js opisuje egzemplarz
   papierowy. Klucze muszą zgadzać się z API i js/ebooki.js. */
const FORMATY_EBOOK = {
    epub: 'EPUB',
    pdf: 'PDF',
    mobi: 'MOBI',
    azw3: 'AZW3',
    fb2: 'FB2',
    cbz: 'CBZ',
    inne: 'Inne',
};

/** Jedna kafelka z liczbą. */
function kafelka(etykieta, wartosc, podpis = '') {
    return `
        <div class="stat-kafelka">
            <span class="stat-liczba">${esc(wartosc)}</span>
            <span class="stat-etykieta">${esc(etykieta)}</span>
            ${podpis ? `<span class="stat-podpis">${esc(podpis)}</span>` : ''}
        </div>`;
}

/** Pasek proporcji (do rozkładu statusów, lat, tagów). */
function pasekProporcji(etykieta, wartosc, max, opis = '') {
    const szerokosc = max > 0
        ? Math.min(100, Math.max(3, Math.round((wartosc / max) * 100)))
        : 0;
    return `
        <li class="stat-szereg">
            <span class="stat-nazwa" title="${esc(etykieta)}">${esc(etykieta)}</span>
            <span class="stat-szereg-srodek">
                <span class="stat-pasek" style="width:${szerokosc}%"></span>
            </span>
            <span class="stat-wartosc">${esc(wartosc)}${opis ? ` <em>${esc(opis)}</em>` : ''}</span>
        </li>`;
}

/** Skrót liczby tysięcy (12 400 → „12,4 tys."), żeby kafelki się mieściły. */
function skroc(liczba) {
    const n = Number(liczba) || 0;
    if (n < 1000) {
        return String(n);
    }
    if (n < 10000) {
        return (n / 1000).toFixed(1).replace('.', ',') + ' tys.';
    }
    return Math.round(n / 1000).toLocaleString('pl-PL') + ' tys.';
}

/** Liczba zmiennoprzecinkowa z przecinkiem dziesiętnym (4.14 → „4,14"). */
function dziesietne(liczba, miejsca = 2) {
    if (liczba === null || liczba === undefined) {
        return '—';
    }
    return Number(liczba).toFixed(miejsca).replace('.', ',');
}

/* ============================================================== bloki */

// Te same kształty danych w obu katalogach, więc bloki są wspólne — sekcje
// różnią się tylko zestawem bloków (ebooki mają formaty, papier wypożyczenia).

/** `stopki` to dodatkowe zdania pod paskami — liczby, które nie zmieściły się w kaflach. */
function blokCzytanie(statusy, suma, stopki = []) {
    const wszystkie = Object.keys(STATUSY).reduce((razem, k) => razem + (statusy[k] ?? 0), 0);
    const zdania = [
        ...stopki,
        suma.najstarszy && suma.najnowszy
            ? `Wydania od ${suma.najstarszy} do ${suma.najnowszy}` : '',
    ].filter(Boolean);
    return `
        <section class="stat-blok">
            <h3>Czytanie</h3>
            <ul class="stat-szeregi">
                ${Object.entries(STATUSY).map(([klucz, nazwa]) =>
                    pasekProporcji(nazwa, statusy[klucz] ?? 0, wszystkie || 1)).join('')}
            </ul>
            ${zdania.map((t) => `<p class="stat-stopka">${esc(t)}</p>`).join('')}
        </section>`;
}

function blokLat(lata) {
    if (!lata.length) {
        return `<section class="stat-blok"><h3>Rok wydania</h3>
            <p class="pole-pusto">Brak roczników.</p></section>`;
    }
    const max = Math.max(...lata.map((r) => Number(r.ile) || 0), 1);
    return `
        <section class="stat-blok">
            <h3>Rok wydania</h3>
            <ul class="stat-szeregi">
                ${lata.slice(0, 10).map((r) => pasekProporcji(r.rok, r.ile, max)).join('')}
            </ul>
            ${lata.length > 10 ? `<p class="stat-stopka">i ${lata.length - 10} starszych lat</p>` : ''}
        </section>`;
}

function blokAutorow(autorzy) {
    if (!autorzy.length) {
        return `<section class="stat-blok"><h3>Najczęstsi autorzy</h3>
            <p class="pole-pusto">Brak autorów.</p></section>`;
    }
    const max = Number(autorzy[0].ile) || 1;
    return `
        <section class="stat-blok">
            <h3>Najczęstsi autorzy</h3>
            <ul class="stat-szeregi">
                ${autorzy.map((r) => pasekProporcji(r.autor, r.ile, max)).join('')}
            </ul>
        </section>`;
}

/** Chipy tagów; `cel` decyduje, do którego katalogu prowadzi klik. */
function blokTagow(tagi, cel) {
    if (!tagi.length) {
        return `<section class="stat-blok"><h3>Najczęstsze tagi</h3>
            <p class="pole-pusto">Brak tagów.</p></section>`;
    }
    return `
        <section class="stat-blok">
            <h3>Najczęstsze tagi</h3>
            <div class="chip-row stat-chipy">
                ${tagi.map((t) => `
                    <a class="chip" href="#/${cel === 'ebooki' ? 'ebooki' : 'katalog'}"
                        data-stat-tag="${esc(t.nazwa)}" data-stat-cel="${cel}"
                        title="Filtruj: ${esc(t.nazwa)}">#${esc(t.nazwa)} <em>${esc(t.ile)}</em></a>`).join('')}
            </div>
        </section>`;
}

function blokBrakow(braki, ile) {
    const razem = (braki.bez_okladki ?? 0) + (braki.bez_oceny ?? 0) + (braki.bez_opisu ?? 0);
    if (razem === 0) {
        return '';
    }
    return `
        <section class="stat-blok stat-blok--do-uzupelnienia">
            <h3>Czeka na uzupełnienie</h3>
            <ul class="stat-szeregi">
                ${braki.bez_okladki ? pasekProporcji('Bez okładki', braki.bez_okladki, ile) : ''}
                ${braki.bez_oceny ? pasekProporcji('Bez oceny', braki.bez_oceny, ile) : ''}
                ${braki.bez_opisu ? pasekProporcji('Bez opisu', braki.bez_opisu, ile) : ''}
            </ul>
        </section>`;
}

/** Rozkład formatów plików — cecha wyłącznie ebooków. */
function blokFormatow(formaty) {
    if (!formaty.length) {
        return `<section class="stat-blok"><h3>Formaty</h3>
            <p class="pole-pusto">Brak plików.</p></section>`;
    }
    const max = Math.max(...formaty.map((f) => Number(f.ile) || 0), 1);
    return `
        <section class="stat-blok">
            <h3>Formaty</h3>
            <ul class="stat-szeregi">
                ${formaty.map((f) => pasekProporcji(
                    FORMATY_EBOOK[f.format] ?? f.format, f.ile, max)).join('')}
            </ul>
        </section>`;
}

/* ============================================================ sekcje */

function sekcjaPapier(p) {
    const s = p.suma;
    return `
        <section class="stat-blok">
            <h3>Papier</h3>
            ${s.ksiazki === 0 ? '<p class="pole-pusto">Nie ma jeszcze książek papierowych.</p>' : `
                <section class="stat-siatka">
                    ${kafelka('Książek', s.ksiazki, s.autorow > 0 ? `autorów: ${s.autorow}` : '')}
                    ${kafelka('Stron łącznie', skroc(s.stron),
                        s.z_stronami > 0 ? `w ${s.z_stronami} egzemplarzach` : '')}
                    ${kafelka('Średnia ocena', dziesietne(s.srednia_ocena),
                        s.ocenionych > 0 ? `z ${s.ocenionych} ocenionych` : 'brak ocen')}
                </section>

                <div class="stat-kolumny">
                    ${blokCzytanie(p.statusy, s, [
                        p.wypozyczenia.razem > 0
                            ? `Wypożyczone teraz: ${p.wypozyczenia.aktywne} z ${p.wypozyczenia.razem}` : '',
                    ])}
                    ${blokLat(p.lata)}
                    ${blokAutorow(p.autorzy)}
                    ${blokTagow(p.tagi, 'papier')}
                    ${blokBrakow(p.braki, s.ksiazki)}
                </div>`}
        </section>`;
}

function sekcjaEbooki(e) {
    const s = e.suma;
    return `
        <section class="stat-blok">
            <h3>Ebooki</h3>
            ${s.razem === 0 ? '<p class="pole-pusto">Nie ma jeszcze ebooków.</p>' : `
                <section class="stat-siatka">
                    ${kafelka('Ebooków', s.razem, s.autorow > 0 ? `autorów: ${s.autorow}` : '')}
                    ${kafelka('Stron łącznie', skroc(s.stron),
                        s.z_stronami > 0 ? `w ${s.z_stronami} plikach` : '')}
                    ${kafelka('Średnia ocena', dziesietne(s.srednia_ocena),
                        s.ocenionych > 0 ? `z ${s.ocenionych} ocenionych` : 'brak ocen')}
                </section>

                <div class="stat-kolumny">
                    ${blokCzytanie(e.statusy, s, [
                        `Z okładką: ${s.z_okladka} z ${s.razem}`,
                    ])}
                    ${blokFormatow(e.formaty)}
                    ${blokLat(e.lata)}
                    ${blokAutorow(e.autorzy)}
                    ${blokTagow(e.tagi, 'ebooki')}
                    ${blokBrakow(e.braki, s.razem)}
                </div>`}
        </section>`;
}

/* ============================================================= widok */

export async function statystyki(el) {
    el.innerHTML = `${naglowek(powrotKatalog())}
        <main class="tresc tresc-szeroka"><p class="ladowanie">Liczę…</p></main>`;

    let d;
    try {
        d = await api('stats.php');
    } catch (err) {
        document.querySelector('.tresc').innerHTML =
            `<p class="pusty">Nie udało się policzyć statystyk — ${esc(err.message)}</p>`;
        return;
    }
    if (d === null) {
        return;
    }

    const papier = d.papier;
    const ebooki = d.ebooki;

    // Pusto w obu katalogach — nie ma czego rozdzielać na sekcje.
    if (papier.suma.ksiazki === 0 && ebooki.suma.razem === 0) {
        document.querySelector('.tresc').innerHTML = `
            <div class="pusty">
                <p>Nie ma jeszcze książek ani ebooków, więc nie ma czego liczyć.</p>
                <a class="przycisk" href="#/dodaj">Dodaj pierwszą książkę</a>
            </div>`;
        return;
    }

    document.querySelector('.tresc').innerHTML = `
        <div class="statystyki">
            <h2>Statystyki</h2>
            <div class="stat-kolumny">
                ${sekcjaPapier(papier)}
                ${sekcjaEbooki(ebooki)}
            </div>
        </div>`;

    // Klik w tag ze statystyk → filtr właściwego katalogu (tak jak chip na karcie)
    document.querySelectorAll('[data-stat-tag]').forEach((chip) => {
        chip.addEventListener('click', (ev) => {
            ev.preventDefault();
            filtrujTagiem(chip.dataset.statTag, chip.dataset.statCel);
        });
    });
}

/** Zapisuje filtr tagu w stanie widoku i przechodzi do listy. */
function filtrujTagiem(tag, cel) {
    const ebook = cel === 'ebooki';
    const klucz = ebook ? 'bib.ebooki' : 'bib.ustawienia';
    let zapis = {};
    try {
        zapis = JSON.parse(localStorage.getItem(klucz) ?? '{}') ?? {};
    } catch { /* uszkodzony zapis — lecimy na domyślnych */ }
    // Stan ebooków trzyma też sort i stronę — dokładamy tylko tag i wracamy na pierwszą.
    localStorage.setItem(klucz, JSON.stringify({
        ...zapis,
        filtry: { ...(zapis.filtry ?? {}), tag },
        ...(ebook ? { strona: 1 } : {}),
    }));
    location.hash = ebook ? '#/ebooki' : '#/katalog';
}
