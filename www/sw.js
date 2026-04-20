const CACHE_NAME = 'printserver-v7';
const STATIC_ASSETS = [
    'manifest.json',
    'icon-192.png',
    'icon-512.png'
];

self.addEventListener('install', event => {
    self.skipWaiting();
    event.waitUntil(
        caches.open(CACHE_NAME).then(cache => cache.addAll(STATIC_ASSETS).catch(() => {}))
    );
});

self.addEventListener('message', event => {
    if (event.data && event.data.type === 'SKIP_WAITING') {
        self.skipWaiting();
    }
});

self.addEventListener('activate', event => {
    event.waitUntil(
        caches.keys().then(keys =>
            Promise.all(keys.filter(k => k !== CACHE_NAME).map(k => caches.delete(k)))
        ).then(() => self.clients.claim())
    );
});

self.addEventListener('fetch', event => {
    if (event.request.method !== 'GET') return;
    const url = event.request.url;

    // NUNCA cachear: API, login, sw-version, QR, descargas
    if (url.includes('api.php') ||
        url.includes('login.php') ||
        url.includes('qr-approve.php') ||
        url.includes('sw-version.json') ||
        url.includes('qr_login=') ||
        url.includes('qrserver.com') ||
        url.includes('download_driver')) {
        return; // Dejar pasar al network directamente
    }

    // Network-first para HTML (index.php, /)
    if (url.includes('index.php') || url.endsWith('/') || url.includes('.php')) {
        event.respondWith(
            fetch(event.request).then(response => {
                if (response && response.status === 200) {
                    const toCache = response.clone();
                    caches.open(CACHE_NAME).then(cache => cache.put(event.request, toCache));
                }
                return response;
            }).catch(() => caches.match(event.request))
        );
        return;
    }

    // Cache-first para estáticos
    event.respondWith(
        caches.match(event.request).then(cached => {
            if (cached) return cached;
            return fetch(event.request).then(response => {
                if (!response || response.status !== 200) return response;
                const toCache = response.clone();
                caches.open(CACHE_NAME).then(cache => cache.put(event.request, toCache));
                return response;
            }).catch(() => cached);
        })
    );
});
