<?php
/**
 * Customer registration endpoint for the public/customer side.
 *
 * Public registration must never create staff/admin/manager users. Staff
 * accounts are created from the admin workflow only.
 */

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

function pf_terms_agreement_accepted($value): bool {
    return in_array((string)$value, ['1', 'on', 'true', 'yes'], true);
}
function pf_register_redirect_error(string $message): void {
    redirect(AUTH_REDIRECT_BASE . '/?auth_modal=register&error=' . urlencode($message));
}

function pf_remove_legacy_public_staff_registration(string $email): void {
    $legacy = db_query(
        "SELECT user_id
         FROM users
         WHERE LOWER(TRIM(email)) = LOWER(?)
           AND role = 'Staff'
           AND status = 'Pending'
           AND COALESCE(email_verified, 0) = 0
           AND last_name = 'Account'
         LIMIT 1",
        's',
        [$email]
    );

    if (!empty($legacy[0]['user_id'])) {
        db_execute("DELETE FROM users WHERE user_id = ?", 'i', [(int)$legacy[0]['user_id']]);
    }
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect(AUTH_REDIRECT_BASE . '/?auth_modal=register');
}

if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
    pf_register_redirect_error('Invalid request. Please try again.');
}

unset(
    $_SESSION['otp_pending_email'],
    $_SESSION['otp_user_type'],
    $_SESSION['otp_error'],
    $_SESSION['otp_success'],
    $_SESSION['otp_resend_attempts']
);

$reg_type = sanitize($_POST['reg_type'] ?? 'direct');
$identifier_type = sanitize($_POST['identifier_type'] ?? 'email');
$identifier = sanitize($_POST['identifier'] ?? '');
$password = $_POST['password'] ?? '';
$confirm_password = $_POST['confirm_password'] ?? '';
$first_name = sanitize($_POST['first_name'] ?? '');
$middle_name = sanitize($_POST['middle_name'] ?? '');
$last_name = sanitize($_POST['last_name'] ?? '');
$contact_number = sanitize($_POST['contact_number'] ?? '');
$dob = sanitize($_POST['dob'] ?? '');
$gender = sanitize($_POST['gender'] ?? '');
$province = sanitize($_POST['province'] ?? '');
$city = sanitize($_POST['city'] ?? '');
$barangay = sanitize($_POST['barangay'] ?? '');
$street_address = sanitize($_POST['street_address'] ?? '');

if (!pf_terms_agreement_accepted($_POST['terms_agreement'] ?? '')) {
    pf_register_redirect_error('Please agree to the Terms of Service and Privacy Policy before creating your account.');
}

if ($reg_type !== 'direct') {
    pf_register_redirect_error('Public registration is for customer accounts only.');
}

if (!in_array($identifier_type, ['email', 'phone'], true)) {
    pf_register_redirect_error('Invalid registration type.');
}

if ($identifier === '' || $password === '' || $confirm_password === '') {
    pf_register_redirect_error('Please fill in all fields.');
}

$name_regex = '/^[A-Za-z]+(?: [A-Za-z]+){0,2}$/';
$contact_clean = preg_replace('/[\s\-\(\)]/', '', $contact_number);
if ($first_name === '' || !preg_match($name_regex, $first_name)) {
    pf_register_redirect_error('Please enter a valid first name.');
}
if ($middle_name !== '' && !preg_match($name_regex, $middle_name)) {
    pf_register_redirect_error('Please enter a valid middle name or leave it blank.');
}
if ($last_name === '' || !preg_match($name_regex, $last_name)) {
    pf_register_redirect_error('Please enter a valid last name.');
}
if (!preg_match('/^(\+63|0)9\d{9}$/', $contact_clean)) {
    pf_register_redirect_error('Please enter a valid Philippine mobile number.');
}
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dob) || strtotime($dob) === false || strtotime($dob) > strtotime('-13 years')) {
    pf_register_redirect_error('Please enter a valid birthday.');
}
if (!in_array($gender, ['Male', 'Female', 'Other'], true)) {
    pf_register_redirect_error('Please select a valid gender.');
}
foreach (['Province' => $province, 'City / Municipality' => $city, 'Barangay' => $barangay, 'Street address' => $street_address] as $label => $value) {
    if (trim((string)$value) === '') {
        pf_register_redirect_error($label . ' is required.');
    }
}
if (strlen($street_address) > 255) {
    pf_register_redirect_error('Street address must not exceed 255 characters.');
}
if (contact_phone_in_use_across_accounts($contact_clean)) {
    pf_register_redirect_error('This phone number is already in use on another account. Please sign in or use a different number.');
}

if ($identifier_type === 'email') {
    $identifier = trim($identifier);
    if (
        strlen($identifier) > 254 ||
        strpos($identifier, ' ') !== false ||
        !filter_var($identifier, FILTER_VALIDATE_EMAIL) ||
        !preg_match('/^[^\s@]+@[^\s@]+\.[a-zA-Z]{2,}$/', $identifier)
    ) {
        pf_register_redirect_error('Please enter a valid email address.');
    }
} else {
    $phone = preg_replace('/[\s\-\(\)]/', '', $identifier);
    if (!preg_match('/^(\+63|0)9\d{9}$/', $phone)) {
        pf_register_redirect_error('Please enter a valid Philippine mobile number.');
    }
    $identifier = $phone;
}

$pw_errors = [];
if (strlen($password) < 8) $pw_errors[] = 'at least 8 characters';
if (strlen($password) > 64) $pw_errors[] = 'at most 64 characters';
if (!preg_match('/[A-Z]/', $password)) $pw_errors[] = 'an uppercase letter';
if (!preg_match('/[a-z]/', $password)) $pw_errors[] = 'a lowercase letter';
if (!preg_match('/[0-9]/', $password)) $pw_errors[] = 'a number';
if (!preg_match('/[^A-Za-z0-9]/', $password)) $pw_errors[] = 'a special character';
if (strpos($password, ' ') !== false) $pw_errors[] = 'no spaces';
if (!empty($pw_errors)) {
    pf_register_redirect_error('Password must contain: ' . implode(', ', $pw_errors) . '.');
}

if ($password !== $confirm_password) {
    pf_register_redirect_error('Passwords do not match.');
}

if ($identifier_type === 'email') {
    pf_remove_legacy_public_staff_registration($identifier);
}

$profile_data = [
    'first_name' => $first_name,
    'middle_name' => $middle_name,
    'last_name' => $last_name,
    'contact_number' => $contact_clean,
    'dob' => $dob,
    'gender' => $gender,
    'region' => '',
    'province' => $province,
    'city' => $city,
    'barangay' => $barangay,
    'street_address' => $street_address,
];
$result = register_customer_direct($identifier_type, $identifier, $password, date('Y-m-d H:i:s'), PRINTFLOW_TERMS_VERSION, $profile_data);
if (!$result['success']) {
    pf_register_redirect_error($result['message'] ?? 'Registration failed. Please try again.');
}

$_SESSION['otp_pending_email'] = ($identifier_type === 'email') ? $identifier : ($identifier . '@phone.local');
$_SESSION['otp_user_type'] = 'Customer';
$_SESSION['otp_resend_attempts'] = 0;

redirect(AUTH_REDIRECT_BASE . '/public/verify_email.php');
