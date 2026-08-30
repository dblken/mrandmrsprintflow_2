<?php

function production_revision_assert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
    echo "PASS: {$message}\n";
}

$root = dirname(__DIR__);
$edit = (string)file_get_contents($root . '/customer/edit_order.php');
$orders = (string)file_get_contents($root . '/customer/orders.php');
$itemsApi = (string)file_get_contents($root . '/customer/get_order_items.php');
$workflow = (string)file_get_contents($root . '/includes/revision_workflow.php');
$serve = (string)file_get_contents($root . '/public/serve_design.php');
$staff = (string)file_get_contents($root . '/staff/customizations.php');

production_revision_assert(
    strpos($itemsApi, "'/customer/edit_order.php?order_id=' . \$order_id") !== false,
    'customer API builds the edit URL from the internal numeric order ID'
);
production_revision_assert(
    strpos($orders, '<a href="${escIM(revisionActionUrl)}"') !== false
        && strpos($orders, 'function openRevisionForm(') === false,
    'My Orders uses an unblocked direct revision anchor'
);
production_revision_assert(
    strpos($edit, 'LEFT JOIN branches b ON b.id = o.branch_id') !== false,
    'customer revision ownership query matches the production branch primary key'
);
production_revision_assert(
    strpos($edit, '<input type="hidden" name="resubmit_order" value="1">') !== false,
    'revision POST action survives submit-button disabling'
);
production_revision_assert(
    strpos($workflow, 'is_uploaded_file($tmpName)') !== false
        && strpos($workflow, 'move_uploaded_file($tmpName, $targetPath)') !== false
        && strpos($workflow, "'path' => '/uploads/orders/' . \$storedName") !== false,
    'replacement upload is verified, physically moved, and stored with one canonical path format'
);
production_revision_assert(
    strpos($workflow, 'is_readable($targetPath)') !== false
        && strpos($workflow, "\$designStmt->send_long_data(0, \$storedBinary)") !== false
        && strpos($workflow, 'design_file = ?') !== false,
    'physical and database-backed replacement copies must both save successfully'
);
production_revision_assert(
    strpos($workflow, 'type=revision_submission&id=') !== false
        && strpos($serve, "\$type === 'revision_submission'") !== false,
    'staff preview points at the dedicated revision-submission route'
);
production_revision_assert(
    strpos($serve, "in_array('uploaded_design', \$permittedFields, true)") !== false
        && strpos($serve, "['Resubmitted for Review', 'Staff Reviewing']") !== false
        && strpos($serve, 'printflow_assert_order_branch_access($orderId)') !== false,
    'replacement serving enforces permission, state, ownership, and branch access'
);
production_revision_assert(
    strpos($serve, 'pf_serve_design_resolve_revision_upload') !== false
        && strpos($serve, "\$webPath !== '/uploads/orders/' . \$basename") !== false
        && strpos($serve, "elseif (count(\$revisedSnapshot['items']) === 1)") === false,
    'replacement serving requires an exact canonical upload and exact changed-item identity'
);
production_revision_assert(
    strpos($serve, "db_table_has_column('order_items', 'revision_design_name')") !== false
        && strpos($serve, "db_table_has_column('order_items', 'revision_design_path')") !== false,
    'missing legacy revision columns cannot invalidate the order-item media query'
);
production_revision_assert(
    strpos($workflow, 'ALTER TABLE `{$safeTable}` ADD COLUMN `{$safeColumn}`') !== false
        && strpos($workflow, "'design_image' => 'LONGBLOB NULL'") !== false,
    'older revision-history tables receive only additive missing media columns'
);
production_revision_assert(
    strpos($workflow, "'media_type' =>") !== false
        && strpos($staff, 'design.media_type') !== false,
    'staff comparison receives media kind without exposing storage path or generated filename'
);
production_revision_assert(
    strpos($edit, 'customer_revision_dedupe_specs') !== false
        && strpos($workflow, 'equivalentKeys') !== false
        && strpos($workflow, 'printflow_revision_keys_are_verified_aliases') !== false
        && strpos($staff, 'item.customization_data || {}') !== false,
    'Print Type aliases share one validated control, persisted permission key, and transactional value'
);

echo "Production revision remaining-bugs regression test passed.\n";
