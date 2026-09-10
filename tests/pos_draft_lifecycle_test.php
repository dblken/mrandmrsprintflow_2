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

echo "pos_draft_lifecycle_test: PASS\n";
