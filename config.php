<?php
/**
 * PrintFlow Configuration
 * This file handles environment-specific settings
 */

// Load local .env values without overriding environment variables supplied by
// Apache, the container runtime, Railway, or the hosting control panel.
require_once __DIR__ . '/includes/env.php';
printflow_import_dotenv(__DIR__ . '/.env');

// Detect environment
$is_production = (
    isset($_SERVER['HTTP_HOST']) && 
    (strpos($_SERVER['HTTP_HOST'], 'mrandmrsprintflow.com') !== false ||
     strpos($_SERVER['HTTP_HOST'], 'hostinger') !== false ||
     strpos($_SERVER['HTTP_HOST'], 'printflow.com') !== false)
);

// Set base path based on environment
if ($is_production) {
    // Production: domain root - force empty values
    // Clear any .env overrides first to avoid inheritance issues
    putenv('BASE_PATH=');
    putenv('BASE_URL=');
    $_ENV['BASE_PATH'] = '';
    $_ENV['BASE_URL'] = '';
    $_SERVER['BASE_PATH'] = '';
    $_SERVER['BASE_URL'] = '';
    
    if (!defined('BASE_PATH')) define('BASE_PATH', '');
    if (!defined('BASE_URL')) define('BASE_URL', '');
} else {
    // Local development: /printflow subdirectory
    if (!defined('BASE_PATH')) define('BASE_PATH', '/printflow');
    if (!defined('BASE_URL')) define('BASE_URL', '/printflow');
}

// Asset paths
define('ASSET_PATH', BASE_PATH . '/public/assets');
define('UPLOAD_PATH', BASE_PATH . '/uploads');

// Full URLs for absolute links
$protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host = $_SERVER['HTTP_HOST'] ?? 'localhost';
define('SITE_URL', $protocol . '://' . $host . BASE_PATH);

// Debug toggles (default off). Enable via environment variables in production as needed.
if (!defined('PRINTFLOW_DEBUG_SESSION_LOG')) {
    $raw = getenv('PRINTFLOW_DEBUG_SESSION_LOG');
    $raw = is_string($raw) ? strtolower(trim($raw)) : '';
    define('PRINTFLOW_DEBUG_SESSION_LOG', in_array($raw, ['1', 'true', 'yes', 'on'], true));
}
