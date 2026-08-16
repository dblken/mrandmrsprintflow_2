(function () {
    'use strict';
    if (window.__printflowReceiptScannerLoaded) return;
    window.__printflowReceiptScannerLoaded = true;

    var script = document.currentScript;
    var basePath = script && script.dataset ? (script.dataset.basePath || '') : '';
    var buffer = '';
    var startedAt = 0;
    var lastKeyAt = 0;
    var scanTarget = null;
    var targetSnapshot = null;
    var settleTimer = null;
    var inFlight = false;
    var lastPayload = '';
    var lastHandledAt = 0;
    var debugEnabled = false;
    var MAX_GAP_MS = 250;
    var MAX_SCAN_MS = 4000;
    var SETTLE_MS = 180;
    var DUPLICATE_MS = 1200;
    var LOOKUP_TIMEOUT_MS = 6500;

    try {
        debugEnabled = new URLSearchParams(window.location.search).get('receipt_scanner_debug') === '1'
            || window.localStorage.getItem('printflow_receipt_scanner_debug') === '1';
    } catch (ignore) {}

    function debug(message, details) {
        if (!debugEnabled || !window.console || typeof window.console.info !== 'function') return;
        if (details === undefined) window.console.info('[Receipt Scanner] ' + message);
        else window.console.info('[Receipt Scanner] ' + message, details);
    }

    function safeError(message, details) {
        if (!window.console || typeof window.console.error !== 'function') return;
        window.console.error('[Receipt Scanner] ' + message, details || {});
    }

    function isProductBarcodeTarget(target) {
        return !!(target && typeof target.closest === 'function' && target.closest('.pos-barcode-entry'));
    }

    function isProtectedEditingTarget(target) {
        if (!target || target === document.body) return false;
        if (target.isContentEditable) return true;
        var tag = String(target.tagName || '').toLowerCase();
        var type = String(target.type || '').toLowerCase();
        return tag === 'select' || type === 'password' || target.id === 'receipt-lookup-input';
    }

    function isRestorableEditingTarget(target) {
        if (!target || (isProtectedEditingTarget(target) && !isProductBarcodeTarget(target))) return false;
        var tag = String(target.tagName || '').toLowerCase();
        return tag === 'input' || tag === 'textarea';
    }

    function snapshotTarget(target) {
        if (!isRestorableEditingTarget(target)) return null;
        return {
            value: String(target.value || ''),
            selectionStart: typeof target.selectionStart === 'number' ? target.selectionStart : null,
            selectionEnd: typeof target.selectionEnd === 'number' ? target.selectionEnd : null
        };
    }

    function restoreTarget() {
        if (!scanTarget || !targetSnapshot || !isRestorableEditingTarget(scanTarget)) return;
        scanTarget.value = targetSnapshot.value;
        if (targetSnapshot.selectionStart !== null && typeof scanTarget.setSelectionRange === 'function') {
            try { scanTarget.setSelectionRange(targetSnapshot.selectionStart, targetSnapshot.selectionEnd); } catch (ignore) {}
        }
        try { scanTarget.dispatchEvent(new Event('input', { bubbles: true })); } catch (ignore) {}
    }

    function clearSettleTimer() {
        if (settleTimer) window.clearTimeout(settleTimer);
        settleTimer = null;
    }

    function reset() {
        clearSettleTimer();
        buffer = '';
        startedAt = 0;
        lastKeyAt = 0;
        scanTarget = null;
        targetSnapshot = null;
    }

    function normalizePayload(raw) {
        var value = String(raw || '')
            .replace(/[\u0000-\u001F\u007F\u200B-\u200D\u2060\uFEFF]/g, '')
            .toUpperCase();
        var matches = value.match(/PF1:ORDER:[1-9][0-9]{0,9}(?![0-9])/g) || [];
        return matches.length === 1 ? matches[0] : '';
    }

    function toast(message, isError) {
        var node = document.getElementById('pf-receipt-scanner-toast');
        if (!node) {
            node = document.createElement('div');
            node.id = 'pf-receipt-scanner-toast';
            node.setAttribute('role', 'status');
            node.style.cssText = 'position:fixed;right:20px;bottom:20px;z-index:2147483000;max-width:360px;padding:12px 16px;border-radius:10px;color:#fff;font:600 14px/1.4 system-ui,sans-serif;box-shadow:0 12px 30px rgba(15,23,42,.28);transition:opacity .2s';
            document.body.appendChild(node);
        }
        node.style.background = isError ? '#b91c1c' : '#0f766e';
        node.style.opacity = '1';
        node.textContent = message;
        window.clearTimeout(node.__hideTimer);
        node.__hideTimer = window.setTimeout(function () { node.style.opacity = '0'; }, 3500);
    }

    function lookupError(code, message, status, requestId, retryable) {
        var error = new Error(message || 'Unable to look up receipt. Please scan again.');
        error.code = code || 'LOOKUP_FAILED';
        error.status = status || 0;
        error.requestId = requestId || '';
        error.retryable = !!retryable;
        return error;
    }

    async function requestLookup(payload, attempt) {
        var controller = typeof AbortController === 'function' ? new AbortController() : null;
        var timeoutId = window.setTimeout(function () { if (controller) controller.abort(); }, LOOKUP_TIMEOUT_MS);
        try {
            var url = basePath + '/staff/api/order_receipt_lookup.php?identifier=' + encodeURIComponent(payload) + '&_=' + Date.now();
            var response = await fetch(url, {
                cache: 'no-store', credentials: 'same-origin', headers: { Accept: 'application/json' },
                signal: controller ? controller.signal : undefined
            });
            var text = await response.text();
            var data;
            try { data = JSON.parse(text); }
            catch (ignore) { throw lookupError('MALFORMED_RESPONSE', 'Unable to look up receipt. Please scan again.', response.status, '', false); }
            if (!response.ok || !data.success || !data.route) {
                var transient = response.status === 408 || response.status === 429 || response.status >= 500;
                throw lookupError(data.code, data.message, response.status, data.request_id, transient);
            }
            return data;
        } catch (error) {
            var networkFailure = error && (error.name === 'AbortError' || error instanceof TypeError);
            var normalized = error && error.code
                ? error
                : lookupError(networkFailure ? 'NETWORK_ERROR' : 'LOOKUP_FAILED', 'Unable to look up receipt. Please scan again.', 0, '', networkFailure);
            if (attempt === 0 && normalized.retryable) {
                debug('transient lookup failure; retrying once', { code: normalized.code, status: normalized.status });
                await new Promise(function (resolve) { window.setTimeout(resolve, 180); });
                return requestLookup(payload, 1);
            }
            throw normalized;
        } finally {
            window.clearTimeout(timeoutId);
        }
    }

    async function lookup(payload) {
        var now = Date.now();
        if (inFlight) { debug('duplicate terminator ignored; lookup already in progress'); return; }
        if (payload === lastPayload && now - lastHandledAt < DUPLICATE_MS) {
            debug('duplicate scan ignored', { identifier: payload });
            return;
        }
        inFlight = true;
        lastPayload = payload;
        lastHandledAt = now;
        debug('lookup started', { identifier: payload });
        toast('Looking up receipt…', false);
        try {
            var data = await requestLookup(payload, 0);
            debug('lookup success', {
                identifier: data.identifier, order_id: data.order_id, source: data.source,
                route: data.route, request_id: data.request_id || ''
            });
            try {
                window.sessionStorage.setItem('printflow_receipt_scan_pending', JSON.stringify({
                    order_id: Number(data.order_id || 0), source: String(data.source || ''),
                    route: String(data.route || ''), started_at: Date.now()
                }));
            } catch (ignore) {}
            toast(data.warning || 'Receipt found. Opening order…', false);
            window.location.assign(String(data.route));
        } catch (error) {
            var details = {
                code: error && error.code ? error.code : 'LOOKUP_FAILED',
                status: error && error.status ? error.status : 0,
                request_id: error && error.requestId ? error.requestId : ''
            };
            safeError('lookup failed: ' + details.code, details);
            toast(error && error.message ? error.message : 'Unable to look up receipt. Please scan again.', true);
            inFlight = false;
            lastHandledAt = 0;
        }
    }

    function finishScan(reason, event) {
        clearSettleTimer();
        var raw = buffer;
        var payload = normalizePayload(raw);
        var duration = startedAt ? Date.now() - startedAt : Number.MAX_SAFE_INTEGER;
        if (!payload || duration > MAX_SCAN_MS) {
            if (raw) debug('decode/input incomplete', { terminator: reason, characters: raw.length, duration_ms: duration });
            reset();
            return false;
        }
        if (event) {
            event.preventDefault();
            if (typeof event.stopImmediatePropagation === 'function') event.stopImmediatePropagation();
        }
        restoreTarget();
        debug('scan captured', { identifier: payload, terminator: reason, characters: raw.length, duration_ms: duration });
        reset();
        lookup(payload);
        return true;
    }

    function scheduleSettledScan() {
        clearSettleTimer();
        if (!normalizePayload(buffer)) return;
        settleTimer = window.setTimeout(function () { finishScan('settled', null); }, SETTLE_MS);
    }

    document.addEventListener('keydown', function (event) {
        if (event.defaultPrevented || event.ctrlKey || event.altKey || event.metaKey) { reset(); return; }
        if (isProtectedEditingTarget(event.target)) { reset(); return; }
        var now = Date.now();
        var isTerminator = event.key === 'Enter' || event.key === 'Tab' || event.key === '\r' || event.key === '\n';
        if (isTerminator) { finishScan(event.key === 'Tab' ? 'tab' : 'enter', event); return; }
        if (event.key === 'Shift' || event.key === 'CapsLock' || event.key === 'NumLock' || event.key === 'Process') return;
        if (event.key.length !== 1 || !/[\x20-\x7E]/.test(event.key)) {
            if (buffer) debug('decode/input incomplete', { key: String(event.key || ''), characters: buffer.length });
            reset();
            return;
        }
        if (!startedAt || now - lastKeyAt > MAX_GAP_MS || (scanTarget && scanTarget !== event.target)) {
            reset();
            startedAt = now;
            scanTarget = event.target || null;
            targetSnapshot = snapshotTarget(scanTarget);
        }
        buffer += event.key;
        lastKeyAt = now;
        if (buffer.length > 96) { debug('decode/input incomplete', { reason: 'buffer_limit' }); reset(); return; }
        scheduleSettledScan();
    }, true);

    function readPendingScan() {
        try {
            var pending = JSON.parse(window.sessionStorage.getItem('printflow_receipt_scan_pending') || 'null');
            if (pending && Date.now() - Number(pending.started_at || 0) < 60000) {
                debug('route reached', { order_id: pending.order_id, source: pending.source, path: window.location.pathname });
                return pending;
            }
            window.sessionStorage.removeItem('printflow_receipt_scan_pending');
        } catch (ignore) {}
        return null;
    }

    window.PrintFlowReceiptScanner = {
        normalizePayload: normalizePayload,
        setDebug: function (enabled) {
            debugEnabled = !!enabled;
            try { window.localStorage.setItem('printflow_receipt_scanner_debug', debugEnabled ? '1' : '0'); } catch (ignore) {}
            return debugEnabled;
        },
        getState: function () { return { inFlight: inFlight, debug: debugEnabled }; },
        markOrderOpened: function (orderId, context) {
            var pending = readPendingScan();
            if (!pending || Number(pending.order_id) !== Number(orderId)) return false;
            debug('order opened', { order_id: Number(orderId), context: String(context || '') });
            try { window.sessionStorage.removeItem('printflow_receipt_scan_pending'); } catch (ignore) {}
            return true;
        }
    };
    readPendingScan();
})();
