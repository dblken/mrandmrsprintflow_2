<?php
declare(strict_types=1);

$root = dirname(__DIR__);

foreach ([
    [$root . '/includes/provider_payments.php', 'printflow_money_to_centavos'],
    [$root . '/admin/job_orders_api.php', 'jo_api_parse_final_price'],
] as [$path, $functionName]) {
    $source = (string)file_get_contents($path);
    $pattern = '/function\s+' . preg_quote($functionName, '/') . '\s*\([^)]*\)\s*(?::\s*[^{]+)?\{[\s\S]*?\n\}/';
    if (!preg_match($pattern, $source, $match)) {
        throw new RuntimeException('Could not extract actual source function ' . $functionName . '.');
    }
    eval($match[0]);
}

$valid = [
    '0.01' => 1,
    '1' => 100,
    '1,000.00' => 100000,
    '10,000.00' => 1000000,
    '250,000.50' => 25000050,
    '1,000,000.00' => 100000000,
    '9,999,999.99' => 999999999,
];
foreach ($valid as $input => $expectedCentavos) {
    $result = jo_api_parse_final_price($input);
    if ((int)round($result * 100) !== $expectedCentavos) {
        throw new RuntimeException("Backend parser changed {$input}: expected {$expectedCentavos} centavos.");
    }
}

$invalid = ['', '0', '0.00', '-1', '-0.01', 'letters', '1.001', '1.000', '10,000,000.00', '10000000', '1,00', '1,,000'];
foreach ($invalid as $input) {
    try {
        jo_api_parse_final_price($input);
    } catch (InvalidArgumentException $error) {
        if (!str_contains($error->getMessage(), 'PHP 0.01 to PHP 9,999,999.99')
            || !str_contains($error->getMessage(), 'two decimal places')) {
            throw new RuntimeException('Backend parser returned unclear validation for ' . var_export($input, true));
        }
        continue;
    }
    throw new RuntimeException('Backend parser accepted invalid final price ' . var_export($input, true));
}

echo "Final price backend validation tests passed.\n";
