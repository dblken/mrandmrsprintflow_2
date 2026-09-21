<?php
/**
 * Admin Profile Page
 * PrintFlow - Printing Shop PWA
 */

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

require_role(['Admin', 'Manager']);
// Ensure $base_path is defined
if (!isset($base_path)) {
    if (file_exists(__DIR__ . '/../config.php')) {
        require_once __DIR__ . '/../config.php';
    }
    $base_path = defined('BASE_PATH') ? BASE_PATH : '/printflow';
}

$current_user = get_logged_in_user();

// Ensure profile columns exist (safe migration)
try {
    db_execute("ALTER TABLE users ADD COLUMN IF NOT EXISTS middle_name VARCHAR(100) NULL AFTER first_name");
    db_execute("ALTER TABLE users ADD COLUMN IF NOT EXISTS birthday DATE NULL AFTER last_name");
} catch (Throwable $e) { /* ignore */ }

if (isset($_GET['address_action'])) {
    header('Content-Type: application/json');

    $fetchJson = static function (string $url): array {
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_CONNECTTIMEOUT => 8,
                CURLOPT_TIMEOUT => 20,
                CURLOPT_HTTPHEADER => ['Accept: application/json']
            ]);
            $body = curl_exec($ch);
            $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $err = curl_error($ch);
            curl_close($ch);
            if ($body === false || $httpCode >= 400) {
                throw new RuntimeException($err ?: ('Address data request failed (' . $httpCode . ')'));
            }
        } else {
            $context = stream_context_create([
                'http' => [
                    'method' => 'GET',
                    'header' => "Accept: application/json\r\n",
                    'timeout' => 20,
                ]
            ]);
            $body = @file_get_contents($url, false, $context);
            if ($body === false) {
                throw new RuntimeException('Unable to fetch address data.');
            }
        }

        $decoded = json_decode($body, true);
        if (!is_array($decoded)) {
            throw new RuntimeException('Invalid address dataset response.');
        }
        return $decoded;
    };

    try {
        $base = 'https://psgc.gitlab.io/api';
        $action = $_GET['address_action'] ?? '';

        if ($action === 'provinces') {
            $rows = $fetchJson($base . '/provinces/');
            $data = array_map(static fn($r) => [
                'code' => (string)($r['code'] ?? ''),
                'name' => (string)($r['name'] ?? ''),
            ], $rows);
            usort($data, static fn($a, $b) => strcasecmp($a['name'], $b['name']));
            echo json_encode(['success' => true, 'data' => $data]);
            exit;
        }

        if ($action === 'cities') {
            $provinceCode = preg_replace('/[^0-9]/', '', (string)($_GET['province_code'] ?? ''));
            if ($provinceCode === '') {
                throw new RuntimeException('Province code is required.');
            }
            $rows = $fetchJson($base . '/provinces/' . rawurlencode($provinceCode) . '/cities-municipalities/');
            $data = array_map(static fn($r) => [
                'code' => (string)($r['code'] ?? ''),
                'name' => (string)($r['name'] ?? ''),
            ], $rows);
            usort($data, static fn($a, $b) => strcasecmp($a['name'], $b['name']));
            echo json_encode(['success' => true, 'data' => $data]);
            exit;
        }

        if ($action === 'barangays') {
            $cityCode = preg_replace('/[^0-9]/', '', (string)($_GET['city_code'] ?? ''));
            if ($cityCode === '') {
                throw new RuntimeException('City/Municipality code is required.');
            }
            $rows = $fetchJson($base . '/cities-municipalities/' . rawurlencode($cityCode) . '/barangays/');
            $data = array_map(static fn($r) => [
                'code' => (string)($r['code'] ?? ''),
                'name' => (string)($r['name'] ?? ''),
            ], $rows);
            usort($data, static fn($a, $b) => strcasecmp($a['name'], $b['name']));
            echo json_encode(['success' => true, 'data' => $data]);
            exit;
        }

        throw new RuntimeException('Invalid address action.');
    } catch (Throwable $e) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        exit;
    }
}

$admin_id = get_user_id();
$error = '';
$success = '';
if (!empty($_SESSION['admin_profile_success'])) {
    $success = (string)$_SESSION['admin_profile_success'];
    unset($_SESSION['admin_profile_success']);
}
if (!empty($_SESSION['admin_profile_error'])) {
    $error = (string)$_SESSION['admin_profile_error'];
    unset($_SESSION['admin_profile_error']);
}

// Get admin data
$admin = db_query("SELECT * FROM users WHERE user_id = ?", 'i', [$admin_id])[0];

$addressCountry = 'Philippines';
$addressProvince = '';
$addressCity = '';
$addressBarangay = '';
$addressLine = '';
$maxBirthday = date('Y-m-d', strtotime('-18 years'));
$existingAddress = trim((string)($admin['address'] ?? ''));

if ($existingAddress !== '') {
    $parts = array_values(array_filter(array_map('trim', explode(',', $existingAddress)), static fn($p) => $p !== ''));
    if (count($parts) >= 4 && strcasecmp(end($parts), 'Philippines') === 0) {
        $addressCountry = 'Philippines';
        $addressProvince = $parts[count($parts) - 2] ?? '';
        $addressCity = $parts[count($parts) - 3] ?? '';
        $addressBarangay = preg_replace('/^Brgy\.?\s*/i', '', (string)($parts[count($parts) - 4] ?? ''));
        $addressLine = implode(', ', array_slice($parts, 0, -4));
    } else {
        $addressLine = $existingAddress;
    }
}

// Handle profile picture upload
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['upload_picture']) && verify_csrf_token($_POST['csrf_token'] ?? '')) {
    if (isset($_FILES['profile_picture']) && $_FILES['profile_picture']['error'] === UPLOAD_ERR_OK) {
        $allowed_types = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
        $allowed_extensions = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
        $max_size = 5 * 1024 * 1024; // 5MB
        
        $file = $_FILES['profile_picture'];
        $file_ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        
        // Check file extension first
        if (!in_array($file_ext, $allowed_extensions)) {
            $_SESSION['admin_profile_error'] = 'Invalid file type. Only JPG, PNG, GIF, and WEBP images are allowed.';
        } 
        // Check MIME type
        elseif (!in_array($file['type'], $allowed_types)) {
            $_SESSION['admin_profile_error'] = 'Invalid file type. Only image files are allowed. Videos are not permitted.';
        } 
        // Check file size
        elseif ($file['size'] > $max_size) {
            $size_mb = round($file['size'] / (1024 * 1024), 2);
            $_SESSION['admin_profile_error'] = "File too large ({$size_mb}MB). Maximum size is 5MB.";
        } else {
            $ext = pathinfo($file['name'], PATHINFO_EXTENSION);
            $filename = 'admin_' . $admin_id . '_' . time() . '.' . $ext;
            $upload_dir = __DIR__ . '/../public/assets/uploads/profiles/';
            
            // Create directory if it doesn't exist
            if (!is_dir($upload_dir)) {
                mkdir($upload_dir, 0755, true);
            }
            
            $filepath = $upload_dir . $filename;
            
            // Delete old picture if exists
            if (!empty($admin['profile_picture'])) {
                $old_file = $upload_dir . $admin['profile_picture'];
                if (file_exists($old_file)) {
                    unlink($old_file);
                }
            }
            
            if (move_uploaded_file($file['tmp_name'], $filepath)) {
                db_execute("UPDATE users SET profile_picture = ?, updated_at = NOW() WHERE user_id = ?", 'si', [$filename, $admin_id]);
                $_SESSION['admin_profile_success'] = 'Profile picture updated successfully!';
            } else {
                $_SESSION['admin_profile_error'] = 'Failed to upload file. Please try again.';
            }
        }
    } elseif (isset($_FILES['profile_picture'])) {
        // Handle upload errors
        $error_code = $_FILES['profile_picture']['error'];
        if ($error_code === UPLOAD_ERR_INI_SIZE || $error_code === UPLOAD_ERR_FORM_SIZE) {
            $_SESSION['admin_profile_error'] = 'File too large. Maximum size is 5MB.';
        } elseif ($error_code === UPLOAD_ERR_NO_FILE) {
            $_SESSION['admin_profile_error'] = 'Please select a file to upload.';
        } else {
            $_SESSION['admin_profile_error'] = 'Upload failed. Please try again.';
        }
    } else {
        $_SESSION['admin_profile_error'] = 'Please select a file to upload.';
    }
    header('Location: profile.php', true, 303);
    exit;
}

// Handle remove picture
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['remove_picture']) && verify_csrf_token($_POST['csrf_token'] ?? '')) {
    if (!empty($admin['profile_picture'])) {
        $upload_dir = __DIR__ . '/../public/assets/uploads/profiles/';
        $old_file = $upload_dir . $admin['profile_picture'];
        if (file_exists($old_file)) {
            unlink($old_file);
        }
        db_execute("UPDATE users SET profile_picture = NULL, updated_at = NOW() WHERE user_id = ?", 'i', [$admin_id]);
        $_SESSION['admin_profile_success'] = 'Profile picture removed.';
    }
    header('Location: profile.php', true, 303);
    exit;
}

// Handle profile update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_profile']) && verify_csrf_token($_POST['csrf_token'] ?? '')) {
    $error = '';
    $first_name = trim($_POST['first_name'] ?? '');
    $middle_name = trim($_POST['middle_name'] ?? '');
    $last_name = trim($_POST['last_name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $birthday = trim($_POST['birthday'] ?? '');
    $contact_number = trim($_POST['contact_number'] ?? '');
    $addressCountry = 'Philippines';
    $addressProvince = trim((string)($_POST['address_province'] ?? ''));
    $addressCity = trim((string)($_POST['address_city'] ?? ''));
    $addressBarangay = trim((string)($_POST['address_barangay'] ?? ''));
    $addressLine = trim((string)($_POST['address_line'] ?? ''));

    $addressParts = [];
    if ($addressLine !== '') {
        $addressParts[] = $addressLine;
    }
    if ($addressBarangay !== '') {
        $addressParts[] = 'Brgy. ' . $addressBarangay;
    }
    if ($addressCity !== '') {
        $addressParts[] = $addressCity;
    }
    if ($addressProvince !== '') {
        $addressParts[] = $addressProvince;
    }
    $addressParts[] = $addressCountry;
    $address = implode(', ', $addressParts);
    
    // Server-side validation
    if (empty($first_name) || empty($last_name)) {
        $error = 'First and last names are required.';
    } elseif (!preg_match("/^[A-Za-z]+( [A-Za-z]+)*$/", $first_name) || !preg_match("/^[A-Za-z]+( [A-Za-z]+)*$/", $last_name)) {
        $error = 'Names must contain only letters.';
    } elseif ($middle_name !== '' && !preg_match("/^[A-Za-z]+( [A-Za-z]+)*$/", $middle_name)) {
        $error = 'Middle name must contain only letters.';
    } elseif (strlen($first_name) < 2 || strlen($first_name) > 50 || strlen($last_name) < 2 || strlen($last_name) > 50) {
        $error = 'Names must be between 2 and 50 characters.';
    } elseif ($middle_name !== '' && (strlen($middle_name) < 2 || strlen($middle_name) > 50)) {
        $error = 'Middle name must be between 2 and 50 characters.';
    } elseif (empty($email)) {
        $error = 'Email is required.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Please enter a valid email address.';
    } elseif (!preg_match('/^[^\s@]+@[^\s@]+\.[a-zA-Z]{2,}$/', $email)) {
        $error = 'Email domain extension must be at least 2 characters (e.g., .com, .org).';
    } else {
        // Check if email is already taken by another user
        $existing = db_query("SELECT user_id FROM users WHERE email = ? AND user_id != ?", 'si', [$email, $admin_id]);
        if (!empty($existing)) {
            $error = 'This email is already registered to another account.';
        }
    }
    
    if (!$error && $birthday === '') {
        $error = 'Birthday is required.';
    } elseif (!$error && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $birthday)) {
        $error = 'Invalid birthday format.';
    } elseif (!$error) {
        try {
            $today = new DateTime('today');
            $birthDate = new DateTime($birthday);
            $age = $today->diff($birthDate)->y;
            if ($birthDate > $today) {
                $error = 'Birthday cannot be a future date.';
            } elseif ($age < 18) {
                $error = 'You must be at least 18 years old.';
            }
        } catch (Throwable $e) {
            $error = 'Invalid birthday value.';
        }
    }

    if (!$error && !preg_match("/^09\d{9}$/", $contact_number)) {
        $error = 'Contact number must be exactly 11 digits and start with 09.';
    } elseif (!$error && ($addressProvince === '' || $addressCity === '' || $addressBarangay === '')) {
        $error = 'Please select Province, City/Municipality, and Barangay.';
    } elseif (!$error && (strlen($address) < 5 || strlen($address) > 200)) {
        $error = 'Address must be between 5 and 200 characters.';
    }

    if (!$error) {
        // Auto-capitalize first letter
        $first_name = ucfirst($first_name);
        $middle_name = $middle_name !== '' ? ucfirst($middle_name) : '';
        $last_name = ucfirst($last_name);

        db_execute("UPDATE users SET first_name = ?, middle_name = ?, last_name = ?, email = ?, birthday = ?, contact_number = ?, address = ?, updated_at = NOW() WHERE user_id = ?",
            'sssssssi', [$first_name, $middle_name, $last_name, $email, $birthday, $contact_number, $address, $admin_id]);

        $_SESSION['user_name'] = trim($first_name . ' ' . $last_name);
        $_SESSION['user_email'] = $email;
        $_SESSION['admin_profile_success'] = 'Personal information updated successfully!';
    } else {
        $_SESSION['admin_profile_error'] = $error;
    }
    header('Location: profile.php', true, 303);
    exit;
}

// Handle password change
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['change_password']) && verify_csrf_token($_POST['csrf_token'] ?? '')) {
    $current_password = $_POST['current_password'] ?? '';
    $new_password = $_POST['new_password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';

    $pw_error = '';
    if (empty($current_password) || empty($new_password)) {
        $pw_error = 'All password fields are required.';
    } elseif (!password_verify($current_password, $admin['password_hash'])) {
        $pw_error = 'Current password is incorrect.';
    } elseif (strlen($new_password) < 8 || strlen($new_password) > 100 || !preg_match('/[A-Z]/', $new_password) || !preg_match('/[a-z]/', $new_password) || !preg_match('/[0-9]/', $new_password) || !preg_match('/[^A-Za-z0-9]/', $new_password) || strpos($new_password, ' ') !== false) {
        $pw_error = 'Password must contain at least 8 and at most 100 characters, uppercase, lowercase, number, special character, and no spaces.';
    } elseif ($new_password !== $confirm_password) {
        $pw_error = 'New passwords do not match.';
    } else {
        $password_hash = password_hash($new_password, PASSWORD_BCRYPT);
        db_execute("UPDATE users SET password_hash = ?, updated_at = NOW() WHERE user_id = ?", 'si', [$password_hash, $admin_id]);
        $_SESSION['admin_profile_success'] = 'Password updated successfully!';
    }
    if ($pw_error !== '') {
        $_SESSION['admin_profile_error'] = $pw_error;
    }
    header('Location: profile.php', true, 303);
    exit;
}

$user_initial = strtoupper(substr($admin['first_name'], 0, 1));
$profile_pic_url = !empty($admin['profile_picture']) 
    ? $base_path . '/public/assets/uploads/profiles/' . $admin['profile_picture']
    : '';

$page_title = 'My Profile - PrintFlow Admin';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $page_title; ?></title>
    <link rel="stylesheet" href="<?php echo $base_path; ?>/public/assets/css/output.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/cropperjs/1.6.1/cropper.min.css">
    <?php include __DIR__ . '/../includes/admin_style.php'; ?>
    <style>
        .profile-hero {
            background: linear-gradient(90deg, #00232b, #53C5E0);
            border-radius: 16px;
            padding: 40px 32px;
            margin-bottom: 24px;
            display: flex;
            align-items: center;
            gap: 28px;
            position: relative;
            overflow: hidden;
        }
        .profile-hero::before {
            content: '';
            position: absolute;
            top: -50%;
            right: -20%;
            width: 300px;
            height: 300px;
            border-radius: 50%;
            background: rgba(255,255,255,0.08);
        }
        .profile-avatar-wrapper {
            position: relative;
            flex-shrink: 0;
        }
        .profile-avatar {
            width: 100px;
            height: 100px;
            border-radius: 50%;
            border: 4px solid rgba(255,255,255,0.3);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 36px;
            font-weight: 700;
            color: white;
            background: rgba(255,255,255,0.15);
            overflow: hidden;
        }
        .profile-avatar img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }
        .avatar-edit-btn {
            position: absolute;
            bottom: 2px;
            right: 2px;
            width: 32px;
            height: 32px;
            border-radius: 50%;
            background: white;
            border: 2px solid #00232b;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            color: #00232b;
            transition: all 0.2s;
            box-shadow: 0 2px 8px rgba(0,0,0,0.15);
        }
        .avatar-edit-btn:hover {
            background: linear-gradient(135deg, #f0fdfa 0%, #e0f2f4 50%, #d8eef2 100%);
            color: #00232b;
            border-color: #53C5E0;
        }
        .profile-hero-info h2 {
            color: white;
            font-size: 24px;
            font-weight: 700;
            margin: 0 0 4px;
        }
        .profile-hero-info p {
            color: rgba(255,255,255,0.8);
            font-size: 14px;
            margin: 0;
        }
        .profile-hero-info .role-badge {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 4px 12px;
            background: rgba(255,255,255,0.2);
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            color: white;
            margin-top: 8px;
        }
        /* Fill row next to fixed sidebar; avoids empty strip on the right (broken <head> markup previously confused layout). */
        .dashboard-container > .main-content {
            flex: 1 1 auto;
            min-width: 0;
            max-width: 100%;
        }

        .section-card {
            background: white;
            border: 1px solid #f3f4f6;
            border-radius: 12px;
            padding: 24px;
            margin-bottom: 20px;
        }
        .section-title {
            font-size: 15px;
            font-weight: 700;
            color: #1f2937;
            margin: 0 0 20px;
            padding-bottom: 12px;
            border-bottom: 1px solid #f3f4f6;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .section-title svg { color: #6b7280; }
        .form-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 16px;
        }
        @media (max-width: 768px) {
            .form-grid { grid-template-columns: 1fr; }
            .profile-hero {
                flex-direction: column;
                text-align: center;
                padding: 28px 18px;
                gap: 18px;
                border-radius: 12px;
            }
            .profile-avatar {
                width: 86px;
                height: 86px;
                font-size: 30px;
            }
            .profile-hero-info {
                min-width: 0;
                width: 100%;
            }
            .profile-hero-info h2,
            .profile-hero-info p {
                overflow-wrap: anywhere;
            }
            .profile-columns {
                grid-template-columns: 1fr !important;
                gap: 14px !important;
            }
            .section-card {
                padding: 18px;
                border-radius: 10px;
            }
            .profile-form-actions {
                justify-content: stretch;
            }
            .profile-form-actions .btn-save,
            .btn-danger-outline {
                width: 100%;
                justify-content: center;
            }
            .upload-modal {
                max-width: calc(100vw - 24px);
                max-height: calc(100dvh - 48px);
                overflow-y: auto;
                padding: 18px;
                border-radius: 10px;
            }
        }
        .form-group { margin-bottom: 16px; }
        .form-group label {
            display: block;
            font-size: 13px;
            font-weight: 600;
            color: #374151;
            margin-bottom: 6px;
        }
        .form-group input, .form-group textarea, .form-group select {
            width: 100%;
            padding: 10px 14px;
            border: 1px solid #e5e7eb;
            border-radius: 8px;
            font-size: 14px;
            transition: border-color 0.2s;
            background: #fff;
        }
        .form-group input:focus, .form-group textarea:focus, .form-group select:focus {
            outline: none;
            border-color: #53C5E0;
            box-shadow: 0 0 0 3px rgba(83, 197, 224, 0.2);
        }
        .form-group input:disabled {
            background: #f9fafb;
            color: #9ca3af;
        }
        .profile-form-actions {
            display: flex;
            justify-content: flex-end;
            flex-wrap: wrap;
            gap: 8px;
            width: 100%;
            margin-top: 8px;
        }
        .btn-save {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 10px 20px;
            background: #00232b;
            color: white;
            border: none;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            transition: background 0.15s, color 0.15s, transform 0.2s, box-shadow 0.2s;
        }
        .btn-save:hover {
            background: #0a3d4d;
            transform: translateY(-1px);
            box-shadow: 0 4px 14px rgba(0, 35, 43, 0.25);
        }
        .btn-save:active {
            background: linear-gradient(135deg, #f0fdfa 0%, #e0f2f4 50%, #d8eef2 100%);
            color: #00232b;
            transform: translateY(0);
            box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.85);
        }
        .btn-danger-outline {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 10px 20px;
            background: transparent;
            color: #ef4444;
            border: 1px solid #ef4444;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.2s;
        }
        .btn-danger-outline:hover { background: #fef2f2; }
        .info-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(180px, 1fr));
            gap: 16px;
        }
        .info-item label {
            font-size: 11px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: #9ca3af;
            display: block;
            margin-bottom: 4px;
        }
        .info-item span {
            font-size: 14px;
            font-weight: 600;
            color: #1f2937;
        }
        .alert {
            padding: 12px 16px;
            border-radius: 8px;
            font-size: 14px;
            margin-bottom: 16px;
        }
        .alert-success { background: #ecfdf5; color: #065f46; border: 1px solid #a7f3d0; }
        .alert-error { background: #fef2f2; color: #991b1b; border: 1px solid #fecaca; }
        
        /* Mobile Header */
        .mobile-header { display: none; }
        @media (max-width: 768px) {
            .mobile-header { display: flex; position: fixed; top: 0; left: 0; right: 0; height: 60px; background: #fff; z-index: 60; padding: 0 20px; align-items: center; justify-content: space-between; border-bottom: 1px solid #e5e7eb; }
            .mobile-menu-btn { font-size: 24px; background: none; border: none; cursor: pointer; color: #1f2937; }
        }

        /* Picture upload modal */
        .upload-modal-overlay {
            position: fixed; top:0; left:0; right:0; bottom:0;
            background: rgba(0,0,0,0.5);
            display: flex; align-items: center; justify-content: center;
            z-index: 9999;
        }
        .upload-modal {
            background: white;
            border-radius: 12px;
            padding: 24px;
            width: 100%;
            max-width: 500px;
            margin: 16px;
            box-shadow: 0 25px 50px rgba(0,0,0,0.25);
        }
        .crop-container {
            max-height: 400px;
            margin: 16px 0;
        }
        #cropImage {
            max-width: 100%;
            display: block;
        }
        .view-picture-modal {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0, 0, 0, 0.9);
            display: flex;
            align-items: center;
            justify-content: center;
            z-index: 10000;
            padding: 20px;
        }
        .view-picture-modal img {
            max-width: 90%;
            max-height: 90vh;
            border-radius: 8px;
            box-shadow: 0 25px 50px rgba(0, 0, 0, 0.5);
        }
        .view-picture-close {
            position: absolute;
            top: 20px;
            right: 20px;
            background: rgba(255, 255, 255, 0.9);
            border: none;
            border-radius: 50%;
            width: 40px;
            height: 40px;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            color: #1f2937;
            transition: all 0.2s;
        }
        .view-picture-close:hover {
            background: white;
            transform: scale(1.1);
        }

        /* Validation: only show errors; neutral default (no persistent "valid" green) */
        .form-group.is-invalid input, 
        .form-group.is-invalid textarea,
        .form-group.is-invalid select {
            border-color: #ef4444 !important;
            background-color: #fef2f2;
        }
        .error-message {
            color: #ef4444;
            font-size: 11px;
            margin-top: 4px;
            display: none;
            font-weight: 500;
        }
        .form-group.is-invalid .error-message {
            display: block;
        }
        .btn-save:disabled {
            opacity: 0.5;
            cursor: not-allowed;
            filter: grayscale(1);
            transform: none !important;
            box-shadow: none !important;
        }
        .password-wrapper {
            position: relative;
        }
        .password-wrapper input {
            padding-right: 45px !important;
        }
        .password-toggle {
            position: absolute;
            right: 12px;
            top: 50%;
            transform: translateY(-50%);
            background: none;
            border: none;
            cursor: pointer;
            color: #9ca3af;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 4px;
            transition: color 0.2s;
            z-index: 10;
        }
        .password-toggle:hover {
            color: #53C5E0;
        }
    </style>
</head>
<body>

<div class="dashboard-container">
    <?php include defined('MANAGER_PANEL') ? __DIR__ . '/../includes/manager_sidebar.php' : __DIR__ . '/../includes/admin_sidebar.php'; ?>

    <!-- Main Content -->
    <div class="main-content">
        <header>
            <h1 class="page-title">My Profile</h1>
        </header>

        <main>
            <?php if ($error): ?>
                <div class="alert alert-error"><?php echo htmlspecialchars($error); ?></div>
            <?php endif; ?>
            <?php if ($success): ?>
                <div class="alert alert-success"><?php echo htmlspecialchars($success); ?></div>
            <?php endif; ?>

            <!-- Profile Hero -->
            <div class="profile-hero">
                <div class="profile-avatar-wrapper">
                    <div class="profile-avatar" style="<?php echo $profile_pic_url ? 'cursor: pointer;' : ''; ?>" onclick="<?php echo $profile_pic_url ? 'viewProfilePicture()' : ''; ?>">
                        <?php if ($profile_pic_url): ?>
                            <img src="<?php echo $profile_pic_url; ?>?t=<?php echo time(); ?>" alt="Profile">
                        <?php else: ?>
                            <?php echo $user_initial; ?>
                        <?php endif; ?>
                    </div>
                    <button type="button" class="avatar-edit-btn" onclick="document.getElementById('pictureModal').style.display='flex'">
                        <svg width="14" height="14" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 9a2 2 0 012-2h.93a2 2 0 001.664-.89l.812-1.22A2 2 0 0110.07 4h3.86a2 2 0 011.664.89l.812 1.22A2 2 0 0018.07 7H19a2 2 0 012 2v9a2 2 0 01-2 2H5a2 2 0 01-2-2V9z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 13a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
                    </button>
                </div>
                <div class="profile-hero-info">
                    <h2><?php echo htmlspecialchars($admin['first_name'] . ' ' . $admin['last_name']); ?></h2>
                    <p><?php echo htmlspecialchars($admin['email']); ?></p>
                    <div class="role-badge">
                        <svg width="12" height="12" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/></svg>
                        <?php echo $admin['role']; ?>
                    </div>
                </div>
            </div>

            <div class="profile-columns" style="display:grid; grid-template-columns:1fr 1fr; gap:20px;">
                <!-- Profile Information -->
                <div class="section-card">
                    <div class="section-title">
                        <svg width="18" height="18" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/></svg>
                        Personal Information
                    </div>
                    <form method="POST" id="personalInfoForm" onsubmit="return validatePersonalInfoForm(event)">
                        <?php echo csrf_field(); ?>
                        <input type="hidden" name="update_profile" value="1">
                        
                        <div class="form-grid">
                            <div class="form-group" id="group_first_name">
                                <label>First Name *</label>
                                <input type="text" name="first_name" id="first_name" value="<?php echo htmlspecialchars($admin['first_name']); ?>" required autocomplete="given-name" onkeydown="return blockNonLetters(event)" oninput="removeNonLetters(this)" maxlength="50">
                                <div class="error-message" id="error_first_name">First name is required.</div>
                            </div>
                            <div class="form-group" id="group_middle_name">
                                <label>Middle Name (Optional)</label>
                                <input type="text" name="middle_name" id="middle_name" value="<?php echo htmlspecialchars($admin['middle_name'] ?? ''); ?>" autocomplete="additional-name" onkeydown="return blockNonLetters(event)" oninput="removeNonLetters(this)" maxlength="50">
                                <div class="error-message" id="error_middle_name">Middle name must contain only letters.</div>
                            </div>
                            <div class="form-group" id="group_last_name">
                                <label>Last Name *</label>
                                <input type="text" name="last_name" id="last_name" value="<?php echo htmlspecialchars($admin['last_name']); ?>" required autocomplete="family-name" onkeydown="return blockNonLetters(event)" oninput="removeNonLetters(this)" maxlength="50">
                                <div class="error-message" id="error_last_name">Last name is required.</div>
                            </div>
                            <div class="form-group" id="group_birthday">
                                <label>Birthday *</label>
                                <input type="date" name="birthday" id="birthday" value="<?php echo htmlspecialchars($admin['birthday'] ?? ''); ?>" required max="<?php echo htmlspecialchars($maxBirthday); ?>">
                                <div class="error-message" id="error_birthday">You must be at least 18 years old.</div>
                            </div>
                        </div>
                        
                        <div class="form-group" id="group_email">
                            <label>Email *</label>
                            <input type="email" name="email" id="email" value="<?php echo htmlspecialchars($admin['email']); ?>" required autocomplete="email" maxlength="100">
                            <div class="error-message" id="error_email">Please enter a valid email address.</div>
                        </div>
                        
                        <div class="form-group" id="group_contact_number">
                            <label>Contact Number *</label>
                            <input type="text" name="contact_number" id="contact_number" value="<?php echo htmlspecialchars($admin['contact_number'] ?? '09'); ?>" placeholder="e.g. 09171234567" required autocomplete="tel" maxlength="11" oninput="formatPhoneNumber(this)">
                            <div class="error-message" id="error_contact_number">Contact number is required.</div>
                        </div>
                        
                        <div class="form-group">
                            <label>Country</label>
                            <input type="text" value="Philippines" disabled>
                            <input type="hidden" name="address_country" id="address_country" value="Philippines">
                        </div>

                        <div class="form-group" id="group_address_province">
                            <label>Province *</label>
                            <select id="address_province" name="address_province" required>
                                <option value="">Select province</option>
                                <?php if ($addressProvince !== ''): ?>
                                <option value="<?php echo htmlspecialchars($addressProvince); ?>" selected data-code=""><?php echo htmlspecialchars($addressProvince); ?></option>
                                <?php endif; ?>
                            </select>
                            <div class="error-message" id="error_address_province">Province is required.</div>
                        </div>

                        <div class="form-group" id="group_address_city">
                            <label>City / Municipality *</label>
                            <select id="address_city" name="address_city" required <?php echo $addressProvince === '' ? 'disabled' : ''; ?>>
                                <option value="">Select city/municipality</option>
                                <?php if ($addressCity !== ''): ?>
                                <option value="<?php echo htmlspecialchars($addressCity); ?>" selected data-code=""><?php echo htmlspecialchars($addressCity); ?></option>
                                <?php endif; ?>
                            </select>
                            <div class="error-message" id="error_address_city">City / Municipality is required.</div>
                        </div>

                        <div class="form-group" id="group_address_barangay">
                            <label>Barangay *</label>
                            <select id="address_barangay" name="address_barangay" required <?php echo $addressCity === '' ? 'disabled' : ''; ?>>
                                <option value="">Select barangay</option>
                                <?php if ($addressBarangay !== ''): ?>
                                <option value="<?php echo htmlspecialchars($addressBarangay); ?>" selected data-code=""><?php echo htmlspecialchars($addressBarangay); ?></option>
                                <?php endif; ?>
                            </select>
                            <div class="error-message" id="error_address_barangay">Barangay is required.</div>
                        </div>

                        <div class="form-group">
                            <label>Street / House No. (Optional)</label>
                            <input type="text" id="address_line" name="address_line" maxlength="120" placeholder="e.g. 123 Rizal St." value="<?php echo htmlspecialchars($addressLine); ?>">
                            <p style="font-size:11px;color:#9ca3af;margin-top:4px;">Optional detailed line; location is validated by PSGC-based selectors.</p>
                        </div>

                        <div class="form-group" id="group_address">
                            <label>Saved Address Preview</label>
                            <textarea name="address" id="address" rows="2" readonly required><?php echo htmlspecialchars($admin['address'] ?? ''); ?></textarea>
                            <div class="error-message" id="error_address">Please complete the Philippine address fields.</div>
                        </div>
                        
                        <div class="profile-form-actions">
                            <button type="submit" class="btn-save" id="btn_save_profile">
                                <svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                                Save Changes
                            </button>
                        </div>
                    </form>
                </div>

                <!-- Change Password -->
                <div class="section-card">
                    <div class="section-title">
                        <svg width="18" height="18" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/></svg>
                        Change Password
                    </div>
                    <form method="POST" id="passwordForm" onsubmit="return validatePasswordForm(event)">
                        <?php echo csrf_field(); ?>
                        <input type="hidden" name="change_password" value="1">
                        
                        <div class="form-group" id="group_current_password">
                            <label>Current Password *</label>
                            <div class="password-wrapper">
                                <input type="password" name="current_password" id="current_password" required autocomplete="current-password">
                                <button type="button" class="password-toggle" onclick="togglePassword('current_password', this)">
                                    <svg width="18" height="18" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/></svg>
                                </button>
                            </div>
                            <div class="error-message" id="error_current_password">Current password is required.</div>
                        </div>
                        
                        <div class="form-group" id="group_new_password">
                            <label>New Password *</label>
                            <div class="password-wrapper">
                                <input type="password" name="new_password" id="new_password" required autocomplete="new-password">
                                <button type="button" class="password-toggle" onclick="togglePassword('new_password', this)">
                                    <svg width="18" height="18" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/></svg>
                                </button>
                            </div>
                            <p id="password_requirements" style="font-size:11px;color:#9ca3af;margin-top:4px;">Min. 8 chars, 1 uppercase, 1 lowercase, 1 number, 1 symbol</p>
                            <div class="error-message" id="error_new_password">Invalid password format.</div>
                        </div>
                        
                        <div class="form-group" id="group_confirm_password">
                            <label>Confirm New Password *</label>
                            <div class="password-wrapper">
                                <input type="password" name="confirm_password" id="confirm_password" required autocomplete="new-password">
                                <button type="button" class="password-toggle" onclick="togglePassword('confirm_password', this)">
                                    <svg width="18" height="18" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/></svg>
                                </button>
                            </div>
                            <div class="error-message" id="error_confirm_password">Passwords do not match.</div>
                        </div>
                        
                        <div class="profile-form-actions">
                            <button type="submit" class="btn-save" id="btn_update_password">
                                <svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/></svg>
                                Update Password
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </main>
    </div>
</div>

<!-- Picture Upload Modal -->
<div id="pictureModal" style="display:none;" class="upload-modal-overlay" onclick="if(event.target===this)closePictureModal()">
    <div class="upload-modal">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px;">
            <h3 style="font-size:16px;font-weight:700;margin:0;">Profile Picture</h3>
            <button onclick="closePictureModal()" style="background:none;border:none;cursor:pointer;color:#6b7280;">
                <svg width="20" height="20" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
            </button>
        </div>
        
        <!-- Upload Form -->
        <form method="POST" enctype="multipart/form-data" id="uploadForm">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="upload_picture" value="1">
            <input type="hidden" name="cropped_image" id="croppedImageData">
            
            <div style="border:2px dashed #e5e7eb;border-radius:8px;padding:24px;text-align:center;margin-bottom:16px;" id="dropZone">
                <svg width="40" height="40" fill="none" stroke="#9ca3af" viewBox="0 0 24 24" style="margin:0 auto 8px;display:block;"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>
                <p style="font-size:13px;color:#6b7280;margin:0 0 8px;">Click to select or drag a photo</p>
                <p style="font-size:11px;color:#9ca3af;margin:0;">JPG, PNG, GIF, WEBP (max 5MB)</p>
                <input type="file" accept="image/jpeg,image/png,image/gif,image/webp" id="fileInput" style="position:absolute;opacity:0;pointer-events:none;">
            </div>
            
            <div id="cropArea" style="display:none;">
                <div class="crop-container">
                    <img id="cropImage" src="">
                </div>
                <div style="display:flex;gap:8px;margin-top:16px;">
                    <button type="button" class="btn-save" style="flex:1;justify-content:center;" onclick="cropAndUpload()">Crop & Upload</button>
                    <button type="button" class="btn-danger-outline" onclick="cancelCrop()">Cancel</button>
                </div>
            </div>
            
            <?php if (!empty($admin['profile_picture'])): ?>
            <div style="display:flex;gap:8px;" id="removeArea">
                <button type="submit" name="remove_picture" value="1" class="btn-danger-outline" style="flex:1;justify-content:center;" onclick="this.form.querySelector('[name=upload_picture]').disabled=true;">Remove Current Picture</button>
            </div>
            <?php endif; ?>
        </form>
    </div>
</div>

<!-- View Picture Modal -->
<div id="viewPictureModal" style="display:none;" class="view-picture-modal" onclick="if(event.target===this)closeViewPicture()">
    <button class="view-picture-close" onclick="closeViewPicture()">
        <svg width="24" height="24" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
    </button>
    <img id="viewPictureImg" src="" alt="Profile Picture">
</div>

<script src="https://cdnjs.cloudflare.com/ajax/libs/cropperjs/1.6.1/cropper.min.js"></script>
<script>
    // File input handling (Turbo-safe: var + bind once)
    var dropZone = document.getElementById('dropZone');
    var fileInput = document.getElementById('fileInput');
    var cropArea = document.getElementById('cropArea');
    var cropImage = document.getElementById('cropImage');
    var cropper = null;
    
    function validateFile(file) {
        var maxSize = 5 * 1024 * 1024; // 5MB
        var allowedTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
        var allowedExtensions = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
        
        var fileExt = file.name.split('.').pop().toLowerCase();
        
        if (file.type.startsWith('video/') || ['mp4', 'avi', 'mov', 'wmv', 'flv', 'mkv', 'webm'].indexOf(fileExt) !== -1) {
            alert('Videos are not allowed. Please upload an image file (JPG, PNG, GIF, or WEBP).');
            return false;
        }
        
        if (allowedExtensions.indexOf(fileExt) === -1) {
            alert('Invalid file type. Only JPG, PNG, GIF, and WEBP images are allowed.');
            return false;
        }
        
        if (allowedTypes.indexOf(file.type) === -1) {
            alert('Invalid file type. Only image files are allowed.');
            return false;
        }
        
        if (file.size > maxSize) {
            var sizeMB = (file.size / (1024 * 1024)).toFixed(2);
            alert('File too large (' + sizeMB + 'MB). Maximum size is 5MB.');
            return false;
        }
        
        return true;
    }
    
    if (dropZone && fileInput && cropArea && cropImage && !dropZone._pfUploadBound) {
        dropZone._pfUploadBound = true;
        dropZone.addEventListener('click', function () { fileInput.click(); });
        dropZone.addEventListener('dragover', function (e) { e.preventDefault(); dropZone.style.borderColor = '#53C5E0'; });
        dropZone.addEventListener('dragleave', function () { dropZone.style.borderColor = '#e5e7eb'; });
        dropZone.addEventListener('drop', function (e) {
            e.preventDefault();
            dropZone.style.borderColor = '#e5e7eb';
            if (e.dataTransfer.files.length) {
                var file = e.dataTransfer.files[0];
                if (validateFile(file)) {
                    showCropper(file);
                }
            }
        });
        fileInput.addEventListener('change', function (e) {
            if (e.target.files.length) {
                var file = e.target.files[0];
                if (validateFile(file)) {
                    showCropper(file);
                }
            }
        });
    }
    
    function showCropper(file) {
        if (!cropImage || !cropArea || !dropZone) return;
        var reader = new FileReader();
        reader.onload = function (e) {
            cropImage.src = e.target.result;
            dropZone.style.display = 'none';
            var removeArea = document.getElementById('removeArea');
            if (removeArea) removeArea.style.display = 'none';
            cropArea.style.display = 'block';
            
            if (cropper) cropper.destroy();
            cropper = new Cropper(cropImage, {
                aspectRatio: 1,
                viewMode: 2,
                dragMode: 'move',
                autoCropArea: 1,
                restore: false,
                guides: true,
                center: true,
                highlight: false,
                cropBoxMovable: true,
                cropBoxResizable: true,
                toggleDragModeOnDblclick: false,
            });
        };
        reader.readAsDataURL(file);
    }
    
    function cropAndUpload() {
        if (!cropper) return;
        var canvas = cropper.getCroppedCanvas({
            width: 400,
            height: 400,
            imageSmoothingQuality: 'high'
        });
        canvas.toBlob(function(blob) {
            var formData = new FormData();
            formData.append('profile_picture', blob, 'profile.jpg');
            formData.append('upload_picture', '1');
            var csrfInput = document.querySelector('input[name="csrf_token"]');
            if (csrfInput) formData.append('csrf_token', csrfInput.value);
            
            fetch('profile.php', {
                method: 'POST',
                body: formData
            }).then(function() {
                window.location.reload();
            });
        }, 'image/jpeg', 0.9);
    }
    
    function cancelCrop() {
        if (cropper) {
            cropper.destroy();
            cropper = null;
        }
        if (cropArea) cropArea.style.display = 'none';
        if (dropZone) dropZone.style.display = 'block';
        var removeArea = document.getElementById('removeArea');
        if (removeArea) removeArea.style.display = 'flex';
        if (fileInput) fileInput.value = '';
    }
    
    function closePictureModal() {
        cancelCrop();
        var modal = document.getElementById('pictureModal');
        if (modal) modal.style.display = 'none';
    }
    
    function viewProfilePicture() {
        var profileImg = document.querySelector('.profile-avatar img');
        var viewModal = document.getElementById('viewPictureModal');
        var viewImg = document.getElementById('viewPictureImg');
        if (profileImg && viewModal && viewImg) {
            viewImg.src = profileImg.src;
            viewModal.style.display = 'flex';
        }
    }
    
    function closeViewPicture() {
        var viewModal = document.getElementById('viewPictureModal');
        if (viewModal) viewModal.style.display = 'none';
    }
    
    // Close view modal with Escape key
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
            closeViewPicture();
        }
    });
    
    window.viewProfilePicture = viewProfilePicture;
    window.closeViewPicture = closeViewPicture;
    
    function togglePassword(fieldId, button) {
        var field = document.getElementById(fieldId);
        if (!field) return;
        
        if (field.type === 'password') {
            field.type = 'text';
            button.innerHTML = '<svg width="18" height="18" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.875 18.825A10.05 10.05 0 0112 19c-4.478 0-8.268-2.943-9.543-7a9.97 9.97 0 011.563-3.029m5.858.908a3 3 0 114.243 4.243M9.878 9.878l4.242 4.242M9.88 9.88l-3.29-3.29m7.532 7.532l3.29 3.29M3 3l3.59 3.59m0 0A9.953 9.953 0 0112 5c4.478 0 8.268 2.943 9.543 7a10.025 10.025 0 01-4.132 5.411m0 0L21 21"/></svg>';
        } else {
            field.type = 'password';
            button.innerHTML = '<svg width="18" height="18" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/></svg>';
        }
    }
    
    window.togglePassword = togglePassword;

    // --- PSGC-based Address Cascading (DOM refs refreshed in printflowInitProfilePage for Turbo) ---
    var addressInitial = {
        province: <?php echo json_encode($addressProvince, JSON_UNESCAPED_UNICODE); ?>,
        city: <?php echo json_encode($addressCity, JSON_UNESCAPED_UNICODE); ?>,
        barangay: <?php echo json_encode($addressBarangay, JSON_UNESCAPED_UNICODE); ?>,
    };

    var provinceSelect, citySelect, barangaySelect, addressLineInput, addressPreview;

    /** True while JS is filling province/city/barangay from PSGC (avoids change handlers racing loadProvinces). */
    var _pfProfileCascadeProgrammatic = false;
    /** Bumped on each loadProvinces start so stale async work does not touch the DOM or clear the flag. */
    var _pfProfileAddressLoadToken = 0;

    function clearOptions(selectEl, placeholder) {
        if (!selectEl) return;
        selectEl.innerHTML = '';
        const opt = document.createElement('option');
        opt.value = '';
        opt.textContent = placeholder;
        selectEl.appendChild(opt);
    }

    function setSelectOptions(selectEl, rows, placeholder) {
        if (!selectEl) return;
        clearOptions(selectEl, placeholder);
        rows.forEach((row) => {
            var opt = document.createElement('option');
            opt.value = row.name;
            opt.textContent = row.name;
            opt.dataset.code = row.code;
            selectEl.appendChild(opt);
        });
    }

    function setSelectState(selectEl, disabled) {
        if (!selectEl) return;
        selectEl.disabled = disabled;
    }

    async function fetchAddressRows(url) {
        const response = await fetch(url, { headers: { 'Accept': 'application/json' } });
        if (!response.ok) throw new Error('Address request failed');
        const payload = await response.json();
        if (!payload.success) throw new Error(payload.error || 'Address request failed');
        return payload.data || [];
    }

    function selectedOptionCode(selectEl) {
        if (!selectEl) return '';
        var selected = selectEl.options[selectEl.selectedIndex];
        return selected?.dataset?.code || '';
    }

    function updateAddressPreview() {
        if (!addressLineInput || !barangaySelect || !citySelect || !provinceSelect || !addressPreview) return;
        /* Avoid replacing server-rendered textarea with partial text while PSGC lists are still loading. */
        if (_pfProfileCascadeProgrammatic) return;
        var parts = [];
        var line = addressLineInput.value.trim();
        var barangay = barangaySelect.value.trim();
        var city = citySelect.value.trim();
        var province = provinceSelect.value.trim();

        if (line) parts.push(line);
        if (barangay) parts.push('Brgy. ' + barangay);
        if (city) parts.push(city);
        if (province) parts.push(province);
        parts.push('Philippines');

        addressPreview.value = parts.join(', ');
    }

    async function loadProvinces(initialProvince = '', initialCity = '', initialBarangay = '') {
        if (!provinceSelect || !citySelect || !barangaySelect) return;
        var myToken = ++_pfProfileAddressLoadToken;
        _pfProfileCascadeProgrammatic = true;
        try {
            var provinces = await fetchAddressRows('profile.php?address_action=provinces');
            if (myToken !== _pfProfileAddressLoadToken) return;
            setSelectOptions(provinceSelect, provinces, 'Select province');
            setSelectState(provinceSelect, false);

            if (initialProvince) {
                var target = [...provinceSelect.options].find(o => o.value.toLowerCase() === initialProvince.toLowerCase());
                if (target) {
                    provinceSelect.value = target.value;
                    await loadCities(target.dataset.code || '', initialCity, initialBarangay, myToken);
                    return;
                }
            }
        } finally {
            if (myToken === _pfProfileAddressLoadToken) {
                _pfProfileCascadeProgrammatic = false;
                updateAddressPreview();
            }
        }
    }

    async function loadCities(provinceCode, initialCity = '', initialBarangay = '', syncToken) {
        if (!citySelect || !barangaySelect) return;
        if (syncToken !== undefined && syncToken !== _pfProfileAddressLoadToken) return;

        if (!provinceCode) {
            clearOptions(citySelect, 'Select city/municipality');
            clearOptions(barangaySelect, 'Select barangay');
            setSelectState(citySelect, true);
            setSelectState(barangaySelect, true);
            updateAddressPreview();
            return;
        }

        /* User changed province: handler already cleared children. Initial page load: keep PHP-prefilled labels until fetch returns. */
        var userDriven = syncToken === undefined;
        if (userDriven) {
            clearOptions(citySelect, 'Select city/municipality');
            clearOptions(barangaySelect, 'Select barangay');
            setSelectState(citySelect, true);
            setSelectState(barangaySelect, true);
        }

        const cities = await fetchAddressRows('profile.php?address_action=cities&province_code=' + encodeURIComponent(provinceCode));
        if (syncToken !== undefined && syncToken !== _pfProfileAddressLoadToken) return;
        setSelectOptions(citySelect, cities, 'Select city/municipality');
        setSelectState(citySelect, false);

        if (initialCity) {
            const target = [...citySelect.options].find(o => o.value.toLowerCase() === initialCity.toLowerCase());
            if (target) {
                citySelect.value = target.value;
                await loadBarangays(target.dataset.code || '', initialBarangay, syncToken);
                return;
            }
        }
        if (syncToken !== undefined && syncToken !== _pfProfileAddressLoadToken) return;
        clearOptions(barangaySelect, 'Select barangay');
        setSelectState(barangaySelect, true);
        updateAddressPreview();
    }

    async function loadBarangays(cityCode, initialBarangay = '', syncToken) {
        if (!barangaySelect) return;
        if (syncToken !== undefined && syncToken !== _pfProfileAddressLoadToken) return;

        if (!cityCode) {
            clearOptions(barangaySelect, 'Select barangay');
            setSelectState(barangaySelect, true);
            updateAddressPreview();
            return;
        }

        var userDriven = syncToken === undefined;
        if (userDriven) {
            clearOptions(barangaySelect, 'Select barangay');
            setSelectState(barangaySelect, true);
        }

        const barangays = await fetchAddressRows('profile.php?address_action=barangays&city_code=' + encodeURIComponent(cityCode));
        if (syncToken !== undefined && syncToken !== _pfProfileAddressLoadToken) return;
        setSelectOptions(barangaySelect, barangays, 'Select barangay');
        setSelectState(barangaySelect, false);

        if (initialBarangay) {
            var targetB = [...barangaySelect.options].find(o => o.value.toLowerCase() === initialBarangay.toLowerCase());
            if (targetB) barangaySelect.value = targetB.value;
        }
        if (syncToken !== undefined && syncToken !== _pfProfileAddressLoadToken) return;
        updateAddressPreview();
    }

    function bindProfileAddressCascadeListeners() {
        if (!provinceSelect || !citySelect || !barangaySelect || !addressLineInput || !addressPreview) return;
        if (provinceSelect._pfCascadeBound) return;
        provinceSelect._pfCascadeBound = true;
        provinceSelect.addEventListener('change', async function () {
            if (_pfProfileCascadeProgrammatic) return;
            clearOptions(citySelect, 'Select city/municipality');
            clearOptions(barangaySelect, 'Select barangay');
            setSelectState(citySelect, true);
            setSelectState(barangaySelect, true);
            updateAddressPreview();
            var provinceCode = selectedOptionCode(provinceSelect);
            if (provinceCode) {
                await loadCities(provinceCode);
            }
            checkPersonalInfo();
        });
        citySelect.addEventListener('change', async function () {
            if (_pfProfileCascadeProgrammatic) return;
            clearOptions(barangaySelect, 'Select barangay');
            setSelectState(barangaySelect, true);
            updateAddressPreview();
            var cityCode = selectedOptionCode(citySelect);
            if (cityCode) {
                await loadBarangays(cityCode);
            }
            checkPersonalInfo();
        });
        barangaySelect.addEventListener('change', function () {
            if (_pfProfileCascadeProgrammatic) return;
            updateAddressPreview();
            checkPersonalInfo();
        });
        addressLineInput.addEventListener('input', function () {
            updateAddressPreview();
            checkPersonalInfo();
        });
    }

    // --- Validation Logic ---
    var birthdayMax = <?php echo json_encode($maxBirthday); ?>;
    var personalForm = document.getElementById('personalInfoForm');
    var passwordForm = document.getElementById('passwordForm');
    var passwordFieldIds = ['current_password', 'new_password', 'confirm_password'];
    var passwordTouched = { current_password: false, new_password: false, confirm_password: false };

    var validators = {
        first_name: (val) => {
            if (!val) return "First name is required.";
            if (!/^[A-Za-z]+( [A-Za-z]+)*$/.test(val)) return "First name must contain only letters.";
            if (val.length < 2 || val.length > 50) return "First name must be between 2 and 50 characters.";
            return null;
        },
        middle_name: (val) => {
            if (!val) return null;
            if (!/^[A-Za-z]+( [A-Za-z]+)*$/.test(val)) return "Middle name must contain only letters.";
            if (val.length < 2 || val.length > 50) return "Middle name must be between 2 and 50 characters.";
            return null;
        },
        last_name: (val) => {
            if (!val) return "Last name is required.";
            if (!/^[A-Za-z]+( [A-Za-z]+)*$/.test(val)) return "Last name must contain only letters.";
            if (val.length < 2 || val.length > 50) return "Last name must be between 2 and 50 characters.";
            return null;
        },
        email: (val) => {
            if (!val) return "Email is required.";
            // Email validation: require at least 2 characters after the last dot
            if (!/^[^\s@]+@[^\s@]+\.[a-zA-Z]{2,}$/.test(val)) return "Please enter a valid email address.";
            if (val.length > 100) return "Email cannot exceed 100 characters.";
            return null;
        },
        birthday: (val) => {
            if (!val) return "Birthday is required.";
            const today = new Date();
            const selected = new Date(val + 'T00:00:00');
            if (Number.isNaN(selected.getTime())) return "Invalid birthday.";
            if (selected > today) return "Birthday cannot be a future date.";
            const adultLimit = new Date(birthdayMax + 'T00:00:00');
            if (selected > adultLimit) return "You must be at least 18 years old.";
            return null;
        },
        contact_number: (val) => {
            if (!val) return "Contact number is required.";
            if (!/^\d+$/.test(val)) return "Contact number must contain digits only.";
            if (!val.startsWith('09')) return "Contact number must start with 09.";
            if (val.length !== 11) return "Contact number must be exactly 11 digits.";
            return null;
        },
        address_province: (val) => !val ? "Province is required." : null,
        address_city: (val) => !val ? "City / Municipality is required." : null,
        address_barangay: (val) => !val ? "Barangay is required." : null,
        address: (val) => {
            if (!val || val === 'Philippines') return "Address is required.";
            if (val.length < 5) return "Address must be at least 5 characters.";
            if (val.length > 200) return "Address cannot exceed 200 characters.";
            return null;
        },
        new_password: (val) => {
            // Match registration rules exactly.
            if (!val) return "New password is required.";
            if (val.length < 8) return "Password must be at least 8 characters.";
            if (val.length > 100) return "Password must be at most 100 characters.";
            if (!/[A-Z]/.test(val)) return "Password must have an uppercase letter.";
            if (!/[a-z]/.test(val)) return "Password must have a lowercase letter.";
            if (!/[0-9]/.test(val)) return "Password must have a number.";
            if (!/[^A-Za-z0-9]/.test(val)) return "Password must have a special character.";
            if (/\s/.test(val)) return "Password must not contain spaces.";
            return null;
        }
    };

    function clearValidationState(id) {
        const group = document.getElementById('group_' + id);
        if (!group) return;
        group.classList.remove('is-invalid', 'is-valid');
    }

    function validateField(id, validator, options = {}) {
        const input = document.getElementById(id);
        const group = document.getElementById('group_' + id);
        const error = document.getElementById('error_' + id);
        if (!input || !group || !error) return true;

        let val = input.value || '';

        if (id === 'first_name' || id === 'middle_name' || id === 'last_name') {
            if (val.startsWith(' ')) val = val.trimStart();
            if (val.length > 0) val = val.charAt(0).toUpperCase() + val.slice(1);
            input.value = val;
        }
        if (val.startsWith(' ')) {
            input.value = val.trimStart();
            val = input.value;
        }

        const trimmed = val.trim();
        if (options.skipEmpty && trimmed === '') {
            clearValidationState(id);
            return true;
        }
        if (options.onlyWhenTouched && !options.isTouched) {
            clearValidationState(id);
            return false;
        }

        const errorMessage = validator(trimmed);
        if (errorMessage) {
            group.classList.add('is-invalid');
            group.classList.remove('is-valid');
            error.textContent = errorMessage;
            return false;
        }

        group.classList.remove('is-invalid');
        group.classList.remove('is-valid');
        return true;
    }

    function checkPersonalInfo() {
        if (_pfProfileCascadeProgrammatic) return;
        const fValid = validateField('first_name', validators.first_name);
        const mValid = validateField('middle_name', validators.middle_name, { skipEmpty: true });
        const lValid = validateField('last_name', validators.last_name);
        const eValid = validateField('email', validators.email);
        const bdayValid = validateField('birthday', validators.birthday);
        const cValid = validateField('contact_number', validators.contact_number);
        const pValid = validateField('address_province', validators.address_province);
        const ciValid = validateField('address_city', validators.address_city);
        const bValid = validateField('address_barangay', validators.address_barangay);
        const aValid = validateField('address', validators.address);
        var btnSave = document.getElementById('btn_save_profile');
        if (btnSave) btnSave.disabled = !(fValid && mValid && lValid && eValid && bdayValid && cValid && pValid && ciValid && bValid && aValid);
    }

    function checkPassword(force = false) {
        const current = document.getElementById('current_password');
        const newPass = document.getElementById('new_password');
        const confirm = document.getElementById('confirm_password');
        if (!current || !newPass || !confirm) return;
        const hasAnyInput = (current.value + newPass.value + confirm.value).trim().length > 0;
        const mustValidate = force || hasAnyInput || (passwordTouched && typeof passwordTouched === 'object' ? Object.values(passwordTouched).some(Boolean) : false);

        if (!mustValidate) {
            passwordFieldIds.forEach(clearValidationState);
            var btnUp = document.getElementById('btn_update_password');
            if (btnUp) btnUp.disabled = true;
            return;
        }

        const currentValid = validateField('current_password', (val) => !val ? 'Current password is required.' : null, {
            onlyWhenTouched: !force,
            isTouched: passwordTouched.current_password || current.value.length > 0
        });
        const nValid = validateField('new_password', validators.new_password, {
            onlyWhenTouched: !force,
            isTouched: passwordTouched.new_password || newPass.value.length > 0
        });

        const cGroup = document.getElementById('group_confirm_password');
        const cError = document.getElementById('error_confirm_password');
        if (!cGroup || !cError) return;
        let confirmValid = false;
        if (!force && !passwordTouched.confirm_password && confirm.value === '') {
            clearValidationState('confirm_password');
        } else if (!confirm.value) {
            cGroup.classList.add('is-invalid');
            cGroup.classList.remove('is-valid');
            cError.textContent = "Confirm password is required.";
        } else if (confirm.value !== newPass.value) {
            cGroup.classList.add('is-invalid');
            cGroup.classList.remove('is-valid');
            cError.textContent = "Passwords do not match.";
        } else {
            cGroup.classList.remove('is-invalid');
            cGroup.classList.remove('is-valid');
            confirmValid = true;
        }

        var btnUp2 = document.getElementById('btn_update_password');
        if (btnUp2) btnUp2.disabled = !(currentValid && nValid && confirmValid);
    }

    function validatePersonalInfoForm(event) {
        checkPersonalInfo();
        var btn = document.getElementById('btn_save_profile');
        var ok = !!(btn && !btn.disabled);
        if (!ok && event && typeof event.preventDefault === 'function') {
            event.preventDefault();
        }
        return ok;
    }

    function validatePasswordForm(event) {
        checkPassword(true);
        var btn = document.getElementById('btn_update_password');
        var ok = !!(btn && !btn.disabled);
        if (!ok && event && typeof event.preventDefault === 'function') {
            event.preventDefault();
        }
        return ok;
    }

    window.validatePersonalInfoForm = validatePersonalInfoForm;
    window.validatePasswordForm = validatePasswordForm;

    // Block numbers and special characters in name fields
    function blockNonLetters(event) {
        // Allow: backspace, delete, tab, escape, enter, arrow keys
        if ([8, 9, 27, 13, 46, 37, 38, 39, 40].indexOf(event.keyCode) !== -1 ||
            // Allow: Ctrl+A, Ctrl+C, Ctrl+V, Ctrl+X
            (event.keyCode === 65 && event.ctrlKey === true) ||
            (event.keyCode === 67 && event.ctrlKey === true) ||
            (event.keyCode === 86 && event.ctrlKey === true) ||
            (event.keyCode === 88 && event.ctrlKey === true) ||
            // Allow: home, end
            (event.keyCode >= 35 && event.keyCode <= 36)) {
            return true;
        }
        
        // Get the character that would be typed
        var char = event.key;
        
        // Block ALL special characters and numbers - only allow letters and space
        if (char && char.length === 1 && !/^[a-zA-Z ]$/.test(char)) {
            event.preventDefault();
            return false;
        }
        
        // Block consecutive spaces
        if (char === ' ') {
            var input = event.target;
            var value = input.value;
            var cursorPos = input.selectionStart;
            
            // Block space at the beginning
            if (cursorPos === 0) {
                event.preventDefault();
                return false;
            }
            
            // Block consecutive spaces
            if (value.charAt(cursorPos - 1) === ' ') {
                event.preventDefault();
                return false;
            }
        }
        
        return true;
    }

    // Remove any non-letter characters that get through (e.g., from paste)
    function removeNonLetters(input) {
        var value = input.value;
        var cursorPos = input.selectionStart;
        
        // Remove ALL special characters and numbers - keep only letters and spaces
        var cleaned = value.replace(/[^a-zA-Z ]/g, '');
        // Remove consecutive spaces
        cleaned = cleaned.replace(/  +/g, ' ');
        // Remove leading spaces
        cleaned = cleaned.replace(/^ +/, '');
        
        // Capitalize first letter of each word, lowercase the rest
        cleaned = cleaned.split(' ').map(function(word) {
            if (word.length === 0) return word;
            return word.charAt(0).toUpperCase() + word.slice(1).toLowerCase();
        }).join(' ');
        
        // Enforce max length of 50
        if (cleaned.length > 50) {
            cleaned = cleaned.substring(0, 50);
        }
        
        if (value !== cleaned) {
            input.value = cleaned;
            // Restore cursor position
            if (cursorPos !== null) {
                input.setSelectionRange(cursorPos, cursorPos);
            }
        }
    }

    // Format phone number to ensure it starts with 09 and is max 11 digits
    function formatPhoneNumber(input) {
        var value = input.value;
        // Remove all non-digit characters
        var cleaned = value.replace(/\D/g, '');
        
        // If empty or user deleted everything, set to '09'
        if (cleaned.length === 0) {
            input.value = '09';
            return;
        }
        
        // If doesn't start with 09, prepend it
        if (!cleaned.startsWith('09')) {
            // If starts with 9, add 0
            if (cleaned.startsWith('9')) {
                cleaned = '0' + cleaned;
            } else {
                // Otherwise, replace with 09
                cleaned = '09' + cleaned;
            }
        }
        
        // Limit to 11 digits
        if (cleaned.length > 11) {
            cleaned = cleaned.substring(0, 11);
        }
        
        input.value = cleaned;
    }

    function printflowInitProfilePage() {
        if (!document.getElementById('personalInfoForm')) return;

        personalForm = document.getElementById('personalInfoForm');
        passwordForm = document.getElementById('passwordForm');
        provinceSelect = document.getElementById('address_province');
        citySelect = document.getElementById('address_city');
        barangaySelect = document.getElementById('address_barangay');
        addressLineInput = document.getElementById('address_line');
        addressPreview = document.getElementById('address');
        bindProfileAddressCascadeListeners();

        // Reset touched states
        Object.keys(passwordTouched).forEach(k => passwordTouched[k] = false);

        // Initialize phone number with 09 if empty
        var phoneInput = document.getElementById('contact_number');
        if (phoneInput && (!phoneInput.value || phoneInput.value.trim() === '')) {
            phoneInput.value = '09';
        }

        loadProvinces(addressInitial.province, addressInitial.city, addressInitial.barangay)
            .catch(() => {
                clearOptions(provinceSelect, 'Unable to load provinces');
                setSelectState(provinceSelect, true);
                clearOptions(citySelect, 'Select city/municipality');
                clearOptions(barangaySelect, 'Select barangay');
                setSelectState(citySelect, true);
                setSelectState(barangaySelect, true);
                updateAddressPreview();
                checkPersonalInfo();
            })
            .finally(() => {
                if (typeof checkPersonalInfo === 'function') checkPersonalInfo();
                if (window.printflowFormGuard && typeof window.printflowFormGuard.refresh === 'function') {
                    window.printflowFormGuard.refresh();
                }
            });

        // Re-attach listeners
        ['first_name', 'middle_name', 'last_name', 'email', 'birthday', 'contact_number', 'address', 'address_line'].forEach(id => {
            const el = document.getElementById(id);
            if (!el) return;
            el.removeEventListener('input', checkPersonalInfo);
            el.addEventListener('input', checkPersonalInfo);
            el.removeEventListener('blur', checkPersonalInfo);
            el.addEventListener('blur', checkPersonalInfo);
        });

        ['address_province', 'address_city', 'address_barangay'].forEach(id => {
            const el = document.getElementById(id);
            if (!el) return;
            el.removeEventListener('change', checkPersonalInfo);
            el.addEventListener('change', checkPersonalInfo);
            el.removeEventListener('blur', checkPersonalInfo);
            el.addEventListener('blur', checkPersonalInfo);
        });

        passwordFieldIds.forEach(id => {
            const el = document.getElementById(id);
            if (!el) return;
            el.removeEventListener('input', checkPassword);
            el.addEventListener('input', () => { passwordTouched[id] = true; checkPassword(); });
            el.removeEventListener('blur', checkPassword);
            el.addEventListener('blur', () => { passwordTouched[id] = true; checkPassword(); });
            
            // Block input when max length (64) is reached
            el.removeEventListener('keydown', blockExcessPasswordInput);
            el.addEventListener('keydown', blockExcessPasswordInput);
            
            // Block paste when it would exceed max length
            el.removeEventListener('paste', blockExcessPasswordPaste);
            el.addEventListener('paste', blockExcessPasswordPaste);
        });

        checkPassword(false);
    }
    
    function blockExcessPasswordInput(event) {
        var input = event.target;
        var maxLength = 100;
        
        // Allow: backspace, delete, tab, escape, enter, arrow keys
        if ([8, 9, 27, 13, 46, 37, 38, 39, 40].indexOf(event.keyCode) !== -1 ||
            // Allow: Ctrl+A, Ctrl+C, Ctrl+X, Ctrl+Z (but NOT Ctrl+V - handled by paste event)
            (event.ctrlKey === true && [65, 67, 88, 90].indexOf(event.keyCode) !== -1) ||
            // Allow: home, end
            (event.keyCode >= 35 && event.keyCode <= 36)) {
            return true;
        }
        
        // Block if at max length and no text is selected
        if (input.value.length >= maxLength && input.selectionStart === input.selectionEnd) {
            event.preventDefault();
            return false;
        }
        
        return true;
    }
    
    function blockExcessPasswordPaste(event) {
        var input = event.target;
        var maxLength = 100;
        
        // Get pasted text
        var pastedText = (event.clipboardData || window.clipboardData).getData('text');
        
        // Calculate what the new value would be
        var currentValue = input.value;
        var selectionStart = input.selectionStart;
        var selectionEnd = input.selectionEnd;
        
        // Remove selected text and insert pasted text
        var newValue = currentValue.substring(0, selectionStart) + pastedText + currentValue.substring(selectionEnd);
        
        // If new value exceeds max length, prevent paste
        if (newValue.length > maxLength) {
            event.preventDefault();
            
            // Optionally, paste only what fits
            var availableSpace = maxLength - (currentValue.length - (selectionEnd - selectionStart));
            if (availableSpace > 0) {
                var truncatedText = pastedText.substring(0, availableSpace);
                var finalValue = currentValue.substring(0, selectionStart) + truncatedText + currentValue.substring(selectionEnd);
                input.value = finalValue;
                input.setSelectionRange(selectionStart + truncatedText.length, selectionStart + truncatedText.length);
            }
            
            return false;
        }
        
        return true;
    }

    /* Turbo: turbo-init dispatches once per navigation (no duplicate DOMContentLoaded + page-init). */
    document.addEventListener('printflow:page-init', printflowInitProfilePage);
    if (typeof window.Turbo === 'undefined') {
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', printflowInitProfilePage, { once: true });
        } else {
            printflowInitProfilePage();
        }
    }
</script>

</body>
</html>
