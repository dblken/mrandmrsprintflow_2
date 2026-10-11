<?php
/**
 * Send OTP Code API
 * POST: { type: 'email'|'phone', identifier: '...', purpose: 'register'|'reset' }
 * Returns JSON: { success, message }
 */
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
    exit;
}

require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';

$input = json_decode(file_get_contents('php://input'), true);
if (!$input) $input = $_POST;

$type       = $input['type'] ?? '';
$identifier = trim($input['identifier'] ?? '');
$purpose    = $input['purpose'] ?? 'register';

// Validate type
if (!in_array($type, ['email', 'phone'])) {
    echo json_encode(['success' => false, 'message' => 'Invalid type. Use "email" or "phone".']);
    exit;
}

// Validate identifier format
if ($type === 'email') {
    if (!filter_var($identifier, FILTER_VALIDATE_EMAIL)) {
        echo json_encode(['success' => false, 'message' => 'Please enter a valid email address.']);
        exit;
    }
} else {
    // PH phone: starts with 09 or +639, 10-13 digits
    $phone_clean = preg_replace('/[\s\-\(\)]/', '', $identifier);
    if (!preg_match('/^(\+63|0)9\d{9}$/', $phone_clean)) {
        echo json_encode(['success' => false, 'message' => 'Please enter a valid PH phone number (e.g. 09171234567).']);
        exit;
    }
    $identifier = $phone_clean;
}

// Check if already registered (for registration purpose) — customers OR staff/users
if ($purpose === 'register') {
    if ($type === 'email') {
        $normalized_email = strtolower(trim($identifier));
        $staff_match = db_query(
            'SELECT user_id FROM users WHERE LOWER(TRIM(email)) = ? LIMIT 1',
            's',
            [$normalized_email]
        );
        $service_order_guard = !empty(db_query("SHOW TABLES LIKE 'service_orders'"))
            ? ' AND NOT EXISTS (SELECT 1 FROM service_orders so WHERE so.customer_id = c.customer_id)'
            : '';
        $customer_matches = db_query(
            "SELECT c.email_verified, c.auth_provider, c.created_at, c.status,
                    (
                        COALESCE(c.email_verified, 0) = 0
                        AND LOWER(TRIM(COALESCE(c.auth_provider, ''))) IN ('', 'local', 'password', 'email')
                        AND LOWER(TRIM(COALESCE(c.status, ''))) NOT IN ('', 'activated', 'active')
                        AND c.created_at IS NOT NULL
                        AND c.created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
                        AND NOT EXISTS (SELECT 1 FROM orders o WHERE o.customer_id = c.customer_id)
                        AND NOT EXISTS (SELECT 1 FROM job_orders jo WHERE jo.customer_id = c.customer_id)" . $service_order_guard . '
                    ) AS pending_reusable
             FROM customers c
             WHERE LOWER(TRIM(c.email)) = ?
             ORDER BY c.customer_id ASC',
            's',
            [$normalized_email]
        );
        $blocked_customer = false;
        foreach ($customer_matches as $customer_match) {
            $is_verified = (int)($customer_match['email_verified'] ?? 0) === 1;
            $is_local_pending = !$is_verified
                && printflow_is_customer_local_auth_provider($customer_match['auth_provider'] ?? '')
                && (int)($customer_match['pending_reusable'] ?? 0) === 1;
            if (!$is_local_pending) {
                $blocked_customer = true;
                break;
            }
        }
        if (!empty($staff_match) || $blocked_customer) {
            echo json_encode(['success' => false, 'message' => 'This email is already in use. Please sign in instead.']);
            exit;
        }
    } elseif (contact_phone_in_use_across_accounts($identifier)) {
        echo json_encode(['success' => false, 'message' => 'This phone number is already in use. Please sign in instead.']);
        exit;
    }
}

// Rate limit: max 3 codes per identifier per hour
$recent = db_query(
    "SELECT COUNT(*) as cnt FROM verification_codes WHERE identifier = ? AND purpose = ? AND created_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)",
    'ss', [$identifier, $purpose]
);
if (!empty($recent) && ($recent[0]['cnt'] ?? 0) >= 3) {
    echo json_encode(['success' => false, 'message' => 'Too many attempts. Please try again in 1 hour.']);
    exit;
}

// Generate 6-digit OTP
$code = str_pad(random_int(0, 999999), 6, '0', STR_PAD_LEFT);
$expires_at = date('Y-m-d H:i:s', time() + 600); // 10 minutes

// Store in DB
$result = db_execute(
    "INSERT INTO verification_codes (identifier, type, code, purpose, expires_at) VALUES (?, ?, ?, ?, ?)",
    'sssss', [$identifier, $type, $code, $purpose, $expires_at]
);

if (!$result) {
    echo json_encode(['success' => false, 'message' => 'Failed to generate code. Please try again.']);
    exit;
}

// Send OTP
if ($type === 'email') {
    // Try PHP mail() — on localhost it usually fails, so log it
    $subject = "PrintFlow - Your verification code: $code";
    $body = "Your PrintFlow verification code is: $code\n\nThis code expires in 10 minutes.\n\nIf you did not request this, please ignore this email.";
    $headers = "From: noreply@printflow.com\r\nContent-Type: text/plain; charset=UTF-8\r\n";
    
    $sent = @mail($identifier, $subject, $body, $headers);
    if (!$sent) {
        // Fallback: log to PHP error log
        error_log("[PrintFlow OTP] Email to $identifier: CODE = $code (mail() failed, showing in log)");
    }
} else {
    // SMS: Log to error_log (no SMS API configured)
    error_log("[PrintFlow OTP] SMS to $identifier: CODE = $code");
}

// DEVELOPMENT/LOCALHOST: also return the code for testing convenience
// REMOVE THIS IN PRODUCTION!
$is_localhost = in_array($_SERVER['SERVER_NAME'] ?? '', ['localhost', '127.0.0.1']);

$response = [
    'success' => true,
    'message' => $type === 'email'
        ? "Verification code sent to your email."
        : "Verification code sent to your phone.",
];

if ($is_localhost) {
    $response['dev_code'] = $code;  // Only in dev/localhost
    $response['dev_note'] = 'This code is shown only on localhost for testing.';
}

echo json_encode($response);
