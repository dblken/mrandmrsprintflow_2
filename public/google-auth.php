<?php
/**
 * Google OAuth: redirect to Google for login, then callback to find/create customer and log in.
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/google-oauth-config.php';
require_once __DIR__ . '/../includes/auth.php';

$base_url = defined('BASE_URL') ? BASE_URL : '/printflow';
$redirect_uri = $base_url . '/public/google-auth.php';
$client_id = defined('GOOGLE_CLIENT_ID') ? GOOGLE_CLIENT_ID : '';
$client_secret = defined('GOOGLE_CLIENT_SECRET') ? GOOGLE_CLIENT_SECRET : '';

$request_host = strtolower((string)($_SERVER['HTTP_HOST'] ?? ''));
$request_host = preg_replace('/:\d+$/', '', $request_host);
$is_production_host = $request_host === 'mrandmrsprintflow.com';

// Keep provider diagnostics useful without exposing credentials, tokens, or session data.
$oauth_log = static function (string $event, array $context = []): void {
    $safe_context = [];
    foreach ($context as $key => $value) {
        if (in_array($key, ['code', 'access_token', 'refresh_token', 'client_secret', 'id_token', 'session_id'], true)) {
            continue;
        }
        $safe_context[$key] = is_scalar($value) ? (string)$value : gettype($value);
    }
    error_log('[google-oauth] ' . $event . ($safe_context ? ' ' . json_encode($safe_context) : ''));
};

$oauth_fail = static function (string $category, string $message) use ($base_url, $oauth_log): void {
    $oauth_log('failure', ['category' => $category]);
    header('Location: ' . $base_url . '/?auth_modal=login&error=' . urlencode($message));
    exit;
};

if (empty($client_id) || empty($client_secret)) {
    $oauth_fail('configuration', 'Google sign-in is not configured.');
}

if (is_logged_in()) {
    $ut = get_user_type();
    if ($ut === 'Admin') header('Location: ' . $base_url . '/admin/dashboard.php');
    elseif ($ut === 'Staff') header('Location: ' . $base_url . '/staff/dashboard.php');
    else header('Location: ' . $base_url . '/customer/services.php');
    exit;
}

// Use the exact production callback registered with Google. Keep local development dynamic.
$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host = $_SERVER['HTTP_HOST'] ?? 'localhost';
$redirect_uri_full = $is_production_host
    ? 'https://mrandmrsprintflow.com/public/google-auth.php'
    : $scheme . '://' . $host . $redirect_uri;

$oauth_log('callback_handler_reached', ['host' => $request_host, 'callback' => $redirect_uri_full]);

$code = $_GET['code'] ?? '';
$error_param = $_GET['error'] ?? '';

if ($error_param) {
    $oauth_fail($error_param === 'access_denied' ? 'user_cancelled' : 'provider_error', $error_param === 'access_denied'
        ? 'Google sign-in was cancelled.'
        : 'Google could not complete sign-in. Please try again.');
}

// Step 1: No code -> redirect to Google
if ($code === '') {
    $oauth_log('oauth_started');
    $state = bin2hex(random_bytes(16));
    // Store state in a SameSite=Lax cookie so it survives the redirect back from Google.
    // The main session cookie uses SameSite=Strict and may not be sent on OAuth callback.
    $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
    setcookie('oauth_state', $state, [
        'expires' => time() + 600,
        'path' => '/',
        'domain' => '',
        'secure' => $secure,
        'httponly' => true,
        'samesite' => 'Lax'
    ]);
    if (session_status() === PHP_SESSION_NONE) session_start();
    $_SESSION['google_oauth_state'] = $state;
    $url = 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query([
        'client_id' => $client_id,
        'redirect_uri' => $redirect_uri_full,
        'response_type' => 'code',
        'scope' => 'openid email profile',
        'state' => $state,
        'access_type' => 'offline',
        'prompt' => 'consent'
    ]);
    header('Location: ' . $url);
    exit;
}

// Step 2: Exchange code for tokens
$state_sent = $_GET['state'] ?? '';
$state_from_cookie = $_COOKIE['oauth_state'] ?? '';
$state_valid = false;
if ($state_sent !== '') {
    if (session_status() === PHP_SESSION_NONE) session_start();
    if (!empty($_SESSION['google_oauth_state']) && hash_equals($_SESSION['google_oauth_state'], $state_sent)) {
        $state_valid = true;
    } elseif ($state_from_cookie !== '' && hash_equals($state_from_cookie, $state_sent)) {
        $state_valid = true;
    }
}
setcookie('oauth_state', '', ['expires' => time() - 3600, 'path' => '/']);
if (!$state_valid) {
    if (session_status() === PHP_SESSION_ACTIVE) unset($_SESSION['google_oauth_state']);
    $oauth_fail('state_mismatch', 'Google sign-in could not be verified. Please try again.');
}
$oauth_log('state_validated');
if (session_status() === PHP_SESSION_ACTIVE) unset($_SESSION['google_oauth_state']);

$token_url = 'https://oauth2.googleapis.com/token';
$token_body = [
    'code' => $code,
    'client_id' => $client_id,
    'client_secret' => $client_secret,
    'redirect_uri' => $redirect_uri_full,
    'grant_type' => 'authorization_code'
];
$ctx = stream_context_create([
    'http' => [
        'method' => 'POST',
        'header' => "Content-Type: application/x-www-form-urlencoded\r\n",
        'content' => http_build_query($token_body),
        'ignore_errors' => true,
        'timeout' => 15,
    ]
]);
$token_response = @file_get_contents($token_url, false, $ctx);
if ($token_response === false) {
    $oauth_fail('token_exchange_connection', 'Google sign-in could not connect. Please try again.');
}
$token_data = json_decode($token_response, true);
if (!is_array($token_data) || empty($token_data['access_token'])) {
    $oauth_fail('token_exchange', 'Google sign-in could not be completed. Please try again.');
}
$oauth_log('token_exchange_succeeded');

// Get user info using an Authorization header so the access token is not placed in the URL.
$userinfo_context = stream_context_create([
    'http' => [
        'method' => 'GET',
        'header' => "Authorization: Bearer " . $token_data['access_token'] . "\r\n",
        'ignore_errors' => true,
        'timeout' => 15,
    ]
]);
$user_response = @file_get_contents('https://www.googleapis.com/oauth2/v2/userinfo', false, $userinfo_context);
if ($user_response === false) {
    $oauth_fail('profile_retrieval_connection', 'Google profile information could not be retrieved.');
}
$user = json_decode($user_response, true);
if (!is_array($user)) {
    $oauth_fail('profile_retrieval', 'Google profile information was invalid.');
}
if (array_key_exists('verified_email', $user) && $user['verified_email'] !== true) {
    $oauth_fail('unverified_email', 'Google returned an unverified email address.');
}
if (empty($user['email'])) {
    $oauth_fail('missing_email', 'Google did not provide an email address.');
}
$oauth_log('profile_retrieved');

$email = $user['email'];
$first_name = $user['given_name'] ?? '';
$last_name = $user['family_name'] ?? '';

try {
    $result = login_customer_by_google($email, $first_name, $last_name);
} catch (Throwable $e) {
    error_log('[google-oauth] customer_lookup_or_creation_exception ' . get_class($e));
    $oauth_fail('database_or_account', 'Google sign-in could not complete. Please try again.');
}
if ($result['success']) {
    $redirect_url = $scheme . '://' . $host . $result['redirect'];
    $oauth_log('customer_session_established', ['destination' => $result['redirect']]);
    header('Location: ' . $redirect_url);
    exit;
}
$oauth_fail('account_login_or_creation', (string)($result['message'] ?? 'Google sign-in could not complete. Please try again.'));
