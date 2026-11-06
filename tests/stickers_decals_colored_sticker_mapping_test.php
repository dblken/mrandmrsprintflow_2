<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/pos_material_selection.php';
require_once dirname(__DIR__) . '/includes/service_material_rule_display.php';

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

$serviceId = 36;
$colors = printflow_stickers_decals_colored_sticker_item_names();
$assert($colors === [
    51 => 'STICKER BLACK',
    52 => 'STICKER WHITE',
    53 => 'STICKER RED',
    54 => 'STICKER BLUE',
    55 => 'STICKER GREEN',
    56 => 'STICKER GOLD',
    57 => 'STICKER SILVER',
    58 => 'STICKER YELLOW',
], 'The eight colored sticker IDs match the verified inventory catalog identities.');

foreach ($colors as $itemId => $name) {
    $assert(in_array((int)$itemId, printflow_pos_material_selection_allowed_ids($serviceId), true),
        "{$name} (#{$itemId}) is mapped in the Stickers/Decals selection registry.");
    $assert(!in_array((int)$itemId, printflow_pos_material_selection_allowed_ids(71), true),
        "{$name} (#{$itemId}) is not mapped onto Sintraboard.");
    $resolved = printflow_pos_material_selection_resolve_assignments($serviceId, [
        ['item_id' => (string)$itemId, 'quantity' => 1],
    ]);
    $assert(count($resolved) === 1 && (int)$resolved[0]['item_id'] === (int)$itemId,
        "{$name} (#{$itemId}) passes Stickers/Decals Set Price assignment validation.");
}

$legacy = printflow_pos_material_selection_resolve_assignments($serviceId, [
    ['item_id' => 18, 'quantity' => 1],
    ['item_id' => 20, 'quantity' => 1],
]);
$assert(array_column($legacy, 'item_id') === [18, 20], 'Existing Nextjet + Gloss Laminate mappings still validate.');

$multiColor = printflow_pos_material_selection_resolve_assignments($serviceId, [
    ['item_id' => 55, 'quantity' => 2],
    ['item_id' => 51, 'quantity' => 1],
]);
$assert(array_column($multiColor, 'item_id') === [55, 51], 'Multiple colored stickers may be selected together.');

$fails(
    static fn() => printflow_pos_material_selection_resolve_assignments($serviceId, [['item_id' => 212, 'quantity' => 1]]),
    'not mapped to this service',
    'Unrelated Brochure paper remains rejected for Stickers/Decals.'
);
$fails(
    static fn() => printflow_pos_material_selection_resolve_assignments(71, [
        ['item_id' => 206, 'quantity' => 1],
        ['item_id' => 55, 'quantity' => 1],
    ], ['board_thickness' => 'Sintra 3mm']),
    'Material #55 is not mapped',
    'STICKER GREEN is not accepted for Sintraboard.'
);

$display = printflow_service_material_rule_display_registry()[36];
foreach ($colors as $itemId => $name) {
    $assert(isset($display['item_names'][$itemId]), "Admin display registry includes item {$itemId}.");
}

$synthetic = printflow_production_assignment_material_rules_with_registry([
    [
        'service_id' => 36,
        'service_type' => 'Decals/Stickers (Print/Cut)',
        'item_id' => 18,
        'rule_type' => 'REQUIRED',
        'item_name' => 'Nextjet',
    ],
]);
$syntheticIds = array_map(static fn(array $row): int => (int)$row['item_id'], $synthetic);
foreach (array_keys($colors) as $itemId) {
    $assert(in_array((int)$itemId, $syntheticIds, true),
        "Production picker rule synthesis includes colored sticker #{$itemId}.");
}
$assert(in_array(18, $syntheticIds, true), 'Existing Nextjet rule remains present after synthesis.');

$stockSource = (string)file_get_contents(dirname(__DIR__) . '/includes/pos_service_stock_validation.php');
$assert(str_contains($stockSource, 'printflow_pos_material_selection_resolve_assignments')
    && str_contains($stockSource, 'printflow_pos_service_stock_exceeds_available'),
    'Branch stock validation remains wired after colored sticker mapping.');
$assert(str_contains((string)file_get_contents(dirname(__DIR__) . '/staff/customizations.php'), 'queueMaterialFromPicker(item)'),
    'One-click Production Assignment selection remains in place.');

echo "stickers_decals_colored_sticker_mapping_test: PASS\n";
