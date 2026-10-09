<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$api = file_get_contents($root . '/staff/api_dashboard_stats.php');
$helpers = file_get_contents($root . '/includes/staff_dashboard_revenue.php');

if ($api === false || $helpers === false) {
    throw new RuntimeException('Unable to read staff dashboard revenue sources.');
}

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException('FAIL: ' . $message);
    }
};

$assert(strpos($helpers, "in_array(\$code, ['ALL', 'COMPLETED'], true)") !== false, 'online revenue counts when status filter is ALL');
$assert(strpos($helpers, 'function printflow_staff_online_dashboard_revenue_sql(') !== false, 'online revenue uses paid completed rules');
$assert(strpos($api, 'printflow_staff_dashboard_should_compute_revenue') !== false, 'dashboard API uses shared revenue eligibility');
$assert(strpos($api, "\$status_filter === '' || \$status_filter === 'COMPLETED'") === false, 'dashboard API no longer treats only empty string as revenue-eligible');
$assert(strpos($api, 'printflow_staff_dashboard_revenue_debug') !== false, 'dashboard API exposes debug revenue diagnostics');

echo "OK staff_dashboard_revenue_test\n";
