<?php
/**
 * Optional realtime/signaling configuration.
 *
 * Realtime is deliberately off by default. Core HTTP workflows must never
 * depend on the external Socket.IO provider being reachable.
 */

require_once __DIR__ . '/env.php';
printflow_load_project_env();

if (!function_exists('printflow_realtime_enabled')) {
    function printflow_realtime_enabled(): bool {
        return printflow_env_bool('PRINTFLOW_REALTIME_ENABLED', false);
    }
}

if (!function_exists('printflow_realtime_url')) {
    function printflow_realtime_url(): string {
        $url = trim((string)(printflow_env('PRINTFLOW_REALTIME_URL') ?: ''));
        if ($url === '' || !preg_match('#^https?://#i', $url)) {
            return '';
        }
        return rtrim($url, '/');
    }
}

if (!function_exists('printflow_realtime_available')) {
    function printflow_realtime_available(): bool {
        return printflow_realtime_enabled() && printflow_realtime_url() !== '';
    }
}
