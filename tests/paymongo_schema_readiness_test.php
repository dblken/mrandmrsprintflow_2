<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$provider = (string)file_get_contents($root . '/includes/provider_payments.php');
$migration = (string)file_get_contents(
    $root . '/database/migrate_paymongo_provider_livemode_20261009.php'
);

$checks = [
    'Live readiness retains the provider livemode verification columns' =>
        str_contains($provider, "'provider_livemode', 'provider_livemode_verified_at',"),
    'Test simulator URL is required only for Test mode' =>
        str_contains($provider, "if (\$mode === 'test')")
        && str_contains($provider, "\$requiredColumns[] = 'provider_test_url';"),
    'Schema inspection errors are reported separately from missing schema' =>
        str_contains($provider, "'error_code' => 'payment_intent_schema_inspection_failed'")
        && str_contains($provider, "'error_code' => 'payment_intent_schema_missing'"),
    'Missing schema diagnostics name only missing tables or columns' =>
        str_contains($provider, "'[paymongo-schema] migration_required missing='")
        && str_contains($provider, "'missing' => \$missing"),
    'Customer response does not disclose schema or database details' =>
        str_contains($provider, 'QRPh checkout is temporarily unavailable. Please try again later.')
        && !str_contains($provider, "'message' => 'The Payment Intent migration has not been applied.'"),
    'Livemode migration remains additive and preserves historical rows' =>
        str_contains($migration, 'ADD COLUMN `provider_livemode`')
        && str_contains($migration, 'ADD COLUMN `provider_livemode_verified_at`')
        && str_contains($migration, 'ADD COLUMN `provider_test_url`')
        && str_contains($migration, 'historical rows remain unverified'),
];

$failed = 0;
foreach ($checks as $name => $passed) {
    if (!$passed) {
        fwrite(STDERR, "FAIL: {$name}\n");
        $failed++;
        continue;
    }
    echo "PASS: {$name}\n";
}

if ($failed > 0) {
    exit(1);
}
echo 'PayMongo schema readiness checks passed: ' . count($checks) . "\n";
