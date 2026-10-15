<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$read = static fn(string $path): string => (string)file_get_contents($root . '/' . $path);
$pos = $read('staff/pos.php');
$checkout = $read('staff/api/pos_checkout.php');
$serviceFields = $read('staff/api/pos_service_fields.php');
$renderer = $read('includes/service_field_renderer.php');
$inventory = $read('includes/product_branch_stock.php');
$inventoryManager = $read('includes/InventoryManager.php');
$jobs = $read('includes/JobOrderService.php');
$functions = $read('includes/functions.php');
$trace = $read('staff/api/pos_transaction_trace.php');
$receipt = $read('includes/pos_receipt.php');
$migration = $read('migrate_db.php');

$passed = 0;
$assert = static function (bool $condition, string $message) use (&$passed): void {
    if (!$condition) {
        throw new RuntimeException('FAIL: ' . $message);
    }
    $passed++;
    echo 'PASS: ', $message, PHP_EOL;
};

$assert(
    !str_contains($pos, 'use_custom_transaction_datetime')
        && !str_contains($pos, 'posCheckoutCustomTransactionPayload')
        && !str_contains($pos, 'Set Date &amp; Time')
        && !str_contains($pos, 'printflow_allow_past_needed_date')
        && str_contains($pos, 'Needed date cannot be in the past.'),
    'POS no longer exposes backdate controls and validates needed dates client-side'
);
$assert(
    str_contains($checkout, 'function pos_checkout_selected_transaction_datetime(array $data): string')
        && str_contains($checkout, "return date('Y-m-d H:i:s');")
        && !str_contains($checkout, 'custom_transaction_date')
        && str_contains($checkout, '$selectedTransactionAt = pos_checkout_selected_transaction_datetime($data);'),
    'checkout always uses current server time for new transactions'
);
$assert(
    str_contains($serviceFields, 'name="needed_date"')
        && str_contains($serviceFields, '$needed_date_min = date(\'Y-m-d\')')
        && !str_contains($serviceFields, 'printflow_allow_past_needed_date'),
    'POS service fields keep needed_date min set to today'
);
$assert(
    str_contains($renderer, 'function printflow_service_field_date_min_attr')
        && !str_contains($renderer, 'printflow_allow_past_needed_date'),
    'service field renderer always applies today min on date fields'
);
$assert(
    str_contains($checkout, 'order_date, updated_at')
        && preg_match('/array_merge\(\s*\[\$customer_id\],\s*\$guestParams,\s*\[\$branch_id,\s*\$reference_id,[\s\S]*\$selectedTransactionAt,\s*\$order_type\]/', $checkout) === 1,
    'order insert binds the canonical timestamp as order_date'
);
$assert(
    str_contains($checkout, 'printflow_apply_product_order_item_inventory(')
        && str_contains($checkout, "'POS sale',")
        && str_contains($checkout, '$selectedTransactionAt')
        && !str_contains($checkout, 'pos_checkout_inventory_ledger_date'),
    'POS passes the complete canonical timestamp to the inventory writer'
);
$assert(
    str_contains($inventory, "\$transactionDateForLedger = \$transactionDate ?: date('Y-m-d H:i:s');")
        && !str_contains($inventory, 'substr($transactionDateForLedger, 0, 10)'),
    'inventory writer does not truncate the selected timestamp to a date'
);
$assert(
    str_contains($inventory, 'MODIFY transaction_date TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP')
        && str_contains($migration, '`transaction_date` timestamp NULL DEFAULT CURRENT_TIMESTAMP'),
    'new and existing inventory schemas retain transaction time'
);
$assert(
    str_contains($functions, 'function printflow_store_order_ledger_date(int $order_id): ?string')
        && str_contains($functions, 'return $raw;')
        && !str_contains($functions, "return strlen(\$raw) >= 10 ? substr(\$raw, 0, 10) : \$raw;"),
    'material deductions inherit the complete saved order timestamp'
);
$assert(
    str_contains($inventoryManager, "\$date = \$date ?: date('Y-m-d H:i:s');")
        && !str_contains($inventoryManager, 'substr($date, 0, 10)')
        && !str_contains($jobs, 'substr($ledgerTransactionDate, 0, 10)'),
    'material, ink, and roll ledger writers preserve a supplied timestamp'
);
$assert(
    str_contains($receipt, "\$receiptDateTime = (string)(\$order['order_date'] ?? '');")
        && !str_contains($receipt, "providerPayment['paid_at'] ?? \$order['order_date']"),
    'receipt displays the saved order transaction timestamp'
);
$assert(
    str_contains($checkout, "'transaction_date' => \$selectedTransactionAt"),
    'checkout response exposes the saved transaction timestamp'
);
$assert(
    str_contains($trace, "'order' => \$order")
        && str_contains($trace, "'material_assignments' => \$materials")
        && str_contains($trace, "'inventory_transactions' => \$ledger")
        && str_contains($trace, "UPPER(ref_type) = 'JOB_ORDER'"),
    'staff-only trace endpoint exposes saved order, material, and ledger evidence'
);

echo "POS custom transaction datetime contract: {$passed} assertions passed.\n";
