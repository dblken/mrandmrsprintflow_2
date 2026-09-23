<?php
/**
 * Set Password Page - For new customers to set their password
 */
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';

$base_url = defined('BASE_URL') ? BASE_URL : (defined('BASE_PATH') ? BASE_PATH : '');
$login_page_url = rtrim((string) $base_url, '/') . '/?auth_modal=login';

$error = '';
$success = '';
$token = $_GET['token'] ?? '';
$valid_token = false;
$customer_email = '';
$customer_id = null;

if ($token) {
    // For now, decode token as base64 encoded customer_id (simple approach)
    // In production, use proper token table
    try {
        $decoded = base64_decode($token);
        if ($decoded && is_numeric($decoded)) {
            $customer_id = (int)$decoded;
            $customer = db_query("SELECT email, first_name, last_name, password_hash FROM customers WHERE customer_id = ?", 'i', [$customer_id]);
            if (!empty($customer)) {
                $valid_token = true;
                $customer_email = $customer[0]['email'];
                $customer_name = $customer[0]['first_name'] . ' ' . $customer[0]['last_name'];
                // Check if password already set (not temporary)
                $current_hash = $customer[0]['password_hash'];
            }
        }
    } catch (Exception $e) {
        $error = 'Invalid token format.';
    }
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $valid_token) {
    $password = $_POST['password'] ?? '';
    $confirm = $_POST['confirm_password'] ?? '';
    
    if (strlen($password) < 8) {
        $error = 'Password must be at least 8 characters long.';
    } elseif ($password !== $confirm) {
        $error = 'Passwords do not match.';
    } else {
        // Update customer password
        $password_hash = password_hash($password, PASSWORD_BCRYPT);
        $result = db_execute("UPDATE customers SET password_hash = ? WHERE customer_id = ?", 'si', [$password_hash, $customer_id]);
        
        if ($result) {
            $success = 'Password set successfully! You can now log in to your account.';
        } else {
            $error = 'Failed to update password. Please try again.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Set Your Password - Mr. and Mrs. Print</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Inter', sans-serif;
            background: #00151b;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }
        .container {
            background: #001a23;
            border-radius: 20px;
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.5);
            max-width: 480px;
            width: 100%;
            padding: 40px;
            border: 1px solid rgba(50, 161, 196, 0.1);
        }
        .logo {
            text-align: center;
            margin-bottom: 30px;
        }
        .logo h1 {
            font-size: 32px;
            font-weight: 800;
            color: #32a1c4;
        }
        h2 {
            font-size: 18px;
            font-weight: 700;
            color: #32a1c4;
            margin-bottom: 6px;
        }
        .subtitle {
            color: #94a3b8;
            font-size: 12px;
            margin-bottom: 24px;
        }
        .alert {
            padding: 14px 16px;
            border-radius: 10px;
            margin-bottom: 20px;
            font-size: 14px;
            font-weight: 500;
        }
        .alert-error {
            background: rgba(239, 68, 68, 0.1);
            color: #fca5a5;
            border-left: 4px solid #ef4444;
        }
        .alert-success {
            background: rgba(16, 185, 129, 0.1);
            color: #6ee7b7;
            border-left: 4px solid #10b981;
        }
        .form-group {
            margin-bottom: 20px;
            position: relative;
        }
        label {
            display: block;
            font-size: 13px;
            font-weight: 600;
            color: #94a3b8;
            margin-bottom: 8px;
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }
        .password-wrapper {
            position: relative;
        }
        input[type="password"], input[type="text"], input[type="email"] {
            width: 100%;
            padding: 14px 45px 14px 16px;
            border: 1px solid rgba(50, 161, 196, 0.2);
            border-radius: 10px;
            font-size: 15px;
            font-family: 'Inter', sans-serif;
            transition: all 0.2s;
            background: rgba(0, 26, 35, 0.5);
            color: #e2e8f0;
        }
        input::placeholder {
            color: #475569;
        }
        input:focus {
            outline: none;
            border-color: #32a1c4;
            background: rgba(0, 26, 35, 0.8);
            box-shadow: 0 0 0 3px rgba(50, 161, 196, 0.1);
        }
        input.invalid {
            border-color: #ef4444;
            background: rgba(239, 68, 68, 0.05);
        }
        input.valid {
            border-color: #10b981;
            background: rgba(16, 185, 129, 0.05);
        }
        .toggle-password {
            position: absolute;
            right: 0.75rem;
            top: 50%;
            transform: translateY(-50%);
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 2rem;
            height: 2rem;
            background: none;
            border: none;
            color: #94a3b8;
            cursor: pointer;
            padding: 0;
            transition: color 0.2s;
            margin: 0;
        }
        .toggle-password:hover {
            color: #94a3b8;
            transform: translateY(-50%);
            box-shadow: none;
            background: none;
        }
        .toggle-password:focus-visible {
            outline: none;
        }
        .toggle-password svg {
            width: 1.25rem;
            height: 1.25rem;
            pointer-events: none;
        }
        .password-requirements {
            margin-top: 10px;
            padding: 12px;
            background: rgba(0, 26, 35, 0.5);
            border-radius: 8px;
            font-size: 12px;
            border: 1px solid rgba(50, 161, 196, 0.1);
        }
        .requirement {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 4px 0;
            color: #64748b;
            transition: color 0.2s;
        }
        .requirement.met {
            color: #6ee7b7;
        }
        .requirement .icon {
            width: 16px;
            height: 16px;
            border-radius: 50%;
            border: 2px solid #475569;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 10px;
            transition: all 0.2s;
        }
        .requirement.met .icon {
            background: #10b981;
            border-color: #10b981;
            color: white;
        }
        .match-indicator {
            margin-top: 8px;
            font-size: 12px;
            font-weight: 600;
            padding: 6px 10px;
            border-radius: 6px;
            display: none;
        }
        .match-indicator.show {
            display: block;
        }
        .match-indicator.match {
            background: rgba(16, 185, 129, 0.1);
            color: #6ee7b7;
        }
        .match-indicator.no-match {
            background: rgba(239, 68, 68, 0.1);
            color: #fca5a5;
        }
        button {
            width: 100%;
            padding: 16px;
            background: #32a1c4;
            color: white;
            border: none;
            border-radius: 10px;
            font-size: 16px;
            font-weight: 700;
            cursor: pointer;
            transition: all 0.2s;
            margin-top: 10px;
        }
        button:not(.toggle-password):hover:not(:disabled) {
            transform: translateY(-2px);
            box-shadow: 0 10px 20px rgba(50, 161, 196, 0.3);
            background: #2891b3;
        }
        button:disabled {
            background: #1e3a47;
            cursor: not-allowed;
            transform: none;
            color: #475569;
        }
        .login-link {
            text-align: center;
            margin-top: 24px;
            font-size: 14px;
            color: #94a3b8;
        }
        .login-link a {
            color: #32a1c4;
            font-weight: 600;
            text-decoration: none;
        }
        .login-link a:hover {
            text-decoration: underline;
            color: #4db8d8;
        }
        .info-box {
            background: rgba(50, 161, 196, 0.1);
            border-left: 4px solid #32a1c4;
            padding: 14px;
            border-radius: 8px;
            margin-bottom: 24px;
            font-size: 14px;
            color: #94a3b8;
        }
        .info-box strong {
            color: #e2e8f0;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="logo">
            <h1>Mr. and Mrs. Print</h1>
        </div>

        <?php if ($success): ?>
            <div class="alert alert-success">
                <?= htmlspecialchars($success) ?>
            </div>
            <div class="login-link">
                <a href="<?= htmlspecialchars($login_page_url, ENT_QUOTES, 'UTF-8') ?>">Go to Login Page →</a>
            </div>
        <?php elseif (!$valid_token): ?>
            <h2>Invalid Link</h2>
            <div class="alert alert-error">
                <?= htmlspecialchars($error ?: 'This password setup link is invalid or has expired.') ?>
            </div>
            <div class="login-link">
                <a href="<?= htmlspecialchars($login_page_url, ENT_QUOTES, 'UTF-8') ?>">Back to Login</a>
            </div>
        <?php else: ?>
            <h2>Set Your Password</h2>
            <p class="subtitle">Welcome, <?= htmlspecialchars($customer_name ?? 'Customer') ?>! Create a secure password for your account.</p>

            <div class="info-box">
                <strong>Account:</strong> <?= htmlspecialchars($customer_email) ?>
            </div>

            <?php if ($error): ?>
                <div class="alert alert-error">
                    <?= htmlspecialchars($error) ?>
                </div>
            <?php endif; ?>

            <form method="POST" id="passwordForm">
                <div class="form-group">
                    <label for="password">New Password *</label>
                    <div class="password-wrapper">
                        <input type="password" id="password" name="password" required>
                        <button type="button" class="toggle-password" id="toggle-password" aria-label="Show password">
                            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M1.5 12s3.75-7.5 10.5-7.5S22.5 12 22.5 12 18.75 19.5 12 19.5 1.5 12 1.5 12z"></path><circle cx="12" cy="12" r="3" stroke-width="2"></circle></svg>
                        </button>
                    </div>
                    <div class="password-requirements">
                        <div class="requirement" id="req-length">
                            <span class="icon"></span>
                            <span>At least 8 characters</span>
                        </div>
                        <div class="requirement" id="req-uppercase">
                            <span class="icon"></span>
                            <span>One uppercase letter</span>
                        </div>
                        <div class="requirement" id="req-lowercase">
                            <span class="icon"></span>
                            <span>One lowercase letter</span>
                        </div>
                        <div class="requirement" id="req-number">
                            <span class="icon"></span>
                            <span>One number</span>
                        </div>
                    </div>
                </div>

                <div class="form-group">
                    <label for="confirm_password">Confirm Password *</label>
                    <div class="password-wrapper">
                        <input type="password" id="confirm_password" name="confirm_password" required>
                        <button type="button" class="toggle-password" id="toggle-confirm" aria-label="Show password">
                            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M1.5 12s3.75-7.5 10.5-7.5S22.5 12 22.5 12 18.75 19.5 12 19.5 1.5 12 1.5 12z"></path><circle cx="12" cy="12" r="3" stroke-width="2"></circle></svg>
                        </button>
                    </div>
                    <div class="match-indicator" id="matchIndicator"></div>
                </div>

                <button type="submit" id="submitBtn" disabled>Set Password & Activate Account</button>
            </form>

            <div class="login-link">
                Already have a password? <a href="<?= htmlspecialchars($login_page_url, ENT_QUOTES, 'UTF-8') ?>">Login here</a>
            </div>
        <?php endif; ?>
    </div>

    <script>
        const EYE_OPEN = '<svg fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M1.5 12s3.75-7.5 10.5-7.5S22.5 12 22.5 12 18.75 19.5 12 19.5 1.5 12 1.5 12z"></path><circle cx="12" cy="12" r="3" stroke-width="2"></circle></svg>';
        const EYE_OFF  = '<svg fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 3l18 18"></path><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.584 10.587A2 2 0 0012 14a2 2 0 001.414-.586"></path><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9.878 5.099A10.45 10.45 0 0112 4.5c6.75 0 10.5 7.5 10.5 7.5a17.537 17.537 0 01-4.232 4.919M6.228 6.228A17.646 17.646 0 001.5 12s3.75 7.5 10.5 7.5a10.56 10.56 0 005.012-1.228"></path></svg>';

        function setupToggle(btnId, inputId) {
            const btn = document.getElementById(btnId);
            const input = document.getElementById(inputId);
            if (!btn || !input) return;
            btn.addEventListener('click', function() {
                const visible = input.type === 'text';
                input.type = visible ? 'password' : 'text';
                btn.setAttribute('aria-label', visible ? 'Show password' : 'Hide password');
                btn.innerHTML = visible ? EYE_OPEN : EYE_OFF;
            });
        }
        setupToggle('toggle-password', 'password');
        setupToggle('toggle-confirm', 'confirm_password');

        const passwordInput = document.getElementById('password');
        const confirmInput = document.getElementById('confirm_password');
        const submitBtn = document.getElementById('submitBtn');
        const matchIndicator = document.getElementById('matchIndicator');

        const requirements = {
            length: { regex: /.{8,}/, element: document.getElementById('req-length') },
            uppercase: { regex: /[A-Z]/, element: document.getElementById('req-uppercase') },
            lowercase: { regex: /[a-z]/, element: document.getElementById('req-lowercase') },
            number: { regex: /[0-9]/, element: document.getElementById('req-number') }
        };

        function checkRequirements() {
            const password = passwordInput.value;
            let allMet = true;
            for (const key in requirements) {
                const req = requirements[key];
                const met = req.regex.test(password);
                if (met) {
                    req.element.classList.add('met');
                    req.element.querySelector('.icon').innerHTML = '✓';
                } else {
                    req.element.classList.remove('met');
                    req.element.querySelector('.icon').innerHTML = '';
                    allMet = false;
                }
            }
            if (password.length > 0) {
                passwordInput.classList.remove('invalid', 'valid');
                passwordInput.classList.add(allMet ? 'valid' : 'invalid');
            } else {
                passwordInput.classList.remove('invalid', 'valid');
            }
            return allMet;
        }

        function checkMatch() {
            const password = passwordInput.value;
            const confirm = confirmInput.value;
            const reqsMet = checkRequirements();

            if (confirm.length === 0) {
                matchIndicator.classList.remove('show');
                confirmInput.classList.remove('invalid', 'valid');
                submitBtn.disabled = true;
                return;
            }

            matchIndicator.classList.add('show');
            const matches = password === confirm;

            if (matches) {
                matchIndicator.textContent = '✓ Passwords match';
                matchIndicator.classList.remove('no-match');
                matchIndicator.classList.add('match');
                confirmInput.classList.remove('invalid');
                confirmInput.classList.add('valid');
            } else {
                matchIndicator.textContent = '✗ Passwords do not match';
                matchIndicator.classList.remove('match');
                matchIndicator.classList.add('no-match');
                confirmInput.classList.remove('valid');
                confirmInput.classList.add('invalid');
            }

            submitBtn.disabled = !(matches && reqsMet);
        }

        passwordInput.addEventListener('input', checkMatch);
        confirmInput.addEventListener('input', checkMatch);

        // Prevent form submission if validation fails
        document.getElementById('passwordForm').addEventListener('submit', function(e) {
            if (!validatePassword() || passwordInput.value !== confirmInput.value) {
                e.preventDefault();
                alert('Please ensure all password requirements are met and passwords match.');
            }
        });
    </script>
</body>
</html>
