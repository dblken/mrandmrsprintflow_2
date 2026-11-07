(function (root, factory) {
    const api = factory();
    if (typeof module === 'object' && module.exports) module.exports = api;
    if (root) root.PrintFlowMaterialConsumption = api;
})(typeof globalThis !== 'undefined' ? globalThis : this, function () {
    'use strict';

    const WHOLE_UNITS = new Set([
        'pc', 'pcs', 'piece', 'pieces', 'sheet', 'sheets', 'roll', 'rolls',
        'unit', 'units', 'set', 'sets', 'bottle', 'bottles', 'pack', 'packs'
    ]);

    /** Subli Paper is stored in sheets but Mugs consume half-sheets (0.5/mug). */
    function allowsFractionalSheetUsage(item) {
        const uom = String(item && item.unit_of_measure || '').trim().toLowerCase();
        if (uom !== 'sheet' && uom !== 'sheets') return false;
        const itemId = Number(item && (item.id != null ? item.id : item.item_id));
        const name = String(item && item.name || '').trim().toLowerCase();
        return itemId === 55 || name === 'subli paper';
    }

    /** Quantity must be a positive actual amount in the saved inventory UOM. */
    function parseMaterialQuantity(value, item) {
        const uom = String(item && item.unit_of_measure || '').trim().toLowerCase();
        if (!uom) return null;
        const raw = String(value ?? '').trim();
        // Whole units also accept DECIMAL round-trips such as "1.00" from saved
        // assignments; fractional discrete values like "1.5" remain invalid.
        // Subli Paper sheets are the exception: 0.5 sheet per mug is valid.
        const wholeUnit = WHOLE_UNITS.has(uom) && !allowsFractionalSheetUsage(item);
        const pattern = wholeUnit
            ? /^[1-9][0-9]{0,7}(?:\.0+)?$/
            : /^(?:0|[1-9][0-9]{0,7})(?:\.[0-9]{1,2})?$/;
        if (!pattern.test(raw)) return null;
        const quantity = Number(raw);
        if (!Number.isFinite(quantity) || quantity <= 0 || quantity > 99999999.99) return null;
        return quantity;
    }

    /** Compatibility entry point: quantities are always entered by staff. */
    function estimate() {
        return { status: 'manual', quantity: null, method: 'manual', reason: 'Staff enters actual usage in the inventory unit.' };
    }

    return { estimate, parseMaterialQuantity };
});
