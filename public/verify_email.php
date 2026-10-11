<?php
/**
 * Email Verification Page — enter the 6-digit OTP sent after registration.
 * PrintFlow
 */

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

// Set no-cache headers
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");

// Require pending_email in session (set during registration)
$pending_email = $_SESSION['otp_pending_email'] ?? '';
$user_type = $_SESSION['otp_user_type'] ?? 'Customer';

if (empty($pending_email)) {
    // Redirect to home with register modal open if session expired or accessed directly
    header('Location: /printflow/?auth_modal=register&error=' . urlencode('Session expired. Please register again.'));
    exit;
}

$table = ($user_type === 'User') ? 'users' : 'customers';

// Base cooldown is 5 minutes (300 seconds)
$base_cooldown = 300; 
$attempts = $_SESSION['otp_resend_attempts'] ?? 1;
$cooldown = $base_cooldown * $attempts;

// Fetch OTP last_sent to show countdown
$row = db_query("SELECT otp_last_sent FROM $table WHERE email = ?", 's', [$pending_email]);
$last_sent_ts = !empty($row[0]['otp_last_sent']) ? strtotime($row[0]['otp_last_sent']) : (time() - $cooldown - 1);
$seconds_since = time() - $last_sent_ts;
$resend_wait   = max(0, $cooldown - $seconds_since);

// Format initial wait time for PHP render
$init_min = floor($resend_wait / 60);
$init_sec = $resend_wait % 60;
$init_time_str = sprintf("%02d:%02d", $init_min, $init_sec);

$error   = $_SESSION['otp_error']   ?? ''; unset($_SESSION['otp_error']);
$success = $_SESSION['otp_success'] ?? ''; unset($_SESSION['otp_success']);

if (empty($error) && !empty($_GET['error'])) {
    $error = $_GET['error'];
}
if (empty($success) && !empty($_GET['success'])) {
    $success = $_GET['success'];
}
$verify_lock_remaining = !empty($_GET['otp_locked'])
    ? max(0, (int)($_GET['remaining_seconds'] ?? 0))
    : 0;

$page_title = 'Verify Email — PrintFlow';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $page_title; ?></title>
    <?php include __DIR__ . '/../includes/favicon_links.php'; ?>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: 'Inter', sans-serif;
            min-height: 100vh;
            display: flex; align-items: center; justify-content: center;
            background: linear-gradient(135deg, #0a0f1e 0%, #001a2c 50%, #0d2137 100%);
            padding: 1rem;
        }
        .card {
            background: rgba(255,255,255,.04);
            border: 1px solid rgba(255,255,255,.09);
            border-radius: 20px;
            padding: 2.5rem 2rem;
            width: 100%; max-width: 400px;
            box-shadow: 0 30px 70px rgba(0,0,0,.6);
            text-align: center;
        }
        .logo-wrap { margin-bottom: 1.5rem; }
        .logo-wrap svg { width: 52px; height: 52px; }
        h1 { font-size: 1.5rem; font-weight: 800; color: #fff; margin-bottom: .4rem; }
        .sub { font-size: 0.875rem; color: #94a3b8; margin-bottom: 1.75rem; line-height: 1.5; }
        .email-badge { display: inline-block; background: rgba(50,161,196,.15); color: #53c5e0; border: 1px solid rgba(83,197,224,.25); padding: .25rem .75rem; border-radius: 20px; font-size: .8125rem; font-weight: 600; margin-bottom: 1.5rem; }
        .otp-inputs { display: flex; gap: .5rem; justify-content: center; margin-bottom: 1.5rem; }
        .otp-inputs input {
            width: 3rem; height: 3.4rem;
            text-align: center; font-size: 1.5rem; font-weight: 800;
            background: rgba(255,255,255,.06);
            border: 2px solid rgba(255,255,255,.12);
            border-radius: 10px; color: #e0f2fe;
            font-family: 'Inter', monospace;
            transition: border-color .2s, box-shadow .2s;
        }
        .otp-inputs input:focus { border-color: #32a1c4; outline: none; box-shadow: 0 0 0 3px rgba(83,197,224,.25); }
        .otp-inputs input.filled { border-color: rgba(83,197,224,.5); }
        .btn-verify {
            width: 100%; padding: .75rem;
            background: linear-gradient(135deg, #32a1c4, #1d6fa6);
            color: #fff; font-weight: 700; font-size: 1rem;
            border: none; border-radius: 10px; cursor: pointer;
            transition: opacity .2s, transform .1s;
            margin-bottom: 1rem;
        }
        .btn-verify:hover { opacity: .9; }
        .btn-verify:active { transform: scale(.98); }
        .btn-verify:disabled { opacity: .45; cursor: not-allowed; }
        .resend-row { font-size: .875rem; color: #64748b; }
        .btn-resend { background: none; border: none; color: #53c5e0; font-weight: 600; cursor: pointer; font-size: .875rem; padding: 0; }
        .btn-resend:disabled { color: #475569; cursor: not-allowed; }
        #resend-timer { font-size: .8rem; color: #64748b; margin-top: .4rem; }
        .alert { padding: .75rem 1rem; border-radius: 10px; font-size: .875rem; margin-bottom: 1rem; font-weight: 500; }
        .alert-error   { background: rgba(239,68,68,.15); border: 1px solid rgba(239,68,68,.35); color: #fca5a5; }
        .alert-success { background: rgba(34,197,94,.12); border: 1px solid rgba(34,197,94,.3);  color: #86efac; }
        .back-link { display: block; margin-top: 1.25rem; font-size: .8125rem; color: #475569; }
        .back-link a { color: #53c5e0; text-decoration: none; font-weight: 600; }
    </style>
</head>
<body>
<div class="card">
    <div class="logo-wrap">
        <svg viewBox="0 0 52 52" fill="none">
            <rect width="52" height="52" rx="14" fill="url(#g)"/>
            <path d="M14 26h24M26 14v24" stroke="#fff" stroke-width="3" stroke-linecap="round"/>
            <defs><linearGradient id="g" x1="0" y1="0" x2="52" y2="52" gradientUnits="userSpaceOnUse">
                <stop stop-color="#32a1c4"/><stop offset="1" stop-color="#1d6fa6"/>
            </linearGradient></defs>
        </svg>
    </div>

    <h1>Verify your email</h1>
    <p class="sub">We sent a 6-digit verification code to</p>
    <span class="email-badge"><?php echo htmlspecialchars($pending_email); ?></span>

    <?php if ($error): ?>
        <div class="alert alert-error"><?php echo htmlspecialchars($error); ?></div>
    <?php endif; ?>
    <?php if ($success): ?>
        <div class="alert alert-success"><?php echo htmlspecialchars($success); ?></div>
    <?php endif; ?>

    <form method="POST" action="<?php echo $base_path; ?>/public/verify_otp.php" id="otp-form">
        <?php echo csrf_field(); ?>
        <input type="hidden" name="email" value="<?php echo htmlspecialchars($pending_email); ?>">
        <input type="hidden" name="otp" id="otp-hidden">

        <div class="otp-inputs" id="otp-boxes">
            <?php for ($i = 0; $i < 6; $i++): ?>
            <input type="text" maxlength="1" pattern="[0-9]" inputmode="numeric" autocomplete="one-time-code" data-idx="<?php echo $i; ?>">
            <?php endfor; ?>
        </div>

        <button type="submit" class="btn-verify" id="btn-verify" disabled>Verify Email</button>
    </form>

    <div class="resend-row">
        Didn't receive it?
        <button class="btn-resend" id="btn-resend" <?php echo $resend_wait > 0 ? 'disabled' : ''; ?>
                onclick="resendOtp(event)">Resend Code</button>
        <div id="resend-timer" <?php echo $resend_wait <= 0 ? 'style="display:none"' : ''; ?>>
            Resend available in <span id="timer-count"><?php echo $init_time_str; ?></span>
        </div>
    </div>

    <p class="back-link">Wrong email? <a href="<?php echo $base_path; ?>/index.php?auth_modal=register">Register again</a></p>
    <div style="font-size: 10px; color: #475569; margin-top: 20px; opacity: 0.5;">v4-fresh</div>
</div>

<script>
const RESEND_COOLDOWN = <?php echo $cooldown; ?>;
let resendWait = <?php echo $resend_wait; ?>;

// ── OTP Boxes ────────────────────────────────────────────────
const boxes = document.querySelectorAll('#otp-boxes input');
const hiddenOtp = document.getElementById('otp-hidden');
const btnVerify = document.getElementById('btn-verify');
let verifyLockRemaining = <?php echo $verify_lock_remaining; ?>;
let verifyLockTimer = null;

function getOtpValue() {
    return Array.from(boxes).map(b => b.value).join('');
}

function updateVerifyState() {
    const val = getOtpValue();
    hiddenOtp.value = val;
    btnVerify.disabled = verifyLockRemaining > 0 || val.length !== 6;
}

function renderVerifyLockout() {
    if (verifyLockRemaining <= 0) {
        btnVerify.textContent = 'Verify Email';
        updateVerifyState();
        return;
    }
    const minutes = Math.floor(verifyLockRemaining / 60);
    const seconds = verifyLockRemaining % 60;
    btnVerify.disabled = true;
    btnVerify.textContent = 'Try again in ' + minutes + ':' + String(seconds).padStart(2, '0');
}

if (verifyLockRemaining > 0) {
    renderVerifyLockout();
    verifyLockTimer = setInterval(() => {
        verifyLockRemaining = Math.max(0, verifyLockRemaining - 1);
        renderVerifyLockout();
        if (verifyLockRemaining <= 0) {
            clearInterval(verifyLockTimer);
            verifyLockTimer = null;
        }
    }, 1000);
}

boxes.forEach((box, idx) => {
    box.addEventListener('input', e => {
        // Only allow digits
        box.value = box.value.replace(/\D/g, '').slice(-1);
        box.classList.toggle('filled', box.value !== '');
        if (box.value && idx < boxes.length - 1) boxes[idx + 1].focus();
        updateVerifyState();
    });
    box.addEventListener('keydown', e => {
        if (e.key === 'Backspace' && !box.value && idx > 0) {
            boxes[idx - 1].focus();
            boxes[idx - 1].value = '';
            boxes[idx - 1].classList.remove('filled');
            updateVerifyState();
        }
    });
    // Handle paste
    box.addEventListener('paste', e => {
        e.preventDefault();
        const text = (e.clipboardData || window.clipboardData).getData('text').replace(/\D/g, '').slice(0, 6);
        text.split('').forEach((ch, i) => {
            if (boxes[i]) { boxes[i].value = ch; boxes[i].classList.add('filled'); }
        });
        if (boxes[Math.min(text.length, 5)]) boxes[Math.min(text.length, 5)].focus();
        updateVerifyState();
    });
});
boxes[0].focus();

// ── Resend OTP ───────────────────────────────────────────────
const timerEl   = document.getElementById('resend-timer');
const timerCount = document.getElementById('timer-count');
const btnResend  = document.getElementById('btn-resend');

function formatTime(secs) {
    const m = Math.floor(secs / 60);
    const s = secs % 60;
    return (m < 10 ? '0' : '') + m + ':' + (s < 10 ? '0' : '') + s;
}

function startCountdown(seconds) {
    resendWait = seconds;
    btnResend.disabled = true;
    timerEl.style.display = 'block';
    timerCount.textContent = formatTime(resendWait);
    const interval = setInterval(() => {
        resendWait--;
        if (resendWait <= 0) {
            clearInterval(interval);
            timerEl.style.display = 'none';
            btnResend.disabled = false;
        } else {
            timerCount.textContent = formatTime(resendWait);
        }
    }, 1000);
}

if (resendWait > 0) startCountdown(resendWait);

function resendOtp(e) {
    e.preventDefault();
    btnResend.disabled = true;
    btnResend.textContent = 'Sending…';

    const fd = new FormData();
    fd.append('email', <?php echo json_encode($pending_email); ?>);
    fd.append('csrf_token', document.querySelector('input[name="csrf_token"]').value);

    fetch(BASE_PATH . '/public/resend_otp.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => {
            btnResend.textContent = 'Resend Code';
            if (data.success) {
                // The server passes back the new total cooldown required
                startCountdown(data.new_cooldown || 300);
                showAlert('success', data.message || 'Verification code resent!');
            } else {
                btnResend.disabled = false;
                showAlert('error', data.message || 'Failed to resend.');
            }
        })
        .catch(() => {
            btnResend.textContent = 'Resend Code';
            btnResend.disabled = false;
            showAlert('error', 'Network error. Please try again.');
        });
}

function showAlert(type, text) {
    const existing = document.querySelectorAll('.alert');
    existing.forEach(el => el.remove());
    const div = document.createElement('div');
    div.className = 'alert alert-' + type;
    div.textContent = text;
    document.getElementById('otp-form').before(div);
}
</script>
</body>
</html>
