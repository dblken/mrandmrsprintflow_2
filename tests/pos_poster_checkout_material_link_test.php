<?php
declare(strict_types=1);

/**
 * Poster Printing (service 64) checkout was returning 409
 * stage=service_material_stock_validation with:
 * "The assigned service materials could not be verified..."
 *
 * Root cause: pos_checkout_validate_assigned_service_materials required
 * $_SESSION['pos_pending_orders'][64] === cart.pending_order_id. After Set Price,
 * update_service_link wrote only the cart line; the map was often missing/stale.
 * This is not an out-of-stock failure.
 */

$root = dirname(__DIR__);
require_once $root . '/includes/pos_checkout_service_materials.php';

$assert = static function (bool $ok, string $message): void {
    if (!$ok) {
        throw new RuntimeException($message);
    }
};

$posterItem = [
    'id' => 64,
    'qty' => 1,
    'name' => 'Poster Printing',
    'is_service' => true,
    'pending_order_id' => 5001,
    'pending_order_item_id' => 9001,
    'pos_cart_line_id' => 'line-poster-1',
    'customization' => ['service_id' => 64, 'service_type' => 'Poster Printing'],
];

$sessionCart = [[
    'product_id' => 64,
    'qty' => 1,
    'is_service' => true,
    'pending_order_id' => 5001,
    'pending_order_item_id' => 9001,
    'pos_cart_line_id' => 'line-poster-1',
    'customization' => ['service_id' => 64],
]];

// Exact failing case: cart has Set Price draft link; session map is empty.
$resolved = printflow_pos_checkout_resolve_service_pending_order($posterItem, [], $sessionCart);
$assert(($resolved['status'] ?? '') === 'ok' && (int)$resolved['pending_order_id'] === 5001,
    'Poster Printing with cart pending_order_id and empty pos_pending_orders map must resolve OK.');

// Stale map must not override the authoritative cart line link.
$resolvedStaleMap = printflow_pos_checkout_resolve_service_pending_order(
    $posterItem,
    [64 => 1111],
    $sessionCart
);
$assert((int)$resolvedStaleMap['pending_order_id'] === 5001,
    'Server cart pending_order_id wins over a stale pos_pending_orders map entry.');

// Payload-only fallback when session cart is unavailable (legacy).
$resolvedPayload = printflow_pos_checkout_resolve_service_pending_order($posterItem, [], []);
$assert(($resolvedPayload['status'] ?? '') === 'ok' && (int)$resolvedPayload['pending_order_id'] === 5001,
    'Checkout payload pending_order_id is used when the session cart line is not matched.');

// Map-only fallback when cart/payload omit the link.
$resolvedMap = printflow_pos_checkout_resolve_service_pending_order(
    ['id' => 64, 'qty' => 1, 'is_service' => true, 'pending_order_id' => 0],
    [64 => 5001],
    []
);
$assert(($resolvedMap['status'] ?? '') === 'ok' && (int)$resolvedMap['pending_order_id'] === 5001,
    'pos_pending_orders map remains a valid fallback.');

// No draft link → skip (services without Set Price assignment).
$skipped = printflow_pos_checkout_resolve_service_pending_order(
    ['id' => 64, 'qty' => 1, 'is_service' => true, 'pending_order_id' => 0],
    [],
    []
);
$assert(($skipped['status'] ?? '') === 'skip', 'Missing draft link skips assigned-material revalidation.');

// Successful material stock path: assigned materials verify with no shortage.
$okStock = printflow_pos_checkout_evaluate_pending_material_stock(
    5001,
    9001,
    1,
    static fn(int $orderId, int $itemId): int => ($orderId === 5001 && $itemId === 9001) ? 7001 : 0,
    static fn(int $jobId, int $qty): array => []
);
$assert($okStock === null, 'Valid Poster assignment with sufficient stock returns no checkout error.');

// Real insufficient-stock rejection stays specific and actionable.
$shortStock = printflow_pos_checkout_evaluate_pending_material_stock(
    5001,
    9001,
    2,
    static fn(int $orderId, int $itemId): int => 7001,
    static fn(int $jobId, int $qty): array => [[
        'name' => 'C2s Board',
        'uom' => 'pcs',
        'required' => 2,
        'available' => 0,
    ]]
);
$assert(
    is_string($shortStock)
    && str_contains($shortStock, 'C2s Board')
    && str_contains($shortStock, '0')
    && str_contains($shortStock, '2')
    && str_contains($shortStock, 'available'),
    'Insufficient Poster material stock still blocks with a specific shortage message.'
);

// Runtime exception from the stock validator is surfaced unchanged.
$runtimeStock = printflow_pos_checkout_evaluate_pending_material_stock(
    5001,
    0,
    1,
    static fn(int $orderId, int $itemId): int => 7001,
    static function (int $jobId, int $qty): array {
        throw new RuntimeException('Assigned material quantity is invalid.');
    }
);
$assert($runtimeStock === 'Assigned material quantity is invalid.',
    'Invalid usage from the stock validator still blocks checkout.');

// Wiring: checkout uses the resolver + passes session cart; cart syncs the map.
$checkout = (string)file_get_contents($root . '/staff/api/pos_checkout.php');
$cart = (string)file_get_contents($root . '/staff/api/pos_cart_handler.php');
$assert(
    str_contains($checkout, 'printflow_pos_checkout_resolve_service_pending_order(')
    && str_contains($checkout, 'pos_checkout_validate_assigned_service_materials(')
    && str_contains($checkout, '$sessionCart')
    && !preg_match('/\$sessionPendingOrderId !== \$pendingOrderId/', $checkout),
    'Checkout no longer rejects when the session map disagrees with the cart draft link.'
);
$assert(
    str_contains($cart, "case 'update_service_link':")
    && str_contains($cart, "\$_SESSION['pos_pending_orders'][\$linkedProductId] = \$pending_order_id"),
    'update_service_link keeps pos_pending_orders aligned with the cart line.'
);

// Poster alternative mapping contract unchanged (C2s Board / C2S Special Paper).
require_once $root . '/includes/pos_material_selection.php';
$assert(isset(printflow_pos_material_selection_registry()[64][212])
    && isset(printflow_pos_material_selection_registry()[64][211]),
    'Poster Printing mapped papers 212/211 remain in the selection registry.');
$assert(printflow_production_assignment_selection_errors(64, [212]) === [],
    'One selected Poster paper remains valid.');
$assert(isset(printflow_production_assignment_selection_errors(64, [212, 211])['material_selection']),
    'Selecting both Poster paper alternatives still conflicts.');

echo "Poster Printing checkout material-link regression tests passed.\n";
