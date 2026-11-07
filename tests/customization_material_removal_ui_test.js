'use strict';

const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');

const page = fs.readFileSync(path.join(__dirname, '..', 'staff', 'customizations.php'), 'utf8');
const scripts = [...page.matchAll(/<script>([\s\S]*?)<\/script>/g)];
assert(scripts.length >= 2, 'Custom Orders main script exists');
const script = scripts[1][1].replace(/<\?php[\s\S]*?\?>/g, 'null');

function extractMethod(name, args) {
    const marker = `${name}(${args}) {`;
    const start = script.indexOf(marker);
    assert(start >= 0, `${name} exists`);
    const open = script.indexOf('{', start + marker.length - 1);
    let depth = 0;
    for (let i = open; i < script.length; i += 1) {
        if (script[i] === '{') depth += 1;
        if (script[i] === '}') depth -= 1;
        if (depth === 0) return script.slice(start, i + 1);
    }
    throw new Error(`${name} method is unclosed`);
}

function compileMethod(name, args, asyncMethod = false) {
    const source = extractMethod(name, args);
    const signature = `${name}(${args}) {`;
    assert(source.startsWith(signature), `${name} signature matches the rendered source`);
    return new Function(`return (${asyncMethod ? 'async ' : ''}function(${args}) ${source.slice(signature.length - 1)});`)();
}

const removeMaterial = compileMethod('removeMaterial', 'jomId', true);
const isMaterialSelected = compileMethod('isMaterialSelected', 'itemId');
const selectMaterialCandidate = compileMethod('selectMaterialCandidate', 'item, deliberateKeyboardAction = false');
const refreshMaterials = compileMethod('refreshMaterials', '', true);
const applyPosSetPriceDeepLinkOverride = compileMethod('applyPosSetPriceDeepLinkOverride', 'row');

assert.doesNotMatch(page, /Edit Actual Usage|canReopenMaterialUsage|reopenAssignedMaterialUsage/,
    'The order-details assigned-material rows do not expose the Edit Actual Usage action or its dedicated handler.');
assert.match(page, /x-for="m in groupedMaterials"[\s\S]*?x-text="m\.item_name"[\s\S]*?x-text="Number\(m\.quantity\) \+ ' ' \+ \(m\.uom \|\| 'inventory units'\)"[\s\S]*?<span>Actual usage<\/span>/,
    'Assigned material rows continue to show the material name, recorded quantity, inventory unit, and actual-usage label.');
assert.match(page, /Actual Usage:[\s\S]*?:value="pm\.qty"[\s\S]*?x-text="pm\.uom"/,
    'Queued materials expose one Actual Usage field in the material inventory unit.');
assert.doesNotMatch(page, /Linear Ft:/, 'Legacy Linear Ft pending-material controls stay removed in favor of Actual Usage.');

async function runSuccessCase(itemId, itemName) {
    const assigned = { id: 900 + itemId, item_id: itemId, item_name: itemName, deducted_at: null };
    const ctx = {
        currentJo: { order_id: 1234, job_order_id: 2345, order_type: 'JOB', materials: [assigned] },
        newMaterialId: String(itemId),
        pendingMaterials: [],
        jobPriceInput: '400.00',
        showDetailsModal: true,
        assignmentActionFeedback: '',
        productionErrors: { material: '' },
        actionBusy: false,
        refreshed: 0,
        alerts: [],
        selectedAgain: [],
        queuedAgain: 0,
        beginModalAction() { this.actionBusy = true; return true; },
        endModalAction() { this.actionBusy = false; },
        showStaffAlert(title, message) { this.alerts.push({ title, message }); },
        async refreshMaterials() { this.refreshed += 1; return true; },
        handleMaterialSelection(id) { this.selectedAgain.push(String(id)); },
        syncInkSelectionWithMaterial() {},
        addMaterialToQueue() { this.queuedAgain += 1; },
        queueMaterialFromPicker(item) {
            this.selectedAgain.push(String(item.id));
            this.queuedAgain += 1;
            this.pendingMaterials.push({ item_id: item.id, qty: '', manual_quantity_confirmed: false });
            this.materialListExpanded = true;
        },
        materialClassification() { return { selectable: true, directSelectable: true }; },
        removePendingMaterial() {},
        isMaterialSelected,
    };
    global.window = { confirm: message => message.includes(itemName) };
    global.document = { body: { getAttribute: name => name === 'data-csrf' ? 'test-csrf' : '' } };
    global.fetch = async (_url, options) => {
        assert.equal(options.method, 'POST');
        assert.equal(options.credentials, 'same-origin');
        assert.equal(options.body.get('action'), 'remove_material');
        assert.equal(options.body.get('id'), String(assigned.id));
        assert.equal(options.body.get('csrf_token'), 'test-csrf', 'Removal request retains the page CSRF token');
        return { ok: true, async json() { return { success: true, message: 'Correction recorded.' }; } };
    };

    await removeMaterial.call(ctx, assigned.id);
    assert.equal(ctx.refreshed, 1, `${itemName} removal refreshes the assignment list`);
    assert.deepEqual(ctx.currentJo.materials, [], `${itemName} assignment is pruned from UI state after API success`);
    assert.equal(ctx.newMaterialId, '', `${itemName} stale picker value is cleared`);
    assert.equal(isMaterialSelected.call(ctx, itemId), false, `${itemName} is no longer marked assigned`);
    assert.match(ctx.assignmentActionFeedback, /correction recorded/i);
    assert.equal(ctx.alerts.length, 0, 'A successful correction uses inline feedback instead of an overlay popup');
    assert.equal(ctx.showDetailsModal, true, 'Removal keeps the current Set Price/Production Assignment modal open');
    assert.equal(ctx.jobPriceInput, '400.00', 'Removal preserves the entered price');

    selectMaterialCandidate.call(ctx, { id: itemId, compatibility: { selectable: true, directSelectable: true } });
    assert.deepEqual(ctx.selectedAgain, [String(itemId)], `${itemName} can be selected again after correction`);
    assert.equal(ctx.queuedAgain, 1, `${itemName} reselection one-click queues the material again`);
    assert.equal(ctx.pendingMaterials.length, 1, `${itemName} appears in the pending assignment list after reselection`);
}

async function runRejectedCase(reason) {
    const assigned = { id: 990, item_id: 206, item_name: 'Sintra 3mm', deducted_at: null };
    const ctx = {
        currentJo: { materials: [assigned] }, newMaterialId: '', actionBusy: false, refreshed: 0, alerts: [],
        beginModalAction() { this.actionBusy = true; return true; },
        endModalAction() { this.actionBusy = false; },
        showStaffAlert(title, message) { this.alerts.push({ title, message }); },
        async refreshMaterials() { this.refreshed += 1; return true; },
    };
    global.window = { confirm: () => true };
    global.document = { body: { getAttribute: name => name === 'data-csrf' ? 'test-csrf' : '' } };
    global.fetch = async () => ({ ok: false, async json() { return { success: false, error: reason }; } });
    await removeMaterial.call(ctx, assigned.id);
    assert.deepEqual(ctx.currentJo.materials, [assigned], 'Rejected removal keeps the assignment visible and selected');
    assert.equal(ctx.refreshed, 0, 'Rejected removal does not refresh as if successful');
    assert.equal(ctx.alerts[0]?.title, 'Material Not Changed');
    assert.equal(ctx.alerts[0]?.message, reason);
}

async function runRefreshFailureCase() {
    const assigned = { id: 998, item_id: 207, item_name: 'Sintra 5mm', deducted_at: null };
    const ctx = {
        currentJo: { materials: [assigned] }, newMaterialId: '', pendingMaterials: [], actionBusy: false, alerts: [],
        beginModalAction() { this.actionBusy = true; return true; },
        endModalAction() { this.actionBusy = false; },
        showStaffAlert(title, message) { this.alerts.push({ title, message }); },
        async refreshMaterials() { throw new Error('Refresh unavailable'); },
    };
    global.window = { confirm: () => true };
    global.document = { body: { getAttribute: () => 'test-csrf' } };
    global.fetch = async () => ({ ok: true, async json() { return { success: true }; } });
    await removeMaterial.call(ctx, assigned.id);
    assert.deepEqual(ctx.currentJo.materials, [], 'Successful removal clears stale selection even when the refresh request fails');
    assert.match(ctx.assignmentActionFeedback, /Reload the order/);
    assert.equal(ctx.alerts.length, 0, 'A refresh warning after confirmed removal stays inline');
    assert.equal(isMaterialSelected.call(ctx, 207), false, 'A refresh failure does not leave a stale assigned badge');
}

async function runPosDraftRefreshCase() {
    const jobPriceInput = '400.00';
    const pending = [{ item_id: 207, qty: 2 }];
    const ctx = {
        currentJo: {
            id: 1234, order_id: 1234, job_order_id: 2345, order_type: 'ORDER',
            order_source: 'pos_draft', status: 'APPROVED', materials: [],
        },
        detailContextMode: 'pos_pricing',
        deepLinkExpectedStatus: 'APPROVED',
        deepLinkSourceOrderId: '1234',
        jobPriceInput,
        pendingMaterials: pending,
        isPosPricingMode() { return this.detailContextMode === 'pos_pricing'; },
        resolveEffectiveJobId: async () => 2345,
        async fetchOrderModalSummary(orderId, options) {
            assert.equal(String(orderId), '1234');
            assert.equal(options.includeAssignments, true);
            assert.equal(options.ensureJob, true);
            return { success: true, data: { order_id: 1234, order_source: 'pos_draft', order_type: 'ORDER', status: 'DRAFT', materials: [] } };
        },
        applyStaffOrderDetailMerge(previous, incoming) { return { ...previous, ...incoming }; },
        applyPosSetPriceDeepLinkOverride,
        loadAvailableRolls() {},
    };

    const refreshed = await refreshMaterials.call(ctx);
    assert.equal(refreshed, true);
    assert.equal(ctx.currentJo.status, 'APPROVED', 'A POS draft refresh restores only the Set Price presentation stage');
    assert.equal(ctx.currentJo.order_source, 'pos_draft');
    assert.equal(ctx.jobPriceInput, jobPriceInput, 'Entered Set Price value survives assignment refresh');
    assert.equal(ctx.pendingMaterials, pending, 'Other in-progress assignment form state survives refresh');
}

(async () => {
    await runSuccessCase(206, 'Sintra 3mm');
    await runSuccessCase(207, 'Sintra 5mm');
    await runSuccessCase(212, 'C2S Board');
    await runRejectedCase('This material has already been marked as deducted.');
    await runRejectedCase('Materials can only be corrected before production starts.');
    await runRefreshFailureCase();
    await runPosDraftRefreshCase();
    console.log('Custom Orders material removal/reselection and POS Set Price refresh tests passed for Sintra 3mm, Sintra 5mm, and protected removals.');
})().catch(error => {
    console.error(error);
    process.exitCode = 1;
});
