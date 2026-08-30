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
$assert(strpos($orders, '@container staff-orders-card (min-width: 801px) and (max-width: 1040px)') !== false, 'Orders must retain a compact table at medium component widths');
$assert(strpos($orders, '@container staff-orders-card (max-width: 800px)') !== false, 'Orders must reserve cards for genuinely narrow component widths');
$assert(strpos($orders, '.orders-table--online .col-action { width: 20%; }') !== false, 'Compact Online Orders must preserve room for side-by-side actions');
$assert(substr_count($orders, 'class="table-action-btn alt"') === 0, 'Orders View and Message actions must use the same teal outlined base style');
$assert(strpos($orders, 'class="action-cell orders-card-actions') === false, 'Orders actions must not inherit the shared mobile flex/max-width rule');
$assert(substr_count($orders, 'class="orders-card-actions') >= 2, 'Initial and AJAX rows must share the dedicated two-slot action wrapper');
$assert(preg_match_all('/class="orders-card-actions[^\"]*"[^>]*>(.*?)<\/div>/s', $orders, $actionWrappers) >= 2, 'Initial and AJAX action wrappers must be inspectable');
foreach ($actionWrappers[1] as $actionMarkup) {
    $assert(substr_count($actionMarkup, 'class="table-action-btn"') === 2, 'Every online Orders action wrapper must contain exactly View and Message slots');
}
$assert(strpos($orders, 'height: 40px !important;') !== false && strpos($orders, 'max-height: 40px !important;') !== false, 'Mobile Orders actions must have identical fixed heights');
$assert(strpos($orders, ".pagination-wrapper .pagination-link.is-active") !== false, 'Orders pagination must have a scoped Customizations-style active state');
$assert(strpos($orders, 'min-width: 38px !important;') !== false && strpos($orders, 'border-radius: 10px !important;') !== false, 'Orders pagination must reuse the Customizations control geometry');
$assert(strpos($orders, "content: '…';") !== false, 'Orders pagination gaps must use a compact ellipsis');
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
$assert(strpos($customizations, 'class="customizations-mobile-list"') !== false, 'Narrow Customizations must use a block-card list outside desktop table geometry');
$assert(strpos($customizations, '.pf-staff-customizations-root .customizations-table-scroll') !== false, 'The desktop table wrapper must be explicitly removed from the narrow layout path');
$assert(strpos($customizations, 'class="customization-mobile-card"') !== false, 'Each narrow Customization record must be one viewport-width card');
$assert(strpos($customizations, 'grid-template-columns: minmax(64px, auto) minmax(0, 1fr)') !== false, 'Mobile metadata values must be allowed to shrink without overflow');
$assert(strpos($customizations, "? 'Loading...' : 'View Order'") !== false, 'The mobile View action must remain inside the card footer');
$assert(preg_match('/\?>\s*\?>\s*<!DOCTYPE html>/', $customizations) !== 1, 'The page must not emit a literal stray PHP closing token');
foreach (['Order', 'Details', 'Status', 'Customer', 'Created', 'Action'] as $label) {
    $assert(strpos($customizations, 'data-label="' . $label . '"') !== false, "Customization cards must expose {$label}");
}
$assert(strpos($customizations, 'ordersSummaryPageSize: 15') !== false, 'Customization list must retain the 15-row lightweight summary page size');
$assert(strpos($customizations, 'modalCacheTtlMs: 60000') !== false, 'Customization modal detail caching must remain intact');
$assert(strpos($customizations, 'customization_counts') !== false, 'Customization lightweight counts must remain intact');

echo "Staff responsive layout contract tests passed.\n";
