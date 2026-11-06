<?php
declare(strict_types=1);

/**
 * Additive Stickers/Decals (service_id 36) mappings for the eight verified
 * STICKER (Colored) inventory IDs. Safe to re-run; does not alter stock,
 * prices, UOMs, or other services.
 *
 * Do NOT run against production until explicitly authorized.
 *
 * Usage (authorized environments only):
 *   php database/migrate_stickers_decals_colored_sticker_mappings_20261107.php
 */

require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/pos_material_selection.php';

$serviceId = 36;
$serviceType = 'Decals/Stickers (Print/Cut)';
$colors = printflow_stickers_decals_colored_sticker_item_names();
$hasServiceId = function_exists('db_table_has_column')
    && db_table_has_column('service_material_rules', 'service_id', true);

$inserted = 0;
$skipped = 0;
foreach ($colors as $itemId => $expectedName) {
    $itemId = (int)$itemId;
    $itemRows = db_query(
        'SELECT id, name, status FROM inv_items WHERE id = ? LIMIT 1',
        'i',
        [$itemId]
    ) ?: [];
    $item = $itemRows[0] ?? null;
    if (!$item || strtoupper(trim((string)($item['status'] ?? ''))) !== 'ACTIVE') {
        fwrite(STDERR, "SKIP item {$itemId}: missing or inactive.\n");
        $skipped++;
        continue;
    }
    if (strcasecmp(trim((string)$item['name']), $expectedName) !== 0
        && strcasecmp(trim((string)$item['name']), ucwords(strtolower($expectedName))) !== 0) {
        fwrite(STDERR, "SKIP item {$itemId}: name mismatch got {$item['name']} expected {$expectedName}.\n");
        $skipped++;
        continue;
    }

    if ($hasServiceId) {
        $existing = db_query(
            'SELECT id FROM service_material_rules WHERE service_id = ? AND item_id = ? LIMIT 1',
            'ii',
            [$serviceId, $itemId]
        ) ?: [];
    } else {
        $existing = db_query(
            'SELECT id FROM service_material_rules WHERE service_type = ? AND item_id = ? LIMIT 1',
            'si',
            [$serviceType, $itemId]
        ) ?: [];
    }
    if ($existing !== []) {
        echo "EXISTS item {$itemId} ({$item['name']})\n";
        $skipped++;
        continue;
    }

    if ($hasServiceId) {
        db_query(
            'INSERT INTO service_material_rules (service_id, service_type, item_id, rule_type) VALUES (?, ?, ?, ?)',
            'isis',
            [$serviceId, $serviceType, $itemId, 'OPTIONAL']
        );
    } else {
        db_query(
            'INSERT INTO service_material_rules (service_type, item_id, rule_type) VALUES (?, ?, ?)',
            'sis',
            [$serviceType, $itemId, 'OPTIONAL']
        );
    }
    echo "INSERTED item {$itemId} ({$item['name']}) -> service {$serviceId}\n";
    $inserted++;
}

echo "Done. inserted={$inserted} skipped={$skipped}\n";
