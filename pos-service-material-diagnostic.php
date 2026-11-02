<?php
declare(strict_types=1);

/**
 * Narrow, read-only production diagnostic for POS service materials.
 * CLI only. Example: PRINTFLOW_ROOT=/home/account/domains/site/public_html php
 *   /home/account/.pos-service-material-diagnostic.php
 * Emits only service/material configuration and Tarpaulin roll stock metadata.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$root = rtrim((string)(getenv('PRINTFLOW_ROOT') ?: ''), '/');
if ($root === '' || !is_file($root . '/includes/env.php')) {
    fwrite(STDERR, "DIAGNOSTIC_RESULT=FAIL reason=invalid_site_root\n");
    exit(2);
}
// Load only the dotenv reader and connect directly. The normal db.php bootstrap
// includes admin_reports_cache_generation.php, which bumps a derived cache token
// during CLI startup. Bypassing that bootstrap avoids the unrelated file write
// without redefining or overriding any application function.
require_once $root . '/includes/env.php';
if (function_exists('printflow_load_project_env')) printflow_load_project_env();
$dbValue = static function (array $names, string $default = ''): string {
    foreach ($names as $name) {
        $value = function_exists('printflow_env') ? printflow_env($name) : getenv($name);
        if ($value !== false && trim((string)$value) !== '') return (string)$value;
    }
    return $default;
};
mysqli_report(MYSQLI_REPORT_OFF);
try {
    $conn = new mysqli(
        $dbValue(['DB_HOST', 'PRINTFLOW_DB_HOST']),
        $dbValue(['DB_USER', 'PRINTFLOW_DB_USER']),
        $dbValue(['DB_PASSWORD', 'PRINTFLOW_DB_PASSWORD', 'PRINTFLOW_DB_PASS']),
        $dbValue(['DB_NAME', 'PRINTFLOW_DB_NAME']),
        (int)$dbValue(['DB_PORT', 'PRINTFLOW_DB_PORT'], '3306')
    );
} catch (Throwable $connectionError) {
    fwrite(STDERR, "DIAGNOSTIC_RESULT=FAIL reason=database_unavailable\n");
    exit(2);
}
if (!($conn instanceof mysqli) || $conn->connect_errno !== 0) {
    fwrite(STDERR, "DIAGNOSTIC_RESULT=FAIL reason=database_unavailable\n");
    exit(2);
}
$conn->set_charset('utf8mb4');
require_once $root . '/includes/production_material_compatibility.php';
require_once $root . '/includes/tarpaulin_material_calculation.php';
$serviceIds = [36, 39, 64, 67];
$expectedTypes = [
    36 => ['Decals/Stickers (Print/Cut)', 'Decals / Stickers (Print/Cut)', 'Decals / Stickers (Print & Cut)', 'Stickers/Decals', 'Stickers', 'Sticker', 'Decal', 'Decals'],
    39 => ['Plates', 'Plate'],
    64 => ['Poster Printing', 'Poster'],
    67 => ['Brochure', 'Brochures'],
];
$out = ['READ_ONLY' => true, 'generated_at_utc' => gmdate('c'), 'services' => [], 'rules' => [], 'inventory_recommendations' => [], 'schema' => [], 'tarpaulin_rolls' => []];

$columns = static function (mysqli $db, string $table): array {
    $allowed = ['services', 'service_material_rules', 'inv_items', 'inv_categories', 'inv_rolls', 'branches'];
    if (!in_array($table, $allowed, true)) return [];
    $result = @$db->query('SHOW COLUMNS FROM `' . $table . '`');
    if (!$result) return [];
    $found = [];
    while ($row = $result->fetch_assoc()) {
        $name = (string)($row['Field'] ?? '');
        if ($name !== '') $found[$name] = true;
    }
    $result->free();
    return $found;
};
$bindDynamic = static function (mysqli_stmt $stmt, string $types, array &$values): bool {
    $args = [$types];
    foreach ($values as &$value) $args[] = &$value;
    unset($value);
    return call_user_func_array([$stmt, 'bind_param'], $args);
};
$cols = [];
foreach (['services', 'service_material_rules', 'inv_items', 'inv_categories', 'inv_rolls', 'branches'] as $table) {
    $cols[$table] = $columns($conn, $table);
}
$out['schema']['service_material_rules.qty_multiplier'] = isset($cols['service_material_rules']['qty_multiplier']);
$out['schema']['service_material_rules.qty_multiplier_approved'] = isset($cols['service_material_rules']['qty_multiplier_approved']);
$out['schema']['inv_rolls.branch_id'] = isset($cols['inv_rolls']['branch_id']);
$out['schema']['inv_items_configuration_columns'] = [];
foreach (['size_variant', 'material_format', 'material_width', 'material_height', 'material_dimension_unit', 'consumption_method', 'designs_per_unit', 'usable_margin_width', 'usable_margin_height', 'wastage_percent'] as $field) {
    $out['schema']['inv_items_configuration_columns'][$field] = isset($cols['inv_items'][$field]);
}

$serviceNames = [];
if (isset($cols['services']['service_id'], $cols['services']['name'])) {
    $stmt = $conn->prepare('SELECT service_id, name, category, status FROM services WHERE service_id IN (36,39,64,67) ORDER BY service_id');
    if ($stmt && $stmt->execute()) {
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $id = (int)$row['service_id'];
            $serviceNames[$id] = (string)$row['name'];
            $out['services'][] = ['service_id' => $id, 'name' => (string)$row['name'], 'category' => (string)($row['category'] ?? ''), 'status' => (string)($row['status'] ?? '')];
        }
        $stmt->close();
    } else {
        if ($stmt) $stmt->close();
        $out['services_error'] = 'query_failed';
    }
} else {
    $out['services_error'] = 'required_columns_unavailable';
}

$serviceTypes = [];
foreach ($expectedTypes as $id => $types) {
    if (isset($serviceNames[$id]) && $serviceNames[$id] !== '') $types[] = $serviceNames[$id];
    $serviceTypes[$id] = array_values(array_unique($types));
}
$allTypes = array_values(array_unique(array_merge(...array_values($serviceTypes))));
$rules = [];
if (isset($cols['service_material_rules']['service_type'], $cols['service_material_rules']['item_id'], $cols['service_material_rules']['rule_type'])) {
    $hasMultiplier = isset($cols['service_material_rules']['qty_multiplier']);
    $hasMultiplierApproval = isset($cols['service_material_rules']['qty_multiplier_approved']);
    $hasActive = isset($cols['service_material_rules']['status']);
    $sql = 'SELECT service_type, item_id, rule_type' . ($hasMultiplier ? ', qty_multiplier' : '') . ($hasMultiplierApproval ? ', qty_multiplier_approved' : '') . ($hasActive ? ', status' : '')
        . ' FROM service_material_rules WHERE service_type IN (' . implode(',', array_fill(0, count($allTypes), '?')) . ') ORDER BY service_type, item_id';
    $stmt = $conn->prepare($sql);
    if ($stmt) {
        $types = str_repeat('s', count($allTypes));
        if ($bindDynamic($stmt, $types, $allTypes) && $stmt->execute()) {
            $result = $stmt->get_result();
            while ($row = $result->fetch_assoc()) {
                $row['item_id'] = (int)$row['item_id'];
                if (isset($row['qty_multiplier'])) $row['qty_multiplier'] = (string)$row['qty_multiplier'];
                if (isset($row['qty_multiplier_approved'])) $row['qty_multiplier_approved'] = (int)$row['qty_multiplier_approved'];
                $rules[] = $row;
            }
        } else $out['rules_error'] = 'query_failed';
        $stmt->close();
    } else $out['rules_error'] = 'query_unavailable';
} else $out['rules_error'] = 'required_columns_unavailable';
$out['rules'] = $rules;

$select = ['i.id', 'i.name', 'i.status'];
foreach (['category_id', 'unit_of_measure', 'track_by_roll', 'size_variant', 'material_format', 'material_width', 'material_height', 'material_dimension_unit', 'consumption_method', 'designs_per_unit', 'usable_margin_width', 'usable_margin_height', 'wastage_percent'] as $field) {
    if (isset($cols['inv_items'][$field])) $select[] = 'i.' . $field;
}
$joinCategory = isset($cols['inv_items']['category_id'], $cols['inv_categories']['id'], $cols['inv_categories']['name']);
if ($joinCategory) $select[] = 'c.name AS category_name';
$items = [];
if (isset($cols['inv_items']['id'], $cols['inv_items']['name'], $cols['inv_items']['status'])) {
    $sql = 'SELECT ' . implode(', ', $select) . ' FROM inv_items i ' . ($joinCategory ? 'LEFT JOIN inv_categories c ON c.id = i.category_id ' : '') . "WHERE UPPER(TRIM(i.status)) = 'ACTIVE' ORDER BY i.id";
    $result = @$conn->query($sql);
    if ($result) {
        while ($row = $result->fetch_assoc()) $items[] = $row;
        $result->free();
    } else $out['inventory_error'] = 'query_failed';
} else $out['inventory_error'] = 'required_columns_unavailable';

$serviceKinds = [36 => 'Decals/Stickers (Print/Cut)', 39 => 'Plates', 64 => 'Poster Printing', 67 => 'Brochure'];
foreach ($serviceKinds as $id => $canonical) {
    $context = ['serviceType' => $canonical, 'serviceLabel' => $canonical, 'serviceCategory' => ''];
    foreach ($out['services'] as $service) if ((int)$service['service_id'] === $id) $context['serviceCategory'] = (string)$service['category'];
    $typeSet = array_map('pfpm_normalize', $serviceTypes[$id]);
    $serviceRules = array_values(array_filter($rules, static fn(array $rule): bool => in_array(pfpm_normalize($rule['service_type'] ?? ''), $typeSet, true)));
    $candidates = [];
    foreach ($items as $item) {
        $classification = pfpm_classify_item($item, $context, $serviceRules);
        if (!in_array($classification['tier'] ?? '', ['recommended', 'optional'], true) && empty(array_filter($serviceRules, static fn(array $r): bool => (int)$r['item_id'] === (int)$item['id']))) continue;
        $matched = array_values(array_filter($serviceRules, static fn(array $r): bool => (int)$r['item_id'] === (int)$item['id']));
        $candidates[] = [
            'inventory_id' => (int)$item['id'], 'name' => (string)$item['name'], 'category' => (string)($item['category_name'] ?? ''),
            'uom' => (string)($item['unit_of_measure'] ?? 'UNKNOWN'), 'track_by_roll' => (bool)($item['track_by_roll'] ?? false),
            'consumption' => array_intersect_key($item, array_flip(['size_variant', 'material_format', 'material_width', 'material_height', 'material_dimension_unit', 'consumption_method', 'designs_per_unit', 'usable_margin_width', 'usable_margin_height', 'wastage_percent'])),
            'compatibility_tier' => (string)($classification['tier'] ?? 'unverified'),
            'rule_classification' => $matched ?: 'NO_EXPLICIT_RULE',
        ];
    }
    $out['inventory_recommendations'][] = ['service_id' => $id, 'canonical_service_type' => $canonical, 'candidates' => $candidates];
}

$tarpItems = [];
foreach ($items as $item) {
    if (pfpm_material_family($item) === 'tarpaulin') $tarpItems[(int)$item['id']] = $item;
}
if (isset($cols['inv_rolls']['id'], $cols['inv_rolls']['item_id'], $cols['inv_rolls']['remaining_length_ft'], $cols['inv_rolls']['status']) && $tarpItems) {
    if (!isset($cols['inv_rolls']['branch_id'])) {
        $out['tarpaulin_rolls_error'] = 'branch_id_missing; roll rows not queried';
    } else {
        $rollFields = ['r.id', 'r.item_id', 'r.branch_id', 'r.remaining_length_ft', 'r.status'];
        if (isset($cols['inv_rolls']['roll_code'])) $rollFields[] = 'r.roll_code';
        if (isset($cols['inv_rolls']['received_at'])) $rollFields[] = 'r.received_at';
        $joinBranch = isset($cols['branches']['id'], $cols['branches']['branch_name']);
        if ($joinBranch) $rollFields[] = 'b.branch_name';
        $ids = array_keys($tarpItems);
        $sql = 'SELECT ' . implode(', ', $rollFields) . ' FROM inv_rolls r ' . ($joinBranch ? 'LEFT JOIN branches b ON b.id = r.branch_id ' : '')
            . "WHERE r.status = 'OPEN' AND r.remaining_length_ft > 0 AND r.item_id IN (" . implode(',', array_fill(0, count($ids), '?')) . ') ORDER BY r.branch_id, r.item_id, r.id';
        $stmt = $conn->prepare($sql);
        if ($stmt) {
            $bindTypes = str_repeat('i', count($ids));
            if ($bindDynamic($stmt, $bindTypes, $ids) && $stmt->execute()) {
                $result = $stmt->get_result();
                while ($row = $result->fetch_assoc()) {
                    $itemId = (int)$row['item_id'];
                    $out['tarpaulin_rolls'][] = [
                        'item_id' => $itemId, 'item_name' => (string)($tarpItems[$itemId]['name'] ?? ''),
                        'uom' => (string)($tarpItems[$itemId]['unit_of_measure'] ?? 'UNKNOWN'),
                        'roll_nominal_width_ft' => function_exists('printflow_tarpaulin_parse_nominal_width') ? printflow_tarpaulin_parse_nominal_width($tarpItems[$itemId]) : null,
                        'roll_id' => (int)$row['id'], 'roll_code' => (string)($row['roll_code'] ?? ''),
                        'branch_id' => (int)$row['branch_id'], 'branch_name' => (string)($row['branch_name'] ?? ''),
                        'remaining_length_ft' => (string)$row['remaining_length_ft'],
                        'status' => (string)$row['status'], 'received_at' => $row['received_at'] ?? null,
                    ];
                }
            } else $out['tarpaulin_rolls_error'] = 'query_failed';
            $stmt->close();
        } else $out['tarpaulin_rolls_error'] = 'query_unavailable';
    }
} else {
    $out['tarpaulin_rolls_error'] = $tarpItems ? 'required_columns_unavailable' : 'no_active_tarpaulin_inventory_items_found';
}

header('Content-Type: application/json; charset=utf-8');
echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE), "\n";
