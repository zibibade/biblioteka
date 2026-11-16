/* Biblioteka — import ebooków z folderu wskazanego w przeglądarce.
   Same pliki zostają na dysku użytkownika: na serwer lecą tylko metadane,
   hash i (dla EPUB) przeskalowana okładka. Etykieta folderu to nazwa podana
   przez użytkownika, nigdy ścieżka dyskowa. */

import { api } from './api.js';
import { esc } from './ui.js';

const PARTIA = 25;                 // tyle pozycji w jednym żądaniu do API
const MAX_ROZMIAR = 100 * 1024 * 1024;
const OKLADKA_SZEROKOSC = 400;
const FORMATY = new Set(['epub', 'pdf', 'mobi', 'azw3', 'fb2', 'cbz']);

/* ===================================================================== UI */

function modal(trescHTML) {
    const overlay = document.createElement('div');
    overlay.className = 'modal-overlay';
    overlay.innerHTML =
        `<div class="modal" role="dialog" aria-modal="true">${trescHTML}</div>`;
    document.body.appendChild(overlay);
    return overlay;
}

/** Okno z nazwą folderu → Promise<string|null>. Domyślnie „Ebooki". */
function poprozEtykiete() {
    return new Promise((resolve) => {
        const overlay = modal(`
            <h3>Importuj folder</h3>
            <p>Same pliki zostają na Twoim dysku. Do katalogu trafią tylko
               metadane, hash i ewentualna okładka.</p>
            <label class="pole">
                <span>Nazwa folderu</span>
                <input id="eb-folder" type="text" value="Ebooki" maxlength="120"
                       autocomplete="off">
            </label>
            <div class="modal-przyciski">
                <button class="przycisk-pusty" data-anuluj>Anuluj</button>
                <button class="przycisk" data-dalej>Wybierz folder</button>
            </div>`);
        const wejscie = overlay.querySelector('#eb-folder');
        const zamknij = (wartosc) => {
            overlay.remove();
            resolve(wartosc);
        };
        wejscie.focus();
        wejscie.addEventListener('keydown', (e) => {
            if (e.key === 'Enter') {
                zamknij(wejscie.value.trim() || 'Ebooki');
            }
        });
        overlay.querySelector('[data-anuluj]').addEventListener('click', () => zamknij(null));
        overlay.querySelector('[data-dalej]').addEventListener('click', () =>
            zamknij(wejscie.value.trim() || 'Ebooki'));
        overlay.addEventListener('click', (e) => {
            if (e.target === overlay) {
                zamknij(null);
            }
        });
    });
}

/** Ukryty input katalogowy → Promise<File[]>. Czeka na wybór; anulowanie
    (zdarzenie `cancel`, Chrome) zwraca pustą listę. */
function wybierzPliki() {
    return new Promise((resolve) => {
        const input = document.createElement('input');
        input.type = 'file';
        input.setAttribute('webkitdirectory', '');
        input.setAttribute('multiple', '');
        input.hidden = true;
        const koniec = (pliki) => {
            input.remove();
            resolve(pliki);
        };
        input.addEventListener('change', () => koniec(Array.from(input.files ?? [])));
        input.addEventListener('cancel', () => koniec([]));
        document.body.appendChild(input);
        input.click();
    });
}

/* ============================================================ pomocnicze */

function rozszerzenie(nazwa) {
    const i = nazwa.lastIndexOf('.');
    return i > 0 ? nazwa.slice(i + 1).toLowerCase() : '';
}

/** Tytuł/autor z nazwy pliku. Wzorce: „Autor - Tytuł", „Tytuł (Autor)";
    `_` zamieniamy na spacje, a kropkę numeracji na początku/końcu usuwamy. */
function tytulZNazwy(nazwa) {
    let n = nazwa.replace(/\.[^.]+$/, '');
    n = n.replace(/_/g, ' ');
    n = n.replace(/^\s*[\(\[]?\d{1,3}[\)\].]?\s*[-–—.]?\s*/, '');
    n = n.replace(/\s*[\(\[]\d{1,3}[\)\]]\s*$/, '');
    n = n.replace(/\s{2,}/g, ' ').trim();

    const zAutorem = n.match(/^(.*?)\s+-\s+(.+)$/);
    if (zAutorem) {
        return { tytul: zAutorem[2].trim(), autor: zAutorem[1].trim() };
    }
    const zNawiasem = n.match(/^(.*?)\s+\(([^()]+)\)$/);
    // Nawias z samymi cyframi to raczej rok/numer, nie autor.
    if (zNawiasem && !/^\d+$/.test(zNawiasem[2].trim())) {
        return { tytul: zNawiasem[1].trim(), autor: zNawiasem[2].trim() };
    }
    return { tytul: n, autor: '' };
}

async function sha256(buf) {
    const h = await crypto.subtle.digest('SHA-256', buf);
    return Array.from(new Uint8Array(h))
        .map((b) => b.toString(16).padStart(2, '0'))
        .join('');
}

/** fflate UMD nie deklaruje eksportów ES — przy ładowaniu jako moduł trafia
    do window.fflate. Ładujemy leniwie, tylko gdy napotkamy EPUB. */
async function fflateZaladuj() {
    if (window.fflate?.unzipSync) {
        return window.fflate;
    }
    try {
        await import('./vendor/fflate.min.js');
    } catch {
        throw new Error('Brak biblioteki fflate (js/vendor/fflate.min.js).');
    }
    if (!window.fflate?.unzipSync) {
        throw new Error('fflate nie mógł się załadować.');
    }
    return window.fflate;
}

/* ================================================================ EPUB */

function xmlDok(tekst) {
    const doc = new DOMParser().parseFromString(tekst, 'application/xml');
    return doc.getElementsByTagName('parsererror').length ? null : doc;
}

function tekstW(dok, nazwa) {
    return (dok?.getElementsByTagName(nazwa)[0]?.textContent ?? '').trim();
}

/** Tekst z elementu XML: tagi zrzucamy do spacji, żeby opis nie był surowym HTML. */
function czystyTekst(html) {
    return (html ?? '')
        .replace(/<[^>]+>/g, ' ')
        .replace(/\s+/g, ' ')
        .trim();
}

/** fflate trzyma ścieżki dokładnie tak, jak zapisane w ZIP; nagłówek OPF bywa
    zakodowany w URL, więc porównujemy też zdekodowaną postać. */
function kluczZip(zip, sciezka) {
    const warianty = [sciezka];
    try {
        warianty.push(decodeURIComponent(sciezka));
    } catch { /* ścieżka z „%” — zostawiamy oryginał */ }
    for (const w of warianty) {
        if (Object.prototype.hasOwnProperty.call(zip, w)) {
            return w;
        }
    }
    return null;
}

function czytajZipTekst(zip, sciezka) {
    const klucz = kluczZip(zip, sciezka);
    if (klucz === null) {
        return null;
    }
    return new TextDecoder('utf-8', { fatal: false }).decode(zip[klucz]);
}

/** Składa ścieżkę pliku względem katalogu OPF i normalizuje `./` oraz `../`. */
function polaczSciezki(baza, href) {
    const katalog = baza.split('/').slice(0, -1);
    const stos = [];
    for (const czesc of katalog.concat(href.split('/'))) {
        if (!czesc || czesc === '.') {
            continue;
        }
        if (czesc === '..') {
            stos.pop();
        } else {
            stos.push(czesc);
        }
    }
    return stos.join('/');
}

function mimeZRozszerzenia(sciezka) {
    const ext = rozszerzenie(sciezka);
    return ({
        jpg: 'image/jpeg', jpeg: 'image/jpeg', png: 'image/png',
        gif: 'image/gif', webp: 'image/webp', svg: 'image/svg+xml',
    })[ext] ?? 'application/octet-stream';
}

function isbnZTekst(wartosc) {
    const c = wartosc.toLowerCase().replace(/^urn:isbn:/, '').replace(/[\s-]/g, '');
    if (/^\d{9}[\dx]$/.test(c) || /^\d{13}$/.test(c)) {
        return c;
    }
    return null;
}

function isbnZDokumentu(doc) {
    for (const el of doc.getElementsByTagName('dc:identifier')) {
        const isbn = isbnZTekst((el.textContent ?? '').trim());
        if (isbn) {
            return isbn;
        }
    }
    return null;
}

/** Okładka z manifestu: item z właściwością `cover-image` albo id z `meta name=cover`. */
function znajdzOkladke(zip, opfDoc, opfSciezka) {
    const itemy = opfDoc.getElementsByTagName('item');
    let href = '';
    for (const it of itemy) {
        const wlasciwosci = (it.getAttribute('properties') ?? '').split(/\s+/);
        if (wlasciwosci.includes('cover-image')) {
            href = it.getAttribute('href') ?? '';
            break;
        }
    }
    if (!href) {
        for (const m of opfDoc.getElementsByTagName('meta')) {
            if ((m.getAttribute('name') ?? '').toLowerCase() !== 'cover') {
                continue;
            }
            const id = m.getAttribute('content') ?? '';
            for (const it of itemy) {
                if (it.getAttribute('id') === id) {
                    href = it.getAttribute('href') ?? '';
                    break;
                }
            }
            break;
        }
    }
    if (!href) {
        return null;
    }
    const pelna = polaczSciezki(opfSciezka, href);
    const klucz = kluczZip(zip, pelna);
    if (klucz === null) {
        return null;
    }
    const dane = zip[klucz];
    if (!dane || dane.length === 0) {
        return null;
    }
    return new Blob([dane], { type: mimeZRozszerzenia(pelna) });
}

async function parsujEpub(buf) {
    const fflate = await fflateZaladuj();
    const zip = fflate.unzipSync(new Uint8Array(buf));
    const container = czytajZipTekst(zip, 'META-INF/container.xml');
    if (!container) {
        throw new Error('brak META-INF/container.xml');
    }
    const rootfile = xmlDok(container)?.getElementsByTagName('rootfile')[0];
    const opfSciezka = rootfile?.getAttribute('full-path') ?? '';
    if (!opfSciezka) {
        throw new Error('brak ścieżki do pliku OPF');
    }
    const opf = czytajZipTekst(zip, opfSciezka);
    if (!opf) {
        throw new Error('brak pliku OPF w archiwum');
    }
    const opfDoc = xmlDok(opf);
    if (!opfDoc) {
        throw new Error('nie da się sparsować pliku OPF');
    }
    const autorzy = Array.from(opfDoc.getElementsByTagName('dc:creator'))
        .map((el) => (el.textContent ?? '').trim())
        .filter(Boolean);
    return {
        tytul: tekstW(opfDoc, 'dc:title'),
        autor: autorzy.join(', '),
        wydawca: tekstW(opfDoc, 'dc:publisher'),
        rok: tekstW(opfDoc, 'dc:date'),
        jezyk: tekstW(opfDoc, 'dc:language'),
        isbn: isbnZDokumentu(opfDoc),
        opis: czystyTekst(tekstW(opfDoc, 'dc:description')),
        okladka: znajdzOkladke(zip, opfDoc, opfSciezka),
    };
}

/* ================================================================= FB2 */

/** Dekoduje tekst XML z uwzględnieniem deklaracji kodowania (FB2 bywa windows-1251). */
function dekodujXml(bajty) {
    const glow = new TextDecoder('ascii').decode(bajty.slice(0, 200)).toLowerCase();
    const m = glow.match(/encoding\s*=\s*["']([^"']+)["']/);
    try {
        return new TextDecoder(m?.[1] ?? 'utf-8').decode(bajty);
    } catch {
        return new TextDecoder('utf-8').decode(bajty);
    }
}

function autorFb2(autor) {
    return [
        tekstW(autor, 'first-name'),
        tekstW(autor, 'middle-name'),
        tekstW(autor, 'last-name'),
    ].filter(Boolean).join(' ');
}

function parsujFb2(buf) {
    const doc = xmlDok(dekodujXml(new Uint8Array(buf)));
    if (!doc) {
        return {};
    }
    const info = doc.getElementsByTagName('title-info')[0];
    if (!info) {
        return {};
    }
    const autorzy = Array.from(info.getElementsByTagName('author'))
        .map(autorFb2)
        .filter(Boolean);
    return {
        tytul: tekstW(info, 'book-title'),
        autor: autorzy.join(', '),
        jezyk: tekstW(info, 'lang'),
        opis: czystyTekst(tekstW(info, 'annotation')),
        wydawca: tekstW(info, 'publisher'),
        rok: tekstW(info, 'year'),
        isbn: isbnZTekst(tekstW(doc, 'ISBN')),
    };
}

/* ============================================================ MOBI/AZW3 */

/** EXTH: 100 autor, 101 wydawca, 103 opis, 104 ISBN, 503 tytuł, 524 język.
    Nagłówki MOBI są absolutne względem rekordu 0 (po 16-bajtowym PalmDOC). */
function parsujMobi(buf) {
    const bajty = new Uint8Array(buf);
    const widok = new DataView(buf);
    if (bajty.length < 82) {
        return {};
    }
    const liczbaRekordow = widok.getUint16(76, false);
    if (liczbaRekordow < 1) {
        return {};
    }
    // PalmDB: 78-bajtowy nagłówek, potem 8-bajtowe wpisy rekordów.
    const rekord0 = widok.getUint32(78, false);
    if (rekord0 + 0x84 > bajty.length) {
        return {};
    }
    const magic = String.fromCharCode(...bajty.slice(rekord0 + 0x10, rekord0 + 0x14));
    if (magic !== 'MOBI') {
        return {};
    }
    const dlugoscNaglowka = widok.getUint32(rekord0 + 0x14, false);
    const kodowanie = widok.getUint32(rekord0 + 0x1c, false) === 1252
        ? 'windows-1252'
        : 'utf-8';
    const flagi = widok.getUint32(rekord0 + 0x80, false);
    const wytlumacz = (dane) => new TextDecoder(kodowanie).decode(dane);

    let tytul = '';
    // Tytuł z nagłówka (0x54/0x58) jest starszy format niż EXTH 503 —
    // trzymamy go jako fallback, gdy rekord 503 nie istnieje.
    const tytulOffset = widok.getUint32(rekord0 + 0x54, false);
    const tytulDlugosc = widok.getUint32(rekord0 + 0x58, false);
    if (tytulOffset > 0 && tytulDlugosc > 0 && tytulOffset + tytulDlugosc <= bajty.length) {
        tytul = sanityzacja(wytlumacz(bajty.slice(rekord0 + tytulOffset, rekord0 + tytulOffset + tytulDlugosc)));
    }

    const wartosci = {};
    if ((flagi & 0x40) !== 0) {
        const exth = rekord0 + 16 + dlugoscNaglowka;
        if (exth + 12 <= bajty.length
            && String.fromCharCode(...bajty.slice(exth, exth + 4)) === 'EXTH') {
            const dlugosc = widok.getUint32(exth + 4, false);
            const ile = widok.getUint32(exth + 8, false);
            const koniec = Math.min(exth + dlugosc, bajty.length);
            let p = exth + 12;
            for (let i = 0; i < ile && p + 8 <= koniec; i++) {
                const typ = widok.getUint32(p, false);
                const rozmiar = widok.getUint32(p + 4, false);
                if (rozmiar < 8 || p + rozmiar > koniec) {
                    break;
                }
                wartosci[typ] = bajty.slice(p + 8, p + rozmiar);
                p += rozmiar;
            }
        }
    }
    const zrodlo = (typ) => sanityzacja(wytlumacz(wartosci[typ] ?? new Uint8Array(0)));

    return {
        tytul: zrodlo(503) || tytul,
        autor: zrodlo(100),
        wydawca: zrodlo(101),
        opis: zrodlo(103),
        isbn: isbnZTekst(zrodlo(104)),
        jezyk: zrodlo(524),
    };
}

function sanityzacja(tekst) {
    return (tekst ?? '').replace(/\u0000/g, '').replace(/\s+/g, ' ').trim();
}

/* ================================================================== PDF */

/** XMP zwykle zaczyna się na początku pliku, a słownik Info na końcu —
    dekodujemy dwa okna zamiast całego (do 100 MB) łańcucha. */
function parsujPdf(buf) {
    const ile = 8 * 1024 * 1024;
    const poczatek = new TextDecoder('utf-8', { fatal: false }).decode(buf.slice(0, ile));
    const koniec = new TextDecoder('utf-8', { fatal: false })
        .decode(buf.slice(Math.max(0, buf.byteLength - ile)));

    let tytul = '';
    let autor = '';
    const pakiet = poczatek.match(/<\?xpacket[\s\S]*?<\/x:xmpmeta>/i);
    if (pakiet) {
        tytul = xmpWartosc(pakiet[0], 'dc:title');
        autor = xmpWartosc(pakiet[0], 'dc:creator');
    }
    if (!tytul) {
        tytul = pdfInfo(poczatek + '\n' + koniec, 'Title');
    }
    if (!autor) {
        autor = pdfInfo(koniec, 'Author');
    }
    return { tytul, autor };
}

function xmpWartosc(xml, element) {
    const m = xml.match(new RegExp('<' + element + '\\b[^>]*>([\\s\\S]*?)</' + element + '>', 'i'));
    if (!m) {
        return '';
    }
    const li = Array.from(m[1].matchAll(/<rdf:li\b[^>]*>([\s\S]*?)<\/rdf:li>/gi))
        .map((x) => czystyTekst(x[1]))
        .filter(Boolean);
    return li.length ? li.join(', ') : czystyTekst(m[1]);
}

/** `/Title (wartość)` albo `/Title <hex UTF-16BE>`. */
function pdfInfo(tekst, klucz) {
    const w = tekst.match(new RegExp('/' + klucz + '\\s*\\(([^)]*)\\)', 'i'));
    if (w) {
        return w[1].replace(/\\([()\\])/g, '$1').trim();
    }
    const h = tekst.match(new RegExp('/' + klucz + '\\s*<([0-9A-Fa-f]+)>', 'i'));
    if (h && h[1].length % 2 === 0) {
        const bajty = new Uint8Array(h[1].length / 2);
        for (let i = 0; i < bajty.length; i++) {
            bajty[i] = parseInt(h[1].slice(i * 2, i * 2 + 2), 16);
        }
        return new TextDecoder('utf-16be').decode(bajty).trim();
    }
    return '';
}

/* ============================================================== dispatcher */

async function parsuj(ext, buf) {
    switch (ext) {
        case 'epub': return parsujEpub(buf);
        case 'fb2': return parsujFb2(buf);
        case 'mobi':
        case 'azw3': return parsujMobi(buf);
        case 'pdf': return parsujPdf(buf);
        default: return {};   // cbz — tylko nazwa pliku
    }
}

/* ================================================ wysyłka i okładki */

/** Identyfikator pozycji: nazwa pliku + hash. Tylko taka para jednoznacznie
    odróżnia rekord w katalogu, a import nie zwraca nam id. */
function kluczPozycji(p) {
    return `${p.nazwa_pliku}\n${p.hash_pliku}`;
}

function doJson(p) {
    const { okladka, ...reszta } = p;   // Blob nie jest JSON-em
    return reszta;
}

/** Znajduje id rekordów dodanych w tym imporcie. Lista z sortowaniem
    „dodania_desc" zawiera je na pierwszych stronach; liczbę stron wybieramy
    po liczbie dodanych pozycji. */
async function znajdzIds(pozycje, dodano) {
    const mapa = new Map();
    if (dodano <= 0) {
        return mapa;
    }
    const szukane = new Set(pozycje.map(kluczPozycji));
    const stron = Math.ceil(dodano / 24);   // 24 = EBOOK_NA_STRONE na serwerze
    for (let s = 1; s <= stron && mapa.size < szukane.size; s++) {
        const dane = await api(`ebooki.php?sort=dodania_desc&strona=${s}`);
        if (!dane) {
            return mapa;   // 401 — sesja wygasła
        }
        for (const e of dane.ebooki ?? []) {
            const k = `${e.nazwa_pliku}\n${e.hash_pliku}`;
            if (szukane.has(k) && !mapa.has(k) && !e.okladka) {
                mapa.set(k, e.id);
            }
        }
    }
    return mapa;
}

function przeskaluj(blob) {
    return new Promise((resolve) => {
        createImageBitmap(blob).then((bitmap) => {
            if (!bitmap.width || !bitmap.height) {
                resolve(null);
                return;
            }
            const wysokosc = Math.max(1, Math.round(bitmap.height * OKLADKA_SZEROKOSC / bitmap.width));
            const canvas = document.createElement('canvas');
            canvas.width = OKLADKA_SZEROKOSC;
            canvas.height = wysokosc;
            canvas.getContext('2d').drawImage(bitmap, 0, 0, canvas.width, canvas.height);
            canvas.toBlob((webp) => {
                if (webp) {
                    resolve(webp);
                    return;
                }
                // Stare Safari nie koduje WebP — serwer akceptuje też JPEG.
                canvas.toBlob((jpeg) => resolve(jpeg), 'image/jpeg', 0.85);
            }, 'image/webp', 0.85);
        }).catch(() => resolve(null));
    });
}

async function wyslijOkladke(id, blob) {
    const fd = new FormData();
    fd.append('id', String(id));
    fd.append('okladka', blob, 'okladka.webp');
    const odp = await fetch('api/ebook-okladka.php', {
        method: 'POST',
        body: fd,
        credentials: 'same-origin',
    });
    if (odp.status === 401) {
        window.dispatchEvent(new Event('sesja-wygasla'));
        throw new Error('sesja-wygasla');
    }
    return odp.ok;
}

async function wyslijOkladki(pozycje, dodano, postep) {
    const zOkladka = pozycje.filter((p) => p.okladka);
    if (zOkladka.length === 0) {
        return 0;
    }
    const mapa = await znajdzIds(zOkladka, dodano);
    let wyslane = 0;
    for (let i = 0; i < zOkladka.length; i++) {
        postep.textContent = `okładka ${i + 1}/${zOkladka.length}`;
        const p = zOkladka[i];
        const id = mapa.get(kluczPozycji(p));
        if (!id) {
            continue;   // duplikat albo błąd zapisu — okładki nie musimy mieć
        }
        try {
            const blob = await przeskaluj(p.okladka);
            if (blob && (await wyslijOkladke(id, blob))) {
                wyslane++;
            }
        } catch (err) {
            if (err.message === 'sesja-wygasla') {
                throw err;
            }
            // Pojedynczą okładkę ignorujemy — katalog nabędzie bez niej.
        }
    }
    return wyslane;
}

/* ================================================================ raport */

function raport({ dodano, okladki, duplikaty, bledy, pominiete, nieprzetworzone }) {
    const lista = (tytul, elementy) => elementy.length === 0 ? '' : `
        <p class="pole-pusto">${esc(tytul)} (${elementy.length})</p>
        <ul>${elementy
            .map((e) => `<li><b>${esc(e.tytul)}</b> — ${esc(e.powod)}</li>`)
            .join('')}</ul>`;
    const overlay = modal(`
        <h3>Raport importu</h3>
        <p>Dodano: <strong>${dodano}</strong>; wysłano okładek:
           <strong>${okladki}</strong>.</p>
        ${nieprzetworzone > 0
            ? `<p class="pole-pusto">Serwer nie przetworzył ${nieprzetworzone} pozycji
               (przekroczony budżet czasu) — usuń część plików i powtórz import.</p>`
            : ''}
        ${lista('Duplikaty', duplikaty)}
        ${lista('Błędy', bledy)}
        ${lista('Pominięte pliki', pominiete)}
        <div class="modal-przyciski">
            <button class="przycisk" data-zamknij>Zamknij</button>
        </div>`);
    overlay.querySelector('[data-zamknij]').addEventListener('click', () => overlay.remove());
    overlay.addEventListener('click', (e) => {
        if (e.target === overlay) {
            overlay.remove();
        }
    });
    overlay.querySelector('[data-zamknij]').focus();
}

/* ============================================================ punkt wejścia */

export async function importFolder() {
    const etykieta = await poprozEtykiete();
    if (etykieta === null) {
        return;
    }
    const pliki = await wybierzPliki();
    if (pliki.length === 0) {
        return;
    }

    const overlay = modal(`
        <h3>Importowanie…</h3>
        <p class="status-lookup" id="eb-postep">Przygotowanie…</p>
        <p class="pole-pusto" id="eb-etap"></p>`);
    const postep = overlay.querySelector('#eb-postep');
    const etap = overlay.querySelector('#eb-etap');

    const pozycje = [];
    const pominiete = [];
    for (let i = 0; i < pliki.length; i++) {
        const plik = pliki[i];
        postep.textContent = `plik ${i + 1}/${pliki.length} — parsowanie`;
        const ext = rozszerzenie(plik.name);
        if (!FORMATY.has(ext)) {
            pominiete.push({
                tytul: plik.name,
                powod: `nieobsługiwany format .${ext || '—'}`,
            });
            continue;
        }
        if (plik.size > MAX_ROZMIAR) {
            pominiete.push({ tytul: plik.name, powod: 'plik powyżej 100 MB' });
            continue;
        }
        try {
            const buf = await plik.arrayBuffer();
            const meta = await parsuj(ext, buf);
            const zNazwy = tytulZNazwy(plik.name);
            pozycje.push({
                ...meta,
                tytul: (meta.tytul || '').trim() || zNazwy.tytul,
                autor: (meta.autor || '').trim() || zNazwy.autor,
                format: ext,
                hash_pliku: await sha256(buf),
                nazwa_pliku: plik.name,   // tylko nazwa, nigdy ścieżka
            });
        } catch (err) {
            pominiete.push({
                tytul: plik.name,
                powod: err.message || 'nieznany błąd odczytu',
            });
        }
    }

    let dodano = 0;
    let nieprzetworzone = 0;
    const duplikaty = [];
    const bledy = [];
    const liczbaPartii = Math.ceil(pozycje.length / PARTIA);
    for (let i = 0; i < pozycje.length; i += PARTIA) {
        etap.textContent =
            `wysyłanie partii ${i / PARTIA + 1}/${liczbaPartii}`;
        const partia = pozycje.slice(i, i + PARTIA).map(doJson);
        try {
            const wynik = await api('ebook-import.php', { folder: etykieta, pozycje: partia });
            if (wynik === null) {
                overlay.remove();
                return;   // 401 — aplikacja pokaże ekran logowania
            }
            dodano += wynik.dodano ?? 0;
            nieprzetworzone += wynik.nieprzetworzone ?? 0;
            duplikaty.push(...(wynik.duplikaty ?? []));
            bledy.push(...(wynik.blledy ?? []));
        } catch (err) {
            bledy.push({
                tytul: `partia ${i / PARTIA + 1}`,
                powod: err.message || 'błąd wysyłki',
            });
        }
    }

    let okladki = 0;
    try {
        etap.textContent = 'okładki…';
        okladki = await wyslijOkladki(pozycje, dodano, postep);
    } catch (err) {
        overlay.remove();
        if (err.message === 'sesja-wygasla') {
            throw err;   // wygasła sesja przerywa import
        }
        // Sieć/serwer odpadł przy okładkach — sam import jest ważny, raport
        // pokaże zero okładek zamiast gubić cały wynik.
    }

    overlay.remove();
    raport({ dodano, okladki, duplikaty, bledy, pominiete, nieprzetworzone });
}
