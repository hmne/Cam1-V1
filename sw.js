/**
 * Service Worker for Camera Control PWA
 *
 * Provides:
 * - Offline capability
 * - Fast loading via caching
 * - Background sync
 *
 * @version 1.0.0
 * @author Net Storm
 */

const CACHE_NAME = 'cam1-v1';
const CACHE_VERSION = 1;

// Files to cache for offline use
const STATIC_ASSETS = [
    '/cam1-v1/',
    '/cam1-v1/index.php',
    '/cam1-v1/assets/css/file.css',
    '/cam1-v1/assets/js/camera-control.js',
    '/cam1-v1/assets/js/adaptive-quality.js',
    '/cam1-v1/assets/js/download-helper.js',
    '/cam1-v1/assets/js/jquery-3.7.1.min.js',
    '/cam1-v1/assets/images/logo.ico',
    '/cam1-v1/buffer.jpg',
    '/cam1-v1/manifest.json'
];

// Files that should never be cached
const NO_CACHE = [
    'mode.php',
    'live.jpg',
    'pic.jpg',
    'tmp/',
    'check_new_image',
    'get_image_size'
];

// =============================================================================
// INSTALL
// =============================================================================

self.addEventListener('install', event => {
    console.log('[SW] Installing...');

    event.waitUntil(
        caches.open(CACHE_NAME)
            .then(cache => {
                console.log('[SW] Caching static assets');
                return cache.addAll(STATIC_ASSETS);
            })
            .then(() => {
                console.log('[SW] Install complete');
                return self.skipWaiting();
            })
            .catch(err => {
                console.error('[SW] Install failed:', err);
            })
    );
});

// =============================================================================
// ACTIVATE
// =============================================================================

self.addEventListener('activate', event => {
    console.log('[SW] Activating...');

    event.waitUntil(
        caches.keys()
            .then(cacheNames => {
                return Promise.all(
                    cacheNames
                        .filter(name => name !== CACHE_NAME)
                        .map(name => {
                            console.log('[SW] Deleting old cache:', name);
                            return caches.delete(name);
                        })
                );
            })
            .then(() => {
                console.log('[SW] Activated');
                return self.clients.claim();
            })
    );
});

// =============================================================================
// FETCH
// =============================================================================

self.addEventListener('fetch', event => {
    const url = new URL(event.request.url);

    // Skip non-GET requests
    if (event.request.method !== 'GET') {
        return;
    }

    // Check if should skip cache
    const shouldSkip = NO_CACHE.some(pattern => url.pathname.includes(pattern));
    if (shouldSkip) {
        // Network only - no caching
        event.respondWith(
            fetch(event.request)
                .catch(() => {
                    // Return offline fallback for mode.php
                    if (url.pathname.includes('mode.php')) {
                        return new Response(
                            '<div class="status-indicator offline">Offline</div>',
                            { headers: { 'Content-Type': 'text/html' } }
                        );
                    }
                    return new Response('Offline', { status: 503 });
                })
        );
        return;
    }

    // Stale-while-revalidate strategy
    event.respondWith(
        caches.match(event.request)
            .then(cachedResponse => {
                // Return cached version immediately
                const fetchPromise = fetch(event.request)
                    .then(networkResponse => {
                        // Update cache with new version
                        if (networkResponse && networkResponse.status === 200) {
                            const responseClone = networkResponse.clone();
                            caches.open(CACHE_NAME)
                                .then(cache => {
                                    cache.put(event.request, responseClone);
                                });
                        }
                        return networkResponse;
                    })
                    .catch(err => {
                        console.log('[SW] Fetch failed:', err);
                        return cachedResponse;
                    });

                return cachedResponse || fetchPromise;
            })
    );
});

// =============================================================================
// PUSH NOTIFICATIONS
// =============================================================================

self.addEventListener('push', event => {
    if (!event.data) return;

    const data = event.data.json();

    const options = {
        body: data.body || 'Camera notification',
        icon: '/cam1-v1/assets/images/icon-192.png',
        badge: '/cam1-v1/assets/images/badge-72.png',
        vibrate: [200, 100, 200],
        tag: data.tag || 'camera',
        renotify: true,
        data: {
            url: data.url || '/cam1-v1/'
        }
    };

    event.waitUntil(
        self.registration.showNotification(data.title || 'Camera Alert', options)
    );
});

// Handle notification click
self.addEventListener('notificationclick', event => {
    event.notification.close();

    const url = event.notification.data?.url || '/cam1-v1/';

    event.waitUntil(
        clients.matchAll({ type: 'window', includeUncontrolled: true })
            .then(windowClients => {
                // Focus existing window if open
                for (const client of windowClients) {
                    if (client.url.includes('/cam1-v1/') && 'focus' in client) {
                        return client.focus();
                    }
                }
                // Open new window
                if (clients.openWindow) {
                    return clients.openWindow(url);
                }
            })
    );
});

// =============================================================================
// BACKGROUND SYNC
// =============================================================================

self.addEventListener('sync', event => {
    if (event.tag === 'capture-sync') {
        event.waitUntil(
            // Retry capture command when back online
            fetch('/cam1-v1/index.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: 'b1=inic&submit=submit'
            })
        );
    }
});

// =============================================================================
// MESSAGE HANDLER
// =============================================================================

self.addEventListener('message', event => {
    if (event.data === 'skipWaiting') {
        self.skipWaiting();
    }

    if (event.data === 'clearCache') {
        caches.delete(CACHE_NAME).then(() => {
            console.log('[SW] Cache cleared');
        });
    }
});

console.log('[SW] Service Worker loaded');
