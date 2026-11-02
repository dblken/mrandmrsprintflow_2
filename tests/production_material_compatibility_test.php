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
$stickerAliasContext = [
    'serviceId' => 36,
    'serviceType' => 'Stickers /Decals',
    'serviceLabel' => 'Stickers /Decals / Stickers',
    'serviceCategory' => 'Stickers',
    'customization' => ['cut_type' => 'Cut Out'],
];
$nextjet = ['id' => 18, 'name' => 'Nextjet', 'category_name' => 'Materials'];
$hologram = ['id' => 22, 'name' => 'Hologram', 'category_name' => 'Materials'];
$stickerRuleRows = [
    ['service_type' => 'Decals/Stickers (Print/Cut)', 'item_id' => 18, 'rule_type' => 'REQUIRED'],
    ['service_type' => 'Decals/Stickers (Print/Cut)', 'item_id' => 22, 'rule_type' => 'OPTIONAL'],
];
assert_same_value(pfpm_classify_item($nextjet, $stickerAliasContext, $stickerRuleRows)['applicable'], true,
    'the canonical service 36 REQUIRED Nextjet rule matches the live UI service-label alias in server validation');
assert_same_value(pfpm_classify_item($hologram, $stickerAliasContext, $stickerRuleRows)['tier'], 'optional',
    'the canonical service 36 Hologram rule remains OPTIONAL when service labels use an alias');
$canonicalStickerContext = ['serviceId' => 36, 'serviceType' => 'Decals/Stickers (Print/Cut)'];
assert_same_value(pfpm_classify_item($nextjet, $canonicalStickerContext, $stickerRuleRows)['tier'], 'recommended',
    'the canonical Decals service identity resolves its REQUIRED rule');
$conflictingStickerContext = ['serviceId' => 36, 'serviceType' => 'Poster Printing', 'serviceCategory' => 'Print'];
assert_same_value(pfpm_classify_item($nextjet, $conflictingStickerContext, $stickerRuleRows)['applicable'], false,
    'service ID 36 with a conflicting Poster Printing identity cannot inherit Decals rules');
$incompleteStickerContext = ['serviceId' => 36, 'serviceCategory' => 'Stickers'];
assert_same_value(pfpm_matching_context_rule($nextjet, $incompleteStickerContext, $stickerRuleRows), null,
    'an incomplete service 36 identity cannot match a generic sticker rule');
$posterRuleRows = [
    ['service_type' => 'Poster Printing', 'item_id' => 18, 'rule_type' => 'OPTIONAL'],
    $stickerRuleRows[0],
];
assert_same_value(pfpm_classify_item($nextjet, ['serviceId' => 64, 'serviceType' => 'Poster Printing'], $posterRuleRows)['tier'], 'optional',
    'a valid Poster service resolves its own material rule');
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

$sintraContext = ['serviceType' => 'Sintraboard Standees', 'serviceCategory' => 'Signage', 'customization' => []];
$platesContext = ['serviceType' => 'Plates', 'serviceCategory' => 'Print', 'customization' => []];
foreach (['AC MC', 'SP MC'] as $plateMaterial) {
    $item = ['id' => 500, 'name' => $plateMaterial, 'category_name' => 'Materials'];
    $badSintraRule = [['service_type' => 'Sintraboard Standees', 'item_id' => 500, 'rule_type' => 'REQUIRED']];
    assert_same_value(pfpm_classify_item($item, $sintraContext, $badSintraRule)['applicable'], false,
        $plateMaterial . ' cannot be made selectable for Sintraboard Standees by a conflicting rule');
    $plateRule = [['service_type' => 'Plates', 'item_id' => 500, 'rule_type' => 'REQUIRED']];
    assert_same_value(pfpm_classify_item($item, $platesContext, $plateRule)['tier'], 'recommended',
        $plateMaterial . ' remains selectable for Plates');
}

echo "production_material_compatibility_test: PASS\n";
