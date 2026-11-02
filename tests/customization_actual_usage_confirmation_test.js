const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');

const page = fs.readFileSync(path.join(__dirname, '..', 'staff', 'customizations.php'), 'utf8');
const start = page.indexOf('            handlePendingMaterialQtyInput(material, value) {');
const end = page.indexOf('            isCurrentTarpaulinService() {', start);
assert(start >= 0 && end > start, 'Actual Usage methods are present in the staff page.');
const methods = new Function(`return ({${page.slice(start, end)}\n});`)();
const validationStart = page.indexOf('            validateProductionAssignments(extraMaterials = [], extraInks = []) {');
const validationEnd = page.indexOf('            hasProductionAssignments(extraMaterials = [], extraInks = []) {', validationStart);
assert(validationStart >= 0 && validationEnd > validationStart, 'Production assignment validator is present in the staff page.');
const validationMethod = new Function(`return ({${page.slice(validationStart, validationEnd)}\n});`)().validateProductionAssignments;

function context(error) {
    return {
        productionErrors: { material: error },
        clearPendingMaterialConfirmationError: methods.clearPendingMaterialConfirmationError
    };
}

const confirmationError = 'Confirm the actual usage for Pp Stkr Matte 98 before saving.';
const item = {
    name: 'Pp Stkr Matte 98',
    uom: 'pcs',
    qty: 1,
    estimated_qty: null,
    usage_method: 'manual',
    manual_confirmation_required: true,
    manual_quantity_confirmed: false,
    metadata: { material_usage: { staff_confirmed: false } }
};

assert.equal(item.manual_quantity_confirmed, false, 'A prefilled quantity alone is not confirmation.');

const inputContext = context(confirmationError);
methods.handlePendingMaterialQtyInput.call(inputContext, item, '1');
assert.equal(item.manual_quantity_confirmed, true, 'A valid edited actual quantity confirms usage.');
assert.equal(item.metadata.material_usage.actual, 1, 'The entered quantity is retained without conversion.');
assert.equal(item.metadata.material_usage.staff_confirmed, true, 'Confirmation metadata follows the state.');
assert.equal(inputContext.productionErrors.material, '', 'Editing valid usage clears its stale confirmation warning.');

const invalidItem = { ...item, metadata: {}, manual_quantity_confirmed: true };
const invalidContext = context(confirmationError);
methods.handlePendingMaterialQtyInput.call(invalidContext, invalidItem, '0');
assert.equal(invalidItem.manual_quantity_confirmed, false, 'Zero usage cannot confirm a manual quantity.');
assert.equal(invalidItem.metadata.material_usage.staff_confirmed, false, 'Invalid usage is not marked staff-confirmed.');
assert.equal(invalidContext.productionErrors.material, confirmationError, 'Invalid input retains the confirmation warning.');

const negativeItem = { ...item, metadata: {}, manual_quantity_confirmed: true };
methods.handlePendingMaterialQtyInput.call(context(confirmationError), negativeItem, '-1');
assert.equal(negativeItem.manual_quantity_confirmed, false, 'Negative usage cannot confirm a manual quantity.');
assert.equal(negativeItem.metadata.material_usage.staff_confirmed, false, 'Negative usage is not marked staff-confirmed.');

const fractionalPieces = { ...item, metadata: {}, manual_quantity_confirmed: false };
methods.handlePendingMaterialQtyInput.call(context(''), fractionalPieces, '1.5');
assert.equal(fractionalPieces.manual_quantity_confirmed, false, 'Fractional pieces remain invalid.');

const fractionalFeet = { ...item, uom: 'ft', metadata: {}, manual_quantity_confirmed: false };
methods.handlePendingMaterialQtyInput.call(context(''), fractionalFeet, '1.5');
assert.equal(fractionalFeet.manual_quantity_confirmed, true, 'Fractional length usage remains valid.');

function validateEstimatedMaterial(material) {
    const validatorContext = {
        currentJo: { materials: [], ink_usage: [] },
        pendingMaterials: [],
        productionErrors: {},
        requiresInk: false,
        posMaterialQuantityError: () => ''
    };
    return validationMethod.call(validatorContext, [material], []);
}

for (const uom of ['pcs', 'pc', 'piece', 'pieces']) {
    const estimatedMaterial = {
        ...item,
        uom,
        qty: 1.5,
        estimated_qty: 1,
        manual_confirmation_required: false,
        manual_quantity_confirmed: false
    };
    assert.equal(validateEstimatedMaterial(estimatedMaterial), false, `Final assignment validator rejects fractional estimated ${uom}.`);
    for (const qty of [0, -1, Number.NaN, Number.POSITIVE_INFINITY]) {
        assert.equal(validateEstimatedMaterial({ ...estimatedMaterial, qty }), false, `Final assignment validator rejects ${qty} for ${uom}.`);
    }
}

assert.equal(validateEstimatedMaterial({ ...fractionalFeet, estimated_qty: 1, manual_confirmation_required: false }), true,
    'Final assignment validator permits fractional feet when otherwise valid.');

const unrelatedErrorContext = context('Insufficient stock for this material.');
methods.handlePendingMaterialQtyInput.call(unrelatedErrorContext, { ...item, metadata: {} }, '2');
assert.equal(unrelatedErrorContext.productionErrors.material, 'Insufficient stock for this material.', 'Unrelated validation errors are preserved.');

console.log('customization_actual_usage_confirmation_test: PASS');
