const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const materialConsumption = require('../public/assets/js/material_consumption_estimator.js');
const { estimate: estimateMaterialConsumption } = materialConsumption;

// The extracted production-assignment validator normally runs in a browser.
// Provide the optional global resolver as absent so the Node harness exercises
// the same local validation path without requiring a DOM.
globalThis.window = { PrintFlowProductionMaterials: null, PrintFlowMaterialConsumption: materialConsumption };

const page = fs.readFileSync(path.join(__dirname, '..', 'staff', 'customizations.php'), 'utf8');
const inputBindingMatch = page.match(/@input="([^"]*handlePendingMaterialQtyInput\(pm, \$event\.target\.value, \$event\.target, \$event\)[^"]*)"/);
assert(inputBindingMatch, 'Rendered Actual Usage input binds the displayed pm row, browser value, and input element.');
const actualUsageInputBinding = new Function('pm', '$event', 'handlePendingMaterialQtyInput', `${inputBindingMatch[1]};`);
const beforeInputBindingMatch = page.match(/@beforeinput="([^"]*markPendingMaterialQtyEdit\(pm, \$event\.target, \$event\)[^"]*)"/);
const keydownBindingMatch = page.match(/@keydown="([^"]*markPendingMaterialQtyKeyEdit\(pm, \$event\.target, \$event\)[^"]*)"/);
const keyupBindingMatch = page.match(/@keyup="([^"]*clearPendingMaterialQtyKeyEdit\(\$event\.target\)[^"]*)"/);
const pointerdownBindingMatch = page.match(/@pointerdown="([^"]*markPendingMaterialQtyPointerEdit\(pm, \$event\.target, \$event\)[^"]*)"/);
const blurBindingMatch = page.match(/@blur="([^"]*handlePendingMaterialQtyBlur\(pm, \$event\.target, \$event\)[^"]*)"/);
assert(beforeInputBindingMatch && keydownBindingMatch && keyupBindingMatch && pointerdownBindingMatch && blurBindingMatch, 'Rendered input tracks trusted keyboard, mouse, and same-value edit intent.');
const beforeInputBinding = new Function('pm', '$event', 'markPendingMaterialQtyEdit', `${beforeInputBindingMatch[1]};`);
const keydownBinding = new Function('pm', '$event', 'markPendingMaterialQtyKeyEdit', `${keydownBindingMatch[1]};`);
const keyupBinding = new Function('pm', '$event', 'clearPendingMaterialQtyKeyEdit', `${keyupBindingMatch[1]};`);
const pointerdownBinding = new Function('pm', '$event', 'markPendingMaterialQtyPointerEdit', `${pointerdownBindingMatch[1]};`);
const blurBinding = new Function('pm', '$event', 'handlePendingMaterialQtyBlur', `${blurBindingMatch[1]};`);
const start = page.indexOf('            markPendingMaterialQtyEdit(material, input, event) {');
const end = page.indexOf('            tarpaulinCalculationForItem(itemId', start);
assert(start >= 0 && end > start, 'Actual Usage methods are present in the staff page.');
const methods = new Function(`return ({${page.slice(start, end)}\n});`)();
const validationStart = page.indexOf('            validateProductionAssignments(extraMaterials = [], extraInks = []) {');
const validationEnd = page.indexOf('            hasProductionAssignments(extraMaterials = [], extraInks = []) {', validationStart);
assert(validationStart >= 0 && validationEnd > validationStart, 'Production assignment validator is present in the staff page.');
const validationMethod = new Function(`return ({${page.slice(validationStart, validationEnd)}\n});`)().validateProductionAssignments;
const feedbackStart = page.indexOf('            setProductionValidationFeedback() {');
const feedbackEnd = page.indexOf('            hasProductionAssignments(extraMaterials = [], extraInks = []) {', feedbackStart);
assert(feedbackStart >= 0 && feedbackEnd > feedbackStart, 'Production validation feedback method is present in the staff page.');
const feedbackMethods = new Function(`return ({${page.slice(feedbackStart, feedbackEnd)}\n});`)();
assert.equal((page.match(/this\.setProductionValidationFeedback\(\);/g) || []).length, 2,
    'Both Set Final Price submission branches route validation feedback through the deduplicating helper.');
assert.equal((page.match(/x-show="productionErrors\.material"/g) || []).length, 0,
    'Generic material errors are not duplicated via an ungated section x-show.');
assert.match(page, /x-show="showProductionMaterialSectionError\(\)"/, 'Section material errors use the deduplicated renderer.');
assert.match(
  page,
  /template x-if="productionAssignmentSubmitAttempted === true && showPendingMaterialUsageError\(pm\)"[\s\S]{0,200}Enter a valid actual quantity before saving\./,
  'Field-level Actual Usage validation copy is rendered once per material row.'
);

function context(error, material = null) {
    return {
        productionErrors: { material: error },
        footerActionError: error,
        __material: material,
        getInventoryItem(itemId) {
            const material = this.__material;
            if (!material) return null;
            if (itemId != null && Number(material.item_id || material.id) !== Number(itemId)) return null;
            return {
                id: material.item_id || material.id,
                name: material.name,
                unit_of_measure: material.unit_of_measure || material.uom,
            };
        },
        isValidPosMaterialQuantity: methods.isValidPosMaterialQuantity,
        normalizePosMaterialQuantity: methods.normalizePosMaterialQuantity,
        handlePendingMaterialQtyInput: methods.handlePendingMaterialQtyInput,
        clearPendingMaterialQtyPointerEdit: methods.clearPendingMaterialQtyPointerEdit,
        clearPendingMaterialConfirmationError: methods.clearPendingMaterialConfirmationError
    };
}

function dispatchActualUsageInput(ctx, material, value, {isTrusted = true, inputType = 'insertText'} = {}) {
    ctx.__material = material;
    const boundHandler = methods.handlePendingMaterialQtyInput.bind(ctx);
    const target = { value: String(material.qty ?? ''), dataset: {}, getBoundingClientRect: () => ({right: 100, left: 0}) };
    const event = { target, isTrusted, inputType };
    beforeInputBinding(material, event, methods.markPendingMaterialQtyEdit.bind(ctx));
    target.value = String(value ?? '');
    actualUsageInputBinding(material, event, boundHandler);
    return target;
}

function dispatchSameValueEditWithoutInput(ctx, material, value) {
    ctx.__material = material;
    const target = { value: String(value ?? ''), dataset: {} };
    beforeInputBinding(material, { target, isTrusted: true, inputType: 'insertText' }, methods.markPendingMaterialQtyEdit.bind(ctx));
    // A native input control may not dispatch `input` if the final value is unchanged.
    blurBinding(material, { target, isTrusted: true, type: 'blur' }, methods.handlePendingMaterialQtyBlur.bind(ctx));
    return target;
}

const confirmationError = 'Enter a valid actual quantity for Pp Stkr Matte 98 before saving.';
const item = {
    item_id: 98,
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
assert.equal(validateEstimatedMaterial(item), false, 'Untouched prefilled manual usage remains blocked by the final assignment validator.');
const untouchedTarget = { value: '1', dataset: {} };
const untouchedContext = context('', item);
blurBinding(item, { target: untouchedTarget }, methods.handlePendingMaterialQtyBlur.bind(untouchedContext));
assert.equal(item.manual_quantity_confirmed, false, 'Focusing and leaving an untouched prefilled value does not confirm it.');

const sameValueContext = context(confirmationError, item);
dispatchSameValueEditWithoutInput(sameValueContext, item, '1');
assert.equal(item.qty, 1, 'Same-value edit preserves the explicit actual quantity.');
assert.equal(item.manual_quantity_confirmed, true, 'An explicit edit gesture confirms a valid prefilled quantity even when no native input event is emitted.');
assert.equal(item.metadata.material_usage.staff_confirmed, true, 'Same-value edit synchronizes the staff-confirmed metadata.');
assert.equal(item.metadata.material_usage.manual_actual_usage_confirmed, true, 'An explicitly edited manual quantity is distinguishable from an automatic estimate in the Set Price payload.');
assert.equal(sameValueContext.productionErrors.material, '', 'Same-value edit clears its confirmation warning.');
assert.equal(validateEstimatedMaterial(item), true, 'Same-value explicit entry passes the final assignment validator.');

const autofillItem = { ...item, qty: 1, manual_quantity_confirmed: false, metadata: { material_usage: { staff_confirmed: false } } };
const autofillTarget = { value: '2', dataset: {} };
const autofillContext = context(confirmationError, autofillItem);
const autofillEvent = { target: autofillTarget, isTrusted: true, inputType: 'insertReplacementText' };
beforeInputBinding(autofillItem, autofillEvent, methods.markPendingMaterialQtyEdit.bind(autofillContext));
actualUsageInputBinding(autofillItem, autofillEvent, methods.handlePendingMaterialQtyInput.bind(autofillContext));
assert.equal(autofillItem.qty, 2, 'An autofill-like value update is reflected in the displayed pending row.');
assert.equal(autofillItem.manual_quantity_confirmed, false, 'Autofill replacement text does not count as explicit staff confirmation.');
assert.equal(autofillItem.metadata.material_usage.staff_confirmed, false, 'Autofill does not set confirmation audit metadata.');
assert.equal(autofillItem.metadata.material_usage.manual_actual_usage_confirmed, false, 'Autofill cannot attest a manually confirmed actual quantity.');
assert.equal(validateEstimatedMaterial(autofillItem), false, 'Autofilled manual usage remains blocked by final validation.');

const racedInputItem = { ...item, qty: 1, manual_quantity_confirmed: false, metadata: {} };
const racedInputTarget = { value: '1', dataset: {} };
const racedInputContext = context(confirmationError, racedInputItem);
beforeInputBinding(racedInputItem, { target: racedInputTarget, isTrusted: true, inputType: 'insertText' }, methods.markPendingMaterialQtyEdit.bind(racedInputContext));
racedInputTarget.value = '2';
actualUsageInputBinding(racedInputItem, { target: racedInputTarget, isTrusted: true, inputType: 'insertReplacementText' }, methods.handlePendingMaterialQtyInput.bind(racedInputContext));
assert.equal(racedInputItem.qty, 2, 'An autofill event after a separate edit intent still synchronizes the field value.');
assert.equal(racedInputItem.manual_quantity_confirmed, false, 'Autofill cannot reuse a stale, differently typed beforeinput marker.');
assert.equal(validateEstimatedMaterial(racedInputItem), false, 'Stale edit intent cannot make autofilled usage pass validation.');

const racedAutofillItem = { ...item, qty: 1, manual_quantity_confirmed: false, metadata: {} };
const racedAutofillTarget = { value: '1', dataset: {} };
const racedAutofillContext = context(confirmationError, racedAutofillItem);
beforeInputBinding(racedAutofillItem, { target: racedAutofillTarget, isTrusted: true, inputType: 'insertText' }, methods.markPendingMaterialQtyEdit.bind(racedAutofillContext));
racedAutofillTarget.value = '2'; // Autofill or a script changes the DOM value without an input event.
blurBinding(racedAutofillItem, { target: racedAutofillTarget, isTrusted: true, type: 'blur' }, methods.handlePendingMaterialQtyBlur.bind(racedAutofillContext));
assert.equal(racedAutofillItem.qty, 2, 'A value changed without an input event is synchronized on blur.');
assert.equal(racedAutofillItem.manual_quantity_confirmed, false, 'A value changed after edit intent without an input event is not treated as the entered value.');
assert.equal(validateEstimatedMaterial(racedAutofillItem), false, 'A script/autofill DOM change cannot pass final assignment validation.');

const syntheticItem = { ...item, qty: 1, manual_quantity_confirmed: false, metadata: {} };
dispatchActualUsageInput(context(''), syntheticItem, '2', { isTrusted: false });
assert.equal(syntheticItem.qty, 2, 'A programmatic value update reaches the shared state handler.');
assert.equal(syntheticItem.manual_quantity_confirmed, false, 'Synthetic programmatic input is not accepted as explicit staff confirmation.');
assert.equal(syntheticItem.metadata.material_usage.manual_actual_usage_confirmed, false, 'Programmatic input cannot mark an actual material quantity as staff-confirmed.');
assert.equal(validateEstimatedMaterial(syntheticItem), false, 'Programmatic input alone cannot pass final assignment validation.');

const keyboardItem = { ...item, qty: 1, manual_quantity_confirmed: false, metadata: {} };
const keyboardContext = context(confirmationError, keyboardItem);
const keyboardTarget = { value: '2', dataset: {}, getBoundingClientRect: () => ({right: 100, left: 0}) };
keydownBinding(keyboardItem, { target: keyboardTarget, isTrusted: true, key: '2' }, methods.markPendingMaterialQtyKeyEdit.bind(keyboardContext));
actualUsageInputBinding(keyboardItem, { target: keyboardTarget, isTrusted: true, inputType: '' }, methods.handlePendingMaterialQtyInput.bind(keyboardContext));
assert.equal(keyboardItem.manual_quantity_confirmed, true, 'A normal trusted keyboard edit confirms the changed valid value even without beforeinput support.');
assert.equal(validateEstimatedMaterial(keyboardItem), true, 'Keyboard-edited quantity passes final validation.');

const mouseItem = { ...item, qty: 1, manual_quantity_confirmed: false, metadata: {} };
const mouseContext = context(confirmationError, mouseItem);
const mouseTarget = { value: '2', dataset: {}, getBoundingClientRect: () => ({right: 100, left: 0}) };
pointerdownBinding(mouseItem, { target: mouseTarget, isTrusted: true, pointerType: 'mouse', clientX: 95 }, methods.markPendingMaterialQtyPointerEdit.bind(mouseContext));
actualUsageInputBinding(mouseItem, { target: mouseTarget, isTrusted: true, inputType: '' }, methods.handlePendingMaterialQtyInput.bind(mouseContext));
assert.equal(mouseItem.manual_quantity_confirmed, true, 'A trusted mouse spinner edit confirms its changed valid value.');
assert.equal(validateEstimatedMaterial(mouseItem), true, 'Mouse-edited quantity passes final validation.');

const blockedKeyItem = { ...item, qty: 1, manual_quantity_confirmed: false, metadata: {} };
const blockedKeyTarget = { value: '1', dataset: {}, getBoundingClientRect: () => ({right: 100, left: 0}) };
keydownBinding(blockedKeyItem, { target: blockedKeyTarget, isTrusted: true, key: '2' }, methods.markPendingMaterialQtyKeyEdit.bind(context('')));
keyupBinding(blockedKeyItem, { target: blockedKeyTarget }, methods.clearPendingMaterialQtyKeyEdit.bind(context('')));
blurBinding(blockedKeyItem, { target: blockedKeyTarget, isTrusted: true, type: 'blur' }, methods.handlePendingMaterialQtyBlur.bind(context('')));
assert.equal(blockedKeyItem.manual_quantity_confirmed, false, 'A key press without a corresponding value input does not confirm the prefilled quantity.');
assert.equal(validateEstimatedMaterial(blockedKeyItem), false, 'A no-op keyboard gesture remains blocked.');

item.manual_quantity_confirmed = false;
item.metadata.material_usage.staff_confirmed = false;

const inputContext = context(confirmationError, item);
dispatchActualUsageInput(inputContext, item, '1');
assert.equal(item.manual_quantity_confirmed, true, 'A valid edited actual quantity confirms usage.');
assert.equal(item.metadata.material_usage.actual, 1, 'The entered quantity is retained without conversion.');
assert.equal(item.metadata.material_usage.staff_confirmed, true, 'Confirmation metadata follows the state.');
assert.equal(item.metadata.material_usage.manual_actual_usage_confirmed, true, 'The explicit actual-usage confirmation is included with its exact quantity.');
assert.equal(inputContext.productionErrors.material, '', 'Editing valid usage clears its stale confirmation warning.');
assert.equal(inputContext.footerActionError, '', 'Editing valid usage clears its matching stale footer copy.');
assert.equal(validateEstimatedMaterial(item), true, 'The same pending material passes the final assignment validator after the rendered input event.');

for (const qty of ['1', '2', '5']) {
    const pieceMaterial = { ...item, name: `Piece material ${qty}`, uom: 'pcs', qty: 1, estimated_qty: null, manual_confirmation_required: true, manual_quantity_confirmed: false, metadata: { material_usage: { staff_confirmed: false } } };
    const pieceContext = context(`Enter a valid actual quantity for ${pieceMaterial.name} before saving.`);
    dispatchActualUsageInput(pieceContext, pieceMaterial, qty);
    assert.equal(pieceMaterial.qty, Number(qty), `Valid piece quantity ${qty} is retained.`);
    assert.equal(validateEstimatedMaterial(pieceMaterial), true, `Valid piece quantity ${qty} passes final assignment validation.`);
}

for (const [itemId, name] of [[206, 'Sintra 3mm'], [207, 'Sintra 5mm']]) {
    const sintraMaterial = {
        ...item,
        item_id: itemId,
        name,
        uom: 'pcs',
        qty: 1,
        estimated_qty: null,
        manual_confirmation_required: true,
        manual_quantity_confirmed: false,
        metadata: { material_usage: { method: 'manual', staff_confirmed: false } }
    };
    const sintraContext = context(`Enter a valid actual quantity for ${name} before saving.`);
    dispatchActualUsageInput(sintraContext, sintraMaterial, '2');
    assert.equal(sintraMaterial.qty, 2, `${name} Actual Usage input updates the selected material quantity.`);
    assert.equal(sintraMaterial.uom, 'pcs', `${name} retains its configured inventory unit.`);
    assert.equal(sintraMaterial.manual_quantity_confirmed, true, `${name} has an explicit staff confirmation marker.`);
    assert.equal(sintraMaterial.metadata.material_usage.actual, 2, `${name} actual quantity reaches the material-usage metadata.`);
    assert.equal(sintraMaterial.metadata.material_usage.staff_confirmed, true);
    assert.equal(validateEstimatedMaterial(sintraMaterial), true, `${name} valid whole-piece use passes the final client validator.`);
}

assert.match(page, /fdMaterial\.append\('quantity', pm\.qty\)/, 'The confirmed Actual Usage value is posted as the assignment quantity.');
assert.match(page, /fdMaterial\.append\('uom', pm\.uom\)/, 'The configured inventory UOM is posted with the assignment quantity.');
assert.match(page, /fdMaterial\.append\('metadata', JSON\.stringify\(pm\.metadata \|\| \{\}\)\)/, 'Actual Usage confirmation metadata reaches the Set Price save request.');

const firstMaterial = { ...item, name: 'Piece material', item_id: 501, uom: 'pcs', qty: 1, estimated_qty: null, manual_confirmation_required: true, manual_quantity_confirmed: false, metadata: {} };
const secondMaterial = { ...item, name: 'Length material', item_id: 502, uom: 'ft', qty: 1, estimated_qty: null, manual_confirmation_required: true, manual_quantity_confirmed: false, metadata: {} };
const multipleContext = context('');
dispatchActualUsageInput(multipleContext, firstMaterial, '1');
const multiplePending = [firstMaterial, secondMaterial];
assert.equal(validationMethod.call(buildValidatorContext(multiplePending, { pendingMaterials: multiplePending }), [], []), false, 'Confirming one row does not confirm another pending material.');
dispatchSameValueEditWithoutInput(multipleContext, secondMaterial, '1');
const materialsCopiedForSubmission = [...multiplePending];
assert.equal(materialsCopiedForSubmission[0], firstMaterial, 'Submission list copy retains the first pending material object.');
assert.equal(materialsCopiedForSubmission[1], secondMaterial, 'Submission list copy retains the second pending material object.');
assert.equal(validationMethod.call(buildValidatorContext(materialsCopiedForSubmission), materialsCopiedForSubmission, []), true, 'Both independently confirmed materials pass the final submission validator.');

const recalcMaterial = { ...item, qty: 1, estimated_qty: null, manual_confirmation_required: true, manual_quantity_confirmed: false, actual_usage_overridden: true, usage_method: 'manual', metadata: { material_usage: { actual: 1, staff_confirmed: false, overridden: true } } };
const manualRecalcContext = {
    pendingMaterials: [recalcMaterial],
    getInventoryItem: () => ({ id: 503 }),
    estimateMaterialUsage: () => ({ status: 'manual', quantity: null, reason: 'No reliable estimate is configured.', method: 'manual' }),
    isVersionedTarpaulinMaterial: () => false,
    clearPendingMaterialConfirmationError: methods.clearPendingMaterialConfirmationError
};
methods.recalculatePendingMaterial.call(manualRecalcContext, 0);
assert.equal(recalcMaterial.qty, 1, 'Manual recalculation does not overwrite the entered actual quantity.');
assert.equal(recalcMaterial.manual_confirmation_required, true, 'A manual recalculation explicitly restores the confirmation requirement.');
assert.equal(recalcMaterial.manual_quantity_confirmed, false, 'A refreshed manual estimate requires actual usage confirmation.');
assert.equal(recalcMaterial.metadata.material_usage.staff_confirmed, false, 'Recalculation keeps confirmation metadata consistent with the unconfirmed state.');
assert.equal(validateEstimatedMaterial(recalcMaterial), false, 'Recalculated manual usage remains blocked until staff confirms it.');
dispatchActualUsageInput(context(''), recalcMaterial, '1');
assert.equal(validateEstimatedMaterial(recalcMaterial), true, 'An explicit valid quantity after recalculation passes final validation.');

const estimatedRecalcMaterial = { ...recalcMaterial, qty: 4, estimated_qty: 2, manual_quantity_confirmed: true, metadata: {} };
const exactRecalcWarning = `Enter a valid actual quantity for ${estimatedRecalcMaterial.name} before saving.`;
const estimatedRecalcContext = {
    pendingMaterials: [estimatedRecalcMaterial],
    getInventoryItem: () => ({ id: 504 }),
    estimateMaterialUsage: () => ({ status: 'manual', quantity: null, reason: 'Manual entry only.', method: 'manual' }),
    isVersionedTarpaulinMaterial: () => false,
    productionErrors: { material: exactRecalcWarning },
    footerActionError: 'A separate footer error must remain visible.',
    clearPendingMaterialConfirmationError: methods.clearPendingMaterialConfirmationError
};
methods.recalculatePendingMaterial.call({
    ...estimatedRecalcContext
}, 0);
assert.equal(estimatedRecalcMaterial.qty, 4, 'Manual mode never replaces staff quantity with a prior estimate.');
assert.equal(estimatedRecalcMaterial.estimated_qty, null, 'Recalculation clears the stale automatic estimate.');
assert.equal(estimatedRecalcMaterial.metadata.material_usage.actual, 4, 'Manual mode retains the entered quantity in metadata.');
assert.equal(estimatedRecalcMaterial.manual_confirmation_required, true, 'Manual mode continues to require explicit usage confirmation.');
assert.equal(estimatedRecalcMaterial.manual_quantity_confirmed, false, 'Recalculation cannot auto-confirm usage.');
assert.equal(estimatedRecalcMaterial.metadata.material_usage.staff_confirmed, false, 'Recalculation cannot create staff confirmation metadata.');
assert.equal(estimatedRecalcContext.productionErrors.material, exactRecalcWarning, 'Manual recalculation does not silently clear the confirmation warning.');
assert.equal(estimatedRecalcContext.footerActionError, 'A separate footer error must remain visible.', 'Recalculation preserves an unrelated footer error.');
assert.equal(validateEstimatedMaterial(estimatedRecalcMaterial), false, 'Recalculation cannot pass final validation without explicit confirmation.');

const acMcFallback = { ...item, name: 'Ac Mc', item_id: 1, qty: 1, estimated_qty: null, manual_confirmation_required: false, manual_quantity_confirmed: true, actual_usage_overridden: false, metadata: {} };
assert.equal(estimateMaterialConsumption({ name: 'Ac Mc', unit_of_measure: 'pcs', material_format: 'piece', consumption_method: '' }, { quantity: 1 }).status, 'manual',
    'Ac Mc without a configured consumption method is not a trustworthy automatic material estimate.');
const acMcContext = {
    pendingMaterials: [acMcFallback],
    getInventoryItem: () => ({ id: 1, name: 'Ac Mc', unit_of_measure: 'pcs', material_format: 'piece', consumption_method: '' }),
    estimateMaterialUsage: () => ({ status: 'manual', quantity: null, reason: 'Enter and confirm the actual material usage.', method: 'manual' }),
    isVersionedTarpaulinMaterial: () => false,
    clearPendingMaterialConfirmationError: methods.clearPendingMaterialConfirmationError
};
methods.recalculatePendingMaterial.call(acMcContext, 0);
assert.equal(acMcFallback.qty, 1, 'An unconfigured material keeps the displayed fallback quantity for staff review.');
assert.equal(acMcFallback.estimated_qty, null, 'The order-quantity fallback is not mislabeled as a consumption estimate.');
assert.equal(acMcFallback.manual_confirmation_required, true, 'An order-quantity fallback cannot silently satisfy actual-usage confirmation.');
assert.equal(acMcFallback.manual_quantity_confirmed, false, 'An unconfigured fallback remains unconfirmed.');
assert.equal(validateEstimatedMaterial(acMcFallback), false, 'The final validator blocks an unconfigured fallback until staff confirms actual usage.');

const invalidItem = { ...item, metadata: {}, manual_quantity_confirmed: true };
const invalidContext = context(confirmationError, invalidItem);
dispatchActualUsageInput(invalidContext, invalidItem, '0');
assert.equal(invalidItem.manual_quantity_confirmed, false, 'Zero usage cannot confirm a manual quantity.');
assert.equal(invalidItem.metadata.material_usage.staff_confirmed, false, 'Invalid usage is not marked staff-confirmed.');
assert.equal(invalidContext.productionErrors.material, confirmationError, 'Invalid input retains the confirmation warning.');
assert.equal(invalidContext.footerActionError, confirmationError, 'Invalid input retains the matching footer warning.');

const negativeItem = { ...item, metadata: {}, manual_quantity_confirmed: true };
dispatchActualUsageInput(context(confirmationError), negativeItem, '-1');
assert.equal(negativeItem.manual_quantity_confirmed, false, 'Negative usage cannot confirm a manual quantity.');
assert.equal(negativeItem.metadata.material_usage.staff_confirmed, false, 'Negative usage is not marked staff-confirmed.');

const fractionalPieces = { ...item, metadata: {}, manual_quantity_confirmed: false };
dispatchActualUsageInput(context(''), fractionalPieces, '1.5');
assert.equal(fractionalPieces.manual_quantity_confirmed, false, 'Fractional pieces remain invalid.');

const fractionalFeet = { ...item, uom: 'ft', metadata: {}, manual_quantity_confirmed: false };
dispatchActualUsageInput(context(''), fractionalFeet, '1.5');
assert.equal(fractionalFeet.manual_quantity_confirmed, true, 'Fractional length usage remains valid.');

function buildValidatorContext(materials = [], overrides = {}) {
    const registry = new Map(
        (Array.isArray(materials) ? materials : [materials])
            .filter(Boolean)
            .map((material) => [Number(material.item_id || material.id), material])
    );
    return {
        currentJo: { materials: [], ink_usage: [] },
        pendingMaterials: [],
        productionErrors: {},
        productionAssignmentSubmitAttempted: false,
        requiresInk: false,
        materialCompatibilityContext: {},
        materialRules: [],
        posMaterialQuantityError: () => '',
        isPosSetPriceFlow: () => true,
        isPendingTarpaulinMaterial: () => false,
        isPendingStickerLaminateMaterial: () => false,
        pendingMaterialStockError: () => '',
        platesBlankUsageError: () => '',
        posterPrimaryUsageError: () => '',
        getInventoryItem(itemId) {
            const material = registry.get(Number(itemId));
            if (!material) return null;
            return {
                id: material.item_id || material.id,
                name: material.name,
                unit_of_measure: material.unit_of_measure || material.uom,
            };
        },
        isValidPosMaterialQuantity(value, itemId) {
            const inv = this.getInventoryItem(itemId);
            return !!(inv && materialConsumption.parseMaterialQuantity(value, inv) !== null);
        },
        ...overrides,
    };
}

function validateEstimatedMaterial(material) {
    return validationMethod.call(buildValidatorContext(material), [material], []);
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

for (const value of ['', '0', '-1', 'Infinity', '1.5', '1 pcs']) {
    const invalidManualMaterial = { ...item, uom: 'pcs', qty: 1, estimated_qty: null, manual_confirmation_required: true, manual_quantity_confirmed: false, metadata: {} };
    dispatchActualUsageInput(context(''), invalidManualMaterial, value);
    assert.equal(validateEstimatedMaterial(invalidManualMaterial), false, `Rendered input value ${JSON.stringify(value)} cannot pass assignment validation for pcs.`);
}

for (const material of [
    { ...item, name: 'Nextjet', item_id: 18, uom: 'pcs', estimated_qty: null, manual_confirmation_required: true, manual_quantity_confirmed: false },
    { ...item, name: 'Sticker Blue', item_id: 31, uom: 'ft', estimated_qty: null, manual_confirmation_required: true, manual_quantity_confirmed: false },
    { ...item, name: 'Hologram Roll', item_id: 44, uom: 'ft', qty: 1.5, estimated_qty: 1, manual_confirmation_required: false }
]) {
    const materialContext = context(`Enter a valid actual quantity for ${material.name} before saving.`);
    dispatchActualUsageInput(materialContext, material, material.qty);
    assert.equal(material.manual_quantity_confirmed, true, `${material.name} accepts a valid actual quantity in its unit.`);
    assert.equal(materialContext.productionErrors.material, '', `${material.name} clears its own confirmation message.`);
    assert.equal(materialContext.footerActionError, '', `${material.name} clears its duplicate footer message.`);
    assert.equal(validateEstimatedMaterial(material), true, `${material.name} passes the final validator after its bound input event.`);
}

const otherMaterialWarning = context('Enter a valid actual quantity for Nextjet before saving.');
dispatchActualUsageInput(otherMaterialWarning, { ...item, name: 'Sticker Blue', metadata: {} }, '2');
assert.equal(otherMaterialWarning.productionErrors.material, 'Enter a valid actual quantity for Nextjet before saving.', 'Editing another material does not clear its warning.');
assert.equal(otherMaterialWarning.footerActionError, 'Enter a valid actual quantity for Nextjet before saving.', 'Editing another material does not clear its footer warning.');

const duplicateFeedbackContext = {
    productionErrors: { material: confirmationError, ink_set: '', ink_consumption: '' },
    footerActionError: '',
    setFooterActionError(message) { this.footerActionError = message; }
};
feedbackMethods.setProductionValidationFeedback.call(duplicateFeedbackContext);
assert.equal(duplicateFeedbackContext.footerActionError, '', 'Material confirmation stays in the inline error slot without a duplicate footer copy.');
duplicateFeedbackContext.productionErrors.ink_set = 'Please select an ink set.';
feedbackMethods.setProductionValidationFeedback.call(duplicateFeedbackContext);
assert.equal(duplicateFeedbackContext.footerActionError, 'Please select an ink set.', 'Non-material validation errors remain visible in the footer.');
duplicateFeedbackContext.productionErrors.ink_set = '';
duplicateFeedbackContext.footerActionError = 'A separate stock validation error.';
feedbackMethods.setProductionValidationFeedback.call(duplicateFeedbackContext);
assert.equal(duplicateFeedbackContext.footerActionError, 'A separate stock validation error.', 'A different existing footer error is preserved.');

const unrelatedErrorContext = context('Insufficient stock for this material.');
dispatchActualUsageInput(unrelatedErrorContext, { ...item, metadata: {} }, '2');
assert.equal(unrelatedErrorContext.productionErrors.material, 'Insufficient stock for this material.', 'Unrelated validation errors are preserved.');

// --- Manual actual usage on material selection (Set Price / Production Assignment) ---
const helperStart = page.indexOf('            isValidPendingMaterialActualQty(qty, uom, itemId = null) {');
const helperEnd = page.indexOf('            maybeApplyAutoMaterialSuggestion() {', helperStart);
assert(helperStart >= 0 && helperEnd > helperStart, 'System autofill confirmation helpers are present.');
const queueStart = page.indexOf('            addMaterialToQueue(options = {}) {');
const queueEnd = page.indexOf('            removePendingMaterial(index) {', queueStart);
assert(queueStart >= 0 && queueEnd > queueStart, 'addMaterialToQueue is present for regression coverage.');
const queueMethods = new Function(`return ({${page.slice(helperStart, helperEnd)}${page.slice(queueStart, queueEnd)}\n});`)();
assert.equal(typeof queueMethods.addMaterialToQueue, 'function', 'addMaterialToQueue is extractable for regression coverage.');
assert.equal(typeof queueMethods.pendingMaterialUsageConfirmationState, 'function', 'Selection confirmation helper is extractable.');

function queueMaterialFromSystemAutofill({
    itemId = 101,
    name = 'C2s Board',
    uom = 'sheet',
    qty = 1,
    consumptionMethod = '',
    estimate = null,
    isPos = true,
} = {}) {
    const inventoryItem = {
        id: itemId,
        name,
        unit_of_measure: uom,
        size_variant: uom === 'sheet' ? 'A4' : '',
        consumption_method: consumptionMethod,
        current_stock: 104,
    };
    const ctx = {
        newMaterialId: itemId,
    newMaterialQty: qty,
        newMaterialRollId: '',
        newMaterialNotes: '',
        newMaterialHeight: 0,
        newMaterialMetadata: {},
        newMaterialUsageEstimate: estimate,
        pendingMaterials: [],
        materialListExpanded: true,
        selectedMaterialStockError: '',
        allInventoryItems: [inventoryItem],
        getInventoryItem(id) { return this.allInventoryItems.find(row => String(row.id) === String(id)) || null; },
        materialClassification: () => ({ selectable: true }),
        isVersionedTarpaulinMaterial: () => false,
        isPosSetPriceFlow: () => isPos,
        isValidPosMaterialQuantity: () => true,
        normalizePosMaterialQuantity: (value) => Number(value),
        normalizeMaterialQtyValue: (value, fallback = 1) => {
            const parsed = Number(value);
            return Number.isFinite(parsed) && parsed > 0 ? parsed : fallback;
        },
        getMaterialEntryUom: () => uom,
        estimateMaterialUsage: () => estimate || { status: 'manual', quantity: null, reason: 'Enter and confirm the actual material usage.', method: 'manual' },
        isSticker: () => false,
        showStaffAlert() {},
        resetMaterialForm() {
            this.newMaterialId = '';
            this.newMaterialQty = '';
            this.newMaterialUsageEstimate = null;
        },
        isValidPendingMaterialActualQty: queueMethods.isValidPendingMaterialActualQty,
        pendingMaterialUsageConfirmationState: queueMethods.pendingMaterialUsageConfirmationState,
        addMaterialToQueue: queueMethods.addMaterialToQueue,
    };
    queueMethods.addMaterialToQueue.call(ctx, { keepListOpen: true, allowQuantityAdjustment: true });
    return { ctx, material: ctx.pendingMaterials[0], inventoryItem };
}

const c2sCase = queueMaterialFromSystemAutofill({
    name: 'C2s Board',
    uom: 'sheet',
    qty: 1,
    consumptionMethod: '',
    estimate: { status: 'manual', quantity: null, reason: 'Enter and confirm the actual material usage.', method: 'manual' },
});
assert.equal(c2sCase.material.qty, 1, 'The entered C2s Board actual quantity is preserved exactly.');
assert.equal(c2sCase.material.uom, 'sheet', 'C2s Board retains its sheet unit.');
assert.equal(c2sCase.material.manual_quantity_confirmed, false, 'Queueing a material does not auto-confirm the entered quantity.');
assert.equal(c2sCase.material.metadata.material_usage.actual, 1, 'Submitted actual usage matches the autofilled quantity.');
assert.equal(c2sCase.material.metadata.material_usage.staff_confirmed, false, 'Queueing does not create staff-confirmation metadata.');
assert.equal(c2sCase.material.metadata.material_usage.manual_actual_usage_confirmed, false, 'A manually entered amount is not attested before explicit confirmation.');
assert.equal(validateEstimatedMaterial(c2sCase.material), false, 'Set Price requires explicit confirmation of the manual amount.');
dispatchActualUsageInput(context(''), c2sCase.material, '1');
assert.equal(validateEstimatedMaterial(c2sCase.material), true, 'Confirmed C2s Board actual usage passes Set Price validation.');

for (const sample of [
    { name: 'Brochure Paper', uom: 'sheet', qty: 1, method: 'per_piece', estimate: { status: 'estimated', quantity: 1, method: 'PER_PIECE' } },
    { name: 'Sintraboard 3mm', uom: 'pcs', qty: 2, method: 'per_piece', estimate: { status: 'estimated', quantity: 2, method: 'PER_PIECE' } },
    { name: 'Sintraboard 5mm', uom: 'pcs', qty: 1, method: 'per_piece', estimate: { status: 'estimated', quantity: 1, method: 'PER_PIECE' } },
    { name: 'Pp Stkr Matte 98', uom: 'pcs', qty: 3, method: '', estimate: { status: 'manual', quantity: null, reason: 'Enter and confirm the actual material usage.', method: 'manual' } },
    { name: 'Plates', uom: 'pcs', qty: 1, method: 'per_piece', estimate: { status: 'estimated', quantity: 1, method: 'PER_PIECE' } },
    { name: 'Poster Printing Sheet', uom: 'sheet', qty: 1, method: 'sheet_yield', estimate: { status: 'estimated', quantity: 1, method: 'SHEET_YIELD' } },
    { name: 'Stickers Decals', uom: 'ft', qty: 2.5, method: 'by_length', estimate: { status: 'estimated', quantity: 2.5, method: 'BY_LENGTH' } },
    { name: 'Mugs', uom: 'pcs', qty: 1, method: '', estimate: { status: 'manual', quantity: null, reason: 'Enter and confirm the actual material usage.', method: 'manual' } },
]) {
    const queued = queueMaterialFromSystemAutofill({
        itemId: Math.abs(sample.name.length * 17),
        name: sample.name,
        uom: sample.uom,
        qty: sample.qty,
        consumptionMethod: sample.method,
        estimate: sample.estimate,
    });
    assert.equal(queued.material.qty, sample.qty, `${sample.name} keeps the staff-entered actual usage quantity.`);
    assert.equal(queued.material.uom, sample.uom, `${sample.name} keeps its configured unit.`);
    assert.equal(queued.material.manual_quantity_confirmed, false, `${sample.name} is not auto-confirmed when queued.`);
    assert.equal(queued.material.metadata.material_usage.actual, sample.qty, `${sample.name} payload actual matches the queued quantity.`);
    assert.equal(validateEstimatedMaterial(queued.material), false, `${sample.name} requires explicit actual-usage confirmation.`);
    dispatchActualUsageInput(context(''), queued.material, String(sample.qty));
    assert.equal(validateEstimatedMaterial(queued.material), true, `${sample.name} passes Set Price after actual usage is confirmed.`);
}

const onlineOpsCase = queueMaterialFromSystemAutofill({
    name: 'Online Ops C2s Board',
    uom: 'sheet',
    qty: 1,
    isPos: false,
    estimate: { status: 'manual', quantity: null, reason: 'Enter and confirm the actual material usage.', method: 'manual' },
});
assert.equal(onlineOpsCase.material.manual_quantity_confirmed, false, 'Online Operations does not auto-confirm a queued manual quantity.');
assert.equal(validateEstimatedMaterial(onlineOpsCase.material), false, 'Online Operations requires explicit actual-usage confirmation.');
dispatchActualUsageInput(context(''), onlineOpsCase.material, '1');
assert.equal(validateEstimatedMaterial(onlineOpsCase.material), true, 'Online Operations accepts the explicitly confirmed manual quantity.');

const emptyUsage = queueMaterialFromSystemAutofill({
    name: 'Missing Usage',
    qty: 0,
    estimate: { status: 'manual', quantity: null, reason: 'Enter and confirm the actual material usage.', method: 'manual' },
});
assert.equal(emptyUsage.ctx.pendingMaterials.length, 0, 'Zero/invalid actual usage is not queued.');

const optionalOmitted = {
    currentJo: { materials: [{ item_id: 206, qty: 1, uom: 'pcs' }], ink_usage: [] },
    pendingMaterials: [],
    productionErrors: {},
    requiresInk: false,
    posMaterialQuantityError: () => '',
    isPendingTarpaulinMaterial: () => false,
    pendingMaterialStockError: () => '',
};
assert.equal(validationMethod.call(optionalOmitted, [], []), true, 'Optional materials can remain omitted while a required assignment is already saved.');

const savedMatchesDeduction = c2sCase.material;
assert.equal(Number(savedMatchesDeduction.qty), Number(savedMatchesDeduction.metadata.material_usage.actual),
    'Saved assignment quantity matches the deduction/actual usage input.');

console.log('customization_actual_usage_confirmation_test: PASS');
