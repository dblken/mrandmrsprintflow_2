<?php
/**
 * API: Add Walk-in Customer for POS
 * Path: staff/api/pos_add_customer.php
 */

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';

// Require staff or admin role
if (!has_role(['Admin', 'Staff'])) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Unauthorized access.']);
    exit;
}

header('Content-Type: application/json');

$json = file_get_contents('php://input');
$data = json_decode($json, true);

if (!$data || empty($data['first_name']) || empty($data['last_name']) || empty($data['email'])) {
    echo json_encode(['success' => false, 'message' => 'First name, last name, and email are required.']);
    exit;
}

$first_name = sanitize($data['first_name']);
$last_name = sanitize($data['last_name']);
$email = sanitize($data['email']);
$contact = !empty($data['contact_number']) ? sanitize($data['contact_number']) : null;

// Validate email format
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    echo json_encode(['success' => false, 'message' => 'Invalid email address format.']);
    exit;
}

try {
    // Check if email exists in customers table
    $existing_customer = db_query("SELECT customer_id FROM customers WHERE email = ?", 's', [$email]);
    if (!empty($existing_customer)) {
        echo json_encode(['success' => false, 'message' => 'This email address is already registered as a customer.']);
        exit;
    }
    
    // Check if email exists in users table
    $existing_user = db_query("SELECT user_id FROM users WHERE email = ?", 's', [$email]);
    if (!empty($existing_user)) {
        echo json_encode(['success' => false, 'message' => 'This email address is already registered in the system.']);
        exit;
    }
    
    // Check phone number if provided
    if ($contact && contact_phone_in_use_across_accounts($contact)) {
        echo json_encode(['success' => false, 'message' => 'This phone number is already in use on another account.']);
        exit;
    }

    // Generate password reset token
    $token = bin2hex(random_bytes(32));
    $token_expiry = date('Y-m-d H:i:s', strtotime('+24 hours'));
    
    // Generate temporary password hash (will be replaced when user sets password)
    $temp_password_hash = password_hash(bin2hex(random_bytes(16)), PASSWORD_BCRYPT);

    printflow_ensure_customers_auth_provider_column();

    $contactVal = ($contact !== null && $contact !== '') ? $contact : '';

    // Same account-creation guard as register_customer() (trigger + created_by_system)
    $result = printflow_run_guarded_account_insert(function () use (
        $first_name,
        $last_name,
        $email,
        $contactVal,
        $temp_password_hash
    ) {
        return db_execute(
            "INSERT INTO customers (first_name, last_name, email, contact_number, password_hash, status, auth_provider, created_by_system, created_at)
             VALUES (?, ?, ?, ?, ?, 'Activated', 'local', 1, NOW())",
            'sssss',
            [$first_name, $last_name, $email, $contactVal, $temp_password_hash]
        );
    });

    if (!$result) {
        $logDetail = '';
        if (!empty($GLOBALS['printflow_db_errors']) && is_array($GLOBALS['printflow_db_errors'])) {
            $lastDbErr = end($GLOBALS['printflow_db_errors']);
            if (is_array($lastDbErr)) {
                $stage = trim((string) ($lastDbErr['stage'] ?? ''));
                $errText = trim((string) ($lastDbErr['error'] ?? ''));
                $logDetail = $stage !== '' ? ($stage . ': ' . $errText) : $errText;
            }
        }
        if ($logDetail === '' && isset($GLOBALS['conn']) && $GLOBALS['conn'] instanceof mysqli && $GLOBALS['conn']->error) {
            $logDetail = trim((string) $GLOBALS['conn']->error);
        }
        if ($logDetail === '') {
            $logDetail = 'db_execute returned false';
        }
        error_log('[pos_add_customer] INSERT customers failed: ' . $logDetail);

        echo json_encode([
            'success' => false,
            'message' => 'Failed to create customer record. Please try again.',
        ]);
        exit;
    }

    if ($result) {
        global $conn;
        $customer_id = is_numeric($result) ? (int) $result : (int) ($conn->insert_id ?? 0);
        
        // Store password reset token
        try {
            // Check if password_resets table exists and what columns it has
            $token_result = db_execute(
                "INSERT INTO password_resets (email, token, expires_at, created_at) VALUES (?, ?, ?, NOW())",
                'sss',
                [$email, $token, $token_expiry]
            );
            
            if (!$token_result) {
                error_log('Password reset token insert returned false');
            }
        } catch (Exception $e) {
            // Log the specific error
            error_log('Password reset token creation failed: ' . $e->getMessage());
            // Don't fail the whole operation, customer is already created
        }
        
        // Send password setup email
        $token = base64_encode($customer_id);
        $reset_link = (defined('SITE_URL') ? SITE_URL : ((empty($_SERVER['HTTPS']) || $_SERVER['HTTPS'] === 'off' ? 'http' : 'https') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . (defined('BASE_PATH') ? BASE_PATH : '/printflow'))) . "/public/set-password.php?token=" . $token;
        
        $email_subject = "Welcome to PrintFlow - Set Your Password";
        $email_body = "
            <html>
            <head>
                <style>
                    body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
                    .container { max-width: 600px; margin: 0 auto; padding: 20px; }
                    .header { background: linear-gradient(135deg, #06A1A1 0%, #048888 100%); color: white; padding: 30px; text-align: center; border-radius: 10px 10px 0 0; }
                    .content { background: #f8fafc; padding: 30px; border-radius: 0 0 10px 10px; }
                    .button { display: inline-block; background: #4f46e5; color: white; padding: 14px 28px; text-decoration: none; border-radius: 8px; font-weight: bold; margin: 20px 0; }
                    .info-box { background: white; border-left: 4px solid #06A1A1; padding: 15px; margin: 20px 0; border-radius: 4px; }
                    .footer { text-align: center; margin-top: 20px; color: #64748b; font-size: 12px; }
                </style>
            </head>
            <body>
                <div class='container'>
                    <div class='header'>
                        <h1 style='margin: 0;'>Welcome to PrintFlow!</h1>
                    </div>
                    <div class='content'>
                        <h2 style='color: #1e293b;'>Hello {$first_name} {$last_name},</h2>
                        <p>Your customer account has been created by our staff at PrintFlow. To complete your registration and access your account, please set up your password using the link below.</p>
                        <p style='background: #fff3cd; border-left: 4px solid #ffc107; padding: 12px; border-radius: 4px; color: #856404;'><strong>Important:</strong> You must set your password using the link below before you can log in to your account.</p>
                        
                        <div class='info-box'>
                            <strong>Your Account Details:</strong><br>
                            Name: {$first_name} {$last_name}<br>
                            Email: {$email}
                        </div>
                        
                        <p>Click the button below to create your password:</p>
                        
                        <div style='text-align: center;'>
                            <a href='{$reset_link}' class='button' style='color: white !important; text-decoration: none;'>Set My Password</a>
                        </div>
                        
                        <p style='color: #64748b; font-size: 14px;'>Or copy and paste this link into your browser:<br>
                        <a href='{$reset_link}' style='color: #06A1A1; word-break: break-all;'>{$reset_link}</a></p>
                        
                        <p style='color: #ef4444; font-size: 13px;'><strong>Important:</strong> This link will expire in 24 hours for security reasons.</p>
                        
                        <p>Once you set your password, you'll be able to:</p>
                        <ul>
                            <li>Browse our products and services</li>
                            <li>Place orders online</li>
                            <li>Track your order status</li>
                            <li>View your order history</li>
                            <li>Upload custom designs</li>
                        </ul>
                        
                        <p>If you didn't request this account, please ignore this email or contact us.</p>
                    </div>
                    <div class='footer'>
                        <p>&copy; " . date('Y') . " PrintFlow. All rights reserved.</p>
                    </div>
                </div>
            </body>
            </html>
        ";
        
        echo json_encode([
            'success' => true,
            'customer_id' => $customer_id,
            'message' => 'Customer created successfully! A password setup email will be sent to ' . $email,
        ]);

        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
        } else {
            while (ob_get_level() > 0) {
                ob_end_flush();
            }
            flush();
        }

        $email_sent = send_email($email, $email_subject, $email_body);

        log_activity(
            (int) $_SESSION['user_id'],
            'Customer Created',
            "Created customer: {$first_name} {$last_name} (ID: {$customer_id}) via POS. Password setup email "
            . ($email_sent ? 'sent' : 'failed to send')
            . " to: {$email}"
        );
    }
} catch (Exception $e) {
    error_log('[pos_add_customer] Exception: ' . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => 'Failed to create customer record. Please try again.',
    ]);
}
