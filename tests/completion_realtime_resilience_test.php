<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$failures = [];

$read = static function (string $path) use ($root, &$failures): string {
    $full = $root . '/' . $path;
    if (!is_file($full)) {
        $failures[] = "Missing {$path}";
        return '';
    }
    return (string)file_get_contents($full);
};
$expect = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) $failures[] = $message;
};

$endpoint = $read('staff/update_order_status_process.php');
$json = $read('includes/json_endpoint.php');
$db = $read('includes/db.php');
$jobs = $read('includes/JobOrderService.php');
$customizations = $read('staff/customizations.php');
$pollJs = $read('public/assets/js/notifications.js');
$pollApi = $read('public/api/push/poll.php');

$expect(strpos($endpoint, 'printflow_json_endpoint_bootstrap();') !== false, 'Completion endpoint must bootstrap protected JSON output.');
$expect(strpos($json, "header('Content-Type: application/json; charset=utf-8')") !== false, 'JSON responses must set an explicit JSON Content-Type.');
$expect(strpos($endpoint, 'LIMIT 1 FOR UPDATE') !== false, 'Completion must serialize transitions with a row lock.');
$expect(strpos($db, 'SELECT @@session.in_transaction AS active_transaction') !== false, 'Nested status services must detect the real mysqli transaction state.');
$expect(strpos($endpoint, "'changed' => false") !== false && strpos($endpoint, "'already_completed'") !== false, 'Repeated completion must return an idempotent success contract.');
$expect(strpos($endpoint, "['paid', 'fully paid']") !== false, 'Store-order completion must require a canonical paid state.');
$expect(strpos($jobs, "['PAID', 'FULLY PAID']") !== false, 'Job completion must accept canonical paid states.');
$expect(strpos($jobs, 'LIMIT 1 FOR UPDATE') !== false && strpos($jobs, '$currentNormalizedStatus === $normalizedNewStatus') !== false, 'Job transitions must lock and short-circuit repeated statuses.');
$expect(substr_count($jobs, 'printflow_db_in_transaction($conn)') >= 2, 'Job creation and completion must preserve an existing caller transaction.');
$expect(strpos($customizations, "if (payload) {") !== false && strpos($customizations, 'incorrect Content-Type') !== false, 'Frontend parser must accept valid JSON despite a legacy wrong Content-Type.');
$expect(strpos($customizations, "this.activeStatus = 'COMPLETED'") !== false && strpos($customizations, 'loadOrders({ force: true })') !== false, 'Completion response must directly refresh the Completed tab.');

$expect(strpos($pollJs, 'pollFailureCount') !== false && strpos($pollJs, '300000') !== false, 'Notification fallback must back off after failures.');
$expect(strpos($pollApi, "header('Retry-After: 60')") !== false, 'Polling 503 must advertise a retry interval.');
$expect(strpos($pollApi, "'available' => false") !== false, 'Polling unavailability must remain valid JSON.');

if ($failures !== []) {
    fwrite(STDERR, "Completion/realtime resilience test failed:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

echo "Completion/realtime resilience test passed.\n";
