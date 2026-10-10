<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/paymongo.php';

$root = dirname(__DIR__);
$paymongo = (string)file_get_contents($root . '/includes/paymongo.php');
$provider = (string)file_get_contents($root . '/includes/provider_payments.php');

$passed = 0;
$check = static function (bool $condition, string $name) use (&$passed): void {
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$name}\n");
        exit(1);
    }
    $passed++;
    echo "PASS: {$name}\n";
};

$runMode = static function (array $env) use ($root): string {
    $script = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pf_paymongo_mode_' . bin2hex(random_bytes(6)) . '.php';
    $payload = var_export([
        'root' => $root,
        'clear' => [
            'PAYMONGO_MODE',
            'PAYMONGO_LIVE_ENABLED',
            'PAYMONGO_PUBLIC_KEY',
            'PAYMONGO_SECRET_KEY',
            'PAYMONGO_TEST_PUBLIC_KEY',
            'PAYMONGO_TEST_SECRET_KEY',
            'PAYMONGO_TEST_WEBHOOK_SECRET',
            'PAYMONGO_LIVE_PUBLIC_KEY',
            'PAYMONGO_LIVE_SECRET_KEY',
            'PAYMONGO_LIVE_WEBHOOK_SECRET',
            'PAYMONGO_WEBHOOK_SECRET',
        ],
        'env' => $env,
    ], true);
    file_put_contents($script, <<<PHP
<?php
declare(strict_types=1);
\$config = {$payload};
chdir(\$config['root']);
foreach (\$config['clear'] as \$name) {
    putenv(\$name);
    unset(\$_ENV[\$name], \$_SERVER[\$name]);
}
foreach (\$config['env'] as \$name => \$value) {
    putenv(\$name . '=' . \$value);
    \$_ENV[\$name] = \$value;
    \$_SERVER[\$name] = \$value;
}
require 'includes/env.php';
require 'includes/paymongo.php';
echo printflow_paymongo_mode();
PHP);
    $output = shell_exec('php ' . escapeshellarg($script));
    @unlink($script);
    return trim((string)$output);
};

$check(
    str_contains($paymongo, 'function printflow_paymongo_live_checkout_allowed()')
        && str_contains($paymongo, 'function printflow_paymongo_enforce_response_livemode(')
        && str_contains($paymongo, 'function printflow_paymongo_webhook_secret_for_mode('),
    'paymongo exposes live checkout gate, livemode enforcement, and webhook secret resolver'
);
$check(
    !str_contains($provider, 'function printflow_paymongo_webhook_secret_for_mode('),
    'webhook secret resolver is centralized in paymongo.php'
);
$check(
    str_contains($provider, 'provider_livemode')
        && str_contains($provider, "'livemode' => \$providerLivemode"),
    'provider payment public payload exposes stored PayMongo livemode'
);

$testKey = 'sk_test_' . str_repeat('a', 24);
$liveKey = 'sk_live_' . str_repeat('b', 24);
$testPk = 'pk_test_' . str_repeat('c', 24);

$check(
    $runMode([
        'PAYMONGO_MODE' => '',
        'PAYMONGO_LIVE_ENABLED' => 'true',
        'PAYMONGO_LIVE_SECRET_KEY' => '',
        'PAYMONGO_LIVE_PUBLIC_KEY' => '',
        'PAYMONGO_SECRET_KEY' => $testKey,
        'PAYMONGO_PUBLIC_KEY' => $testPk,
        'PAYMONGO_TEST_WEBHOOK_SECRET' => 'whsk_test_secret',
    ]) === '',
    'unset PAYMONGO_MODE with Live enabled fails closed instead of selecting Test'
);
$check(
    $runMode([
        'PAYMONGO_MODE' => '',
        'PAYMONGO_LIVE_ENABLED' => 'false',
        'PAYMONGO_TEST_SECRET_KEY' => $testKey,
        'PAYMONGO_TEST_PUBLIC_KEY' => $testPk,
        'PAYMONGO_TEST_WEBHOOK_SECRET' => 'whsk_test_secret',
    ]) === 'test',
    'unset PAYMONGO_MODE still supports a Test-only configuration without Live settings'
);
$check(
    $runMode([
        'PAYMONGO_MODE' => 'live',
        'PAYMONGO_LIVE_ENABLED' => 'true',
        'PAYMONGO_LIVE_SECRET_KEY' => '',
        'PAYMONGO_LIVE_PUBLIC_KEY' => '',
        'PAYMONGO_SECRET_KEY' => $testKey,
        'PAYMONGO_PUBLIC_KEY' => $testPk,
        'PAYMONGO_TEST_WEBHOOK_SECRET' => 'whsk_test_secret',
    ]) === '',
    'PAYMONGO_MODE=live without live keys fails closed instead of falling back to test'
);
$check(
    $runMode([
        'PAYMONGO_MODE' => 'live',
        'PAYMONGO_LIVE_ENABLED' => 'false',
        'PAYMONGO_LIVE_SECRET_KEY' => $liveKey,
        'PAYMONGO_LIVE_PUBLIC_KEY' => 'pk_live_' . str_repeat('d', 24),
        'PAYMONGO_TEST_SECRET_KEY' => $testKey,
        'PAYMONGO_TEST_PUBLIC_KEY' => $testPk,
        'PAYMONGO_TEST_WEBHOOK_SECRET' => 'whsk_test_secret',
    ]) === '',
    'PAYMONGO_LIVE_ENABLED=false fails closed when Live mode is explicitly selected'
);
$check(
    $runMode([
        'PAYMONGO_MODE' => 'test',
        'PAYMONGO_LIVE_ENABLED' => 'true',
        'PAYMONGO_LIVE_SECRET_KEY' => $liveKey,
        'PAYMONGO_LIVE_PUBLIC_KEY' => 'pk_live_' . str_repeat('d', 24),
        'PAYMONGO_TEST_SECRET_KEY' => $testKey,
        'PAYMONGO_TEST_PUBLIC_KEY' => $testPk,
        'PAYMONGO_TEST_WEBHOOK_SECRET' => 'whsk_test_secret',
    ]) === 'test',
    'PAYMONGO_MODE=test keeps checkout on test even when live keys and LIVE_ENABLED are set'
);
$check(
    $runMode([
        'PAYMONGO_MODE' => 'live',
        'PAYMONGO_LIVE_ENABLED' => 'true',
        'PAYMONGO_LIVE_SECRET_KEY' => $liveKey,
        'PAYMONGO_LIVE_PUBLIC_KEY' => 'pk_live_' . str_repeat('d', 24),
        'PAYMONGO_LIVE_WEBHOOK_SECRET' => 'whsk_live_secret',
        'PAYMONGO_TEST_SECRET_KEY' => $testKey,
        'PAYMONGO_TEST_PUBLIC_KEY' => $testPk,
        'PAYMONGO_TEST_WEBHOOK_SECRET' => 'whsk_test_secret',
    ]) === 'live',
    'PAYMONGO_MODE=live selects Live when both environments are configured'
);
foreach ([
    'PAYMONGO_MODE' => 'test',
    'PAYMONGO_TEST_SECRET_KEY' => $testKey,
    'PAYMONGO_TEST_PUBLIC_KEY' => $testPk,
] as $name => $value) {
    putenv($name . '=' . $value);
    $_ENV[$name] = $value;
    $_SERVER[$name] = $value;
}
$blockedLiveCreate = printflow_paymongo_resolve_api_mode('live', 'POST');
$check(
    $blockedLiveCreate === 'test',
    'mutating PayMongo API calls are forced to test while PAYMONGO_MODE=test'
);
foreach ([
    'PAYMONGO_MODE' => 'live',
    'PAYMONGO_LIVE_ENABLED' => 'false',
    'PAYMONGO_LIVE_SECRET_KEY' => '',
    'PAYMONGO_LIVE_PUBLIC_KEY' => '',
] as $name => $value) {
    putenv($name . '=' . $value);
    $_ENV[$name] = $value;
    $_SERVER[$name] = $value;
}
$blockedTestFallback = printflow_paymongo_resolve_api_mode('live', 'POST');
$check(
    $blockedTestFallback === '',
    'Live API requests fail closed instead of falling back to configured Test credentials'
);
foreach ([
    'PAYMONGO_MODE' => 'live',
    'PAYMONGO_LIVE_ENABLED' => 'true',
    'PAYMONGO_LIVE_PUBLIC_KEY' => 'pk_live_' . str_repeat('d', 24),
    'PAYMONGO_LIVE_SECRET_KEY' => $liveKey,
    'PAYMONGO_LIVE_WEBHOOK_SECRET' => 'whsk_live_secret',
    'PAYMONGO_LIVE_DIRECT_METHODS' => 'qrph',
] as $name => $value) {
    putenv($name . '=' . $value);
    $_ENV[$name] = $value;
    $_SERVER[$name] = $value;
}
$check(
    printflow_paymongo_mode() === 'live'
        && printflow_paymongo_resolve_api_mode('live', 'POST') === 'live'
        && printflow_paymongo_enabled_methods('live') === ['qrph'],
    'Live mode uses the Live API environment and enables QRPh only with its Live webhook secret and allowlist'
);

$reject = printflow_paymongo_enforce_response_livemode([
    'ok' => true,
    'livemode' => true,
    'mode' => 'test',
], 'test');
$check(
    empty($reject['ok']) && ($reject['error_code'] ?? '') === 'livemode_mismatch',
    'server-side safety rejects live PayMongo resources while in test mode'
);
$missingMode = printflow_paymongo_enforce_response_livemode([
    'ok' => true,
    'mode' => 'test',
], 'test');
$check(
    empty($missingMode['ok']) && ($missingMode['error_code'] ?? '') === 'livemode_unverified',
    'server-side safety rejects responses without a boolean provider livemode value'
);
$nonBooleanMode = printflow_paymongo_enforce_response_livemode([
    'ok' => true,
    'mode' => 'test',
    'livemode' => 0,
], 'test');
$check(
    empty($nonBooleanMode['ok']) && ($nonBooleanMode['error_code'] ?? '') === 'livemode_unverified',
    'server-side safety rejects non-boolean provider livemode values'
);
$check(
    str_contains($provider, "\$mode === 'live'")
        && str_contains($provider, "\$providerLivemode !== null")
        && str_contains($provider, 'printflow_provider_payment_customer_test_simulation_url'),
    'public QR exposure requires verified Live mode while Test payments use a separate simulator URL'
);

echo "All {$passed} PayMongo mode resolution tests passed.\n";
