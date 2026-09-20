/**
 * KSPDOWA — Progressive Web App Service Worker
 * ============================================================
 * Cache Version: v1.0
 *
 * CRITICAL SECURITY ARCHITECTURE:
 * - Public static assets (/assets/images/*, /assets/css/*, favicon)
 *   are cached for high-performance loading and offline asset availability.
 * - Private, authenticated, and administrative routes (/admin/*, /member/*,
 *   login pages, payment verifications, and document downloads) are
 *   STRICTLY BYPASSED and NEVER stored in the client cache.
 * - If network connectivity is lost during public browsing, a graceful
 *   offline fallback (/offline.html) is rendered.
 * ============================================================
 */

const CACHE_NAME = 'kspdowa-static-v1.0';
const OFFLINE_URL = '/offline.html';

const PRECACHE_ASSETS = [
    '/offline.html',
    '/manifest.webmanifest',
    '/assets/images/logo.png',
    '/assets/images/favicon.png'
];

// 1. Install Event: Precache essential static offline assets
self.addEventListener('install', (event) => {
    event.waitUntil(
        caches.open(CACHE_NAME).then((cache) => {
            return cache.addAll(PRECACHE_ASSETS);
        }).then(() => self.skipWaiting())
    );
});

// 2. Activate Event: Clean up legacy caches
self.addEventListener('activate', (event) => {
    event.waitUntil(
        caches.keys().then((keys) => {
            return Promise.all(
                keys.map((key) => {
                    if (key !== CACHE_NAME) {
                        return caches.delete(key);
                    }
                })
            );
        }).then(() => self.clients.claim())
    );
});

// 3. Fetch Event: Safe routing and cache enforcement
self.addEventListener('fetch', (event) => {
    const req = event.request;
    const url = new URL(req.url);

    // Only intercept same-origin HTTP/HTTPS GET requests
    if (req.method !== 'GET' || url.origin !== self.location.origin) {
        return;
    }

    const pathname = url.pathname.toLowerCase();

    // STRICT SECURITY GATE:
    // Never cache admin, member, authentication, payment, or document endpoints
    const isPrivateEndpoint = 
        pathname.startsWith('/admin') ||
        pathname.startsWith('/member') ||
        pathname.includes('login') ||
        pathname.includes('logout') ||
        pathname.includes('payment') ||
        pathname.includes('download') ||
        pathname.includes('receipt');

    if (isPrivateEndpoint) {
        // Direct network fetch with zero caching
        event.respondWith(fetch(req));
        return;
    }

    // Static Assets (CSS, JS, Fonts, Images) -> Stale While Revalidate
    const isStaticAsset = 
        pathname.startsWith('/assets/') ||
        pathname.endsWith('.png') ||
        pathname.endsWith('.jpg') ||
        pathname.endsWith('.jpeg') ||
        pathname.endsWith('.svg') ||
        pathname.endsWith('.ico') ||
        pathname.endsWith('.css') ||
        pathname.endsWith('.js') ||
        pathname.endsWith('.woff2');

    if (isStaticAsset) {
        event.respondWith(
            caches.open(CACHE_NAME).then((cache) => {
                return cache.match(req).then((cachedResponse) => {
                    const fetchPromise = fetch(req).then((networkResponse) => {
                        if (networkResponse && networkResponse.status === 200) {
                            cache.put(req, networkResponse.clone());
                        }
                        return networkResponse;
                    }).catch(() => cachedResponse);

                    return cachedResponse || fetchPromise;
                });
            })
        );
        return;
    }

    // HTML Navigation Requests -> Network First with Offline Fallback
    if (req.mode === 'navigate') {
        event.respondWith(
            fetch(req).catch(() => {
                return caches.match(OFFLINE_URL);
            })
        );
        return;
    }

    // Default: Network fetch with cache fallback
    event.respondWith(
        fetch(req).catch(() => caches.match(req))
    );
});
