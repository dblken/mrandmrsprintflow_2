<?php
/**
 * verify_otp.php
 * Validates User/Customer OTP
 */

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

const OTP_VERIFY_MAX_ATTEMPTS = 5;
const OTP_VERIFY_LOCKOUT_SECONDS = 15 * 60;
const OTP_VERIFY_RATE_ACTION = 'email_otp_verify';

function redirect_otp_lockout(int $remainingSeconds): never {
    $remainingSeconds = max(1, $remainingSeconds);
    redirect(
        'verify_email.php?error=' . urlencode('Too many incorrect attempts. OTP verification is blocked for 15 minutes.')
        . '&otp_locked=1&remaining_seconds=' . $remainingSeconds
    );
}

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
$active_lock = RateLimiter::getActiveLockout(OTP_VERIFY_RATE_ACTION, $attempt_key);
if ($active_lock !== null) {
    redirect_otp_lockout((int)($active_lock['remaining_seconds'] ?? OTP_VERIFY_LOCKOUT_SECONDS));
}

$table = ($type === 'User') ? 'users' : 'customers';
$id_col = ($type === 'User') ? 'user_id' : 'customer_id';
$pending_customer_id = $type === 'Customer'
    ? (int)($_SESSION['otp_pending_customer_id'] ?? 0)
    : 0;

// 2. Query database
if ($type === 'Customer' && $pending_customer_id > 0) {
    $sql = "SELECT $id_col, otp_code, otp_expiry
            FROM customers
            WHERE customer_id = ? AND LOWER(TRIM(email)) = LOWER(?)
            LIMIT 1";
    $result = db_query($sql, 'is', [$pending_customer_id, $email]);
} else {
    // Compatibility for verification sessions created before customer IDs were
    // stored in the session. Prefer the row that most recently received an OTP.
    $sql = "SELECT $id_col, otp_code, otp_expiry
            FROM $table
            WHERE LOWER(TRIM(email)) = LOWER(?)
            ORDER BY otp_last_sent DESC, $id_col DESC
            LIMIT 1";
    $result = db_query($sql, 's', [$email]);
}

if (empty($result)) {
    redirect('verify_email.php?error=Account not found');
}

$record = $result[0];
$record_id = (int)($record[$id_col] ?? 0);
$stored_otp = isset($record['otp_code']) ? (string)$record['otp_code'] : '';
$expiry_ts = !empty($record['otp_expiry']) ? strtotime($record['otp_expiry']) : 0;
$now_ts = time();

// Expired first
if ($expiry_ts <= $now_ts) {
    redirect('verify_email.php?error=' . urlencode('Verification code expired. Please request a new code.'));
}

// Accept both previously issued hashes and schema-compatible six-digit values.
$otp_matches = password_verify((string)$otp, $stored_otp);
if (!$otp_matches && preg_match('/^\d{6}$/', $stored_otp)) {
    $otp_matches = hash_equals($stored_otp, (string)$otp);
}

// Wrong code
if (!$otp_matches) {
    $failure = RateLimiter::recordFixedFailure(
        OTP_VERIFY_RATE_ACTION,
        $attempt_key,
        OTP_VERIFY_MAX_ATTEMPTS,
        OTP_VERIFY_LOCKOUT_SECONDS,
        OTP_VERIFY_LOCKOUT_SECONDS
    );
    if (!empty($failure['locked'])) {
        redirect_otp_lockout((int)($failure['remaining_seconds'] ?? OTP_VERIFY_LOCKOUT_SECONDS));
    }
    redirect('verify_email.php?error=' . urlencode('Incorrect OTP. Please try again.'));
}

// If valid
if ($otp_matches) {
    RateLimiter::clear(OTP_VERIFY_RATE_ACTION, $attempt_key);
    $verification_updated = false;
    $pending_update = $_SESSION['pending_registration_update'] ?? null;

    // A repeated local registration carries its new profile data in the session
    // until the email is actually verified. Re-check the account protections here
    // before applying it, because orders or an account-state change may occur
    // while the OTP is pending.
    if ($type === 'Customer' && is_array($pending_update)) {
        $pending_update_customer_id = (int)($pending_update['customer_id'] ?? 0);
        $pending_update_email = trim((string)($pending_update['email'] ?? ''));
        $service_order_guard = '';
        if (!empty(db_query("SHOW TABLES LIKE 'service_orders'"))) {
            $service_order_guard = "
                AND NOT EXISTS (
                    SELECT 1 FROM service_orders so WHERE so.customer_id = c.customer_id
                )";
        }

        $safe_pending = false;
        if ($pending_update_customer_id > 0 && $pending_update_customer_id === $record_id
            && $pending_update_email !== ''
            && strcasecmp($pending_update_email, $email) === 0) {
            $safe_pending = !empty(db_query(
                "SELECT c.customer_id
                 FROM customers c
                 WHERE c.customer_id = ?
                   AND LOWER(TRIM(c.email)) = LOWER(?)
                   AND COALESCE(c.email_verified, 0) = 0
                   AND LOWER(TRIM(COALESCE(c.auth_provider, ''))) <> 'google'
                   AND LOWER(TRIM(COALESCE(c.status, ''))) NOT IN ('activated', 'active')
                   AND NOT EXISTS (SELECT 1 FROM orders o WHERE o.customer_id = c.customer_id)
                   AND NOT EXISTS (SELECT 1 FROM job_orders jo WHERE jo.customer_id = c.customer_id)" . $service_order_guard . "
                 LIMIT 1",
                'is',
                [$pending_update_customer_id, $email]
            ));
        }

        if ($safe_pending) {
            $profile_update_sql = "UPDATE customers
                SET first_name = ?, middle_name = ?, last_name = ?, dob = ?, gender = ?,
                    contact_number = ?, password_hash = ?, is_profile_complete = ?,
                    terms_accepted_at = ?, terms_version = ?, region = ?, province = ?,
                    city = ?, barangay = ?, street_address = ?, auth_provider = 'local',
                    email_verified = 1, otp_code = NULL, otp_expiry = NULL
                WHERE customer_id = ? AND LOWER(TRIM(email)) = LOWER(?)
                  AND COALESCE(email_verified, 0) = 0";
            $profile_update = db_execute($profile_update_sql, 'sssssssisssssssis', [
                (string)($pending_update['first_name'] ?? ''),
                (string)($pending_update['middle_name'] ?? ''),
                (string)($pending_update['last_name'] ?? ''),
                (string)($pending_update['dob'] ?? ''),
                (string)($pending_update['gender'] ?? ''),
                (string)($pending_update['contact_number'] ?? ''),
                (string)($pending_update['password_hash'] ?? ''),
                (int)($pending_update['is_profile_complete'] ?? 0),
                $pending_update['terms_accepted_at'] ?? null,
                $pending_update['terms_version'] ?? null,
                (string)($pending_update['region'] ?? ''),
                (string)($pending_update['province'] ?? ''),
                (string)($pending_update['city'] ?? ''),
                (string)($pending_update['barangay'] ?? ''),
                (string)($pending_update['street_address'] ?? ''),
                $pending_update_customer_id,
                $email,
            ]);
            if ($profile_update === false) {
                redirect('verify_email.php?error=' . urlencode('Your registration could not be completed. Please request a new code and try again.'));
            }
            $verification_updated = true;
        }
    }

    if (!$verification_updated) {
        $update_sql = "UPDATE $table SET email_verified = 1, otp_code = NULL, otp_expiry = NULL WHERE $id_col = ?";
        db_execute($update_sql, 'i', [$record_id]);
    }
    unset($_SESSION['pending_registration_update'], $_SESSION['otp_pending_customer_id']);
    unset($_SESSION['otp_verify_attempt_key'], $_SESSION['otp_verify_failed_attempts'], $_SESSION['otp_verify_blocked_until']);

    $_SESSION['otp_success'] = "Email verified successfully. You can now log in.";
    redirect(AUTH_REDIRECT_BASE . '/?auth_modal=login&success=' . urlencode('Email verified. Please log in.'));
}
