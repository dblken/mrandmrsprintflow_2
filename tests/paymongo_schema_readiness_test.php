<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$provider = (string)file_get_contents($root . '/includes/provider_payments.php');
$migration = (string)file_get_contents(
    $root . '/database/migrate_paymongo_provider_livemode_20261009.php'
);

// Exercise the actual readiness function without loading the database bootstrap
// or inspecting customer/payment data. The fixtures represent schema metadata.
$functionStart = strpos($provider, 'function printflow_provider_payment_intent_schema_status(');
$functionEnd = strpos($provider, 'function printflow_provider_payment_intent_schema_ready(', $functionStart ?: 0);
if ($functionStart === false || $functionEnd === false) {
    throw new RuntimeException('The schema readiness function could not be located.');
}
eval(substr($provider, $functionStart, $functionEnd - $functionStart));

final class PaymongoSchemaFixtureResult {
    public function __construct(private array $row) {}
    public function fetch_row(): ?array { return $this->row === [] ? null : array_values($this->row); }
    public function fetch_assoc(): ?array { return $this->row === [] ? null : $this->row; }
    public function free(): void {}
}

final class PaymongoSchemaFixtureConnection {
    public int $errno = 1142;
    public string $sqlstate = '42000';
    public bool $inspectionFails = false;
    public array $tables = ['provider_payments', 'provider_webhook_events'];
    public array $columns = [
        'mode', 'payment_flow', 'payment_intent_id', 'payment_method_id', 'qr_image_url',
        'qr_expires_at', 'client_key', 'idempotency_key', 'payment_status', 'provider_status',
        'provider_livemode', 'provider_livemode_verified_at',
    ];
    public string $modeType = "enum('test','live')";

    public function real_escape_string(string $value): string { return addslashes($value); }
    public function query(string $sql): PaymongoSchemaFixtureResult|false {
        if ($this->inspectionFails) return false;
        if (preg_match("/^SHOW TABLES LIKE '([a-z_]+)'$/", $sql, $match)) {
            return new PaymongoSchemaFixtureResult(
                in_array($match[1], $this->tables, true) ? [$match[1]] : []
            );
        }
        if (preg_match("/^SHOW COLUMNS FROM `provider_payments` LIKE '([a-z_]+)'$/", $sql, $match)) {
            return new PaymongoSchemaFixtureResult(
                in_array($match[1], $this->columns, true)
                    ? ['Field' => $match[1], 'Type' => $match[1] === 'mode' ? $this->modeType : 'fixture']
                    : []
            );
        }
        throw new RuntimeException('Unexpected schema query.');
    }
}

$conn = new PaymongoSchemaFixtureConnection();
$liveWithoutTestUrl = printflow_provider_payment_intent_schema_status('live');
$testWithoutTestUrl = printflow_provider_payment_intent_schema_status('test');
$conn->columns[] = 'provider_test_url';
$completeTest = printflow_provider_payment_intent_schema_status('test');
$conn->columns = array_values(array_diff($conn->columns, ['provider_livemode_verified_at']));
$missingVerification = printflow_provider_payment_intent_schema_status('live');
$conn = new PaymongoSchemaFixtureConnection();
$conn->inspectionFails = true;
$inspectionFailure = printflow_provider_payment_intent_schema_status('live');
$conn = new PaymongoSchemaFixtureConnection();
$conn->modeType = "enum('test')";
$unsupportedLive = printflow_provider_payment_intent_schema_status('live');
unset($conn);

$checks = [
    'Live schema is actually ready without the Test simulator column' =>
        !empty($liveWithoutTestUrl['ready']),
    'Test schema identifies precisely the missing simulator column' =>
        empty($testWithoutTestUrl['ready'])
        && ($testWithoutTestUrl['missing'] ?? []) === ['provider_payments.provider_test_url'],
    'Complete Test schema passes the actual readiness function' =>
        !empty($completeTest['ready']),
    'Live schema still requires the provider verification timestamp' =>
        empty($missingVerification['ready'])
        && ($missingVerification['missing'] ?? []) === ['provider_payments.provider_livemode_verified_at'],
    'Failed schema queries do not become a missing-migration diagnosis' =>
        ($inspectionFailure['error_code'] ?? '') === 'payment_intent_schema_inspection_failed'
        && !isset($inspectionFailure['missing']),
    'A Test-only mode enum fails closed for Live creation' =>
        empty($unsupportedLive['ready'])
        && ($unsupportedLive['missing'] ?? []) === ['provider_payments.mode does not support live'],
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
