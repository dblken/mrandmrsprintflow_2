<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/pos_material_selection.php';
require_once dirname(__DIR__) . '/includes/production_material_compatibility.php';

$assert = static function (bool $ok, string $message): void {
    if (!$ok) throw new RuntimeException($message);
};
$fails = static function (callable $fn, string $needle, string $message): void {
    try {
        $fn();
    } catch (InvalidArgumentException $error) {
        if ($needle !== '' && !str_contains($error->getMessage(), $needle)) {
            throw new RuntimeException($message . ' Got: ' . $error->getMessage());
        }
        return;
    }
    throw new RuntimeException($message);
};

unset($GLOBALS['PRINTFLOW_TEST_PRODUCTION_ASSIGNMENT_DB_MAPPED_IDS']);

foreach ([39 => 1, 67 => 212, 64 => 211, 71 => 206, 36 => 18, 40 => 13] as $serviceId => $itemId) {
    $resolved = printflow_pos_material_selection_resolve_assignments((int)$serviceId, [
        ['item_id' => (string)$itemId, 'quantity' => 1],
    ], $serviceId === 71 ? ['board_thickness' => 'Sintra 3mm'] : []);
    $assert(count($resolved) === 1 && (int)$resolved[0]['item_id'] === (int)$itemId,
        "Mapped material {$itemId} must pass Production Assignment validation for service {$serviceId} with string/int ID normalization.");
}

$fails(
    static fn() => printflow_pos_material_selection_resolve_assignments(67, [['item_id' => 18, 'quantity' => 1]]),
    'not mapped to this service',
    'A sticker material is rejected for Brochure when it is not mapped there.'
);
$fails(
    static fn() => printflow_pos_material_selection_resolve_assignments(36, [['item_id' => 212, 'quantity' => 1]]),
    'not mapped to this service',
    'Brochure paper is rejected for Stickers/Decals when it is not mapped there.'
);

$brochureEmpty = printflow_pos_material_selection_resolve_assignments(67, []);
$assert($brochureEmpty === [], 'Optional/unused mapped Brochure materials may remain unselected.');

$fails(
    static fn() => printflow_pos_material_selection_resolve_assignments(67, [
        ['item_id' => 211, 'quantity' => 1],
        ['item_id' => 212, 'quantity' => 1],
    ]),
    'Choose only one',
    'Mutually exclusive Brochure papers remain rejected together.'
);

// Simulate authoritative DB mappings that include interchangeable sticker colors
// beyond the POS form allowlist, without changing production data.
$GLOBALS['PRINTFLOW_TEST_PRODUCTION_ASSIGNMENT_DB_MAPPED_IDS'] = [
    36 => [18, 20, 21, 22, 101, 102, 103],
    67 => [212, 211, 250],
];
$stickerColors = printflow_pos_material_selection_resolve_assignments(36, [
    ['item_id' => '101', 'quantity' => 2],
    ['item_id' => 20, 'quantity' => 1],
]);
$assert(array_column($stickerColors, 'item_id') === [101, 20],
    'DB-mapped interchangeable sticker materials pass Set Price validation for Stickers/Decals.');
$extraBrochure = printflow_pos_material_selection_resolve_assignments(67, [
    ['item_id' => '250', 'quantity' => 1],
]);
$assert(count($extraBrochure) === 1 && (int)$extraBrochure[0]['item_id'] === 250,
    'An active Brochure DB mapping outside the POS form allowlist is accepted in Production Assignment.');
$fails(
    static fn() => printflow_pos_material_selection_resolve_assignments(36, [['item_id' => 999, 'quantity' => 1]]),
    'Refresh Production Assignment',
    'A stale/unmapped material is rejected with an actionable refresh message.'
);
unset($GLOBALS['PRINTFLOW_TEST_PRODUCTION_ASSIGNMENT_DB_MAPPED_IDS']);

$assert(in_array(212, printflow_production_assignment_allowed_item_ids(67), true),
    'Brochure registry materials remain in the allowed Production Assignment set.');
$assert(!in_array(999, printflow_production_assignment_allowed_item_ids(67), true),
    'Unmapped IDs stay outside the allowed Production Assignment set.');

// Material #55 is STICKER GREEN. It is now an explicit Stickers/Decals mapping
// and must remain unmapped for Sintraboard.
$assert(in_array(55, printflow_pos_material_selection_allowed_ids(36), true),
    'Material #55 is mapped in the Stickers/Decals selection registry.');
$assert(!in_array(55, array_map('intval', array_keys(printflow_production_assignment_selection_registry()[71] ?? [])), true),
    'Material #55 is outside the Sintraboard Production Assignment selection registry.');
$stickerGreenMapped = printflow_pos_material_selection_resolve_assignments(36, [
    ['item_id' => '55', 'quantity' => 1],
]);
$assert(count($stickerGreenMapped) === 1 && (int)$stickerGreenMapped[0]['item_id'] === 55,
    'Material #55 passes Stickers/Decals Set Price validation.');
$fails(
    static fn() => printflow_pos_material_selection_resolve_assignments(71, [
        ['item_id' => 206, 'quantity' => 1],
        ['item_id' => 55, 'quantity' => 1],
    ], ['board_thickness' => 'Sintra 3mm']),
    'Material #55 is not mapped',
    'Material #55 remains rejected for Sintraboard and is not silently remapped.'
);

$item = ['id' => 250, 'name' => 'Extra Brochure Stock', 'category_name' => 'Print', 'status' => 'ACTIVE'];
$matched = pfpm_matching_context_rule($item, [
    'serviceId' => 67,
    'serviceType' => 'Brochure',
    'serviceLabel' => 'Brochure',
], [
    ['service_id' => '67', 'item_id' => '250', 'service_type' => 'Brochure', 'rule_type' => 'OPTIONAL'],
]);
$assert(is_array($matched), 'Stable numeric service_id/item_id matching accepts mapped materials without string-label ambiguity.');

$source = (string)file_get_contents(dirname(__DIR__) . '/includes/pos_material_selection.php');
$assert(str_contains($source, 'printflow_production_assignment_allowed_item_ids')
    && str_contains($source, 'printflow_production_assignment_db_mapped_item_ids')
    && str_contains($source, 'Refresh Production Assignment'),
    'Production Assignment eligibility uses authoritative mapped IDs instead of the closed POS form allowlist alone.');
$stockSource = (string)file_get_contents(dirname(__DIR__) . '/includes/pos_service_stock_validation.php');
$assert(str_contains($stockSource, 'printflow_pos_material_selection_resolve_assignments($serviceId, $assignmentRows, $customization)'),
    'Set Price stock validation still routes assignment identity through resolve_assignments.');
$staff = (string)file_get_contents(dirname(__DIR__) . '/staff/customizations.php');
$assert(str_contains($staff, 'queueMaterialFromPicker(item')
    && (str_contains($staff, "qty: ''") || str_contains($staff, "let qty = ''"))
    && !str_contains($staff, '>Add actual usage</button>'),
    'One-click Production Assignment selection remains in place.');

echo "production_assignment_mapping_validation_test: PASS\n";
