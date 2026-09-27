<?php
/**
 * API for Customer Profile Modal
 * PrintFlow - Printing Shop PWA
 */

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

require_role('Customer');

header('Content-Type: application/json');

$customer_id = get_user_id();

if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['action']) && $_GET['action'] === 'get') {
    $customer = db_query("SELECT customer_id, first_name, last_name, email, contact_number, gender, created_at FROM customers WHERE customer_id = ?", 'i', [$customer_id])[0];
    echo json_encode(['success' => true, 'customer' => $customer]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token((string)($_POST['csrf_token'] ?? ''))) {
        http_response_code(419);
        echo json_encode(['success' => false, 'error' => 'Your session expired. Please refresh and try again.']);
        exit;
    }
    $action = $_POST['action'] ?? '';

    if ($action === 'update_profile') {
        $first_name = trim((string)($_POST['first_name'] ?? ''));
        $last_name = trim((string)($_POST['last_name'] ?? ''));
        $contact_number = trim((string)($_POST['contact_number'] ?? ''));
        $gender = trim((string)($_POST['gender'] ?? ''));

        if (empty($first_name) || empty($last_name)) {
            echo json_encode(['success' => false, 'error' => 'First and last name are required']);
            exit;
        }

        if ($contact_number !== '' && contact_phone_in_use_across_accounts($contact_number, $customer_id, null)) {
            echo json_encode(['success' => false, 'error' => 'This phone number is already used by another account.']);
            exit;
        }

        $result = db_execute("UPDATE customers SET first_name = ?, last_name = ?, contact_number = ?, gender = ? WHERE customer_id = ?",
            'ssssi', [$first_name, $last_name, $contact_number, $gender, $customer_id]);
        
        if ($result) {
            $_SESSION['user_name'] = $first_name . ' ' . $last_name;
            echo json_encode(['success' => true]);
        } else {
            echo json_encode(['success' => false, 'error' => 'Database error']);
        }
        exit;
    }

    if ($action === 'change_password') {
        $current = $_POST['current_password'] ?? '';
        $new = $_POST['new_password'] ?? '';
        
        $customer = db_query("SELECT password_hash FROM customers WHERE customer_id = ?", 'i', [$customer_id])[0];

        if (!password_verify($current, $customer['password_hash'])) {
            echo json_encode(['success' => false, 'error' => 'Current password is incorrect']);
            exit;
        }

        if (strlen($new) < 8) {
            echo json_encode(['success' => false, 'error' => 'New password must be at least 8 characters']);
            exit;
        }

        $hash = password_hash($new, PASSWORD_BCRYPT);
        $result = db_execute("UPDATE customers SET password_hash = ? WHERE customer_id = ?", 'si', [$hash, $customer_id]);
        
        if ($result) {
            echo json_encode(['success' => true]);
        } else {
            echo json_encode(['success' => false, 'error' => 'Database error']);
        }
        exit;
    }
}

echo json_encode(['success' => false, 'error' => 'Invalid action']);
exit;

