<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/pos_draft_lifecycle.php';

function assert_true(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

assert_true(pos_order_is_voidable_unfinalized_draft([
    'order_source' => 'pos_draft',
    'payment_status' => 'Unpaid',
    'status' => 'Draft',
]), 'pos_draft unpaid draft is voidable');

assert_true(pos_order_is_voidable_unfinalized_draft([
    'order_source' => 'pos',
    'payment_status' => 'Unpaid',
    'status' => 'Approved',
]), 'priced but unpaid POS order is voidable');

assert_true(!pos_order_is_voidable_unfinalized_draft([
    'order_source' => 'pos_merged',
    'payment_status' => 'Unpaid',
    'status' => 'Pending',
]), 'pos_merged checkout order is not voidable');

assert_true(!pos_order_is_voidable_unfinalized_draft([
    'order_source' => 'pos',
    'payment_status' => 'Paid',
    'status' => 'Pending',
]), 'paid POS order is not voidable');

assert_true(!pos_order_is_voidable_unfinalized_draft([
    'order_source' => 'pos_draft',
    'payment_status' => 'Unpaid',
    'status' => 'Completed',
]), 'completed draft source is not voidable');

assert_true(pos_customization_json_marked_as_pos('{"source":"POS","dimensions":"2 x 8 ft"}'), 'POS customization marker is detected');
assert_true(!pos_customization_json_marked_as_pos('{"source":"Online"}'), 'non-POS customization marker is ignored');

$linkedIds = pos_cart_item_draft_order_ids(['pending_order_id' => 42]);
assert_true($linkedIds === [42], 'cart draft resolver returns linked pending order ids');

echo "pos_draft_lifecycle_test: PASS\n";
