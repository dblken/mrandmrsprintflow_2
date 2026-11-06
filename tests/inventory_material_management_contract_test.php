<?php
$api = file_get_contents(__DIR__ . '/../admin/inventory_items_api.php');
$migration = file_get_contents(__DIR__ . '/../database/migrate_inventory_material_configuration_20261026.php');
$form = file_get_contents(__DIR__ . '/../admin/inv_items_management.php');
$staff = file_get_contents(__DIR__ . '/../staff/customizations.php');

$checks = [
    'material rule display reads optional rate metadata only behind schema guards' => str_contains($api, "db_table_has_column('service_material_rules', 'qty_multiplier', true)") && str_contains($api, "'NULL AS qty_multiplier'") && str_contains($api, 'r.qty_multiplier'),
    'usage metadata is returned through i.*' => str_contains($api, 'SELECT i.*, c.name as category_name'),
    'create and update persist configuration fields' => substr_count($api, 'size_variant') >= 5 && str_contains($api, 'consumption_method'),
    'inventory CRUD bind types match their SQL placeholders' => (static function () use ($api): bool {
        preg_match_all('/\$sql\s*=\s*"((?:UPDATE|INSERT INTO).*?)";\s*global \$conn;\s*\$stmt = \$conn->prepare\(\$sql\);\s*\$stmt->bind_param\("([^"]+)"/s', $api, $matches, PREG_SET_ORDER);
        if (count($matches) !== 3) return false;
        foreach ($matches as $match) {
            if (substr_count($match[1], '?') !== strlen($match[2])) return false;
        }
        return true;
    })(),
    'configuration migration is additive and verifies exact existing column names' => str_contains($migration, 'SHOW COLUMNS FROM `inv_items`') && !str_contains($migration, 'SHOW COLUMNS FROM `inv_items` LIKE') && str_contains($migration, "\$field = (string)(\$row['Field'] ?? '');") && str_contains($migration, 'ADD COLUMN `designs_per_unit`'),
    'nullable SQL defaults are verified as explicit NULL values' => str_contains($migration, 'array_key_exists(\'Default\', $row)'),
    'edit form exposes optional material configuration' => str_contains($form, 'id="materialUsageConfiguration"') && str_contains($form, 'name="designs_per_unit"'),
    'staff enters actual usage in the selected inventory unit without an estimate field' => str_contains($staff, 'Actual Usage') && str_contains($staff, 'Enter the actual quantity used in the inventory unit.') && str_contains($staff, "newMaterialQty: ''") && !str_contains($staff, 'Estimated Usage:'),
    'manual assignment confirmation gates saving' => str_contains($staff, 'manual_confirmation_required && !material.manual_quantity_confirmed'),
    'manual usage confirmation state does not auto-fill from a calculated estimate' => str_contains($staff, 'pendingMaterialUsageConfirmationState') && str_contains($staff, 'isValidPendingMaterialActualQty') && str_contains($staff, 'manual_confirmation_required: true') && str_contains($staff, 'manual_quantity_confirmed: false') && str_contains($staff, 'estimated_qty: null') && str_contains($staff, 'required_minimum_qty: null'),
    'actual usage input gates confirmation on trusted edit intent' => str_contains($staff, '@beforeinput="markPendingMaterialQtyEdit(pm, $event.target, $event)"') && str_contains($staff, '@keydown="markPendingMaterialQtyKeyEdit(pm, $event.target, $event)"') && str_contains($staff, '@keyup="clearPendingMaterialQtyKeyEdit($event.target)"') && str_contains($staff, '@pointerdown="markPendingMaterialQtyPointerEdit(pm, $event.target, $event)"') && str_contains($staff, '@input="handlePendingMaterialQtyInput(pm, $event.target.value, $event.target, $event)"') && str_contains($staff, '@blur="handlePendingMaterialQtyBlur(pm, $event.target, $event)"') && str_contains($staff, "event?.isTrusted !== true") && str_contains($staff, 'allowedEditTypes') && str_contains($staff, 'insertReplacementText') === false,
    'manual usage is confirmed only for a trusted valid positive quantity in its unit' => str_contains($staff, 'material.manual_quantity_confirmed = explicitEdit && this.isValidPosMaterialQuantity(raw, material.item_id);') && str_contains($staff, 'staff_confirmed: material.manual_quantity_confirmed') && str_contains($staff, 'parseMaterialQuantity(value, item) !== null'),
    'editing valid usage clears only its matching stale confirmation warning' => str_contains($staff, 'if (material.manual_quantity_confirmed) this.clearPendingMaterialConfirmationError(material);') && str_contains($staff, 'if (this.productionErrors.material === expected) this.productionErrors.material = \'\';'),
    'explicit confirmation remains required and recalculation preserves manual quantity' => str_contains($staff, '@click="confirmPendingMaterialUsage(pm)"') && str_contains($staff, 'material.qty = raw === \'\' ? \'\' : qty;') && str_contains($staff, 'manual_quantity_confirmed: false'),
    'final usage remains posted as assignment quantity' => str_contains($staff, "fd.append('quantity', pm.qty)"),
];

$failed = [];
foreach ($checks as $label => $pass) {
    echo ($pass ? 'PASS: ' : 'FAIL: ') . $label . "\n";
    if (!$pass) $failed[] = $label;
}
if ($failed) exit(1);
