<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/staff_dashboard_cache.php';

// Clear PHP OpCache
if (function_exists('opcache_reset')) {
    opcache_reset();
    echo "OpCache cleared!<br>";
} else {
    echo "OpCache not enabled<br>";
}

// Clear any file-based cache
if (function_exists('apcu_clear_cache')) {
    apcu_clear_cache();
    echo "APCu cache cleared!<br>";
}

if (function_exists('printflow_staff_dashboard_cache_flush_all')) {
    printflow_staff_dashboard_cache_flush_all();
    echo "Staff dashboard JSON cache cleared!<br>";
}

echo "<br>Cache clearing complete. <a href='/staff/pos/dashboard.php?nocache=1'>Open Counter Staff Dashboard</a>";
?>
