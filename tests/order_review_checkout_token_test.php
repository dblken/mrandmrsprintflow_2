<?php
declare(strict_types=1);

$root = dirname(__DIR__);

function review_item_quantity(array $item): int {
    return max(1, (int)($item['quantity'] ?? 1));
}
function review_item_unit_price(array $item): float {
    return (float)($item['price'] ?? $item['unit_price'] ?? $item['estimated_price'] ?? 0);
}
function review_item_estimated_total(array $item): float {
    $estimated = (float)($item['estimated_price'] ?? 0);
    return $estimated > 0 ? $estimated : 0.0;
}
function review_item_customization(array $item): array {
    $custom = $item['customization'] ?? [];
    return is_array($custom) ? $custom : [];
}

require_once $root . '/includes/order_review_checkout_token.php';

$failures = 0;
$assert = static function (bool $ok, string $message) use (&$failures): void {
    if (!$ok) {
        fwrite(STDERR, "FAIL: {$message}\n");
        $failures++;
    }
};

$reviewSource = (string)file_get_contents($root . '/customer/order_review.php');
$assert(str_contains($reviewSource, 'order_review_checkout_token.php')
    && str_contains($reviewSource, 'review_checkout_token_accept_for_attempt')
    && str_contains($reviewSource, 'refresh_checkout_token'),
    'order review wires shared checkout token helpers and stale-token refresh responses.');

$mugLine = [
    'service_id' => 63,
    'quantity' => 1,
    'price' => 0,
    'customization' => [
        'service_id' => '63',
        'layout' => 'without_layout',
        'needed_date' => '2026-11-09',
        'order_priority' => 'Regular Order',
        'branch_id' => '1',
    ],
    '_cart_key' => 'service_63_1794232380_393',
];

$beforePricing = $mugLine;
$afterPricing = $mugLine;
$afterPricing['design_fee'] = 200.0;
$afterPricing['estimated_price'] = 200.0;
$afterPricing['price'] = 0.0;
$afterPricing['customization']['design_fee'] = '200.00';
$afterPricing['customization']['design_fee_version'] = 1;
$afterPricing['customization']['calculated_estimated_price'] = '200.00';

$prePricingFingerprint = review_checkout_fingerprint_line($beforePricing);
$postPricingFingerprint = review_checkout_fingerprint_line($afterPricing);
$assert($prePricingFingerprint !== $postPricingFingerprint,
    'Unpriced service cart lines change fingerprint once authoritative pricing is applied.');

$stableA = review_build_checkout_fingerprint(12, [
    'service_63_1794232380_393' => review_checkout_fingerprint_line($afterPricing),
]);
$stableB = review_build_checkout_fingerprint(12, [
    'service_63_1794232380_393' => review_checkout_fingerprint_line($afterPricing),
]);
$assert(hash_equals($stableA, $stableB), 'Post-pricing fingerprint is stable across reload.');

$_SESSION = [];
$attemptKey = review_checkout_attempt_storage_key(['service_63_1794232380_393']);
$tokenA = review_ensure_checkout_token(12, $stableA, $attemptKey);
$tokenB = review_ensure_checkout_token(12, $stableA, $attemptKey);
$assert(hash_equals($tokenA, $tokenB), 'The same fingerprint reuses the issued checkout token.');

$driftFingerprint = review_build_checkout_fingerprint(12, [
    'service_63_1794232380_393' => $prePricingFingerprint,
]);
$tokenAfterDrift = review_ensure_checkout_token(12, $driftFingerprint, $attemptKey);
$tokenAfterReprice = review_ensure_checkout_token(12, $stableA, $attemptKey);
$assert(hash_equals($tokenA, $tokenAfterDrift) && hash_equals($tokenA, $tokenAfterReprice),
    'The same cart item key keeps its checkout token across fingerprint drift.');
$assert(
    review_checkout_token_accept_for_attempt($tokenA, $tokenAfterReprice, $attemptKey, $stableA),
    'Submitting the browser-held token still succeeds after repricing.'
);
$assert(!review_checkout_token_accept_for_attempt('0' . str_repeat('a', 63), $tokenAfterReprice, $attemptKey, $stableA),
    'Unknown tokens are still rejected.');

if ($failures > 0) {
    fwrite(STDERR, "{$failures} assertion(s) failed.\n");
    exit(1);
}

fwrite(STDOUT, "order_review_checkout_token_test: OK\n");
