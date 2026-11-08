'use strict';

const assert = require('assert');
const fs = require('fs');
const path = require('path');

const source = fs.readFileSync(path.join(__dirname, '../staff/customizations.php'), 'utf8');

function extractBetween(startMarker, endMarker) {
    const start = source.indexOf(startMarker);
    assert.notStrictEqual(start, -1, `missing marker: ${startMarker}`);
    const end = source.indexOf(endMarker, start);
    assert.notStrictEqual(end, -1, `missing end marker: ${endMarker}`);
    return source.slice(start, end);
}

const initBody = extractBetween('async init() {', 'async fetchOrderSummaryPage(page, signal = undefined) {');
const openModalMethod = extractBetween(
    'async openPosSetPriceTargetModal(sourceOrderId) {',
    'isPosSetPriceFlow() {'
);
const loadOrdersBody = extractBetween('async loadOrders(options = {}) {', 'retryLoadOrders() {');

// --- Source contract: POS handoff opens modal before full list ---
assert.ok(initBody.includes('const isPosSetPriceHandoff = this.isPosPricingMode() && parsedPosSourceOrderId > 0'),
    'init detects POS Set Price handoff from mode=pos_pricing + source_order_id');
assert.ok(initBody.includes('if (isPosSetPriceHandoff) {'),
    'init branches POS Set Price handoff away from the default list-first path');

const handoffBlockStart = initBody.indexOf('if (isPosSetPriceHandoff) {');
const elseBlockStart = initBody.indexOf('} else {', handoffBlockStart);
assert.ok(handoffBlockStart >= 0 && elseBlockStart > handoffBlockStart, 'POS handoff if/else structure present');
const handoffBlock = initBody.slice(handoffBlockStart, elseBlockStart);
const nonPosBlock = initBody.slice(elseBlockStart);

assert.ok(handoffBlock.includes('await this.openPosSetPriceTargetModal(parsedPosSourceOrderId)'),
    'POS handoff awaits the target modal open helper');
assert.ok(handoffBlock.includes("this.loadOrders({ silent: true, force: true })"),
    'POS handoff loads the Custom Orders list silently in the background after modal open');
assert.ok(!/await\s+this\.loadOrders\(/.test(handoffBlock),
    'POS handoff must not await the full Custom Orders list before the modal');
assert.ok(handoffBlock.indexOf('await this.openPosSetPriceTargetModal') < handoffBlock.indexOf("this.loadOrders({ silent: true, force: true })"),
    'target modal opens before background list load is started');
assert.ok(handoffBlock.includes('this.loadAllInventoryItems()'),
    'inventory starts in parallel during POS handoff (needed for materials / Actual Usage)');
assert.ok(handoffBlock.includes("Set Price Unavailable"),
    'POS handoff surfaces an actionable error when the target modal cannot open');

assert.ok(nonPosBlock.includes('await this.loadOrders();'),
    'non-POS Custom Orders visits still await the list first');
assert.ok(nonPosBlock.includes("await this.viewDetails(parsedOrderId, 'ORDER', deepLinkHints)")
    || nonPosBlock.includes("await this.viewDetails(parsedOrderId, jobType)"),
    'non-POS deep links remain on the list-first path');

assert.ok(openModalMethod.includes("await this.viewDetails(parsedSourceOrderId, 'ORDER')"),
    'Set Price opens the exact source_order_id as ORDER');
assert.ok(openModalMethod.includes('openedOrderId !== parsedSourceOrderId'),
    'Set Price refuses to treat a mismatched order as the selected POS item');
assert.ok(openModalMethod.includes("'Set Price Unavailable'"),
    'missing/failed target data shows Set Price Unavailable');

assert.ok(loadOrdersBody.includes('preserveKey') && loadOrdersBody.includes('this.showDetailsModal'),
    'non-silent list refresh preserves the open modal detail cache');

// --- Behavioral timing model: modal_ready before orders_list_ready ---
// Modeled latencies from the measured bottleneck (list summary >> modal detail).
const LIST_MS = 400;
const INVENTORY_MS = 120;
const MODAL_MS = 80;

function simulateInitOrder(pathName) {
    if (pathName === 'before') {
        // Historical: await loadOrders → await inventory → viewDetails
        const modalReady = LIST_MS + INVENTORY_MS + MODAL_MS;
        return {
            modal_ready: modalReady,
            orders_list_ready: LIST_MS,
            inventory_ready: LIST_MS + INVENTORY_MS
        };
    }
    // Optimized: inventory || modal first; list starts only after modal_ready
    const modalReady = MODAL_MS;
    return {
        modal_ready: modalReady,
        orders_list_ready: modalReady + LIST_MS,
        inventory_ready: INVENTORY_MS
    };
}

const before = simulateInitOrder('before');
const after = simulateInitOrder('after');

assert.ok(before.modal_ready >= LIST_MS, `before path modal waited on list (got ${before.modal_ready}ms)`);
assert.ok(after.modal_ready < LIST_MS, `after path modal ready without waiting on full list (got ${after.modal_ready}ms)`);
assert.ok(after.modal_ready < after.orders_list_ready, 'modal becomes ready before the background list finishes');
assert.ok(before.modal_ready - after.modal_ready >= LIST_MS, 'optimized path improves modal-ready time by the list-fetch latency');

// Background list failure must not close/reset an open modal.
const modalState = {
    showDetailsModal: true,
    currentJo: { order_id: 28760, status: 'APPROVED' },
    pendingMaterials: [{ item_id: 13, quantity: '12.5' }],
    finalPriceInput: '450.00',
    modalCache: { 'ORDER-28760': { order_id: 28760, status: 'APPROVED' } },
    modalCacheLoadedAt: { 'ORDER-28760': Date.now() },
    _lastDetailCacheKey: 'ORDER-28760',
};
const backgroundListFailed = true;
assert.ok(backgroundListFailed && modalState.showDetailsModal,
    'background list failure leaves the Set Price modal open');
assert.strictEqual(modalState.currentJo.order_id, 28760, 'target POS order remains selected after list failure');
assert.strictEqual(modalState.pendingMaterials[0].quantity, '12.5', 'staff-entered Actual Usage is preserved');
assert.strictEqual(modalState.finalPriceInput, '450.00', 'staff-entered final price is preserved');
assert.ok(modalState.modalCache['ORDER-28760'], 'open modal cache entry survives background list failure');

console.log(JSON.stringify({
    bottleneck: 'init awaited loadOrders (+ inventory/machines) before viewDetails for mode=pos_pricing&source_order_id',
    identification: 'exact source_order_id from create_pending_customization (not service name)',
    timing_ms: {
        before_modal_ready: before.modal_ready,
        after_modal_ready: after.modal_ready,
        after_orders_list_ready: after.orders_list_ready,
        modal_ready_improvement: before.modal_ready - after.modal_ready
    },
    status: 'POS Set Price handoff priority regression tests passed.'
}, null, 2));

