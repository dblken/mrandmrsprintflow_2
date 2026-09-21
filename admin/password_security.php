<?php
/**
 * Admin Password & Security Page
 * PrintFlow - Printing Shop PWA
 */

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

require_role(['Admin', 'Manager']);

if (!isset($base_path)) {
    if (file_exists(__DIR__ . '/../config.php')) {
        require_once __DIR__ . '/../config.php';
    }
    $base_path = defined('BASE_PATH') ? BASE_PATH : '/printflow';
}

$admin_id = get_user_id();
$error = '';
$success = '';

if (!empty($_SESSION['password_security_success'])) {
    $success = (string)$_SESSION['password_security_success'];
    unset($_SESSION['password_security_success']);
}
if (!empty($_SESSION['password_security_error'])) {
    $error = (string)$_SESSION['password_security_error'];
    unset($_SESSION['password_security_error']);
}

$admin = db_query("SELECT user_id, first_name, last_name, email, role, password_hash, profile_picture FROM users WHERE user_id = ?", 'i', [$admin_id])[0] ?? null;
if (!$admin) {
    redirect($base_path . '/logout');
}

$user_initial = strtoupper(substr((string)$admin['first_name'], 0, 1));
$profile_pic_url = !empty($admin['profile_picture'])
    ? $base_path . '/public/assets/uploads/profiles/' . $admin['profile_picture']
    : '';

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
        $_SESSION['password_security_success'] = 'Password updated successfully!';
    }
    if ($pw_error !== '') {
        $_SESSION['password_security_error'] = $pw_error;
    }
    header('Location: password_security.php', true, 303);
    exit;
}

$page_title = 'Password & Security - PrintFlow Admin';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($page_title); ?></title>
    <link rel="stylesheet" href="<?php echo $base_path; ?>/public/assets/css/output.css">
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
        .profile-avatar-wrapper { position: relative; flex-shrink: 0; }
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
        .profile-avatar img { width: 100%; height: 100%; object-fit: cover; }
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
        .dashboard-container > .main-content {
            flex: 1 1 auto;
            min-width: 0;
            max-width: 100%;
        }
        .profile-columns {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
            align-items: start;
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
        .form-grid-full { grid-column: 1 / -1; }
        .form-group { margin-bottom: 16px; }
        .form-group label {
            display: block;
            font-size: 13px;
            font-weight: 600;
            color: #374151;
            margin-bottom: 6px;
        }
        .form-group input {
            width: 100%;
            padding: 10px 14px;
            border: 1px solid #e5e7eb;
            border-radius: 8px;
            font-size: 14px;
            transition: border-color 0.2s;
            background: #fff;
        }
        .form-group input:focus {
            outline: none;
            border-color: #53C5E0;
            box-shadow: 0 0 0 3px rgba(83, 197, 224, 0.2);
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
        .btn-save:disabled {
            opacity: 0.5;
            cursor: not-allowed;
            filter: grayscale(1);
            transform: none !important;
            box-shadow: none !important;
        }
        .alert {
            padding: 12px 16px;
            border-radius: 8px;
            font-size: 14px;
            margin-bottom: 16px;
        }
        .alert-success { background: #ecfdf5; color: #065f46; border: 1px solid #a7f3d0; }
        .alert-error { background: #fef2f2; color: #991b1b; border: 1px solid #fecaca; }
        .error-message {
            color: #ef4444;
            font-size: 11px;
            margin-top: 4px;
            display: none;
            font-weight: 500;
        }
        .form-group.is-invalid .error-message { display: block; }
        .password-wrapper { position: relative; }
        .password-wrapper input { padding-right: 45px !important; }
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
            z-index: 10;
        }
        .password-toggle:hover { color: #53C5E0; }
        .security-tip {
            font-size: 13px;
            color: #6b7280;
            margin: 0 0 16px;
            line-height: 1.5;
        }
        .security-tips-list {
            list-style: none;
            margin: 0;
            padding: 0;
            display: flex;
            flex-direction: column;
            gap: 12px;
        }
        .security-tips-list li {
            display: flex;
            align-items: flex-start;
            gap: 10px;
            font-size: 13px;
            color: #4b5563;
            line-height: 1.5;
        }
        .security-tips-list svg {
            flex-shrink: 0;
            color: #53C5E0;
            margin-top: 2px;
        }
        .btn-outline {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 10px 18px;
            border: 1px solid #53C5E0;
            color: #0891b2;
            background: #fff;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 600;
            text-decoration: none;
            transition: background 0.2s, color 0.2s;
            margin-top: 16px;
        }
        .btn-outline:hover {
            background: #ecfeff;
            color: #0e7490;
        }
        @media (max-width: 768px) {
            .profile-columns { grid-template-columns: 1fr; }
            .form-grid { grid-template-columns: 1fr; }
            .profile-hero {
                flex-direction: column;
                text-align: center;
                padding: 28px 18px;
                gap: 18px;
                border-radius: 12px;
            }
            .profile-avatar { width: 86px; height: 86px; font-size: 30px; }
            .section-card { padding: 18px; border-radius: 10px; }
            .profile-form-actions .btn-save { width: 100%; justify-content: center; }
        }
    </style>
</head>
<body>

<div class="dashboard-container">
    <?php include defined('MANAGER_PANEL') ? __DIR__ . '/../includes/manager_sidebar.php' : __DIR__ . '/../includes/admin_sidebar.php'; ?>

    <div class="main-content">
        <header>
            <h1 class="page-title">Password & Security</h1>
        </header>

        <main>
            <?php if ($error): ?>
                <div class="alert alert-error"><?php echo htmlspecialchars($error); ?></div>
            <?php endif; ?>
            <?php if ($success): ?>
                <div class="alert alert-success"><?php echo htmlspecialchars($success); ?></div>
            <?php endif; ?>

            <div class="profile-hero">
                <div class="profile-avatar-wrapper">
                    <div class="profile-avatar">
                        <?php if ($profile_pic_url): ?>
                            <img src="<?php echo htmlspecialchars($profile_pic_url); ?>?t=<?php echo time(); ?>" alt="Profile">
                        <?php else: ?>
                            <?php echo htmlspecialchars($user_initial); ?>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="profile-hero-info">
                    <h2><?php echo htmlspecialchars(trim($admin['first_name'] . ' ' . $admin['last_name'])); ?></h2>
                    <p><?php echo htmlspecialchars($admin['email']); ?></p>
                    <div class="role-badge">
                        <svg width="12" height="12" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/></svg>
                        <?php echo htmlspecialchars($admin['role']); ?>
                    </div>
                </div>
            </div>

            <div class="profile-columns">
                <div class="section-card">
                    <div class="section-title">
                        <svg width="18" height="18" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/></svg>
                        Change Password
                    </div>
                    <p class="security-tip">Enter your current password, then choose a new one.</p>

                    <form method="POST" id="passwordForm" onsubmit="return validatePasswordForm(event)">
                        <?php echo csrf_field(); ?>
                        <input type="hidden" name="change_password" value="1">

                        <div class="form-grid">
                            <div class="form-group form-grid-full" id="group_current_password">
                                <label>Current Password *</label>
                                <div class="password-wrapper">
                                    <input type="password" name="current_password" id="current_password" required autocomplete="current-password">
                                    <button type="button" class="password-toggle" onclick="togglePassword('current_password', this)" aria-label="Show password">
                                        <svg width="18" height="18" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/></svg>
                                    </button>
                                </div>
                                <div class="error-message" id="error_current_password">Current password is required.</div>
                            </div>

                            <div class="form-group" id="group_new_password">
                                <label>New Password *</label>
                                <div class="password-wrapper">
                                    <input type="password" name="new_password" id="new_password" required autocomplete="new-password">
                                    <button type="button" class="password-toggle" onclick="togglePassword('new_password', this)" aria-label="Show password">
                                        <svg width="18" height="18" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/></svg>
                                    </button>
                                </div>
                                <p style="font-size:11px;color:#9ca3af;margin-top:4px;">Min. 8 chars, 1 uppercase, 1 lowercase, 1 number, 1 symbol</p>
                                <div class="error-message" id="error_new_password">Invalid password format.</div>
                            </div>

                            <div class="form-group" id="group_confirm_password">
                                <label>Confirm New Password *</label>
                                <div class="password-wrapper">
                                    <input type="password" name="confirm_password" id="confirm_password" required autocomplete="new-password">
                                    <button type="button" class="password-toggle" onclick="togglePassword('confirm_password', this)" aria-label="Show password">
                                        <svg width="18" height="18" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/></svg>
                                    </button>
                                </div>
                                <div class="error-message" id="error_confirm_password">Passwords do not match.</div>
                            </div>
                        </div>

                        <div class="profile-form-actions">
                            <button type="submit" class="btn-save" id="btn_update_password">
                                <svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/></svg>
                                Update Password
                            </button>
                        </div>
                    </form>
                </div>

                <div class="section-card">
                    <div class="section-title">
                        <svg width="18" height="18" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/></svg>
                        Security Tips
                    </div>
                    <ul class="security-tips-list">
                        <li>
                            <svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                            Use a unique password you do not reuse on other sites.
                        </li>
                        <li>
                            <svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                            Mix uppercase, lowercase, numbers, and symbols.
                        </li>
                        <li>
                            <svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                            Avoid personal info like birthdays or names in your password.
                        </li>
                        <li>
                            <svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                            Log out when using shared or public devices.
                        </li>
                    </ul>
                    <a href="profile.php" class="btn-outline">
                        <svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/></svg>
                        Back to My Profile
                    </a>
                </div>
            </div>
        </main>
    </div>
</div>

<script>
(function () {
    var passwordFieldIds = ['current_password', 'new_password', 'confirm_password'];
    var passwordTouched = { current_password: false, new_password: false, confirm_password: false };

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

    function validateNewPassword(val) {
        if (!val) return 'New password is required.';
        if (val.length < 8) return 'Password must be at least 8 characters.';
        if (val.length > 100) return 'Password must be at most 100 characters.';
        if (!/[A-Z]/.test(val)) return 'Password must have an uppercase letter.';
        if (!/[a-z]/.test(val)) return 'Password must have a lowercase letter.';
        if (!/[0-9]/.test(val)) return 'Password must have a number.';
        if (!/[^A-Za-z0-9]/.test(val)) return 'Password must have a special character.';
        if (/\s/.test(val)) return 'Password must not contain spaces.';
        return null;
    }

    function clearValidationState(id) {
        var group = document.getElementById('group_' + id);
        if (group) group.classList.remove('is-invalid');
    }

    function validateField(id, validator, options) {
        options = options || {};
        var input = document.getElementById(id);
        var group = document.getElementById('group_' + id);
        var error = document.getElementById('error_' + id);
        if (!input || !group || !error) return true;

        var val = (input.value || '').trim();
        if (options.onlyWhenTouched && !options.isTouched) {
            clearValidationState(id);
            return false;
        }

        var errorMessage = validator(val);
        if (errorMessage) {
            group.classList.add('is-invalid');
            error.textContent = errorMessage;
            return false;
        }
        group.classList.remove('is-invalid');
        return true;
    }

    function checkPassword(force) {
        var current = document.getElementById('current_password');
        var newPass = document.getElementById('new_password');
        var confirm = document.getElementById('confirm_password');
        if (!current || !newPass || !confirm) return;

        var hasAnyInput = (current.value + newPass.value + confirm.value).trim().length > 0;
        var mustValidate = force || hasAnyInput || Object.values(passwordTouched).some(Boolean);

        if (!mustValidate) {
            passwordFieldIds.forEach(clearValidationState);
            var btnUp = document.getElementById('btn_update_password');
            if (btnUp) btnUp.disabled = true;
            return;
        }

        var currentValid = validateField('current_password', function (val) {
            return !val ? 'Current password is required.' : null;
        }, {
            onlyWhenTouched: !force,
            isTouched: passwordTouched.current_password || current.value.length > 0
        });
        var nValid = validateField('new_password', validateNewPassword, {
            onlyWhenTouched: !force,
            isTouched: passwordTouched.new_password || newPass.value.length > 0
        });

        var cGroup = document.getElementById('group_confirm_password');
        var cError = document.getElementById('error_confirm_password');
        var confirmValid = false;
        if (!force && !passwordTouched.confirm_password && confirm.value === '') {
            clearValidationState('confirm_password');
        } else if (!confirm.value) {
            cGroup.classList.add('is-invalid');
            cError.textContent = 'Confirm password is required.';
        } else if (confirm.value !== newPass.value) {
            cGroup.classList.add('is-invalid');
            cError.textContent = 'Passwords do not match.';
        } else {
            cGroup.classList.remove('is-invalid');
            confirmValid = true;
        }

        var btn = document.getElementById('btn_update_password');
        if (btn) btn.disabled = !(currentValid && nValid && confirmValid);
    }

    function validatePasswordForm(event) {
        checkPassword(true);
        var btn = document.getElementById('btn_update_password');
        var ok = !!(btn && !btn.disabled);
        if (!ok && event && typeof event.preventDefault === 'function') event.preventDefault();
        return ok;
    }
    window.validatePasswordForm = validatePasswordForm;

    function blockExcessPasswordInput(event) {
        var input = event.target;
        var maxLength = 100;
        if ([8, 9, 27, 13, 46, 37, 38, 39, 40].indexOf(event.keyCode) !== -1 ||
            (event.ctrlKey === true && [65, 67, 88, 90].indexOf(event.keyCode) !== -1) ||
            (event.keyCode >= 35 && event.keyCode <= 36)) {
            return true;
        }
        if (input.value.length >= maxLength && input.selectionStart === input.selectionEnd) {
            event.preventDefault();
            return false;
        }
        return true;
    }

    function blockExcessPasswordPaste(event) {
        var input = event.target;
        var maxLength = 100;
        var pastedText = (event.clipboardData || window.clipboardData).getData('text');
        var currentValue = input.value;
        var selectionStart = input.selectionStart;
        var selectionEnd = input.selectionEnd;
        var newValue = currentValue.substring(0, selectionStart) + pastedText + currentValue.substring(selectionEnd);
        if (newValue.length > maxLength) {
            event.preventDefault();
            var availableSpace = maxLength - (currentValue.length - (selectionEnd - selectionStart));
            if (availableSpace > 0) {
                var truncatedText = pastedText.substring(0, availableSpace);
                input.value = currentValue.substring(0, selectionStart) + truncatedText + currentValue.substring(selectionEnd);
                input.setSelectionRange(selectionStart + truncatedText.length, selectionStart + truncatedText.length);
            }
            return false;
        }
        return true;
    }

    function printflowInitPasswordSecurityPage() {
        if (!document.getElementById('passwordForm')) return;
        Object.keys(passwordTouched).forEach(function (k) { passwordTouched[k] = false; });
        passwordFieldIds.forEach(function (id) {
            var el = document.getElementById(id);
            if (!el) return;
            el.removeEventListener('input', checkPassword);
            el.addEventListener('input', function () { passwordTouched[id] = true; checkPassword(); });
            el.removeEventListener('blur', checkPassword);
            el.addEventListener('blur', function () { passwordTouched[id] = true; checkPassword(); });
            el.removeEventListener('keydown', blockExcessPasswordInput);
            el.addEventListener('keydown', blockExcessPasswordInput);
            el.removeEventListener('paste', blockExcessPasswordPaste);
            el.addEventListener('paste', blockExcessPasswordPaste);
        });
        checkPassword(false);
    }

    document.addEventListener('printflow:page-init', printflowInitPasswordSecurityPage);
    if (typeof window.Turbo === 'undefined') {
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', printflowInitPasswordSecurityPage, { once: true });
        } else {
            printflowInitPasswordSecurityPage();
        }
    }
})();
</script>

</body>
</html>
