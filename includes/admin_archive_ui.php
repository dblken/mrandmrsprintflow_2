<?php

declare(strict_types=1);

/**
 * Admin UI visibility for order archive tools (Settings link + archived_orders.php).
 * APIs, routes, and archive logic stay enabled when this is false.
 */
if (!defined('PRINTFLOW_ADMIN_ARCHIVE_UI_VISIBLE')) {
    define('PRINTFLOW_ADMIN_ARCHIVE_UI_VISIBLE', false);
}

if (!function_exists('printflow_admin_archive_ui_visible')) {
    function printflow_admin_archive_ui_visible(): bool
    {
        return PRINTFLOW_ADMIN_ARCHIVE_UI_VISIBLE;
    }
}
