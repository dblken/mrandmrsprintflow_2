<?php
declare(strict_types=1);

$tablesReady = true;
function db_query($sql, $types = '', $params = [])
{
    global $tablesReady;
    if (str_contains((string)$sql, 'information_schema.tables')) {
        return [['n' => $tablesReady ? 1 : 0]];
    }
    return [];
}

require_once __DIR__ . '/../includes/temporary_order_visibility.php';

$failures = [];
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) $failures[] = $message;
};

$scope = printflow_temporary_unpaid_hide_eligible_sql('o');
$assert(str_contains($scope, "'2026-08-31 16:00:00'") && str_contains($scope, "'2026-10-15 16:00:00'"), 'scope uses inclusive Manila dates converted to a half-open UTC TIMESTAMP range');
$assert(str_contains($scope, "BINARY TRIM(COALESCE(o.status, '')) = 'Pending'") && str_contains($scope, "BINARY TRIM(COALESCE(o.payment_status, '')) = 'Unpaid'"), 'scope requires exact Pending and Unpaid states');
$assert(str_contains($scope, 'FROM payment_submissions') && str_contains($scope, 'FROM provider_payments'), 'scope excludes any payment submission or provider record');
$assert(str_contains($scope, 'FROM job_orders') && str_contains($scope, 'FROM inventory_movements'), 'scope excludes active/processed production and inventory-linked orders');
$assert(str_contains($scope, 'FROM printflow_order_archive_map') && str_contains($scope, 'FROM historical_generator_hidden_orders'), 'already archived orders remain excluded');
$assert(str_contains($scope, 'FROM printflow_pending_unpaid_hidden_orders pf_tmp_done'), 'repeated hide runs exclude already processed visibility mappings');
$assert(str_contains(printflow_temporary_hidden_order_exclusion_sql('o.order_id'), 'pf_tmp_hidden.order_id = o.order_id'), 'list exclusion is limited to the new visibility map');

$root = dirname(__DIR__);
$endpoint = (string)file_get_contents($root . '/admin/hide_pending_unpaid_orders_process.php');
$adminPage = (string)file_get_contents($root . '/admin/job_orders.php');
$api = (string)file_get_contents($root . '/admin/job_orders_api.php');
$staffOrders = (string)file_get_contents($root . '/staff/orders.php');
$migration = (string)file_get_contents($root . '/database/temporary_pending_unpaid_order_visibility.sql');

$role = strpos($endpoint, "require_role('Admin')");
$csrf = strpos($endpoint, "verify_csrf_token(\$_POST['csrf_token'] ?? '')");
$assert($role !== false && $csrf !== false && $role < $csrf, 'Admin authorization and CSRF validation guard the hide write');
$assert(str_contains($endpoint, 'begin_transaction()') && str_contains($endpoint, 'FOR UPDATE') && str_contains($endpoint, 'rollback()'), 'hide action locks and rechecks candidates in a rollback-safe transaction');
$assert(str_contains($endpoint, 'INSERT IGNORE INTO printflow_pending_unpaid_hidden_orders') && !preg_match('/\bDELETE\s+FROM\s+(orders|order_items|customers)|\bUPDATE\s+(orders|customers)\s+SET/i', $endpoint), 'action only inserts idempotent visibility metadata');
$assert(str_contains($adminPage, "(get_user_type() ?? '') === 'Admin'") && str_contains($adminPage, 'Hide Pending Unpaid Orders'), 'button is Admin-only and has the requested label');
$assert(!preg_match('/\bRestore\b|\bDelete\b|confirm\s*\(/i', $adminPage), 'hide UI adds no restore, deletion, or confirmation flow');
$assert(str_contains($api, 'printflow_temporary_hidden_order_exclusion_sql') && str_contains($staffOrders, 'printflow_temporary_hidden_order_exclusion_sql'), 'Customizations API and operational order list apply the narrow visibility exclusion');
$assert(str_contains($migration, 'CREATE TABLE IF NOT EXISTS `printflow_pending_unpaid_hidden_orders`') && str_contains($migration, 'PRIMARY KEY (`order_id`)'), 'migration creates an idempotent order-keyed visibility table');

if ($failures !== []) {
    fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL);
    exit(1);
}

echo "Temporary pending/unpaid hide contract checks passed.\n";
