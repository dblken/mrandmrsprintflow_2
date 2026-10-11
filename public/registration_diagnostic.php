<?php
declare(strict_types=1);

// Temporary read-only diagnostic for the public registration deployment.
ini_set('display_errors', '0');
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');

$mailerPath = __DIR__ . '/../includes/otp_mailer.php';
$smtpPath = __DIR__ . '/../includes/smtp_config.php';
$autoloadPath = __DIR__ . '/../vendor/autoload.php';
$handlerPath = __DIR__ . '/process_register.php';

$result = [
    'success' => false,
    'checks' => [
        'registration_handler' => is_file($handlerPath),
        'otp_mailer_path' => is_file($mailerPath),
        'smtp_config_path' => is_file($smtpPath),
        'composer_autoload' => is_file($autoloadPath),
    ],
];

try {
    require_once $mailerPath;
    $result['checks']['otp_mailer_loaded'] = function_exists('send_otp_email');
    $result['checks']['phpmailer_loaded'] = class_exists('\PHPMailer\PHPMailer\PHPMailer');

    if (is_file($smtpPath)) {
        $config = require $smtpPath;
        $requiredKeys = ['smtp_host', 'smtp_port', 'smtp_user', 'smtp_pass', 'smtp_secure', 'from_email'];
        $result['checks']['smtp_config_complete'] = is_array($config)
            && count(array_diff($requiredKeys, array_keys($config))) === 0;
    } else {
        $result['checks']['smtp_config_complete'] = false;
    }

    $result['success'] = !in_array(false, $result['checks'], true);

    // Load the same database layer used by registration, but perform read-only checks.
    require_once __DIR__ . '/../config.php';
    require_once __DIR__ . '/../includes/db.php';
    $requiredCustomerColumns = [
        'customer_id', 'first_name', 'middle_name', 'last_name', 'dob', 'gender',
        'email', 'contact_number', 'password_hash', 'is_profile_complete',
        'email_verified', 'created_by_system', 'status', 'terms_accepted_at',
        'terms_version', 'region', 'province', 'city', 'barangay', 'street_address',
        'auth_provider', 'otp_code', 'otp_expiry', 'otp_last_sent',
    ];
    $customerColumns = db_query('SHOW COLUMNS FROM customers');
    $customerColumnNames = array_map(
        static fn(array $column): string => (string)($column['Field'] ?? ''),
        $customerColumns
    );
    $missingCustomerColumns = array_values(array_diff($requiredCustomerColumns, $customerColumnNames));
    $result['checks']['database_connected'] = isset($conn) && $conn instanceof mysqli;
    $result['checks']['customers_table'] = !empty($customerColumns);
    $result['checks']['customer_schema_complete'] = $missingCustomerColumns === [];
    if ($missingCustomerColumns !== []) {
        $result['missing_customer_columns'] = $missingCustomerColumns;
    }

    $result['success'] = !in_array(false, $result['checks'], true);
    $result['message'] = $result['success']
        ? 'Registration dependencies and database schema loaded successfully.'
        : 'One or more registration dependencies or database checks failed.';
} catch (Throwable $exception) {
    $result['error'] = 'Registration dependency check failed.';
    error_log('[registration_diagnostic] ' . get_class($exception) . ': ' . $exception->getMessage());
}

http_response_code($result['success'] ? 200 : 500);
echo json_encode($result, JSON_UNESCAPED_SLASHES);
