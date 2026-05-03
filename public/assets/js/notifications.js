(function () {
    'use strict';

    /* -- Config ------------------------------------------------------------ */
    var POLL_INTERVAL_MS       = 5000;
    var POLL_INTERVAL_HIDDEN   = 60000;
    var SEEN_STORAGE_KEY       = 'pf_seen_notifications';
    var LAST_TOAST_ID_KEY      = 'pf_last_toast_notification_id';
    var AUTO_RESTORE_KEY       = 'pf_push_autorestore_attempted';
    var PROMPT_DISMISSED_KEY   = 'pf_push_prompt_dismissed';
    var BADGE_SELECTOR         = '#sidebar-notif-badge, #nav-notif-badge';

    function normalizeBasePath(rawBase) {
        var base = String(rawBase || '').trim();
        if (!base || base === '/') return '';
        if (base.charAt(0) !== '/') base = '/' + base;
        return base.replace(/\/+$/, '');
    }

    function getBasePath() {
        if (window.PFConfig && Object.prototype.hasOwnProperty.call(window.PFConfig, 'basePath')) {
            return normalizeBasePath(window.PFConfig.basePath);
        }
        return normalizeBasePath('/printflow');
    }

    function buildAppUrl(path) {
        var cleanPath = String(path || '').replace(/^\/+/, '');
        var base = getBasePath();
        return cleanPath ? (base + '/' + cleanPath) : (base || '');
    }

    function normalizeNotificationTarget(url) {
        if (!url) return url;

        var base = getBasePath();
        var host = String(window.location.hostname || '').toLowerCase();

        try {
            var target = new URL(url, window.location.origin);
            if (!base && host.indexOf('mrandmrsprintflow.com') !== -1 && target.pathname.indexOf('/printflow/') === 0) {
                target.pathname = target.pathname.replace(/^\/printflow(?=\/)/, '');
            }
            return target.pathname + target.search + target.hash;
        } catch (e) {
            if (!base && host.indexOf('mrandmrsprintflow.com') !== -1) {
                return String(url).replace(/^\/printflow(?=\/)/, '');
            }
            return url;
        }
    }

    var SW_PATH                = buildAppUrl('public/sw.php');
    var SW_SCOPE               = buildAppUrl('') || '/';
    var API_VAPID_PUB          = buildAppUrl('public/api/push/vapid_public_key.php');
    var API_SUBSCRIBE          = buildAppUrl('public/api/push/subscribe.php');
    var API_PUSH_TEST          = buildAppUrl('public/api/push/test.php');
    var API_PUSH_DEBUG         = buildAppUrl('public/api/push/debug_log.php');
    var API_PUSH_STATUS        = buildAppUrl('public/api/push/status.php');
    var API_POLL               = buildAppUrl('public/api/push/poll.php');
    var API_LIST               = buildAppUrl('public/api/notifications/list.php');
    var PUSH_CLIENT_VERSION    = 'v16';

    var USER_TYPE = (window.PFConfig && window.PFConfig.userType) ? window.PFConfig.userType : 'Customer';

    var pollTimer   = null;
    var recentToastMap = {};
    var lastPollTs  = Math.floor(Date.now() / 1000) - 30;

    function isIosFamily() {
        var ua = String(window.navigator.userAgent || '').toLowerCase();
        var platform = String(window.navigator.platform || '').toLowerCase();
        return /iphone|ipad|ipod/.test(ua) || (platform === 'macintel' && navigator.maxTouchPoints > 1);
    }

    function isStandaloneDisplay() {
        return !!(window.matchMedia && window.matchMedia('(display-mode: standalone)').matches)
            || window.navigator.standalone === true;
    }

    function getPushClientFingerprint() {
        var parts = [
            String(window.navigator.userAgent || ''),
            String(window.navigator.platform || ''),
            String(window.screen && window.screen.width ? window.screen.width : ''),
            String(window.screen && window.screen.height ? window.screen.height : ''),
            isStandaloneDisplay() ? 'standalone' : 'browser'
        ];
        return parts.join(' | ');
    }

    /* -- Export Early ------------------------------------------------------ */
    // Using simple var to ensure global access without modern scoping issues
    window.PFNotifications = {
        markSeen: markSeen,
        updateBadge: updateBadge,
        poll: poll,
        loadDropdown: loadDropdown,
        subscribeToPush: subscribeToPush,
        unsubscribeFromPush: unsubscribeFromPush,
        handlePushToggleClick: handlePushToggleClick,
        getPushStatus: function() {
            return fetch(API_PUSH_STATUS, { credentials: 'include' }).then(function(res) {
                return res.ok ? res.json() : null;
            });
        },
        getClientFingerprint: getPushClientFingerprint
    };

    /* -- Helpers ----------------------------------------------------------- */

    function seenIds() {
        try {
            var data = sessionStorage.getItem(SEEN_STORAGE_KEY);
            return new Set(JSON.parse(data || '[]'));
        } catch (e) {
            return new Set();
        }
    }

    function getLastToastNotificationId() {
        try {
            return parseInt(sessionStorage.getItem(LAST_TOAST_ID_KEY) || '0', 10) || 0;
        } catch (e) {
            return 0;
        }
    }

    function setLastToastNotificationId(id) {
        try {
            sessionStorage.setItem(LAST_TOAST_ID_KEY, String(parseInt(id, 10) || 0));
        } catch (e) {}
    }

    function getAuthSessionKey() {
        var cfg = window.PFConfig || {};
        var userId = cfg.userId || 'guest';
        var userType = cfg.userType || USER_TYPE || 'Customer';
        var sessionId = cfg.sessionId || 'session';
        return [userType, userId, sessionId].join(':');
    }

    function isPermissionPromptDismissed() {
        try {
            return sessionStorage.getItem(PROMPT_DISMISSED_KEY) === getAuthSessionKey();
        } catch (e) {
            return false;
        }
    }

    function rememberPermissionPromptDismissal() {
        try {
            sessionStorage.setItem(PROMPT_DISMISSED_KEY, getAuthSessionKey());
        } catch (e) {}
    }

    function clearPermissionPromptDismissal() {
        try {
            if (sessionStorage.getItem(PROMPT_DISMISSED_KEY) === getAuthSessionKey()) {
                sessionStorage.removeItem(PROMPT_DISMISSED_KEY);
            }
        } catch (e) {}
    }

    function getAutoRestoreKey() {
        return AUTO_RESTORE_KEY + ':' + getAuthSessionKey();
    }

    function getPushVersionKey() {
        return 'pf_push_subscription_version:' + getAuthSessionKey();
    }

    function getPushVapidKeyStorageKey() {
        return 'pf_push_vapid_public_key:' + getAuthSessionKey();
    }

    function getStoredPushVersion() {
        try {
            return localStorage.getItem(getPushVersionKey()) || '';
        } catch (e) {
            return '';
        }
    }

    function rememberPushVersion() {
        try {
            localStorage.setItem(getPushVersionKey(), PUSH_CLIENT_VERSION);
        } catch (e) {}
    }

    function getStoredPushVapidKey() {
        try {
            return localStorage.getItem(getPushVapidKeyStorageKey()) || '';
        } catch (e) {
            return '';
        }
    }

    function rememberPushVapidKey(publicKey) {
        try {
            localStorage.setItem(getPushVapidKeyStorageKey(), String(publicKey || ''));
        } catch (e) {}
    }

    function clearPushVersion() {
        try {
            localStorage.removeItem(getPushVersionKey());
        } catch (e) {}
        try {
            localStorage.removeItem(getPushVapidKeyStorageKey());
        } catch (e) {}
    }

    function markSeen(id) {
        var s = seenIds();
        s.add(String(id));
        var arr = [];
        s.forEach(function(val) { arr.push(val); });
        arr = arr.slice(-200);
        sessionStorage.setItem(SEEN_STORAGE_KEY, JSON.stringify(arr));
    }

    function urlB64ToUint8Array(base64String) {
        var pad = '='.repeat((4 - base64String.length % 4) % 4);
        var b64 = (base64String + pad).replace(/-/g, '+').replace(/_/g, '/');
        var raw = atob(b64);
        var outputArray = new Uint8Array(raw.length);
        for (var i = 0; i < raw.length; ++i) {
            outputArray[i] = raw.charCodeAt(i);
        }
        return outputArray;
    }

    function isPushSupported() {
        return 'serviceWorker' in navigator && 'PushManager' in window && typeof Notification !== 'undefined';
    }

    function getPushEnvironmentIssue() {
        if (!('serviceWorker' in navigator)) return 'This browser does not support service workers.';
        if (!('PushManager' in window)) return 'This browser does not support PushManager.';
        if (typeof Notification === 'undefined') return 'This browser does not support notifications.';

        var hostname = String(window.location.hostname || '').toLowerCase();
        var isLocalhost = hostname === 'localhost' || hostname === '127.0.0.1' || hostname === '[::1]';
        if (!window.isSecureContext && !isLocalhost) {
            return 'Background push requires HTTPS on this device. HTTP works only on true localhost.';
        }

        if (isIosFamily() && !isStandaloneDisplay()) {
            return 'On iPhone and iPad, background notifications only work after installing PrintFlow to the Home Screen and opening it as an app.';
        }

        return '';
    }

    function ensureServiceWorker() {
        if (!('serviceWorker' in navigator)) return Promise.reject(new Error('serviceWorker unsupported'));

        return cleanupLegacyServiceWorkers().then(function() {
            return navigator.serviceWorker.getRegistration(SW_SCOPE).then(function(reg) {
                if (reg) return reg;
                return navigator.serviceWorker.register(SW_PATH, {
                    scope: SW_SCOPE,
                    updateViaCache: 'none'
                });
            });
        }).then(function(reg) {
            if (reg && typeof reg.update === 'function') {
                reg.update().catch(function() {});
            }
            return navigator.serviceWorker.ready;
        }).then(function(reg) {
            logPushClientEvent('client_sw_ready', {
                has_installing: !!(reg && reg.installing),
                has_waiting: !!(reg && reg.waiting),
                has_active: !!(reg && reg.active)
            });
            return waitForActiveServiceWorker(reg);
        });
    }

    function cleanupLegacyServiceWorkers() {
        return listServiceWorkerRegistrations().then(function(registrations) {
            var tasks = [];
            for (var i = 0; i < registrations.length; i++) {
                var reg = registrations[i];
                var active = reg && (reg.active || reg.waiting || reg.installing);
                var scriptURL = active && active.scriptURL ? String(active.scriptURL) : '';
                if (!scriptURL) continue;
                if (scriptURL.indexOf('/public/sw.js') !== -1 && scriptURL.indexOf('/public/sw.php') === -1) {
                    tasks.push(reg.unregister().catch(function() { return false; }));
                }
            }
            return Promise.all(tasks);
        }).catch(function() { return []; });
    }

    function waitForActiveServiceWorker(reg) {
        if (!reg) return Promise.reject(new Error('service worker registration unavailable'));
        if (reg.active) return Promise.resolve(reg);

        return new Promise(function(resolve, reject) {
            var timeoutId = setTimeout(function() {
                reject(new Error('service worker activation timed out'));
            }, 15000);

            function resolveReady() {
                clearTimeout(timeoutId);
                resolve(reg);
            }

            if (reg.installing) {
                reg.installing.addEventListener('statechange', function() {
                    if (reg.active) {
                        resolveReady();
                    }
                });
            }

            if (reg.waiting) {
                reg.waiting.postMessage({ type: 'SKIP_WAITING' });
            }

            navigator.serviceWorker.ready.then(function(readyReg) {
                if (readyReg && readyReg.active) {
                    clearTimeout(timeoutId);
                    resolve(readyReg);
                }
            }).catch(function() {});
        });
    }

    function resetServiceWorkerAndRetry() {
        if (!('serviceWorker' in navigator)) return Promise.reject(new Error('serviceWorker unsupported'));

        return navigator.serviceWorker.getRegistration(SW_SCOPE)
            .then(function(reg) {
                if (!reg) return null;
                return reg.unregister().catch(function() { return false; });
            })
            .then(function() {
                return navigator.serviceWorker.register(SW_PATH, {
                    scope: SW_SCOPE,
                    updateViaCache: 'none'
                });
            })
            .then(function() {
                return navigator.serviceWorker.ready;
            });
    }

    function listServiceWorkerRegistrations() {
        if (!('serviceWorker' in navigator) || typeof navigator.serviceWorker.getRegistrations !== 'function') {
            return Promise.resolve([]);
        }
        return navigator.serviceWorker.getRegistrations().catch(function() { return []; });
    }

    function fetchVapidPublicKey() {
        return fetch(API_VAPID_PUB, { credentials: 'include' })
            .then(function(res) { return res.ok ? res.json() : null; })
            .then(function(data) { return data && data.public_key ? String(data.public_key) : ''; })
            .catch(function() { return ''; });
    }

    function bytesToBase64Url(bytes) {
        if (!bytes || typeof bytes.length !== 'number') return '';
        var binary = '';
        for (var i = 0; i < bytes.length; i++) {
            binary += String.fromCharCode(bytes[i]);
        }
        return btoa(binary).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/g, '');
    }

    function subscriptionMatchesServerKey(subscription, expectedPublicKey) {
        if (!subscription || !expectedPublicKey) return true;
        try {
            var options = subscription.options || {};
            var currentKey = options.applicationServerKey;
            if (!currentKey) return false;
            return bytesToBase64Url(new Uint8Array(currentKey)) === String(expectedPublicKey);
        } catch (e) {
            return false;
        }
    }

    function logPushClientEvent(eventType, payload) {
        payload = payload || {};
        if (!Object.prototype.hasOwnProperty.call(payload, 'client_fingerprint')) {
            payload.client_fingerprint = getPushClientFingerprint();
        }
        return fetch(API_PUSH_DEBUG, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            credentials: 'include',
            keepalive: true,
            body: JSON.stringify({
                event_type: eventType,
                payload: payload
            })
        }).catch(function() { return null; });
    }

    function sendSubscription(sub, action) {
        var payload = sub && typeof sub.toJSON === 'function' ? sub.toJSON() : sub;
        payload = payload || {};
        payload.action = action || 'subscribe';
        payload.client_fingerprint = getPushClientFingerprint();

        return fetch(API_SUBSCRIBE, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            credentials: 'include',
            body: JSON.stringify(payload)
        }).then(function(res) {
            return res.json().catch(function() { return {}; }).then(function(data) {
                if (!res.ok || !data || data.success === false) {
                    throw new Error((data && (data.error || data.message)) || ('Subscription request failed with status ' + res.status));
                }
                return data;
            });
        });
    }

    function triggerTestPush() {
        logPushClientEvent('client_push_test_requested', {});
        return fetch(API_PUSH_TEST, {
            method: 'POST',
            credentials: 'include',
            headers: { 'Accept': 'application/json' }
        })
        .then(function(res) {
            return res.json().catch(function() { return null; }).then(function(data) {
                if (!res.ok) {
                    throw new Error((data && (data.error || data.message)) || ('Test push failed with status ' + res.status));
                }
                return data;
            });
        })
        .then(function(data) {
            if (!data) {
                logPushClientEvent('client_push_test_empty_response', {});
                return { ok: false, error: 'No response from push test.' };
            }
            logPushClientEvent('client_push_test_result', {
                success: !!data.success,
                error: data.error || '',
                dispatch: data.dispatch || null
            });
            return {
                ok: !!data.success,
                error: data.error || '',
                dispatch: data.dispatch || null
            };
        })
        .catch(function(err) {
            logPushClientEvent('client_push_test_failed', {
                error: (err && err.message) ? err.message : 'Push test failed.'
            });
            return {
                ok: false,
                error: (err && err.message) ? err.message : 'Push test failed.'
            };
        });
    }

    function unregisterSubscriptionOnServer(existing) {
        if (!existing || !existing.endpoint) return Promise.resolve({});
        return sendSubscription({ endpoint: existing.endpoint }, 'unsubscribe').catch(function() {
            return {};
        });
    }

    function refreshSubscription(reg, isUserAction) {
        return reg.pushManager.getSubscription().then(function(existing) {
            var cleanup = existing
                ? existing.unsubscribe().catch(function() { return false; }).then(function() {
                    return unregisterSubscriptionOnServer(existing);
                })
                : Promise.resolve({});

            return cleanup.then(function() {
                clearPushVersion();
                return createFreshSubscription(reg, isUserAction, false).then(function(sub) {
                    if (sub) rememberPushVersion();
                    return sub;
                });
            });
        });
    }

    function createFreshSubscription(reg, isUserAction, didRetry) {
        return fetchVapidPublicKey().then(function(pubKey) {
            if (!pubKey) {
                if (isUserAction) alert('Push is not configured yet.');
                return null;
            }

            return Notification.requestPermission().then(function(permission) {
                if (permission !== 'granted') {
                    if (isUserAction) alert('Please allow notifications in your browser settings.');
                    return null;
                }

                return reg.pushManager.subscribe({
                    userVisibleOnly: true,
                    applicationServerKey: urlB64ToUint8Array(pubKey)
                }).then(function(sub) {
                    return sendSubscription(sub, 'subscribe').then(function() {
                        rememberPushVapidKey(pubKey);
                        logPushClientEvent('client_subscription_created', {
                            endpoint_present: !!(sub && sub.endpoint)
                        });
                        return sub;
                    });
                }).catch(function(err) {
                    var msg = String((err && err.message) || '').toLowerCase();
                    var shouldRetry = !didRetry && (msg.indexOf('registration failed') !== -1 || msg.indexOf('push service error') !== -1);
                    if (!shouldRetry) {
                        logPushClientEvent('client_subscription_create_failed', {
                            error: String((err && err.message) || 'subscribe failed')
                        });
                        throw err;
                    }
                    return listServiceWorkerRegistrations()
                        .then(function(regs) {
                            return Promise.all((regs || []).map(function(r) {
                                if (!r || typeof r.unregister !== 'function') return Promise.resolve(false);
                                return r.unregister().catch(function() { return false; });
                            }));
                        })
                        .then(function() {
                            return resetServiceWorkerAndRetry();
                        })
                        .then(function(newReg) {
                            return createFreshSubscription(newReg, isUserAction, true);
                        });
                });
            });
        });
    }

    function updatePushToggle(btn, state) {
        if (!btn) return;
        btn.dataset.state = state;

        if (state === 'unsupported') {
            var issue = getPushEnvironmentIssue();
            btn.textContent = issue ? 'HTTPS required for push' : 'Notifications unsupported';
            return;
        }
        if (state === 'blocked') {
            btn.textContent = 'Notifications blocked';
            return;
        }
        if (state === 'enabled') {
            btn.textContent = 'Disable notifications';
            return;
        }

        btn.textContent = 'Enable notifications';
    }

    function dismissPermissionPrompt() {
        var el = document.getElementById('pf-notify-prompt');
        if (el && el.parentNode) {
            el.parentNode.removeChild(el);
        }
    }

    function getPushPromptState() {
        if (!isPushSupported()) {
            return Promise.resolve({ show: false, state: 'unsupported' });
        }

        if (Notification.permission === 'denied') {
            return Promise.resolve({ show: true, state: 'blocked' });
        }

        return ensureServiceWorker()
            .then(function(reg) {
                return reg.pushManager.getSubscription().then(function(sub) {
                    if (sub) {
                        return { show: false, state: 'enabled', subscription: sub };
                    }

                    return {
                        show: true,
                        state: Notification.permission === 'granted' ? 'needs-subscription' : 'needs-permission'
                    };
                });
            })
            .catch(function() {
                return {
                    show: true,
                    state: Notification.permission === 'granted' ? 'needs-subscription' : 'needs-permission'
                };
            });
    }

    function getPromptCopy(state) {
        if (state === 'blocked') {
            return {
                title: 'Notifications are blocked',
                body: 'Turn on browser notifications for PrintFlow so you can receive order, payment, and status updates right away.',
                primaryLabel: 'Open notification settings',
                secondaryLabel: 'Close'
            };
        }

        if (state === 'needs-subscription') {
            return {
                title: 'Finish enabling notifications',
                body: 'Your browser allows notifications, but this device is not subscribed yet. Turn them on to receive PrintFlow updates.',
                primaryLabel: 'Enable now',
                secondaryLabel: 'Later'
            };
        }

        if (isIosFamily() && !isStandaloneDisplay()) {
            return {
                title: 'Install PrintFlow first',
                body: 'On iPhone and iPad, background notifications only work from the installed Home Screen app, not from a regular browser tab.',
                primaryLabel: 'Close',
                secondaryLabel: 'Later'
            };
        }

        return {
            title: 'Enable device notifications',
            body: 'Get order approvals, payment updates, and status changes even when PrintFlow is in the background.',
            primaryLabel: 'Enable',
            secondaryLabel: 'Later'
        };
    }

    function openBrowserNotificationHelp() {
        try {
            if (isIosFamily() && !isStandaloneDisplay()) {
                alert('On iPhone and iPad, open Share > Add to Home Screen, launch PrintFlow from the Home Screen, then enable notifications there.');
                return;
            }
            alert('Notifications are blocked. Please enable them from your browser site settings, then refresh this page.');
        } catch (e) {}
    }

    function showPermissionPrompt() {
        if (isPermissionPromptDismissed()) {
            dismissPermissionPrompt();
            return;
        }
        if (document.getElementById('pf-notify-prompt')) return;
        getPushPromptState().then(function(promptState) {
            if (isPermissionPromptDismissed()) {
                dismissPermissionPrompt();
                return;
            }
            if (!promptState || !promptState.show) {
                dismissPermissionPrompt();
                return;
            }
            if (document.getElementById('pf-notify-prompt')) return;

            var copy = getPromptCopy(promptState.state);
            var prompt = document.createElement('div');
            prompt.id = 'pf-notify-prompt';
            prompt.setAttribute('role', 'dialog');
            prompt.setAttribute('aria-live', 'polite');
            prompt.style.position = 'fixed';
            prompt.style.right = '24px';
            prompt.style.bottom = '24px';
            prompt.style.zIndex = '100000';
            prompt.style.width = 'min(360px, calc(100vw - 32px))';
            prompt.style.background = '#ffffff';
            prompt.style.border = '1px solid #e5e7eb';
            prompt.style.borderRadius = '14px';
            prompt.style.boxShadow = '0 20px 45px rgba(15, 23, 42, 0.2)';
            prompt.style.padding = '16px';
            prompt.style.fontFamily = 'inherit';
            prompt.style.color = '#0f172a';

            prompt.innerHTML =
                '<div style="display:flex;align-items:flex-start;gap:12px;">' +
                '  <div style="width:42px;height:42px;border-radius:12px;background:#ecfeff;color:#0e7490;display:flex;align-items:center;justify-content:center;flex-shrink:0;">' +
                '    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" style="width:22px;height:22px;"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9"/></svg>' +
                '  </div>' +
                '  <div style="flex:1;min-width:0;">' +
                '    <div style="font-size:15px;font-weight:700;line-height:1.3;margin-bottom:4px;">' + escHtml(copy.title) + '</div>' +
                '    <div style="font-size:13px;line-height:1.5;color:#475569;">' + escHtml(copy.body) + '</div>' +
                '  </div>' +
                '  <button type="button" aria-label="Close" style="border:none;background:transparent;color:#94a3b8;font-size:18px;line-height:1;cursor:pointer;padding:0;">&times;</button>' +
                '</div>' +
                '<div style="display:flex;justify-content:flex-end;gap:10px;margin-top:14px;">' +
                '  <button type="button" data-action="later" style="border:1px solid #cbd5e1;background:#ffffff;color:#475569;border-radius:10px;padding:10px 14px;font-size:13px;font-weight:600;cursor:pointer;">' + escHtml(copy.secondaryLabel) + '</button>' +
                '  <button type="button" data-action="enable" style="border:1px solid #0891b2;background:#0891b2;color:#ffffff;border-radius:10px;padding:10px 14px;font-size:13px;font-weight:700;cursor:pointer;">' + escHtml(copy.primaryLabel) + '</button>' +
                '</div>';

            var mountTarget = document.querySelector('.main-content') || document.querySelector('.dashboard-container') || document.body;
            mountTarget.appendChild(prompt);

            var closeBtn = prompt.querySelector('button[aria-label="Close"]');
            var laterBtn = prompt.querySelector('button[data-action="later"]');
            var enableBtn = prompt.querySelector('button[data-action="enable"]');

            if (closeBtn) {
                closeBtn.onclick = function() {
                    dismissPermissionPrompt();
                };
            }

            if (laterBtn) {
                laterBtn.onclick = function() {
                    if (promptState.state !== 'blocked') {
                        rememberPermissionPromptDismissal();
                    }
                    dismissPermissionPrompt();
                };
            }

            if (enableBtn) {
                enableBtn.onclick = function() {
                    if (isIosFamily() && !isStandaloneDisplay()) {
                        openBrowserNotificationHelp();
                        return;
                    }

                    if (promptState.state === 'blocked') {
                        openBrowserNotificationHelp();
                        return;
                    }

                    subscribeToPush(true).then(function(sub) {
                        if (sub) {
                            clearPermissionPromptDismissal();
                            dismissPermissionPrompt();
                            showToast('PrintFlow', 'Notifications enabled on this device.', '', '', '');
                            initPushToggle();
                            showPermissionPrompt();
                        }
                    });
                };
            }
        });
    }

    function subscribeToPush(isUserAction) {
        if (!isPushSupported()) return Promise.resolve(null);

        return ensureServiceWorker()
            .then(function(reg) {
                return fetchVapidPublicKey().then(function(serverPublicKey) {
                    return reg.pushManager.getSubscription().then(function(existing) {
                    if (existing) {
                        var requiresRefresh = getStoredPushVersion() !== PUSH_CLIENT_VERSION;
                        if (!requiresRefresh && serverPublicKey) {
                            requiresRefresh = !subscriptionMatchesServerKey(existing, serverPublicKey)
                                || getStoredPushVapidKey() !== String(serverPublicKey);
                        }

                        if (requiresRefresh) {
                            logPushClientEvent('client_subscription_refresh_required', {
                                version_mismatch: getStoredPushVersion() !== PUSH_CLIENT_VERSION,
                                vapid_mismatch: !!serverPublicKey
                            });
                            return refreshSubscription(reg, isUserAction);
                        }

                        return sendSubscription(existing, 'subscribe').then(function() {
                            rememberPushVersion();
                            if (serverPublicKey) rememberPushVapidKey(serverPublicKey);
                            logPushClientEvent('client_subscription_confirmed', {
                                endpoint_present: !!(existing && existing.endpoint)
                            });
                            return existing;
                        }).catch(function() {
                            return refreshSubscription(reg, isUserAction);
                        });
                    }

                    return createFreshSubscription(reg, isUserAction, false).then(function(sub) {
                        if (sub) rememberPushVersion();
                        return sub;
                    });
                });
                });
            })
            .catch(function(err) {
                if (isUserAction) {
                    alert('Notification setup failed: ' + (err && err.message ? err.message : 'Please try again.'));
                }
                logPushClientEvent('client_subscribe_failed', {
                    error: (err && err.message) ? err.message : 'unknown'
                });
                return null;
            });
    }

    function autoRestorePushSubscription() {
        if (!isPushSupported()) return;
        if (Notification.permission !== 'granted') return;

        ensureServiceWorker()
            .then(function(reg) {
                return fetchVapidPublicKey().then(function(serverPublicKey) {
                    return reg.pushManager.getSubscription().then(function(existing) {
                    if (existing) {
                        var requiresRefresh = getStoredPushVersion() !== PUSH_CLIENT_VERSION;
                        if (!requiresRefresh && serverPublicKey) {
                            requiresRefresh = !subscriptionMatchesServerKey(existing, serverPublicKey)
                                || getStoredPushVapidKey() !== String(serverPublicKey);
                        }

                        if (requiresRefresh) {
                            return refreshSubscription(reg, false).then(function(sub) {
                                if (sub) {
                                    try { localStorage.setItem(getAutoRestoreKey(), 'done'); } catch (e) {}
                                }
                                return sub;
                            });
                        }

                        return sendSubscription(existing, 'subscribe').then(function() {
                            rememberPushVersion();
                            if (serverPublicKey) rememberPushVapidKey(serverPublicKey);
                            try { localStorage.setItem(getAutoRestoreKey(), 'done'); } catch (e) {}
                            return existing;
                        }).catch(function() {
                            return refreshSubscription(reg, false).then(function(sub) {
                                if (sub) {
                                    try { localStorage.setItem(getAutoRestoreKey(), 'done'); } catch (e) {}
                                }
                                return sub;
                            });
                        });
                    }

                    return createFreshSubscription(reg, false).then(function(sub) {
                        if (sub) {
                            try { localStorage.setItem(getAutoRestoreKey(), 'done'); } catch (e) {}
                        }
                        return sub;
                    });
                });
                });
            })
            .then(function() {
                initPushToggle();
            })
            .catch(function() {
                try { localStorage.removeItem(getAutoRestoreKey()); } catch (e) {}
            });
    }

    function unsubscribeFromPush() {
        if (!isPushSupported()) return Promise.resolve(false);

        return ensureServiceWorker()
            .then(function(reg) {
                return reg.pushManager.getSubscription().then(function(existing) {
                    if (!existing) return false;
                    return existing.unsubscribe().then(function() {
                        return unregisterSubscriptionOnServer(existing).then(function() {
                            clearPushVersion();
                            return true;
                        });
                    });
                });
            })
            .catch(function() { return false; });
    }

    function initPushToggle() {
        var btn = document.getElementById('pf-push-toggle');
        if (!btn) return;

        if (!isPushSupported()) {
            updatePushToggle(btn, 'unsupported');
            return;
        }

        if (getPushEnvironmentIssue()) {
            updatePushToggle(btn, 'unsupported');
            return;
        }

        if (Notification.permission === 'denied') {
            updatePushToggle(btn, 'blocked');
            return;
        }

        ensureServiceWorker()
            .then(function(reg) { return reg.pushManager.getSubscription(); })
            .then(function(sub) {
                updatePushToggle(btn, sub ? 'enabled' : 'disabled');
            })
            .catch(function() {
                updatePushToggle(btn, 'disabled');
            });
    }

    function handlePushToggleClick(btn) {
        if (!btn) return;
        var state = btn.dataset.state || 'disabled';

        if (state === 'unsupported') {
            alert(getPushEnvironmentIssue() || 'This device/browser does not support push notifications.');
            return;
        }
        if (state === 'blocked') {
            alert('Notifications are blocked in your browser settings.');
            return;
        }
        if (state === 'enabled') {
            if (!confirm('Disable notifications on this device?')) return;
            unsubscribeFromPush().then(function() {
                updatePushToggle(btn, 'disabled');
            });
            return;
        }

        subscribeToPush(true).then(function(sub) {
            if (sub) clearPermissionPromptDismissal();
            updatePushToggle(btn, sub ? 'enabled' : 'disabled');
            if (sub) {
                triggerTestPush().then(function(result) {
                    if (result && result.ok) {
                        return;
                    }

                    ensureServiceWorker()
                        .then(function(reg) {
                            return refreshSubscription(reg, true);
                        })
                        .then(function(refreshedSub) {
                            updatePushToggle(btn, refreshedSub ? 'enabled' : 'disabled');
                            if (!refreshedSub) {
                                throw new Error('This browser could not refresh its push subscription.');
                            }
                            return triggerTestPush();
                        })
                        .then(function(retryResult) {
                            if (retryResult && retryResult.ok) {
                                alert('Notifications were repaired on this device. Please lock the device or background the app and test again.');
                                return;
                            }

                            var retryDispatch = retryResult && retryResult.dispatch
                                ? (' Sent: ' + (retryResult.dispatch.sent || 0) + ', failed: ' + (retryResult.dispatch.failed || 0) + ', expired: ' + (retryResult.dispatch.expired || 0) + '.')
                                : '';
                            throw new Error((retryResult && retryResult.error ? retryResult.error : 'Background push test still failed after refreshing this device subscription.') + retryDispatch);
                        })
                        .catch(function(err) {
                            alert('Notifications were enabled, but this device still did not confirm background delivery. ' + (err && err.message ? err.message : 'Please check browser and OS background notification settings.'));
                        });
                });
            }
        });
    }

    function bindPushMessages() {
        if (!('serviceWorker' in navigator)) return;
        navigator.serviceWorker.addEventListener('message', function(event) {
            var data = event.data || {};
            if (data.type === 'PF_PUSH_RECEIVED' && data.payload) {
                var payload = data.payload || {};
                showToast(
                    payload.title || 'PrintFlow',
                    payload.body || '',
                    payload.url ? normalizeNotificationTarget(payload.url) : '',
                    payload.image || payload.icon || '',
                    ''
                );
                return;
            }
            if (data.type === 'PF_NAVIGATE' && data.url) {
                window.location.href = normalizeNotificationTarget(data.url);
            }
        });
    }

    function updateBadge(count) {
        var els = document.querySelectorAll(BADGE_SELECTOR);
        for (var i = 0; i < els.length; i++) {
            var el = els[i];
            if (count > 0) {
                el.textContent = count > 99 ? '99+' : count;
                el.style.display = el.getAttribute('data-badge-display') || (el.id === 'nav-notif-badge' ? 'flex' : 'inline-flex');
                el.style.visibility = 'visible';
            } else {
                el.textContent = '';
                el.style.display = 'none';
                el.style.visibility = 'hidden';
            }
        }
    }

    function timeAgo(date) {
        if (!date) return 'just now';
        var d = new Date(date.replace(/-/g, '/'));
        var seconds = Math.floor((new Date() - d) / 1000);
        if (seconds < 60) return 'just now';
        var minutes = Math.floor(seconds / 60);
        if (minutes < 60) return minutes + 'm ago';
        var hours = Math.floor(minutes / 60);
        if (hours < 24) return hours + 'h ago';
        var days = Math.floor(hours / 24);
        if (days < 7) return days + 'd ago';
        return d.toLocaleDateString();
    }

    function loadDropdown() {
        var lists = document.querySelectorAll('[data-pf-notif-list]');
        if (lists.length === 0) return;

        fetch(API_LIST + '?limit=8', { credentials: 'include' })
            .then(function(res) {
                if (!res.ok) throw new Error('Response ' + res.status);
                return res.json();
            })
            .then(function(data) {
                if (!data.success) {
                    for (var i = 0; i < lists.length; i++) lists[i].innerHTML = '<div class="pf-notif-empty">' + escHtml(data.error || 'Failed to load.') + '</div>';
                    return;
                }

                if (!data.notifications || data.notifications.length === 0) {
                    for (var i = 0; i < lists.length; i++) lists[i].innerHTML = '<div class="pf-notif-empty">No notifications yet.</div>';
                    updateBadge(0);
                    return;
                }

                updateBadge(data.unread_count || 0);

                var html = '';
                for (var j = 0; j < data.notifications.length; j++) {
                    var n = data.notifications[j];
                    var target = normalizeNotificationTarget((n && n.link) ? n.link : ((n && n.target_url) ? n.target_url : getNotifUrl(n.type, n.data_id, n.message, n.id, n.order_type)));
                    var unreadClass = n.is_read == 0 ? 'unread' : '';
                    var type = (n.type || '').toLowerCase();
                    var iconSvg = '<svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9"/></svg>';
                    var mediaHtml = '';
                    
                    if (type.indexOf('order') !== -1 || type.indexOf('status') !== -1) {
                        iconSvg = '<svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"/></svg>';
                    } else if (type.indexOf('message') !== -1 || type.indexOf('chat') !== -1) {
                        iconSvg = '<svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 10h.01M12 10h.01M16 10h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z"/></svg>';
                    } else if (type.indexOf('payment') !== -1) {
                        iconSvg = '<svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>';
                    }

                    if (n.image) {
                        mediaHtml = '<img src="' + escAttr(n.image) + '" alt="" style="width:32px;height:32px;border-radius:8px;object-fit:cover;display:block;" onerror="this.onerror=null;this.src=\'' + escJsString(n.fallback || buildAppUrl('public/assets/images/icon-192.png')) + '\'">';
                    } else {
                        mediaHtml = iconSvg;
                    }

                    var itemKindHtml = '';
                    var itemKind = (n.item_kind || '').toLowerCase();
                    if (itemKind === 'product' || itemKind === 'service') {
                        var kindBg = itemKind === 'product' ? '#e0f2fe' : '#dcfce7';
                        var kindColor = itemKind === 'product' ? '#075985' : '#166534';
                        itemKindHtml = ' <span style="display:inline-flex;align-items:center;justify-content:center;min-width:56px;height:18px;padding:0 8px;border-radius:999px;font-size:10px;font-weight:700;text-transform:uppercase;background:' + kindBg + ';color:' + kindColor + ';">' + escHtml(itemKind) + '</span>';
                    }

                    html += '<a href="' + target + '" class="pf-notif-item ' + unreadClass + '">' +
                            '  <div class="pf-notif-item-icon">' + mediaHtml + '</div>' +
                            '  <div class="pf-notif-item-content">' +
                            '    <div class="pf-notif-item-text">' + escHtml(n.message) + '</div>' +
                            '    <div class="pf-notif-item-time">' + timeAgo(n.created_at) + itemKindHtml + '</div>' +
                            '  </div>' +
                            '</a>';
                }
                for (var k = 0; k < lists.length; k++) lists[k].innerHTML = html;
            })
            .catch(function(err) {
                for (var i = 0; i < lists.length; i++) lists[i].innerHTML = '<div class="pf-notif-empty">Error: ' + escHtml(err.message) + '</div>';
            });
    }

    function getNotifUrl(type, dataId, message, notifId, orderType) {
        var base = '/printflow';
        var t = (type || '').toLowerCase();
        var isStaff = (USER_TYPE.toLowerCase() === 'admin' || USER_TYPE.toLowerCase() === 'staff' || USER_TYPE.toLowerCase() === 'manager');
        var msg = (message || '').toLowerCase();
        var did = (dataId != null && dataId !== '') ? parseInt(dataId, 10) : 0;
        var url = base + '/';

        if (isStaff && t === 'system' && did > 0 && (msg.indexOf('ready for admin review') !== -1 || msg.indexOf('completed their profile') !== -1)) {
            url = base + '/admin/user_staff_management.php?open_user=' + did;
        } else if (isStaff) {
            if (t.indexOf('inventory') !== -1) url = base + '/admin/inv_items_management.php';
            else if (t.indexOf('message') !== -1 || t.indexOf('chat') !== -1) {
                url = did ? base + '/staff/chats.php?order_id=' + did : base + '/staff/chats.php';
            }
            else if (t.indexOf('rating') !== -1 || t.indexOf('review') !== -1) {
                url = USER_TYPE.toLowerCase() === 'staff' ? base + '/staff/reviews.php' : base + '/admin/notifications.php';
            }
            else if (t.indexOf('payment') !== -1) {
                url = did ? base + '/staff/orders.php?order_id=' + did : base + '/staff/notifications.php';
            }
            else if (t.indexOf('order') !== -1 || t.indexOf('job') !== -1 || t.indexOf('design') !== -1 || t.indexOf('custom') !== -1) {
                var oType = (orderType || '').toLowerCase();
                if (oType === 'custom' || t.indexOf('job') !== -1 || t.indexOf('custom') !== -1) {
                    url = base + '/staff/customizations.php?order_id=' + did + '&job_type=ORDER';
                } else {
                    url = base + '/staff/orders.php?order_id=' + did;
                }
            }
            else url = base + '/staff/dashboard.php';
        } else {
            if (msg.indexOf('support chat') !== -1 || msg.indexOf('chatbot') !== -1) url = base + '/customer/notifications.php?chatbot=open';
            else if (t.indexOf('message') !== -1 || t.indexOf('chat') !== -1) url = did ? base + '/customer/chat.php?order_id=' + did : base + '/customer/messages.php';
            else if (t.indexOf('order') !== -1 || t.indexOf('status') !== -1) url = base + '/customer/orders.php?highlight=' + did;
            else if (t.indexOf('payment') !== -1) url = did ? base + '/customer/orders.php?highlight=' + did : base + '/customer/notifications.php';
            else if (t.indexOf('job') !== -1) url = base + '/customer/new_job_order.php';
            else if (t.indexOf('rating') !== -1 || t.indexOf('review') !== -1) url = did ? base + '/customer/rate_order.php?order_id=' + did : base + '/customer/reviews.php';
            else if ((t.indexOf('design') !== -1 || t.indexOf('custom') !== -1) && did) url = base + '/customer/chat.php?order_id=' + did;
            else url = base + '/customer/notifications.php';
        }

        if (notifId) {
            url += (url.indexOf('?') !== -1 ? '&' : '?') + 'mark_read=' + notifId;
        }
        return url;
    }

    /* -- Polling ----------------------------------------------------------- */

    function poll() {
        fetch(API_POLL + '?since=' + lastPollTs, { credentials: 'include' })
            .then(function(res) { return res.ok ? res.json() : null; })
            .then(function(data) {
                if (!data || !data.success) return;
                updateBadge(data.unread_count || 0);
                if (data.server_time) {
                    lastPollTs = parseInt(data.server_time, 10) || lastPollTs;
                }

                var notifs = data.notifications || [];
                notifs.sort(function(a, b) {
                    return (parseInt(a.id, 10) || 0) - (parseInt(b.id, 10) || 0);
                });

                var highestId = getLastToastNotificationId();
                for (var i = 0; i < notifs.length; i++) {
                    var item = notifs[i];
                    var itemId = parseInt(item.id, 10) || 0;
                    if (itemId <= 0 || itemId <= highestId) {
                        continue;
                    }
                    if (itemId > 0) {
                        markSeen(String(itemId));
                        highestId = Math.max(highestId, itemId);
                    }
                    var targetUrl = normalizeNotificationTarget((item && item.link) ? item.link : ((item && item.target_url) ? item.target_url : getNotifUrl(item.type, item.data_id, item.message, item.id, item.order_type)));
                    showToast(item.title || 'PrintFlow', item.message, targetUrl, item.image || '', item.fallback || '');
                }

                setLastToastNotificationId(highestId);
            })
            .catch(function(){});
    }

    function schedulePoll() {
        clearTimeout(pollTimer);
        var delay = document.hidden ? POLL_INTERVAL_HIDDEN : POLL_INTERVAL_MS;
        pollTimer = setTimeout(function() { poll(); schedulePoll(); }, delay);
    }

    function showToast(title, body, url, imageUrl, fallbackImage) {
        var toastKey = [String(body || ''), String(url || ''), String(title || '')].join('|');
        var now = Date.now();
        var keys = Object.keys(recentToastMap);
        for (var r = 0; r < keys.length; r++) {
            if ((now - recentToastMap[keys[r]]) > 15000) {
                delete recentToastMap[keys[r]];
            }
        }
        if (recentToastMap[toastKey] && (now - recentToastMap[toastKey]) < 15000) {
            return;
        }
        recentToastMap[toastKey] = now;

        var container = document.getElementById('pf-toast-container');
        if (!container) {
            container = document.createElement('div');
            container.id = 'pf-toast-container';
            container.style.position = 'fixed';
            container.style.bottom = '24px';
            container.style.right = '24px';
            container.style.zIndex = '99999';
            container.style.display = 'flex';
            container.style.flexDirection = 'column';
            container.style.gap = '10px';
            container.style.maxWidth = '340px';
            document.body.appendChild(container);
        }

        var toast = document.createElement('div');
        toast.style.background = '#ffffff';
        toast.style.border = '1px solid #e5e7eb';
        toast.style.borderLeft = '4px solid #f97316';
        toast.style.borderRadius = '8px';
        toast.style.boxShadow = '0 4px 16px rgba(0,0,0,.12)';
        toast.style.padding = '12px 16px';
        toast.style.cursor = url ? 'pointer' : 'default';
        toast.style.display = 'flex';
        toast.style.alignItems = 'flex-start';
        toast.style.gap = '10px';

        var icon = document.createElement('img');
        icon.src = imageUrl || (window.PFConfig && window.PFConfig.logoUrl ? String(window.PFConfig.logoUrl) : buildAppUrl('public/assets/images/icon-72.png'));
        icon.style.width = '32px';
        icon.style.height = '32px';
        icon.style.borderRadius = '6px';
        icon.style.objectFit = 'cover';
        icon.style.flexShrink = '0';
        icon.onerror = function() {
            this.onerror = null;
            this.src = fallbackImage || buildAppUrl('public/assets/images/icon-192.png');
        };

        var text = document.createElement('div');
        text.innerHTML = '<div style="font-weight:600;font-size:.875rem;color:#111827;margin-bottom:2px">' + escHtml(title) + '</div>' +
                         '<div style="font-size:.8125rem;color:#6b7280;line-height:1.4">' + escHtml(body) + '</div>';

        var close = document.createElement('button');
        close.style.marginLeft = 'auto';
        close.style.background = 'none';
        close.style.border = 'none';
        close.style.cursor = 'pointer';
        close.style.color = '#9ca3af';
        close.style.fontSize = '1rem';
        close.style.padding = '0 0 0 8px';
        close.style.flexShrink = '0';
        close.innerHTML = '&times;';
        close.onclick = function(e) { e.stopPropagation(); toast.remove(); };

        toast.appendChild(icon);
        toast.appendChild(text);
        toast.appendChild(close);
        container.appendChild(toast);

        if (url) toast.onclick = function() { window.location.href = normalizeNotificationTarget(url); };
        setTimeout(function() { if (toast.parentNode) toast.remove(); }, 6000);
    }

    function escHtml(str) {
        if (str === null || str === undefined) return '';
        return String(str).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
    }

    function escAttr(str) {
        return escHtml(str);
    }

    function escJsString(str) {
        if (str === null || str === undefined) return '';
        return String(str).replace(/\\/g, '\\\\').replace(/'/g, "\\'");
    }

    var initStarted = false;

    function init() {
        if (initStarted) return;
        initStarted = true;
        logPushClientEvent('client_notifications_init', {
            permission: typeof Notification !== 'undefined' ? Notification.permission : 'unsupported',
            secure: !!window.isSecureContext,
            standalone: isStandaloneDisplay(),
            ios_family: isIosFamily()
        });
        bindPushMessages();
        initPushToggle();
        autoRestorePushSubscription();
        showPermissionPrompt();
        poll();
        schedulePoll();
    }

    function reinit() {
        clearTimeout(pollTimer);
        pollTimer = null;
        initStarted = false;
        init();
    }

    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
    else init();
    document.addEventListener('turbo:load', reinit);

    document.addEventListener('visibilitychange', function() {
        if (!document.hidden) {
            poll();
            schedulePoll();
        }
    });

    window.addEventListener('focus', function() {
        poll();
        schedulePoll();
    });

})();
