<?php

$source = file_get_contents(__DIR__ . '/../staff/customizations.php');
$api = file_get_contents(__DIR__ . '/../admin/inventory_items_api.php');

$assertions = [
    'custom picker script is loaded' => str_contains($source, '/public/assets/js/production_material_picker.js'),
    'native material select was replaced' => !str_contains($source, '<option value="">-- Choose Material --</option>'),
    'picker has an internal bounded scroller' => str_contains($source, '.production-material-results') && str_contains($source, 'max-height: 260px'),
    'only truly blocked compatibility states are disabled' => str_contains($source, ':disabled="!item.compatibility.selectable"') && str_contains($source, ':aria-disabled="item.compatibility.selectable'),
    'keyboard material selection is wired' => str_contains($source, '@keydown.arrow-down.prevent') && str_contains($source, '@keydown.enter.prevent'),
    'one click queues the material with an editable Actual Usage field' => str_contains($source, 'queueMaterialFromPicker(item') && str_contains($source, 'Actual Usage:') && !str_contains($source, '>Add actual usage</button>') && !str_contains($source, '>Confirm Actual Usage</button>'),
    'Tarpaulin assignment shows requested print size and material usage without a Width input or roll-code text' => str_contains($source, 'handlePendingTarpaulinDimensionInput')
        && str_contains($source, 'Requested print')
        && str_contains($source, 'Material usage')
        && !str_contains($source, 'Tarpaulin customer width in feet')
        && !str_contains($source, 'tarpaulinAllocationSummary(pm)')
        && str_contains($source, 'pos-tarp-assignment-dims')
        && str_contains($source, 'customer_width_ft')
        && str_contains($source, 'customer_height_ft')
        && str_contains($source, 'x-show="!isPendingTarpaulinMaterial(pm)"')
        && str_contains($source, 'calculated_linear_ft'),
    'Tarpaulin auto-selects the fitted roll and can prefill calculated Actual Usage' => str_contains($source, 'maybeAutoSelectTarpaulinRoll') && str_contains($source, 'tarpaulin_linear_ft') && str_contains($source, 'out.Width') && str_contains($source, 'out.Height'),
    'secondary add-to-content button is removed' => !str_contains($source, '>Add to Order Content</button>'),
    'selected state persists for queued and saved materials' => str_contains($source, "'is-selected': isMaterialSelected(item.id)") && str_contains($source, 'isMaterialSelected(itemId)'),
    'selected state includes visible check badge' => str_contains($source, 'production-material-option__selected-badge') && str_contains($source, 'Selected'),
    'hover and keyboard focus use themed accent styling' => str_contains($source, '.production-material-option:hover') && str_contains($source, '.production-material-option:focus-visible') && str_contains($source, '--staff-accent-rgb'),
    'material starts unselected' => str_contains($source, "newMaterialId: ''"),
    'recommendations never invoke generic handleMaterialSelection automatically' => !preg_match('/(?:init|finishDetailLoadWith|loadAllInventoryItems)[\s\S]{0,500}handleMaterialSelection\s*\(/', $source),
    'Tarpaulin restores automatic mapped-roll selection from customer dimensions' => str_contains($source, 'maybeAutoSelectTarpaulinRoll()')
        && str_contains($source, 'this.$nextTick(() => this.maybeAutoSelectTarpaulinRoll())'),
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
    'material usage stays manual and is not inferred from order dimensions' => str_contains($source, "return { status: 'manual', quantity: null, method: 'manual'") && str_contains($source, 'Staff enters actual usage in the inventory unit.'),
    'modal design uses one combined dynamic section' => str_contains($source, 'staffDesignSectionLabel(item)') && str_contains($source, 'staffDesignDisplayFilename(item)') && !str_contains($source, 'Design Preview'),
    'modal dimensions use one combined label' => str_contains($source, "const displayLabel = 'Dimensions'") && str_contains($source, 'staffSpecIsRedundantDimensionPart'),
    'calculated total area is hidden when dimensions exist' => str_contains($source, "meta.group === 'total_area' && (presentGroups.dimensions || (presentGroups.width && presentGroups.height))") && str_contains($source, 'staffDimensionDisplayStripKeys'),
    'material picker reads raw dimensions before display enrichment' => str_contains($source, 'const rawSpecs = materialService.primaryCustom') && str_contains($source, 'staffExtractDimensionParts(rawSpecs'),
    'design uploads are excluded from spec grid cards' => str_contains($source, 'staffSpecIsDesignDisplayField(k, v, item)'),
    'modal specs resolve from raw customization payload' => str_contains($source, 'staffResolveItemCustomizationSource') && str_contains($source, 'customization_data'),
    'detail modal preserves submitted specs and avoids service-profile filtering' => str_contains($source, 'let specs = isDetail || options.skipServiceFilter === true') && str_contains($source, 'submittedSpecs') && str_contains($source, 'this.staffFilterSpecsByServiceForm(normalized, item)'),
    'item customization payload is preserved for fulfillment logic' => str_contains($source, 'const customization = merged') && !str_contains($source, 'const customization = this.normalizeSpecAliases(merged)'),
    'spec rendering uses one normalization pipeline' => str_contains($source, 'staffBuildItemDisplaySpecs') && str_contains($source, 'staffEnrichDimensionSpecs'),
    'internal POS stock metadata is excluded by exact keys' => str_contains($source, "'pos_selected_material_ids'") && str_contains($source, "'pos_material_dimension_unit'") && str_contains($source, "'pos_material_consumption'") && str_contains($source, "'pos_tarpaulin_selection'"),
    'assigned undeducted material can invoke the guarded correction request' => str_contains($source, "this.removeMaterial(assignment.id)") && str_contains($source, "fd.append('csrf_token'") && str_contains($source, 'await this.refreshMaterials();'),
    'deducted assigned material stays protected in the UI' => str_contains($source, 'Material Already Deducted') && str_contains($source, 'authorized inventory correction workflow'),
    'canonical dimensions label is never suppressed by width height aliases' => str_contains($source, "key !== 'Dimensions'") && str_contains($source, "key !== 'Size / Dimensions'"),
    'order notes box is hidden when item specs already include the same note' => str_contains($source, 'staffOrderNotesRenderedInItemSpecs') && str_contains($source, '!staffOrderNotesRenderedInItemSpecs()') && str_contains($source, 'orderFingerprint'),
    'notes aliases collapse to one Notes label' => str_contains($source, "meta.group === 'notes' ? 'Notes' : meta.label"),
];

$failed = array_keys(array_filter($assertions, static fn($passed) => !$passed));
if ($failed) {
    fwrite(STDERR, "production_assignment_ui_test: FAIL\n - " . implode("\n - ", $failed) . "\n");
    exit(1);
}

echo "production_assignment_ui_test: PASS\n";
