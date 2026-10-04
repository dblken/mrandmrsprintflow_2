<?php
declare(strict_types=1);

/**
 * Lightweight server-side discount math checks (no DB bootstrap).
 */
function pf_pos_discount_calc(string $type, float $value, float $subtotal): array
{
    $subtotal = round(max(0, $subtotal), 2);
    if ($type === 'none' || $value <= 0 || $subtotal <= 0) {
        return ['amount' => 0.0, 'final_total' => $subtotal];
    }
    $amount = $type === 'percentage'
        ? round($subtotal * ($value / 100), 2)
        : round($value, 2);
    $amount = round(min(max(0, $amount), $subtotal), 2);
    return [
        'amount' => $amount,
        'final_total' => round(max(0, $subtotal - $amount), 2),
    ];
}

function pf_pos_discount_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException('FAIL: ' . $message);
    }
}

$case = pf_pos_discount_calc('fixed', 100, 1000);
pf_pos_discount_assert(abs($case['final_total'] - 900) < 0.001, '1000 - 100 fixed discount = 900');
pf_pos_discount_assert(abs($case['amount'] - 100) < 0.001, 'fixed discount amount is 100');

$percent = pf_pos_discount_calc('percentage', 10, 1000);
pf_pos_discount_assert(abs($percent['amount'] - 100) < 0.001, '10% of 1000 = 100');
pf_pos_discount_assert(abs($percent['final_total'] - 900) < 0.001, '10% discount final total = 900');

$change = 1000 - 900;
pf_pos_discount_assert($change === 100, '1000 tender on 900 total yields 100 change');

echo "POS discount validation math checks passed.\n";
