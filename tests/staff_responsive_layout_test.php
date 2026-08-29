<?php

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
};

$orders = (string)file_get_contents(__DIR__ . '/../staff/orders.php');
$customizations = (string)file_get_contents(__DIR__ . '/../staff/customizations.php');

$assert(strpos($orders, 'container: staff-orders-card / inline-size') !== false, 'Orders must respond to available component width after the sidebar');
$assert(strpos($orders, '@container staff-orders-card (max-width: 1050px)') !== false, 'Orders must switch before narrow desktop columns collide');
$assert(strpos($orders, '@container staff-orders-card (max-width: 430px)') !== false, 'Orders must compact labels for small phones');
$assert(strpos($orders, 'grid-template-columns: repeat(2, minmax(0, 1fr)) !important') !== false, 'Orders KPI cards must form a readable tablet grid');
$assert(strpos($orders, 'overflow-x: hidden !important') !== false, 'Order cards must not require horizontal scrolling');
$assert(strpos($orders, 'height: auto !important') !== false, 'Order cards must size naturally without inherited blank height');
foreach (['Order Code', 'Product', 'Customer', 'Source', 'Date', 'Total', 'Status', 'Actions'] as $label) {
    $assert(substr_count($orders, 'data-label="' . $label . '"') >= 2, "Orders initial and AJAX rows must expose {$label}");
}
$assert(strpos($orders, "orders-table--pos' : 'orders-table--online") !== false, 'Online and Counter desktop tables must retain role-appropriate column sets');
$assert(strpos($orders, 'ordersSummaryPageSize') === false, 'Responsive Orders changes must not add a second data-loading architecture');

$assert(strpos($customizations, 'container: customization-list / inline-size') !== false, 'Customizations must respond to available component width after the sidebar');
$assert(strpos($customizations, '@container customization-list (max-width: 960px)') !== false, 'Customizations must become cards at tablet/narrow desktop widths');
$assert(strpos($customizations, '@container customization-list (max-width: 430px)') !== false, 'Customizations must compact labels for small phones');
$assert(strpos($customizations, '.pf-staff-customizations-root .kpi-row') !== false, 'Customization KPI cards must retain responsive tablet rules');
$assert(strpos($customizations, 'tr.customization-row') !== false, 'Customizations must reuse one semantic row for Online and Counter cards');
foreach (['Order', 'Details', 'Status', 'Customer', 'Created', 'Action'] as $label) {
    $assert(strpos($customizations, 'data-label="' . $label . '"') !== false, "Customization cards must expose {$label}");
}
$assert(strpos($customizations, 'ordersSummaryPageSize: 15') !== false, 'Customization list must retain the 15-row lightweight summary page size');
$assert(strpos($customizations, 'modalCacheTtlMs: 60000') !== false, 'Customization modal detail caching must remain intact');
$assert(strpos($customizations, 'customization_counts') !== false, 'Customization lightweight counts must remain intact');

echo "Staff responsive layout contract tests passed.\n";
