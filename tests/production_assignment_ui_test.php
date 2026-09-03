<?php

$source = file_get_contents(__DIR__ . '/../staff/customizations.php');
$api = file_get_contents(__DIR__ . '/../admin/inventory_items_api.php');

$assertions = [
    'custom picker script is loaded' => str_contains($source, '/public/assets/js/production_material_picker.js'),
    'native material select was replaced' => !str_contains($source, '<option value="">-- Choose Material --</option>'),
    'picker has an internal bounded scroller' => str_contains($source, '.production-material-results') && str_contains($source, 'max-height: 260px'),
    'disabled compatibility state is exposed' => str_contains($source, ':aria-disabled="item.compatibility.selectable'),
    'keyboard material selection is wired' => str_contains($source, '@keydown.arrow-down.prevent') && str_contains($source, '@keydown.enter.prevent'),
    'material starts unselected' => str_contains($source, "newMaterialId: ''"),
    'recommendations never invoke selection automatically' => !preg_match('/(?:init|finishDetailLoadWith|loadAllInventoryItems)[\s\S]{0,500}handleMaterialSelection\s*\(/', $source),
    'active inventory is requested' => str_contains($source, 'inventory_items_api.php?action=get_items&active_only=1'),
    'item 63 is excluded from picker data' => str_contains($source, 'Number(item.id) !== 63'),
    'active service rules are returned' => str_contains($api, "WHERE i.status = 'ACTIVE'") && str_contains($api, "'material_rules' => \$materialRules"),
    'price helper copy is removed' => !str_contains($source, 'Set the final amount, then continue to POS to receive payment.'),
    'POS helper copy is removed' => !str_contains($source, 'Saving here keeps the item in the POS cart so staff can continue payment on the walk-in POS page.'),
    'price step uses peso display' => str_contains($source, '[3] Set Final Price') && str_contains($source, "x-text=\"'₱' + Number(currentJo.estimated_price"),
    'modal shell size was not redefined by picker CSS' => !preg_match('/\.production-material-results\s*\{[^}]*\bwidth\s*:\s*(?:[5-9]\d{2}|\d{4,})px/i', $source),
];

$failed = array_keys(array_filter($assertions, static fn($passed) => !$passed));
if ($failed) {
    fwrite(STDERR, "production_assignment_ui_test: FAIL\n - " . implode("\n - ", $failed) . "\n");
    exit(1);
}

echo "production_assignment_ui_test: PASS\n";
