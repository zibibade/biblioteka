/* Biblioteka — szczegóły książki, formularz dodawania (z lookupem ISBN
   i podglądem metryczki przed zapisem) i edycji. */

import { api } from './api.js';
import {
    esc, ikona, okladkaHTML, naglowek, powrotKatalog, potwierdz, opcje, komunikat, wybieracz,
    poleTagow, polePolk, polkiZaznaczone, tagiHTML, polkaBadge, badgeWypozyczona,
    panelWypozyczenia, oknoWypozyczenia, segmentyFormat, segmentyPodlacz, gwiazdki, gwiazdkiPodlacz,
    formatEtykieta, szerokoscEtykieta, FORMATY, SZEROKOSCI, STATUSY,
} from './ui.js';
import { skanujKod } from './skaner.js';
import { slownikTagi, slownikPolki, cacheWyczysc } from './katalog.js';

/** Czytelne nazwy źródeł metryczki („bn+google” → „Biblioteka Narodowa + Google Books”). */
const NAZWY_ZRODEL = {
    bn: 'Biblioteka Narodowa', bnk: 'Katalog Biblioteki Narodowej',
    google: 'Google Books', ol: 'Open Library',
    recznie: 'ręcznie', brak: 'brak',
};
function nazwaZrodla(z) {
    return String(z ?? '').split('+')
        .map((t) => NAZWY_ZRODEL[t] ?? t).join(' + ') || String(z ?? '');
}

/** Pełny rekord do save.php (zmiany dopisane na końcu). */
function payload(k, zmiany = {}) {
    return {
        id: k.id,
        isbn: k.isbn, tytul: k.tytul, podtytul: k.podtytul, autor: k.autor,
        rok: k.rok, wydawca: k.wydawca, liczba_stron: k.liczba_stron,
        jezyk: k.jezyk, oryginalny_tytul: k.oryginalny_tytul, opis: k.opis,
        okladka: k.okladka, zdjecie: k.zdjecie, status: k.status,
        notatki: k.notatki, zrodlo: k.zrodlo,
        // Relacje wiele-do-wielu — z formularza czytamy z kontrolek
        tagi: (k.tagi ?? []).map((t) => (typeof t === 'string' ? t : t.nazwa)),
        polki: (k.polki ?? []).map((p) => (typeof p === 'string' ? p : p.id)),
        ...zmiany,
    };
}

/* ==================================================== szczegóły książki */

export async function szczegoly(el, id) {
    el.innerHTML = `${naglowek(powrotKatalog())}
        <main class="tresc"><p class="ladowanie">Wczytywanie…</p></main>`;

    let ksiazka;
    try {
        const d = await api('get.php?id=' + id);
        if (d === null) {
            return; // 401 → event sesji
        }
        ksiazka = d.ksiazka;
    } catch (err) {
        komunikat(err.message, 'blad');
        location.hash = '#/katalog';
        return;
    }

    const metryka = [
        ['ISBN', ksiazka.isbn], ['Rok', ksiazka.rok],
        ['Wydawca', ksiazka.wydawca], ['Stron', ksiazka.liczba_stron],
        ['Format', formatEtykieta(ksiazka.format)],
        ['Szerokość', szerokoscEtykieta(ksiazka.szerokosc)],
        ['Język', ksiazka.jezyk], ['Tytuł oryginalny', ksiazka.oryginalny_tytul],
        ['Podtytuł', ksiazka.podtytul],
        ['Źródło metryczki', nazwaZrodla(ksiazka.zrodlo)],
        ['Dodano', ksiazka.data_dodania],
    ].filter(([, v]) => v !== null && v !== '' && v !== undefined);

    el.innerHTML = `
        ${naglowek(`${powrotKatalog()}
            <a class="przycisk-pusty" href="#/dodaj/${id}">${ikona('plus')} Dodaj książkę</a>
            <a class="przycisk-pusty" href="#/ksiazka/${id}/edytuj">Edytuj</a>
            <button class="przycisk-niebezpieczny" id="usun">Usuń</button>`)}
        <main class="detal">
            <div class="detal-okladka">${okladkaHTML(ksiazka)}</div>
            <div class="detal-info">
                <h2>${esc(ksiazka.tytul)}</h2>
                <p class="autor">${esc(ksiazka.autor || 'Autor nieznany')}</p>
                <label class="pole">
                    <span>Status czytania</span>
                    <span class="wybieracz" id="status"></span>
                </label>
                <div class="pole">
                    <span>Ocena własna</span>
                    ${gwiazdki('ocena', ksiazka.ocena)}
                </div>
                <dl class="detal-metryczka">
                    ${metryka.map(([k, v]) => `<dt>${esc(k)}</dt><dd>${esc(v)}</dd>`).join('')}
                </dl>
                ${(ksiazka.tagi ?? []).length || (ksiazka.polki ?? []).length ? `
                <div class="detal-etykiety">
                    ${tagiHTML(ksiazka.tagi)}
                    <div class="chip-row">${(ksiazka.polki ?? []).map(polkaBadge).join('')}</div>
                </div>` : ''}
                ${panelWypozyczenia(ksiazka)}
                ${ksiazka.opis ? `<div class="detal-opis">${esc(ksiazka.opis)}</div>` : ''}
                <label class="pole">
                    <span>Notatki</span>
                    <textarea id="notatki" placeholder="Twoje przemyślenia, stan, gdzie kupione…">${esc(ksiazka.notatki ?? '')}</textarea>
                </label>
                <div class="detal-akcje">
                    <button class="przycisk-pusty" id="zapisz-notatki">Zapisz notatki</button>
                </div>
                <div class="detal-zdjecie">
                    <span class="etykieta">Zdjęcie egzemplarza</span>
                    ${ksiazka.zdjecie
                        ? `<img class="mini-zdjecie" src="${esc(ksiazka.zdjecie)}" alt="Zdjęcie egzemplarza">
                           <button class="przycisk-niebezpieczny" id="usun-zdjecie">Usuń zdjęcie</button>`
                        : `<span class="status-lookup">Brak — zrób zdjęcie aparatem.</span>`}
                    <button type="button" class="przycisk-pusty" id="foto-btn">${ikona('aparat')} Dodaj zdjęcie</button>
                    <input type="file" id="foto" hidden accept="image/jpeg,image/png,image/webp" capture="environment">
                </div>
            </div>
        </main>`;

    // Zmiana statusu — własny dropdown, natychmiastowy zapis pełnego rekordu
    wybieracz('status', Object.entries(STATUSY), ksiazka.status, async (w) => {
        try {
            const r = await api('save.php', payload(ksiazka, { status: w }));
            if (r?.ok) {
                ksiazka.status = w;
                komunikat('Status zapisany', 'ok');
            }
        } catch (err) {
            komunikat(err.message, 'blad');
        }
    });

    // Ocena — klik w gwiazdkę zapisuje od razu (jak status). Ten sam gwiazdek
    // drugi raz czyści ocenę; koniec listy gwiazdek to „Usuń ocenę".
    const gwiazdkiEl = document.getElementById('ocena');
    if (gwiazdkiEl) {
        gwiazdkiPodlacz(gwiazdkiEl, async (ocena) => {
            try {
                const r = await api('save.php', payload(ksiazka, { ocena }));
                if (r?.ok) {
                    ksiazka.ocena = ocena;
                    gwiazdkiEl.querySelector('.gwiazdka--wyczysc')?.remove();
                    komunikat(ocena ? `Ocena: ${ocena}/5` : 'Ocena usunięta', 'ok');
                }
            } catch (err) {
                komunikat(err.message, 'blad');
            }
        });
    }

    // Wypożyczenie — akcje panelu (delegacja: panel przerysowujemy po każdej zmianie)
    const panelWyp = document.querySelector('.panel-wypozyczenia');
    if (panelWyp) {
        panelWyp.addEventListener('click', async (e) => {
            const usunId = e.target.closest('[data-usun-wyp]')?.dataset.usunWyp;
            if (usunId) {
                const ok = await potwierdz(
                    'Usunąć wpis z historii?',
                    'Wpis zniknie z historii wypożyczeń tej książki. Sama książka zostaje.',
                    'Usuń wpis'
                );
                if (!ok) {
                    return;
                }
                try {
                    await api('wypozyczenia.php', { akcja: 'usun', id: Number(usunId) });
                    komunikat('Wpis usunięty', 'ok');
                    await szczegoly(el, id);
                } catch (err) {
                    komunikat(err.message, 'blad');
                }
                return;
            }

            if (e.target.closest('#wypozycz')) {
                const dane = await oknoWypozyczenia(ksiazka);
                if (!dane?.osoba?.trim()) {
                    return;
                }
                try {
                    await api('wypozyczenia.php', {
                        akcja: 'wypozycz',
                        ksiazka_id: Number(id),
                        osoba: dane.osoba,
                        uwagi: dane.uwagi,
                    });
                    komunikat(`Wypożyczono: ${dane.osoba.trim()}`, 'ok');
                    await szczegoly(el, id);
                } catch (err) {
                    komunikat(err.message, 'blad');
                }
                return;
            }

            if (e.target.closest('#zwroc')) {
                try {
                    await api('wypozyczenia.php', {
                        akcja: 'zwroc',
                        id: ksiazka.wypozyczona.id,
                    });
                    komunikat('Zaznaczono jako zwróconą', 'ok');
                    await szczegoly(el, id);
                } catch (err) {
                    komunikat(err.message, 'blad');
                }
            }
        });
    }

    // Notatki — zapis po kliknięciu
    document.getElementById('zapisz-notatki').addEventListener('click', async () => {
        try {
            const r = await api('save.php', payload(ksiazka, {
                notatki: document.getElementById('notatki').value,
            }));
            if (r?.ok) {
                ksiazka.notatki = document.getElementById('notatki').value;
                komunikat('Notatki zapisane', 'ok');
            }
        } catch (err) {
            komunikat(err.message, 'blad');
        }
    });

    // Zdjęcie egzemplarza — upload FormData (aparat z capture=environment)
    const wyslijZdjecie = async (fd, opis) => {
        const odp = await fetch('api/photo.php', { method: 'POST', body: fd, credentials: 'same-origin' });
        const j = await odp.json().catch(() => null);
        if (odp.status === 401) {
            window.dispatchEvent(new Event('sesja-wygasla'));
            return;
        }
        if (!odp.ok || !j?.ok) {
            throw new Error(j?.blad ?? 'Błąd wysyłania zdjęcia.');
        }
        komunikat(opis, 'ok');
        await szczegoly(el, id);      // przerysowanie widoku ze zdjęciem
    };

    // Własny przycisk zamiast systemowego inputa „Choose File"
    document.getElementById('foto-btn').addEventListener('click', () => {
        document.getElementById('foto').click();
    });
    document.getElementById('foto').addEventListener('change', async (e) => {
        const plik = e.target.files?.[0];
        if (!plik) {
            return;
        }
        const fd = new FormData();
        fd.append('id', String(id));
        fd.append('plik', plik);
        try {
            await wyslijZdjecie(fd, 'Zdjęcie zapisane');
        } catch (err) {
            komunikat(err.message, 'blad');
        }
    });

    const btnUsunZdjecie = document.getElementById('usun-zdjecie');
    if (btnUsunZdjecie) {
        btnUsunZdjecie.addEventListener('click', async () => {
            const ok = await potwierdz(
                'Usunąć zdjęcie?',
                'Zdjęcie tego egzemplarza zostanie skasowane na stałe.',
                'Usuń zdjęcie'
            );
            if (!ok) {
                return;
            }
            const fd = new FormData();
            fd.append('id', String(id));
            fd.append('usun', '1');
            try {
                await wyslijZdjecie(fd, 'Zdjęcie usunięte');
            } catch (err) {
                komunikat(err.message, 'blad');
            }
        });
    }

    // Usuwanie — trwałe, z potwierdzeniem (bez kosza)
    document.getElementById('usun').addEventListener('click', async () => {
        const ok = await potwierdz(
            'Usunąć książkę?',
            'Rekord zostanie usunięty trwale — bez kosza. W razie pomyłki dodasz ją ponownie.',
            'Usuń trwale'
        );
        if (!ok) {
            return;
        }
        try {
            await api('delete.php', { id });
            komunikat('Książka usunięta', 'ok');
            location.hash = '#/katalog';
        } catch (err) {
            komunikat(err.message, 'blad');
        }
    });
}

/* ====================================== formularz: dodawanie i edycja */

export async function formularz(el, id = null, opcje = {}) {
    // `kopiujZ` = dodajemy DRUGI egzemplarz istniejącej książki: formularz
    // wypełnia się jej metryką, ale zapis idzie jako nowy wpis (id = null).
    const kopiujZ = opcje.kopiujZ ?? null;
    let wiersz = null;
    if (id !== null || kopiujZ !== null) {
        try {
            const d = await api('get.php?id=' + (id ?? kopiujZ));
            if (d === null) {
                return;
            }
            wiersz = d.ksiazka;
        } catch (err) {
            komunikat(err.message, 'blad');
            location.hash = '#/katalog';
            return;
        }
    }

    // Słowniki do podpowiedzi tagów i checkboxów półek (z cache modułu katalogu)
    const [slownikiTagow, slownikiPolk] = await Promise.all([slownikTagi(), slownikPolki()]);

    const v = (p) => esc(wiersz?.[p] ?? '');
    let zrodlo = wiersz?.zrodlo ?? 'recznie';
    let okladka = wiersz?.okladka ?? null;

    el.innerHTML = `
        ${naglowek(powrotKatalog())}
        <main class="forma">
            <h2>${id !== null ? 'Edytuj książkę' : 'Dodaj książkę'}</h2>

            ${id === null ? `
            <div class="pole-wiersz pole-isbn">
                <label class="pole">
                    <span>ISBN (zeskanuj lub wpisz — metryczka uzupełni się sama)</span>
                    <input id="f-isbn-lookup" placeholder="9788301123456" inputmode="numeric">
                </label>
                <button class="przycisk-pusty" id="skanuj" title="Skanuj kod kreskowy">${ikona('aparat')} Skanuj</button>
                <button class="przycisk" id="szukaj">${ikona('szukaj')} Szukaj</button>
            </div>
            <p class="status-lookup" id="status-lookup"></p>` : ''}

            ${kopiujZ !== null ? `<p class="status-lookup">Metryka skopiowana
               z „${esc(wiersz?.tytul ?? '')}”. Popraw, co trzeba, i zapisz —
               powstanie drugi egzemplarz.</p>` : ''}

            <!-- Górne miejsce na akcje. Puste (selektor .slot-akcji:empty),
                 więc do udanego odczytu nie zostawia odstępu w flex-column.
                 Do 0.26.0 przycisk zapisu był wyłącznie na dole, a skan to
                 najczęstszy scenariusz — trzeba było przescrollować całą
                 metrykę. Po odczycie blok przenosimy w to miejsce: jeden
                 przycisk, bez duplikatu zapraszającego do podwójnego
                 zapisania. -->
            <div class="slot-akcji" id="slot-akcji-gora"></div>

            <label class="pole" for="f-tytul">
                <span>Tytuł *</span>
                <input id="f-tytul" value="${v('tytul')}" required>
            </label>
            <div class="siatka-pol">
                <label class="pole"><span>Autor</span>
                    <input id="f-autor" value="${v('autor')}"></label>
                <label class="pole"><span>Podtytuł</span>
                    <input id="f-podtytul" value="${v('podtytul')}"></label>
                <label class="pole"><span>ISBN</span>
                    <input id="f-isbn" value="${v('isbn')}" inputmode="numeric"></label>
                <label class="pole"><span>Rok</span>
                    <input id="f-rok" value="${v('rok')}" inputmode="numeric"></label>
                <label class="pole"><span>Wydawca</span>
                    <input id="f-wydawca" value="${v('wydawca')}"></label>
                <label class="pole"><span>Liczba stron</span>
                    <input id="f-liczba_stron" value="${v('liczba_stron')}" inputmode="numeric"></label>
                <div class="pole">
                    <span>Format <em>(egzemplarz)</em></span>
                    ${segmentyFormat('f-format', wiersz?.format ?? '', FORMATY)}
                </div>
                <div class="pole">
                    <span>Szerokość <em>(egzemplarz)</em></span>
                    ${segmentyFormat('f-szerokosc', wiersz?.szerokosc ?? '', SZEROKOSCI)}
                </div>
                <label class="pole"><span>Język</span>
                    <input id="f-jezyk" value="${v('jezyk')}"></label>
                <label class="pole"><span>Tytuł oryginalny</span>
                    <input id="f-oryginalny_tytul" value="${v('oryginalny_tytul')}"></label>
                <label class="pole"><span>Status czytania</span>
                    <span class="wybieracz" id="f-status"></span>
                </label>
            </div>
            <label class="pole"><span>Opis (z bazy)</span>
                <textarea id="f-opis">${v('opis')}</textarea></label>
            <label class="pole"><span>Notatki</span>
                <textarea id="f-notatki">${v('notatki')}</textarea></label>
            <div class="pole">
                <span>Ocena własna</span>
                ${gwiazdki('f-ocena', wiersz?.ocena)}
            </div>
            ${poleTagow('f-tag', (wiersz?.tagi ?? []).join(', '), slownikiTagow.map((t) => t.nazwa))}
            ${polePolk(slownikiPolk, (wiersz?.polki ?? []).map((p) => p.id))}

            <div id="slot-akcji-dol"></div>
        </main>`;

    // Blok akcji tworzymy poza <main> i wstawiamy w zależności od sytuacji:
    // na dole (ręczne wpisywanie) albo — po udanym odczycie — na górze.
    const akcje = document.createElement('div');
    akcje.className = 'forma-akcje';
    akcje.innerHTML = `
        <a class="przycisk-pusty" href="${id !== null ? `#/ksiazka/${id}` : '#/katalog'}">Anuluj</a>
        <button class="przycisk" id="zapisz">${id !== null ? 'Zapisz zmiany' : 'Zapisz w katalogu'}</button>`;
    el.querySelector('#slot-akcji-dol').appendChild(akcje);

    wybieracz('f-status', Object.entries(STATUSY), wiersz?.status ?? 'chce_przeczytac');
    // Format i szerokość egzemplarza — przyciski-segmenty (nie dropdown)
    segmentyPodlacz(document.getElementById('f-format'));
    segmentyPodlacz(document.getElementById('f-szerokosc'));
    gwiazdkiPodlacz(document.getElementById('f-ocena'));

    if (id === null) {
        document.getElementById('szukaj').addEventListener('click', () => szukajISBN(
            {
                isbn: document.getElementById('f-isbn-lookup'),
                status: document.getElementById('status-lookup'),
                przycisk: document.getElementById('szukaj'),
            },
            (dane) => {
                // Podgląd metryczki: wypełniamy formularz, NIE zapisujemy
                const map = {
                    'f-isbn': dane.isbn, 'f-tytul': dane.tytul, 'f-podtytul': dane.podtytul,
                    'f-autor': dane.autor, 'f-rok': dane.rok, 'f-wydawca': dane.wydawca,
                    'f-liczba_stron': dane.liczba_stron, 'f-jezyk': dane.jezyk,
                    'f-oryginalny_tytul': dane.oryginalny_tytul, 'f-opis': dane.opis,
                };
                for (const [pole, wartosc] of Object.entries(map)) {
                    const elPole = document.getElementById(pole);
                    if (elPole && wartosc !== null && wartosc !== undefined && wartosc !== '') {
                        elPole.value = String(wartosc);
                    }
                }
                okladka = dane.okladka ?? null;
                zrodlo = dane.zrodlo ?? 'recznie';
                // Książka znaleziona — blok akcji wjeżdża na górę. Bez tego
                // użytkownik musiał przescrollować cały formularz, żeby zapisać
                // (zgłoszenie właściciela, 2026-10-04). Przenosimy węzeł, nie
                // kopiujemy przycisku: dwa „Zapisz w katalogu” na ekranie to
                // zaproszenie do podwójnego zapisania.
                // Stan „akcje na górze” wynika z tego, że slot nie jest
                // pusty — żadnej dodatkowej klasy w JS, którą trzeba by
                // pamiętać i zsynchronizować (id i klasa miały tę samą
                // nazwę, co zapraszało pomyłkę przy kopiowaniu).
                const slot = document.getElementById('slot-akcji-gora');
                if (slot && !slot.contains(akcje)) {
                    slot.appendChild(akcje);
                }
            }
        ));

        // Skanowanie: kamera → ISBN → automatyczny lookup → podgląd metryczki
        document.getElementById('skanuj').addEventListener('click', () => {
            skanujKod((isbn) => {
                document.getElementById('f-isbn-lookup').value = isbn;
                document.getElementById('szukaj').click();
            });
        });
    }

    document.getElementById('zapisz').addEventListener('click', async () => {
        const dane = {
            id: id ?? undefined,
            isbn: document.getElementById('f-isbn').value,
            tytul: document.getElementById('f-tytul').value,
            podtytul: document.getElementById('f-podtytul').value,
            autor: document.getElementById('f-autor').value,
            rok: document.getElementById('f-rok').value,
            wydawca: document.getElementById('f-wydawca').value,
            liczba_stron: document.getElementById('f-liczba_stron').value,
            format: document.getElementById('f-format').dataset.wartosc || null,
            szerokosc: document.getElementById('f-szerokosc').dataset.wartosc || null,
            ocena: document.getElementById('f-ocena').dataset.wartosc || null,
            jezyk: document.getElementById('f-jezyk').value,
            oryginalny_tytul: document.getElementById('f-oryginalny_tytul').value,
            opis: document.getElementById('f-opis').value,
            notatki: document.getElementById('f-notatki').value,
            status: document.getElementById('f-status').dataset.wartosc,
            okladka: okladka,
            zrodlo: zrodlo,
            tagi: document.getElementById('f-tag').value,
            polki: polkiZaznaczone(el),
        };
        wyczyscBledy(el);   // zdejmij oznaczenia z poprzedniej próby
        try {
            const r = await api('save.php', dane);
            if (r?.ok) {
                wyczyscBledy(el);
                cacheWyczysc();   // słowniki tagów/półek mogły się zmienić
                komunikat(id !== null ? 'Zmiany zapisane' : 'Książka dodana', 'ok');
                location.hash = `#/ksiazka/${r.id}`;
            }
        } catch (err) {
            komunikat(err.message, 'blad');
            pokazBledy(el, MAPA_BLEDOW_KSIAZKI, err.message);
        }
    });
}

/* --------------------------------------------------- znaczniki walidacji */

/** Słowa z komunikatu serwera → kontrolka, którą trzeba poprawić. */
const MAPA_BLEDOW_KSIAZKI = [
    ['tytuł', 'f-tytul'], ['tytul', 'f-tytul'],
    ['autor', 'f-autor'],
    ['isbn', 'f-isbn'],
    ['rok', 'f-rok'],
    ['liczba stron', 'f-liczba_stron'],
    ['ocena', 'f-ocena'],
];

/** Znaczniki błędu po poprzedniej próbie zapisu — zdejmowane przed nową. */
function wyczyscBledy(korzen) {
    korzen.querySelectorAll('[aria-invalid="true"]').forEach((pole) => {
        pole.removeAttribute('aria-invalid');
        pole.classList.remove('pole--blad');
    });
}

/**
 * Serwer zwraca 422 z tekstem („Tytuł jest wymagany”), a nie z nazwą pola,
 * więc dopasowujemy słowo z komunikatu do kontrolki. Fokus przenosimy na
 * pierwsze błędne pole: na telefonie toast na dole bywa poza kadrem i bez
 * tego użytkownik nie wie, gdzie poprawić.
 */
function pokazBledy(korzen, mapa, tekst) {
    const tresc = String(tekst || '').toLowerCase();
    const pola = [];
    for (const [slowo, idPola] of mapa) {
        if (tresc.includes(slowo) && !pola.some((p) => p.id === idPola)) {
            const pole = document.getElementById(idPola);
            if (pole) {
                pole.setAttribute('aria-invalid', 'true');
                pole.classList.add('pole--blad');
                pola.push(pole);
            }
        }
    }
    if (pola.length === 0) {
        const pole = document.getElementById('f-tytul');
        if (pole) {
            pole.setAttribute('aria-invalid', 'true');
            pole.classList.add('pole--blad');
            pola.push(pole);
        }
    }
    const pierwsze = pola[0];
    if (pierwsze) {
        pierwsze.focus();
        if (typeof pierwsze.select === 'function') {
            pierwsze.select();
        }
    }
    return pola;
}

/** Lookup ISBN → wypełnienie formularza + ostrzeżenie o duplikacie. */
async function szukajISBN(kontrolki, wypelnij) {
    const isbn = kontrolki.isbn.value.replace(/[\s-]/g, '').toUpperCase();
    if (!isbn) {
        komunikat('Wpisz ISBN', 'blad');
        return;
    }
    kontrolki.przycisk.disabled = true;
    kontrolki.status.textContent = 'Szukam w bazach…';
    try {
        const r = await api('lookup.php?isbn=' + encodeURIComponent(isbn));
        if (r === null) {
            return;
        }
        if (r.dane === null) {
            // Brak w bazach to **luka w konkretnym wydaniu**, nie awaria
            // aplikacji: sprawdziliśmy BN (stare API i nowy katalog) oraz
            // Open Library. Biblioteka Narodowa ma wiele książek, ale pod
            // innym ISBN — bo każde nowe wydanie/nakład dostaje swój numer.
            // (Zmierzone na 41 bestsellerach rynkowych: 39 trafionych, 2 —
            //  tytuły w BN, lecz pod ISBN innych wydań.)
            kontrolki.status.textContent =
                'Tej książki nie ma w bazach — sprawdziliśmy Bibliotekę Narodową '
                + '(stary i nowy katalog) oraz Open Library. Zwykle BN ma ją pod '
                + 'numerem innego wydania. Uzupełnij metryczkę ręcznie.';
            return;
        }
        wypelnij(r.dane);
        kontrolki.status.textContent = `Uzupełniono ze źródła: ${nazwaZrodla(r.zrodlo)}${r.z_cache ? ' (z cache)' : ''}. Sprawdź i zapisz.`;

        // Ostrzeżenie o duplikacie: „masz już N egzemplarzy" (zezwalamy)
        const istniejace = await api('list.php?q=' + encodeURIComponent(isbn));
        const sztuk = (istniejace?.ksiazki ?? []).filter((k) => k.isbn === isbn).length;
        if (sztuk > 0) {
            await potwierdz(
                'Ta książka jest już w katalogu',
                `W katalogu ${sztuk === 1 ? 'jest 1 egzemplarz' : `są ${sztuk} egzemplarze`} tego wydania. Dodać kolejny?`,
                'Dodaj egzemplarz'
            );
            // Niezależnie od potwierdzenia formularz zostaje wypełniony —
            // decyzja o zapisie i tak należy do kliknięcia „Zapisz".
        }
    } catch (err) {
        kontrolki.status.textContent = '';
        komunikat(err.message, 'blad');
    } finally {
        kontrolki.przycisk.disabled = false;
    }
}

/* ==================================================== formularz ebooka */

/** Formaty plików katalogu ebooków — kolejność od najczęstszego. */
const FORMATY_EBOOK = {
    epub: 'EPUB', pdf: 'PDF', mobi: 'MOBI', azw3: 'AZW3',
    fb2: 'FB2', cbz: 'CBZ', inne: 'Inne',
};

/** Słowa z komunikatu 422 → kontrolka ebooka (format i powiązanie mają własne). */
const MAPA_BLEDOW_EBOOKA = [
    ['tytuł', 'f-tytul'], ['tytul', 'f-tytul'],
    ['autor', 'f-autor'],
    ['isbn', 'f-isbn'],
    ['rok', 'f-rok'],
    ['liczba stron', 'f-liczba_stron'],
    ['ocena', 'f-ocena'],
    ['format', 'f-format'],
    ['status', 'f-status'],
    ['tag', 'f-tag'],
    ['powiązan', 'f-powiazana-szukaj'],
];

function powrotEbooki() {
    return `<a class="przycisk-pusty powrot-lewy" href="#/ebooki">${ikona('strzalkaLewo')} Ebooki</a>`;
}

/**
 * Formularz ebooka: te same metadane co książka papierowa plus format pliku
 * i powiązanie z egzemplarzem papierowym. Plik ebooka nigdzie nie jedzie —
 * katalogujemy wyłącznie opis.
 */
export async function widokEbookFormularz(korzen, id = null) {
    korzen.innerHTML = `${naglowek(powrotEbooki())}
        <main class="forma"><p class="ladowanie">Wczytywanie…</p></main>`;

    let ebook = null;
    let powiazana = null;
    if (id !== null) {
        try {
            const d = await api('ebook.php?id=' + id);
            if (d === null) {
                return;      // 401 → event sesji pokaże logowanie
            }
            ebook = d.ebook ?? null;
            powiazana = d.powiazana ?? null;
        } catch (err) {
            komunikat(err.message, 'blad');
            location.hash = '#/ebooki';
            return;
        }
    }
    if (id !== null && ebook === null) {
        // Rekord usunięty w innej karcie — nie ma czego edytować.
        komunikat('Nie ma takiego ebooka', 'blad');
        location.hash = '#/ebooki';
        return;
    }

    // Słowniki tagów i półek są wspólne z katalogiem papierowym (te same
    // tabele) — bierzemy je z cache modułu katalogu zamiast pytać drugi raz.
    const [slownikiTagow, slownikiPolk] = await Promise.all([slownikTagi(), slownikPolki()]);

    const v = (p) => esc(ebook?.[p] ?? '');
    const nazwyTagow = (ebook?.tagi ?? []).map((t) => (typeof t === 'string' ? t : t.nazwa));
    const idPolk = (ebook?.polki ?? []).map((p) => p.id);

    korzen.innerHTML = `
        ${naglowek(`${powrotEbooki()}
            ${id !== null ? '<button class="przycisk-niebezpieczny" id="usun-ebook">Usuń</button>' : ''}`)}
        <main class="forma">
            <h2>${id !== null ? 'Edytuj ebooka' : 'Dodaj ebooka'}</h2>

            <label class="pole" for="f-tytul">
                <span>Tytuł *</span>
                <input id="f-tytul" value="${v('tytul')}" required>
            </label>
            <div class="siatka-pol">
                <label class="pole"><span>Autor</span>
                    <input id="f-autor" value="${v('autor')}"></label>
                <label class="pole"><span>Podtytuł</span>
                    <input id="f-podtytul" value="${v('podtytul')}"></label>
                <label class="pole"><span>ISBN</span>
                    <input id="f-isbn" value="${v('isbn')}" inputmode="numeric"></label>
                <label class="pole"><span>Rok</span>
                    <input id="f-rok" value="${v('rok')}" inputmode="numeric"></label>
                <label class="pole"><span>Wydawca</span>
                    <input id="f-wydawca" value="${v('wydawca')}"></label>
                <label class="pole"><span>Liczba stron</span>
                    <input id="f-liczba_stron" value="${v('liczba_stron')}" inputmode="numeric"></label>
                <div class="pole">
                    <span>Format pliku</span>
                    <span class="wybieracz" id="f-format"></span>
                </div>
                <label class="pole"><span>Język</span>
                    <input id="f-jezyk" value="${v('jezyk')}"></label>
                <label class="pole"><span>Tytuł oryginalny</span>
                    <input id="f-oryginalny_tytul" value="${v('oryginalny_tytul')}"></label>
                <label class="pole"><span>Status czytania</span>
                    <span class="wybieracz" id="f-status"></span>
                </label>
            </div>
            <label class="pole"><span>Opis</span>
                <textarea id="f-opis">${v('opis')}</textarea></label>
            <label class="pole"><span>Notatki</span>
                <textarea id="f-notatki">${v('notatki')}</textarea></label>
            <div class="pole">
                <span>Ocena własna</span>
                ${gwiazdki('f-ocena', ebook?.ocena)}
            </div>

            <!-- Powiązanie z papierowym egzemplarzem wybieramy z katalogu:
                 przepisywanie id z pamięci kończyło się literówką. -->
            <div class="pole">
                <span>Powiązana książka papierowa</span>
                <div class="chip-row" id="f-powiazana-wybrana"></div>
                <input id="f-powiazana-szukaj" autocomplete="off"
                       placeholder="Szukaj w katalogu papierowym (tytuł, autor, ISBN)…"
                       aria-describedby="f-powiazana-lista">
                <div class="chip-row" id="f-powiazana-lista" role="listbox"
                     aria-label="Wyniki wyszukiwania książek papierowych"></div>
            </div>

            ${poleTagow('f-tag', nazwyTagow.join(', '), slownikiTagow.map((t) => t.nazwa))}
            ${polePolk(slownikiPolk, idPolk)}

            <div class="forma-akcje">
                <a class="przycisk-pusty" href="#/ebooki">Anuluj</a>
                <button class="przycisk" id="zapisz-ebook">${id !== null ? 'Zapisz zmiany' : 'Zapisz ebooka'}</button>
            </div>
        </main>`;

    wybieracz('f-status', Object.entries(STATUSY), ebook?.status ?? 'chce_przeczytac');
    wybieracz('f-format', Object.entries(FORMATY_EBOOK), ebook?.format ?? 'epub');
    gwiazdkiPodlacz(document.getElementById('f-ocena'));

    /* ------------------------------------------------- powiązana książka */

    // Chip jest przyciskiem w całości (wzorzec `.chip[role='button']`),
    // bo osobny „×” miałby na telefonie za mały cel dotyku.
    const wybrana = document.getElementById('f-powiazana-wybrana');
    const rysujPowiazana = () => {
        wybrana.innerHTML = powiazana === null ? '' : `
            <span class="chip" role="button" tabindex="0" data-odepnij
                  title="Odepnij książkę papierową"
                  aria-label="Odepnij: ${esc(powiazana.tytul)}">${esc(powiazana.tytul)} <span aria-hidden="true">×</span></span>`;
    };
    rysujPowiazana();

    const odepnij = () => {
        powiazana = null;
        rysujPowiazana();
    };
    wybrana.addEventListener('click', (e) => {
        if (e.target.closest('[data-odepnij]')) {
            odepnij();
        }
    });
    wybrana.addEventListener('keydown', (e) => {
        if ((e.key === 'Enter' || e.key === ' ') && e.target.closest('[data-odepnij]')) {
            e.preventDefault();
            odepnij();
        }
    });

    const szukajPole = document.getElementById('f-powiazana-szukaj');
    const lista = document.getElementById('f-powiazana-lista');
    let zegar = null;
    let licznik = 0;      // chroni przed odpowiedzią na nieaktualne zapytanie

    const pokazPodpowiedzi = (ksiazki) => {
        const widoczne = ksiazki
            .filter((k) => Number(k.id) !== powiazana?.id)
            .slice(0, 8);   // więcej na raz i tak nie zmieści się na telefonie
        lista.innerHTML = widoczne.map((k) => `
            <button type="button" class="chip" role="option" aria-selected="false"
                    data-id="${esc(k.id)}" data-tytul="${esc(k.tytul)}"
                    title="${esc(k.autor ?? '')}">${esc(k.tytul)}</button>`).join('');
    };

    szukajPole.addEventListener('input', () => {
        clearTimeout(zegar);
        const q = szukajPole.value.trim();
        if (q.length < 2) {
            lista.innerHTML = '';
            return;
        }
        // Debounce 300 ms: pisanie tytułu to jedno zapytanie, nie dziesięć.
        zegar = setTimeout(async () => {
            const moj = ++licznik;
            try {
                const r = await api('list.php?q=' + encodeURIComponent(q));
                if (moj !== licznik) {
                    return;  // w międzyczasie poszło nowsze zapytanie
                }
                pokazPodpowiedzi(r?.ksiazki ?? []);
            } catch (err) {
                komunikat(err.message, 'blad');
            }
        }, 300);
    });

    lista.addEventListener('click', (e) => {
        const przycisk = e.target.closest('[data-id]');
        if (!przycisk) {
            return;
        }
        powiazana = {
            id: Number(przycisk.dataset.id),
            tytul: przycisk.dataset.tytul,
        };
        rysujPowiazana();
        lista.innerHTML = '';
        szukajPole.value = '';
    });

    /* ------------------------------------------------------------ zapis */

    document.getElementById('zapisz-ebook').addEventListener('click', async () => {
        const dane = {
            id: id ?? undefined,
            isbn: document.getElementById('f-isbn').value,
            tytul: document.getElementById('f-tytul').value,
            podtytul: document.getElementById('f-podtytul').value,
            autor: document.getElementById('f-autor').value,
            rok: document.getElementById('f-rok').value,
            wydawca: document.getElementById('f-wydawca').value,
            liczba_stron: document.getElementById('f-liczba_stron').value,
            jezyk: document.getElementById('f-jezyk').value,
            oryginalny_tytul: document.getElementById('f-oryginalny_tytul').value,
            opis: document.getElementById('f-opis').value,
            notatki: document.getElementById('f-notatki').value,
            status: document.getElementById('f-status').dataset.wartosc,
            format: document.getElementById('f-format').dataset.wartosc,
            ocena: document.getElementById('f-ocena').dataset.wartosc || null,
            tagi: document.getElementById('f-tag').value,
            polki: polkiZaznaczone(korzen),
            powiazana_ksiazka_id: powiazana?.id ?? null,
        };
        wyczyscBledy(korzen);
        try {
            const r = await api('ebook-save.php', dane);
            if (r?.ok) {
                cacheWyczysc();   // tagi/półki mogły dojść
                komunikat(id !== null ? 'Zmiany zapisane' : 'Ebook dodany', 'ok');
                location.hash = '#/ebooki';
            }
        } catch (err) {
            komunikat(err.message, 'blad');
            pokazBledy(korzen, MAPA_BLEDOW_EBOOKA, err.message);
        }
    });

    /* ----------------------------------------------------------- usuwanie */

    const btnUsun = document.getElementById('usun-ebook');
    if (btnUsun) {
        btnUsun.addEventListener('click', async () => {
            const ok = await potwierdz(
                'Usunąć ebooka?',
                'Rekord zniknie trwale, razem z tagami i półkami. Plik na dysku zostaje — katalogujemy tylko metadane.',
                'Usuń trwale'
            );
            if (!ok) {
                return;
            }
            try {
                await api('ebook-delete.php', { id });
                komunikat('Ebook usunięty', 'ok');
                location.hash = '#/ebooki';
            } catch (err) {
                komunikat(err.message, 'blad');
            }
        });
    }
}
