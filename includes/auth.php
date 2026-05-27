<?php
/**
 * Authentication System
 * PrintFlow - Printing Shop PWA
 *
 * Role redirects: change REDIRECT_BASE if the app is not at /printflow (e.g. on production).
 */

// Load config for environment detection
if (file_exists(__DIR__ . '/../config.php')) {
    require_once __DIR__ . '/../config.php';
}

// Base path for redirects (no trailing slash). Change this if app lives at a different path.
if (!defined('AUTH_REDIRECT_BASE')) {
    define('AUTH_REDIRECT_BASE', defined('BASE_URL') ? BASE_URL : '/printflow');
}

require_once __DIR__ . '/session_manager.php';
require_once __DIR__ . '/rate_limiter.php';

// Start session with security hardening (fingerprint, timeout, secure cookies)
SessionManager::start();

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/staff_access.php';
require_once __DIR__ . '/ensure_account_creation_guard.php';
printflow_ensure_account_creation_guard();

// Try to include functions.php
$functions_path = __DIR__ . '/functions.php';
if (file_exists($functions_path)) {
    require_once $functions_path;
}

// Fallback: Define log_activity if it still doesn't exist to prevent fatal error
if (!function_exists('log_activity')) {
    function log_activity($user_id, $action, $details = '') {
        // Silently fail if function is missing, but don't crash the app
        error_log("Warning: log_activity function missing. Action: $action");
        return false;
    }
}

/**
 * Case-fold email (UTF-8) without hard dependency on ext-mbstring.
 */
if (!function_exists('printflow_email_lower')) {
    function printflow_email_lower($email) {
        if (!is_string($email) || $email === '') {
            return '';
        }
        $t = trim($email);
        if (function_exists('mb_strtolower')) {
            return mb_strtolower($t, 'UTF-8');
        }
        return strtolower($t);
    }
}

if (!defined('PRINTFLOW_REMEMBER_TOKEN_COOKIE')) {
    define('PRINTFLOW_REMEMBER_TOKEN_COOKIE', 'PRINTFLOWREMEMBERTOKEN');
}

if (!defined('PRINTFLOW_REMEMBER_TOKEN_BYTES')) {
    define('PRINTFLOW_REMEMBER_TOKEN_BYTES', 32);
}

if (!defined('PRINTFLOW_REMEMBER_SELECTOR_BYTES')) {
    define('PRINTFLOW_REMEMBER_SELECTOR_BYTES', 12);
}

if (!function_exists('printflow_ensure_remember_tokens_table')) {
    function printflow_ensure_remember_tokens_table(): void {
        static $done = false;
        if ($done) {
            return;
        }
        $done = true;
        db_execute(
            "CREATE TABLE IF NOT EXISTS remember_tokens (
                id INT AUTO_INCREMENT PRIMARY KEY,
                selector VARCHAR(64) NOT NULL UNIQUE,
                token_hash VARCHAR(128) NOT NULL,
                user_id INT NOT NULL,
                user_type VARCHAR(32) NOT NULL,
                expires_at DATETIME NOT NULL,
                user_agent VARCHAR(255) DEFAULT NULL,
                ip_address VARCHAR(45) DEFAULT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                last_used TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                KEY idx_user (user_id, user_type),
                KEY idx_expires (expires_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
    }
}

if (!function_exists('printflow_remember_cookie_value')) {
    function printflow_remember_cookie_value(string $selector, string $validator): string {
        return $selector . ':' . $validator;
    }
}

if (!function_exists('printflow_parse_remember_cookie')) {
    function printflow_parse_remember_cookie(string $raw): ?array {
        $parts = explode(':', $raw, 2);
        if (count($parts) !== 2) {
            return null;
        }
        $selector = trim($parts[0]);
        $validator = trim($parts[1]);
        if ($selector === '' || $validator === '') {
            return null;
        }
        if (!ctype_xdigit($selector) || !ctype_xdigit($validator)) {
            return null;
        }
        return ['selector' => strtolower($selector), 'validator' => strtolower($validator)];
    }
}

if (!function_exists('printflow_set_remember_token_cookie')) {
    function printflow_set_remember_token_cookie(string $value, int $expiresAt): void {
        $host = strtolower((string)($_SERVER['HTTP_HOST'] ?? ''));
        $host = preg_replace('/:\d+$/', '', $host);
        $domain = '';
        if ($host !== '' && $host !== 'localhost' && !filter_var($host, FILTER_VALIDATE_IP) && substr_count($host, '.') >= 1) {
            $domain = $host;
        }

        setcookie(PRINTFLOW_REMEMBER_TOKEN_COOKIE, $value, [
            'expires' => $expiresAt,
            'path' => '/',
            'domain' => $domain,
            'secure' => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }
}

if (!function_exists('printflow_clear_remember_token_cookie')) {
    function printflow_clear_remember_token_cookie(): void {
        $host = strtolower((string)($_SERVER['HTTP_HOST'] ?? ''));
        $host = preg_replace('/:\d+$/', '', $host);
        $domains = [''];
        if ($host !== '' && $host !== 'localhost' && !filter_var($host, FILTER_VALIDATE_IP)) {
            $domains[] = $host;
            $domains[] = '.' . ltrim($host, '.');
            $parts = explode('.', $host);
            if (count($parts) >= 2) {
                $apex = $parts[count($parts) - 2] . '.' . $parts[count($parts) - 1];
                $domains[] = $apex;
                $domains[] = '.' . $apex;
            }
        }
        $domains = array_values(array_unique($domains));

        foreach ($domains as $domain) {
            setcookie(PRINTFLOW_REMEMBER_TOKEN_COOKIE, '', [
                'expires' => time() - 3600,
                'path' => '/',
                'domain' => $domain,
                'secure' => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
                'httponly' => true,
                'samesite' => 'Lax',
            ]);
        }
    }
}

if (!function_exists('printflow_store_remember_token')) {
    function printflow_store_remember_token(int $user_id, string $user_type, int $days): void {
        printflow_ensure_remember_tokens_table();
        $selector = bin2hex(random_bytes(PRINTFLOW_REMEMBER_SELECTOR_BYTES));
        $validator = bin2hex(random_bytes(PRINTFLOW_REMEMBER_TOKEN_BYTES));
        $tokenHash = hash('sha256', $validator);
        $expiresAtTs = time() + max(1, $days) * 86400;
        $expiresAt = date('Y-m-d H:i:s', $expiresAtTs);
        db_execute(
            "INSERT INTO remember_tokens (selector, token_hash, user_id, user_type, expires_at, user_agent, ip_address)
             VALUES (?, ?, ?, ?, ?, ?, ?)",
            'ssissss',
            [
                $selector,
                $tokenHash,
                $user_id,
                $user_type,
                $expiresAt,
                substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255),
                substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45),
            ]
        );
        printflow_set_remember_token_cookie(
            printflow_remember_cookie_value($selector, $validator),
            $expiresAtTs
        );
    }
}

if (!function_exists('printflow_clear_remember_token')) {
    function printflow_clear_remember_token(): void {
        printflow_ensure_remember_tokens_table();
        $raw = (string)($_COOKIE[PRINTFLOW_REMEMBER_TOKEN_COOKIE] ?? '');
        $parsed = printflow_parse_remember_cookie($raw);
        if ($parsed !== null) {
            db_execute(
                "DELETE FROM remember_tokens WHERE selector = ?",
                's',
                [$parsed['selector']]
            );
        }
        printflow_clear_remember_token_cookie();
    }
}

if (!function_exists('printflow_hydrate_user_session')) {
    function printflow_hydrate_user_session(int $user_id, string $user_type, array $row): array {
        if ($user_type === 'Customer') {
            $_SESSION['user_id'] = $user_id;
            $_SESSION['user_type'] = 'Customer';
            $_SESSION['user_name'] = trim((string)($row['first_name'] ?? '') . ' ' . (string)($row['last_name'] ?? ''));
            $_SESSION['user_email'] = (string)($row['email'] ?? '');
            if (function_exists('load_customer_cart_into_session')) {
                load_customer_cart_into_session($user_id);
            }
            return [
                'redirect' => AUTH_REDIRECT_BASE . '/customer/services.php',
            ];
        }

        $_SESSION['user_id'] = $user_id;
        $_SESSION['user_type'] = $user_type;
        $_SESSION['user_name'] = trim((string)($row['first_name'] ?? '') . ' ' . (string)($row['last_name'] ?? ''));
        $_SESSION['user_email'] = (string)($row['email'] ?? '');
        $_SESSION['user_status'] = (string)($row['status'] ?? '');
        $_SESSION['branch_id'] = $row['branch_id'] ?? null;
        $_SESSION['staff_position'] = (string)($row['position'] ?? '');
        if ($user_type === 'Staff') {
            $_SESSION['staff_access_role'] = printflow_resolve_staff_access_role_from_user($row);
        } else {
            unset($_SESSION['staff_access_role']);
        }

        if ($user_type === 'Manager' || $user_type === 'Staff') {
            $_SESSION['selected_branch_id'] = $row['branch_id'] ?? null;
        } else {
            $_SESSION['selected_branch_id'] = printflow_get_default_admin_branch_id();
        }

        $redirect = AUTH_REDIRECT_BASE . '/admin/dashboard.php';
        if ($user_type === 'Manager') {
            $redirect = AUTH_REDIRECT_BASE . '/manager/dashboard.php';
        } elseif ($user_type === 'Staff') {
            $status = (string)($row['status'] ?? '');
            $redirect = $status === 'Pending'
                ? AUTH_REDIRECT_BASE . '/staff/profile.php'
                : printflow_staff_home_url();
        }

        return ['redirect' => $redirect];
    }
}

if (!function_exists('printflow_attempt_auto_login_via_remember_token')) {
    function printflow_attempt_auto_login_via_remember_token(): void {
        if (is_logged_in()) {
            return;
        }

        $raw = (string)($_COOKIE[PRINTFLOW_REMEMBER_TOKEN_COOKIE] ?? '');
        if ($raw === '') {
            return;
        }

        $parsed = printflow_parse_remember_cookie($raw);
        if ($parsed === null) {
            printflow_clear_remember_token_cookie();
            return;
        }

        printflow_ensure_remember_tokens_table();
        db_execute("DELETE FROM remember_tokens WHERE expires_at <= NOW()");

        $rows = db_query(
            "SELECT * FROM remember_tokens WHERE selector = ? LIMIT 1",
            's',
            [$parsed['selector']]
        );
        if (empty($rows)) {
            printflow_clear_remember_token_cookie();
            return;
        }

        $tokenRow = $rows[0];
        $tokenHash = hash('sha256', $parsed['validator']);
        if (!hash_equals((string)($tokenRow['token_hash'] ?? ''), $tokenHash)) {
            db_execute("DELETE FROM remember_tokens WHERE selector = ?", 's', [$parsed['selector']]);
            printflow_clear_remember_token_cookie();
            return;
        }

        $userId = (int)($tokenRow['user_id'] ?? 0);
        $userType = (string)($tokenRow['user_type'] ?? '');
        if ($userId <= 0 || $userType === '') {
            db_execute("DELETE FROM remember_tokens WHERE selector = ?", 's', [$parsed['selector']]);
            printflow_clear_remember_token_cookie();
            return;
        }

        if ($userType === 'Customer') {
            $userRows = db_query("SELECT * FROM customers WHERE customer_id = ? LIMIT 1", 'i', [$userId]);
            if (empty($userRows)) {
                db_execute("DELETE FROM remember_tokens WHERE selector = ?", 's', [$parsed['selector']]);
                printflow_clear_remember_token_cookie();
                return;
            }
            $customer = $userRows[0];
            $status = isset($customer['status']) ? (string)$customer['status'] : 'Activated';
            if (in_array($status, ['Disabled', 'Suspended'], true)) {
                db_execute("DELETE FROM remember_tokens WHERE selector = ?", 's', [$parsed['selector']]);
                printflow_clear_remember_token_cookie();
                return;
            }
            SessionManager::regenerate();
            printflow_hydrate_user_session($userId, 'Customer', $customer);
            SessionManager::applyRememberMe(REMEMBER_ME_CUSTOMER_DAYS);
            SessionManager::commit();
            return;
        }

        $userRows = db_query("SELECT * FROM users WHERE user_id = ? LIMIT 1", 'i', [$userId]);
        if (empty($userRows)) {
            db_execute("DELETE FROM remember_tokens WHERE selector = ?", 's', [$parsed['selector']]);
            printflow_clear_remember_token_cookie();
            return;
        }

        $user = $userRows[0];
        $status = (string)($user['status'] ?? '');
        $role = (string)($user['role'] ?? '');
        if (!in_array($status, ['Activated', 'Pending'], true) || !in_array($role, ['Admin', 'Manager', 'Staff'], true)) {
            db_execute("DELETE FROM remember_tokens WHERE selector = ?", 's', [$parsed['selector']]);
            printflow_clear_remember_token_cookie();
            return;
        }

        $branchIssue = printflow_get_branch_access_issue($role, $user['branch_id'] ?? null);
        if ($branchIssue !== null) {
            db_execute("DELETE FROM remember_tokens WHERE selector = ?", 's', [$parsed['selector']]);
            printflow_clear_remember_token_cookie();
            return;
        }

        SessionManager::regenerate();
        printflow_hydrate_user_session($userId, $role, $user);
        SessionManager::applyRememberMe(REMEMBER_ME_STAFF_DAYS);
        SessionManager::commit();
    }
}

/**
 * Add customers.auth_provider (password | google) when missing.
 */
function printflow_ensure_customers_auth_provider_column() {
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    try {
        $has = false;
        if (function_exists('db_table_has_column')) {
            $has = db_table_has_column('customers', 'auth_provider');
        } else {
            $r = db_query("SHOW COLUMNS FROM `customers` LIKE 'auth_provider'");
            $has = !empty($r);
        }
        if (!$has) {
            @db_execute("ALTER TABLE `customers` ADD COLUMN `auth_provider` varchar(20) NULL DEFAULT NULL");
        }
    } catch (Exception $e) {
        error_log('printflow_ensure_customers_auth_provider_column: ' . $e->getMessage());
    }
}

function printflow_google_placeholder_password_hash(): string {
    return '!google-oauth-only!';
}

function printflow_customer_has_usable_password_hash($passwordHash): bool {
    $hash = trim((string)$passwordHash);
    return $hash !== '' && $hash !== printflow_google_placeholder_password_hash();
}

// Helper functions for checking duplicate emails/phones
if (!function_exists('email_in_use_across_accounts')) {
    function email_in_use_across_accounts($email) {
        $e = printflow_email_lower($email);
        if ($e === '') {
            return false;
        }
        $users = db_query("SELECT user_id FROM users WHERE LOWER(TRIM(email)) = ? LIMIT 1", 's', [$e]);
        $customers = db_query("SELECT customer_id FROM customers WHERE LOWER(TRIM(email)) = ? LIMIT 1", 's', [$e]);
        return !empty($users) || !empty($customers);
    }
}

if (!function_exists('contact_phone_in_use_across_accounts')) {
    function contact_phone_in_use_across_accounts($phone) {
        if (empty($phone)) return false;
        $users = db_query("SELECT user_id FROM users WHERE contact_number = ?", 's', [$phone]);
        $customers = db_query("SELECT customer_id FROM customers WHERE contact_number = ?", 's', [$phone]);
        return !empty($users) || !empty($customers);
    }
}

/**
 * Check if user is logged in
 * @return bool
 */
function is_logged_in() {
    return isset($_SESSION['user_id']) && isset($_SESSION['user_type']);
}

/**
 * Check if user is Admin
 * @return bool
 */
function is_admin() {
    return is_logged_in() && $_SESSION['user_type'] === 'Admin';
}

/**
 * Check if user is Staff
 * @return bool
 */
function is_staff() {
    return is_logged_in() && $_SESSION['user_type'] === 'Staff';
}

/**
 * Check if user is Manager
 * @return bool
 */
function is_manager() {
    return is_logged_in() && $_SESSION['user_type'] === 'Manager';
}

/**
 * Check if user is Admin or Manager
 * @return bool
 */
function is_admin_or_manager() {
    return is_logged_in() && in_array($_SESSION['user_type'], ['Admin', 'Manager']);
}

/**
 * Check if user is Customer
 * @return bool
 */
function is_customer() {
    return is_logged_in() && $_SESSION['user_type'] === 'Customer';
}

/**
 * Check if the current user has one of the specified roles
 * @param string|array $roles The role(s) to check
 * @return bool
 */
function has_role($roles) {
    if (!is_logged_in()) {
        return false;
    }
    
    if (!is_array($roles)) {
        $roles = [$roles];
    }
    
    $user_type = get_user_type();
    return in_array($user_type, $roles);
}

/**
 * Get current user ID
 * @return int|null
 */
function get_user_id() {
    return $_SESSION['user_id'] ?? null;
}

/**
 * Get current user type
 * @return string|null
 */
function get_user_type() {
    return $_SESSION['user_type'] ?? null;
}

/**
 * Get current logged in user data
 * @return array|null
 */
function get_logged_in_user() {
    if (!is_logged_in()) {
        return null;
    }
    
    $user_id = get_user_id();
    $user_type = get_user_type();
    
    if ($user_type === 'Customer') {
        $result = db_query("SELECT * FROM customers WHERE customer_id = ?", 'i', [$user_id]);
    } else {
        $result = db_query("SELECT * FROM users WHERE user_id = ?", 'i', [$user_id]);
    }
    
    return $result[0] ?? null;
}

function printflow_get_forced_logout_reason(): ?string {
    return isset($GLOBALS['printflow_forced_logout_reason'])
        ? (string)$GLOBALS['printflow_forced_logout_reason']
        : null;
}

function printflow_format_countdown_mmss(int $seconds): string {
    $seconds = max(0, $seconds);
    $minutes = intdiv($seconds, 60);
    $remainingSeconds = $seconds % 60;
    return $minutes . ':' . str_pad((string)$remainingSeconds, 2, '0', STR_PAD_LEFT);
}

function printflow_get_forced_logout_message(): ?string {
    return isset($GLOBALS['printflow_forced_logout_message'])
        ? (string)$GLOBALS['printflow_forced_logout_message']
        : null;
}

function printflow_get_branch_access_issue(string $role, $branchId): ?array {
    if (!in_array($role, ['Manager', 'Staff'], true)) {
        return null;
    }

    $branchId = (int)$branchId;
    if ($branchId <= 0) {
        return [
            'reason' => 'branch_inactive',
            'message' => 'Your assigned branch is unavailable. Please contact an administrator.'
        ];
    }

    $rows = db_query(
        "SELECT branch_name, status FROM branches WHERE id = ? LIMIT 1",
        'i',
        [$branchId]
    );

    $branch = $rows[0] ?? null;
    $status = trim((string)($branch['status'] ?? ''));
    if (strcasecmp($status, 'Active') === 0) {
        return null;
    }

    $branchName = trim((string)($branch['branch_name'] ?? ''));
    $message = $branchName !== ''
        ? ('Your assigned branch (' . $branchName . ') is inactive. Please contact an administrator.')
        : 'Your assigned branch is inactive. Please contact an administrator.';

    return [
        'reason' => 'branch_inactive',
        'message' => $message
    ];
}

function printflow_enforce_restricted_branch_session(): void {
    if (!is_logged_in()) {
        return;
    }

    $role = (string)($_SESSION['user_type'] ?? '');
    if (!in_array($role, ['Manager', 'Staff'], true)) {
        return;
    }

    $issue = printflow_get_branch_access_issue($role, $_SESSION['branch_id'] ?? null);
    if ($issue === null) {
        return;
    }

    $GLOBALS['printflow_forced_logout_reason'] = $issue['reason'];
    $GLOBALS['printflow_forced_logout_message'] = $issue['message'];
    printflow_clear_remember_token();
    SessionManager::clearRememberMe();
    SessionManager::destroy();
}

printflow_attempt_auto_login_via_remember_token();
printflow_enforce_restricted_branch_session();

/**
 * Resolve the default admin branch.
 * Prefers an active Cabuyao branch because it is the main branch,
 * then falls back to the first active branch, then branch id 1.
 *
 * @return int
 */
function printflow_get_default_admin_branch_id(): int {
    static $cached_branch_id = null;
    if ($cached_branch_id !== null) {
        return $cached_branch_id;
    }

    try {
        $match = db_query(
            "SELECT id
             FROM branches
             WHERE status != 'Archived'
               AND (
                   LOWER(branch_name) LIKE '%cabuyao%'
                   OR LOWER(city) LIKE '%cabuyao%'
                   OR LOWER(address) LIKE '%cabuyao%'
               )
             ORDER BY
                 CASE
                     WHEN LOWER(branch_name) = 'cabuyao branch' THEN 0
                     WHEN LOWER(branch_name) = 'cabuyao' THEN 1
                     WHEN LOWER(branch_name) LIKE 'cabuyao%' THEN 2
                     ELSE 3
                 END,
                 id ASC
             LIMIT 1"
        );
        $matched_branch_id = (int)($match[0]['id'] ?? 0);
        if ($matched_branch_id > 0) {
            $cached_branch_id = $matched_branch_id;
            return $cached_branch_id;
        }

        $fallback = db_query(
            "SELECT id
             FROM branches
             WHERE status != 'Archived'
             ORDER BY id ASC
             LIMIT 1"
        );
        $fallback_branch_id = (int)($fallback[0]['id'] ?? 0);
        $cached_branch_id = $fallback_branch_id > 0 ? $fallback_branch_id : 1;
        return $cached_branch_id;
    } catch (Exception $e) {
        $cached_branch_id = 1;
        return $cached_branch_id;
    }
}

/**
 * Login user (Admin/Staff)
 * @param string $email
 * @param string $password
 * @param bool $remember_me Whether to extend session cookie lifetime
 * @return array ['success' => bool, 'message' => string, 'redirect' => string]
 */
function login_user($email, $password, $remember_me = false) {
    // First check if email exists at all (regardless of status)
    $result = db_query("SELECT * FROM users WHERE email = ?", 's', [$email]);

    if (empty($result)) {
        return ['success' => false, 'message' => 'Invalid email or password'];
    }

    $user = $result[0];

    // Account status check — give specific error before password check
    if ($user['status'] === 'Disabled') {
        return ['success' => false, 'message' => 'Your account has been disabled. Please contact support.'];
    }
    if ($user['status'] === 'Suspended') {
        return ['success' => false, 'message' => 'Your account has been suspended. Please contact support.'];
    }
    // Only allow Activated or Pending status
    if (!in_array($user['status'], ['Activated', 'Pending'])) {
        return ['success' => false, 'message' => 'Your account is not active. Please contact support.'];
    }

    if (!password_verify($password, $user['password_hash'])) {
        return ['success' => false, 'message' => 'Invalid email or password'];
    }

    $branchIssue = printflow_get_branch_access_issue((string)($user['role'] ?? ''), $user['branch_id'] ?? null);
    if ($branchIssue !== null) {
        return ['success' => false, 'message' => $branchIssue['message']];
    }
    
    // Set session variables
    $_SESSION['user_id']   = $user['user_id'];
    $_SESSION['user_type'] = $user['role']; // 'Admin', 'Manager', or 'Staff'
    $_SESSION['user_name'] = $user['first_name'] . ' ' . $user['last_name'];
    $_SESSION['user_email']  = $user['email'];
    $_SESSION['user_status'] = $user['status'];
    $_SESSION['branch_id']   = $user['branch_id'] ?? null;
    $_SESSION['staff_position'] = (string)($user['position'] ?? '');
    if (($user['role'] ?? '') === 'Staff') {
        $_SESSION['staff_access_role'] = printflow_resolve_staff_access_role_from_user($user);
    } else {
        unset($_SESSION['staff_access_role']);
    }

    // Force Manager (and Staff) to their assigned branch immediately so the
    // branch selector never shows "All Branches" for restricted accounts.
    if ($user['role'] === 'Manager' || $user['role'] === 'Staff') {
        $_SESSION['selected_branch_id'] = $user['branch_id'] ?? null;
    } else {
        // Admin: default to the main Cabuyao branch on login.
        $_SESSION['selected_branch_id'] = printflow_get_default_admin_branch_id();
    }

    // Determine redirect based on role and status
    if ($user['role'] === 'Admin') {
        $redirect = AUTH_REDIRECT_BASE . '/admin/dashboard.php';
    } elseif ($user['role'] === 'Manager') {
        $redirect = AUTH_REDIRECT_BASE . '/manager/dashboard.php';
    } elseif ($user['status'] === 'Pending') {
        // Pending staff can only see profile to complete their information
        $redirect = AUTH_REDIRECT_BASE . '/staff/profile.php';
    } else {
        $redirect = printflow_staff_home_url();
    }

    SessionManager::regenerate();
    if ($remember_me) {
        SessionManager::applyRememberMe(REMEMBER_ME_STAFF_DAYS);
        printflow_store_remember_token((int)$user['user_id'], (string)$user['role'], REMEMBER_ME_STAFF_DAYS);
    } else {
        printflow_clear_remember_token();
        SessionManager::clearRememberMe();
    }
    SessionManager::commit();
    return [
        'success' => true,
        'message' => 'Login successful',
        'redirect' => $redirect
    ];
}

/**
 * Login customer
 * @param string $email
 * @param string $password
 * @param bool $remember_me Whether to extend session cookie lifetime
 * @return array ['success' => bool, 'message' => string, 'redirect' => string]
 */
function login_customer($email, $password, $remember_me = false) {
    $emailLower = printflow_email_lower($email);
    $result = $emailLower === ''
        ? []
        : db_query("SELECT * FROM customers WHERE LOWER(TRIM(email)) = ?", 's', [$emailLower]);

    // Also try phone-based accounts (contact_number match or phone@phone.local email)
    if (empty($result)) {
        $phone_clean = preg_replace('/[\s\-\(\)]/', '', $email);
        if (preg_match('/^(\+63|0)9\d{9}$/', $phone_clean)) {
            // Try by contact_number
            $result = db_query("SELECT * FROM customers WHERE contact_number = ?", 's', [$phone_clean]);
            if (empty($result)) {
                // Try by generated email placeholder
                $result = db_query("SELECT * FROM customers WHERE email = ?", 's', [$phone_clean . '@phone.local']);
            }
        }
    }

    if (empty($result)) {
        return ['success' => false, 'message' => 'Invalid email or password'];
    }

    $customer = $result[0];

    // Account status check (if the customers table has a status column)
    if (isset($customer['status'])) {
        if ($customer['status'] === 'Disabled') {
            return ['success' => false, 'message' => 'Your account has been disabled. Please contact support.'];
        }
        if ($customer['status'] === 'Suspended') {
            return ['success' => false, 'message' => 'Your account has been suspended. Please contact support.'];
        }
    }

    if (!printflow_customer_has_usable_password_hash($customer['password_hash'] ?? null)) {
        return ['success' => false, 'message' => 'Invalid email or password'];
    }

    if (!password_verify($password, $customer['password_hash'])) {
        return ['success' => false, 'message' => 'Invalid email or password'];
    }
    
    // Set session variables
    $_SESSION['user_id'] = $customer['customer_id'];
    $_SESSION['user_type'] = 'Customer';
    $_SESSION['user_name'] = $customer['first_name'] . ' ' . $customer['last_name'];
    $_SESSION['user_email'] = $customer['email'];

    SessionManager::regenerate();
    if ($remember_me) {
        SessionManager::applyRememberMe(REMEMBER_ME_CUSTOMER_DAYS);
        printflow_store_remember_token((int)$customer['customer_id'], 'Customer', REMEMBER_ME_CUSTOMER_DAYS);
    } else {
        printflow_clear_remember_token();
        SessionManager::clearRememberMe();
    }
    // Load persisted cart from database
    if (function_exists('load_customer_cart_into_session')) {
        load_customer_cart_into_session($customer['customer_id']);
    }
    SessionManager::commit();
    return [
        'success' => true,
        'message' => 'Login successful',
        'redirect' => AUTH_REDIRECT_BASE . '/customer/services.php'
    ];
}

/**
 * Login or register customer using Google profile (no password). Finds by email or creates new.
 * @param string $email
 * @param string $first_name
 * @param string $last_name
 * @return array ['success' => bool, 'message' => string, 'redirect' => string]
 */
function login_customer_by_google($email, $first_name, $last_name) {
    printflow_ensure_customers_auth_provider_column();
    $first_name = trim($first_name) ?: 'User';
    $last_name = trim($last_name) ?: '';
    $raw = trim((string)$email);
    if ($raw === '' || !filter_var($raw, FILTER_VALIDATE_EMAIL)) {
        return ['success' => false, 'message' => 'Invalid email from Google'];
    }
    $email = printflow_email_lower($raw);
    if ($email === '') {
        return ['success' => false, 'message' => 'Invalid email from Google'];
    }
    $staff = db_query("SELECT user_id FROM users WHERE LOWER(TRIM(email)) = ? LIMIT 1", 's', [$email]);
    if (!empty($staff)) {
        return [
            'success' => false,
            'message' => 'This email is already used for a staff or admin account. You cannot use Google sign-in for this address. Please sign in with your work email and password.',
        ];
    }

    $existing = db_query("SELECT * FROM customers WHERE LOWER(TRIM(email)) = ? LIMIT 1", 's', [$email]);
    if (!empty($existing)) {
        $customer = $existing[0];
        $ap = strtolower(trim((string)($customer['auth_provider'] ?? '')));
        // Only allow Google if this customer account was created for Google (one email, one sign-in method).
        if ($ap !== 'google') {
            return [
                'success' => false,
                'message' => 'This email is already registered. Please use Sign in with your email and password — Google sign-in is not available for this address (one email, one sign-in method).',
            ];
        }
        if (!printflow_customer_has_usable_password_hash($customer['password_hash'] ?? null)) {
            db_execute(
                "UPDATE customers SET auth_provider = 'google', password_hash = ? WHERE customer_id = ?",
                'si',
                [printflow_google_placeholder_password_hash(), (int)$customer['customer_id']]
            );
        } else {
            db_execute("UPDATE customers SET auth_provider = 'google' WHERE customer_id = ?", 'i', [(int)$customer['customer_id']]);
        }
        $_SESSION['user_id'] = $customer['customer_id'];
        $_SESSION['user_type'] = 'Customer';
        $_SESSION['user_name'] = ($customer['first_name'] ?? '') . ' ' . ($customer['last_name'] ?? '');
        $_SESSION['user_email'] = $customer['email'];
        SessionManager::regenerate();
        if (function_exists('load_customer_cart_into_session')) {
            load_customer_cart_into_session($customer['customer_id']);
        }
        SessionManager::commit();
        return ['success' => true, 'message' => 'Login successful', 'redirect' => AUTH_REDIRECT_BASE . '/customer/services.php'];
    }
    $password_hash = printflow_google_placeholder_password_hash();
    $sql = "INSERT INTO customers (first_name, middle_name, last_name, dob, gender, email, contact_number, password_hash, auth_provider, created_by_system) VALUES (?, '', ?, NULL, NULL, ?, NULL, ?, 'google', 1)";
    $cid = printflow_run_guarded_account_insert(function() use ($sql, $first_name, $last_name, $email, $password_hash) {
        return db_execute($sql, 'ssss', [$first_name, $last_name, $email, $password_hash]);
    });
    if (!$cid) {
        return ['success' => false, 'message' => 'Could not create account. Please try again.'];
    }
    $_SESSION['user_id'] = $cid;
    $_SESSION['user_type'] = 'Customer';
    $_SESSION['user_name'] = $first_name . ' ' . $last_name;
    $_SESSION['user_email'] = $email;
    SessionManager::regenerate();
    if (function_exists('load_customer_cart_into_session')) {
        load_customer_cart_into_session($cid);
    }
    SessionManager::commit();
    return ['success' => true, 'message' => 'Account created', 'redirect' => AUTH_REDIRECT_BASE . '/customer/services.php'];
}

/**
 * Unified login function (detects user type automatically)
 * @param string $email
 * @param string $password
 * @param bool $remember_me
 * @return array
 */
function login($email, $password, $remember_me = false) {
    $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

    $activeLockout = RateLimiter::getActiveLockout('login', $ip);
    if ($activeLockout !== null) {
        $remainingSeconds = max(1, (int)($activeLockout['remaining_seconds'] ?? 0));
        return [
            'success' => false,
            'message' => 'Too many login attempts. Please try again in ' . printflow_format_countdown_mmss($remainingSeconds) . '.',
            'lockout_remaining_seconds' => $remainingSeconds,
            'lockout_level' => (int)($activeLockout['lockout_level'] ?? 0),
            'code' => 'login_locked'
        ];
    }

    // Try customer login first
    $customer_result = login_customer($email, $password, $remember_me);
    if ($customer_result['success']) {
        RateLimiter::clear('login', $ip);
        return $customer_result;
    }

    // Try user (Admin/Staff) login
    $user_result = login_user($email, $password, $remember_me);
    if ($user_result['success']) {
        RateLimiter::clear('login', $ip);
        return $user_result;
    }

    $failureState = RateLimiter::recordFailure('login', $ip, 5, 900);
    if (!empty($failureState['locked'])) {
        $remainingSeconds = max(1, (int)($failureState['remaining_seconds'] ?? 0));
        return [
            'success' => false,
            'message' => 'Too many login attempts. Please try again in ' . printflow_format_countdown_mmss($remainingSeconds) . '.',
            'lockout_remaining_seconds' => $remainingSeconds,
            'lockout_level' => (int)($failureState['lockout_level'] ?? 0),
            'code' => 'login_locked'
        ];
    }
    return ['success' => false, 'message' => 'Invalid email or password'];
}


/**
 * Register a new customer
 * @param array $data
 * @return array ['success' => bool, 'message' => string]
 */
function register_customer($data) {
    printflow_ensure_customers_auth_provider_column();
    if (!empty($data['email']) && is_string($data['email'])) {
        $data['email'] = printflow_email_lower($data['email']);
    }
    if (email_in_use_across_accounts($data['email'] ?? '')) {
        return ['success' => false, 'message' => 'This email is already in use. Please sign in.'];
    }
    $cn = $data['contact_number'] ?? '';
    if ($cn !== '' && $cn !== null && contact_phone_in_use_across_accounts($cn)) {
        return ['success' => false, 'message' => 'This phone number is already in use. Please sign in or use a different number.'];
    }

    // Hash password
    $password_hash = password_hash($data['password'], PASSWORD_BCRYPT);
    
    // Insert customer
    $sql = "INSERT INTO customers (first_name, middle_name, last_name, dob, gender, email, contact_number, password_hash, auth_provider, created_by_system) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'password', 1)";
    
    $result = printflow_run_guarded_account_insert(function() use ($sql, $data, $password_hash) {
        return db_execute($sql, 'ssssssss', [
            $data['first_name'],
            $data['middle_name'] ?? null,
            $data['last_name'],
            $data['dob'] ?? null,
            $data['gender'] ?? null,
            $data['email'],
            $data['contact_number'] ?? null,
            $password_hash
        ]);
    });
    
    if ($result) {
        // Auto-login after registration
        $_SESSION['user_id'] = $result;
        $_SESSION['user_type'] = 'Customer';
        $_SESSION['user_name'] = $data['first_name'] . ' ' . $data['last_name'];
        $_SESSION['user_email'] = $data['email'];
        SessionManager::regenerate();
        return ['success' => true, 'message' => 'Registration successful'];
    }

    return ['success' => false, 'message' => 'Registration failed. Please try again.'];
}

/**
 * Register customer directly via email or phone (no validation)
 * @param string $type 'email' or 'phone'
 * @param string $identifier The email or phone number
 * @param string $password The password
 * @return array ['success' => bool, 'message' => string]
 */
function register_customer_direct($type, $identifier, $password) {
    // Determine email and contact_number
    if ($type === 'email') {
        $email = $identifier;
        $contact_number = null;
    } else {
        $email = $identifier . '@phone.local'; // placeholder for NOT NULL constraint
        $contact_number = $identifier;
    }

    // Check if already exists
    $existing = db_query("SELECT customer_id, email_verified FROM customers WHERE email = ?", 's', [$email]);
    if (!empty($existing)) {
        if ($existing[0]['email_verified'] == 0) {
            // Delete unverified account to allow retry
            db_execute("DELETE FROM customers WHERE customer_id = ?", 'i', [$existing[0]['customer_id']]);
        } else {
            return ['success' => false, 'message' => 'This email is already in use. Please sign in.'];
        }
    }

    if ($contact_number) {
        $existing2 = db_query("SELECT customer_id, email_verified FROM customers WHERE contact_number = ?", 's', [$contact_number]);
        if (!empty($existing2)) {
            if ($existing2[0]['email_verified'] == 0) {
                db_execute("DELETE FROM customers WHERE customer_id = ?", 'i', [$existing2[0]['customer_id']]);
            } else {
                return ['success' => false, 'message' => 'Phone number already registered. Please login.'];
            }
        }
    }

    if (email_in_use_across_accounts($email)) {
        return ['success' => false, 'message' => 'This email is already in use (customer or staff account). Please sign in or use a different email.'];
    }
    if ($contact_number && contact_phone_in_use_across_accounts($contact_number)) {
        return ['success' => false, 'message' => 'This phone number is already in use on another account. Please sign in or use a different number.'];
    }

    $password_hash = password_hash($password, PASSWORD_BCRYPT);

    $sql = "INSERT INTO customers (first_name, middle_name, last_name, dob, gender, email, contact_number, password_hash, is_profile_complete, email_verified, created_by_system) 
            VALUES (?, '', ?, NULL, NULL, ?, ?, ?, 0, 0, 1)";

    $result = printflow_run_guarded_account_insert(function() use ($sql, $email, $contact_number, $password_hash) {
        return db_execute($sql, 'sssss', [
            '',           // placeholder first_name
            '',           // placeholder last_name
            $email,
            $contact_number,
            $password_hash
        ]);
    });

    if ($result) {
        // Generate OTP
        $otp = (string)rand(100000, 999999);
        $now = date('Y-m-d H:i:s');
        $expiry = date("Y-m-d H:i:s", strtotime("+10 minutes"));

        // Save OTP to database
        db_execute("UPDATE customers SET otp_code = ?, otp_expiry = ?, otp_last_sent = ? WHERE customer_id = ?", 'sssi', [$otp, $expiry, $now, $result]);

        $otp_sent = false;
        $mail_res = null;
        if ($contact_number) {
            // Phone registration: send OTP via SMS (Philippines)
            require_once __DIR__ . '/email_sms_config.php';
            if (defined('SMS_ENABLED') && SMS_ENABLED && function_exists('send_sms')) {
                $phone_e164 = preg_replace('/\D/', '', $contact_number);
                if (preg_match('/^0(\d{10})$/', $phone_e164, $m)) $phone_e164 = '63' . $m[1];
                elseif (strlen($phone_e164) === 10 && $phone_e164[0] === '9') $phone_e164 = '63' . $phone_e164;
                $otp_sent = send_sms('+' . $phone_e164, "PrintFlow: Your verification code is {$otp}. Valid for 10 minutes.");
            }
            if (!$otp_sent) {
                // Fallback: email to placeholder (won't work, but keeps flow; or log)
                require_once __DIR__ . '/otp_mailer.php';
                $mail_res = send_otp_email($email, $otp);
                $otp_sent = isset($mail_res['success']) && $mail_res['success'] === true;
            }
        } else {
            // Email registration: send OTP via email
            require_once __DIR__ . '/otp_mailer.php';
            $mail_res = send_otp_email($email, $otp);
            $otp_sent = isset($mail_res['success']) && $mail_res['success'] === true;
        }

        if ($otp_sent) {
            // Auto-login after registration (Optional - we can keep it or remove it)
            // But if we want them to verify first, maybe don't set user_id yet?
            // Existing flow expects them to be "half-logged in" or just have session markers.
            
            $_SESSION['otp_pending_email'] = $email;
            $_SESSION['otp_user_type'] = 'Customer';
            $_SESSION['otp_resend_attempts'] = 0;

            return ['success' => true, 'message' => 'Registration successful! Verification code sent.'];
        } else {
            // ROLLBACK: Delete the customer record if OTP delivery failed
            db_execute("DELETE FROM customers WHERE customer_id = ?", 'i', [$result]);
            $msg = $contact_number
                ? 'Failed to send SMS. Ensure SMS is configured (Semaphore for PH).'
                : ('Failed to send verification email: ' . ($mail_res['message'] ?? 'Unknown error'));
            return ['success' => false, 'message' => $msg];
        }
    }

    return ['success' => false, 'message' => 'Registration failed. Please try again.'];
}

function customer_profile_completion_status($customer_id = null): array {
    if ($customer_id === null) $customer_id = get_user_id();
    if (!$customer_id || get_user_type() !== 'Customer') {
        return ['complete' => true, 'missing' => []];
    }

    $existing_cols = [];
    foreach (db_query("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'customers'") ?: [] as $r) {
        $existing_cols[$r['COLUMN_NAME']] = true;
    }

    $required_cols = ['first_name', 'last_name', 'dob', 'gender', 'email', 'contact_number'];
    $select_cols = array_values(array_filter(
        array_unique(array_merge($required_cols, ['is_profile_complete'])),
        static fn($col) => !empty($existing_cols[$col])
    ));

    if (empty($select_cols)) {
        return ['complete' => false, 'missing' => ['Personal information']];
    }

    $sql_cols = implode(', ', array_map(static fn($col) => "`$col`", $select_cols));
    $result = db_query("SELECT $sql_cols FROM customers WHERE customer_id = ? LIMIT 1", 'i', [$customer_id]);
    if (empty($result)) {
        return ['complete' => true, 'missing' => []];
    }

    $customer = $result[0];
    $missing = [];
    $name_regex = '/^[A-Za-z]+( [A-Za-z]+){0,2}$/';
    $contact_regex = '/^\+?[0-9]{10,15}$/';

    $first_name = trim((string)($customer['first_name'] ?? ''));
    if ($first_name === '' || strcasecmp($first_name, 'Customer') === 0 || !preg_match($name_regex, $first_name)) {
        $missing[] = 'First name';
    }
    $last_name = trim((string)($customer['last_name'] ?? ''));
    if ($last_name === '' || !preg_match($name_regex, $last_name)) {
        $missing[] = 'Last name';
    }
    $contact_number = trim((string)($customer['contact_number'] ?? ''));
    if ($contact_number === '' || !preg_match($contact_regex, $contact_number)) {
        $missing[] = 'Contact number';
    }
    $dob = trim((string)($customer['dob'] ?? ''));
    if ($dob === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dob) || strtotime($dob) > strtotime('-13 years')) {
        $missing[] = 'Birthday';
    }
    if (!in_array(trim((string)($customer['gender'] ?? '')), ['Male', 'Female', 'Other'], true)) {
        $missing[] = 'Gender';
    }
    if (empty($customer['email']) || !filter_var((string)$customer['email'], FILTER_VALIDATE_EMAIL)) {
        $missing[] = 'Email address';
    }

    return ['complete' => empty($missing), 'missing' => array_values(array_unique($missing))];
}

function sync_customer_profile_completion($customer_id = null): bool {
    if ($customer_id === null) $customer_id = get_user_id();
    if (!$customer_id || get_user_type() !== 'Customer') return true;

    $status = customer_profile_completion_status($customer_id);
    db_execute(
        "UPDATE customers SET is_profile_complete = ? WHERE customer_id = ?",
        'ii',
        [$status['complete'] ? 1 : 0, $customer_id]
    );
    return (bool)$status['complete'];
}

/**
 * Check if customer profile is complete (has all required profile details).
 * @param int|null $customer_id
 * @return bool
 */
function is_profile_complete($customer_id = null) {
    return sync_customer_profile_completion($customer_id);
}

/**
 * Require authentication (redirect to login if not logged in).
 * Sets no-cache headers and handles session timeout redirect.
 */
function require_auth() {
    SessionManager::setNoCacheHeaders();
    if (!is_logged_in()) {
        if (printflow_get_forced_logout_reason() === 'branch_inactive') {
            $message = printflow_get_forced_logout_message() ?: 'Your assigned branch is inactive. Please contact an administrator.';
            header('Location: ' . AUTH_REDIRECT_BASE . '/?auth_modal=login&branch_inactive=1&error=' . urlencode($message));
        } elseif (SessionManager::wasTimedOut()) {
            header('Location: ' . AUTH_REDIRECT_BASE . '/?auth_modal=login&timeout=1');
        } else {
            header('Location: ' . AUTH_REDIRECT_BASE . '/');
        }
        exit();
    }
}

/**
 * Staff/Manager: if DB status is Archived, redirect to logout (session must not stay active).
 * Safe to call multiple times per request (one DB read per user id).
 */
function printflow_guard_archived_staff_manager(): void {
    static $checked_uid = null;
    if (!function_exists('get_user_type') || !function_exists('get_user_id')) {
        return;
    }
    $role = get_user_type();
    if (!in_array($role, ['Manager', 'Staff'], true)) {
        return;
    }
    $uid = (int) get_user_id();
    if ($uid <= 0) {
        return;
    }
    if ($checked_uid === $uid) {
        return;
    }
    $checked_uid = $uid;
    try {
        $row = db_query('SELECT status FROM users WHERE user_id = ? LIMIT 1', 'i', [$uid]);
        if (!empty($row) && ($row[0]['status'] ?? '') === 'Archived') {
            $bp = defined('BASE_PATH') ? BASE_PATH : AUTH_REDIRECT_BASE;
            header('Location: ' . rtrim((string) $bp, '/') . '/public/logout.php');
            exit;
        }
    } catch (Throwable $e) {
        // non-fatal
    }
}

/**
 * Require specific role (redirect if user doesn't have the role)
 * @param string|array $roles Allowed roles (e.g., 'Admin' or ['Admin', 'Staff'])
 */
function require_role($roles) {
    require_auth();
    
    if (!is_array($roles)) {
        $roles = [$roles];
    }
    
    $user_type = get_user_type();
    
    if (!in_array($user_type, $roles)) {
        // Redirect to appropriate dashboard instead of showing error
        redirect_to_dashboard();
        exit();
    }

    printflow_guard_archived_staff_manager();
}

/**
 * Redirect user to their appropriate dashboard based on role
 */
function redirect_to_dashboard() {
    if (!is_logged_in()) {
        header('Location: ' . AUTH_REDIRECT_BASE . '/');
        exit();
    }
    
    $user_type = get_user_type();
    
    switch ($user_type) {
        case 'Admin':
            header('Location: ' . AUTH_REDIRECT_BASE . '/admin/dashboard.php');
            break;
        case 'Manager':
            header('Location: ' . AUTH_REDIRECT_BASE . '/manager/dashboard.php');
            break;
        case 'Staff':
            header('Location: ' . printflow_staff_home_url());
            break;
        case 'Customer':
            header('Location: ' . AUTH_REDIRECT_BASE . '/customer/services.php');
            break;
        default:
            header('Location: ' . AUTH_REDIRECT_BASE . '/');
    }
    exit();
}

/**
 * Require customer access (customers only, block admin/staff)
 */
function require_customer() {
    require_auth();
    
    if (!is_customer()) {
        redirect_to_dashboard();
        exit();
    }
}

/**
 * Require admin/staff access (block customers)
 */
function require_admin_or_staff() {
    require_auth();
    
    $user_type = get_user_type();
    if (!in_array($user_type, ['Admin', 'Manager', 'Staff'])) {
        redirect_to_dashboard();
        exit();
    }

    printflow_guard_archived_staff_manager();
}

/**
 * Redirect any logged-in user away from guest/public pages.
 */
function redirect_logged_in_from_public_page(): void {
    if (!is_logged_in()) {
        return;
    }
    SessionManager::setNoCacheHeaders();
    $user_type = get_user_type();
    if ($user_type === 'Admin') {
        header('Location: ' . AUTH_REDIRECT_BASE . '/admin/dashboard.php', true, 302);
        exit();
    }
    if ($user_type === 'Manager') {
        header('Location: ' . AUTH_REDIRECT_BASE . '/manager/dashboard.php', true, 302);
        exit();
    }
    if ($user_type === 'Staff') {
        header('Location: ' . AUTH_REDIRECT_BASE . '/staff/dashboard.php', true, 302);
        exit();
    }
    if ($user_type === 'Customer') {
        header('Location: ' . AUTH_REDIRECT_BASE . '/customer/services.php', true, 302);
        exit();
    }
}

/**
 * Backward-compatible name used by older public page guards.
 */
function redirect_admin_staff_from_public() {
    redirect_logged_in_from_public_page();
}

/**
 * Redirect any logged-in user away from the public home (/printflow/).
 */
function redirect_logged_in_from_landing_page(): void {
    redirect_logged_in_from_public_page();
}

/**
 * Generate CSRF token
 * @return string
 */
function generate_csrf_token() {
    if (!isset($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/**
 * Verify CSRF token
 * @param string $token
 * @return bool
 */
function verify_csrf_token($token) {
    return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}

/**
 * Get CSRF token HTML input
 * @return string
 */
function csrf_field() {
    $token = generate_csrf_token();
    return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars($token) . '">';
}
