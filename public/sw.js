// EVORA service worker (v45). Pages always come from the network (they are personal and must be
// current), with a friendly offline page when there is no connection. EVORA's own versioned
// static files (/assets/...?v=...) are cached so the app opens faster.
const VERSION = 'evora-v45-1';
const OFFLINE = 'offline.html';

self.addEventListener('install', (event) => {
    event.waitUntil(caches.open(VERSION).then((c) => c.addAll([OFFLINE, 'assets/img/icon-192.png'])).then(() => self.skipWaiting()));
});

self.addEventListener('activate', (event) => {
    event.waitUntil(caches.keys()
        .then((keys) => Promise.all(keys.filter((k) => k !== VERSION).map((k) => caches.delete(k))))
        .then(() => self.clients.claim()));
});

self.addEventListener('fetch', (event) => {
    const req = event.request;
    if (req.method !== 'GET') {
        return;
    }
    if (req.mode === 'navigate') {
        event.respondWith(fetch(req).catch(() => caches.match(OFFLINE)));
        return;
    }
    const url = new URL(req.url);
    if (url.origin === self.location.origin && url.pathname.includes('/assets/')) {
        event.respondWith(caches.match(req).then((hit) => hit || fetch(req).then((res) => {
            if (res.ok) {
                const copy = res.clone();
                caches.open(VERSION).then((c) => c.put(req, copy));
            }
            return res;
        })));
    }
});
