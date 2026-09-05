<?php

require_once __DIR__ . '/../includes/production_requirements.php';

$cases = [
    'mug prints' => [['MUG'], true],
    'mug sublimation paper prints' => [['MUG', 'Subli Paper', 'BOX MUG'], true],
    'printed sticker prints' => [['NEXJET', 'GLOSS LAMINATE'], true],
    'sticker paper prints' => [['Sticker Paper'], true],
    'paper services print' => [['C2s Board'], true],
    'poster photo paper prints' => [['Photo Paper'], true],
    'tarpaulin prints' => [['5FT Tarpaulin'], true],
    'heat-transfer vinyl has no printer ink' => [['VINYL BLACK'], false],
    'colored cut sticker has no printer ink' => [['STICKER SILVER'], false],
    'reflective cut material has no printer ink' => [['3M Reflective'], false],
    'plate material has no standard printer ink' => [['AC EURO', 'STICKER WHITE'], false],
    'sintra has no configured standard ink' => [['Sintra 3mm 32', '3M Reflective'], false],
    'holographic HTV stays distinct from printable hologram' => [['Holographic'], false],
    'printable hologram uses normal ink' => [['Hologram'], true],
    'optional material does not drive ink' => [['BOX MUG'], null],
    'unknown material preserves legacy behavior' => [['Unmapped Supply'], null],
    'Cyno remains unverified' => [['Cyno'], null],
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
