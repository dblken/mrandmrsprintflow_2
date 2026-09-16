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
    strpos($checkout, "\$_SESSION['pos_checkout_orders'][\$checkoutToken] = (int)\$order_id;") !== false
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
    strpos($checkout, 'session_write_close();') !== false
        && strpos($checkout, 'SessionManager::start();') !== false,
    'checkout releases the session lock before the long-running sale transaction'
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

echo "POS checkout processing regression test passed.\n";
