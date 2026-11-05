'use strict';

const assert = require('assert');
const fs = require('fs');
const path = require('path');

const source = fs.readFileSync(path.join(__dirname, '../staff/customizations.php'), 'utf8');

function extractObjectMethod(startMarker, endMarker) {
    const start = source.indexOf(startMarker);
    assert.notStrictEqual(start, -1, `missing source method: ${startMarker}`);
    const end = source.indexOf(endMarker, start);
    assert.notStrictEqual(end, -1, `missing end marker: ${endMarker}`);
    return source.slice(start, end).trim().replace(/,$/, '');
}

const overrideMethod = extractObjectMethod(
    'applyPosSetPriceDeepLinkOverride(row) {',
    'get requiresInk() {'
);
const loadAssignmentsMethod = extractObjectMethod(
    'async loadModalAssignments(orderId, cacheKey, requestToken) {',
    'closeDetailsModal() {'
);
const methods = new Function(`return ({${overrideMethod}, ${loadAssignmentsMethod}});`)();

async function run({sourceOrderId = '28760', pricingMode = true} = {}) {
    const responseData = {
        id: 20376,
        job_order_id: 20376,
        order_id: 28760,
        order_source: 'pos_draft',
        status: 'Draft',
        order_type: 'ORDER',
        items: [],
        materials: []
    };
    const instance = {
        currentJo: { ...responseData, status: 'APPROVED' },
        loadingModalAssignments: false,
        detailRequestToken: 1,
        deepLinkExpectedStatus: 'APPROVED',
        deepLinkSourceOrderId: sourceOrderId,
        isPosPricingMode: () => pricingMode,
        fetchOrderModalSummary: async () => ({ success: true, data: responseData }),
        applyStaffOrderDetailMerge: (previous, incoming) => ({ ...previous, ...incoming }),
        normalizeCustomerType: (value) => value || 'Walk-in',
        restoreSavedInkUsage() {},
        maybeApplyAutoMaterialSuggestion() {},
        modalCache: {},
        modalCacheLoadedAt: {},
        ...methods
    };
    await methods.loadModalAssignments.call(instance, 28760, 'ORDER-28760', 1);
    return { instance, responseData };
}

(async () => {
    const exactDraft = await run();
    assert.strictEqual(exactDraft.responseData.status, 'Draft', 'the server response remains in its persisted Draft state');
    assert.strictEqual(exactDraft.instance.currentJo.status, 'APPROVED', 'the exact POS Set Price view survives the second ensure_job response');
    assert.strictEqual(exactDraft.instance.modalCache['ORDER-28760'].status, 'APPROVED', 'the modal cache retains the Set Price presentation state');

    const mismatchedDraft = await run({ sourceOrderId: '99999' });
    assert.strictEqual(mismatchedDraft.instance.currentJo.status, 'Draft', 'a mismatched order ID is never given the Set Price presentation override');

    const nonPricingView = await run({ pricingMode: false });
    assert.strictEqual(nonPricingView.instance.currentJo.status, 'Draft', 'ordinary production views retain the actual Draft status');

    console.log('POS Set Price deep-link regression tests passed.');
})().catch((error) => {
    console.error(error);
    process.exitCode = 1;
});
