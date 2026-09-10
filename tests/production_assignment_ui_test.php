<?php

$source = file_get_contents(__DIR__ . '/../staff/customizations.php');
$api = file_get_contents(__DIR__ . '/../admin/inventory_items_api.php');

$assertions = [
    'custom picker script is loaded' => str_contains($source, '/public/assets/js/production_material_picker.js'),
    'native material select was replaced' => !str_contains($source, '<option value="">-- Choose Material --</option>'),
    'picker has an internal bounded scroller' => str_contains($source, '.production-material-results') && str_contains($source, 'max-height: 260px'),
    'only truly blocked compatibility states are disabled' => str_contains($source, ':disabled="!item.compatibility.selectable"') && str_contains($source, ':aria-disabled="item.compatibility.selectable'),
    'keyboard material selection is wired' => str_contains($source, '@keydown.arrow-down.prevent') && str_contains($source, '@keydown.enter.prevent'),
    'recommended material click queues immediately' => str_contains($source, 'this.addMaterialToQueue({ keepListOpen: true, allowQuantityAdjustment: true })'),
    'secondary add-to-content button is removed' => !str_contains($source, '>Add to Order Content</button>'),
    'selected state persists for queued and saved materials' => str_contains($source, "'is-selected': isMaterialSelected(item.id)") && str_contains($source, 'isMaterialSelected(itemId)'),
    'selected state includes visible check badge' => str_contains($source, 'production-material-option__selected-badge') && str_contains($source, 'Selected'),
    'hover and keyboard focus use themed accent styling' => str_contains($source, '.production-material-option:hover') && str_contains($source, '.production-material-option:focus-visible') && str_contains($source, '--staff-accent-rgb'),
    'material starts unselected' => str_contains($source, "newMaterialId: ''"),
    'recommendations never invoke selection automatically' => !preg_match('/(?:init|finishDetailLoadWith|loadAllInventoryItems)[\s\S]{0,500}handleMaterialSelection\s*\(/', $source),
    'active inventory is requested for the production picker' => str_contains($source, 'inventory_items_api.php?action=get_items&active_only=1&production_picker=1'),
    'item 63 is excluded from picker data' => str_contains($source, 'Number(item.id) !== 63'),
    'active service rules are returned' => str_contains($api, "WHERE i.status = 'ACTIVE'") && str_contains($api, "'material_rules' => \$materialRules"),
    'activated service catalog remains authoritative' => str_contains($api, "WHERE status = 'Activated'") && str_contains($api, "'active_services' => \$activeServices"),
    'existing stock status policy is returned' => str_contains($api, 'printflow_item_stock_status') && str_contains($source, "item.stock_status.label"),
    'unverified material state is visible and manually overrideable' => str_contains($source, "state.tier === 'unverified'") && str_contains($source, 'Usage not verified') && str_contains($source, 'Use unverified material?'),
    'manual override requires deliberate activation' => str_contains($source, '@dblclick="requestMaterialOverride(item)"') && str_contains($source, 'deliberateKeyboardAction'),
    'manual override has explicit cancel and confirm actions' => str_contains($source, 'cancelMaterialOverride()') && str_contains($source, 'confirmMaterialOverride()') && str_contains($source, '>Use Material</button>'),
    'manual override renders above the production detail modal' => str_contains($source, 'z-index:11020') && str_contains($source, 'z-index:11021'),
    'single click cannot open manual override' => str_contains($source, '@click="selectMaterialCandidate(item)"') && !str_contains($source, '@click="requestMaterialOverride(item)"'),
    'picker asset is cache busted' => str_contains($source, 'production_material_picker.js?v=') && str_contains($source, 'filemtime'),
    'price helper copy is removed' => !str_contains($source, 'Set the final amount, then continue to POS to receive payment.'),
    'POS helper copy is removed' => !str_contains($source, 'Saving here keeps the item in the POS cart so staff can continue payment on the walk-in POS page.'),
    'price step uses peso display' => str_contains($source, '[2] Set Final Price') && str_contains($source, "x-text=\"'₱' + Number(currentJo.estimated_price"),
    'obsolete ink options step is removed' => !str_contains($source, 'Ink Options') && !str_contains($source, 'SELECT INK SET') && !str_contains($source, 'Select Ink Set'),
    'obsolete ink guidance copy is removed' => !str_contains($source, 'No printer ink required') && !str_contains($source, 'Printer ink is no longer selected or recorded per order.'),
    'modal shell size was not redefined by picker CSS' => !preg_match('/\.production-material-results\s*\{[^}]*\bwidth\s*:\s*(?:[5-9]\d{2}|\d{4,})px/i', $source),
    'material usage resolves order dimensions before defaulting' => str_contains($source, 'resolveOrderDimensions(jo)') && str_contains($source, 'const dims = this.resolveOrderDimensions(this.currentJo)'),
    'modal design uses one combined section' => str_contains($source, '>Design</div>') && str_contains($source, 'staffDesignDisplayFilename(item)') && !str_contains($source, 'Design Preview'),
    'modal dimensions use one combined label' => str_contains($source, "const displayLabel = 'Size / Dimensions'") && str_contains($source, 'staffSpecIsRedundantDimensionPart'),
    'design uploads are excluded from spec grid cards' => str_contains($source, 'staffSpecIsDesignDisplayField(k, v, item)'),
];

$failed = array_keys(array_filter($assertions, static fn($passed) => !$passed));
if ($failed) {
    fwrite(STDERR, "production_assignment_ui_test: FAIL\n - " . implode("\n - ", $failed) . "\n");
    exit(1);
}

echo "production_assignment_ui_test: PASS\n";
