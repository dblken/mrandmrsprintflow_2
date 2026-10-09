<?php
declare(strict_types=1);
define('DEMO_SEED_TEST_SUPPORT_ONLY', true);
require_once __DIR__ . '/demo_seed_deletion_transaction_test.php';

// Execute an unchanged copy of the real endpoint with test-only auth and SQLite bootstraps.
$root = sys_get_temp_dir() . '/printflow-demo-api-' . bin2hex(random_bytes(8));
mkdir($root); mkdir($root . '/includes'); mkdir($root . '/admin'); mkdir($root . '/admin/api'); mkdir($root . '/sessions');
$databaseFile = $root . '/fixture.sqlite';
$fixture = fixture($databaseFile);
$files = [];
$put = static function (string $relative, string $content) use ($root, &$files): void {
    $path = $root . '/' . $relative; file_put_contents($path, $content); $files[] = $path;
};
$put('admin/api/demo_seed_data.php', (string)file_get_contents(__DIR__ . '/../admin/api/demo_seed_data.php'));
$put('includes/functions.php', '<?php');
$put('includes/auth.php', <<<'PHP'
<?php
function is_logged_in() { return ($_SESSION['test_role'] ?? '') !== ''; }
function get_user_type() { return $_SESSION['test_role'] ?? ''; }
function get_user_id() { return 1; }
function require_role($role) { if (get_user_type() !== $role) throw new RuntimeException('Unauthorized test role.'); }
function verify_csrf_token($value) { return hash_equals('fixture-csrf-token', $value); }
PHP);
$put('includes/demo_seed_data.php', "<?php\ndefine('DEMO_SEED_TEST_SUPPORT_ONLY', true);\nrequire_once " . var_export(__DIR__ . '/demo_seed_deletion_transaction_test.php', true) . ";\n" . <<<'PHP'
$testDatabase = new DemoSeedSqliteTestDatabase(DEMO_API_TEST_DATABASE);
function testTool() { global $testDatabase; return new DemoSeedDeletionTool($testDatabase); }
function demo_seed_list_batches() { return testTool()->batches(); }
function demo_seed_delete_preview($batch, $verifiedOnly = false) { return testTool()->preview($batch, $verifiedOnly); }
function demo_seed_delete_batch($batch, $admin, $fingerprint, $verifiedOnly = false) { return testTool()->delete($batch, $admin, $fingerprint, $verifiedOnly); }
function demo_seed_parse_import_failure_message($message) { return ['seed_row_key'=>null,'step'=>null,'message'=>$message]; }
function demo_seed_ensure_tables() { throw new RuntimeException('Deletion/preview must not create tables.'); }
PHP);
$put('request.php', "<?php\ndefine('DEMO_API_TEST_DATABASE', " . var_export($databaseFile, true) . ");\n" . <<<'PHP'
$request = json_decode(file_get_contents($argv[1]), true, 512, JSON_THROW_ON_ERROR);
session_save_path(__DIR__ . '/sessions'); session_id('fixture-session'); session_start();
$_SESSION['test_role'] = $request['role'] ?? 'Admin';
if (!empty($request['expire_preview'])) foreach ($_SESSION['demo_seed_deletion_previews'] ?? [] as $key => $_item) $_SESSION['demo_seed_deletion_previews'][$key]['expires_at'] = time() - 1;
$_SERVER['REQUEST_METHOD'] = $request['method'] ?? 'POST';
$_POST = $request['post'];
register_shutdown_function(static function () { file_put_contents(__DIR__ . '/status.json', json_encode(['status' => http_response_code() ?: 200])); });
require __DIR__ . '/admin/api/demo_seed_data.php';
PHP);
$passed = 0;
$call = static function (array $post, string $role = 'Admin', bool $expire = false) use ($root): array {
    $requestPath = $root . '/request.json';
    file_put_contents($requestPath, json_encode(['post' => $post, 'role' => $role, 'expire_preview' => $expire], JSON_THROW_ON_ERROR));
    $process = proc_open([PHP_BINARY, $root . '/request.php', $requestPath], [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']], $pipes);
    if (!is_resource($process)) throw new RuntimeException('Could not run API fixture.');
    fclose($pipes[0]); $output = stream_get_contents($pipes[1]); $errors = stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]);
    $exit = proc_close($process);
    $data = json_decode($output, true);
    if ($exit !== 0 || !is_array($data)) throw new RuntimeException('API fixture failed: ' . $errors . ' ' . $output);
    return ['status' => json_decode(file_get_contents($root . '/status.json'), true)['status'], 'data' => $data];
};
$pass = static function (bool $ok, string $name) use (&$passed): void { assertTrue($ok, $name); $passed++; echo 'PASS: ', $name, PHP_EOL; };
$batch = 'meet_20261001_v1';
$base = ['csrf_token'=>'fixture-csrf-token', 'batch_id'=>$batch];
$delete = $base + ['action'=>'delete_batch', 'confirm_text'=>DemoSeedDeletionTool::CONFIRMATION, 'backup_confirmed'=>'1', 'verified_only'=>'0'];
try {
    $before = businessSnapshot($fixture);
    $response = $call($base + ['action'=>'delete_preview'], 'Staff');
    $pass($response['status'] === 403 && !$response['data']['success'], 'actual API rejects unauthorized role with JSON');
    $response = $call($base + ['action'=>'delete_preview'], '');
    $pass($response['status'] === 401, 'actual API rejects missing login');
    $response = $call(['action'=>'delete_preview','batch_id'=>$batch,'csrf_token'=>'bad']);
    $pass($response['status'] === 403 && str_contains($response['data']['message'], 'CSRF'), 'actual API rejects invalid CSRF');
    $response = $call($delete);
    $pass(!$response['data']['success'] && str_contains($response['data']['message'], 'Dry run expired'), 'actual API rejects delete without a dry run');
    $response = $call(['action'=>'delete_preview','batch_id'=>'wrong','csrf_token'=>'fixture-csrf-token']);
    $pass(!$response['data']['success'] && str_contains($response['data']['message'], 'not found'), 'actual API rejects wrong batch ID');
    $response = $call($base + ['action'=>'delete_preview','verified_only'=>'0']);
    $token = $response['data']['preview_token'];
    $pass($response['data']['preview']['safe_to_delete'] && businessSnapshot($fixture) === $before, 'actual dry-run API returns schema and records without writing');
    $response = $call(array_replace($delete, ['preview_token'=>$token, 'confirm_text'=>'DELETING MEETING DATA']));
    $pass(!$response['data']['success'] && str_contains($response['data']['message'], 'exact phrase'), 'actual API rejects screenshot confirmation phrase');
    $response = $call(array_replace($delete, ['preview_token'=>$token, 'backup_confirmed'=>'0']));
    $pass(!$response['data']['success'] && str_contains($response['data']['message'], 'backup'), 'actual API requires backup acknowledgement');
    $response = $call(array_replace($delete, ['preview_token'=>$token, 'batch_id'=>'other']));
    $pass(!$response['data']['success'], 'actual API binds token to exact batch');
    $response = $call(array_replace($delete, ['preview_token'=>$token, 'verified_only'=>'1']));
    $pass(!$response['data']['success'], 'actual API binds token to deletion mode');
    $response = $call($delete + ['preview_token'=>$token], 'Admin', true);
    $pass(!$response['data']['success'] && str_contains($response['data']['message'], 'expired'), 'actual API rejects expired preview');
    $response = $call($base + ['action'=>'delete_preview','verified_only'=>'0']);
    $token = $response['data']['preview_token'];
    $response = $call($delete + ['preview_token'=>$token]);
    $pass($response['data']['success'] && $response['data']['result']['deleted_counts']['orders'] === 1 && countTable($fixture, 'orders') === 1, 'actual API deletes exact verified batch and returns counts');
    $response = $call($delete + ['preview_token'=>$token]);
    $pass($response['data']['success'] && $response['data']['result']['no_records'] && $response['data']['result']['deleted_counts'] === [], 'actual duplicate API request deletes no additional records');
    $response = $call($base + ['action'=>'delete_preview','verified_only'=>'0']);
    $pass($response['data']['preview']['no_records'] && $response['data']['preview']['registry_rows'] === 0, 'actual post-delete API preview shows zero verified rows');
    $pass(countTable($fixture, 'customers') === 1 && countTable($fixture, 'products') === 1 && countTable($fixture, 'users') === 1, 'actual API leaves real/shared fixture records untouched');
    echo 'Demo deletion API tests: ', $passed, ' passed (test auth/bootstrap and SQLite; no production connection).', PHP_EOL;
} finally {
    unset($fixture);
    foreach ([$root . '/request.json', $root . '/status.json', $databaseFile, $root . '/sessions/sess_fixture-session'] as $file) if (is_file($file)) unlink($file);
    foreach (array_reverse($files) as $file) if (is_file($file)) unlink($file);
    foreach (['sessions', 'admin/api', 'admin', 'includes', ''] as $directory) rmdir($root . ($directory !== '' ? '/' . $directory : ''));
}
