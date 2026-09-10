<?php
/**
 * Staff Profile Page
 * PrintFlow - Printing Shop PWA
 */

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

require_role('Staff');
require_once __DIR__ . '/../includes/staff_pending_check.php';

$user_id = get_user_id();
$error = '';
$success = '';

$urows = db_query("SELECT * FROM users WHERE user_id = ?", 'i', [$user_id]);
$user = $urows[0] ?? null;
if (!$user) {
    redirect(AUTH_REDIRECT_BASE . '/');
    exit;
}
// Match session to DB (banner + sidebar use session in some places)
$_SESSION['user_status'] = $user['status'] ?? ($_SESSION['user_status'] ?? 'Pending');
$is_pending = ($user['status'] ?? '') === 'Pending';
$needs_id = $is_pending && empty($user['id_validation_image'] ?? '');

$max_birthday = date('Y-m-d', strtotime('-18 years'));
$min_birthday = date('Y-m-d', strtotime('-70 years'));
$contact_display = (string)($user['contact_number'] ?? '');
$contact_digits = preg_replace('/\D/', '', $contact_display);
if (strlen($contact_digits) === 12 && strncmp($contact_digits, '63', 2) === 0) {
    $contact_display = '0' . substr($contact_digits, 2);
} elseif (strlen($contact_digits) === 10 && ($contact_digits[0] ?? '') === '9') {
    $contact_display = '0' . $contact_digits;
} elseif (strlen($contact_digits) === 11 && strncmp($contact_digits, '09', 2) === 0) {
    $contact_display = $contact_digits;
}

// Parse address for province/city/barangay
$addressProvince = $addressCity = $addressBarangay = $addressLine = '';
if (!empty($user['address'])) {
    $parts = array_values(array_filter(array_map('trim', explode(',', $user['address'])), static fn($p) => $p !== ''));
    if (count($parts) >= 4 && strcasecmp(end($parts), 'Philippines') === 0) {
        $addressProvince = $parts[count($parts) - 2] ?? '';
        $addressCity = $parts[count($parts) - 3] ?? '';
        $addressBarangay = preg_replace('/^Brgy\.?\s*/i', '', (string)($parts[count($parts) - 4] ?? ''));
        $addressLine = implode(', ', array_slice($parts, 0, -4));
    } else {
        $addressLine = $user['address'];
    }
}

// Handle profile update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_profile'])) {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $error = 'Invalid request. Please try again.';
    } else {
        $first_name = sanitize($_POST['first_name'] ?? '');
        $middle_name = sanitize($_POST['middle_name'] ?? '');
        $last_name = sanitize($_POST['last_name'] ?? '');
        $contact_number = preg_replace('/[^0-9]/', '', trim($_POST['contact_number'] ?? ''));
        if (strlen($contact_number) === 12 && strncmp($contact_number, '63', 2) === 0) {
            $contact_number = '0' . substr($contact_number, 2);
        } elseif (strlen($contact_number) === 10 && ($contact_number[0] ?? '') === '9') {
            $contact_number = '0' . $contact_number;
        }
        $address_province = trim($_POST['address_province'] ?? '');
        $address_city = trim($_POST['address_city'] ?? '');
        $address_barangay = trim($_POST['address_barangay'] ?? '');
        $address_line = trim($_POST['address_line'] ?? '');
        $gender = trim($_POST['gender'] ?? '');
        $profile_picture = $user['profile_picture'];
        
        $first_name = ucwords(strtolower(trim($first_name)));
        $middle_name = ucwords(strtolower(trim($middle_name)));
        $last_name = ucwords(strtolower(trim($last_name)));

        // Handle profile picture upload
        if (!empty($_FILES['profile_picture']['tmp_name']) && $_FILES['profile_picture']['error'] === UPLOAD_ERR_OK) {
            $finfo = new finfo(FILEINFO_MIME_TYPE);
            $mime = $finfo->file($_FILES['profile_picture']['tmp_name']);
            $allowed = ['image/jpeg', 'image/png', 'image/webp'];
            
            if (!in_array($mime, $allowed)) {
                $error = 'Profile picture must be JPG, PNG, or WEBP.';
            } elseif ($_FILES['profile_picture']['size'] > 2 * 1024 * 1024) {
                $error = 'Profile picture must be under 2MB.';
            } else {
                $ext = pathinfo($_FILES['profile_picture']['name'], PATHINFO_EXTENSION);
                $new_filename = 'staff_' . $user_id . '_' . time() . '.' . $ext;
                $upload_dir = __DIR__ . '/../public/assets/uploads/profiles/';
                
                if (!is_dir($upload_dir)) mkdir($upload_dir, 0755, true);
                
                if (move_uploaded_file($_FILES['profile_picture']['tmp_name'], $upload_dir . $new_filename)) {
                    // Delete old picture if exists
                    if (!empty($user['profile_picture']) && file_exists($upload_dir . $user['profile_picture'])) {
                        unlink($upload_dir . $user['profile_picture']);
                    }
                    $profile_picture = $new_filename;
                    $_SESSION['user_profile_picture'] = $profile_picture;
                } else {
                    $error = 'Failed to upload profile picture.';
                }
            }
        }

        $addressParts = [];
        if ($address_line !== '') $addressParts[] = $address_line;
        if ($address_barangay !== '') $addressParts[] = 'Brgy. ' . $address_barangay;
        if ($address_city !== '') $addressParts[] = $address_city;
        if ($address_province !== '') $addressParts[] = $address_province;
        $addressParts[] = 'Philippines';
        $address = implode(', ', $addressParts);
        
        // Name validation: letters and spaces only
        $nameRegex = '/^[A-Za-z]+(?: [A-Za-z]+)*$/';
        $contactRegex = '/^09\d{9}$/';

        // Backend strong validation
        if (empty($first_name)) {
            $error = 'First name is required.';
        } elseif (!preg_match($nameRegex, $first_name)) {
            $error = 'First name must contain letters only.';
        } elseif (strlen($first_name) < 2 || strlen($first_name) > 50) {
            $error = 'First name must be between 2 and 50 characters.';
        } elseif (!empty($middle_name) && !preg_match($nameRegex, $middle_name)) {
            $error = 'Middle name must contain letters only.';
        } elseif (!empty($middle_name) && (strlen($middle_name) < 1 || strlen($middle_name) > 50)) {
            $error = 'Middle name must be between 1 and 50 characters.';
        } elseif (empty($last_name)) {
            $error = 'Last name is required.';
        } elseif (!preg_match($nameRegex, $last_name)) {
            $error = 'Last name must contain letters only.';
        } elseif (strlen($last_name) < 2 || strlen($last_name) > 50) {
            $error = 'Last name must be between 2 and 50 characters.';
        } elseif (empty($contact_number) || !preg_match($contactRegex, $contact_number)) {
            $error = 'Valid contact number required (09XXXXXXXXX).';
        }
        
        if (!$error && contact_phone_in_use_across_accounts($contact_number, null, $user_id)) {
            $error = 'This contact number is already used by another account.';
        }
        
        if (!$error && (strip_tags($first_name) !== $first_name || strip_tags($last_name) !== $last_name || strip_tags($middle_name) !== $middle_name)) {
            $error = 'Invalid characters detected in name fields.';
        }
        
        // Handle ID image upload
        if (!$error) {
            $id_filename = $user['id_validation_image'] ?? null;
            if (!empty($_FILES['id_image']['tmp_name']) && $_FILES['id_image']['error'] === UPLOAD_ERR_OK) {
                $finfo = new finfo(FILEINFO_MIME_TYPE);
                $mime = $finfo->file($_FILES['id_image']['tmp_name']);
                $allowed = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
                if (!in_array($mime, $allowed)) {
                    $error = 'ID image must be JPG, PNG, GIF, or WEBP.';
                } elseif ($_FILES['id_image']['size'] > 5 * 1024 * 1024) {
                    $error = 'ID image must be under 5MB.';
                } else {
                    $ext = pathinfo($_FILES['id_image']['name'], PATHINFO_EXTENSION) ?: 'jpg';
                    $id_filename = 'id_user_' . $user_id . '_' . time() . '.' . $ext;
                    $upload_dir = __DIR__ . '/../uploads/ids/';
                    if (!is_dir($upload_dir)) mkdir($upload_dir, 0755, true);
                    if (!move_uploaded_file($_FILES['id_image']['tmp_name'], $upload_dir . $id_filename)) {
                        $error = 'Failed to save ID image. Please try again.';
                        $id_filename = null;
                    }
                }
            }
        }
        
        // Validate birthday
        if (!$error) {
            $id_to_save = $id_filename ?? $user['id_validation_image'] ?? null;
            $birthday = trim($_POST['birthday'] ?? '');
            if ($birthday === '') {
                $error = 'Birthday is required.';
            } else {
                $bday_date = DateTime::createFromFormat('Y-m-d', $birthday);
                $bday_errors = DateTime::getLastErrors();
                if (!$bday_date || ($bday_errors['warning_count'] ?? 0) > 0 || ($bday_errors['error_count'] ?? 0) > 0) {
                    $error = 'Invalid birthday format.';
                } else {
                    $today = new DateTime();
                    $age = $today->diff($bday_date)->y;
                    if ($bday_date > $today) {
                        $error = 'Birthday cannot be a future date.';
                    } elseif ($age < 18) {
                        $error = 'User must be at least 18 years old.';
                    } elseif ($age > 70) {
                        $error = 'User must be 70 years old or younger.';
                    }
                }
            }
        }
        
        // Only proceed with database update if no errors
        if (!$error) {
            $result = db_execute(
                "UPDATE users SET first_name=?, middle_name=?, last_name=?, contact_number=?, birthday=?, address=?, gender=?, id_validation_image=?, profile_picture=?, updated_at=NOW() WHERE user_id=?",
                'sssssssssi',
                [$first_name, $middle_name, $last_name, $contact_number, $birthday, $address, $gender, $id_to_save, $profile_picture, $user_id]
            );
            if ($result) {
                $success = 'Profile updated successfully!';
                $_SESSION['user_name'] = $first_name . ' ' . $last_name;
                $user = db_query("SELECT * FROM users WHERE user_id = ?", 'i', [$user_id])[0];
                $_SESSION['user_status'] = $user['status'] ?? $_SESSION['user_status'];
                $is_pending = ($user['status'] ?? '') === 'Pending';
                $needs_id = false;
                if ($is_pending && $id_filename) {
                    $full_name = trim($first_name . ' ' . ($middle_name ?? '') . ' ' . $last_name);
                    $msg = $full_name . ' (' . $user['email'] . ') has completed their profile and is ready for admin review.';
                    $admins = db_query("SELECT user_id, role FROM users WHERE role = 'Admin' AND status = 'Activated'");
                    foreach ($admins as $a) {
                        $recipType = $a['role'] ?? 'Admin';
                        create_notification((int)$a['user_id'], $recipType, $msg, 'System', true, false, (int)$user_id);
                    }
                }
            } else {
                $error = 'Failed to update profile';
            }
        }
    }
}

// Handle password change
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['change_password'])) {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $error = 'Invalid request. Please try again.';
    } else {
        $current_password = $_POST['current_password'] ?? '';
        $new_password = $_POST['new_password'] ?? '';
        $confirm_password = $_POST['confirm_password'] ?? '';
        
        if (!password_verify($current_password, $user['password_hash'])) {
            $error = 'Current password is incorrect';
        } elseif (strlen($new_password) < 8 || !preg_match('/[A-Z]/', $new_password) || !preg_match('/[a-z]/', $new_password) || !preg_match('/\d/', $new_password)) {
            $error = 'New password must be at least 8 characters and include uppercase, lowercase, and a number';
        } elseif ($new_password !== $confirm_password) {
            $error = 'New passwords do not match';
        } else {
            $password_hash = password_hash($new_password, PASSWORD_BCRYPT);
            $result = db_execute("UPDATE users SET password_hash = ? WHERE user_id = ?", 'si', [$password_hash, $user_id]);
            
            if ($result !== false) {
                $success = 'Password changed successfully!';
                log_activity($user_id, 'Password Change', 'Staff member changed password');
            } else {
                $error = 'Failed to change password';
            }
        }
    }
}

$staff_access_meta = function_exists('printflow_get_staff_access_meta') ? printflow_get_staff_access_meta() : ['short_label' => 'Staff'];
$staff_role_label = printflow_staff_role_display_name($user['role'] ?? 'Staff', $user['position'] ?? null);
$staff_status = (string)($user['status'] ?? 'Pending');
$staff_status_color = $staff_status === 'Activated' ? '#16a34a' : '#b45309';
if (!empty($user['id_validation_image'] ?? '')) {
    if ($staff_status === 'Activated') {
        $id_status_label = 'Verified';
        $id_status_color = '#16a34a';
    } else {
        $id_status_label = 'Pending Review';
        $id_status_color = '#b45309';
    }
} else {
    $id_status_label = 'Not Submitted';
    $id_status_color = '#b45309';
}
$staff_branch_label = '';
if (file_exists(__DIR__ . '/../includes/branch_context.php')) {
    require_once __DIR__ . '/../includes/branch_context.php';
    $staff_branch_label = (string)(init_branch_context(false)['branch_name'] ?? '');
}
if ($staff_branch_label === '' || $staff_branch_label === 'All Branches') {
    $staff_branch_label = !empty($user['branch_id']) ? ('Branch #' . $user['branch_id']) : 'Not assigned';
}
$page_title = 'My Profile - Staff';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $page_title; ?></title>
    <?php include __DIR__ . '/../includes/admin_style.php'; ?>
    <style>
/* ── Modern Staff Profile (Light Enterprise Layout) ─── */
:root {
    --pf-bg: #f8fafc;
    --pf-card: #ffffff;
    --pf-accent: var(--staff-primary, #06A1A1);
    --pf-accent-hover: var(--staff-primary-strong, #058f8f);
    --pf-text-main: #1e293b;
    --pf-text-muted: #64748b;
    --pf-border: #e2e8f0;
    --pf-input-bg: #ffffff;
}

.main-content { background: var(--pf-bg); color: var(--pf-text-main); }

/* 1. SINGLE MAIN CONTAINER */
.profile-container {
    max-width: 1100px;
    margin: 20px auto;
    padding: 1.5rem;
    background: var(--pf-card);
    border-radius: 0;
    border: 1px solid var(--pf-border);
    box-shadow: 0 4px 20px rgba(0, 0, 0, 0.05);
}

/* 2. LAYOUT STRUCTURE */
.profile-grid-main {
    display: grid;
    grid-template-columns: 280px 1fr;
    gap: 1.5rem;
    align-items: start;
}

@media (max-width: 992px) {
    .profile-grid-main { grid-template-columns: 1fr; gap: 2rem; }
    .profile-container { padding: 1.5rem; }
    .profile-sidebar-wrap {
        position: static !important;
        top: auto !important;
    }
}

/* ─ SIDEBAR (LEFT SIDE) ─ */
.profile-sidebar-wrap {
    position: sticky;
    top: 20px;
    align-self: start;
}
.profile-sidebar-content {
    text-align: center;
    padding: 1.5rem;
    background: #fcfdfe;
    border-radius: 0;
    border: 1px solid var(--pf-border);
}

.avatar-upload-wrap {
    position: relative;
    display: inline-block;
    margin-bottom: 1.5rem;
}

.avatar-ring {
    width: 140px; height: 140px;
    border-radius: 0;
    overflow: hidden;
    background: #f1f5f9;
    border: 3px solid var(--pf-accent);
    box-shadow: 0 8px 15px rgba(var(--staff-accent-rgb, 6, 161, 161), 0.14);
    margin: 0 auto;
    transition: all 0.3s ease;
}
.avatar-ring img { width: 100%; height: 100%; object-fit: cover; }

.avatar-edit-btn {
    position: absolute;
    bottom: -5px; right: -5px;
    width: 36px; height: 36px;
    border-radius: 0;
    background: var(--pf-accent);
    color: #fff;
    display: flex; align-items: center; justify-content: center;
    cursor: pointer;
    box-shadow: 0 4px 10px rgba(var(--staff-accent-rgb, 6, 161, 161), 0.28);
    border: 3px solid #fff;
    transition: 0.2s;
}
.avatar-edit-btn:hover { background: var(--pf-accent-hover); transform: scale(1.1); }

.profile-name { font-size: 1.4rem; font-weight: 800; color: var(--pf-text-main); margin-bottom: 0.25rem; }
.profile-email { font-size: 0.85rem; color: var(--pf-text-muted); margin-bottom: 1.5rem; word-break: break-all; }
.info-pill { display: flex; justify-content: space-between; padding: 0.875rem 0; border-top: 1px solid #f1f5f9; font-size: 0.85rem; }

/* ─ MAIN CONTENT ─ */
.section-title { font-size: 0.85rem; font-weight: 800; color: var(--pf-accent); margin-bottom: 1.25rem; display: flex; align-items: center; gap: 8px; text-transform: uppercase; letter-spacing: 0.08em; border-bottom: 2px solid #f1f5f9; padding-bottom: 8px; }

.form-grid-layout { display: grid; grid-template-columns: repeat(2, 1fr); gap: 1rem; }
@media (max-width: 576px) { .form-grid-layout { grid-template-columns: 1fr; } }

.field-wrap { width: 100%; }
.field-label { display: block; font-size: 0.65rem; font-weight: 800; color: var(--pf-text-muted); margin-bottom: 6px; text-transform: uppercase; letter-spacing: 0.05em; }
.form-input { 
    width: 100%; padding: 10px 14px; border: 1px solid #cbd5e1; border-radius: 0;
    font-size: 0.95rem; color: #334155; background: var(--pf-input-bg);
    box-sizing: border-box; transition: 0.2s;
}
.form-input:focus { outline: none; border-color: var(--pf-accent); box-shadow: none; }
.form-input:disabled { opacity: 0.6; background: #f8fafc; cursor: not-allowed; }

.btn-teal-save { 
    padding: 10px 20px; border-radius: 0; border: none; background: var(--pf-accent); color: #fff; font-weight: 800;
    cursor: pointer; transition: 0.2s; display: inline-flex; align-items: center; gap: 8px; font-size: 0.85rem; text-transform: uppercase; letter-spacing: 0.05em;
}
.btn-teal-save:hover { background: var(--pf-accent-hover); transform: none; box-shadow: none; }

html.printflow-staff.printflow-staff-pos .section-title,
html.printflow-staff.printflow-staff-pos .info-pill span[style*="var(--pf-accent)"],
html.printflow-staff.printflow-staff-pos .avatar-ring svg {
    color: var(--staff-primary) !important;
    stroke: var(--staff-primary) !important;
}

.alert-item { padding: 1rem 1.25rem; border-radius: 0; margin-bottom: 2rem; display: flex; align-items: center; gap: 12px; font-size: 0.9rem; font-weight: 600; }
.alert-item.error { background: #fef2f2; color: #991b1b; border: 1px solid #fee2e2; }
.alert-item.success { background: #f0fdf4; color: #166534; border: 1px solid #dcfce7; }

.id-preview-box { background: #f8fafc; border: 1px solid #cbd5e1; border-radius: 0; padding: 2rem; text-align: center; }

/* Modal Styles */
.id-modal { display:none; position:fixed; inset:0; background:rgba(0,0,0,0.8); z-index:9999; align-items:center; justify-content:center; padding:20px; }
.id-modal-content { max-width:100%; max-height:100%; background:#fff; position:relative; box-shadow: 0 20px 50px rgba(0,0,0,0.5); }
.id-modal-close { position:absolute; top:-40px; right:0; color:#fff; font-size:30px; cursor:pointer; font-weight:700; }
.id-modal-img { display:block; max-width:85vw; max-height:85vh; border: 4px solid #fff; }

/* Customer-profile-aligned section navigation and cards */
.profile-container {
    padding: 2.5rem;
    border-radius: 16px;
    box-shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.05), 0 8px 10px -6px rgba(0, 0, 0, 0.05);
    font-size: 0.813rem;
    color: #64748b;
    line-height: 1.5;
}
.profile-grid-main {
    grid-template-columns: 280px minmax(0, 1fr);
    gap: 2.5rem;
}
.profile-sidebar-wrap {
    display: flex;
    flex-direction: column;
    gap: 1.5rem;
}
.profile-sidebar-content,
.profile-nav-card {
    background: #f8fafc;
    border-radius: 12px;
    border: 1px solid var(--pf-border);
}
.profile-sidebar-content {
    padding: 1.5rem;
}
.avatar-ring {
    width: 130px;
    height: 130px;
    border-radius: 50%;
    background: #fff;
    border: 3px solid #fff;
    box-shadow: 0 4px 12px rgba(0,0,0,0.1);
}
.avatar-edit-btn {
    bottom: 5px;
    right: 5px;
    width: 32px;
    height: 32px;
    border-radius: 50%;
    border-width: 2px;
}
.profile-name {
    font-size: inherit;
    font-weight: 700;
    color: #0f172a;
    word-break: break-word;
}
.profile-email {
    font-size: inherit;
    color: inherit;
    margin-bottom: 1rem;
}
.info-pill {
    gap: 1rem;
    padding: 0.75rem 0;
    border-top-color: #e2e8f0;
    font-size: inherit;
    text-align: left;
}
.info-pill span:last-child {
    text-align: right;
    overflow-wrap: anywhere;
}
.profile-main-inner {
    display: flex;
    flex-direction: column;
    gap: 2rem;
    min-width: 0;
}
.profile-section-card {
    display: none;
    background: #fff;
    padding: 1.5rem;
    border: 1px solid var(--pf-border);
    border-radius: 14px;
    box-shadow: 0 8px 24px rgba(15, 23, 42, 0.04);
    margin-bottom: 0;
}
.profile-section-card.is-active {
    display: block;
}
.section-title {
    font-size: inherit;
    font-weight: 700;
    color: #0f172a;
    margin: 0 0 0.5rem;
    text-transform: none;
    letter-spacing: 0;
    border-bottom: 0;
    padding-bottom: 0;
}
.section-description {
    font-size: inherit;
    color: inherit;
    margin: 0 0 1.5rem;
    line-height: 1.5;
}
.field-label {
    font-size: inherit;
    font-weight: 400;
    color: inherit;
    margin-bottom: 8px;
    text-transform: none;
    letter-spacing: 0;
}
.form-input {
    border-radius: 8px;
    font-size: inherit;
    color: #0f172a;
}
.form-input:focus {
    box-shadow: 0 0 0 3px rgba(var(--staff-accent-rgb, 6, 161, 161), 0.1);
}
.btn-teal-save {
    padding: 7px 24px;
    border-radius: 3px;
    background: #0a2530;
    font-size: inherit;
    font-weight: 600;
    text-transform: none;
    letter-spacing: 0;
    min-height: 40px;
    justify-content: center;
    text-decoration: none;
}
.btn-teal-save:hover {
    opacity: 0.9;
}
.profile-nav-card {
    padding: 0.5rem;
}
.profile-nav-title {
    padding: 0.75rem 0.85rem 0.5rem;
    font-weight: 700;
    color: #0f172a;
}
.profile-nav-list {
    list-style: none;
    padding: 0;
    margin: 0;
}
.profile-nav-item a {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 10px 14px;
    border-radius: 8px;
    font-size: inherit;
    font-weight: 400;
    color: #64748b;
    text-decoration: none;
    transition: 0.2s;
}
.profile-nav-item a:hover {
    background: #fff;
    color: var(--pf-accent);
}
.profile-nav-item a.active {
    font-weight: 700;
    color: #0f172a;
    background: #fff;
    box-shadow: 0 2px 4px rgba(0,0,0,0.05);
}
.profile-status-grid {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 1rem;
}
.status-box {
    border: 1px solid var(--pf-border);
    border-radius: 12px;
    padding: 1rem;
    background: #f8fafc;
}
.status-box-label {
    display: block;
    color: #64748b;
    margin-bottom: 0.25rem;
}
.status-box-value {
    display: block;
    color: #0f172a;
    font-weight: 700;
    overflow-wrap: anywhere;
}
.profile-actions-row {
    display:flex;
    justify-content:flex-end;
    gap: 0.75rem;
    margin-top:1.5rem;
    flex-wrap: wrap;
}
.password-wrapper {
    position: relative;
    display: flex;
    align-items: center;
}
.password-wrapper input {
    padding-right: 44px;
}
.password-toggle {
    position: absolute;
    right: 6px;
    width: 32px;
    height: 32px;
    border: 0;
    border-radius: 8px;
    background: transparent;
    color: #64748b;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
}
.password-toggle:hover {
    background: #f1f5f9;
    color: #0f172a;
}
@media (max-width: 768px) {
    .profile-container {
        margin: 20px 1rem;
        padding: 1.5rem 1rem;
        border-radius: 12px;
    }
    .profile-status-grid {
        grid-template-columns: 1fr;
    }
    .profile-actions-row {
        justify-content: stretch;
    }
    .btn-teal-save {
        width: 100%;
        padding: 12px 20px;
        min-height: 48px;
    }
    .profile-nav-item a {
        padding: 12px 14px;
    }
    .form-input {
        padding: 12px;
        min-height: 44px;
    }
}
    </style>
</head>
<body data-turbo="false" class="printflow-staff">

<div class="dashboard-container">
    <?php include __DIR__ . '/../includes/staff_sidebar.php'; ?>

    <div class="main-content">
        <header>
            <div>
                <h1 class="page-title">Personal Profile</h1>
                <p class="page-subtitle">Manage your account information, work address, and security settings</p>
            </div>
        </header>

        <div class="content-area">
            <div class="profile-container">

                <?php if ($error): ?>
                <div class="alert-item error"><strong>Error:</strong> <?php echo htmlspecialchars($error); ?></div>
                <?php endif; ?>
                
                <?php if ($success): ?>
                <div class="alert-item success"><strong>Success:</strong> <?php echo htmlspecialchars($success); ?></div>
                <?php endif; ?>

                <div class="profile-grid-main">
                    <!-- ── SIDEBAR (LEFT) ── -->
                    <aside class="profile-sidebar-wrap">
                        <div class="profile-sidebar-content">
                            <div class="avatar-upload-wrap">
                                <div class="avatar-ring">
                                    <?php
                                    $profile_avatar_url = !empty($user['profile_picture'])
                                        ? get_profile_image($user['profile_picture']) . '?t=' . time()
                                        : BASE_PATH . '/public/assets/uploads/profiles/default.png';
                                    ?>
                                    <img src="<?php echo htmlspecialchars($profile_avatar_url, ENT_QUOTES, 'UTF-8'); ?>" alt="Avatar" id="profile-preview" style="width:100%;height:100%;object-fit:cover;" onerror="this.onerror=null;this.src='<?php echo htmlspecialchars(BASE_PATH . '/public/assets/uploads/profiles/default.png', ENT_QUOTES, 'UTF-8'); ?>'">
                                    <div id="profile-avatar-placeholder" style="display:none;width:100%;height:100%;align-items:center;justify-content:center;background:rgba(255,255,255,0.05);">
                                        <svg width="60" height="60" fill="none" stroke="var(--pf-accent)" viewBox="0 0 24 24" style="opacity:0.4;"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/></svg>
                                    </div>
                                </div>
                                <label for="profile_picture" class="avatar-edit-btn" title="Change photo">
                                    <svg width="14" height="14" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 9a2 2 0 012-2h.93a2 2 0 001.664-.89l.812-1.22A2 2 0 0110.07 4h3.86a2 2 0 011.664.89l.812 1.22A2 2 0 0018.07 7H19a2 2 0 012 2v9a2 2 0 01-2 2H5a2 2 0 01-2-2V9z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 13a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
                                </label>
                            </div>
                            <div class="profile-name"><?php echo htmlspecialchars(trim(($user['first_name']??'') . ' ' . ($user['last_name']??''))); ?></div>
                            <div class="profile-email"><?php echo htmlspecialchars($user['email']??''); ?></div>
                            
                            <div style="margin-top: 1rem;">
                                <div class="info-pill">
                                    <span>Joined</span>
                                    <span style="font-weight:700; color:var(--pf-accent);"><?php echo isset($user['created_at']) ? date('M Y', strtotime($user['created_at'])) : '---'; ?></span>
                                </div>
                                <div class="info-pill" style="border-bottom: none;">
                                    <span>Status</span>
                                    <span style="font-weight:700; color:<?php echo $user['status']==='Activated' ? '#16a34a':'#fcd34d'; ?>;"><?php echo htmlspecialchars($user['status']??'Pending'); ?></span>
                                </div>
                            </div>
                        </div>
                        <div class="profile-nav-card">
                            <div class="profile-nav-title"><?php echo htmlspecialchars(($staff_access_meta['short_label'] ?? 'Staff') . ' Account'); ?></div>
                            <ul class="profile-nav-list">
                                <?php
                                $profile_nav_sections = [
                                    'section-profile' => 'Personal Information',
                                    'section-address' => 'Work Address / Location',
                                    'section-account' => 'Account Management',
                                    'section-security' => 'Security & Verification',
                                ];
                                foreach ($profile_nav_sections as $section_id => $section_label):
                                ?>
                                <li class="profile-nav-item">
                                    <a href="#<?php echo htmlspecialchars($section_id, ENT_QUOTES, 'UTF-8'); ?>" class="account-nav-link<?php echo $section_id === 'section-profile' ? ' active' : ''; ?>" data-section="<?php echo htmlspecialchars($section_id, ENT_QUOTES, 'UTF-8'); ?>">
                                        <?php echo htmlspecialchars($section_label); ?>
                                    </a>
                                </li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                    </aside>

                    <!-- ── MAIN CONTENT (RIGHT) ── -->
                    <div class="profile-main-inner">
                        <form method="POST" action="" enctype="multipart/form-data" id="profileForm">
                            <?php echo csrf_field(); ?>
                            <input type="hidden" name="update_profile" value="1">
                            <input type="file" id="profile_picture" name="profile_picture" class="hidden" accept="image/*" style="display:none;"
                                   onchange="const f=this.files[0];if(f){const r=new FileReader();r.onload=e=>{const p=document.getElementById('profile-preview');const ph=document.getElementById('profile-avatar-placeholder');if(p){p.src=e.target.result;p.style.display='block';}if(ph){ph.style.display='none';}};r.readAsDataURL(f);}">

                            <!-- Section 1: Personal -->
                            <div class="profile-section-card is-active" id="section-profile">
                                <h3 class="section-title">Personal Information</h3>
                                <p class="section-description">Update the identity details used across your staff account.</p>
                                <div class="form-grid-layout">
                                    <div class="field-wrap">
                                        <label class="field-label">First Name</label>
                                        <input type="text" name="first_name" class="form-input" required value="<?php echo htmlspecialchars($user['first_name']??''); ?>">
                                    </div>
                                    <div class="field-wrap">
                                        <label class="field-label">Middle Name</label>
                                        <input type="text" name="middle_name" class="form-input" value="<?php echo htmlspecialchars($user['middle_name']??''); ?>">
                                    </div>
                                    <div class="field-wrap">
                                        <label class="field-label">Last Name</label>
                                        <input type="text" name="last_name" class="form-input" required value="<?php echo htmlspecialchars($user['last_name']??''); ?>">
                                    </div>
                                    <div class="field-wrap">
                                        <label class="field-label">Email Address (Locked)</label>
                                        <input type="email" class="form-input" value="<?php echo htmlspecialchars($user['email']??''); ?>" disabled style="opacity: 0.6; cursor: not-allowed;">
                                    </div>
                                    <div class="field-wrap">
                                        <label class="field-label">Contact Number</label>
                                        <input type="tel" name="contact_number" id="profile_contact" class="form-input" placeholder="09XXXXXXXXX" maxlength="11" inputmode="numeric" required value="<?php echo htmlspecialchars($contact_display); ?>">
                                    </div>
                                    <div class="field-wrap">
                                        <div style="display:grid; grid-template-columns: 1fr 1fr; gap: 10px;">
                                            <div>
                                                <label class="field-label">Birthday</label>
                                                <input type="date" name="birthday" class="form-input" min="<?php echo $min_birthday; ?>" max="<?php echo $max_birthday; ?>" required value="<?php echo htmlspecialchars($user['birthday']??''); ?>">
                                            </div>
                                            <div>
                                                <label class="field-label">Gender</label>
                                                <select name="gender" class="form-input">
                                                    <option value="Male" <?php echo ($user['gender']??'') === 'Male' ? 'selected' : ''; ?>>Male</option>
                                                    <option value="Female" <?php echo ($user['gender']??'') === 'Female' ? 'selected' : ''; ?>>Female</option>
                                                    <option value="Other" <?php echo ($user['gender']??'') === 'Other' ? 'selected' : ''; ?>>Other</option>
                                                </select>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <div class="profile-actions-row">
                                    <button type="submit" class="btn-teal-save">Save Changes</button>
                                </div>
                            </div>

                            <!-- Section 2: Address -->
                            <div class="profile-section-card" id="section-address">
                                <h3 class="section-title">Work Address / Location</h3>
                                <p class="section-description">Keep your staff work address and location details current.</p>
                                <div class="form-grid-layout">
                                    <div class="field-wrap">
                                        <label class="field-label">Province</label>
                                        <select name="address_province" id="profile_province" class="form-input">
                                            <option value="">Select Province</option>
                                        </select>
                                    </div>
                                    <div class="field-wrap">
                                        <label class="field-label">City / Municipality</label>
                                        <select name="address_city" id="profile_city" class="form-input" disabled>
                                            <option value="">Select City</option>
                                        </select>
                                    </div>
                                    <div class="field-wrap">
                                        <label class="field-label">Barangay</label>
                                        <select name="address_barangay" id="profile_barangay" class="form-input" disabled>
                                            <option value="">Select Barangay</option>
                                        </select>
                                    </div>
                                    <div class="field-wrap">
                                        <label class="field-label">Street / Building Info</label>
                                        <input type="text" name="address_line" id="profile_address_line" class="form-input" placeholder="e.g. 123 Building Name" value="<?php echo htmlspecialchars($addressLine); ?>">
                                    </div>
                                </div>
                                <input type="hidden" name="address" id="profile_address" value="<?php echo htmlspecialchars($user['address']??''); ?>">
                                <div class="profile-actions-row">
                                    <button type="submit" class="btn-teal-save">Update Work Address</button>
                                </div>
                            </div>

                            <!-- Section 3: Security & Verification -->
                            <div class="profile-section-card" id="section-security">
                                <h3 class="section-title">Security & Verification</h3>
                                <p class="section-description">Review staff verification state and manage the ID document used for account activation checks.</p>
                                <div class="profile-status-grid" style="margin-bottom:1rem;">
                                    <div class="status-box">
                                        <span class="status-box-label">ID Verification Status</span>
                                        <span class="status-box-value" style="color:<?php echo htmlspecialchars($id_status_color, ENT_QUOTES, 'UTF-8'); ?>;"><?php echo htmlspecialchars($id_status_label); ?></span>
                                    </div>
                                    <div class="status-box">
                                        <span class="status-box-label">Account Status</span>
                                        <span class="status-box-value" style="color:<?php echo htmlspecialchars($staff_status_color, ENT_QUOTES, 'UTF-8'); ?>;"><?php echo htmlspecialchars($staff_status); ?></span>
                                    </div>
                                </div>
                                <?php if ($needs_id): ?>
                                    <div class="id-preview-box">
                                        <p style="font-size:0.85rem; color:var(--pf-text-muted); margin-bottom:1rem;">Please upload a clear photo of your valid ID for verification.</p>
                                        <label class="btn-teal-save" style="cursor:pointer; display:inline-flex;">
                                            <input type="file" name="id_image" accept="image/*" class="hidden" onchange="this.nextElementSibling.textContent = this.files[0].name">
                                            <span>Upload ID Image</span>
                                        </label>
                                    </div>
                                <?php elseif (!empty($user['id_validation_image'])): ?>
                                    <div style="display:flex; align-items:center; gap:20px; background:rgba(255,255,255,0.03); padding:1rem; border-radius:12px; border:1px solid var(--pf-border);">
                                        <div style="flex:1;">
                                            <p style="font-size:0.85rem; font-weight:700; color:var(--pf-accent);">ID VALIDATION IMAGE</p>
                                            <p style="font-size:0.75rem; color:var(--pf-text-muted);">Verified for staff authentication.</p>
                                        </div>
                                        <a href="javascript:void(0)" onclick="openIdModal('<?php echo htmlspecialchars(BASE_PATH . '/uploads/ids/' . $user['id_validation_image'], ENT_QUOTES, 'UTF-8'); ?>')" class="btn-teal-save" style="font-size:0.7rem; padding:8px 16px;">View Current ID</a>
                                    </div>
                                    <div style="margin-top:1rem;">
                                        <label class="field-label">Replace ID (Optional)</label>
                                        <input type="file" name="id_image" accept="image/*" class="form-input">
                                    </div>
                                <?php else: ?>
                                    <div class="id-preview-box">
                                        <p style="font-size:0.85rem; color:var(--pf-text-muted); margin:0;">No ID replacement is required for this staff account right now.</p>
                                    </div>
                                <?php endif; ?>
                                <div class="profile-actions-row">
                                    <button type="submit" class="btn-teal-save">Save Verification</button>
                                </div>
                            </div>

                        </form>

                        <div class="profile-section-card" id="section-account">
                            <form method="POST" action="">
                                <?php echo csrf_field(); ?>
                                <input type="hidden" name="change_password" value="1">
                                <h3 class="section-title">Account Management</h3>
                                <p class="section-description">Review protected staff account details and change your password.</p>
                                <div class="profile-status-grid" style="margin-bottom:1.5rem;">
                                    <div class="status-box">
                                        <span class="status-box-label">Staff Role</span>
                                        <span class="status-box-value"><?php echo htmlspecialchars($staff_role_label); ?></span>
                                    </div>
                                    <div class="status-box">
                                        <span class="status-box-label">Branch</span>
                                        <span class="status-box-value"><?php echo htmlspecialchars($staff_branch_label); ?></span>
                                    </div>
                                    <div class="status-box">
                                        <span class="status-box-label">Activation Status</span>
                                        <span class="status-box-value" style="color:<?php echo htmlspecialchars($staff_status_color, ENT_QUOTES, 'UTF-8'); ?>;"><?php echo htmlspecialchars($staff_status); ?></span>
                                    </div>
                                    <div class="status-box">
                                        <span class="status-box-label">Joined Date</span>
                                        <span class="status-box-value"><?php echo isset($user['created_at']) ? date('M d, Y', strtotime($user['created_at'])) : '---'; ?></span>
                                    </div>
                                </div>
                                <div class="form-grid-layout">
                                    <div class="field-wrap">
                                        <label class="field-label">Current Password</label>
                                        <div class="password-wrapper">
                                            <input type="password" name="current_password" id="staff_current_password" class="form-input" required autocomplete="current-password">
                                            <button type="button" class="password-toggle" onclick="toggleStaffPassword('staff_current_password', this)" title="Show password">
                                                <svg width="18" height="18" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/></svg>
                                            </button>
                                        </div>
                                    </div>
                                    <div class="field-wrap" style="display:flex; align-items:flex-end;">
                                        <p style="font-size:0.8rem; color:var(--pf-text-muted);">Confirm identity to update security.</p>
                                    </div>
                                    <div class="field-wrap">
                                        <label class="field-label">New Password</label>
                                        <div class="password-wrapper">
                                            <input type="password" name="new_password" id="staff_new_password" class="form-input" required minlength="8" autocomplete="new-password">
                                            <button type="button" class="password-toggle" onclick="toggleStaffPassword('staff_new_password', this)" title="Show password">
                                                <svg width="18" height="18" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/></svg>
                                            </button>
                                        </div>
                                    </div>
                                    <div class="field-wrap">
                                        <label class="field-label">Confirm New Password</label>
                                        <div class="password-wrapper">
                                            <input type="password" name="confirm_password" id="staff_confirm_password" class="form-input" required minlength="8" autocomplete="new-password">
                                            <button type="button" class="password-toggle" onclick="toggleStaffPassword('staff_confirm_password', this)" title="Show password">
                                                <svg width="18" height="18" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/></svg>
                                            </button>
                                        </div>
                                    </div>
                                </div>
                                <div class="profile-actions-row">
                                    <button type="submit" class="btn-teal-save">Update Password</button>
                                </div>
                            </form>
                        </div>

                    </div><!-- /main-inner -->
                </div><!-- /profile-grid-main -->
            </div><!-- /profile-container -->
        </div><!-- /content-area -->
    </div><!-- /main-content -->
</div><!-- /dashboard-container -->

<div id="idModal" class="id-modal" onclick="this.style.display='none'">
    <div class="id-modal-content" onclick="event.stopPropagation()">
        <span class="id-modal-close" onclick="document.getElementById('idModal').style.display='none'">&times;</span>
        <img src="" id="idModalImg" class="id-modal-img">
    </div>
</div>

<script>
(function() {
    window.openIdModal = function(src) {
        document.getElementById('idModalImg').src = src;
        document.getElementById('idModal').style.display = 'flex';
    };
    window.toggleStaffPassword = function(inputId, button) {
        const input = document.getElementById(inputId);
        if (!input) return;
        input.type = input.type === 'password' ? 'text' : 'password';
        if (button) button.title = input.type === 'password' ? 'Show password' : 'Hide password';
    };
    document.addEventListener('DOMContentLoaded', function () {
        const panels = Array.from(document.querySelectorAll('.profile-section-card'));
        const navLinks = Array.from(document.querySelectorAll('.account-nav-link'));

        function activateSection(id) {
            panels.forEach(panel => panel.classList.toggle('is-active', panel.id === id));
            navLinks.forEach(link => link.classList.toggle('active', link.dataset.section === id));
        }

        navLinks.forEach(link => {
            link.addEventListener('click', function (event) {
                event.preventDefault();
                const id = this.dataset.section;
                const panel = id ? document.getElementById(id) : null;
                if (!panel) return;
                activateSection(id);
                history.replaceState(null, '', '#' + id);
                panel.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
            });
        });

        const hashSection = (window.location.hash || '').replace(/^#/, '');
        if (hashSection && document.getElementById(hashSection)) {
            activateSection(hashSection);
        }
    });
    const addrApi = <?php echo json_encode(BASE_PATH . '/public/api_address_public.php'); ?>;
    const prov = document.getElementById('profile_province');
    const city = document.getElementById('profile_city');
    const brgy = document.getElementById('profile_barangay');
    const line = document.getElementById('profile_address_line');
    const addrHidden = document.getElementById('profile_address');
    if (!prov) return;

    function buildAddress() {
        const p = [line?.value?.trim(), brgy?.value ? 'Brgy. ' + brgy.value : '', city?.value, prov?.value].filter(Boolean);
        if (addrHidden) addrHidden.value = p.length ? p.join(', ') + ', Philippines' : '';
    }

    const selProv = '<?php echo addslashes($addressProvince); ?>';
    const selCity = '<?php echo addslashes($addressCity); ?>';
    const selBrgy = '<?php echo addslashes($addressBarangay); ?>';

    async function loadProvinces() {
        const r = await fetch(addrApi + '?address_action=provinces');
        const d = await r.json();
        if (d.success && d.data) {
            prov.innerHTML = '<option value="">Select Province</option>' + d.data.map(x => '<option value="' + x.name + '" data-code="' + x.code + '">' + x.name + '</option>').join('');
            if (selProv) {
                prov.value = selProv;
                const opt = prov.options[prov.selectedIndex];
                await loadCities(opt ? opt.getAttribute('data-code') || '' : '');
            }
        }
    }
    async function loadCities(provinceCode) {
        if (!provinceCode) { city.innerHTML = '<option value="">Select City</option>'; city.disabled = true; brgy.innerHTML = '<option value="">Select Barangay</option>'; brgy.disabled = true; buildAddress(); return; }
        const r = await fetch(addrApi + '?address_action=cities&province_code=' + encodeURIComponent(provinceCode));
        const d = await r.json();
        if (d.success && d.data) {
            city.innerHTML = '<option value="">Select City</option>' + d.data.map(x => '<option value="' + x.name + '" data-code="' + x.code + '">' + x.name + '</option>').join('');
            city.disabled = false;
            brgy.innerHTML = '<option value="">Select Barangay</option>';
            brgy.disabled = true;
            if (selCity) {
                city.value = selCity;
                const cOpt = city.options[city.selectedIndex];
                await loadBarangays(cOpt ? cOpt.getAttribute('data-code') || '' : '');
            }
        }
        buildAddress();
    }
    async function loadBarangays(cityCode) {
        if (!cityCode) { brgy.innerHTML = '<option value="">Select Barangay</option>'; brgy.disabled = true; buildAddress(); return; }
        const r = await fetch(addrApi + '?address_action=barangays&city_code=' + encodeURIComponent(cityCode));
        const d = await r.json();
        if (d.success && d.data) {
            brgy.innerHTML = '<option value="">Select Barangay</option>' + d.data.map(x => '<option value="' + x.name + '">' + x.name + '</option>').join('');
            brgy.disabled = false;
            if (selBrgy) brgy.value = selBrgy;
        }
        buildAddress();
    }

    loadProvinces();
    prov.addEventListener('change', function() {
        const opt = prov.options[prov.selectedIndex];
        loadCities(opt?.value ? opt.getAttribute('data-code') : '');
    });
    city.addEventListener('change', function() {
        const opt = city.options[city.selectedIndex];
        loadBarangays(opt?.value ? opt.getAttribute('data-code') : '');
    });
    brgy.addEventListener('change', buildAddress);
    if (line) line.addEventListener('input', buildAddress);

    const contactInput = document.getElementById('profile_contact');
    function normalizeContact(val) {
        let v = val.replace(/\D/g, '');
        if (v === '') return '';
        if (v.startsWith('63')) v = '0' + v.slice(2);
        else if (v.startsWith('9')) v = '0' + v;
        if (!v.startsWith('09')) v = '09' + v.replace(/^0+/, '');
        return v.slice(0, 11);
    }
    function setContactValidity() {
        if (!contactInput) return;
        if (!/^09\d{9}$/.test(contactInput.value)) {
            contactInput.setCustomValidity('Use format 09XXXXXXXXX');
        } else {
            contactInput.setCustomValidity('');
        }
    }
    if (contactInput) {
        contactInput.addEventListener('focus', function() {
            if (!this.value) this.value = '09';
            this.value = normalizeContact(this.value);
            setContactValidity();
        });
        contactInput.addEventListener('input', function() {
            this.value = normalizeContact(this.value);
            setContactValidity();
        });
        contactInput.addEventListener('blur', setContactValidity);
    }

    const birthdayInput = document.querySelector('input[name="birthday"]');
    function setBirthdayValidity() {
        if (!birthdayInput) return;
        if (!birthdayInput.value) {
            birthdayInput.setCustomValidity('');
            return;
        }
        const dob = new Date(birthdayInput.value);
        const today = new Date();
        if (Number.isNaN(dob.getTime())) {
            birthdayInput.setCustomValidity('Invalid birthday');
            return;
        }
        let age = today.getFullYear() - dob.getFullYear();
        const m = today.getMonth() - dob.getMonth();
        if (m < 0 || (m === 0 && today.getDate() < dob.getDate())) age--;
        if (dob > today) {
            birthdayInput.setCustomValidity('Birthday cannot be in the future');
        } else if (age < 18) {
            birthdayInput.setCustomValidity('Must be at least 18 years old');
        } else if (age > 70) {
            birthdayInput.setCustomValidity('Must be 70 years old or younger');
        } else {
            birthdayInput.setCustomValidity('');
        }
    }
    if (birthdayInput) {
        birthdayInput.addEventListener('change', setBirthdayValidity);
        birthdayInput.addEventListener('input', setBirthdayValidity);
    }

    const pfForm = document.getElementById('profileForm');
    if (pfForm) pfForm.addEventListener('submit', buildAddress);

    window.setGender = function(btn, val) {
        document.querySelectorAll('.gender-btn').forEach(b => b.classList.remove('active'));
        btn.classList.add('active');
        document.getElementById('gender_input').value = val;
    };
})();
</script>
</body>
</html>
