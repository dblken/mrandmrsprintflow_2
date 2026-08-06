<?php

function pricing_security_assert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
    echo "PASS: {$message}\n";
}

/**
 * Every direct FormData caller for a protected action must carry a nearby CSRF
 * token append. Looking on both sides supports shared builders that append the
 * token before choosing the action.
 */
function pricing_security_assert_callers_have_csrf(string $source, string $label): void
{
    preg_match_all(
        "/fd\\.append\\('action', '(?:update_customization|update_order_price|set_price)'\\)/",
        $source,
        $matches,
        PREG_OFFSET_CAPTURE
    );
    pricing_security_assert(!empty($matches[0]), "{$label} contains a protected pricing/customization caller");
    foreach ($matches[0] as $match) {
        $offset = (int)$match[1];
        $window = substr($source, max(0, $offset - 300), 700);
        pricing_security_assert(
            strpos($window, "fd.append('csrf_token'") !== false,
            "{$label} sends a CSRF token with each protected caller"
        );
    }
}

$root = dirname(__DIR__);
$api = (string)file_get_contents($root . '/admin/job_orders_api.php');
$customizations = (string)file_get_contents($root . '/staff/customizations.php');
$customizationsV2 = (string)file_get_contents($root . '/staff/customizations_v2.php');
$jobOrders = (string)file_get_contents($root . '/staff/job_orders_management.php');
$orders = (string)file_get_contents($root . '/staff/orders.php');

pricing_security_assert(
    strpos($api, 'function jo_api_require_staff_mutation(): void') !== false
        && strpos($api, "has_role(['Admin', 'Manager', 'Staff'])") !== false
        && strpos($api, "verify_csrf_token((string)(\$_POST['csrf_token'] ?? ''))") !== false,
    'protected mutations require a staff role and valid CSRF token'
);
pricing_security_assert(
    substr_count($api, 'jo_api_require_staff_mutation();') >= 3,
    'all three pricing/customization mutation cases invoke the shared guard'
);
pricing_security_assert(
    strpos($api, 'jo_api_require_staff_order_branch($joStaffBranch, $linked_order_id);') !== false,
    'customization mutations enforce linked-order branch ownership'
);
pricing_security_assert(
    strpos($api, 'printflow_money_to_centavos($rawPrice)') !== false,
    'final prices are parsed as exact centavos instead of permissive floats'
);
pricing_security_assert(
    strpos($api, "status IN ('generating', 'awaiting_payment', 'paid')") !== false
        && strpos($api, 'FOR UPDATE') !== false,
    'price updates lock records and reject active or paid provider sessions'
);
pricing_security_assert(
    strpos($api, "'PROCESSING', 'IN_PRODUCTION', 'PRINTING'") !== false
        && strpos($api, "'COMPLETED', 'CANCELLED', 'REJECTED'") !== false,
    'production and terminal orders reject final-price edits'
);
pricing_security_assert(
    strpos($api, 'price_finalized_at = NOW(), price_finalized_by = ?') !== false
        && strpos($api, 'UPDATE orders SET total_amount = ?') !== false,
    'the authoritative order total is saved with optional finalization audit metadata'
);
pricing_security_assert(
    strpos($api, 'UPDATE order_items SET unit_price = ? WHERE order_id = ? LIMIT 1') === false,
    'an order total is never assigned to one arbitrary line-item unit price'
);
pricing_security_assert(
    substr_count($api, 'begin_transaction()') >= 3
        && substr_count($api, 'Unable to commit the price update.') >= 2,
    'order and job price mutations use explicit database transactions'
);

pricing_security_assert_callers_have_csrf($customizations, 'staff/customizations.php');
pricing_security_assert_callers_have_csrf($customizationsV2, 'staff/customizations_v2.php');
pricing_security_assert_callers_have_csrf($jobOrders, 'staff/job_orders_management.php');
pricing_security_assert_callers_have_csrf($orders, 'staff/orders.php');

pricing_security_assert(
    strpos($jobOrders, 'data-csrf="<?php echo htmlspecialchars(generate_csrf_token()') !== false,
    'job order management exposes a generated CSRF token to its protected caller'
);

echo "Pricing endpoint security regression test passed.\n";
