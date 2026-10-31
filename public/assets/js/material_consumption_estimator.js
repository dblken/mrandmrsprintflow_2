(function (root, factory) {
    const api = factory();
    if (typeof module === 'object' && module.exports) module.exports = api;
    if (root) root.PrintFlowMaterialConsumption = api;
})(typeof globalThis !== 'undefined' ? globalThis : this, function () {
    'use strict';

    const SUPPORTED_METHODS = new Set(['PER_PIECE', 'SHEET_YIELD', 'DIMENSION_SHEET', 'BY_LENGTH', 'BY_AREA', 'MANUAL']);
    const UNIT_TO_INCHES = { IN: 1, INCH: 1, INCHES: 1, MM: 1 / 25.4, CM: 1 / 2.54, FT: 12, FEET: 12 };

    function finitePositive(value) {
        const n = Number(value);
        return Number.isFinite(n) && n > 0 ? n : null;
    }

    function roundUpForUom(value, uom) {
        if (String(uom || '').toLowerCase() === 'pcs' || String(uom || '').toLowerCase() === 'sheet') return Math.ceil(value - 1e-9);
        return Math.ceil(value * 100 - 1e-9) / 100;
    }

    function manual(reason) {
        return { status: 'manual', quantity: null, reason };
    }

    function estimate(item, order = {}) {
        const method = String(item && item.consumption_method || '').trim().toUpperCase();
        const orderQty = finitePositive(order.quantity);
        if (!SUPPORTED_METHODS.has(method) || method === 'MANUAL' || !orderQty) {
            return manual('Enter and confirm the actual material usage.');
        }
        const waste = Number(item.wastage_percent || 0);
        if (!Number.isFinite(waste) || waste < 0 || waste > 100) return manual('Material wastage configuration is invalid.');
        const factor = 1 + waste / 100;

        if (method === 'PER_PIECE') {
            return { status: 'estimated', quantity: roundUpForUom(orderQty * factor, item.unit_of_measure), method };
        }
        if (method === 'SHEET_YIELD') {
            const yieldCount = finitePositive(item.designs_per_unit);
            if (!yieldCount || !Number.isInteger(yieldCount)) return manual('Set a valid designs-per-sheet yield or confirm manually.');
            return { status: 'estimated', quantity: Math.ceil((orderQty * factor) / yieldCount - 1e-9), method };
        }
        if (method === 'DIMENSION_SHEET') {
            const unit = String(item.material_dimension_unit || '').trim().toUpperCase();
            const toInches = UNIT_TO_INCHES[unit];
            const sheetW = finitePositive(item.material_width);
            const sheetH = finitePositive(item.material_height);
            const designW = finitePositive(order.width_ft);
            const designH = finitePositive(order.height_ft);
            if (!toInches || !sheetW || !sheetH || !designW || !designH) return manual('Verified sheet and design dimensions are required for this estimate.');
            const marginW = Math.max(0, Number(item.usable_margin_width || 0));
            const marginH = Math.max(0, Number(item.usable_margin_height || 0));
            if (!Number.isFinite(marginW) || !Number.isFinite(marginH) || marginW * 2 >= sheetW || marginH * 2 >= sheetH) {
                return manual('Configured sheet margins leave no usable area.');
            }
            const usableW = (sheetW - 2 * marginW) * toInches;
            const usableH = (sheetH - 2 * marginH) * toInches;
            const dw = designW * 12;
            const dh = designH * 12;
            const fit = (w, h) => Math.floor(usableW / w + 1e-9) * Math.floor(usableH / h + 1e-9);
            let perSheet = Math.max(fit(dw, dh), fit(dh, dw));
            const configuredYield = finitePositive(item.designs_per_unit);
            if (configuredYield && Number.isInteger(configuredYield)) perSheet = Math.min(perSheet, configuredYield);
            if (perSheet < 1) return { status: 'no_fit', quantity: null, method, reason: 'The design does not fit on the configured usable sheet in either orientation.' };
            return { status: 'estimated', quantity: Math.ceil((orderQty * factor) / perSheet - 1e-9), method, designs_per_sheet: perSheet };
        }
        if (method === 'BY_LENGTH') {
            const measured = finitePositive(order.required_material_length);
            return measured
                ? { status: 'estimated', quantity: roundUpForUom(measured * factor, item.unit_of_measure), method }
                : manual('A verified required length is not available; enter actual usage.');
        }
        // Area cannot safely be converted to the application's pcs/ft/l stock UOM.
        return manual('Area usage cannot be converted to this material UOM; confirm the actual quantity.');
    }

    return { estimate, SUPPORTED_METHODS: Array.from(SUPPORTED_METHODS) };
});
