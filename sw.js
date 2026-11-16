/* Biblioteka — service worker: cache powłoki aplikacji.
   Aplikacja działa online; API zawsze z sieci (żadnych danych w cache). */

const CACHE = 'biblioteka-v1.0.0';
const SHELL = [
    './',
    './index.html',
    './css/style.css',
    './js/app.js',
    './js/api.js',
    './js/ui.js',
    './js/katalog.js',
    './js/ebooki.js',
    './js/ebook-import.js',
    './js/forma.js',
    './js/statystyki.js',
    './js/skaner.js',
    './js/dekodery.js',
    './js/vendor/zxing.min.js',
    './js/vendor/html5-qrcode.min.js',
    './js/vendor/fflate.min.js',
    './manifest.webmanifest',
    './assets/logo.svg',
    './assets/fonts/manrope-latin.woff2',
    './assets/fonts/manrope-latin-ext.woff2',
];

self.addEventListener('install', (e) => {
    e.waitUntil(
        caches.open(CACHE).then((c) => c.addAll(SHELL)).then(() => self.skipWaiting())
    );
});

self.addEventListener('activate', (e) => {
    e.waitUntil(
        caches.keys()
            .then((klucze) => Promise.all(
                klucze.filter((k) => k !== CACHE).map((k) => caches.delete(k))
            ))
            .then(() => self.clients.claim())
    );
});

self.addEventListener('fetch', (e) => {
    const url = new URL(e.request.url);
    // API i zewnętrzne źródła: tylko sieć, nigdy cache
    if (url.pathname.includes('/api/') || url.origin !== self.location.origin) {
        return;
    }
    if (e.request.method !== 'GET') {
        return;
    }
    // Powłoka: cache-first z podbiciem z sieci w tle
    e.respondWith(
        caches.match(e.request).then((trafienie) => {
            const pobranie = fetch(e.request).then((odp) => {
                if (odp.ok) {
                    const kopia = odp.clone();
                    caches.open(CACHE).then((c) => c.put(e.request, kopia));
                }
                return odp;
            }).catch(() => trafienie);
            return trafienie ?? pobranie;
        })
    );
});
