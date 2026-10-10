<?php
/**
 * verify_otp.php
 * Validates User/Customer OTP
 */

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('verify_email.php?error=Invalid request method');
}

if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
    redirect('verify_email.php?error=' . urlencode('Invalid security token. Please refresh the page.'));
}

$pending_email = trim((string)($_SESSION['otp_pending_email'] ?? ''));
$submitted_email = trim((string)($_POST['email'] ?? ''));
$email = $pending_email;
$otp = trim((string)($_POST['otp'] ?? ''));
$type = $_SESSION['otp_user_type'] ?? 'Customer';

if ($pending_email === '' || ($submitted_email !== '' && strcasecmp($submitted_email, $pending_email) !== 0)) {
    redirect('verify_email.php?error=' . urlencode('Verification session expired. Please register again.'));
}

if ($otp === '') {
    redirect('verify_email.php?error=Please enter the code');
}

$attempt_key = hash('sha256', $type . '|' . strtolower($pending_email));
if (($_SESSION['otp_verify_attempt_key'] ?? '') !== $attempt_key) {
    $_SESSION['otp_verify_attempt_key'] = $attempt_key;
    $_SESSION['otp_verify_failed_attempts'] = 0;
    $_SESSION['otp_verify_blocked_until'] = 0;
}

$blocked_until = (int)($_SESSION['otp_verify_blocked_until'] ?? 0);
if ($blocked_until > time()) {
    redirect('verify_email.php?error=' . urlencode('Too many incorrect attempts. Please wait 15 minutes or request a new code.'));
}

$table = ($type === 'User') ? 'users' : 'customers';
$id_col = ($type === 'User') ? 'user_id' : 'customer_id';

// 2. Query database
$sql = "SELECT $id_col, otp_code, otp_expiry FROM $table WHERE email = ?";
$result = db_query($sql, 's', [$email]);

if (empty($result)) {
    redirect('verify_email.php?error=Account not found');
}

$record = $result[0];
$stored_otp = isset($record['otp_code']) ? (string)$record['otp_code'] : '';
$expiry_ts = !empty($record['otp_expiry']) ? strtotime($record['otp_expiry']) : 0;
$now_ts = time();

// Expired first
if ($expiry_ts <= $now_ts) {
    redirect('verify_email.php?error=' . urlencode('Verification code expired. Please request a new code.'));
}

// Wrong code
if ($stored_otp !== (string)$otp) {
    $failed_attempts = (int)($_SESSION['otp_verify_failed_attempts'] ?? 0) + 1;
    $_SESSION['otp_verify_failed_attempts'] = $failed_attempts;
    if ($failed_attempts >= 5) {
        $_SESSION['otp_verify_blocked_until'] = time() + (15 * 60);
        redirect('verify_email.php?error=' . urlencode('Too many incorrect attempts. Please wait 15 minutes or request a new code.'));
    }
    redirect('verify_email.php?error=' . urlencode('Incorrect OTP. Please try again.'));
}

// If valid
if ($stored_otp === (string)$otp) {
    $update_sql = "UPDATE $table SET email_verified = 1, otp_code = NULL, otp_expiry = NULL WHERE email = ?";
    db_execute($update_sql, 's', [$email]);
    unset($_SESSION['otp_verify_attempt_key'], $_SESSION['otp_verify_failed_attempts'], $_SESSION['otp_verify_blocked_until']);

    $_SESSION['otp_success'] = "Email verified successfully. You can now log in.";
    redirect(AUTH_REDIRECT_BASE . '/?auth_modal=login&success=' . urlencode('Email verified. Please log in.'));
}
