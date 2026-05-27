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
    --pf-accent: #06A1A1;
    --pf-accent-hover: #058f8f;
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
    box-shadow: 0 8px 15px rgba(6, 161, 161, 0.1);
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
    box-shadow: 0 4px 10px rgba(6, 161, 161, 0.3);
    border: 3px solid #fff;
    transition: 0.2s;
}
.avatar-edit-btn:hover { background: var(--pf-accent-hover); transform: scale(1.1); }

.profile-name { font-size: 1.4rem; font-weight: 800; color: var(--pf-text-main); margin-bottom: 0.25rem; }
.profile-email { font-size: 0.85rem; color: var(--pf-text-muted); margin-bottom: 1.5rem; word-break: break-all; }
.info-pill { display: flex; justify-content: space-between; padding: 0.875rem 0; border-top: 1px solid #f1f5f9; font-size: 0.85rem; }

/* ─ MAIN CONTENT ─ */
.profile-section-card { background: transparent; padding: 0; margin-bottom: 2rem; }
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

.alert-item { padding: 1rem 1.25rem; border-radius: 0; margin-bottom: 2rem; display: flex; align-items: center; gap: 12px; font-size: 0.9rem; font-weight: 600; }
.alert-item.error { background: #fef2f2; color: #991b1b; border: 1px solid #fee2e2; }
.alert-item.success { background: #f0fdf4; color: #166534; border: 1px solid #dcfce7; }

.id-preview-box { background: #f8fafc; border: 1px solid #cbd5e1; border-radius: 0; padding: 2rem; text-align: center; }

/* Modal Styles */
.id-modal { display:none; position:fixed; inset:0; background:rgba(0,0,0,0.8); z-index:9999; align-items:center; justify-content:center; padding:20px; }
.id-modal-content { max-width:100%; max-height:100%; background:#fff; position:relative; box-shadow: 0 20px 50px rgba(0,0,0,0.5); }
.id-modal-close { position:absolute; top:-40px; right:0; color:#fff; font-size:30px; cursor:pointer; font-weight:700; }
.id-modal-img { display:block; max-width:85vw; max-height:85vh; border: 4px solid #fff; }
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
                                    <?php if (!empty($user['profile_picture'])): ?>
                                        <img src="<?php echo get_profile_image($user['profile_picture']); ?>?t=<?php echo time(); ?>" alt="Avatar" id="profile-preview" onerror="this.onerror=null;this.src='<?php echo htmlspecialchars(BASE_PATH . '/public/assets/uploads/profiles/default.png', ENT_QUOTES, 'UTF-8'); ?>'">
                                    <?php else: ?>
                                        <div style="width:100%;height:100%;display:flex;align-items:center;justify-content:center;background:rgba(255,255,255,0.05);">
                                            <svg width="60" height="60" fill="none" stroke="var(--pf-accent)" viewBox="0 0 24 24" style="opacity:0.4;"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/></svg>
                                        </div>
                                        <img src="" alt="Profile" style="display:none;width:100%;height:100%;object-fit:cover;" id="profile-preview">
                                    <?php endif; ?>
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
                    </aside>

                    <!-- ── MAIN CONTENT (RIGHT) ── -->
                    <div class="profile-main-inner">
                        <form method="POST" action="" enctype="multipart/form-data" id="profileForm">
                            <?php echo csrf_field(); ?>
                            <input type="hidden" name="update_profile" value="1">
                            <input type="file" id="profile_picture" name="profile_picture" class="hidden" accept="image/*" style="display:none;" 
                                   onchange="const f=this.files[0];if(f){const r=new FileReader();r.onload=e=>{const p=document.getElementById('profile-preview');p.src=e.target.result;p.style.display='block';const s=p.previousElementSibling;if(s && s.tagName==='DIV'){s.style.display='none';}};r.readAsDataURL(f);}">

                            <!-- Section 1: Personal -->
                            <div class="profile-section-card">
                                <h3 class="section-title">Personal Information</h3>
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
                            </div>

                            <!-- Section 2: Address -->
                            <div class="profile-section-card">
                                <h3 class="section-title">Work Address / Location</h3>
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
                            </div>

                            <!-- Section 3: Identity Verification (Staff Specific) -->
                            <?php if ($is_pending || !empty($user['id_validation_image'])): ?>
                            <div class="profile-section-card">
                                <h3 class="section-title">ID Verification</h3>
                                <?php if ($needs_id): ?>
                                    <div class="id-preview-box">
                                        <p style="font-size:0.85rem; color:var(--pf-text-muted); margin-bottom:1rem;">Please upload a clear photo of your valid ID for verification.</p>
                                        <label class="btn-teal-save" style="cursor:pointer; display:inline-flex;">
                                            <input type="file" name="id_image" accept="image/*" class="hidden" required onchange="this.nextElementSibling.textContent = this.files[0].name">
                                            <span>Upload ID Image</span>
                                        </label>
                                    </div>
                                <?php else: ?>
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
                                <?php endif; ?>
                            </div>
                            <?php endif; ?>

                            <div style="display:flex; justify-content:flex-end; padding-top:1rem;">
                                <button type="submit" class="btn-teal-save">Save Profile Changes</button>
                            </div>
                        </form>

                        <div style="margin-top: 5rem; padding-top: 3rem; border-top: 1px solid var(--pf-border);">
                            <form method="POST" action="">
                                <?php echo csrf_field(); ?>
                                <input type="hidden" name="change_password" value="1">
                                <h3 class="section-title">Security & Password</h3>
                                <div class="form-grid-layout">
                                    <div class="field-wrap">
                                        <label class="field-label">Current Password</label>
                                        <input type="password" name="current_password" class="form-input" required>
                                    </div>
                                    <div class="field-wrap" style="display:flex; align-items:flex-end;">
                                        <p style="font-size:0.8rem; color:var(--pf-text-muted);">Confirm identity to update security.</p>
                                    </div>
                                    <div class="field-wrap">
                                        <label class="field-label">New Password</label>
                                        <input type="password" name="new_password" class="form-input" required minlength="8">
                                    </div>
                                    <div class="field-wrap">
                                        <label class="field-label">Confirm New Password</label>
                                        <input type="password" name="confirm_password" class="form-input" required minlength="8">
                                    </div>
                                </div>
                                <div style="display:flex; justify-content:flex-end; margin-top:2rem;">
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
