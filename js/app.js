/* Biblioteka — start, logowanie, router (hash). */

import { api } from './api.js';
import { katalog, slownikPolki } from './katalog.js';
import { szczegoly, formularz } from './forma.js';
import { statystyki } from './statystyki.js';
import { esc, komunikat, wersjaUstaw } from './ui.js';

const app = document.getElementById('app');
let zalogowany = false;

/* ---------------------------------------------------------- logowanie */

function widokLogowania(blad = '') {
    app.innerHTML = `
        <div class="ekran-logowania">
            <div class="karta-logowania">
                <img src="assets/logo.svg" alt="Logo Biblioteki">
                <h1>Biblioteka</h1>
                <p>Katalog książek ze skanowaniem ISBN</p>
                <form id="form-logowania">
                    <label class="sr-only" for="pole-haslo">Hasło dostępu</label>
                    <input type="password" id="pole-haslo" placeholder="Hasło dostępu"
                           autocomplete="current-password" autofocus required>
                    <p class="blad" id="blad-logowania" role="alert">${esc(blad)}</p>
                    <button type="submit" class="przycisk">Zaloguj</button>
                </form>
            </div>
        </div>`;

    document.getElementById('form-logowania').addEventListener('submit', async (e) => {
        e.preventDefault();
        const haslo = document.getElementById('pole-haslo').value;
        const przycisk = e.target.querySelector('button');
        przycisk.disabled = true;
        try {
            // Własne fetch (nie api.js) — tu 401 to zła treść hasła, nie wygaśnięcie sesji
            const odp = await fetch('api/login.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                credentials: 'same-origin',
                body: JSON.stringify({ haslo }),
            });
            const json = await odp.json().catch(() => null);
            if (!odp.ok) {
                throw new Error(json?.blad ?? 'Błąd logowania');
            }
            zalogowany = true;
            wersjaUstaw(json.wersja ?? '');
            if (location.hash && location.hash !== '#/') {
                trasa();
            } else {
                location.hash = '#/katalog';
            }
        } catch (err) {
            document.getElementById('blad-logowania').textContent = err.message;
            const pole = document.getElementById('pole-haslo');
            pole.focus();
            pole.select();
            przycisk.disabled = false;
        }
    });
}

/* -------------------------------------------------------------- router */

async function trasa() {
    if (!zalogowany) {
        widokLogowania();
        return;
    }
    const h = location.hash || '#/';
    window.scrollTo(0, 0);

    if (h === '#/' || h === '#/katalog') {
        await katalog(app);
        return;
    }
    if (h === '#/dodaj') {
        await formularz(app, null);
        return;
    }
    // „Dodaj książkę" z karty istniejącej → drugi egzemplarz z jej metryką.
    // Osobna trasa zamiast `#/dodaj?ksiazka=N`, bo router czyta sam `hash`
    // i dopasowanie z parametrami wymagałoby zmiany wszystkich porównań.
    let md = h.match(/^#\/dodaj\/(\d+)$/);
    if (md) {
        await formularz(app, null, { kopiujZ: Number(md[1]) });
        return;
    }
    // Lista aktywnych wypożyczeń (historia jest na karcie książki)
    if (h === '#/wypozyczone') {
        await katalog(app, { tylkoAktywne: true });
        return;
    }
    // Statystyki katalogu
    if (h === '#/statystyki') {
        await statystyki(app);
        return;
    }
    // „Do przeczytania" — katalog z wymuszonym filtrem statusu
    if (h === '#/do-przeczytania') {
        await katalog(app, { tylkoStatus: 'chce_przeczytac' });
        return;
    }
    // Drugi katalog — ebooki. Importy lazy: widok i formularz ebooków wciągają
    // spory kod (parsery, okładki), a katalog papierowy go nie potrzebuje.
    if (h === '#/ebooki') {
        const { widokEbooki } = await import('./ebooki.js');
        await widokEbooki(app);
        return;
    }
    if (h === '#/ebook-dodaj') {
        const { widokEbookFormularz } = await import('./forma.js');
        await widokEbookFormularz(app);
        return;
    }
    // Ten sam widok co „dodaj", tylko z id → edycja istniejącego ebooka
    let me = h.match(/^#\/ebook\/(\d+)$/);
    if (me) {
        const { widokEbookFormularz } = await import('./forma.js');
        await widokEbookFormularz(app, Number(me[1]));
        return;
    }
    // Widok pojedynczej półki: nazwa idzie w kontekst, filtr wymusza list.php
    let mp = h.match(/^#\/polka\/(\d+)$/);
    if (mp) {
        const id = Number(mp[1]);
        const lista = await slownikPolki();
        const polka = lista.find((p) => p.id === id) ?? null;
        if (polka === null) {
            // Półka usunięta albo zły identyfikator — wypadek do katalogu
            location.hash = '#/katalog';
            return;
        }
        await katalog(app, { polka });
        return;
    }
    let m = h.match(/^#\/ksiazka\/(\d+)\/edytuj$/);
    if (m) {
        await formularz(app, Number(m[1]));
        return;
    }
    m = h.match(/^#\/ksiazka\/(\d+)$/);
    if (m) {
        await szczegoly(app, Number(m[1]));
        return;
    }
    location.hash = '#/katalog';
}

// Wygaśnięcie sesji (401 z dowolnego endpointu) → ekran logowania
window.addEventListener('sesja-wygasla', () => {
    zalogowany = false;
    widokLogowania();
});

window.addEventListener('hashchange', trasa);

/* ---------------------------------------------------------------- start */

async function start() {
    const stan = await api('stan.php').catch(() => null);
    zalogowany = Boolean(stan?.zalogowany);
    wersjaUstaw(stan?.wersja ?? '');
    if (zalogowany) {
        await trasa();
    } else {
        widokLogowania();
    }
}

// Service worker (installowalność PWA + cache powłoki)
if ('serviceWorker' in navigator) {
    navigator.serviceWorker.register('sw.js').catch(() => {});
}

start();
