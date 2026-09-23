/**
 * PWA Registration and Installation
 * PrintFlow - Printing Shop PWA
 */

if ('serviceWorker' in navigator && !window.__pfPwaRegistered) {
    window.__pfPwaRegistered = true;
    window.addEventListener('load', () => {
        const basePath = (window.PFConfig?.basePath || '');
        const swPath = basePath + '/public/sw.php';
        const swScope = basePath + '/';
        const cleanupLegacyWorkers = typeof navigator.serviceWorker.getRegistrations === 'function'
            ? navigator.serviceWorker.getRegistrations().then((registrations) => Promise.all(
                registrations.map((registration) => {
                    const active = registration?.active || registration?.waiting || registration?.installing;
                    const scriptURL = active?.scriptURL ? String(active.scriptURL) : '';
                    if (scriptURL.includes('/public/sw.js') && !scriptURL.includes('/public/sw.php')) {
                        return registration.unregister().catch(() => false);
                    }
                    return Promise.resolve(true);
                })
            ))
            : Promise.resolve([]);

        cleanupLegacyWorkers.then(() => navigator.serviceWorker.register(swPath, {
            scope: swScope,
            updateViaCache: 'none'
        }))
            .then((registration) => {
                if (registration.waiting) {
                    registration.waiting.postMessage({ type: 'SKIP_WAITING' });
                }

                registration.addEventListener('updatefound', () => {
                    const newWorker = registration.installing;
                    newWorker.addEventListener('statechange', () => {
                        if (newWorker.state === 'installed' && navigator.serviceWorker.controller) {
                            showUpdateNotification();
                        }
                    });
                });
            })
            .catch((error) => {
                console.error('[PWA] Service Worker registration failed:', error);
            });
    });
}

if (typeof showUpdateNotification === 'undefined') {
    window.showUpdateNotification = function() {
        if (confirm('A new version of PrintFlow is available. Reload to update?')) {
            window.location.reload();
        }
    };
}

var deferredPrompt = window.deferredPrompt || null;
var _isIOS = /iphone|ipad|ipod/i.test(navigator.userAgent);
var _isStandalone = window.matchMedia('(display-mode: standalone)').matches || window.navigator.standalone === true;

function getInstallButtons() {
    return [
        document.getElementById('pwa-install-btn'),
        document.getElementById('pwa-install-btn-mobile'),
        document.getElementById('pwa-install-btn-profile')
    ].filter(Boolean);
}

function syncInstallButtons() {
    var canShow = !_isStandalone && (!!window.deferredPrompt || _isIOS);
    getInstallButtons().forEach(function(btn) {
        btn.style.display = canShow ? 'inline-flex' : 'none';
    });
}

if (!window.__pfPwaBeforeInstallAdded) {
    window.__pfPwaBeforeInstallAdded = true;
    window.addEventListener('beforeinstallprompt', function(e) {
        if (window.__pfPwaBeforeInstallCaptured) return;
        window.__pfPwaBeforeInstallCaptured = true;

        e.preventDefault();
        window.deferredPrompt = e;
        deferredPrompt = e;
        syncInstallButtons();
    });

    window.addEventListener('appinstalled', function() {
        window.deferredPrompt = null;
        deferredPrompt = null;
        window.__pfPwaBeforeInstallCaptured = false;
        hideInstallButton();
    });
}

if (typeof hideInstallButton === 'undefined') {
    window.hideInstallButton = function() {
        getInstallButtons().forEach(function(btn) {
            btn.style.display = 'none';
        });
    };
}

if (!window.__pfPwaDomBound) {
    window.__pfPwaDomBound = true;
    var bindPwaInstall = function() {
        syncInstallButtons();
        if (_isStandalone) {
            hideInstallButton();
            return;
        }

        getInstallButtons().forEach(function(btn) {
            btn.onclick = async function() {
                if (window.deferredPrompt) {
                    window.deferredPrompt.prompt();
                    var choice = await window.deferredPrompt.userChoice;
                    window.deferredPrompt = null;
                    deferredPrompt = null;
                    window.__pfPwaBeforeInstallCaptured = false;
                    if (choice.outcome === 'accepted') {
                        hideInstallButton();
                    } else {
                        syncInstallButtons();
                    }
                } else if (_isIOS) {
                    alert('To install PrintFlow on iOS:\n\n1. Tap the Share button in Safari\n2. Scroll down and tap "Add to Home Screen"\n3. Tap "Add" to confirm');
                }
            };
        });
    };

    document.addEventListener('DOMContentLoaded', bindPwaInstall);
    document.addEventListener('turbo:load', bindPwaInstall);
}

async function subscribeToPushNotifications() {
    if ('PushManager' in window && 'serviceWorker' in navigator) {
        try {
            var registration = await navigator.serviceWorker.ready;
            var subscription = await registration.pushManager.getSubscription();

            if (!subscription) {
                var permission = await Notification.requestPermission();

                if (permission === 'granted') {
                    var vapidResponse = await fetch((window.PFConfig?.basePath || '') + '/public/api/push/vapid_public_key.php', {
                        credentials: 'include'
                    });
                    var vapidData = await vapidResponse.json().catch(function() { return null; });
                    var vapidPublicKey = vapidData && vapidData.public_key ? String(vapidData.public_key) : '';

                    if (!vapidPublicKey) {
                        throw new Error('Push VAPID public key is not configured.');
                    }

                    subscription = await registration.pushManager.subscribe({
                        userVisibleOnly: true,
                        applicationServerKey: urlBase64ToUint8Array(vapidPublicKey)
                    });

                    console.log('[PWA] Push subscription:', subscription);
                    await sendSubscriptionToServer(subscription);
                }
            }

            return subscription;
        } catch (error) {
            console.error('[PWA] Push subscription failed:', error);
        }
    }
}

function urlBase64ToUint8Array(base64String) {
    var padding = '='.repeat((4 - base64String.length % 4) % 4);
    var base64 = (base64String + padding).replace(/\-/g, '+').replace(/_/g, '/');
    var rawData = window.atob(base64);
    var outputArray = new Uint8Array(rawData.length);

    for (var i = 0; i < rawData.length; ++i) {
        outputArray[i] = rawData.charCodeAt(i);
    }

    return outputArray;
}

async function sendSubscriptionToServer(subscription) {
    try {
        var payload = subscription && typeof subscription.toJSON === 'function'
            ? subscription.toJSON()
            : subscription;

        payload = payload || {};
        payload.action = 'subscribe';

        var response = await fetch((window.PFConfig?.basePath || '') + '/public/api/push/subscribe.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json'
            },
            credentials: 'include',
            body: JSON.stringify(payload)
        });

        if (response.ok) {
            console.log('[PWA] Subscription sent to server');
        }
    } catch (error) {
        console.error('[PWA] Failed to send subscription:', error);
    }
}

window.addEventListener('online', function() {
    hideOfflineNotification();
});

window.addEventListener('offline', function() {
    showOfflineNotification();
});

function showOfflineNotification() {
    var notification = document.getElementById('offline-notification');

    if (!notification) {
        notification = document.createElement('div');
        notification.id = 'offline-notification';
        notification.className = 'fixed bottom-4 right-4 bg-red-500 text-white px-6 py-3 rounded-lg shadow-lg z-50';
        notification.innerHTML = `
            <div class="flex items-center space-x-2">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18.364 5.636a9 9 0 010 12.728m0 0l-2.829-2.829m2.829 2.829L21 21M15.536 8.464a5 5 0 010 7.072m0 0l-2.829-2.829m-4.243 2.829a4.978 4.978 0 01-1.414-2.83m-1.414 5.658a9 9 0 01-2.167-9.238m7.824 2.167a1 1 0 111.414 1.414m-1.414-1.414L3 3"></path>
                </svg>
                <span>You are offline. Some features may be unavailable.</span>
            </div>
        `;
        document.body.appendChild(notification);
    }
}

function hideOfflineNotification() {
    var notification = document.getElementById('offline-notification');
    if (notification) {
        notification.remove();
    }
}

// Auto-subscribe to push notifications on login (optional)
// Uncomment when ready to implement push notifications
// if (document.body.dataset.userLoggedIn === 'true') {
//     subscribeToPushNotifications();
// }
