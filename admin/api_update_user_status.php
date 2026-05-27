<?php
/**
 * Admin Update User Status & Info API
 * PrintFlow - Printing Shop PWA
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/staff_access.php';

require_role(['Admin', 'Manager']);
// Ensure $base_path is defined
if (!isset($base_path)) {
    if (file_exists(__DIR__ . '/../config.php')) {
        require_once __DIR__ . '/../config.php';
    }
    $base_path = defined('BASE_PATH') ? BASE_PATH : '/printflow';
}
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { 
    echo json_encode(['success' => false, 'error' => 'Invalid method']); 
    exit; 
}

$data = json_decode(file_get_contents('php://input'), true);
if (!isset($data['csrf_token']) || $data['csrf_token'] !== ($_SESSION['csrf_token'] ?? '')) {
    echo json_encode(['success' => false, 'error' => 'Invalid CSRF token']); 
    exit;
}

$user_id = (int)($data['user_id'] ?? 0);
$action = $data['action'] ?? '';

if (!$user_id) {
    echo json_encode(['success' => false, 'error' => 'User ID is required']); 
    exit;
}

if ($action === 'toggle_status') {
    $row = db_query("SELECT status, role FROM users WHERE user_id = ?", 'i', [$user_id]);
    if (empty($row)) {
        echo json_encode(['success' => false, 'error' => 'User not found.']);
        exit;
    }
    $db_status = $row[0]['status'] ?? '';
    if (($row[0]['role'] ?? '') === 'Admin') {
        echo json_encode(['success' => false, 'error' => 'Administrator accounts remain activated and cannot be deactivated here.']);
        exit;
    }
    if ($db_status === 'Archived') {
        echo json_encode(['success' => false, 'error' => 'This account is archived. Restore it from Archived accounts first.']);
        exit;
    }
    if ($db_status === 'Pending') {
        echo json_encode(['success' => false, 'error' => 'Use Activate Account for pending users.']);
        exit;
    }
    if ($db_status !== 'Activated' && $db_status !== 'Deactivated') {
        echo json_encode(['success' => false, 'error' => 'Cannot change status for this account.']);
        exit;
    }

    $new_status = ($db_status === 'Activated') ? 'Deactivated' : 'Activated';

    // Prevent deactivating oneself
    if ($new_status === 'Deactivated' && $user_id === $_SESSION['user_id']) {
        echo json_encode(['success' => false, 'error' => 'Cannot deactivate your own account']);
        exit;
    }

    $ok = db_execute("UPDATE users SET status = ? WHERE user_id = ?", 'si', [$new_status, $user_id]);
    if ($ok) {
        echo json_encode(['success' => true, 'new_status' => $new_status, 'message' => "User account successfully {$new_status}."]);
    } else {
        echo json_encode(['success' => false, 'error' => 'Failed to update status.']);
    }
} elseif ($action === 'update_info') {
    $first_name     = sanitize($data['first_name'] ?? '');
    $middle_name    = sanitize($data['middle_name'] ?? '');
    $last_name      = sanitize($data['last_name'] ?? '');
    $contact_number = sanitize($data['contact_number'] ?? '');
    $address        = sanitize($data['address'] ?? '');
    $gender         = sanitize($data['gender'] ?? '');
    $birthday       = sanitize($data['dob'] ?? ''); // Maps from modal's 'dob' model
    $selected_role  = sanitize($data['role'] ?? '');
    $role_payload   = printflow_resolve_staff_role_payload($selected_role);
    $role           = sanitize($role_payload['role'] ?? '');
    $position       = $role_payload['position'] ?? null;
    $branch_id      = !empty($data['branch_id']) ? (int)$data['branch_id'] : null;
    
    if ($role === 'Admin') $branch_id = null;
    
    // Server-side validation
    $row = db_query("SELECT status FROM users WHERE user_id = ?", 'i', [$user_id]);
    if (empty($row)) {
        echo json_encode(['success' => false, 'error' => 'User not found.']);
        exit;
    }
    if (($row[0]['status'] ?? '') === 'Archived') {
        echo json_encode(['success' => false, 'error' => 'Restore this account from Archived before editing.']);
        exit;
    }

    $errors = [];
    
    // Names
    if (empty($first_name) || !preg_match("/^[A-Za-z]+( [A-Za-z]+)*$/", $first_name) || strlen($first_name) < 2 || strlen($first_name) > 50) {
        $errors[] = 'Invalid first name (2-50 letters only)';
    }
    if (empty($last_name) || !preg_match("/^[A-Za-z]+( [A-Za-z]+)*$/", $last_name) || strlen($last_name) < 2 || strlen($last_name) > 50) {
        $errors[] = 'Invalid last name (2-50 letters only)';
    }
    
    // Contact Number
    if (empty($contact_number) || !preg_match("/^09\d{9}$/", $contact_number)) {
        $errors[] = 'Invalid contact number (09XXXXXXXXX)';
    }
    
    // Address
    if (empty($address) || strlen($address) < 5 || strlen($address) > 200) {
        $errors[] = 'Invalid address (5-200 chars)';
    }

    // Birthday
    if (!empty($birthday)) {
        $bday_date = DateTime::createFromFormat('Y-m-d', $birthday);
        $bday_errors = DateTime::getLastErrors();
        if (!$bday_date || ($bday_errors['warning_count'] ?? 0) > 0 || ($bday_errors['error_count'] ?? 0) > 0) {
            $errors[] = 'Invalid birthday format';
        } else {
            $today = new DateTime();
            $age = $today->diff($bday_date)->y;
            if ($bday_date > $today) {
                $errors[] = 'Birthday cannot be a future date';
            } elseif ($age < 18) {
                $errors[] = 'User must be at least 18 years old';
            } elseif ($age > 70) {
                $errors[] = 'User must be 70 years old or younger';
            }
        }
    } else {
        $errors[] = 'Birthday is required';
    }
    
    if (!empty($errors)) {
        echo json_encode(['success' => false, 'error' => implode(', ', $errors)]); 
        exit;
    }

    if (contact_phone_in_use_across_accounts($contact_number, null, $user_id)) {
        echo json_encode(['success' => false, 'error' => 'This phone number is already used by another account.']);
        exit;
    }

    $status = in_array($data['status'] ?? '', ['Activated','Pending','Deactivated']) ? $data['status'] : 'Pending';
    if ($role === 'Admin') {
        $status = 'Activated';
    }

    $ok = db_execute(
        "UPDATE users SET first_name=?, middle_name=?, last_name=?, contact_number=?, address=?, gender=?, birthday=?, role=?, position=?, branch_id=?, status=? WHERE user_id=?",
        "sssssssssisi",
        [$first_name, $middle_name ?: '', $last_name, $contact_number ?: '', $address ?: '', $gender ?: '', $birthday ?: null, $role, $position, $branch_id, $status, $user_id]
    );
    
    if ($ok) {
        echo json_encode(['success' => true, 'message' => "User info updated successfully."]);
    } else {
        echo json_encode(['success' => false, 'error' => "Failed to update user information."]);
    }
} elseif ($action === 'activate_account') {
    $u = db_query("SELECT user_id, first_name, email, status FROM users WHERE user_id = ?", 'i', [$user_id]);
    if (empty($u)) {
        echo json_encode(['success' => false, 'error' => 'User not found.']);
        exit;
    }
    if (($u[0]['status'] ?? '') === 'Archived') {
        echo json_encode(['success' => false, 'error' => 'Restore this account from Archived before activating.']);
        exit;
    }
    $ok = db_execute("UPDATE users SET status = 'Activated', profile_completion_token = NULL, profile_completion_expires = NULL WHERE user_id = ?", 'i', [$user_id]);
    if ($ok) {
        if (!empty($u)) {
            require_once __DIR__ . '/../includes/profile_completion_mailer.php';
            send_account_activated_email($u[0]['email'], $u[0]['first_name']);
        }
        echo json_encode(['success' => true, 'message' => 'Account activated successfully. Staff has been notified via email.']);
    } else {
        echo json_encode(['success' => false, 'error' => 'Failed to activate account.']);
    }
} elseif ($action === 'archive_user') {
    if (($_SESSION['user_type'] ?? '') !== 'Admin') {
        echo json_encode(['success' => false, 'error' => 'Only administrators can archive team accounts.']);
        exit;
    }

    if ($user_id === (int)($_SESSION['user_id'] ?? 0)) {
        echo json_encode(['success' => false, 'error' => 'Cannot archive your own account.']);
        exit;
    }

    $u = db_query("SELECT user_id, role, status FROM users WHERE user_id = ?", 'i', [$user_id]);
    if (empty($u)) {
        echo json_encode(['success' => false, 'error' => 'User not found.']);
        exit;
    }
    if (($u[0]['role'] ?? '') === 'Admin') {
        echo json_encode(['success' => false, 'error' => 'Administrator accounts cannot be archived.']);
        exit;
    }
    if (($u[0]['status'] ?? '') !== 'Deactivated') {
        echo json_encode(['success' => false, 'error' => 'Only deactivated accounts can be archived.']);
        exit;
    }

    $ok = db_execute("UPDATE users SET status = 'Archived', updated_at = NOW() WHERE user_id = ?", 'i', [$user_id]);
    if ($ok) {
        echo json_encode(['success' => true, 'message' => 'Account archived successfully.']);
    } else {
        echo json_encode(['success' => false, 'error' => 'Failed to archive account.']);
    }
} elseif ($action === 'restore_archived_user') {
    if (($_SESSION['user_type'] ?? '') !== 'Admin') {
        echo json_encode(['success' => false, 'error' => 'Only administrators can restore archived accounts.']);
        exit;
    }

    $u = db_query("SELECT status FROM users WHERE user_id = ?", 'i', [$user_id]);
    if (empty($u)) {
        echo json_encode(['success' => false, 'error' => 'User not found.']);
        exit;
    }
    if (($u[0]['status'] ?? '') !== 'Archived') {
        echo json_encode(['success' => false, 'error' => 'This account is not archived.']);
        exit;
    }

    $ok = db_execute("UPDATE users SET status = 'Deactivated', updated_at = NOW() WHERE user_id = ?", 'i', [$user_id]);
    if ($ok) {
        echo json_encode(['success' => true, 'message' => 'Account restored. It is deactivated until you activate it.']);
    } else {
        echo json_encode(['success' => false, 'error' => 'Failed to restore account.']);
    }
} elseif ($action === 'delete_user') {
    echo json_encode(['success' => false, 'error' => 'Deleting team accounts from this screen has been disabled.']);
    exit;
} elseif ($action === 'resend_completion_link') {
    $u = db_query("SELECT user_id, first_name, email FROM users WHERE user_id = ?", 'i', [$user_id]);
    if (empty($u)) {
        echo json_encode(['success' => false, 'error' => 'User not found.']);
        exit;
    }
    $u = $u[0];

    $admin_notes = [];
    if (!empty($data['admin_notes']) && is_array($data['admin_notes'])) {
        $admin_notes = array_values(array_filter(array_map('trim', $data['admin_notes'])));
    }
    if (empty($admin_notes)) {
        echo json_encode(['success' => false, 'error' => 'Please select at least one item before sending the link.']);
        exit;
    }

    $token = bin2hex(random_bytes(32));
    $expires = date('Y-m-d H:i:s', strtotime('+7 days'));
    
    // Store which fields to clear as JSON
    $fields_to_clear = [];
    foreach ($admin_notes as $note) {
        if (stripos($note, 'Address') !== false) {
            $fields_to_clear[] = 'address';
        } elseif (stripos($note, 'ID Image') !== false || stripos($note, 'ID') !== false) {
            $fields_to_clear[] = 'id_image';
        } elseif (stripos($note, 'Contact') !== false) {
            $fields_to_clear[] = 'contact';
        }
    }
    
    $fields_json = !empty($fields_to_clear) ? json_encode($fields_to_clear) : null;
    
    db_execute(
        "UPDATE users SET profile_completion_token = ?, profile_completion_expires = ?, profile_completion_fields_to_clear = ?, status = 'Pending' WHERE user_id = ?", 
        'sssi', 
        [$token, $expires, $fields_json, $user_id]
    );

    $site_url = defined('SITE_URL')
        ? SITE_URL
        : (((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . (defined('BASE_PATH') ? BASE_PATH : '/printflow'));
    $complete_link = rtrim($site_url, '/') . '/public/complete_profile.php?token=' . urlencode($token);

    require_once __DIR__ . '/../includes/profile_completion_mailer.php';
    $mail_res = send_profile_completion_resend_email($u['email'], $u['first_name'], $complete_link, $admin_notes);

    if ($mail_res['success']) {
        echo json_encode(['success' => true, 'message' => 'Profile completion link sent to ' . $u['email']]);
    } else {
        echo json_encode(['success' => true, 'message' => 'Link generated. Email failed: ' . ($mail_res['message'] ?? '') . '. Share manually: ' . $complete_link]);
    }
} else {
    echo json_encode(['success' => false, 'error' => 'Invalid action']);
}
