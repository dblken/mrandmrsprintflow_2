<?php
/**
 * Dashboard recent-order service labels must use order-item identity, not COALESCE(product, service).
 */

$root = dirname(__DIR__);

$failures = [];
$assert = static function (bool $ok, string $message) use (&$failures): void {
    if (!$ok) {
        $failures[] = $message;
    }
};

$staffDashboard = (string) file_get_contents($root . '/staff/dashboard.php');
$staffApi = (string) file_get_contents($root . '/staff/api_dashboard_stats.php');
$functions = (string) file_get_contents($root . '/includes/functions.php');

$assert(str_contains($functions, 'function printflow_dashboard_order_service_labels'), 'dashboard label helper must exist');
$assert(str_contains($functions, 'function printflow_admin_order_management_url'), 'admin manage URL helper must exist');
$assert(str_contains($functions, "in_array(\$source, ['service', 'pos'], true)"), 'order line identity must treat POS service lines as services');
$assert(
    !preg_match('/printflow_dashboard_order_service_labels[\s\S]{0,1200}COALESCE\(p\.name, s\.name/s', $staffDashboard),
    'recent orders must not use product-first COALESCE for service_type'
);
$assert(str_contains($staffDashboard, 'printflow_dashboard_order_service_labels'), 'staff dashboard must enrich recent orders with identity labels');
$assert(!str_contains($staffApi, 'COALESCE(p.name, s.name'), 'staff dashboard API must not prefer product name over service');
$assert(str_contains($staffApi, 'printflow_dashboard_order_service_labels'), 'staff dashboard API must enrich service labels');
$assert(
    str_contains($functions, 'printflow_order_uses_customizations_page($orderId)'),
    'staff manage URL must route service/POS custom orders to customizations'
);
$assert(
    str_contains($functions, "in_array(\$source, ['service', 'pos'], true)"),
    'POS customization source must classify lines as services'
);
$assert(
    str_contains($functions, 'getStoreOrderItemSummariesBatch'),
    'dashboard labels must reuse store order item summaries'
);

if ($failures !== []) {
    fwrite(STDERR, "Dashboard order service label test failed:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

echo "Dashboard order service label test passed.\n";
