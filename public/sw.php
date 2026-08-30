<?php
// Load config for environment detection
if (file_exists(__DIR__ . '/../config.php')) {
    require_once __DIR__ . '/../config.php';
}
require_once __DIR__ . '/../includes/shop_config.php';

$base_path = defined('BASE_PATH') ? BASE_PATH : '';
$logo_version = rawurlencode(printflow_logo_version());
$app_icon = $base_path . '/public/assets/images/icon-512.png?v=' . $logo_version;
$app_badge = $base_path . '/public/assets/images/icon-72.png';

header('Content-Type: application/javascript');
header('Service-Worker-Allowed: /');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');
header('X-Content-Type-Options: nosniff');
?>
/**
 * Service Worker - PrintFlow PWA
 * Strategy: App Shell (instant open) + Stale-While-Revalidate for pages
 */

const BASE_PATH = '<?php echo $base_path; ?>';
const CACHE_VERSION = 'v18';
const SHELL_CACHE = 'printflow-shell-' + CACHE_VERSION;
const PAGE_CACHE = 'printflow-pages-' + CACHE_VERSION;
const IMG_CACHE = 'printflow-img-' + CACHE_VERSION;
const API_VAPID_PUB = BASE_PATH + '/public/api/push/vapid_public_key.php';
const API_SUBSCRIBE = BASE_PATH + '/public/api/push/subscribe.php';
const API_PUSH_DEBUG = BASE_PATH + '/public/api/push/debug_log.php';

// App shell - cached immediately on install so the app opens instantly
const APP_SHELL = [
    BASE_PATH + '/public/offline.html',
    BASE_PATH + '/public/assets/css/output.css',
    BASE_PATH + '/public/assets/js/pwa.js',
    '<?php echo addslashes($app_icon); ?>',
    BASE_PATH + '/public/manifest.php',
];

// Pages to pre-cache
const PRE_CACHE_PAGES = [];

function normalizeTargetUrl(target) {
    const fallback = BASE_PATH + '/';
    const rawTarget = target || fallback;

    try {
        const url = new URL(rawTarget, self.location.origin);
        const host = String(self.location.hostname || '').toLowerCase();
        if (!BASE_PATH && host.includes('mrandmrsprintflow.com') && url.pathname.startsWith('/printflow/')) {
            url.pathname = url.pathname.replace(/^\/printflow(?=\/)/, '');
        }
        return url.pathname + url.search + url.hash;
    } catch (e) {
        if (!BASE_PATH && String(self.location.hostname || '').toLowerCase().includes('mrandmrsprintflow.com')) {
            return String(rawTarget).replace(/^\/printflow(?=\/)/, '');
        }
        return rawTarget;
    }
}

function urlB64ToUint8Array(base64String) {
    const pad = '='.repeat((4 - base64String.length % 4) % 4);
    const b64 = (base64String + pad).replace(/-/g, '+').replace(/_/g, '/');
    const raw = atob(b64);
    const outputArray = new Uint8Array(raw.length);
    for (let i = 0; i < raw.length; i++) {
        outputArray[i] = raw.charCodeAt(i);
    }
    return outputArray;
}

async function fetchVapidPublicKey() {
    try {
        const response = await fetch(API_VAPID_PUB, {
            credentials: 'include',
            cache: 'no-store'
        });
        if (!response.ok) return '';
        const data = await response.json();
        return data && data.public_key ? String(data.public_key) : '';
    } catch (error) {
        return '';
    }
}

async function sendSubscriptionToServer(subscription, action = 'subscribe') {
    if (!subscription) return false;

    const payload = typeof subscription.toJSON === 'function'
        ? subscription.toJSON()
        : subscription;

    payload.action = action;

    const response = await fetch(API_SUBSCRIBE, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        credentials: 'include',
        body: JSON.stringify(payload)
    });

    return response.ok;
}

function safeDebugValue(value, maxLength = 300) {
    if (value === null || value === undefined) return '';
    return String(value).slice(0, maxLength);
}

async function debugLog(eventType, payload = {}, endpoint = '') {
    try {
        await fetch(API_PUSH_DEBUG, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            credentials: 'include',
            keepalive: true,
            body: JSON.stringify({
                event_type: eventType,
                endpoint: endpoint || '',
                payload,
            })
        });
    } catch (error) {
        // Keep diagnostics non-blocking.
    }
}

function scheduleDebugLog(eventType, payload = {}, endpoint = '') {
    // Diagnostics should never delay a user-visible push popup.
    try {
        debugLog(eventType, payload, endpoint);
    } catch (error) {
        // Ignore logging errors entirely.
    }
}

// -- Install: cache shell + pages immediately ---------------------------------
self.addEventListener('install', (event) => {
    console.log('[SW] Installing...');
    event.waitUntil(
        Promise.all([
            debugLog('sw_install', { cache_version: CACHE_VERSION }),
            caches.open(SHELL_CACHE).then((cache) => {
                console.log('[SW] Caching app shell');
                return cache.addAll(APP_SHELL).catch(err => console.log('[SW] Cache failed:', err));
            }),
            caches.open(PAGE_CACHE).then((cache) => {
                return Promise.allSettled(
                    PRE_CACHE_PAGES.map((url) => cache.add(url).catch(() => { }))
                );
            }),
        ])
    );
    self.skipWaiting();
});

self.addEventListener('message', (event) => {
    const message = event && event.data ? event.data : null;
    if (message && message.type === 'SKIP_WAITING') {
        self.skipWaiting();
    }
});

// -- Activate: delete old caches -----------------------------------------------
self.addEventListener('activate', (event) => {
    console.log('[SW] Activating...');
    const KEEP = [SHELL_CACHE, PAGE_CACHE, IMG_CACHE];
    event.waitUntil(
        caches.keys().then((keys) =>
            Promise.all([
                debugLog('sw_activate', { cache_version: CACHE_VERSION, cache_count: keys.length }),
                ...keys.map((key) => {
                    if (!KEEP.includes(key)) {
                        console.log('[SW] Removing old cache:', key);
                        return caches.delete(key);
                    }
                })
            ])
        )
    );
    return self.clients.claim();
});

// -- Fetch: routing strategies -------------------------------------------------
self.addEventListener('fetch', (event) => {
    const { request } = event;
    const url = new URL(request.url);
    if (request.method !== 'GET') return;
    if (url.origin !== self.location.origin) return;

    if (request.destination === 'video' || request.destination === 'audio' || url.pathname.match(/\.(mp4|webm|ogg|mp3|wav)$/i)) {
        return;
    }

    if (url.pathname.includes('/api/') || url.pathname.includes('api_') || url.pathname.includes('ajax')) {
        event.respondWith(
            fetch(request, { cache: 'no-store' }).catch(() =>
                new Response('Network unavailable', { status: 503 })
            )
        );
        return;
    }

    if (isTrustedStaticAsset(url)) {
        event.respondWith(cacheFirst(request, SHELL_CACHE));
        return;
    }

    if (request.destination === 'document' || url.pathname.endsWith('.php') || url.pathname.endsWith('/')) {
        event.respondWith(networkOnlyDocument(request));
        return;
    }

    // Uploaded media, authenticated resources and unknown routes stay out of
    // Cache Storage. Only the explicit public asset tree is cache eligible.
    event.respondWith(networkOnlyResource(request));
});

async function cacheFirst(request, cacheName) {
    const cache = await caches.open(cacheName);
    const cached = await cache.match(request);
    if (cached) return cached;
    try {
        const response = await fetch(request);
        if (isCachePutEligible(request, response)) {
            try {
                await cache.put(request, response.clone());
            } catch (error) {
                const path = new URL(request.url).pathname;
                console.error('[SW] Cache First put error:', path, error);
            }
        }
        return response;
    } catch {
        return new Response('Asset unavailable offline', { status: 503 });
    }
}

async function networkOnlyDocument(request) {
    try {
        return await fetch(request, { cache: 'no-store' });
    } catch {
        const offline = await caches.match(BASE_PATH + '/public/offline.html');
        return offline || new Response('<h1>Offline</h1>', {
            headers: { 'Content-Type': 'text/html' },
            status: 503
        });
    }
}

async function networkOnlyResource(request) {
    try {
        return await fetch(request, { cache: 'no-store' });
    } catch {
        return new Response('Unavailable offline', { status: 503 });
    }
}

function isTrustedStaticAsset(url) {
    const assetRoot = (BASE_PATH || '') + '/public/assets/';
    if (url.origin !== self.location.origin || !url.pathname.startsWith(assetRoot)) return false;
    if (url.pathname.startsWith(assetRoot + 'uploads/')) return false;
    return /\.(css|js|png|jpg|jpeg|gif|webp|svg|ico|woff|woff2|ttf|eot)$/i.test(url.pathname);
}

function isCachePutEligible(request, response) {
    if (request.method !== 'GET' || request.headers.has('range') || request.headers.has('authorization')) return false;
    if (!response || !response.ok || response.status !== 200 || response.type === 'opaque') return false;
    if (response.headers.has('content-range')) return false;

    const cacheControl = String(response.headers.get('cache-control') || '').toLowerCase();
    if (cacheControl.includes('no-store') || cacheControl.includes('private')) return false;
    if (String(response.headers.get('vary') || '').trim() === '*') return false;

    const contentType = String(response.headers.get('content-type') || '').toLowerCase();
    if (!/^(text\/(css|javascript)|application\/(javascript|x-javascript|font-woff)|image\/|font\/|application\/vnd\.ms-fontobject)/.test(contentType)) {
        return false;
    }

    const contentLengthHeader = response.headers.get('content-length');
    const contentLength = Number(contentLengthHeader || 0);
    return !contentLengthHeader || (Number.isFinite(contentLength) && contentLength <= 8 * 1024 * 1024);
}

self.addEventListener('push', (event) => {
    const defaults = {
        title: 'PrintFlow',
        body:  'You have a new update',
        icon:  '<?php echo addslashes($app_icon); ?>',
        badge: '<?php echo addslashes($app_badge); ?>',
        image: '',
        tag:   'pf-general',
        url:   BASE_PATH + '/',
    };

    let payload = { ...defaults };
    if (event.data) {
        try { payload = { ...defaults, ...event.data.json() }; }
        catch { payload.body = event.data.text() || defaults.body; }
    }

    event.waitUntil(
        (async () => {
            const resolvedTag = payload.tag
                ? String(payload.tag)
                : ('pf-' + Date.now() + '-' + Math.random().toString(36).slice(2, 8));

            const primaryOptions = {
                body:    payload.body,
                icon:    payload.icon,
                badge:   payload.badge,
                image:   payload.image || undefined,
                // Ensure each push can trigger a visible popup even when app/browser is inactive.
                tag:     resolvedTag,
                renotify: true,
                requireInteraction: true,
                silent: false,
                data:    { url: normalizeTargetUrl(payload.url) },
            };

            scheduleDebugLog('sw_push_received', {
                has_data: !!event.data,
                title: safeDebugValue(payload.title, 120),
                body: safeDebugValue(payload.body, 160),
                tag: safeDebugValue(payload.tag, 80),
                url: safeDebugValue(payload.url, 180),
            });

            try {
                await self.registration.showNotification(payload.title, primaryOptions);
                scheduleDebugLog('sw_notification_shown', {
                    title: safeDebugValue(payload.title, 120),
                    tag: safeDebugValue(resolvedTag, 80),
                    target: safeDebugValue(primaryOptions.data && primaryOptions.data.url ? primaryOptions.data.url : '', 180),
                });
            } catch (error) {
                const fallbackOptions = {
                    ...primaryOptions,
                    icon: defaults.icon,
                    badge: defaults.badge,
                    image: undefined,
                };
                scheduleDebugLog('sw_notification_show_failed', {
                    error: safeDebugValue(error && error.message ? error.message : 'showNotification failed', 180),
                    title: safeDebugValue(payload.title, 120),
                });
                await self.registration.showNotification(payload.title, fallbackOptions);
                scheduleDebugLog('sw_notification_shown_fallback', {
                    title: safeDebugValue(payload.title, 120),
                    tag: safeDebugValue(resolvedTag, 80),
                });
            }

            const windowClients = await clients.matchAll({ type: 'window', includeUncontrolled: true });
            const targetUrl = new URL(normalizeTargetUrl(payload.url), self.location.origin).href;

            for (const client of windowClients) {
                const clientUrl = new URL(client.url, self.location.origin).href;
                if (clientUrl === targetUrl && client.visibilityState === 'visible') {
                    client.postMessage({ type: 'PF_PUSH_RECEIVED', payload });
                    break;
                }
            }
        })()
    );
});

self.addEventListener('pushsubscriptionchange', (event) => {
    event.waitUntil(
        (async () => {
            try {
                const applicationServerKey = await fetchVapidPublicKey();
                if (!applicationServerKey) return;

                const subscription = await self.registration.pushManager.subscribe({
                    userVisibleOnly: true,
                    applicationServerKey: urlB64ToUint8Array(applicationServerKey)
                });

                await sendSubscriptionToServer(subscription, 'subscribe');
                await debugLog('sw_pushsubscriptionchange_success', {
                    endpoint_present: !!(subscription && subscription.endpoint),
                }, subscription && subscription.endpoint ? subscription.endpoint : '');
            } catch (error) {
                console.error('[SW] Failed to refresh push subscription', error);
                await debugLog('sw_pushsubscriptionchange_failed', {
                    error: safeDebugValue(error && error.message ? error.message : 'subscription refresh failed', 180),
                });
            }
        })()
    );
});

self.addEventListener('notificationclick', (event) => {
    event.notification.close();
    const target = normalizeTargetUrl(event.notification.data?.url || BASE_PATH + '/');

    event.waitUntil(
        (async () => {
            await debugLog('sw_notification_click', {
                target: safeDebugValue(target, 180),
                title: safeDebugValue(event.notification && event.notification.title ? event.notification.title : '', 120),
            });
            const windowClients = await clients.matchAll({ type: 'window', includeUncontrolled: true });

            let bestClient = null;
            for (const client of windowClients) {
                if (new URL(client.url).pathname === new URL(target, self.location.origin).pathname) {
                    bestClient = client;
                    break;
                }
                if (!bestClient) bestClient = client;
            }

            if (bestClient) {
                await bestClient.focus();
                if (new URL(bestClient.url).pathname !== new URL(target, self.location.origin).pathname) {
                    bestClient.postMessage({ type: 'PF_NAVIGATE', url: target });
                }
                return;
            }

            if (clients.openWindow) await clients.openWindow(target);
        })()
    );
});


