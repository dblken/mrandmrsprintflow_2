<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/pos_service_stock_helpers.php';

$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};
$expectRuntimeError = static function (callable $callback, string $expectedText): void {
    try {
        $callback();
    } catch (Throwable $error) {
        if (str_contains($error->getMessage(), $expectedText)) return;
        throw new RuntimeException('Unexpected validation message: ' . $error->getMessage());
    }
    throw new RuntimeException('Expected validation to fail with: ' . $expectedText);
};

$canonicalTypes = [
    36 => 'Decals/Stickers (Print/Cut)',
    39 => 'Plates',
    64 => 'Poster Printing',
    67 => 'Brochure',
];
foreach ($canonicalTypes as $serviceId => $serviceType) {
    $assert(
        printflow_pos_service_material_expected_type($serviceId) === $serviceType,
        'Service ID ' . $serviceId . ' retains its canonical service_type.'
    );
}
$assert(printflow_pos_service_material_expected_type(40) === null, 'Tarpaulin stays on its dedicated rules path.');

foreach ([36, 39, 64, 67] as $serviceId) {
    $expectRuntimeError(
        static fn() => printflow_pos_assert_service_material_type($serviceId, 'Wrong service type'),
        'service ID ' . $serviceId
    );
}
printflow_pos_assert_service_material_type(36, 'Decals/Stickers (Print/Cut)');
$assert(
    printflow_pos_canonical_service_material_type(36, 'Decals / Stickers (Print & Cut)') === 'Decals/Stickers (Print/Cut)',
    'A stored legacy Sticker/Decal alias resolves to the approved canonical service type.'
);
$stickerAliasRows = [
    ['service_type' => 'Decals / Stickers (Print & Cut)', 'item_id' => 12, 'rule_type' => 'REQUIRED'],
    ['service_type' => 'Decals / Stickers (Print & Cut)', 'item_id' => 13, 'rule_type' => 'OPTIONAL'],
];
$selectedStickerRows = printflow_pos_select_service_material_rule_set(36, $stickerAliasRows);
$assert(count($selectedStickerRows) === 2, 'One existing Sticker/Decal alias rule set retains both REQUIRED and OPTIONAL records.');
$canonicalStickerRows = printflow_pos_select_service_material_rule_set(36, [['service_type' => 'Decals/Stickers (Print/Cut)', 'item_id' => 14]]);
$assert(count($canonicalStickerRows) === 1 && (int)$canonicalStickerRows[0]['item_id'] === 14,
    'The canonical Sticker/Decal rule set is returned without modifying its rows.');
$expectRuntimeError(
    static fn() => printflow_pos_select_service_material_rule_set(36, array_merge($stickerAliasRows, [['service_type' => 'Decals/Stickers (Print/Cut)', 'item_id' => 12, 'rule_type' => 'REQUIRED']])),
    'multiple aliases'
);
$unmappedServiceRules = [['service_type' => 'Legacy Service', 'item_id' => 90, 'rule_type' => 'REQUIRED']];
$assert(printflow_pos_select_service_material_rule_set(90, $unmappedServiceRules) === $unmappedServiceRules,
    'Existing non-specialized service rules continue through the generic path unchanged.');

$expectRuntimeError(
    static fn() => printflow_pos_assert_service_stock_multiplier_schema(false, true, true, true),
    'approval or audit schema'
);
$expectRuntimeError(
    static fn() => printflow_pos_assert_service_stock_multiplier_schema(true, false, true, true),
    'approval or audit schema'
);
foreach ([
    [true, true, false, true],
    [true, true, true, false],
] as $missingAuditSchema) {
    $expectRuntimeError(static fn() => printflow_pos_assert_service_stock_multiplier_schema(...$missingAuditSchema), 'approval or audit schema');
}
printflow_pos_assert_service_stock_multiplier_schema(true, true, true, true);
$assertMultiplier = static function (callable $callback): void {
    try {
        $callback();
    } catch (RuntimeException $error) {
        if (str_contains($error->getMessage(), 'no valid Admin-approved multiplier')) return;
        throw $error;
    }
    throw new RuntimeException('An unapproved multiplier unexpectedly passed validation.');
};
$assertMultiplier(static fn() => printflow_pos_approved_service_multiplier(null, 0));
$assertMultiplier(static fn() => printflow_pos_approved_service_multiplier(1.0, 0));
$assertMultiplier(static fn() => printflow_pos_approved_service_multiplier(1.0, null));
$assertMultiplier(static fn() => printflow_pos_approved_service_multiplier(0, 1));
$assertMultiplier(static fn() => printflow_pos_approved_service_multiplier(-1, 1));
$approvedBy = 19;
$approvedAt = '2026-11-01 10:15:30';
$approvedMultiplier = printflow_pos_approved_service_multiplier('1.25', 1, $approvedBy, $approvedAt);
$assert(abs($approvedMultiplier - 1.25) < 0.000001, 'Only a positive multiplier with explicit approval and audit metadata is accepted.');
$assertMultiplier(static fn() => printflow_pos_approved_service_multiplier('1.25', 1, null, $approvedAt));
$assertMultiplier(static fn() => printflow_pos_approved_service_multiplier('1.25', 1, $approvedBy, null));
$assertMultiplier(static fn() => printflow_pos_approved_service_multiplier('1.25', 1, $approvedBy, 'not-a-datetime'));
$assertMultiplier(static fn() => printflow_pos_approved_service_multiplier('1.25', 1, $approvedBy, '2026-99-99 99:99:99'));
$unapprovedDemand = [];
try {
    $unapprovedMultiplier = printflow_pos_approved_service_multiplier(1.0, 0);
    printflow_pos_service_stock_add_demand($unapprovedDemand, 12, printflow_pos_service_required_material_quantity(2, $unapprovedMultiplier), 'Test material', 'pcs');
    throw new RuntimeException('An unapproved rule unexpectedly generated automatic stock demand.');
} catch (RuntimeException $error) {
    if (!str_contains($error->getMessage(), 'no valid Admin-approved multiplier')) throw $error;
}
$assert($unapprovedDemand === [], 'An unapproved multiplier cannot create automatic stock demand or deduction input.');
$approvedDemand = [];
printflow_pos_service_stock_add_demand($approvedDemand, 12, printflow_pos_service_required_material_quantity(2, $approvedMultiplier), 'Test material', 'pcs');
$assert(($approvedDemand[12]['quantity'] ?? null) === 2.5, 'An explicitly approved multiplier generates the configured demand.');

foreach ([39 => 'Plates', 64 => 'Poster Printing', 67 => 'Brochure'] as $serviceId => $serviceType) {
    $expectRuntimeError(
        static fn() => printflow_pos_assert_required_service_material_rule($serviceId, $serviceType, false),
        $serviceType . ' (service ID ' . $serviceId . ')'
    );
    printflow_pos_assert_required_service_material_rule($serviceId, $serviceType, true);
}
// Stickers/Decals is canonicalized and checked above; this task only requires
// missing-rule blocking for the three services confirmed absent in production.
printflow_pos_assert_required_service_material_rule(36, 'Decals/Stickers (Print/Cut)', false);

$required = printflow_pos_service_required_material_quantity(3, 1.25);
$assert(abs($required - 3.75) < 0.000001, 'Required stock multiplies service quantity by the configured rule multiplier.');
$expectRuntimeError(static fn() => printflow_pos_service_required_material_quantity(3, 0), 'consumption ratio');

$root = dirname(__DIR__);
$validator = (string)file_get_contents($root . '/includes/pos_service_stock_validation.php');
$jobOrderService = (string)file_get_contents($root . '/includes/JobOrderService.php');
$stockRulesStart = strpos($validator, 'function printflow_pos_service_stock_rules(');
$stockRulesEnd = strpos($validator, 'function printflow_pos_service_line_job_materials(', $stockRulesStart ?: 0);
$stockRulesBlock = $stockRulesStart !== false && $stockRulesEnd !== false
    ? substr($validator, $stockRulesStart, $stockRulesEnd - $stockRulesStart)
    : '';
$schemaCheck = strpos($stockRulesBlock, "db_table_has_column('service_material_rules', 'qty_multiplier', true)");
$query = strpos($stockRulesBlock, 'SELECT r.id AS rule_id, r.item_id, r.rule_type, r.qty_multiplier');
$tarpaulinExit = strpos($stockRulesBlock, 'if ($serviceId === 40) return [];');
$stockValidationStart = strpos($validator, 'function printflow_pos_validate_service_stock(');
$stockValidationBlock = $stockValidationStart !== false ? substr($validator, $stockValidationStart) : '';
$tarpaulinDedicatedPath = strpos($stockValidationBlock, 'if ($serviceId === 40) {');
$genericRulesCall = strpos($stockValidationBlock, 'printflow_pos_service_stock_rules($serviceId, $customization, $hasAssignedMaterials)');
$approvalSchemaCheck = strpos($stockRulesBlock, "db_table_has_column('service_material_rules', 'qty_multiplier_approved', true)");
$approverSchemaCheck = strpos($stockRulesBlock, "db_table_has_column('service_material_rules', 'qty_multiplier_approved_by', true)");
$approvalTimestampSchemaCheck = strpos($stockRulesBlock, "db_table_has_column('service_material_rules', 'qty_multiplier_approved_at', true)");
$basisSchemaCheck = strpos($stockRulesBlock, "db_table_has_column('service_material_rules', 'qty_multiplier_basis', true)");
$approvedBasisSchemaCheck = strpos($stockRulesBlock, "db_table_has_column('service_material_rules', 'qty_multiplier_approved_basis', true)");
$approvedMultiplierCheck = strpos($stockRulesBlock, 'printflow_pos_approved_service_multiplier(');
$assert($schemaCheck !== false && $approvalSchemaCheck !== false && $approverSchemaCheck !== false
    && $approvalTimestampSchemaCheck !== false && $query !== false
    && $schemaCheck < $query && $approvalSchemaCheck < $query
    && $approverSchemaCheck < $query && $approvalTimestampSchemaCheck < $query,
    'All four production multiplier/approval/audit schema guards run before the dependent query.');
$assert($basisSchemaCheck !== false && $approvedBasisSchemaCheck !== false
    && $basisSchemaCheck < $query && $approvedBasisSchemaCheck < $query,
    'Selection-based services require the explicit and Admin-approved consumption basis before reading rules.');
$assert($approvedMultiplierCheck !== false && $approvedMultiplierCheck < strpos($stockRulesBlock, '$required[] = ['),
    'Unapproved multipliers fail before a required demand entry can be accumulated.');
$assert(str_contains($stockRulesBlock, 'r.qty_multiplier_approved'), 'The runtime selects the explicit approval state.');
$assert($tarpaulinExit !== false && $tarpaulinExit < $schemaCheck, 'Tarpaulin exits before checking or querying qty_multiplier.');
$assert($tarpaulinDedicatedPath !== false && $genericRulesCall !== false && $tarpaulinDedicatedPath < $genericRulesCall,
    'Tarpaulin is validated through its dedicated roll path before generic multiplier rules are consulted.');
$assert(
    str_contains($jobOrderService, "return 'Decals/Stickers (Print/Cut)';")
        && str_contains($jobOrderService, "strpos($" . "cat_lower, 'sticker') !== false || strpos($" . "cat_lower, 'decal') !== false"),
    'The catalog service mapper retains the canonical Stickers/Decals rule key.'
);

echo "POS service material rule validation tests passed.\n";
