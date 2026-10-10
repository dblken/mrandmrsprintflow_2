<?php
/**
 * resend_otp.php — JSON endpoint to resend OTP (rate-limited).
 * PrintFlow
 */

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

header('Content-Type: application/json');

function json_out(bool $success, string $message, array $extra = []): never {
    echo json_encode(array_merge(['success' => $success, 'message' => $message], $extra));
    exit;
}

function human_duration(int $seconds): string {
    $seconds = max(0, $seconds);
    $hours = intdiv($seconds, 3600);
    $minutes = intdiv($seconds % 3600, 60);
    $remaining = $seconds % 60;

    $parts = [];
    if ($hours > 0) {
        $parts[] = $hours . ' ' . ($hours === 1 ? 'hour' : 'hours');
    }
    if ($minutes > 0) {
        $parts[] = $minutes . ' ' . ($minutes === 1 ? 'minute' : 'minutes');
    }
    if ($hours === 0 && $remaining > 0) {
        $parts[] = $remaining . ' ' . ($remaining === 1 ? 'second' : 'seconds');
    }
    return empty($parts) ? '0 seconds' : implode(' ', $parts);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_out(false, 'Invalid request method.');
}

if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
    json_out(false, 'Invalid security token. Please refresh the page.');
}

$pending_email = trim((string)($_SESSION['otp_pending_email'] ?? ''));
$requested_email = trim((string)($_POST['email'] ?? ''));
if ($pending_email === '' || $requested_email === '' || strcasecmp($requested_email, $pending_email) !== 0) {
    json_out(false, 'This verification request is no longer valid. Please register again.');
}
$email = $pending_email;

$smtp_cfg = require __DIR__ . '/../includes/smtp_config.php';

/**
 * Progressive cooldown for resend attempts:
 * initial + first resend: 1m, second: 5m, third: 20m, fourth+: 1h
 */
function otp_resend_cooldown_seconds(int $attempts): int {
    if ($attempts <= 0) return 60;    // initial wait
    if ($attempts === 1) return 300;  // after 1st resend
    if ($attempts === 2) return 900;  // after 2nd resend
    if ($attempts === 3) return 1800; // after 3rd resend
    return 3600;
}

// Number of successful resend attempts in current session
if (!isset($_SESSION['otp_resend_attempts'])) {
    $_SESSION['otp_resend_attempts'] = 0;
}

$cooldown = otp_resend_cooldown_seconds((int) $_SESSION['otp_resend_attempts']);

$type  = $_SESSION['otp_user_type'] ?? 'Customer';
$table = ($type === 'User') ? 'users' : 'customers';
$id_col = ($type === 'User') ? 'user_id' : 'customer_id';

// Fetch record
$rows = db_query("SELECT $id_col, otp_last_sent, email_verified FROM $table WHERE email = ?", 's', [$email]);
if (empty($rows)) {
    json_out(false, 'Account not found.');
}
$customer = $rows[0];

if ($customer['email_verified']) {
    json_out(false, 'This email is already verified. Please log in.');
}

// Rate limit
if (!empty($customer['otp_last_sent'])) {
    $seconds_since = time() - strtotime($customer['otp_last_sent']);
    if ($seconds_since < $cooldown) {
        $wait = $cooldown - $seconds_since;
        json_out(
            false,
            'Please wait ' . human_duration($wait) . ' before requesting another code.',
            ['remaining_seconds' => $wait]
        );
    }
}

// Generate new OTP
$otp_code   = (string) random_int(100000, 999999);
$otp_expiry = date('Y-m-d H:i:s', time() + (($smtp_cfg['otp_expiry_minutes'] ?? 5) * 60));
$now        = date('Y-m-d H:i:s');

// Increment attempts for the NEXT resend window
$_SESSION['otp_resend_attempts'] = (int) $_SESSION['otp_resend_attempts'] + 1;
$next_cooldown = otp_resend_cooldown_seconds((int) $_SESSION['otp_resend_attempts']);

db_execute(
    "UPDATE $table SET otp_code = ?, otp_expiry = ?, otp_last_sent = ? WHERE email = ?",
    'ssss', [$otp_code, $otp_expiry, $now, $email]
);

// Send email (use same mailer path as registration)
require_once __DIR__ . '/../includes/otp_mailer.php';
$mail_result = send_otp_email($email, $otp_code);
if (is_array($mail_result) && !empty($mail_result['success'])) {
    $attempt_key = hash('sha256', $type . '|' . strtolower($email));
    $_SESSION['otp_verify_attempt_key'] = $attempt_key;
    $_SESSION['otp_verify_failed_attempts'] = 0;
    $_SESSION['otp_verify_blocked_until'] = 0;

    echo json_encode([
        'success' => true,
        'message' => 'A new verification code has been sent to your email.',
        'new_cooldown' => $next_cooldown
    ]);
    exit;
} else {
    $mail_message = is_array($mail_result) ? ($mail_result['message'] ?? '') : '';
    json_out(false, $mail_message ?: 'Failed to send email. Please try again later.');
}
