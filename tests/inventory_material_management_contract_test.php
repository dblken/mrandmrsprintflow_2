<?php
$api = file_get_contents(__DIR__ . '/../admin/inventory_items_api.php');
$migration = file_get_contents(__DIR__ . '/../database/migrate_inventory_material_configuration_20261026.php');
$form = file_get_contents(__DIR__ . '/../admin/inv_items_management.php');
$staff = file_get_contents(__DIR__ . '/../staff/customizations.php');

$checks = [
    'get_items does not query absent qty_multiplier' => !str_contains($api, 'r.qty_multiplier'),
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
    'configuration migration is additive and checks existing columns' => str_contains($migration, 'SHOW COLUMNS FROM `inv_items` LIKE') && str_contains($migration, 'ADD COLUMN `designs_per_unit`'),
    'nullable SQL defaults are verified as explicit NULL values' => str_contains($migration, 'array_key_exists(\'Default\', $row)'),
    'edit form exposes optional material configuration' => str_contains($form, 'id="materialUsageConfiguration"') && str_contains($form, 'name="designs_per_unit"'),
    'staff sees estimate and actual usage separately' => str_contains($staff, 'Estimated Usage:') && str_contains($staff, 'Actual Usage:'),
    'manual assignment confirmation gates saving' => str_contains($staff, 'manual_confirmation_required && !material.manual_quantity_confirmed'),
    'final usage remains posted as assignment quantity' => str_contains($staff, "fd.append('quantity', pm.qty)"),
];

$failed = [];
foreach ($checks as $label => $pass) {
    echo ($pass ? 'PASS: ' : 'FAIL: ') . $label . "\n";
    if (!$pass) $failed[] = $label;
}
if ($failed) exit(1);
