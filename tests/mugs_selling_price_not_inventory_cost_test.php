<?php
declare(strict_types=1);

/**
 * Mugs customer selling price must come from services.price only.
 * Inventory Mug unit/average cost must never be used or invented as the selling price.
 */

$root = dirname(__DIR__);
$assert = static function (bool $ok, string $message): void {
    if (!$ok) {
        throw new RuntimeException($message);
    }
};

require_once $root . '/includes/mugs_base_pricing.php';

$pricingSources = [
    'includes/mugs_base_pricing.php',
    'includes/service_order_helper.php',
    'includes/service_design_fee.php',
    'staff/api/pos_service_fields.php',
    'staff/api/pos_cart_handler.php',
    'public/assets/js/service_estimated_price.js',
    'customer/order_service_dynamic.php',
];

foreach ($pricingSources as $relative) {
    $source = (string)file_get_contents($root . '/' . $relative);
    $assert(!preg_match('/average_cost|avg_cost|unit_cost|average cost/i', $source)
        || str_contains($source, 'Inventory average cost is not used')
        || str_contains($source, 'never use inv_items average/cost')
        || str_contains($source, 'Inventory average cost is never used')
        || str_contains($source, 'Inventory unit/average cost is never used')
        || str_contains($source, 'Inventory unit cost is never substituted')
        || str_contains($source, 'Do not copy the Mug inventory'),
        $relative . ' must not read inventory unit/average cost as a selling price.');
    $assert(!preg_match('/InventoryManager::getItem\s*\(\s*37\s*\).*price|getItem\s*\(\s*37\s*\).*average/is', $source),
        $relative . ' must not pull Mug inventory item cost into pricing.');
}

$unitHelper = (string)file_get_contents($root . '/includes/service_order_helper.php');
$assert(str_contains($unitHelper, "SELECT COALESCE(price, 0) AS base_price FROM services WHERE service_id = ?")
    && str_contains($unitHelper, 'printflow_is_mugs_service($serviceId)')
    && str_contains($unitHelper, 'Inventory average cost is not used'),
    'Canonical unit pricing reads services.price and refuses unconfigured Mugs without cost fallback.');

$posFields = (string)file_get_contents($root . '/staff/api/pos_service_fields.php');
$assert(str_contains($posFields, "COALESCE(price, 0) AS base_price")
    && str_contains($posFields, "'base_price'")
    && !str_contains($posFields, 'average_cost')
    && !str_contains($posFields, 'InventoryManager::getItem'),
    'POS service fields expose services.price as base_price and do not load inventory cost.');

$admin = (string)file_get_contents($root . '/admin/services_management.php');
$assert(str_contains($admin, "input.value = Number.isFinite(price) && price > 0 ? price.toFixed(2) : '';")
    && !str_contains($admin, ": '100.00'")
    && str_contains($admin, 'Never invent a selling price'),
    'Admin Mugs price field does not invent ₱100 or copy inventory unit cost.');

$estimator = (string)file_get_contents($root . '/public/assets/js/service_estimated_price.js');
$assert(str_contains($estimator, 'const unitPrice = basePrice + optionsTotal;')
    && str_contains($estimator, 'unitPrice * safeQty + designFee')
    && str_contains($estimator, 'printflowNeedsMissingEstimatedPriceConfirm'),
    'Estimator uses configured base/options × qty + one-time layout fee, and exposes the final-estimate warning helper.');

// Runtime: unconfigured services.price fails closed (no invented inventory cost).
$missing = printflow_mugs_line_totals_from_unit_price(0.0, 4);
$assert(empty($missing['ok']), 'Unconfigured Mugs selling price does not invent a per-Mug amount.');
$assert(str_contains((string)$missing['message'], 'services.price'), 'Unconfigured message names services.price as the source.');

// Runtime: an independently configured selling price (not 100) is honored.
$configured = printflow_mugs_line_totals_from_unit_price(75.0, 4);
$assert(!empty($configured['ok']) && (float)$configured['unit_price'] === 75.0 && (float)$configured['line_subtotal'] === 300.0,
    'Independently configured services.price (₱75) × qty is used; inventory cost is irrelevant.');

$rejectMissing = printflow_mugs_require_configured_selling_price([
    'ok' => false,
    'unit_price' => 0.0,
    'reason' => 'unconfigured',
    'message' => 'Configure a positive Mugs per-piece selling price on the service record (services.price). Inventory average cost is not used.',
]);
$assert($rejectMissing !== null && str_contains($rejectMissing, 'Inventory average cost is not used'),
    'POS/customer guards reject fee-only or cost-derived Mugs unit prices.');

$assert(
    str_contains((string)file_get_contents($root . '/staff/api/pos_cart_handler.php'), 'Inventory unit/average cost is never used')
    && str_contains((string)file_get_contents($root . '/customer/order_service_dynamic.php'), 'Inventory unit cost is never substituted'),
    'POS and customer keep inventory unit cost out of selling-price fallbacks.'
);

echo "Mugs selling price vs inventory cost separation tests passed.\n";
