<?php

$root = dirname(__DIR__);
$api = file_get_contents($root . '/admin/job_orders_api.php');
$workflow = file_get_contents($root . '/includes/revision_workflow.php');
$staff = file_get_contents($root . '/staff/customizations.php');

$assert = function ($condition, string $message): void {
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
};

$assert(
    strpos($api, "[REVISION REQUEST]") === false,
    'new revision requests must not append revision metadata into job_order notes'
);

$assert(
    strpos($workflow, "printflow_revision_key_group(\$key) === 'order_notes'") !== false,
    'item-level notes/job_notes must be excluded from revision comparison rows'
);

$assert(
    strpos($staff, 'Original:') !== false && strpos($staff, 'Updated:') !== false,
    'revision comparison should use Original/Updated labels'
);

$assert(
    strpos($staff, 'Previous:') === false && strpos($staff, 'Revised:') === false,
    'revision comparison should not show Previous/Revised value labels'
);

$resolverStart = strpos($staff, 'combinedCustomerNotes() {');
$resolverEnd = $resolverStart === false ? false : strpos($staff, "\n            staffResolveFieldValueFromSource", $resolverStart);
$resolver = ($resolverStart !== false && $resolverEnd !== false)
    ? substr($staff, $resolverStart, $resolverEnd - $resolverStart)
    : '';

$assert($resolver !== '', 'combinedCustomerNotes resolver should be present');
$assert(
    strpos($resolver, 'job_notes') === false
        && strpos($resolver, 'jobnotes') === false
        && strpos($resolver, 'JobNotes') === false
        && strpos($resolver, "'Job Notes'") === false
        && strpos($resolver, '"Job Notes"') === false,
    'customer notes resolver must not read job notes as customer notes'
);
$assert(
    strpos($resolver, '\\[REVISION REQUEST\\]') !== false,
    'customer notes resolver should safely strip legacy revision markers for display'
);
$assert(
    strpos($resolver, 'const revisedOrderNote = this.staffLatestRevisionOrderNotes();') !== false
        && strpos($resolver, 'cleanCustomerNote(j.store_order_notes || \'\')') !== false
        && strpos($resolver, 'const revisedOrderNote = this.staffLatestRevisionOrderNotes();') < strpos($resolver, 'cleanCustomerNote(j.store_order_notes || \'\')'),
    'customer notes resolver should prefer the latest submitted revision note before stored order notes'
);

$sourceStart = strpos($staff, 'staffResolveItemCustomizationSource(custom, item = null) {');
$sourceEnd = $sourceStart === false ? false : strpos($staff, "\n            staffBuildItemDisplaySpecs", $sourceStart);
$sourceResolver = ($sourceStart !== false && $sourceEnd !== false)
    ? substr($staff, $sourceStart, $sourceEnd - $sourceStart)
    : '';

$assert($sourceResolver !== '', 'item customization source resolver should be present');
$assert(
    strpos($sourceResolver, 'sourceCustom.notes = revisedOrderNotes') !== false
        && strpos($sourceResolver, "this.staffCustomizationFieldMeta(key).group === 'notes'") !== false
        && strpos($sourceResolver, 'delete sourceCustom[key]') !== false
        && strpos($sourceResolver, 'sourceCustom.order_notes = revisedOrderNotes') !== false,
    'item spec source should replace notes aliases with the latest revised order note for one current Notes tile'
);
$assert(
    strpos($sourceResolver, "this.staffCustomizationFieldMeta(key).group === 'quantity'") !== false
        && strpos($sourceResolver, 'sourceCustom.quantity = item.quantity') !== false,
    'item spec source should replace stale quantity aliases with the current order item quantity'
);

echo "revision_notes_separation_test: PASS\n";
