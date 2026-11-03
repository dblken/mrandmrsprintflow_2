<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/pos_service_stock_helpers.php';
require_once dirname(__DIR__) . '/includes/pos_material_selection.php';

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
$assert(printflow_pos_service_material_expected_type(71) === 'Sintraboard Standees', 'Sintraboard retains its canonical service identity.');

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
$stableStickerRows = printflow_pos_select_service_material_rule_set(36, [['service_id' => 36, 'service_type' => 'Decals/Stickers (Print/Cut)', 'item_id' => 18]]);
$assert(count($stableStickerRows) === 1 && (int)$stableStickerRows[0]['item_id'] === 18, 'Stable ID rows with a matching canonical label remain valid.');
$expectRuntimeError(
    static fn() => printflow_pos_select_service_material_rule_set(36, [['service_id' => 36, 'service_type' => 'Poster Printing', 'item_id' => 18]]),
    'conflicts with the stored service_type'
);
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

$sintraStableRules = [
    ['id' => 80, 'service_id' => 71, 'service_type' => 'Sintraboard Standees', 'item_id' => 207, 'rule_type' => 'REQUIRED', 'qty_multiplier' => '2.00', 'qty_multiplier_approved' => 1, 'qty_multiplier_approved_by' => 22, 'qty_multiplier_approved_at' => '2026-11-03 10:00:00', 'qty_multiplier_basis' => 'AREA_SQFT', 'qty_multiplier_approved_basis' => 'AREA_SQFT'],
    ['id' => 81, 'service_id' => 71, 'service_type' => 'Sintraboard Standees', 'item_id' => 206, 'rule_type' => 'REQUIRED', 'qty_multiplier' => null, 'qty_multiplier_approved' => 0, 'qty_multiplier_approved_by' => null, 'qty_multiplier_approved_at' => null, 'qty_multiplier_basis' => null, 'qty_multiplier_approved_basis' => null],
    ['id' => 82, 'service_id' => 71, 'service_type' => 'Sintraboard Standees', 'item_id' => 23, 'rule_type' => 'OPTIONAL', 'qty_multiplier' => '1.00', 'qty_multiplier_approved' => 0, 'qty_multiplier_approved_by' => null, 'qty_multiplier_approved_at' => null, 'qty_multiplier_basis' => 'AREA_SQFT', 'qty_multiplier_approved_basis' => null],
];
$sintraLegacyRules = array_map(static function (array $row): array {
    unset($row['service_id']);
    $row['id'] += 100;
    return $row;
}, $sintraStableRules);
$resolvedSintraRules = printflow_pos_select_service_material_rule_set(71, array_merge($sintraStableRules, $sintraLegacyRules));
$assert(count($resolvedSintraRules) === count($sintraStableRules)
    && array_column($resolvedSintraRules, 'id') === array_column($sintraStableRules, 'id'),
    'Equivalent stable-ID and legacy Sintraboard rows resolve once, preferring stable service IDs.');
$assert(printflow_pos_approved_service_multiplier($resolvedSintraRules[0]['qty_multiplier'], $resolvedSintraRules[0]['qty_multiplier_approved'], $resolvedSintraRules[0]['qty_multiplier_approved_by'], $resolvedSintraRules[0]['qty_multiplier_approved_at']) === 2.0,
    'An approved equivalent Sintraboard material remains usable after deterministic identity resolution.');
$sintraSelected = printflow_pos_material_selection_resolve(71, [207]);
$assert(count($sintraSelected) === 1 && (int)$sintraSelected[0]['item_id'] === 207
    && !printflow_pos_service_stock_exceeds_available(2.0, 3.0),
    'A selected approved Sintraboard thickness can pass the sufficient-stock check without consuming its alternative.');
$sintraFieldMap = printflow_pos_material_selection_field_map(71);
$assert($sintraFieldMap === ['field_key' => 'board_thickness', 'options' => ['Sintra 3mm' => 206, 'Sintra 5mm' => 207]],
    'Sintraboard Board Thickness uses only the verified stable item IDs 206 and 207.');
foreach ([['Sintra 3mm', 206], ['Sintra 5mm', 207]] as [$thickness, $itemId]) {
    $resolvedFieldSelection = printflow_pos_material_selection_resolve_customization(71, [
        'board_thickness' => $thickness,
        'pos_selected_material_ids' => [$itemId],
    ]);
    $assert(count($resolvedFieldSelection) === 1 && (int)$resolvedFieldSelection[0]['item_id'] === $itemId,
        $thickness . ' resolves to its matching selected inventory material ID.');
}
$sintraWithOptional = printflow_pos_material_selection_resolve_customization(71, [
    'board_thickness' => 'Sintra 3mm',
    'pos_selected_material_ids' => [206, 23],
]);
$assert(array_column($sintraWithOptional, 'item_id') === [206, 23],
    'the selected thickness and a manually selected optional sticker layer can be resolved together.');
$expectRuntimeError(
    static fn() => printflow_pos_material_selection_resolve_customization(71, ['board_thickness' => 'Sintra 5mm', 'pos_selected_material_ids' => [206]]),
    'Please select Sintra 3mm or Sintra 5mm.'
);
$expectRuntimeError(
    static fn() => printflow_pos_material_selection_resolve_customization(71, ['board_thickness' => '', 'pos_selected_material_ids' => []]),
    'Please select Sintra 3mm or Sintra 5mm.'
);
foreach ([
    ['rule_type' => 'OPTIONAL'],
    ['qty_multiplier_basis' => 'PER_ITEM'],
    ['qty_multiplier_approved' => 0, 'qty_multiplier_approved_by' => null, 'qty_multiplier_approved_at' => null, 'qty_multiplier_approved_basis' => null],
    ['qty_multiplier' => '3.00'],
] as $conflictingValues) {
    $conflictingStable = $sintraStableRules;
    $conflictingStable[0] = array_merge($conflictingStable[0], $conflictingValues);
    $expectRuntimeError(
        static fn() => printflow_pos_select_service_material_rule_set(71, array_merge($conflictingStable, $sintraLegacyRules)),
        'An Admin must review the service-material configuration'
    );
}
$duplicateSintraLegacy = $sintraLegacyRules;
$duplicateSintraLegacy[] = $sintraLegacyRules[0];
$duplicateStaleLegacyResolved = printflow_pos_select_service_material_rule_set(71, array_merge($sintraStableRules, $duplicateSintraLegacy));
$assert(array_column($duplicateStaleLegacyResolved, 'id') === array_column($sintraStableRules, 'id'),
    'Duplicate unmatched legacy OPTIONAL/unapproved rows are ignored when stable service 71 rules exist.');
$malformedSintraStable = $sintraStableRules;
$malformedSintraLegacy = $sintraLegacyRules;
$malformedSintraStable[0]['qty_multiplier_approved_by'] = null;
$malformedSintraStable[0]['qty_multiplier_approved_at'] = null;
$malformedSintraLegacy[0]['qty_multiplier_approved_by'] = null;
$malformedSintraLegacy[0]['qty_multiplier_approved_at'] = null;
$expectRuntimeError(
    static fn() => printflow_pos_select_service_material_rule_set(71, array_merge($malformedSintraStable, $malformedSintraLegacy)),
    'An Admin must review the service-material configuration'
);

$auditedSintraStableRows = [
    ['id' => 26, 'service_id' => 71, 'service_type' => 'Sintraboard Standees', 'item_id' => 23, 'material_name' => 'Pp Stkr Matte', 'rule_type' => 'OPTIONAL', 'qty_multiplier' => null, 'qty_multiplier_approved' => 0, 'qty_multiplier_approved_by' => null, 'qty_multiplier_approved_at' => null, 'qty_multiplier_basis' => null, 'qty_multiplier_approved_basis' => null],
    ['id' => 35, 'service_id' => 71, 'service_type' => 'Sintraboard Standees', 'item_id' => 206, 'material_name' => 'Sintra 3mm', 'rule_type' => 'REQUIRED', 'qty_multiplier' => '1.00', 'qty_multiplier_approved' => 1, 'qty_multiplier_approved_by' => 22, 'qty_multiplier_approved_at' => '2026-11-03 10:00:00', 'qty_multiplier_basis' => 'AREA_SQFT', 'qty_multiplier_approved_basis' => 'AREA_SQFT'],
    ['id' => 52, 'service_id' => 71, 'service_type' => 'Sintraboard Standees', 'item_id' => 207, 'material_name' => 'Sintra 5mm', 'rule_type' => 'REQUIRED', 'qty_multiplier' => '1.00', 'qty_multiplier_approved' => 1, 'qty_multiplier_approved_by' => 22, 'qty_multiplier_approved_at' => '2026-11-03 10:00:00', 'qty_multiplier_basis' => 'AREA_SQFT', 'qty_multiplier_approved_basis' => 'AREA_SQFT'],
];
$auditedSintraLegacyRows = [
    ['id' => 27, 'service_id' => null, 'service_type' => 'Sintraboard Standees', 'item_id' => 1, 'material_name' => 'Ac Mc', 'rule_type' => 'OPTIONAL', 'qty_multiplier' => null, 'qty_multiplier_approved' => 0, 'qty_multiplier_approved_by' => null, 'qty_multiplier_approved_at' => null, 'qty_multiplier_basis' => null, 'qty_multiplier_approved_basis' => null],
    ['id' => 28, 'service_id' => null, 'service_type' => 'Sintraboard Standees', 'item_id' => 7, 'material_name' => 'Sp Mc', 'rule_type' => 'OPTIONAL', 'qty_multiplier' => '1.00', 'qty_multiplier_approved' => 0, 'qty_multiplier_approved_by' => null, 'qty_multiplier_approved_at' => null, 'qty_multiplier_basis' => null, 'qty_multiplier_approved_basis' => null],
];
$auditedSintraResolved = printflow_pos_select_service_material_rule_set(71, array_merge($auditedSintraStableRows, $auditedSintraLegacyRows));
$assert(array_column($auditedSintraResolved, 'id') === [26, 35, 52]
    && array_column($auditedSintraResolved, 'material_name') === ['Pp Stkr Matte', 'Sintra 3mm', 'Sintra 5mm'],
    'The audited service 71 stable rules remain authoritative while unmatched legacy OPTIONAL/unapproved item 1 and item 7 rows are ignored.');
$assert($auditedSintraResolved[1]['qty_multiplier'] === '1.00'
    && $auditedSintraResolved[1]['qty_multiplier_basis'] === 'AREA_SQFT'
    && (int)$auditedSintraResolved[1]['qty_multiplier_approved'] === 1
    && $auditedSintraResolved[2]['qty_multiplier'] === '1.00'
    && $auditedSintraResolved[2]['qty_multiplier_basis'] === 'AREA_SQFT'
    && (int)$auditedSintraResolved[2]['qty_multiplier_approved'] === 1,
    'The audited approved Sintra 3mm and 5mm AREA_SQFT rules are preserved by resolution.');
$stableSintraWithStaleText = $auditedSintraStableRows;
$stableSintraWithStaleText[1]['service_type'] = '';
$stableSintraWithStaleText[2]['service_type'] = 'Legacy Sintraboard';
$stableSintraTextResolved = printflow_pos_select_service_material_rule_set(
    71,
    array_merge($stableSintraWithStaleText, $auditedSintraLegacyRows)
);
$assert(array_column($stableSintraTextResolved, 'id') === [26, 35, 52]
    && $stableSintraTextResolved[1]['qty_multiplier_basis'] === 'AREA_SQFT'
    && $stableSintraTextResolved[2]['qty_multiplier_basis'] === 'AREA_SQFT',
    'Stable service_id=71 rules remain authoritative when their legacy service_type text is blank or stale; unrelated legacy aliases do not block them.');
$displaySintraRows = printflow_pos_sintraboard_rule_rows_for_display(array_merge($auditedSintraStableRows, $auditedSintraLegacyRows));
$assert(array_column($displaySintraRows, 'id') === [26, 35, 52],
    'The initial Sintraboard form uses stable rows and ignores unmatched legacy entries for display.');
$displayConflictRows = $auditedSintraStableRows;
$displayConflictDuplicate = $auditedSintraStableRows[1];
$displayConflictDuplicate['id'] = 100;
$displayConflictDuplicate['qty_multiplier'] = '2.00';
$displayProjection = printflow_pos_sintraboard_rule_rows_for_display(array_merge($displayConflictRows, [$displayConflictDuplicate]));
$assert(array_column($displayProjection, 'item_id') === [23, 206, 207]
    && count(array_filter($displayProjection, static fn(array $row): bool => (int)$row['item_id'] === 206)) === 1,
    'Conflicting thickness rows do not prevent the initial selector from rendering one choice per material.');
$expectRuntimeError(
    static fn() => printflow_pos_select_service_material_rule_set(71, array_merge($auditedSintraStableRows, [$displayConflictDuplicate])),
    'An Admin must review the service-material configuration'
);
$unapprovedStableDuplicate = $auditedSintraStableRows[1];
$unapprovedStableDuplicate['id'] = 99;
$unapprovedStableDuplicate['qty_multiplier_approved'] = 0;
$unapprovedStableDuplicate['qty_multiplier_approved_by'] = null;
$unapprovedStableDuplicate['qty_multiplier_approved_at'] = null;
$unapprovedStableDuplicate['qty_multiplier_approved_basis'] = null;
$unapprovedStableDuplicate['qty_multiplier'] = 'invalid-stale-value';
$unapprovedStableDuplicate['qty_multiplier_basis'] = 'UNKNOWN';
$stableDuplicateResolved = printflow_pos_select_service_material_rule_set(71, array_merge(
    $auditedSintraStableRows,
    [$unapprovedStableDuplicate],
    $auditedSintraLegacyRows
));
$assert(array_column($stableDuplicateResolved, 'id') === [26, 35, 52],
    'A malformed unapproved duplicate cannot override an approved stable Sintra rule for the same item.');
$obsoleteLegacyRows = $auditedSintraLegacyRows;
$obsoleteLegacyRows[] = ['id' => 29, 'service_id' => null, 'service_type' => 'Sintraboard Standees', 'item_id' => 999, 'rule_type' => 'OBSOLETE', 'qty_multiplier' => 'bad', 'qty_multiplier_approved' => 1];
$obsoleteLegacyResolved = printflow_pos_select_service_material_rule_set(71, array_merge($auditedSintraStableRows, $obsoleteLegacyRows));
$assert(array_column($obsoleteLegacyResolved, 'id') === [26, 35, 52],
    'An unmatched obsolete legacy alias is ignored without changing stable mappings.');
$legacyConflict = $auditedSintraLegacyRows;
$legacyConflict[0]['item_id'] = 206;
$legacyConflict[0]['rule_type'] = 'REQUIRED';
$legacyConflict[0]['qty_multiplier'] = '2.00';
$legacyConflict[0]['qty_multiplier_approved'] = 1;
$legacyConflict[0]['qty_multiplier_approved_by'] = 23;
$legacyConflict[0]['qty_multiplier_approved_at'] = '2026-11-03 11:00:00';
$legacyConflict[0]['qty_multiplier_basis'] = 'AREA_SQFT';
$legacyConflict[0]['qty_multiplier_approved_basis'] = 'AREA_SQFT';
$expectRuntimeError(
    static fn() => printflow_pos_select_service_material_rule_set(71, array_merge($auditedSintraStableRows, $legacyConflict)),
    'An Admin must review the service-material configuration'
);
$unmatchedLegacyRequired = $auditedSintraLegacyRows;
$unmatchedLegacyRequired[0]['rule_type'] = 'REQUIRED';
$unmatchedLegacyResolved = printflow_pos_select_service_material_rule_set(71, array_merge($auditedSintraStableRows, $unmatchedLegacyRequired));
$assert(array_column($unmatchedLegacyResolved, 'id') === [26, 35, 52],
    'An unmatched legacy REQUIRED row without approval is ignored and cannot interfere with the stable service 71 rules.');
$conflictingApprovedStable = $auditedSintraStableRows;
$duplicateApprovedStable = $auditedSintraStableRows[1];
$duplicateApprovedStable['id'] = 99;
$duplicateApprovedStable['qty_multiplier_basis'] = 'PER_ITEM';
$duplicateApprovedStable['qty_multiplier_approved_basis'] = 'PER_ITEM';
$expectRuntimeError(
    static fn() => printflow_pos_select_service_material_rule_set(71, array_merge($conflictingApprovedStable, [$duplicateApprovedStable])),
    'An Admin must review the service-material configuration'
);
$sintraTechnicalError = 'Stable and legacy material mappings conflict. An Admin must resolve the duplicate configuration.';
$sintraStaffMessage = 'May kailangan bang linawin tungkol sa material na gagamitin? May nalilito ba sa pagpili ng material? Pakisuri ang Sintra 3mm o Sintra 5mm bago magpatuloy.';
$assert(printflow_pos_sintraboard_staff_facing_material_error(71, $sintraTechnicalError) === $sintraStaffMessage,
    'Sintraboard mapping diagnostics are replaced with the staff-friendly ambiguity prompt.');
$assert(printflow_pos_sintraboard_staff_facing_material_error(71, 'Select exactly one material from the sintra thickness choices.') === 'Please select Sintra 3mm or Sintra 5mm.',
    'Missing or multiple board-thickness selections receive the requested concise staff instruction.');
$assert(printflow_pos_sintraboard_staff_facing_material_error(71, 'Sintra 3mm is out of stock.') === null
    && printflow_pos_sintraboard_staff_facing_material_error(67, $sintraTechnicalError) === null,
    'Stock messages and other services are not rewritten by the Sintraboard diagnostic sanitizer.');
$serviceFieldsApi = (string)file_get_contents(dirname(__DIR__) . '/staff/api/pos_service_fields.php');
$cartApi = (string)file_get_contents(dirname(__DIR__) . '/staff/api/pos_cart_handler.php');
$checkoutApi = (string)file_get_contents(dirname(__DIR__) . '/staff/api/pos_checkout.php');
$assert(str_contains($serviceFieldsApi, 'printflow_pos_sintraboard_staff_facing_material_error($service_id, $identityError->getMessage())')
    && str_contains($serviceFieldsApi, '$staffError ??')
    && str_contains($cartApi, 'pos_cart_staff_facing_service_material_error($e->getMessage()')
    && str_contains($checkoutApi, 'pos_checkout_staff_facing_material_error((string)$issue[\'message\'], $items)'),
    'Service-fields, cart, and checkout APIs sanitize genuine Sintraboard identity conflicts before returning staff-facing errors.');

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
$query = strpos($stockRulesBlock, 'SELECT r.id AS rule_id, ');
$tarpaulinExit = strpos($stockRulesBlock, 'if ($serviceId === 40) return [];');
$stockValidationStart = strpos($validator, 'function printflow_pos_validate_service_stock(');
$stockValidationBlock = $stockValidationStart !== false ? substr($validator, $stockValidationStart) : '';
$tarpaulinDedicatedPath = strpos($stockValidationBlock, 'if ($serviceId === 40) {');
$genericRulesCall = strpos($stockValidationBlock, 'printflow_pos_service_stock_rules($serviceId, $customization, $hasAssignedMaterials, $branchId, (float)$quantity, $demand)');
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
$assert(str_contains($stockRulesBlock, 'printflow_service_material_rule_identity_scope($serviceId, $ruleTypes,')
    && str_contains($stockRulesBlock, 'NULL AS service_id'), 'POS rule lookup uses stable service IDs when available with explicit legacy alias fallback before migration.');
$assert($tarpaulinExit !== false && $tarpaulinExit < $schemaCheck, 'Tarpaulin exits before checking or querying qty_multiplier.');
$assert($tarpaulinDedicatedPath !== false && $genericRulesCall !== false && $tarpaulinDedicatedPath < $genericRulesCall,
    'Tarpaulin is validated through its dedicated roll path before generic multiplier rules are consulted.');
$assert(
    str_contains($jobOrderService, "return 'Decals/Stickers (Print/Cut)';")
        && str_contains($jobOrderService, "strpos($" . "cat_lower, 'sticker') !== false || strpos($" . "cat_lower, 'decal') !== false"),
    'The catalog service mapper retains the canonical Stickers/Decals rule key.'
);

echo "POS service material rule validation tests passed.\n";
