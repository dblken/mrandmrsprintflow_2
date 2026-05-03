<?php
/**
 * Google OAuth 2.0 config for Sign in with Google.
 *
 * 1. Go to https://console.cloud.google.com/apis/credentials
 * 2. Create OAuth 2.0 Client ID (Web application)
 * 3. Add Authorized redirect URI: https://YOUR_DOMAIN/google-auth/
 *    (for local: http://localhost/google-auth/)
 * 4. Set GOOGLE_CLIENT_ID and GOOGLE_CLIENT_SECRET in your .env file or server environment
 */

function pf_google_env_value(string $name): string
{
    $value = getenv($name);
    if ($value === false || $value === null || $value === '') {
        $value = $_ENV[$name] ?? $_SERVER[$name] ?? '';
    }

    $value = trim((string)$value);
    if ($value === '') {
        return '';
    }

    if (
        (str_starts_with($value, '"') && str_ends_with($value, '"'))
        || (str_starts_with($value, "'") && str_ends_with($value, "'"))
    ) {
        $value = substr($value, 1, -1);
    }

    return trim($value);
}

// Production Google OAuth credentials
$production_google_client_id = '146218015828-nq8mvkqbs5mnmscgtqchjhoeqd8pnm7l.apps.googleusercontent.com';
$is_google_production_host = isset($_SERVER['HTTP_HOST']) && strpos((string)$_SERVER['HTTP_HOST'], 'mrandmrsprintflow.com') !== false;

if (!defined('GOOGLE_CLIENT_ID')) {
    $client_id = pf_google_env_value('GOOGLE_CLIENT_ID');
    if ($client_id === '') {
        $client_id = pf_google_env_value('PRINTFLOW_GOOGLE_CLIENT_ID');
    }
    // Use production client ID if no env var set and we're on production domain
    if ($client_id === '' && $is_google_production_host) {
        $client_id = $production_google_client_id;
    }
    define('GOOGLE_CLIENT_ID', $client_id);
}
if (!defined('GOOGLE_CLIENT_SECRET')) {
    $client_secret = pf_google_env_value('GOOGLE_CLIENT_SECRET');
    if ($client_secret === '') {
        $client_secret = pf_google_env_value('PRINTFLOW_GOOGLE_CLIENT_SECRET');
    }
    define('GOOGLE_CLIENT_SECRET', $client_secret);
}
