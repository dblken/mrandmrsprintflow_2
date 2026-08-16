(function () {
    'use strict';
    if (window.__printflowReceiptScannerLoaded) return;
    window.__printflowReceiptScannerLoaded = true;

    var script = document.currentScript;
    var basePath = script && script.dataset ? (script.dataset.basePath || '') : '';
    var buffer = '';
    var startedAt = 0;
    var lastKeyAt = 0;
    var inFlight = false;
    var lastPayload = '';
    var lastHandledAt = 0;
    var MAX_GAP_MS = 80;
    var MAX_SCAN_MS = 1800;
    var DUPLICATE_MS = 3000;

    function isEditingTarget(target) {
        if (!target || target === document.body) return false;
        if (target.isContentEditable) return true;
        var tag = String(target.tagName || '').toLowerCase();
        return tag === 'input' || tag === 'textarea' || tag === 'select';
    }

    function reset() {
        buffer = '';
        startedAt = 0;
        lastKeyAt = 0;
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
        clearTimeout(node.__hideTimer);
        node.__hideTimer = setTimeout(function () { node.style.opacity = '0'; }, 3500);
    }

    async function lookup(payload) {
        var now = Date.now();
        if (inFlight || (payload === lastPayload && now - lastHandledAt < DUPLICATE_MS)) return;
        inFlight = true;
        lastPayload = payload;
        lastHandledAt = now;
        toast('Looking up receipt…', false);
        try {
            var url = basePath + '/staff/api/order_receipt_lookup.php?identifier=' + encodeURIComponent(payload) + '&_=' + now;
            var response = await fetch(url, { cache: 'no-store', credentials: 'same-origin', headers: { Accept: 'application/json' } });
            var data = await response.json().catch(function () { return {}; });
            if (!response.ok || !data.success || !data.route) throw new Error(data.message || 'Receipt lookup failed.');
            toast(data.warning || 'Receipt found. Opening order…', false);
            window.location.assign(String(data.route));
        } catch (error) {
            toast(error && error.message ? error.message : 'Receipt lookup failed.', true);
            inFlight = false;
        }
    }

    document.addEventListener('keydown', function (event) {
        if (event.defaultPrevented || event.ctrlKey || event.altKey || event.metaKey || isEditingTarget(event.target)) {
            reset();
            return;
        }
        var now = Date.now();
        if (event.key === 'Enter') {
            var payload = buffer.toUpperCase();
            var duration = startedAt ? now - startedAt : Number.MAX_SAFE_INTEGER;
            reset();
            if (/^PF1:ORDER:[1-9][0-9]{0,9}$/.test(payload) && duration <= MAX_SCAN_MS) {
                event.preventDefault();
                lookup(payload);
            }
            return;
        }
        if (event.key.length !== 1 || !/[\x20-\x7E]/.test(event.key)) {
            reset();
            return;
        }
        if (!startedAt || now - lastKeyAt > MAX_GAP_MS) {
            buffer = '';
            startedAt = now;
        }
        buffer += event.key;
        lastKeyAt = now;
        if (buffer.length > 64) reset();
    }, true);
})();

