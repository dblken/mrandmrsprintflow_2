<?php
declare(strict_types=1);

/**
 * Mugs (service_id 63) quantity-based selling price.
 *
 * Source of truth: services.price (per-piece selling price).
 * Never use inv_items average/cost as the selling price.
 * Box Mug (and any non-mapped item) is excluded unless the dedicated
 * Mugs release mapping explicitly requires it (item 37 Mug only).
 */

if (!defined('PRINTFLOW_MUGS_SERVICE_ID')) {
    define('PRINTFLOW_MUGS_SERVICE_ID', 63);
}

if (!function_exists('printflow_is_mugs_service')) {
    function printflow_is_mugs_service(int $serviceId): bool
    {
        return $serviceId === PRINTFLOW_MUGS_SERVICE_ID;
    }
}

if (!function_exists('printflow_parse_mugs_selling_price_input')) {
    /**
     * Parse an admin/UI selling-price input for Mugs.
     * @return float|null Positive finite peso amount, or null when invalid.
     */
    function printflow_parse_mugs_selling_price_input($raw): ?float
    {
        if ($raw === null) {
            return null;
        }
        $text = trim((string)$raw);
        if ($text === '' || !is_numeric($text)) {
            return null;
        }
        $amount = (float)$text;
        if (!is_finite($amount) || $amount < 0.01 || $amount > 99999999.99) {
            return null;
        }
        // Two-decimal money canonicalization.
        return round($amount, 2);
    }
}

if (!function_exists('printflow_mugs_line_totals_from_unit_price')) {
    /**
     * Quantity-based Mugs line: configured per-piece selling price × quantity.
     * Design/layout fees are applied separately by the shared line-pricing helper.
     *
     * @return array{ok:bool,unit_price:float,quantity:int,line_subtotal:float,message:string}
     */
    function printflow_mugs_line_totals_from_unit_price(float $unitPrice, int $quantity): array
    {
        if ($quantity < 1 || $quantity > 1000000) {
            return [
                'ok' => false,
                'unit_price' => 0.0,
                'quantity' => $quantity,
                'line_subtotal' => 0.0,
                'message' => 'Quantity must be between 1 and 1,000,000.',
            ];
        }
        if (!is_finite($unitPrice) || $unitPrice < 0.01) {
            return [
                'ok' => false,
                'unit_price' => 0.0,
                'quantity' => $quantity,
                'line_subtotal' => 0.0,
                'message' => 'Configure a positive Mugs per-piece selling price on the service record (services.price). Inventory average cost is not used.',
            ];
        }
        $unit = round($unitPrice, 2);
        return [
            'ok' => true,
            'unit_price' => $unit,
            'quantity' => $quantity,
            'line_subtotal' => round($unit * $quantity, 2),
            'message' => '',
        ];
    }
}

if (!function_exists('printflow_mugs_assert_quantity_within_branch_stock')) {
    /**
     * Recheck Mug (item 37) availability for the selected branch.
     * Does not include Box Mug.
     *
     * @return array{ok:bool,available:float,required:float,message:string}
     */
    function printflow_mugs_assert_quantity_within_branch_stock(int $quantity, int $branchId): array
    {
        if ($quantity < 1) {
            return ['ok' => false, 'available' => 0.0, 'required' => 0.0, 'message' => 'Quantity must be a positive whole number.'];
        }
        if ($branchId <= 0) {
            return ['ok' => false, 'available' => 0.0, 'required' => (float)$quantity, 'message' => 'Select a valid branch before ordering Mugs.'];
        }
        if (!class_exists('InventoryManager')) {
            require_once __DIR__ . '/InventoryManager.php';
        }
        if (!function_exists('printflow_pos_mugs_release_material_rule')) {
            require_once __DIR__ . '/pos_mugs_release_rule.php';
        }
        $item = InventoryManager::getItem(37);
        try {
            $rules = printflow_pos_mugs_release_material_rule(PRINTFLOW_MUGS_SERVICE_ID, 'Mugs', is_array($item) ? $item : null);
        } catch (Throwable $error) {
            return [
                'ok' => false,
                'available' => 0.0,
                'required' => (float)$quantity,
                'message' => $error->getMessage(),
            ];
        }
        if (!is_array($rules) || $rules === []) {
            return [
                'ok' => false,
                'available' => 0.0,
                'required' => (float)$quantity,
                'message' => 'The dedicated Mug material mapping is unavailable.',
            ];
        }
        $available = (float)InventoryManager::getStockOnHand(37, $branchId);
        $required = (float)$quantity; // 1:1 pcs
        if ($required > $available + 0.000001) {
            return [
                'ok' => false,
                'available' => $available,
                'required' => $required,
                'message' => 'Insufficient Mug stock for this branch. Available: '
                    . rtrim(rtrim(number_format($available, 2, '.', ''), '0'), '.')
                    . ' pcs; requested: ' . $quantity . ' pcs.',
            ];
        }
        return [
            'ok' => true,
            'available' => $available,
            'required' => $required,
            'message' => '',
        ];
    }
}
