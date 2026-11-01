<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/service_material_rule_assignments.php';

$assert = static function (bool $ok, string $message): void {
    if (!$ok) throw new RuntimeException($message);
};
$required = ['item_id' => 301, 'rule_type' => 'REQUIRED', 'qty_multiplier' => '1.50', 'qty_multiplier_approved' => 1, 'qty_multiplier_approved_by' => 17, 'qty_multiplier_approved_at' => '2026-11-01 10:15:30'];
$optional = ['item_id' => 302, 'rule_type' => 'OPTIONAL', 'qty_multiplier' => '2.00', 'qty_multiplier_approved' => 1];
$customerAssignments = printflow_build_approved_service_material_assignments([$required, $optional], 4, 2.5, 'Test Service');
$assert(count($customerAssignments) === 1 && $customerAssignments[0]['item_id'] === 301, 'Approved REQUIRED rule is assigned and OPTIONAL rule is excluded.');
$assert($customerAssignments[0]['quantity'] === 6.0 && $customerAssignments[0]['uom'] === 'ft' && $customerAssignments[0]['computed_len'] === 15.0,
    'Approved multiplier scales the existing quantity and height-based length demand.');
$assert(printflow_build_approved_service_material_assignments([$optional], 4, 0, 'Test Service') === [], 'OPTIONAL-only configuration creates no automatic assignment.');

foreach ([
    ['item_id' => 301, 'rule_type' => 'REQUIRED', 'qty_multiplier' => null, 'qty_multiplier_approved' => 0],
    ['item_id' => 301, 'rule_type' => 'REQUIRED', 'qty_multiplier' => '1.00', 'qty_multiplier_approved' => 0],
    ['item_id' => 301, 'rule_type' => 'REQUIRED', 'qty_multiplier' => '1.00', 'qty_multiplier_approved' => null],
    ['item_id' => 301, 'rule_type' => 'REQUIRED', 'qty_multiplier' => '1.00', 'qty_multiplier_approved' => 1, 'qty_multiplier_approved_by' => null, 'qty_multiplier_approved_at' => '2026-11-01 10:15:30'],
    ['item_id' => 301, 'rule_type' => 'REQUIRED', 'qty_multiplier' => '1.00', 'qty_multiplier_approved' => 1, 'qty_multiplier_approved_by' => 17, 'qty_multiplier_approved_at' => null],
] as $unapprovedRule) {
    try {
        printflow_build_approved_service_material_assignments([$unapprovedRule], 1, 0, 'Test Service');
        throw new RuntimeException('Unapproved REQUIRED material rule unexpectedly produced an assignment.');
    } catch (RuntimeException $error) {
        if (!str_contains($error->getMessage(), 'item #301') || !str_contains($error->getMessage(), 'no valid Admin-approved multiplier')) throw $error;
    }
}
try {
    printflow_build_approved_service_material_assignments([
        ['item_id' => 303, 'rule_type' => 'REQUIRED', 'qty_multiplier' => '1.00', 'qty_multiplier_approved' => 1, 'qty_multiplier_approved_by' => 17, 'qty_multiplier_approved_at' => '2026-11-01 10:15:30', 'item_status' => 'INACTIVE'],
    ], 1, 0, 'Test Service');
    throw new RuntimeException('Inactive REQUIRED material unexpectedly produced an assignment.');
} catch (RuntimeException $error) {
    if (!str_contains($error->getMessage(), 'not active in inventory')) throw $error;
}

$customer = (string)file_get_contents(dirname(__DIR__) . '/customer/customer_order_api.php');
$historical = (string)file_get_contents(dirname(__DIR__) . '/admin/historical_transaction_generator.php');
$assert(str_contains($customer, 'printflow_build_approved_service_material_assignments($requiredRules, $qty, $height, $service)'), 'Customer order path uses the shared approval-gated assignment builder.');
$assert(str_contains($customer, "UPPER(TRIM(r.rule_type)) = 'REQUIRED'") && str_contains($customer, 'qty_multiplier_approved')
    && str_contains($customer, 'qty_multiplier_approved_by') && str_contains($customer, 'qty_multiplier_approved_at')
    && str_contains($customer, 'i.status AS item_status'), 'Customer order queries only REQUIRED rows and validates multiplier, audit, and active inventory.');
$assert(str_contains($historical, 'printflow_filter_approved_required_material_rules(')
    && str_contains($historical, "r.qty_multiplier_approved")
    && str_contains($historical, 'r.qty_multiplier_approved_by') && str_contains($historical, 'r.qty_multiplier_approved_at')
    && str_contains($historical, <<<'SOURCE'
'quantity' => max(1, $quantity) * (float)$rule['approved_multiplier']
SOURCE
    ),
    'Historical plan filters optional rules and scales quantities only with explicitly approved multipliers.');
$insert = strpos($historical, 'function htg_insert(');
$idempotency = strpos($historical, "SELECT order_id FROM orders WHERE reference_id = ? LIMIT 1 FOR UPDATE", $insert ?: 0);
$orderInsert = strpos($historical, "INSERT INTO orders", $insert ?: 0);
$reuse = strpos($historical, 'if ($existing !== []) return', $insert ?: 0);
$assert($insert !== false && $idempotency !== false && $reuse !== false && $orderInsert !== false && $idempotency < $orderInsert && $reuse < $orderInsert,
    'Historical generator retries reuse the existing order reference before adding assignments or stock movements.');

echo "Service material assignment contract tests passed.\n";
