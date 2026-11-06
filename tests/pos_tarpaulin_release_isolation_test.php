<?php
declare(strict_types=1);
$root = dirname(__DIR__);
$validator = file_get_contents($root . '/includes/pos_service_stock_validation.php');
$cart = file_get_contents($root . '/staff/api/pos_cart_handler.php');
$checkout = file_get_contents($root . '/staff/api/pos_checkout.php');
$jobApi = file_get_contents($root . '/admin/job_orders_api.php');
$mugsRule = file_get_contents($root . '/includes/pos_mugs_release_rule.php');
$assert = static function (bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); };
$assert(str_contains($validator, 'if ($serviceId === 63)') && str_contains($validator, "(int)\$material['item_id'] !== 37")
    && str_contains($mugsRule, 'printflow_pos_mugs_release_material_rule'), 'Dedicated Mugs inventory item validation remains present.');
$assert(str_contains($validator, "if (!array_key_exists('pos_material_assignments', \$line))")
    && str_contains($validator, 'if ($serviceId === 40)')
    && !str_contains(substr($validator, strpos($validator, 'function printflow_pos_validate_service_stock(')), 'printflow_pos_tarpaulin_selection('),
    'POS service stock validation defers material demand to manually assigned actual usage, including Tarpaulin.');
$assert(!str_contains($checkout, 'pos_checkout_pair_tarpaulin_cart_lines')
    && str_contains($checkout, 'pos_checkout_validate_assigned_service_materials($items, $pos_pending_orders_snapshot)'),
    'Checkout validates actual assigned material quantities and no longer requires an automatic Tarpaulin panel plan.');
$assert(str_contains($cart, 'pos_cart_validate_service_payload')
    && str_contains($cart, 'printflow_pos_validate_service_stock('),
    'Tarpaulin service specifications and available explicit assignments still pass through POS validation.');
$assert(str_contains($validator, 'Tarpaulin service identity changed after stock validation.'), 'A client cannot relabel Tarpaulin cart line identity to evade validation.');
$jobService = file_get_contents($root . '/includes/JobOrderService.php');
$assert(str_contains($jobService, 'RollService::deductWholePanelsFIFO') && str_contains($jobService, 'tarpaulin_panel_plan'), 'Only marked v2 Tarpaulin assignments use whole-panel completion deductions.');
$rollService = file_get_contents($root . '/includes/RollService.php');
$assert(str_contains($rollService, 'printflow_tarpaulin_allocate_whole_panels($rolls, $plan)')
    && str_contains($jobService, "'actual_usage_version'] ?? '') === 'manual_v1'"),
    'Historical versioned Tarpaulin rows remain readable while new manual rows use their entered quantities.');
$assert(str_contains($jobService, 'printflow_tarpaulin_panel_plans_match')
    && str_contains($jobApi, "\$metadata['actual_usage_version'] = 'manual_v1';")
    && str_contains($jobApi, '$rawQuantity = trim((string)($_POST[\'quantity\'] ?? \'\'))'),
    'Historical panel-plan deduction support remains readable while new assignments store manual actual usage.');
$assert(str_contains($validator, 'POS captures customer specifications only. Exact material and')
    && str_contains($validator, "'tarpaulin_selections' => \$tarpaulinSelections"),
    'The current POS validator leaves Tarpaulin material choices empty until staff records actual usage.');
$retryBlock = strpos($checkout, 'if ($isPayMongo && !empty($sessionContext[\'pos_paymongo_checkouts\'][$checkoutToken]))');
$retryValidate = strpos($checkout, 'pos_checkout_validate_assigned_service_materials(', $retryBlock === false ? 0 : $retryBlock);
$retryPayment = strpos($checkout, "printflow_provider_payment_create_qrph(", $retryBlock === false ? 0 : $retryBlock);
$assert($retryBlock !== false && $retryValidate !== false && $retryPayment !== false && $retryBlock < $retryValidate && $retryValidate < $retryPayment, 'A checkout retry revalidates saved manual material stock before reusing the existing payment flow.');
echo "Tarpaulin release isolation assertions passed.\n";
