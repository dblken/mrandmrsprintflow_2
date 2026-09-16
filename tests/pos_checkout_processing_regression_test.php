<?php
declare(strict_types=1);

$pos = file_get_contents(__DIR__ . '/../staff/pos.php');
$checkout = file_get_contents(__DIR__ . '/../staff/api/pos_checkout.php');
if ($pos === false || $checkout === false) {
    throw new RuntimeException('Unable to read POS checkout sources.');
}

$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException('FAIL: ' . $message);
};

$assert(
    strpos($pos, "fetchWithTimeout(staffUrl('staff/api/pos_checkout.php')") !== false,
    'final checkout request has a bounded network wait'
);
$assert(
    strpos($pos, "syncedCartAction('clear', {}, {silentErrors: true, timeoutMs: 10000})") !== false
        && strpos($pos, 'cart = [];') !== false,
    'post-commit cart clearing cannot leave checkout processing forever'
);
$assert(
    strpos($pos, 'posCheckoutRequestInFlight = false;') !== false
        && preg_match('/finally \{[\s\S]*posCheckoutRequestInFlight = false;[\s\S]*updateCheckoutState\(\);/s', $pos) === 1,
    'checkout always clears the in-flight flag and restores the button in finally'
);
$assert(
    strpos($checkout, "if (!preg_match('/^[a-f0-9]{32,64}$/', \$checkoutToken))") !== false,
    'all cash and PayMongo submissions require an idempotency token'
);
$assert(
    strpos($checkout, 'function pos_checkout_persist_session_state(') !== false
        && strpos($checkout, "\$_SESSION['pos_checkout_orders'][\$checkoutToken] = \$orderId;") !== false
        && strpos($checkout, "'duplicate_request' => true") !== false,
    'a retry returns the already committed sale instead of creating another order'
);
$assert(
    preg_match('/if \(\s*!\$conn->commit\(\)\s*\)\s*\{[\s\S]*?\$checkout_committed = true;/', $checkout) === 1
        && strpos($checkout, 'if (!empty($order_id) && $checkout_committed)') !== false
        && strpos($checkout, '} catch (Throwable $e) {') !== false,
    'checkout_committed is set only after a successful commit'
);
$assert(
    strpos($checkout, 'function pos_checkout_persist_session_state(') !== false
        && strpos($checkout, 'session_write_close();') !== false,
    'checkout releases the session lock before heavy work and only reopens it briefly to persist state'
);
$cartHandler = file_get_contents(__DIR__ . '/../staff/api/pos_cart_handler.php');
if ($cartHandler === false) {
    throw new RuntimeException('Unable to read POS cart handler source.');
}
$assert(
    strpos($cartHandler, '$cartResponse = array_values($_SESSION[\'pos_cart\'] ?? []);') !== false
        && strpos($cartHandler, 'session_write_close();') !== false
        && strpos($cartHandler, 'pos_cart_effective_product_stock') !== false,
    'cart refresh releases the session lock before per-item stock lookups'
);
$assert(
    preg_match('/if \(res\.ok && data\.success\) \{[\s\S]*?updateCheckoutState\(\);[\s\S]*?syncedCartAction\(\'clear\'/', $pos) === 1,
    'checkout UI is restored before optional post-commit cart clearing'
);
$assert(
    strpos($checkout, 'if (!$isPayMongo && $amount_tendered < $total_amount)') !== false
        && strpos($checkout, ': (float)$p[\'price\'];') !== false,
    'cash payment and ready-made totals are validated server-side'
);
$assert(
    strpos($checkout, 'function pos_prefetch_products_by_ids(') !== false
        && strpos($checkout, 'SELECT product_id, price, name FROM products WHERE product_id IN') !== false,
    'checkout batches product lookups instead of querying each cart line'
);
$assert(
    strpos($checkout, 'function pos_customization_has_persisted_media_path(') !== false
        && strpos($checkout, 'pos_customization_has_persisted_media_path($custom_details, \'design_upload\')') !== false,
    'checkout skips re-reading persisted design files for every cart line'
);
$assert(
    strpos($checkout, 'if (!$isPayMongo && !$is_service && $is_actual_product)') !== false,
    'ready-made product inventory deducts during mixed POS checkout'
);
$assert(
    strpos($pos, 'function posCheckoutCustomizationPayload(') !== false
        && strpos($pos, 'cart.map(posCheckoutItemPayload)') !== false,
    'checkout request omits heavy inline upload blobs when paths already exist'
);
$assert(
    strpos($pos, "posCheckoutRequestInFlight && action !== 'clear'") !== false
        && strpos($pos, 'if (posCheckoutRequestInFlight) {') !== false,
    'checkout blocks competing cart sync and keeps Processing state stable'
);

echo "POS checkout processing regression test passed.\n";
