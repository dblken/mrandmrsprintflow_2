const fs = require('fs');
const vm = require('vm');
const path = require('path');

const scannerSource = fs.readFileSync(path.join(__dirname, '..', 'public', 'assets', 'js', 'receipt-scanner.js'), 'utf8');

function assert(condition, message) {
    if (!condition) throw new Error('FAIL: ' + message);
    process.stdout.write('PASS: ' + message + '\n');
}

function storage() {
    const values = new Map();
    return {
        getItem(key) { return values.has(key) ? values.get(key) : null; },
        setItem(key, value) { values.set(key, String(value)); },
        removeItem(key) { values.delete(key); }
    };
}

function createRuntime(fetchImpl) {
    const listeners = {};
    const nodes = {};
    const body = { tagName: 'BODY', isContentEditable: false };
    const document = {
        body,
        currentScript: { dataset: { basePath: '' } },
        addEventListener(type, handler) { (listeners[type] ||= []).push(handler); },
        getElementById(id) { return nodes[id] || null; },
        createElement() {
            return {
                style: {},
                setAttribute() {},
                set id(value) { this._id = value; nodes[value] = this; },
                get id() { return this._id; }
            };
        }
    };
    body.appendChild = function (node) { if (node.id) nodes[node.id] = node; };
    const assigned = [];
    const window = {
        document,
        location: { search: '', pathname: '/staff/dashboard.php', assign(route) { assigned.push(route); } },
        localStorage: storage(), sessionStorage: storage(), console,
        setTimeout, clearTimeout, URLSearchParams, Event
    };
    const context = vm.createContext({
        window, document, fetch: fetchImpl, AbortController, URLSearchParams, Event,
        console, setTimeout, clearTimeout, Date, Error, TypeError, Promise, JSON, Number, String
    });
    vm.runInContext(scannerSource, context, { filename: 'receipt-scanner.js' });

    function key(key, target = body) {
        const event = {
            key, target, defaultPrevented: false, ctrlKey: false, altKey: false, metaKey: false,
            preventDefault() { this.defaultPrevented = true; },
            stopImmediatePropagation() { this.stopped = true; }
        };
        (listeners.keydown || []).forEach(handler => handler(event));
        return event;
    }
    return { window, body, key, assigned };
}

function successResponse(orderId = 11280, source = 'pos') {
    return {
        ok: true, status: 200,
        async text() {
            return JSON.stringify({
                success: true, order_id: orderId, identifier: 'POS-011280', source,
                route: '/staff/orders.php?order_id=' + orderId, request_id: 'test-request'
            });
        }
    };
}

function emit(runtime, text, target = runtime.body, terminator = 'Enter', shiftedColons = true) {
    for (const character of text) {
        if (shiftedColons && character === ':') runtime.key('Shift', target);
        runtime.key(character, target);
    }
    return runtime.key(terminator, target);
}

(async function run() {
    let calls = 0;
    const runtime = createRuntime(async () => { calls++; return successResponse(); });
    const enter = emit(runtime, 'PF1:ORDER:11280');
    await new Promise(resolve => setTimeout(resolve, 0));
    assert(calls === 1, 'shifted colon key events do not discard a valid scan');
    assert(enter.defaultPrevented && enter.stopped, 'a completed receipt scan consumes its terminator');
    assert(runtime.assigned[0] === '/staff/orders.php?order_id=11280', 'successful lookup follows the backend route');
    runtime.key('Enter');
    await new Promise(resolve => setTimeout(resolve, 0));
    assert(calls === 1, 'CRLF or duplicate Enter creates only one lookup');

    let invalidCalls = 0;
    const invalid = createRuntime(async () => { invalidCalls++; return successResponse(); });
    emit(invalid, 'ordinary keyboard typing');
    await new Promise(resolve => setTimeout(resolve, 0));
    assert(invalidCalls === 0, 'ordinary keyboard typing does not trigger receipt lookup');

    let tabCalls = 0;
    const tabRuntime = createRuntime(async () => { tabCalls++; return successResponse(); });
    const tab = emit(tabRuntime, ']PF1:ORDER:11280;', tabRuntime.body, 'Tab');
    await new Promise(resolve => setTimeout(resolve, 0));
    assert(tabCalls === 1 && tab.defaultPrevented, 'scanner prefix/suffix and Tab termination are accepted once');

    let inputCalls = 0;
    const inputRuntime = createRuntime(async () => { inputCalls++; return successResponse(); });
    const input = {
        tagName: 'INPUT', type: 'search', id: 'order-search', value: 'keep me',
        selectionStart: 7, selectionEnd: 7, isContentEditable: false,
        closest() { return null; }, setSelectionRange(start, end) { this.selectionStart = start; this.selectionEnd = end; },
        dispatchEvent() {}
    };
    emit(inputRuntime, 'PF1:ORDER:11280', input);
    await new Promise(resolve => setTimeout(resolve, 0));
    assert(inputCalls === 1 && input.value === 'keep me', 'receipt scan works over an unrelated text field and restores its value');

    let productCalls = 0;
    const productRuntime = createRuntime(async () => { productCalls++; return successResponse(); });
    const productInput = {
        tagName: 'INPUT', type: 'text', id: 'pos-barcode-input', value: '', isContentEditable: false,
        closest(selector) { return selector === '.pos-barcode-entry' ? this : null; }
    };
    emit(productRuntime, 'SNB-0005', productInput);
    await new Promise(resolve => setTimeout(resolve, 0));
    assert(productCalls === 0, 'ordinary POS product barcode input does not trigger receipt lookup');

    emit(productRuntime, 'PF1:ORDER:11280', productInput);
    await new Promise(resolve => setTimeout(resolve, 0));
    assert(productCalls === 1, 'canonical receipt input in the focused POS barcode field uses the shared lookup path');

    let retryCalls = 0;
    const retryRuntime = createRuntime(async () => {
        retryCalls++;
        if (retryCalls === 1) throw new TypeError('temporary network failure');
        return successResponse();
    });
    emit(retryRuntime, 'PF1:ORDER:11280');
    await new Promise(resolve => setTimeout(resolve, 240));
    assert(retryCalls === 2 && retryRuntime.assigned.length === 1, 'one transient network failure is retried without duplicate navigation');

    for (let index = 0; index < 10; index++) {
        let repeatedCalls = 0;
        const repeated = createRuntime(async () => { repeatedCalls++; return successResponse(); });
        emit(repeated, 'PF1:ORDER:11280');
        await new Promise(resolve => setTimeout(resolve, 0));
        assert(repeatedCalls === 1 && repeated.assigned.length === 1, 'repeated scan simulation ' + (index + 1) + '/10 resolves the same order once');
    }
})();
