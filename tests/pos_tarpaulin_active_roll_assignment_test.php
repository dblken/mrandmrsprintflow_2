<?php
declare(strict_types=1);

/**
 * Confirm Set Price Tarpaulin assignment must accept an ACTIVE track_by_roll
 * material whether it was auto-selected or manually queued, while still
 * rejecting inactive / non-roll lookalikes. Optional non-roll extras may
 * accompany a valid roll.
 */

$root = dirname(__DIR__);
require_once $root . '/includes/pos_tarpaulin_release_rule.php';
require_once $root . '/includes/pos_material_selection.php';

$assert = static function (bool $ok, string $message): void {
    if (!$ok) {
        throw new RuntimeException($message);
    }
};

$passes = static function (array $resolved, string $label) use ($assert): void {
    printflow_pos_tarpaulin_assert_resolved_active_roll($resolved);
    $assert(true, $label . ' passed');
};

$fails = static function (array $resolved, string $label) use ($assert): void {
    try {
        printflow_pos_tarpaulin_assert_resolved_active_roll($resolved);
        throw new RuntimeException($label . ' unexpectedly passed.');
    } catch (RuntimeException $error) {
        $assert(
            str_contains($error->getMessage(), 'Select an active Tarpaulin roll before saving its actual usage.'),
            $label . ' must keep the active-roll error message.'
        );
    }
};

$activeRoll = static fn(int $id, string $name = '3FT Tarpaulin'): array => [
    'id' => $id,
    'name' => $name,
    'status' => 'ACTIVE',
    'track_by_roll' => 1,
    'unit_of_measure' => 'ft',
];

// Auto-selected mapped 3ft roll (same shape Confirm Set Price resolves via getItem).
$passes([$activeRoll(13)], 'auto-selected mapped 3ft roll');

// Manual selection of the same canonical inventory id.
$passes([$activeRoll(13)], 'manually selected mapped 3ft roll');

// Roll + optional eyelet extras remain valid.
$passes([
    $activeRoll(13),
    ['id' => 99, 'name' => 'Eyelet', 'status' => 'ACTIVE', 'track_by_roll' => 0, 'unit_of_measure' => 'pcs'],
], 'active roll plus optional eyelet');

// Name lookalike without track_by_roll is rejected (the observed Set Price failure).
$fails([
    ['id' => 913, 'name' => '3ft Tarpaulin', 'status' => 'ACTIVE', 'track_by_roll' => 0, 'unit_of_measure' => 'pcs'],
], 'non-roll lookalike');

$fails([], 'empty assignment list');

$fails([
    ['id' => 13, 'name' => '3FT Tarpaulin', 'status' => 'INACTIVE', 'track_by_roll' => 1, 'unit_of_measure' => 'ft'],
], 'inactive mapped roll');

$fails([
    ['id' => 99, 'name' => 'Eyelet', 'status' => 'ACTIVE', 'track_by_roll' => 0, 'unit_of_measure' => 'pcs'],
], 'optional eyelet alone');

// Validator wiring: Confirm Set Price path still calls the helper for service 40.
$validator = (string)file_get_contents($root . '/includes/pos_service_stock_validation.php');
$assert(
    str_contains($validator, "require_once __DIR__ . '/pos_tarpaulin_release_rule.php';")
    && str_contains($validator, 'printflow_pos_tarpaulin_assert_resolved_active_roll($resolvedTarpaulinItems)'),
    'POS stock validation asserts an active Tarpaulin roll for service 40 assignments.'
);

$customizations = (string)file_get_contents($root . '/staff/customizations.php');
$assert(
    str_contains($customizations, 'productionAssignmentInventoryItemId(material)')
    && str_contains($customizations, 'isEligibleTarpaulinRoll')
    && str_contains($customizations, 'item_id: Number(item.id)')
    && !str_contains($customizations, 'material.item_id || material.id || 0'),
    'Confirm Set Price posts canonical inventory item_id for auto and manual Tarpaulin rows.'
);

$picker = (string)file_get_contents($root . '/public/assets/js/production_material_picker.js');
$assert(
    str_contains($picker, 'const requiresAuthoritativeMapping = [36, 39, 40, 64, 67, 71].includes(serviceId)')
    && str_contains($picker, 'tarpaulinMappedRollIds')
    && str_contains($picker, 'Number(item && item.track_by_roll) === 1'),
    'Tarpaulin picker requires mapped roll-tracked IDs for service 40 auto/manual selection.'
);

$assert(
    printflow_pos_material_selection_service_type(40) === 'Tarpaulin',
    'Tarpaulin registry service type is present so mapped roll rules inject into the picker.'
);
$assert(
    isset(printflow_pos_material_selection_item_names(40)[13]),
    'Mapped 3FT Tarpaulin name remains in the production assignment registry names.'
);

// Alternative-group and non-Tarpaulin behavior remain unchanged.
$assert(printflow_production_assignment_selection_errors(40, [13, 14]) !== [], 'Multiple Tarpaulin roll widths still conflict.');
$assert(printflow_production_assignment_selection_errors(40, [13]) === [], 'Single mapped Tarpaulin roll remains valid.');
$assert(printflow_production_assignment_selection_errors(67, [212]) === [], 'Brochure single-paper selection unchanged.');

echo "POS Tarpaulin active roll assignment regression tests passed.\n";
