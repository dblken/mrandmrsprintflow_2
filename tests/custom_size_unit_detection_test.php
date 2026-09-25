<?php

require dirname(__DIR__) . '/includes/service_dimension_ui.php';

$tests = [
    [
        'name' => 'Tarp ft options',
        'config' => [
            'type' => 'select',
            'label' => 'Size',
            'options' => [
                ['value' => '1 Ft × 2 Ft'],
                ['value' => '2 Ft × 3 Ft'],
            ],
        ],
        'expect_unit' => 'ft',
        'expect_fixed' => true,
    ],
    [
        'name' => 'Brochure inch options',
        'config' => [
            'type' => 'select',
            'label' => 'Dimensions',
            'options' => [
                ['value' => '25pcs A4 (8 × 11 Inch)'],
            ],
        ],
        'expect_unit' => 'in',
        'expect_fixed' => true,
    ],
    [
        'name' => 'Lowercase ft',
        'config' => [
            'type' => 'select',
            'options' => [['value' => '1ft x 2ft']],
        ],
        'expect_unit' => 'ft',
        'expect_fixed' => true,
    ],
    [
        'name' => 'Mixed ambiguous',
        'config' => [
            'type' => 'select',
            'options' => [
                ['value' => '8 × 11 Inch'],
                ['value' => '2 Ft × 3 Ft'],
            ],
        ],
        'expect_unit' => null,
        'expect_fixed' => false,
    ],
];

$failed = 0;
foreach ($tests as $test) {
    $cfg = $test['config'];
    $detect = printflow_detect_dimension_unit_from_options($cfg);
    $ctx = printflow_service_field_custom_size_unit_context('size', $cfg);
    $ok = true;
    if ($test['expect_unit'] === null) {
        if (!$detect['ambiguous'] || $ctx['fixed_unit']) {
            $ok = false;
        }
    } else {
        if ($detect['unit'] !== $test['expect_unit'] || $ctx['unit'] !== $test['expect_unit']) {
            $ok = false;
        }
        if ($ctx['fixed_unit'] !== $test['expect_fixed']) {
            $ok = false;
        }
    }
    if (!$ok) {
        $failed++;
        echo 'FAIL: ' . $test['name'] . PHP_EOL;
        echo '  detect: ' . var_export($detect, true) . PHP_EOL;
        echo '  ctx: ' . var_export($ctx, true) . PHP_EOL;
    } else {
        echo 'OK: ' . $test['name'] . PHP_EOL;
    }
}

exit($failed > 0 ? 1 : 0);
