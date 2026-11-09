'use strict';

const assert = require('assert');
const fs = require('fs');
const path = require('path');
const vm = require('vm');

const root = path.join(__dirname, '..');
const posSource = fs.readFileSync(path.join(root, 'staff/pos.php'), 'utf8');
const customSource = fs.readFileSync(path.join(root, 'staff/customizations.php'), 'utf8');
const calcSource = fs.readFileSync(path.join(root, 'includes/tarpaulin_material_calculation.php'), 'utf8');

assert.doesNotMatch(
  posSource,
  /posEnsureTarpaulinDimensionInputs|data-pos-tarp-width="1"|data-pos-tarp-height="1"|pos-tarpaulin-dimension-controls/,
  'POS Tarpaulin Order Details has no injected Width/Height inputs'
);

const confirmStart = posSource.indexOf('async function confirmServiceModal() {');
const confirmEnd = posSource.indexOf('\n        function ', confirmStart + 20);
assert.ok(confirmStart >= 0 && confirmEnd > confirmStart, 'confirmServiceModal present');
const confirmBody = posSource.slice(confirmStart, confirmEnd);

assert.match(confirmBody, /if \(Number\(serviceId\) === 40\) \{[\s\S]*posCollectTarpaulinStockCustomization\(\)/,
  'Add to Order re-collects Tarpaulin size from Dimensions after the form loop');
assert.match(confirmBody, /else \{\s*posApplySelectedServiceDimension\(customization, body, overlay\);\s*\}/,
  'non-Tarpaulin services still use the shared preset dimension parser');
assert.match(posSource, /if \(Number\(overlay\.dataset\.serviceId \|\| customization\.service_id \|\| 0\) === 40\) \{\s*return;\s*\}/,
  'shared dimension parser never blanks Tarpaulin derived width/height');
assert.doesNotMatch(posSource, /Joining Direction|Joining Allowance|pos-tarpaulin-joining-controls/,
  'joining fields remain absent');

const collectStart = posSource.indexOf('        function posCollectTarpaulinStockCustomization() {');
const collectEnd = posSource.indexOf('\n        async function posValidateServiceStockNow(', collectStart);
const collectSource = posSource.slice(collectStart, collectEnd);
const applyStart = posSource.indexOf('        function posApplySelectedServiceDimension(customization, body, overlay) {');
const applyEnd = posSource.indexOf('\n        function posCollectTarpaulinStockCustomization()', applyStart);
const applySource = posSource.slice(applyStart, applyEnd);

function makeBody({ dimensionsValue, customWidth, customHeight, useCustomPanel }) {
  return {
    querySelectorAll(selector) {
      if (selector === 'select, input[type="radio"]') {
        return [{
          name: 'dimensions',
          tagName: 'SELECT',
          value: dimensionsValue,
          checked: false,
          options: [{ textContent: dimensionsValue }],
          selectedIndex: 0,
          getAttribute() { return dimensionsValue; },
          closest() { return null; }
        }];
      }
      return [];
    },
    querySelector(selector) {
      if (selector === 'select[name="dimensions"]') {
        return {
          tagName: 'SELECT',
          name: 'dimensions',
          value: dimensionsValue,
          classList: { contains: (c) => c === 'pf-select-with-others' && useCustomPanel },
          dataset: { otherOption: 'Others' }
        };
      }
      if (selector.includes('[name="dimensions_unit"]')) {
        return { value: 'ft' };
      }
      if (useCustomPanel && selector.includes('.pf-custom-size-panel') && selector.includes('custom-dim-width')) {
        return { value: customWidth };
      }
      if (useCustomPanel && selector.includes('.pf-custom-size-panel') && selector.includes('custom-dim-height')) {
        return { value: customHeight };
      }
      if (!useCustomPanel && selector.includes('.shopee-opt-btn.active[data-width]')) {
        return null;
      }
      return null;
    }
  };
}

// Preset Dimensions "2x4 Ft" → saved width/height without Width/Height inputs.
{
  const body = makeBody({ dimensionsValue: '2x4 Ft', useCustomPanel: false });
  const context = vm.createContext({
    document: {
      getElementById(id) {
        if (id === 'sm-fields-body') return body;
        if (id === 'service-modal-overlay') {
          return {
            dataset: {
              serviceId: '40',
              materialDimensionFieldKey: 'dimensions',
              materialDimensionUnit: 'ft'
            }
          };
        }
        return null;
      }
    },
    posCollectServiceStockCustomization() {
      return {
        service_id: 40,
        width: '',
        height: '',
        dimensions: '2x4 Ft',
        dimensions_unit: 'ft',
        layout: 'without_layout'
      };
    },
    posServiceQuantityInput() { return { value: '1' }; },
    pfPrepareServiceFormDimensions() {}
  });
  vm.runInContext(applySource + '\n' + collectSource, context);

  const wipedThenCleared = { service_id: 40, width: '2', height: '4', pos_material_dimension_unit: 'ft' };
  context.posApplySelectedServiceDimension(
    wipedThenCleared,
    body,
    context.document.getElementById('service-modal-overlay')
  );
  assert.strictEqual(wipedThenCleared.width, '2', 'parser skip preserves Tarpaulin width');
  assert.strictEqual(wipedThenCleared.height, '4', 'parser skip preserves Tarpaulin height');

  const collected = context.posCollectTarpaulinStockCustomization();
  assert.strictEqual(collected.dimensions, '2x4 Ft', 'selected Dimensions value is saved');
  assert.strictEqual(collected.width, '2', 'preset Dimensions yields width 2');
  assert.strictEqual(collected.height, '4', 'preset Dimensions yields height 4');
  assert.ok(['ft', 'Ft'].includes(String(collected.pos_material_dimension_unit || '').toLowerCase())
    || collected.pos_material_dimension_unit === 'ft',
  'dimension unit survives collection');

  const cartCustomization = {
    service_id: 40,
    width: '',
    height: '',
    dimensions_unit: 'ft',
    layout: 'without_layout'
  };
  Object.assign(cartCustomization, context.posCollectTarpaulinStockCustomization());
  assert.strictEqual(cartCustomization.width, '2', 'handoff payload keeps width from Dimensions');
  assert.strictEqual(cartCustomization.height, '4', 'handoff payload keeps height from Dimensions');
  assert.strictEqual(cartCustomization.dimensions, '2x4 Ft', 'handoff payload keeps Dimensions label');
}

// Others + custom size panel (still part of Dimensions, not injected Width/Height).
{
  const body = makeBody({
    dimensionsValue: 'Others',
    customWidth: '2',
    customHeight: '2',
    useCustomPanel: true
  });
  const context = vm.createContext({
    document: {
      getElementById(id) {
        if (id === 'sm-fields-body') return body;
        if (id === 'service-modal-overlay') {
          return { dataset: { serviceId: '40', materialDimensionUnit: 'ft' } };
        }
        return null;
      }
    },
    posCollectServiceStockCustomization() {
      return { service_id: 40, width: '', height: '', dimensions: 'Others', dimensions_unit: 'ft' };
    },
    posServiceQuantityInput() { return { value: '1' }; },
    pfPrepareServiceFormDimensions() {}
  });
  vm.runInContext(collectSource, context);
  const collected = context.posCollectTarpaulinStockCustomization();
  assert.strictEqual(collected.width, '2', 'Others custom panel width still collected');
  assert.strictEqual(collected.height, '2', 'Others custom panel height still collected');
}

assert.match(calcSource, /function printflow_tarpaulin_calculate_linear_length/,
  'Tarpaulin consumption uses linear length from saved customer dimensions');
assert.match(customSource, /usage_method: isTarpaulin && estimatedQty !== null \? 'tarpaulin_linear_ft' : 'manual'/,
  'Production Assignment Actual Usage uses tarpaulin_linear_ft, distinct from order dimensions');
assert.match(customSource, /Calculated from customer Width × Height against the selected Tarpaulin roll width/,
  'calculated Actual Usage is initialized from preserved customer dimensions + roll width');
assert.match(customSource, /handlePendingTarpaulinDimensionInput/,
  'production assignment can still recalculate when height changes');
assert.match(customSource, /Customer print size:/,
  'Custom Orders keep the requested print size visible without a Width input');
assert.doesNotMatch(customSource, />Width \(ft\)/,
  'Production Assignment does not show a Width (ft) input');
assert.match(customSource, /Height \(ft\)/,
  'Custom Orders / production assignment still expose Height (ft) where required');
assert.match(posSource, /custom-dim-width/,
  'other services retain custom dimension width inputs in POS');
assert.match(posSource, /Brochure|Sintraboard|printflow_pos_material_selection_requires_dimensions/,
  'other services remain dimension-enabled in POS');

console.log(JSON.stringify({
  data_loss_point: 'redundant POS Width/Height inputs removed; Dimensions selection derives width/height for cart/stock/pricing',
  status: 'POS Tarpaulin dimensions handoff regression tests passed.'
}));
