<?php

$root = dirname(__DIR__);
require_once $root . '/includes/staff_access.php';

function pos_modal_access_assert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
    echo "PASS: {$message}\n";
}

pos_modal_access_assert(printflow_order_channel_is_pos('pos_draft'), 'pos_draft is treated as a POS channel order');
pos_modal_access_assert(printflow_order_channel_is_pos('pos'), 'pos remains a POS channel order');
pos_modal_access_assert(!printflow_order_channel_is_pos('customer'), 'customer orders are not POS channel orders');

pos_modal_access_assert(
    printflow_staff_role_can_access_order_source('pos', 'pos_draft'),
    'authorized POS staff can access Set Price draft orders'
);
pos_modal_access_assert(
    !printflow_staff_role_can_access_order_source('online', 'pos_draft'),
    'online staff cannot access Set Price draft orders'
);
pos_modal_access_assert(
    printflow_staff_role_can_access_order_source('pos', 'pos_merged'),
    'POS staff can still access finalized walk-in orders'
);
pos_modal_access_assert(
    !printflow_staff_role_can_access_order_source('online', 'pos'),
    'online staff cannot access walk-in POS orders'
);
pos_modal_access_assert(
    printflow_staff_role_can_access_order_source('online', 'customer'),
    'online staff can access customer-channel orders'
);
pos_modal_access_assert(
    !printflow_staff_role_can_access_order_source('pos', 'customer'),
    'POS staff cannot access unrelated customer-channel orders'
);

$checkout = (string)file_get_contents($root . '/staff/api/pos_checkout.php');
pos_modal_access_assert(
    str_contains($checkout, "'pos_draft'"),
    'Set Price creates orders as pos_draft until checkout finalizes them'
);

$pos = (string)file_get_contents($root . '/staff/pos.php');
pos_modal_access_assert(
    str_contains($pos, "redirectUrl.searchParams.set('mode', 'pos_pricing')")
        && str_contains($pos, "redirectUrl.searchParams.set('source_order_id', data.order_id)"),
    'POS Set Price redirects into customizations with pos_pricing and source_order_id'
);

$customizations = (string)file_get_contents($root . '/staff/customizations.php');
pos_modal_access_assert(
    str_contains($customizations, "mode === 'pos_pricing' ? 'pos_pricing' : 'production_view'")
        && str_contains($customizations, 'get_order_for_modal.php'),
    'customizations deep link opens the modal through get_order_for_modal'
);

$modal = (string)file_get_contents($root . '/staff/get_order_for_modal.php');
pos_modal_access_assert(
    str_contains($modal, 'printflow_resolve_staff_order_source'),
    'order modal resolves POS Set Price draft channel before authorization'
);
pos_modal_access_assert(
    str_contains($modal, 'printflow_staff_role_can_access_order_source'),
    'order modal uses the shared staff channel authorization helper'
);
pos_modal_access_assert(
    str_contains($modal, 'printflow_assert_order_branch_access'),
    'order modal still enforces branch scope before returning order details'
);
pos_modal_access_assert(
    str_contains($modal, 'printflow_order_archive_scope_sql'),
    'archived orders stay excluded from the modal payload'
);

$sql = (string)file_get_contents($root . '/includes/staff_access.php');
pos_modal_access_assert(
    str_contains($sql, "'pos_draft'"),
    'staff list scope SQL includes pos_draft alongside other POS sources'
);

echo "POS Set Price modal access tests passed.\n";
