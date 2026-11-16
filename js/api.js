/* Biblioteka — cienka warstwa komunikacji z API (JSON, obsługa 401). */

const BASE = 'api/';

/** Żądanie JSON do endpointu. Przy 401 zwraca null (frontend pokaże login). */
export async function api(sciezka, dane = null, metoda = null) {
    const opcje = {
        method: metoda ?? (dane !== null ? 'POST' : 'GET'),
        headers: {},
        credentials: 'same-origin',
    };
    if (dane !== null) {
        opcje.headers['Content-Type'] = 'application/json';
        opcje.body = JSON.stringify(dane);
    }
    const odp = await fetch(BASE + sciezka, opcje);
    const json = await odp.json().catch(() => null);
    if (odp.status === 401) {
        // Sesja wygasła — aplikacja pokaże ekran logowania
        window.dispatchEvent(new Event('sesja-wygasla'));
        return null;
    }
    if (!odp.ok || json === null) {
        throw new Error(json?.blad ?? `Błąd HTTP ${odp.status}`);
    }
    return json;
}
