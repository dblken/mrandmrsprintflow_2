<?php

require_once __DIR__ . '/../includes/production_requirements.php';

$cases = [
    'mug prints' => [['MUG'], true],
    'printed sticker prints' => [['NEXJET', 'GLOSS LAMINATE'], true],
    'tarpaulin prints' => [['5FT Tarpaulin'], true],
    'heat-transfer vinyl has no printer ink' => [['VINYL BLACK'], false],
    'colored cut sticker has no printer ink' => [['STICKER SILVER'], false],
    'reflective cut material has no printer ink' => [['3M Reflective'], false],
    'plate material has no standard printer ink' => [['AC EURO', 'STICKER WHITE'], false],
    'optional material does not drive ink' => [['BOX MUG'], null],
    'unknown material preserves legacy behavior' => [['Unmapped Supply'], null],
];

$failed = [];
foreach ($cases as $label => [$names, $expected]) {
    $actual = printflow_material_ink_requirement_from_names($names);
    if ($actual !== $expected) $failed[] = $label;
}

if ($failed) {
    fwrite(STDERR, "production_material_ink_requirement_test: FAIL\n - " . implode("\n - ", $failed) . "\n");
    exit(1);
}

echo "production_material_ink_requirement_test: PASS\n";
