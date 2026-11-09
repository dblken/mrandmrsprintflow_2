<?php
declare(strict_types=1);
$root = dirname(__DIR__);
$validator = file_get_contents($root . '/includes/pos_service_stock_validation.php');
$tarpaulinCalculation = file_get_contents($root . '/includes/tarpaulin_material_calculation.php');
$cart = file_get_contents($root . '/staff/api/pos_cart_handler.php');
$checkout = file_get_contents($root . '/staff/api/pos_checkout.php');
$jobApi = file_get_contents($root . '/admin/job_orders_api.php');
$mugsRule = file_get_contents($root . '/includes/pos_mugs_release_rule.php');
$assert = static function (bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); };
$assert(str_contains($validator, 'if ($serviceId === 63)')
    && str_contains($validator, 'printflow_pos_mugs_assert_dedicated_mug_assigned($assigned)')
    && !str_contains($validator, "(int)\$material['item_id'] !== 37")
    && str_contains($mugsRule, 'printflow_pos_mugs_release_material_rule')
    && str_contains($mugsRule, 'function printflow_pos_mugs_assert_dedicated_mug_assigned'),
    'Dedicated Mugs validation requires item #37 among assignments and does not reject shared mapped materials.');
$validateBody = substr($validator, strpos($validator, 'function printflow_pos_validate_service_stock('));
$assert(str_contains($validateBody, "if (\$serviceId === 40 && !array_key_exists('pos_material_assignments', \$line))")
    && str_contains($validateBody, 'printflow_pos_tarpaulin_selection(')
    && str_contains($validator, 'printflow_tarpaulin_resolve_width_fit(')
    && str_contains($tarpaulinCalculation, 'printflow_tarpaulin_allocate_cart_panels('),
    'POS Tarpaulin Add to Order checks equal-or-wider physical rolls before staff Actual Usage.');
$assert(!str_contains($checkout, 'pos_checkout_pair_tarpaulin_cart_lines')
    && str_contains($checkout, 'pos_checkout_validate_assigned_service_materials(')
    && str_contains($checkout, '$pos_pending_orders_snapshot')
    && str_contains($checkout, '$sessionCart'),
    'Checkout validates actual assigned material quantities; joining panel pairing stays out of checkout.');
$assert(str_contains($cart, 'pos_cart_validate_service_payload')
    && str_contains($cart, 'printflow_pos_validate_service_stock('),
    'Tarpaulin service specifications and available explicit assignments still pass through POS validation.');
$assert(str_contains($validator, 'Tarpaulin service identity changed after stock validation.'), 'A client cannot relabel Tarpaulin cart line identity to evade validation.');
$jobService = file_get_contents($root . '/includes/JobOrderService.php');
$assert(str_contains($jobService, 'RollService::deductWholePanelsFIFO') && str_contains($jobService, 'tarpaulin_panel_plan')
    && str_contains($jobService, 'RollService::deductFromValidatedAllocation'), 'Saved POS width-fit plans constrain completion deduction to their allocated roll IDs.');
$rollService = file_get_contents($root . '/includes/RollService.php');
$assert(str_contains($rollService, 'printflow_tarpaulin_allocate_whole_panels($rolls, $plan)')
    && str_contains($jobService, "'actual_usage_version'] ?? '') === 'manual_v1'"),
    'Historical versioned Tarpaulin rows remain readable while new manual rows use their entered quantities.');
$assert(str_contains($jobService, 'printflow_tarpaulin_panel_plans_match')
    && str_contains($jobApi, "\$metadata['actual_usage_version'] = 'manual_v1';")
    && str_contains($jobApi, '$rawQuantity = trim((string)($_POST[\'quantity\'] ?? \'\'))'),
    'Historical panel-plan deduction support remains readable while new assignments store manual actual usage.');
$assert(str_contains($validator, 'printflow_pos_brochure_poster_is_early_stock_service')
    && str_contains($validator, 'Other non-Tarpaulin POS captures customer specifications only. Exact')
    && str_contains($validator, "'tarpaulin_selections' => \$tarpaulinSelections"),
    'Brochure/Poster use early paper capacity checks; remaining non-Tarpaulin services still defer exact usage; Tarpaulin selections persist for cart.');
$posUi = (string)file_get_contents($root . '/staff/pos.php');
$assert(!str_contains($posUi, 'Joining Direction')
    && !str_contains($posUi, 'Joining Allowance')
    && !str_contains($posUi, 'pos-tarpaulin-joining-controls'),
    'Joining Direction / Allowance remain absent from the Tarpaulin POS form.');
$retryBlock = strpos($checkout, 'if ($isPayMongo && !empty($sessionContext[\'pos_paymongo_checkouts\'][$checkoutToken]))');
$retryValidate = strpos($checkout, 'pos_checkout_validate_assigned_service_materials(', $retryBlock === false ? 0 : $retryBlock);
$retryPayment = strpos($checkout, "printflow_provider_payment_create_qrph(", $retryBlock === false ? 0 : $retryBlock);
$assert($retryBlock !== false && $retryValidate !== false && $retryPayment !== false && $retryBlock < $retryValidate && $retryValidate < $retryPayment, 'A checkout retry revalidates saved manual material stock before reusing the existing payment flow.');
echo "Tarpaulin release isolation assertions passed.\n";
