<?php

$read = static function (string $relativePath): string {
    $path = __DIR__ . '/../' . ltrim($relativePath, '/');
    if (!is_file($path)) {
        throw new RuntimeException('Missing file: ' . $relativePath);
    }
    return (string)file_get_contents($path);
};

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException('FAIL: ' . $message);
    }
    echo 'PASS: ' . $message . PHP_EOL;
};

$filters = $read('includes/staff_status_filters.php');
$reports = $read('staff/reports.php');
$dashboard = $read('staff/dashboard.php');
$notifications = $read('staff/notifications.php');
$orders = $read('staff/orders.php');
$adminStyle = $read('includes/admin_style.php');
$modalJs = $read('public/assets/js/staff_modal_stack.js');

$assert(str_contains($filters, 'function printflow_staff_status_filter_options'), 'shared status helper defines status options');
$assert(str_contains($filters, "if (\$role === 'pos')"), 'shared status helper branches for counter staff');
$assert(str_contains($filters, "'INQUIRY' => 'Inquiry & Design'"), 'shared status helper defines online workflow stages');
$assert(str_contains($filters, 'printflow_staff_notification_type_options'), 'shared notification allowlist exists');
$assert(str_contains($dashboard, 'printflow_staff_status_filter_options'), 'dashboard uses shared status options');
$assert(str_contains($reports, 'printflow_staff_orders_status_clause'), 'reports use shared order status SQL');
$assert(str_contains($reports, 'font-weight: 500'), 'reports top-selling names use normal weight');
$assert(str_contains($reports, 'color: #0f172a'), 'reports sold counts use dark text');
$assert(str_contains($notifications, 'printflow_staff_notification_type_options'), 'notifications use role-specific types');
$assert(str_contains($orders, 'Total Items Sold'), 'walk-in KPI shows total items sold');
$assert(str_contains($orders, 'SUM(oi.quantity)'), 'total items sold sums order item quantities');
$assert(str_contains($adminStyle, 'body.pf-modal-open .main-content'), 'modal stack fix raises main content above sidebar');
$assert(str_contains($modalJs, 'pf-modal-open'), 'modal stack script toggles body class');

echo "Staff status filter regression test passed.\n";
