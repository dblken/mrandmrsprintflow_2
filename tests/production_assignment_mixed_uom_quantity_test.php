<?php
declare(strict_types=1);

/**
 * Production Assignment mixed-UOM save regression.
 *
 * Live failure: after the first pcs material saved as DECIMAL "1.00", the next
 * add_material call revalidated that row and rejected it with
 * "Material quantity must match its inventory unit." Mixed Stickers/Decals
 * selections (pcs laminate/hologram + ft colored sticker rolls) could not save.
 */

require_once dirname(__DIR__) . '/includes/pos_quantity_validation.php';

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

$glossLaminate = ['id' => 20, 'name' => 'Gloss Laminate', 'unit_of_measure' => 'pcs', 'status' => 'ACTIVE'];
$hologram = ['id' => 22, 'name' => 'Hologram', 'unit_of_measure' => 'pcs', 'status' => 'ACTIVE'];
$stickerSilver = ['id' => 57, 'name' => 'STICKER SILVER', 'unit_of_measure' => 'ft', 'status' => 'ACTIVE'];
$stickerYellow = ['id' => 58, 'name' => 'STICKER YELLOW', 'unit_of_measure' => 'ft', 'status' => 'ACTIVE'];

// Reproduce the live payload shape: staff entered 1 for each row; previously
// saved pcs rows round-trip from MySQL DECIMAL(10,2) as "1.00".
$mixedSelection = [
    ['item' => $glossLaminate, 'entered' => '1', 'stored' => '1.00', 'expected' => 1.0],
    ['item' => $hologram, 'entered' => '1', 'stored' => '1.00', 'expected' => 1.0],
    ['item' => $stickerSilver, 'entered' => '1', 'stored' => '1.00', 'expected' => 1.0],
    ['item' => $stickerYellow, 'entered' => '1', 'stored' => '1.00', 'expected' => 1.0],
];

foreach ($mixedSelection as $row) {
    $fromEntry = printflow_pos_parse_positive_material_quantity($row['entered'], $row['item']);
    $fromStored = printflow_pos_parse_positive_material_quantity($row['stored'], $row['item']);
    $fromFloat = printflow_pos_parse_positive_material_quantity($row['expected'], $row['item']);
    $assert(
        $fromEntry === $row['expected'] && $fromStored === $row['expected'] && $fromFloat === $row['expected'],
        $row['item']['name'] . ' must accept staff entry and DECIMAL-stored actual usage independently in its own UOM (' . $row['item']['unit_of_measure'] . ').'
    );
}

foreach ([['1.5', $glossLaminate], ['1.01', $hologram], ['0', $stickerSilver], ['-1', $stickerYellow], ['1.234', $stickerSilver], ['', $glossLaminate]] as [$invalid, $item]) {
    try {
        printflow_pos_parse_positive_material_quantity($invalid, $item);
    } catch (InvalidArgumentException) {
        continue;
    }
    throw new RuntimeException('Invalid quantity ' . var_export($invalid, true) . ' for ' . $item['name'] . ' was accepted.');
}

$root = dirname(__DIR__);
$quantitySource = (string)file_get_contents($root . '/includes/pos_quantity_validation.php');
$stockSource = (string)file_get_contents($root . '/includes/pos_service_stock_validation.php');
$apiSource = (string)file_get_contents($root . '/admin/job_orders_api.php');
$jsSource = (string)file_get_contents($root . '/public/assets/js/material_consumption_estimator.js');

$assert(str_contains($quantitySource, "/^[1-9][0-9]{0,7}(?:\\.0+)?$/"),
    'Whole-unit parser must canonicalize DECIMAL round-trips such as 1.00 without accepting fractional pcs.');
$assert(str_contains($jsSource, '/^[1-9][0-9]{0,7}(?:\\.0+)?$/'),
    'Browser material quantity helper must accept saved whole DECIMAL strings for pcs items.');
$assert(str_contains($stockSource, 'quantity must match its inventory unit')
    && str_contains($stockSource, "\$item['name']")
    && str_contains($stockSource, "\$item['unit_of_measure']"),
    'Stock validation rejects the specific item whose quantity/UOM is invalid.');
$assert(str_contains($apiSource, 'printflow_pos_parse_positive_material_quantity(')
    && str_contains($apiSource, "\$assignment['quantity'] ?? null")
    && str_contains($apiSource, '$assignmentItem')
    && str_contains($apiSource, 'Insufficient ')
    && str_contains($apiSource, 'requested %s %s; available %s %s'),
    'add_material re-canonicalizes each prior assignment with its own inventory item before mixed stock checks and still rejects insufficient stock per item.');

// Stickers/Decals multi_optional mapping must still allow pcs + ft together.
$selectionSource = (string)file_get_contents($root . '/includes/pos_material_selection.php');
$assert(str_contains($selectionSource, "36 => [")
    && str_contains($selectionSource, "20 => ['group' => 'sticker_media', 'role' => 'multi_optional'")
    && str_contains($selectionSource, "22 => ['group' => 'sticker_media', 'role' => 'multi_optional'")
    && str_contains($selectionSource, 'printflow_stickers_decals_colored_sticker_item_names'),
    'Stickers/Decals keeps multi_optional mapped materials (including colored ft rolls) selectable together.');

echo "Production assignment mixed-UOM quantity tests passed.\n";
