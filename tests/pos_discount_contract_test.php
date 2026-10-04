<?php
$root = dirname(__DIR__);

function pf_pos_discount_assert(bool $condition, string $message): void {
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$pos = file_get_contents($root . '/staff/pos.php');
$checkout = file_get_contents($root . '/staff/api/pos_checkout.php');
$receipt = file_get_contents($root . '/includes/pos_receipt.php');
$printer = file_get_contents($root . '/includes/pos_receipt_printer.php');
$reports = file_get_contents($root . '/includes/reports_dashboard_queries.php');
$salesPage = file_get_contents($root . '/admin/sales.php');
$migration = file_get_contents($root . '/database/migrate_pos_order_discounts_20261004.php');

pf_pos_discount_assert(strpos($pos, 'function applyPosDiscount()') !== false, 'POS UI supports applying discount');
pf_pos_discount_assert(strpos($pos, 'function cancelPosDiscountEdit()') !== false, 'POS UI supports canceling discount edits');
pf_pos_discount_assert(strpos($pos, 'id="pos-discount-error"') !== false, 'POS UI shows discount validation errors');
pf_pos_discount_assert(strpos($pos, 'Grand Total') !== false, 'POS summary labels grand total');
pf_pos_discount_assert(strpos($pos, 'Senior Citizen') !== false, 'POS reason list includes Senior Citizen');
pf_pos_discount_assert(strpos($checkout, 'function pos_discount_allowed_reasons()') !== false, 'Backend defines allowed discount reasons');
pf_pos_discount_assert(strpos($checkout, 'Discount reason is required') !== false, 'Backend requires discount reason');
pf_pos_discount_assert(strpos($pos, 'function posCheckoutDiscountPayload()') !== false, 'POS checkout sends discount payload');
pf_pos_discount_assert(strpos($pos, 'tendered < currentTotal') !== false, 'POS tender validation uses discounted currentTotal');
pf_pos_discount_assert(strpos($checkout, 'function pos_validate_checkout_discount') !== false, 'Backend validates discount server-side');
pf_pos_discount_assert(strpos($checkout, '$final_total_amount') !== false && strpos($checkout, '$amount_tendered < $final_total_amount') !== false, 'Backend validates payment against discounted total');
pf_pos_discount_assert(strpos($checkout, 'pos_discount_amount') !== false && strpos($checkout, 'pos_subtotal_amount') !== false, 'Backend stores discount audit columns');
pf_pos_discount_assert(strpos($checkout, 'POS Discount Applied') !== false, 'Backend logs discount audit activity');
pf_pos_discount_assert(strpos($receipt, "'reason' => (string)(\$order['pos_discount_reason']") !== false, 'Receipt payload includes discount reason');
pf_pos_discount_assert(strpos($printer, "printflow_receipt_pair(\$discountLabel, '-' . printflow_receipt_money") !== false, 'Thermal receipt prints discount line');
pf_pos_discount_assert(strpos($reports, "order_source, ''))) = 'pos'") !== false, 'Reports use net order total for POS revenue');
pf_pos_discount_assert(strpos($reports, 'discount_amount') !== false, 'Reports expose discount amount in transaction rows');
pf_pos_discount_assert(strpos($salesPage, '<th class="num">Discount</th>') !== false, 'Sales table shows Discount column');
pf_pos_discount_assert(strpos($migration, 'pos_discount_applied_at') !== false, 'Migration adds discount audit columns');

pf_pos_discount_assert(strpos($checkout, '$discountTypes = \'dsddssi\';') !== false, 'Discount bind types match seven discount params');

echo "POS discount contract checks passed.\n";
