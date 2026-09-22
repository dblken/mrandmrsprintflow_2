'use strict';

const assert = require('assert');

function buildOrderListItems(rows, pageStart, activeStatus, orderIsUrgentRequest) {
    const items = [];
    let prevUrgent = null;
    const safeRows = Array.isArray(rows) ? rows : [];
    safeRows.forEach((jo, index) => {
        if (!jo || typeof jo !== 'object') {
            return;
        }
        const isUrgent = activeStatus === 'ALL' && orderIsUrgentRequest(jo);
        if (isUrgent && prevUrgent !== true) {
            items.push({
                kind: 'section',
                key: `section-urgent-${pageStart + index}`,
                label: 'Urgent Orders',
                jo: null
            });
        }
        if (!isUrgent && prevUrgent === true) {
            items.push({
                kind: 'section',
                key: `section-all-${pageStart + index}`,
                label: 'All Orders',
                jo: null
            });
        }
        items.push({
            kind: 'row',
            key: `row-${jo.order_type || 'JOB'}-${jo.id}`,
            jo
        });
        prevUrgent = isUrgent;
    });
    return items;
}

function isValidOrderListRow(item) {
    return !!(item && item.kind === 'row' && item.jo && typeof item.jo === 'object');
}

function sanitizeOrderListItems(items) {
    if (!Array.isArray(items)) return [];
    return items.filter((item) => {
        if (!item || typeof item !== 'object' || !item.key) return false;
        if (item.kind === 'section') {
            return typeof item.label === 'string' && item.label !== '';
        }
        return isValidOrderListRow(item);
    });
}

const urgent = { id: 1, order_type: 'JOB', urgent: true };
const regular = { id: 2, order_type: 'JOB', urgent: false };
const isUrgent = (jo) => !!jo.urgent;

const built = buildOrderListItems([urgent, regular, null, regular], 0, 'ALL', isUrgent);
const sanitized = sanitizeOrderListItems(built);

assert.strictEqual(sanitized.length, 5, 'urgent section + urgent row + all section + 2 regular rows');
assert.strictEqual(sanitized[0].kind, 'section');
assert.strictEqual(sanitized[0].jo, null);
assert.strictEqual(sanitized[1].kind, 'row');
assert.ok(isValidOrderListRow(sanitized[1]));
assert.strictEqual(sanitized[1].jo.id, 1);
assert.strictEqual(sanitized[2].label, 'All Orders');
assert.strictEqual(sanitized[3].jo.id, 2);

const inquiryOnly = sanitizeOrderListItems(buildOrderListItems([urgent, regular], 0, 'INQUIRY', isUrgent));
assert.strictEqual(inquiryOnly.length, 2);
assert.ok(inquiryOnly.every(isValidOrderListRow));

console.log('Customization order list items test passed.');
