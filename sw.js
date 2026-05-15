/**
 * D-Best TimeClock Service Worker
 * Provides offline functionality, caching, and background sync
 */

const CACHE_VERSION = 'v1.0.2';
const STATIC_CACHE = `timeclock-static-${CACHE_VERSION}`;
const DYNAMIC_CACHE = `timeclock-dynamic-${CACHE_VERSION}`;
const OFFLINE_QUEUE_KEY = 'offline-punch-queue';

// Static assets to cache on install
const STATIC_ASSETS = [
    '/offline.html',
    '/css/user-portal.css',
    '/js/script.js',
    '/js/index.js',
    '/images/FoleyTimePro-logo.png',
    '/images/FoleyTimePro-favicon.webp',
    '/images/default_avatar.png',
    '/images/icons/icon-192x192.png',
    '/images/icons/icon-512x512.png',
    '/images/icons/maskable-192x192.png',
    '/images/icons/maskable-512x512.png'
];

// API endpoints that should use network-first strategy
const API_PATTERNS = [
    '/api/',
    '/functions/',
    '/admin/',
    '/user/'
];

// ========================================
// Service Worker Lifecycle Events
// ========================================

/**
 * Install event - cache static assets
 */
self.addEventListener('install', (event) => {
    console.log('[SW] Installing service worker...');

    event.waitUntil(
        caches.open(STATIC_CACHE)
            .then((cache) => {
                console.log('[SW] Caching static assets');
                // Use addAll with error handling for individual assets
                return Promise.allSettled(
                    STATIC_ASSETS.map(url =>
                        cache.add(url).catch(err => {
                            console.warn(`[SW] Failed to cache: ${url}`, err);
                        })
                    )
                );
            })
            .then(() => {
                console.log('[SW] Static assets cached');
                return self.skipWaiting();
            })
    );
});

/**
 * Activate event - clean up old caches
 */
self.addEventListener('activate', (event) => {
    console.log('[SW] Activating service worker...');

    event.waitUntil(
        caches.keys()
            .then((cacheNames) => {
                return Promise.all(
                    cacheNames
                        .filter((name) => {
                            return name.startsWith('timeclock-') &&
                                   name !== STATIC_CACHE &&
                                   name !== DYNAMIC_CACHE;
                        })
                        .map((name) => {
                            console.log('[SW] Deleting old cache:', name);
                            return caches.delete(name);
                        })
                );
            })
            .then(() => {
                console.log('[SW] Service worker activated');
                return self.clients.claim();
            })
    );
});

// ========================================
// Fetch Strategies
// ========================================

/**
 * Fetch event - handle network requests with appropriate strategy
 */
self.addEventListener('fetch', (event) => {
    const { request } = event;
    const url = new URL(request.url);

    // Skip non-GET requests for caching (except POST for offline queue)
    if (request.method !== 'GET') {
        // Handle POST requests for punch actions when offline
        if (request.method === 'POST' && isPunchEndpoint(url)) {
            event.respondWith(handleOfflinePunch(request));
            return;
        }
        return;
    }

    // Skip chrome-extension and other non-http(s) requests
    if (!url.protocol.startsWith('http')) {
        return;
    }

    // Skip cross-origin requests — let the browser handle third-party scripts
    // (Cloudflare beacon, analytics, etc.) without SW interference so that
    // subresource integrity checks always run against the real network response.
    if (url.hostname !== self.location.hostname) {
        return;
    }

    // Determine strategy based on request type
    if (isApiRequest(url)) {
        // API requests: Network-first with cache fallback
        event.respondWith(networkFirst(request));
    } else if (isStaticAsset(url)) {
        // Static assets: Cache-first
        event.respondWith(cacheFirst(request));
    } else {
        // HTML pages: Network-first with offline fallback
        event.respondWith(networkFirstWithOfflineFallback(request));
    }
});

/**
 * Cache-first strategy - for static assets
 */
async function cacheFirst(request) {
    const cachedResponse = await caches.match(request);
    if (cachedResponse) {
        return cachedResponse;
    }

    try {
        const networkResponse = await fetch(request);
        if (networkResponse.ok) {
            const cache = await caches.open(STATIC_CACHE);
            cache.put(request, networkResponse.clone());
        }
        return networkResponse;
    } catch (error) {
        console.warn('[SW] Cache-first network failed:', request.url);
        // Return a fallback response if available
        return new Response('Asset not available offline', { status: 503 });
    }
}

/**
 * Network-first strategy - for API calls
 */
async function networkFirst(request) {
    try {
        const networkResponse = await fetch(request);
        if (networkResponse.ok) {
            const cache = await caches.open(DYNAMIC_CACHE);
            cache.put(request, networkResponse.clone());
        }
        return networkResponse;
    } catch (error) {
        console.warn('[SW] Network-first failed, trying cache:', request.url);
        const cachedResponse = await caches.match(request);
        if (cachedResponse) {
            return cachedResponse;
        }
        return new Response(JSON.stringify({
            success: false,
            message: 'You are offline. Please try again when connected.'
        }), {
            status: 503,
            headers: { 'Content-Type': 'application/json' }
        });
    }
}

/**
 * Network-first with offline fallback - for HTML pages
 */
async function networkFirstWithOfflineFallback(request) {
    try {
        const networkResponse = await fetch(request);
        if (networkResponse.ok) {
            const cache = await caches.open(DYNAMIC_CACHE);
            cache.put(request, networkResponse.clone());
        }
        return networkResponse;
    } catch (error) {
        console.warn('[SW] Page request failed, checking cache:', request.url);
        const cachedResponse = await caches.match(request);
        if (cachedResponse) {
            return cachedResponse;
        }
        // Return offline page
        const offlinePage = await caches.match('/offline.html');
        if (offlinePage) {
            return offlinePage;
        }
        return new Response('You are offline', { status: 503 });
    }
}

// ========================================
// Offline Punch Queue (Background Sync)
// ========================================

/**
 * Handle punch requests when offline
 */
async function handleOfflinePunch(request) {
    try {
        // Try network first
        const response = await fetch(request.clone());
        return response;
    } catch (error) {
        // Network failed - queue for later
        console.log('[SW] Queuing punch for background sync');

        try {
            const data = await request.json();
            await queueOfflinePunch(data);

            return new Response(JSON.stringify({
                success: true,
                queued: true,
                message: 'Your punch has been saved and will sync when you\'re back online.'
            }), {
                status: 202,
                headers: { 'Content-Type': 'application/json' }
            });
        } catch (queueError) {
            return new Response(JSON.stringify({
                success: false,
                message: 'Failed to save punch offline. Please try again.'
            }), {
                status: 503,
                headers: { 'Content-Type': 'application/json' }
            });
        }
    }
}

/**
 * Queue a punch for later sync
 */
async function queueOfflinePunch(punchData) {
    // Open IndexedDB for persistent storage
    const db = await openPunchDB();
    const tx = db.transaction('punches', 'readwrite');
    const store = tx.objectStore('punches');

    await store.add({
        ...punchData,
        queuedAt: new Date().toISOString(),
        synced: false
    });

    // Register for background sync if supported
    if ('sync' in self.registration) {
        await self.registration.sync.register('sync-punches');
    }
}

/**
 * Open IndexedDB for punch queue
 */
function openPunchDB() {
    return new Promise((resolve, reject) => {
        const request = indexedDB.open('TimeclockOffline', 1);

        request.onerror = () => reject(request.error);
        request.onsuccess = () => resolve(request.result);

        request.onupgradeneeded = (event) => {
            const db = event.target.result;
            if (!db.objectStoreNames.contains('punches')) {
                db.createObjectStore('punches', { keyPath: 'id', autoIncrement: true });
            }
        };
    });
}

// ========================================
// Background Sync
// ========================================

/**
 * Sync event - process queued punches
 */
self.addEventListener('sync', (event) => {
    if (event.tag === 'sync-punches') {
        console.log('[SW] Background sync triggered');
        event.waitUntil(syncOfflinePunches());
    }
});

/**
 * Sync all queued punches
 */
async function syncOfflinePunches() {
    try {
        const db = await openPunchDB();
        const tx = db.transaction('punches', 'readonly');
        const store = tx.objectStore('punches');
        const punches = await getAllFromStore(store);

        for (const punch of punches) {
            if (!punch.synced) {
                try {
                    const response = await fetch('/functions/clock_action.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({
                            EmployeeID: punch.EmployeeID,
                            action: punch.action,
                            note: punch.note + ' [Synced from offline]',
                            time: punch.time,
                            latitude: punch.latitude,
                            longitude: punch.longitude,
                            accuracy: punch.accuracy,
                            clientTime: punch.queuedAt
                        })
                    });

                    if (response.ok) {
                        // Mark as synced and delete
                        const deleteTx = db.transaction('punches', 'readwrite');
                        const deleteStore = deleteTx.objectStore('punches');
                        await deleteStore.delete(punch.id);
                        console.log('[SW] Synced punch:', punch.id);

                        // Notify user
                        await notifyPunchSynced(punch);
                    }
                } catch (syncError) {
                    console.warn('[SW] Failed to sync punch:', punch.id, syncError);
                }
            }
        }
    } catch (error) {
        console.error('[SW] Sync failed:', error);
    }
}

/**
 * Get all records from an object store
 */
function getAllFromStore(store) {
    return new Promise((resolve, reject) => {
        const request = store.getAll();
        request.onerror = () => reject(request.error);
        request.onsuccess = () => resolve(request.result);
    });
}

/**
 * Notify user that punch was synced
 */
async function notifyPunchSynced(punch) {
    if (self.registration.showNotification) {
        await self.registration.showNotification('Punch Synced', {
            body: `Your ${punch.action} has been synced successfully.`,
            icon: '/images/icons/icon-192x192.png',
            badge: '/images/icons/icon-72x72.png',
            tag: `punch-synced-${punch.id}`
        });
    }
}

// ========================================
// Push Notifications
// ========================================

/**
 * Push event - handle incoming push notifications
 */
self.addEventListener('push', (event) => {
    console.log('[SW] Push received');

    let data = {
        title: 'TimeClock Notification',
        body: 'You have a new notification',
        icon: '/images/icons/icon-192x192.png',
        badge: '/images/icons/icon-72x72.png'
    };

    if (event.data) {
        try {
            data = { ...data, ...event.data.json() };
        } catch (e) {
            data.body = event.data.text();
        }
    }

    event.waitUntil(
        self.registration.showNotification(data.title, {
            body: data.body,
            icon: data.icon,
            badge: data.badge,
            data: data.data || {},
            actions: data.actions || []
        })
    );
});

/**
 * Notification click event
 */
self.addEventListener('notificationclick', (event) => {
    console.log('[SW] Notification clicked');
    event.notification.close();

    const urlToOpen = event.notification.data?.url || '/';

    event.waitUntil(
        clients.matchAll({ type: 'window', includeUncontrolled: true })
            .then((windowClients) => {
                // Check if there's already a window open
                for (const client of windowClients) {
                    if (client.url.includes(self.registration.scope) && 'focus' in client) {
                        return client.focus().then(c => c.navigate(urlToOpen));
                    }
                }
                // Open new window
                return clients.openWindow(urlToOpen);
            })
    );
});

// ========================================
// Helper Functions
// ========================================

/**
 * Check if request is to an API endpoint
 */
function isApiRequest(url) {
    return API_PATTERNS.some(pattern => url.pathname.includes(pattern));
}

/**
 * Check if request is for a static asset
 */
function isStaticAsset(url) {
    const staticExtensions = ['.css', '.js', '.png', '.jpg', '.jpeg', '.webp', '.gif', '.svg', '.woff', '.woff2', '.ttf', '.eot'];
    return staticExtensions.some(ext => url.pathname.endsWith(ext));
}

/**
 * Check if request is for punch endpoint
 */
function isPunchEndpoint(url) {
    return url.pathname.includes('clock_action.php') ||
           url.pathname.includes('/api/v1/clock');
}

// ========================================
// Message Handler
// ========================================

/**
 * Handle messages from the main thread
 */
self.addEventListener('message', (event) => {
    console.log('[SW] Message received:', event.data);

    if (event.data.action === 'skipWaiting') {
        self.skipWaiting();
    }

    if (event.data.action === 'clearCache') {
        event.waitUntil(
            caches.keys().then(names =>
                Promise.all(names.map(name => caches.delete(name)))
            )
        );
    }

    if (event.data.action === 'getQueuedPunches') {
        event.waitUntil(
            openPunchDB()
                .then(db => {
                    const tx = db.transaction('punches', 'readonly');
                    return getAllFromStore(tx.objectStore('punches'));
                })
                .then(punches => {
                    event.source.postMessage({
                        action: 'queuedPunches',
                        punches: punches
                    });
                })
        );
    }
});

console.log('[SW] Service worker loaded');
