/*
 * Exam room service worker. Keeps the exam page and its scripts on this computer so a candidate
 * whose connection drops, or whose computer restarts, can reopen the exam and keep writing.
 * Answers themselves are kept by the page (IndexedDB) and sent when the server is reachable.
 *
 * Only the exam room (/my/exams/...) is controlled. Pages are fetched from the network first and
 * the stored copy is used only when the network fails. Built files have unique names, so the
 * stored copy is used first for those.
 */
const CACHE = 'exam-room-v1';

self.addEventListener('install', () => self.skipWaiting());
self.addEventListener('activate', (event) => event.waitUntil(self.clients.claim()));

self.addEventListener('message', (event) => {
    const msg = event.data || {};
    if (msg.type === 'keep') {
        event.waitUntil(
            caches.open(CACHE).then(async (cache) => {
                const urls = [...new Set([msg.page, ...(msg.files || [])])].filter(Boolean);
                await Promise.all(
                    urls.map(async (u) => {
                        if (await cache.match(u)) {
                            return;
                        }
                        try {
                            const res = await fetch(u, { credentials: 'same-origin', cache: 'no-store' });
                            if (res.ok && !res.redirected) {
                                await cache.put(u, res);
                            }
                        } catch (e) {
                            /* offline now: kept next time */
                        }
                    }),
                );
            }),
        );
    }
    if (msg.type === 'forget' && msg.url) {
        event.waitUntil(caches.open(CACHE).then((cache) => cache.delete(msg.url)));
    }
});

function isAsset(url) {
    return url.pathname.startsWith('/build/') || url.pathname.startsWith('/vendor/face-api/') || url.pathname.startsWith('/favicon');
}

self.addEventListener('fetch', (event) => {
    const req = event.request;
    if (req.method !== 'GET') {
        return;
    }
    const url = new URL(req.url);
    if (url.origin !== self.location.origin) {
        return;
    }

    // the exam page: network first (fresh page when online), stored copy when not
    if (req.mode === 'navigate' && /^\/my\/exams\/\d+\/?$/.test(url.pathname)) {
        event.respondWith(
            (async () => {
                const cache = await caches.open(CACHE);
                try {
                    const res = await fetch(req);
                    if (res.ok && !res.redirected) {
                        cache.put(url.pathname, res.clone());
                    }
                    return res;
                } catch (e) {
                    const kept = await cache.match(url.pathname);
                    if (kept) {
                        return kept;
                    }
                    throw e;
                }
            })(),
        );
        return;
    }

    if (isAsset(url)) {
        event.respondWith(
            (async () => {
                const cache = await caches.open(CACHE);
                const kept = await cache.match(req, { ignoreSearch: false });
                if (kept) {
                    return kept;
                }
                const res = await fetch(req);
                if (res.ok) {
                    cache.put(req, res.clone());
                }
                return res;
            })(),
        );
    }
});
