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

$helper = $read('includes/staff_report_top_services.php');
$reports = $read('staff/reports.php');
$functions = $read('includes/functions.php');
$adminMobile = $read('public/assets/js/admin-mobile.js');
$staffTheme = $read('includes/staff_theme.php');
$notifications = $read('customer/notifications.php');

$assert(str_contains($helper, 'printflow_staff_report_is_pseudo_service_name'), 'top services helper defines pseudo-name guard');
$assert(str_contains($helper, "'customization'"), 'pseudo list includes customization');
$assert(str_contains($helper, 'printflow_staff_report_resolve_service_from_hints'), 'top services helper resolves catalog names');
$assert(str_contains($helper, 'oi.service_id'), 'top services joins order_items.service_id');
$assert(!str_contains($helper, "'Customization') AS service_name"), 'helper does not COALESCE to Customization');

$assert(str_contains($reports, 'printflow_staff_report_top_selling_services'), 'reports page uses shared top services helper');
$assert(str_contains($reports, "'top_services'"), 'reports ajax refresh handler exists');

$assert(str_contains($functions, 'printflow_customer_modal_suppress_redundant_dimension_specs'), 'dimension dedupe helper exists');
$assert(str_contains($functions, 'printflow_customer_modal_parse_dimension_pair'), 'dimension pair parser exists');

$assert(str_contains($adminMobile, "panel.querySelector('.filter-close-btn')"), 'admin-mobile skips duplicate close when filter-close-btn exists');

$assert(!str_contains($staffTheme, '.tp-sold .tp-sold-label,'), 'POS theme no longer forces tp-sold-label accent color in shared block');
$assert(str_contains($staffTheme, 'html.printflow-staff.printflow-staff-pos .tp-sold'), 'POS theme keeps tp-sold dark text override');

$assert(str_contains($notifications, 'notif-action-btn'), 'notifications page uses unified action button class');
$assert(str_contains($notifications, 'background: #0e7490'), 'notifications action buttons reuse view-button hover fill');

$assert(str_contains($functions, 'printflow_customer_modal_collapse_equivalent_dimension_fields'), 'size/dimensions collapse helper exists');
$assert(str_contains($functions, 'printflow_customer_modal_finalize_customer_dimension_labels'), 'customer Dimensions label finalize exists');
$assert(str_contains($functions, "abs(\$widthVal - \$sizePair[0])"), 'dimension dedupe compares parsed width/height to size pair');

echo PHP_EOL . 'All staff report / UI consistency tests passed.' . PHP_EOL;
