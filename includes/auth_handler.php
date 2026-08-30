<?php
/**
 * Auth Handler
 * Unified handler for Login and Registration POST requests.
 * This file is internal and should be accessed via routed clean URLs (/login, /register).
 */

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/functions.php';

$action = $_GET['action'] ?? '';

function auth_terms_agreement_accepted($value): bool {
    return in_array((string)$value, ['1', 'on', 'true', 'yes'], true);
}
// 1. Handle GET requests (Redirect to home with modal)
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $error_param = isset($_GET['error']) ? '&error=' . urlencode($_GET['error']) : '';
    $timeout_param = isset($_GET['timeout']) ? '&timeout=1' : '';
    $modal_type = ($action === 'register') ? 'register' : 'login';
    
    redirect(AUTH_REDIRECT_BASE . '/?auth_modal=' . $modal_type . $error_param . $timeout_param);
    exit;
}

// 2. Handle POST requests
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $is_ajax = (
            (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower((string) $_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') ||
            (isset($_SERVER['HTTP_ACCEPT']) && strpos((string) $_SERVER['HTTP_ACCEPT'], 'application/json') !== false)
        );

        if ($is_ajax) {
            header('Content-Type: application/json');
            http_response_code(403);
            echo json_encode([
                'success' => false,
                'code' => 'csrf_mismatch',
                'message' => 'Your session was refreshed. Please try again.',
                'csrf_token' => generate_csrf_token(),
            ]);
            exit;
        }

        $error = 'Invalid request. Please try again.';
        handle_error($error, $action);
    }

    if ($action === 'login') {
        handle_login();
    } elseif ($action === 'register') {
        handle_register();
    } else {
        redirect(AUTH_REDIRECT_BASE . '/');
    }
}

/**
 * Handle Login POST
 */
function handle_login() {
    $is_ajax = (
        (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower((string) $_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') ||
        (isset($_SERVER['HTTP_ACCEPT']) && strpos((string) $_SERVER['HTTP_ACCEPT'], 'application/json') !== false)
    );
    $field_errors = ['email' => '', 'password' => ''];
    $error = '';

    $email = sanitize($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $remember_me = isset($_POST['remember_me']) && $_POST['remember_me'] === '1';

    if (empty($email)) {
        $field_errors['email'] = 'Email is required.';
        $error = 'Please enter your email.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $field_errors['email'] = 'Please enter a valid email address.';
        $error = 'Invalid email format.';
    }

    if (empty($password)) {
        $field_errors['password'] = 'Password is required.';
        if (empty($error)) {
            $error = 'Please enter your password.';
        }
    }

    if (!$error) {
        $result = login($email, $password, $remember_me);
        if ($result['success']) {
            if ($is_ajax) {
                header('Content-Type: application/json');
                echo json_encode(['success' => true, 'redirect' => $result['redirect']]);
                exit;
            }
            redirect($result['redirect']);
        } else {
            $error = $result['message'];
            $field_errors['password'] = $result['message'];
        }
    }

    if ($is_ajax) {
        header('Content-Type: application/json');
        echo json_encode([
            'success' => false,
            'message' => $error ?: 'Login failed.',
            'field_errors' => $field_errors,
            'code' => $result['code'] ?? null,
            'lockout_remaining_seconds' => isset($result['lockout_remaining_seconds']) ? (int)$result['lockout_remaining_seconds'] : null,
            'lockout_level' => isset($result['lockout_level']) ? (int)$result['lockout_level'] : null,
        ]);
        exit;
    }

    handle_error($error, 'login');
}

/**
 * Handle Register POST
 */
function handle_register() {
    // Clear old OTP data to prevent "sticky" email from previous attempts
    unset($_SESSION['otp_pending_email']);
    unset($_SESSION['otp_user_type']);
    
    $error = '';
    $reg_type = $_POST['reg_type'] ?? 'user'; 

    if ($reg_type === 'direct' || $reg_type === 'legacy') {
        if ($reg_type === 'direct') {
            $identifier_type = sanitize($_POST['identifier_type'] ?? '');
            $identifier      = sanitize($_POST['identifier'] ?? '');
            $password        = $_POST['password'] ?? '';
            $confirm_password = $_POST['confirm_password'] ?? '';

            if (!auth_terms_agreement_accepted($_POST['terms_agreement'] ?? '')) {
                $error = 'Please agree to the Terms of Service and Privacy Policy before creating your account.';
            } elseif (empty($identifier_type) || empty($identifier) || empty($password)) {
                $error = 'Please fill in all fields.';
            } elseif (strlen($password) < 8) {
                $error = 'Password must be at least 8 characters.';
            } elseif ($password !== $confirm_password) {
                $error = 'Passwords do not match.';
            } else {
                $result = register_customer_direct($identifier_type, $identifier, $password, date('Y-m-d H:i:s'), PRINTFLOW_TERMS_VERSION);
                if ($result['success']) {
                    $_SESSION['otp_pending_email'] = ($identifier_type === 'email') ? $identifier : ($identifier . '@phone.local');
                    $_SESSION['otp_user_type'] = 'Customer';
                    redirect(AUTH_REDIRECT_BASE . '/public/verify_email.php');
                } else {
                    $error = $result['message'];
                }
            }
        } else {
            $error = "Registration type not supported here.";
        }
    } else {
        $error = "Staff registration is not allowed via this public handler.";
    }

    handle_error($error, 'register');
}

/**
 * Handle Error Redirects
 */
function handle_error($error, $modal_type) {
    $return_path = AUTH_REDIRECT_BASE . '/';
    if (!empty($_SERVER['HTTP_REFERER'])) {
        $ref = $_SERVER['HTTP_REFERER'];
        if (strpos($ref, AUTH_REDIRECT_BASE . '/') !== false) {
            $parsed = parse_url($ref);
            $return_path = isset($parsed['path']) ? $parsed['path'] : $return_path;
        }
    }
    $sep = (strpos($return_path, '?') !== false) ? '&' : '?';
    redirect($return_path . $sep . 'auth_modal=' . $modal_type . '&error=' . urlencode($error));
}
