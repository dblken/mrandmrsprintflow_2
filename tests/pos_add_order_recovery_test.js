'use strict';

const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const source = fs.readFileSync(path.join(__dirname, '../staff/pos.php'), 'utf8');
function block(startMarker, endMarker) {
  const start = source.indexOf(startMarker);
  const end = source.indexOf(endMarker, start);
  assert.ok(start >= 0 && end > start, `${startMarker} block exists`);
  return source.slice(start, end);
}

function makePriorityForm(checkedValue = '') {
  const listeners = {};
  const row = {
    dataset: { fieldKey: 'order_priority' },
    querySelectorAll(selector) {
      return selector === '[required]' ? radios : [];
    },
    querySelector(selector) {
      if (selector === '.pf-file-upload-group') return null;
      if (selector === 'input[type="radio"]') return radios[0];
      return null;
    },
  };
  const radios = ['Regular Order', 'Urgent Order'].map(value => ({
    name: 'order_priority', value, type: 'radio', required: true,
    checked: value === checkedValue, disabled: false,
    // The renderer hides the radio element itself; its parent option remains visible.
    parentElement: { closest() { return null; } },
    closest(selector) { return selector.includes('display') ? { hidden: true } : row; },
    addEventListener(name, callback) { (listeners[name] ||= []).push(callback); },
  }));
  const body = {
    querySelectorAll(selector) {
      if (selector === '.shopee-form-row') return [row];
      if (selector === '.pf-file-upload-group[data-pf-required="1"]') return [];
      if (selector === 'input, select, textarea, button[data-dimension-choice], button[data-dimension-others]') return radios;
      return [];
    },
  };
  return { body, row, radios, listeners };
}

const form = makePriorityForm();
const validatorContext = vm.createContext({
  document: { getElementById() { return form.body; } },
  updateConditionalFields() {},
  isServiceFieldActive() { return true; },
  serviceFieldKey() { return 'order_priority'; },
  serviceFieldLabel() { return 'Order Priority'; },
});
vm.runInContext(
  block('        function serviceValidationMessage(row, input) {', '        function clearServiceFieldError(')
    + block('        function validateServiceOrderForm() {', '        function setCustomizationValue('),
  validatorContext
);
let validation = validatorContext.validateServiceOrderForm();
assert.equal(validation.valid, false, 'an unselected required order-priority group is invalid');
assert.equal(validation.errors.order_priority.message, 'Please select order priority.', 'priority error is specific and clear');

form.radios[0].checked = true;
validation = validatorContext.validateServiceOrderForm();
assert.equal(validation.valid, true, 'Regular Order satisfies the required choice');
form.radios[0].checked = false;
form.radios[1].checked = true;
assert.equal(validatorContext.validateServiceOrderForm().valid, true, 'Urgent Order satisfies the required choice');

let clearedRow = null;
const clearerContext = vm.createContext({
  clearServiceFieldError(row) { clearedRow = row; },
});
vm.runInContext(block('        function bindServiceValidationClearers(container) {', '        function isPublicDesignLinkHost('), clearerContext);
clearerContext.bindServiceValidationClearers(form.body);
form.listeners.change[0].call(form.radios[0]);
assert.equal(clearedRow, form.row, 'correcting the choice clears its inline field error');

const confirmBlock = block('        async function confirmServiceModal() {', '        // ── Legacy service requirements');
function makeConfirmHarness({ validationValid, validationErrors = {}, stockPromise, cartResponse, serviceId = 63, serviceName = 'Mugs', selectedMaterialId = null }) {
  const button = { disabled: false, style: {}, textContent: 'Add to Order' };
  const overlay = { dataset: { serviceId: String(serviceId), serviceName } };
  const hiddenBranch = { value: '1' };
  const selectedMaterial = selectedMaterialId === null ? null : {
    type: 'radio', name: 'pos_material_choice_sintra_thickness', value: String(selectedMaterialId), checked: true,
    dataset: { posMaterialId: String(selectedMaterialId), posMaterialGroup: 'sintra_thickness' },
  };
  const body = {
    querySelector(selector) {
      if (selector === 'input[type="hidden"][name="branch_id"]') return hiddenBranch;
      return null;
    },
    querySelectorAll(selector) {
      if (selector === '[data-pos-material-id]:checked') return selectedMaterial ? [selectedMaterial] : [];
      return [];
    },
  };
  const busyTransitions = [];
  let cartCalls = 0;
  let stockCalls = 0;
  const stockServiceIds = [];
  const stockMaterialIds = [];
  let cartPayload = null;
  let shownValidationErrors = null;
  const context = vm.createContext({
    document: { getElementById(id) {
      if (id === 'service-modal-overlay') return overlay;
      if (id === 'sm-fields-body') return body;
      if (id === 'sm-add-to-order-btn') return button;
      return null;
    } },
    window: {},
    posServiceStockBlocking: false,
    posLastServiceCardEl: null,
    console,
    validateServiceOrderForm() { return { valid: validationValid, errors: validationErrors }; },
    showValidationErrors(errors) { shownValidationErrors = errors; },
    focusFirstInvalidField() {},
    showPOSAlert: async () => {},
    posServiceQuantityInput() { return { value: '3', checkValidity() { return true; }, reportValidity() {} }; },
    async posValidateServiceStockNow(checkedServiceId) {
      stockCalls += 1;
      stockServiceIds.push(Number(checkedServiceId));
      stockMaterialIds.push(body.querySelectorAll('[data-pos-material-id]:checked').map(input => Number(input.dataset.posMaterialId || input.value)));
      return stockPromise();
    },
    resolveServiceModalPricing() { return { unitPrice: 120, quantity: 3, designFee: 200 }; },
    setServiceAddButtonBusy(isBusy) {
      button.disabled = !!isBusy || context.posServiceStockBlocking;
      button.style.opacity = isBusy ? '0.7' : '1';
      button.style.cursor = isBusy ? 'not-allowed' : 'pointer';
      button.textContent = isBusy ? 'Adding...' : 'Add to Order';
      busyTransitions.push(!!isBusy);
    },
    posStripInactiveDesignUploads() {},
    async posStageDesignUploadsFromModal() {},
    async posApplyStagedUploadMany() {},
    posReadFilePayload() {},
    applyServicePricingToCustomization(customization, unitPrice, quantity, designFee) {
      return { unitPrice, quantity, designFee };
    },
    async syncedCartAction(_action, payload) { cartCalls += 1; cartPayload = payload; return cartResponse(); },
    showBackendValidationErrors() {},
    serviceSelectorEscape(value) { return value; },
    isServiceFieldActive() { return true; },
    serviceFieldKey() { return ''; },
    serviceFieldLabel() { return ''; },
    normalizeLayoutCustomizationValue(_row, value) { return value; },
    setCustomizationValue() {},
    posStoreDesignLink() {},
    posCollectTarpaulinStockCustomization() { return {}; },
    posApplySelectedServiceDimension() {},
    closeServiceModal() {},
    confirmMissingEstimatedPrice: async () => false,
  });
  vm.runInContext('let isAddingToOrder = false; let isPreparingServiceOrder = false;', context);
  vm.runInContext(confirmBlock, context);
  return {
    context, button, busyTransitions,
    get cartCalls() { return cartCalls; },
    get stockCalls() { return stockCalls; },
    get stockServiceIds() { return stockServiceIds; },
    get stockMaterialIds() { return stockMaterialIds; },
    get cartPayload() { return cartPayload; },
    get shownValidationErrors() { return shownValidationErrors; },
  };
}

(async () => {
  const invalid = makeConfirmHarness({
    validationValid: false,
    validationErrors: { order_priority: { message: 'Please select order priority.' } },
    stockPromise: async () => ({ valid: true }),
    cartResponse: async () => ({ success: true }),
  });
  await invalid.context.confirmServiceModal();
  assert.deepEqual(invalid.busyTransitions, [], 'invalid required fields never enter the loading state');
  assert.equal(invalid.stockCalls, 0, 'missing priority does not start asynchronous stock/cart work');
  assert.equal(invalid.cartCalls, 0, 'missing priority sends no add-to-cart request');
  assert.equal(invalid.shownValidationErrors.order_priority.message, 'Please select order priority.', 'missing priority displays the inline error');
  assert.equal(invalid.button.textContent, 'Add to Order', 'invalid modal retains the retry label');
  assert.equal(invalid.button.disabled, false, 'invalid modal leaves the action usable for correction');

  let releaseStock;
  const pendingStock = new Promise(resolve => { releaseStock = resolve; });
  const retry = makeConfirmHarness({
    validationValid: true,
    stockPromise: () => pendingStock,
    cartResponse: async () => ({ success: false, message: 'simulated server validation failure' }),
  });
  const firstClick = retry.context.confirmServiceModal();
  await Promise.resolve();
  const secondClick = retry.context.confirmServiceModal();
  releaseStock({ valid: true });
  await Promise.all([firstClick, secondClick]);
  assert.equal(retry.stockCalls, 1, 'a second click during asynchronous validation is ignored');
  assert.equal(retry.cartCalls, 1, 'only one add request is sent while a submission is pending');
  assert.deepEqual(retry.busyTransitions, [true, false], 'request failure reliably clears the loading state');
  assert.equal(retry.button.textContent, 'Add to Order', 'failed request restores the original button label');
  assert.equal(retry.button.disabled, false, 'failed request restores retry availability');
  assert.equal(retry.button.style.opacity, '1', 'failed request restores button opacity');
  assert.equal(retry.button.style.cursor, 'pointer', 'failed request restores button cursor');
  assert.equal(vm.runInContext('isAddingToOrder === false && isPreparingServiceOrder === false', retry.context), true,
    'all pending-state guards clear after failure');

  const networkFailure = makeConfirmHarness({
    validationValid: true,
    stockPromise: async () => ({ valid: true }),
    cartResponse: async () => ({ success: false, message: 'simulated network timeout' }),
  });
  await networkFailure.context.confirmServiceModal();
  assert.deepEqual(networkFailure.busyTransitions, [true, false], 'network failure follows the same cleanup path');
  assert.equal(networkFailure.button.textContent, 'Add to Order', 'network failure restores the retry label');
  assert.equal(networkFailure.button.disabled, false, 'network failure leaves retry enabled');

  const success = makeConfirmHarness({
    validationValid: true,
    stockPromise: async () => ({ valid: true }),
    cartResponse: async () => ({ success: true }),
  });
  await success.context.confirmServiceModal();
  assert.equal(success.cartCalls, 1, 'successful local addition sends one cart request');
  assert.deepEqual(success.busyTransitions, [true, false], 'success also exits the loading state');

  const noThickness = makeConfirmHarness({
    validationValid: true, serviceId: 71, serviceName: 'Sintraboard Standees',
    stockPromise: async () => ({ valid: false }), cartResponse: async () => ({ success: true }),
  });
  await noThickness.context.confirmServiceModal();
  assert.equal(noThickness.cartCalls, 0, 'Sintraboard cannot Add to Order when staff has not selected a board thickness');
  assert.deepEqual(noThickness.stockMaterialIds, [[]], 'no Sintra thickness is auto-selected from dimensions');

  for (const thicknessId of [206, 207]) {
    const sintraboard = makeConfirmHarness({
      validationValid: true, serviceId: 71, serviceName: 'Sintraboard Standees', selectedMaterialId: thicknessId,
      stockPromise: async () => ({ valid: true }), cartResponse: async () => ({ success: true }),
    });
    await sintraboard.context.confirmServiceModal();
    assert.deepEqual(sintraboard.stockServiceIds, [71], `Sintra ${thicknessId} Add to Order revalidates stock for service ID 71`);
    assert.deepEqual(sintraboard.stockMaterialIds, [[thicknessId]], `stock validation receives only selected Sintra item ${thicknessId}`);
    assert.equal(sintraboard.cartCalls, 1, `valid Sintra ${thicknessId} stock allows one cart request`);
    assert.deepEqual(Array.from(sintraboard.cartPayload.customization.pos_selected_material_ids), [thicknessId],
      `Add to Order payload persists selected Sintra item ${thicknessId}`);

    const lowStock = makeConfirmHarness({
      validationValid: true, serviceId: 71, serviceName: 'Sintraboard Standees', selectedMaterialId: thicknessId,
      stockPromise: async () => ({ valid: false, message: `Insufficient Sintra ${thicknessId} stock.` }),
      cartResponse: async () => ({ success: true }),
    });
    await lowStock.context.confirmServiceModal();
    assert.deepEqual(lowStock.stockMaterialIds, [[thicknessId]], `low-stock validation checks only selected Sintra item ${thicknessId}`);
    assert.equal(lowStock.cartCalls, 0, `insufficient Sintra ${thicknessId} stock blocks Add to Order`);
  }

  console.log('POS Add to Order validation and recovery tests passed.');
})().catch(error => {
  console.error(error);
  process.exitCode = 1;
});
