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

$admin = db_query("SELECT user_id, first_name, last_name, email, role, password_hash FROM users WHERE user_id = ?", 'i', [$admin_id])[0] ?? null;
if (!$admin) {
    redirect($base_path . '/logout');
}

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
        .security-hero {
            background: linear-gradient(90deg, #00232b, #53C5E0);
            border-radius: 16px;
            padding: 32px;
            margin-bottom: 24px;
            color: #fff;
        }
        .security-hero h2 {
            margin: 0 0 8px;
            font-size: 24px;
            font-weight: 700;
        }
        .security-hero p {
            margin: 0;
            opacity: 0.9;
            font-size: 14px;
        }
        .section-card {
            background: #fff;
            border-radius: 12px;
            padding: 24px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.08);
            max-width: 560px;
        }
        .section-title {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 16px;
            font-weight: 700;
            color: #111827;
            margin-bottom: 20px;
        }
        .section-title svg { color: #6b7280; }
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
            margin-top: 8px;
        }
        .btn-save {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 10px 20px;
            background: #53C5E0;
            color: #fff;
            border: none;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            transition: background 0.2s, transform 0.1s;
        }
        .btn-save:hover { background: #3bb8d4; }
        .btn-save:disabled {
            opacity: 0.5;
            cursor: not-allowed;
            filter: grayscale(1);
        }
        .alert-success { background: #ecfdf5; color: #065f46; border: 1px solid #a7f3d0; padding: 12px 16px; border-radius: 8px; margin-bottom: 16px; }
        .alert-error { background: #fef2f2; color: #991b1b; border: 1px solid #fecaca; padding: 12px 16px; border-radius: 8px; margin-bottom: 16px; }
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
            margin-bottom: 20px;
            line-height: 1.5;
        }
        @media (max-width: 768px) {
            .security-hero { padding: 24px 18px; border-radius: 12px; }
            .section-card { padding: 18px; }
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

            <div class="security-hero">
                <h2>Account Security</h2>
                <p>Keep your <?php echo htmlspecialchars($admin['role']); ?> account secure by using a strong, unique password.</p>
            </div>

            <div class="section-card">
                <div class="section-title">
                    <svg width="18" height="18" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/></svg>
                    Change Password
                </div>
                <p class="security-tip">Signed in as <strong><?php echo htmlspecialchars($admin['email']); ?></strong>. Enter your current password, then choose a new one.</p>

                <form method="POST" id="passwordForm" onsubmit="return validatePasswordForm(event)">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="change_password" value="1">

                    <div class="form-group" id="group_current_password">
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

                    <div class="profile-form-actions">
                        <button type="submit" class="btn-save" id="btn_update_password">
                            <svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/></svg>
                            Update Password
                        </button>
                    </div>
                </form>
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
