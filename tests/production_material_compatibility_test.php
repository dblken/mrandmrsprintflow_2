<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/production_material_compatibility.php';

function assert_same_value($actual, $expected, string $message): void
{
    if ($actual !== $expected) {
        fwrite(STDERR, "FAIL: {$message} expected " . var_export($expected, true) . ' got ' . var_export($actual, true) . PHP_EOL);
        exit(1);
    }
}

$stickerContext = [
    'serviceType' => 'Stickers Decals',
    'serviceLabel' => 'Stickers Decals / Stickers',
    'serviceCategory' => 'Stickers',
    'customization' => [],
];
$tarpContext = [
    'serviceType' => 'Tarpaulin Printing',
    'serviceLabel' => 'Tarpaulin Printing / Tarpaulin',
    'serviceCategory' => 'Tarpaulin',
    'customization' => [],
];

$glossLaminate = ['id' => 1, 'name' => 'Gloss Laminate', 'category_name' => 'Materials'];
$stickerPaper = ['id' => 2, 'name' => 'Sticker Paper', 'category_name' => 'Materials'];
$tarpaulin = ['id' => 3, 'name' => '3ft Tarpaulin', 'category_name' => 'Tarpaulin'];
$eyelet = ['id' => 4, 'name' => 'Eyelet', 'category_name' => 'Hardware'];

assert_same_value(pfpm_classify_item($glossLaminate, $stickerContext)['tier'], 'optional', 'sticker laminate remains optional/applicable');
assert_same_value(pfpm_classify_item($stickerPaper, $stickerContext)['tier'], 'recommended', 'sticker paper is recommended for stickers');
assert_same_value(pfpm_classify_item($tarpaulin, $stickerContext)['applicable'], false, 'tarpaulin is not applicable for stickers');
assert_same_value(pfpm_classify_item($tarpaulin, $tarpContext)['tier'], 'recommended', 'tarpaulin is recommended for tarpaulin service');
assert_same_value(pfpm_classify_item($eyelet, $tarpContext)['tier'], 'optional', 'eyelet remains optional/applicable for tarpaulin');

$legacyContext = [
    'serviceType' => 'Verified Legacy Service',
    'serviceLabel' => 'Verified Legacy Service',
    'serviceCategory' => '',
    'customization' => [],
];
$legacyItem = ['id' => 99, 'name' => 'Legacy Material', 'category_name' => 'Materials'];
$rules = [['service_type' => 'Verified Legacy Service', 'item_id' => 99, 'rule_type' => 'REQUIRED']];
assert_same_value(pfpm_classify_item($legacyItem, $legacyContext, $rules)['tier'], 'recommended', 'service_material_rules still override unknown services');

echo "production_material_compatibility_test: PASS\n";
