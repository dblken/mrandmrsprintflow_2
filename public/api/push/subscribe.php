<?php
/**
 * push/subscribe.php — Save or refresh a Web Push subscription.
 * POST JSON: { endpoint, keys: { p256dh, auth }, action: 'subscribe'|'unsubscribe' }
 */
require_once __DIR__ . '/../../../includes/json_endpoint.php';
printflow_json_endpoint_bootstrap();
require_once __DIR__ . '/../../../includes/auth.php';
require_once __DIR__ . '/../../../includes/functions.php';
require_once __DIR__ . '/../../../includes/push_debug_helper.php';

if (!is_logged_in()) {
    printflow_push_debug_log('subscribe_rejected_unauthenticated', []);
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthenticated']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    printflow_push_debug_log('subscribe_rejected_method', ['method' => (string)($_SERVER['REQUEST_METHOD'] ?? '')]);
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

$subscriptionTable = db_query("SHOW TABLES LIKE 'push_subscriptions'");
if (empty($subscriptionTable)) {
    http_response_code(503);
    echo json_encode(['success' => false, 'error' => 'Push subscription storage is unavailable.']);
    exit;
}

$raw = file_get_contents('php://input');
$data = json_decode($raw, true);

if (empty($data)) {
    printflow_push_debug_log('subscribe_rejected_invalid_json', []);
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid JSON body']);
    exit;
}

$action   = $data['action'] ?? 'subscribe';
$user_id  = (int) get_user_id();
$user_type = get_user_type() ?? 'Customer';

// ── Unsubscribe ──────────────────────────────────────────────────────────────
if ($action === 'unsubscribe') {
    $endpoint = trim($data['endpoint'] ?? '');
    if ($endpoint) {
        db_execute('DELETE FROM push_subscriptions WHERE endpoint = ? AND user_id = ?', 'si', [$endpoint, $user_id]);
    }
    printflow_push_debug_log('unsubscribe_saved', ['has_endpoint' => $endpoint !== ''], $user_id, (string)$user_type, $endpoint);
    echo json_encode(['success' => true]);
    exit;
}

// ── Subscribe ────────────────────────────────────────────────────────────────
$endpoint = trim($data['endpoint'] ?? '');
$p256dh   = trim($data['keys']['p256dh'] ?? '');
$auth     = trim($data['keys']['auth']   ?? '');

if (!$endpoint || !$p256dh || !$auth) {
    printflow_push_debug_log('subscribe_rejected_missing_fields', [
        'has_endpoint' => $endpoint !== '',
        'has_p256dh' => $p256dh !== '',
        'has_auth' => $auth !== '',
    ], $user_id, (string)$user_type, $endpoint);
    http_response_code(422);
    echo json_encode(['success' => false, 'error' => 'Missing subscription fields']);
    exit;
}

// Upsert: insert or update if endpoint already exists
$existing = db_query(
    'SELECT id, user_id FROM push_subscriptions WHERE endpoint = ?',
    's', [$endpoint]
);

if (!empty($existing)) {
    // Update keys and re-associate with this user (subscription may have been refreshed)
    $ok = db_execute(
        'UPDATE push_subscriptions SET user_id = ?, user_type = ?, p256dh = ?, auth_key = ?, user_agent = ?, last_used = NOW() WHERE endpoint = ?',
        'isssss',
        [$user_id, $user_type, $p256dh, $auth, substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255), $endpoint]
    );
} else {
    $ok = db_execute(
        'INSERT INTO push_subscriptions (user_id, user_type, endpoint, p256dh, auth_key, user_agent) VALUES (?,?,?,?,?,?)',
        'isssss',
        [$user_id, $user_type, $endpoint, $p256dh, $auth, substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255)]
    );
}

if ($ok === false) {
    printflow_push_debug_log('subscribe_save_failed', ['existing' => !empty($existing)], $user_id, (string)$user_type, $endpoint);
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Could not save this device for notifications.']);
    exit;
}

printflow_push_debug_log('subscribe_saved', [
    'existing' => !empty($existing),
    'user_agent_present' => !empty($_SERVER['HTTP_USER_AGENT']),
    'client_fingerprint' => substr((string)($data['client_fingerprint'] ?? ''), 0, 255),
], $user_id, (string)$user_type, $endpoint);

echo json_encode(['success' => true, 'message' => 'Subscription saved.']);
