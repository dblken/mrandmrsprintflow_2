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
    ]) === 'test',
    'unset PAYMONGO_MODE with only test keys resolves to test even when live is enabled'
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
    ]) === 'test',
    'PAYMONGO_MODE=live without live keys falls back to test'
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
    ]) === 'test',
    'PAYMONGO_LIVE_ENABLED=false forces test checkout when test keys exist'
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

$reject = printflow_paymongo_enforce_response_livemode([
    'ok' => true,
    'livemode' => true,
    'mode' => 'test',
], 'test');
$check(
    empty($reject['ok']) && ($reject['error_code'] ?? '') === 'livemode_mismatch',
    'server-side safety rejects live PayMongo resources while in test mode'
);

echo "All {$passed} PayMongo mode resolution tests passed.\n";
