<?php
/**
 * Login and Register modals (blurred backdrop). Include when !$is_logged_in.
 * Requires: $base_url, csrf_field(), and optionally $_GET['auth_modal'], $_GET['error']
 * 
 * Version: 2.1 - Updated 2026-04-24
 * - Restored "Remember me" checkbox in login modal
 * - Added forgot password modal with Email/Mobile tabs
 * - Centered forgot password link in login modal
 */
$auth_modal = isset($_GET['auth_modal']) ? $_GET['auth_modal'] : '';
$auth_error = isset($_GET['error']) ? $_GET['error'] : '';
$auth_success = isset($_GET['success']) ? $_GET['success'] : '';

$pf_google_auth_href = htmlspecialchars($url_google_auth ?? $base_url . '/public/google-auth.php', ENT_QUOTES, 'UTF-8');
$pf_google_auth_btn = '';
if (!empty($google_client_id)) {
    ob_start();
    ?>
    <p class="auth-divider"><span>or</span></p>
    <div class="auth-field auth-google-wrap">
        <a href="<?php echo $pf_google_auth_href; ?>" class="auth-btn-google" aria-label="Continue with Google">
            <svg width="20" height="20" viewBox="0 0 24 24" aria-hidden="true"><path fill="#4285F4" d="M22.56 12.25c0-.78-.07-1.53-.2-2.25H12v4.26h5.92c-.26 1.37-1.04 2.53-2.21 3.31v2.77h3.57c2.08-1.92 3.28-4.74 3.28-8.09z"/><path fill="#34A853" d="M12 23c2.97 0 5.46-.98 7.28-2.66l-3.57-2.77c-.98.66-2.23 1.06-3.71 1.06-2.86 0-5.29-1.93-6.16-4.53H2.18v2.84C3.99 20.53 7.7 23 12 23z"/><path fill="#FBBC05" d="M5.84 14.09c-.22-.66-.35-1.36-.35-2.09s.13-1.43.35-2.09V7.07H2.18C1.43 8.55 1 10.22 1 12s.43 3.45 1.18 4.93l2.85-2.22.81-.62z"/><path fill="#EA4335" d="M12 5.38c1.62 0 3.06.56 4.21 1.64l3.15-3.15C17.45 2.09 14.97 1 12 1 7.7 1 3.99 3.47 2.18 7.07l3.66 2.84c.87-2.6 3.3-4.53 6.16-4.53z"/></svg>
            Continue with Google
        </a>
    </div>
    <?php
    $pf_google_auth_btn = ob_get_clean();
}
?>
<style>
/* Auth Modals — dark navy/teal theme matching landing page */
.auth-modal-backdrop {
    position: fixed;
    inset: 0;
    z-index: 99998;
    background: rgba(0, 10, 18, 0.9);
    opacity: 0;
    visibility: hidden;
    transition: opacity 0.14s ease-out, visibility 0.14s ease-out;
}
.auth-modal-backdrop.is-open {
    opacity: 1;
    visibility: visible;
}
/* Single max-width for login + register (register must not override with a wider value) */
.auth-modal {
    position: fixed;
    left: 50%;
    top: 50%;
    transform: translate(-50%, -50%);
    z-index: 99999;
    width: 100%;
    max-width: min(440px, calc(100vw - 2rem));
    background: #00151b;
    border: 1px solid rgba(255,255,255,.12);
    border-radius: 1.25rem;
    box-shadow: 0 30px 70px rgba(0,0,0,.65), 0 0 50px rgba(83,197,224,.07);
    opacity: 0;
    visibility: hidden;
    transition: opacity 0.14s ease-out, visibility 0.14s ease-out;
    display: flex;
    flex-direction: column;
    max-height: 90vh; /* Attached scrollbar to modal */
}
.auth-modal.is-open {
    opacity: 1;
    visibility: visible;
}
    /* Same width as login modal — do not set a different max-width here */
    .auth-modal-register { max-height: 95vh; }
    .auth-modal-close {
        position: absolute;
        right: 1rem;
        top: 1rem;
        width: 2rem;
        height: 2rem;
        display: flex;
        align-items: center;
        justify-content: center;
        color: #64748b;
        background: none;
        border: none;
        border-radius: 0.5rem;
        cursor: pointer;
        transition: color 0.2s, background 0.2s;
    }
    .auth-modal-close:hover {
        color: #e0f2fe;
        background: rgba(255,255,255,.08);
    }
    .auth-modal-inner { padding: 2rem 1.5rem; }
    .auth-modal h2 { margin: 0 0 0.25rem 0; font-size: 1.5rem; font-weight: 700; color: #ffffff; text-align: center; }
    .auth-modal .auth-modal-sub { margin: 0 0 1.5rem 0; font-size: 0.875rem; color: #94a3b8; text-align: center; }

    .auth-modal-scrollable {
        flex: 1;
        overflow-y: auto;
        padding-right: 4px; /* Space for custom scrollbar */
    }
    .auth-modal-register .auth-modal-scrollable {
        overflow-y: visible;
        padding-right: 0;
    }
    /* Custom scrollbar for modal */
    .auth-modal-scrollable::-webkit-scrollbar { width: 6px; }
    .auth-modal-scrollable::-webkit-scrollbar-track { background: rgba(255,255,255,0.05); }
    .auth-modal-scrollable::-webkit-scrollbar-thumb { background: rgba(255,255,255,0.2); border-radius: 10px; }
    .auth-modal-scrollable::-webkit-scrollbar-thumb:hover { background: rgba(255,255,255,0.3); }
    .auth-modal .input-field {
        width: 100%;
        padding: 0.55rem 0.85rem;
        background: rgba(255,255,255,.05);
        border: 1px solid rgba(83,197,224,.2) !important;
        border-radius: 0.5rem;
        font-size: 1rem;
        color: #e0f2fe;
        box-sizing: border-box;
        outline: none !important;
        transition: border-color 0.2s, box-shadow 0.2s, background 0.2s;
    }
    .auth-modal .input-field::placeholder { color: #475569; }
    .auth-modal .input-field:focus {
        outline: none !important;
        border-color: #32a1c4 !important;
        box-shadow: 0 0 0 3px rgba(83, 197, 224, 0.2);
        background: rgba(255,255,255,.09);
    }
    /* Same valid/invalid realtime cues as public/reset-password.php */
    .auth-modal .input-field.is-invalid {
        border-color: #f87171 !important;
        box-shadow: 0 0 0 3px rgba(248, 113, 113, 0.2) !important;
        outline: none !important;
    }
    .auth-modal .input-field.is-valid {
        border-color: #34d399 !important;
        box-shadow: 0 0 0 3px rgba(52, 211, 153, 0.15) !important;
        outline: none !important;
    }
    .auth-modal label { display: block; font-size: 0.875rem; font-weight: 500; color: #94a3b8; margin-bottom: 0.375rem; }
    .auth-modal .auth-alert-error { background: rgba(239,68,68,.15); border: 1px solid rgba(239,68,68,.35); color: #fca5a5; padding: 0.75rem 1rem; border-radius: 0.5rem; font-size: 0.875rem; margin-bottom: 1rem; }
    .auth-modal .auth-alert-success { background: rgba(34,197,94,.12); border: 1px solid rgba(34,197,94,.3); color: #86efac; padding: 0.75rem 1rem; border-radius: 0.5rem; font-size: 0.875rem; margin-bottom: 1rem; }
    .auth-modal .auth-btn-submit { width: 100%; padding: 0.7rem 1rem; background: #32a1c4; color: #fff; font-weight: 600; border: none; border-radius: 0.5rem; cursor: pointer; font-size: 1rem; transition: background .2s, box-shadow .2s; }
    .auth-modal .auth-btn-submit:hover { background: #2a82a3; box-shadow: 0 0 24px rgba(83,197,224,.4); }
    .auth-modal .auth-btn-submit:disabled { opacity: 0.5; cursor: not-allowed; }
    .auth-modal .btn-loading-wrap { display: inline-flex; align-items: center; justify-content: center; gap: 8px; }
    .auth-modal .btn-loading-spinner {
        width: 14px;
        height: 14px;
        border-radius: 50%;
        border: 2px solid rgba(255,255,255,0.35);
        border-top-color: #53C5E0;
        animation: authBtnSpin .8s linear infinite;
        flex-shrink: 0;
    }
    @keyframes authBtnSpin { to { transform: rotate(360deg); } }
    .auth-modal .auth-switch { margin-top: 1.25rem; text-align: center; font-size: 0.875rem; color: #64748b; }
    .auth-modal .auth-switch a { color: #53C5E0; font-weight: 600; text-decoration: none; }
    .auth-modal .auth-switch a:hover { color: #7acae3; }
    .auth-modal .auth-field { margin-bottom: 1rem; }
    .auth-modal .auth-field-row { margin-bottom: 1rem; display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 0.5rem; }
    .auth-modal .auth-field-row label { margin: 0; display: flex; align-items: center; gap: 0.5rem; font-size: 0.875rem; color: #94a3b8; }
    .auth-modal .auth-field-row a { font-size: 0.875rem; color: #53C5E0; }
    .auth-modal input[type="checkbox"] { accent-color: #32a1c4; }
    .auth-modal .auth-google-wrap { margin-bottom: 1rem; }
    /* Field-level error messages in modals */
    .modal-field-error {
        margin: 0;
        max-height: 0;
        opacity: 0;
        overflow: hidden;
        font-size: 0.72rem;
        color: #f87171;
        transition: max-height 0.18s ease, opacity 0.18s ease, margin-top 0.18s ease;
    }
    .modal-field-error:not(:empty) {
        margin-top: 6px;
        max-height: 72px;
        opacity: 1;
    }
    /* Password strength checklist — 2-col 3×2 grid, matching dark modal theme */
    .reg-pw-checklist {
        list-style: none;
        margin: 0;
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 3px 8px;
        font-size: 0.7rem;
        color: #64748b;
        max-height: 0;
        opacity: 0;
        overflow: hidden;
        padding: 0;
        transition: max-height 0.18s ease, opacity 0.18s ease, margin-top 0.18s ease, padding 0.18s ease;
    }
    .reg-pw-checklist.is-visible {
        margin-top: 8px;
        max-height: 120px;
        opacity: 1;
        padding: 8px 0 2px;
    }
    .reg-pw-checklist li { transition: color 0.15s; display: flex; align-items: center; gap: 4px; }
    .reg-pw-checklist li::before { content: '✘'; font-size: 0.65rem; color: #f87171; flex-shrink: 0; }
    .reg-pw-checklist li.ok { color: #34d399 !important; }
    .reg-pw-checklist li.ok::before { content: '✔'; color: #34d399; }
    .reg-pw-checklist li.neutral::before { content: '○'; color: #64748b; }
    /* Match indicator */
    .reg-match-ok { margin: 4px 0 0; font-size: 0.72rem; color: #34d399; font-weight: 600; }
    .auth-modal .auth-google-wrap { margin-bottom: 1rem; }
    .auth-modal .auth-btn-google {
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 0.5rem;
        width: 100%;
        padding: 0.6rem 1rem;
        background: rgba(255,255,255,.06);
        border: 1px solid rgba(255,255,255,.12);
        border-radius: 0.5rem;
        color: #e0f2fe;
        font-size: 0.9375rem;
        font-weight: 500;
        text-decoration: none;
        transition: background 0.15s, border-color 0.15s;
    }
    .auth-modal .auth-btn-google:hover { background: rgba(255,255,255,.1); border-color: rgba(255,255,255,.25); }
    .auth-modal .auth-divider { display: flex; align-items: center; margin: 1rem 0; font-size: 0.8125rem; color: #475569; }
    .auth-modal .auth-divider::before, .auth-modal .auth-divider::after { content: ''; flex: 1; height: 1px; background: rgba(255,255,255,.08); }
    .auth-modal .auth-divider span { padding: 0 0.75rem; }
    /* OTP register tabs */
    .reg-tabs { display: flex; gap: 0; margin-bottom: 1.25rem; border-radius: 0.5rem; overflow: hidden; border: 1px solid rgba(255,255,255,.1); }
    .reg-tab { flex: 1; padding: 0.6rem 0.75rem; text-align: center; font-size: 0.875rem; font-weight: 600; background: rgba(255,255,255,.03); color: #64748b; border: none; cursor: pointer; transition: all 0.2s; }
    .reg-tab.active { background: #32a1c4; color: #fff; }
    .reg-tab:not(.active):hover { background: rgba(83,197,224,.1); color: #53C5E0; }
    .reg-otp-row { display: flex; gap: 0.5rem; align-items: flex-end; }
    .reg-otp-row .input-field { flex: 1; }
    .reg-otp-btn { padding: 0.5rem 1rem; background: #32a1c4; color: #fff; border: none; border-radius: 0.5rem; font-size: 0.8rem; font-weight: 600; cursor: pointer; white-space: nowrap; transition: opacity 0.2s; }
    .reg-otp-btn:hover { opacity: 0.9; }
    .reg-otp-btn:disabled { opacity: 0.5; cursor: not-allowed; }
    .reg-step { display: none; }
    .reg-step.active { display: block; }
    .reg-code-inputs { display: flex; gap: 0.4rem; justify-content: center; margin: 1rem 0; }
    .reg-code-inputs input { width: 2.5rem; height: 2.8rem; text-align: center; font-size: 1.25rem; font-weight: 700; background: rgba(255,255,255,.05); border: 2px solid rgba(255,255,255,.12); border-radius: 0.5rem; color: #e0f2fe; }
    .reg-code-inputs input:focus { border-color: #32a1c4; outline: none; box-shadow: 0 0 0 3px rgba(83,197,224,.2); }
    .reg-verified { display: inline-flex; align-items: center; gap: 0.3rem; padding: 0.35rem 0.75rem; background: rgba(34,197,94,.12); border: 1px solid rgba(34,197,94,.3); color: #86efac; border-radius: 0.5rem; font-size: 0.8rem; font-weight: 600; }
    .reg-dev-code { background: rgba(251,191,36,.1); border: 1px solid rgba(251,191,36,.25); color: #fbbf24; padding: 0.5rem 0.75rem; border-radius: 0.5rem; font-size: 0.8rem; margin-top: 0.5rem; text-align: center; }
    .reg-countdown { font-size: 0.8rem; color: #64748b; margin-top: 0.5rem; text-align: center; }
    .reg-step-indicator { display: flex; align-items: center; justify-content: center; gap: 0.5rem; margin-bottom: 1.25rem; font-size: 0.75rem; color: #64748b; }
    .reg-step-dot { width: 24px; height: 24px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-weight: 700; font-size: 0.75rem; background: rgba(255,255,255,.08); color: #64748b; }
    .reg-step-dot.active { background: #32a1c4; color: #fff; }
    .reg-step-dot.done { background: #22c55e; color: #fff; }
    .reg-step-line { width: 2rem; height: 2px; background: rgba(255,255,255,.08); }
    .reg-step-line.done { background: #22c55e; }
    .auth-password-wrap { position: relative; }
    .auth-password-wrap .input-field { padding-right: 3rem; }
    .auth-password-toggle {
        position: absolute;
        right: 0.75rem;
        top: 50%;
        transform: translateY(-50%);
        display: inline-flex;
        align-items: center;
        justify-content: center;
        width: 2rem;
        height: 2rem;
        border: none;
        background: transparent;
        color: #94a3b8;
        cursor: pointer;
        padding: 0;
    }
    .auth-password-toggle:hover { color: #e0f2fe; }
    .auth-password-toggle:focus-visible {
        outline: 2px solid rgba(83, 197, 224, 0.45);
        outline-offset: 2px;
        border-radius: 0.5rem;
    }
    .auth-password-toggle svg {
        width: 1.25rem;
        height: 1.25rem;
        pointer-events: none;
    }
    .auth-modal input[type="password"]::-ms-reveal,
    .auth-modal input[type="password"]::-ms-clear {
        display: none;
    }
    #reg-form-final {
        display: flex;
        flex-direction: column;
        gap: 0.95rem;
    }
    #reg-form-final .auth-field,
    #reg-form-final .reg-tabs {
        margin-bottom: 0;
    }
    #reg-form-final .auth-btn-submit {
        margin-top: 0;
    }
    #reg-otp-step {
        display: none;
        text-align: center;
    }
    #reg-otp-step.active {
        display: block;
    }
    #reg-otp-step .auth-field {
        margin-bottom: 0;
    }
    #reg-otp-step .auth-modal-sub {
        margin-bottom: 1rem !important;
    }
    #reg-otp-step label {
        text-align: center;
        width: 100%;
        margin-bottom: 0.5rem;
    }
    .reg-code-inputs {
        margin: 0.25rem 0 0.25rem;
    }
    .reg-code-inputs input {
        width: 2.85rem;
        height: 2.9rem;
        font-size: 1.15rem;
        border-radius: 0.55rem;
    }
    .reg-otp-actions {
        display: flex;
        justify-content: center;
        margin-top: 1rem;
    }
    .reg-otp-actions .auth-btn-submit {
        width: 100%;
        max-width: 220px;
        min-height: 46px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        white-space: nowrap;
    }
    .reg-otp-resend {
        margin-top: 0.85rem;
        font-size: 0.95rem;
        color: #94a3b8;
        min-height: 24px;
    }
    .reg-otp-resend a {
        color: #53C5E0;
        font-weight: 600;
        text-decoration: none;
    }
    .reg-otp-resend a:hover {
        color: #7acae3;
    }
    .auth-btn-secondary {
        width: 100%;
        padding: 0.7rem 1rem;
        background: transparent;
        color: #cbd5e1;
        font-weight: 600;
        border: 1px solid rgba(255,255,255,.2);
        border-radius: 0.5rem;
        cursor: pointer;
        font-size: 1rem;
    }
    .auth-btn-secondary:hover {
        background: rgba(255,255,255,.06);
    }
    @media (max-height: 760px) {
        .auth-modal-register .auth-modal-scrollable {
            overflow-y: auto;
            padding-right: 4px;
        }
    }
</style>

<div class="auth-modal-backdrop" id="auth-modal-backdrop"></div>

<!-- Login Modal -->
<div class="auth-modal" id="auth-modal-login" role="dialog" aria-labelledby="auth-login-title" aria-modal="true">
    <button type="button" class="auth-modal-close" data-auth-close aria-label="Close">&times;</button>
    <div class="auth-modal-scrollable">
        <div class="auth-modal-inner">
            <h2 id="auth-login-title">Welcome Back</h2>
            <p class="auth-modal-sub">Sign in to your PrintFlow account</p>
            <div id="auth-login-message"></div>
            <form method="POST" action="<?php echo htmlspecialchars($base_url); ?>/login">
                <?php echo csrf_field(); ?>
                <div class="auth-field">
                    <label for="auth-email">Email Address</label>
                    <input type="email" id="auth-email" name="email" class="input-field"
                           placeholder="Email address" required
                           maxlength="150"
                           autocomplete="email">
                    <p class="modal-field-error" id="auth-email-error"></p>
                </div>
                <div class="auth-field">
                    <label for="auth-password">Password</label>
                    <div class="auth-password-wrap">
                        <input type="password" id="auth-password" name="password" class="input-field"
                               placeholder="Password" required
                               maxlength="64"
                               autocomplete="current-password">
                        <button type="button" class="auth-password-toggle" data-toggle-password aria-label="Show password" aria-controls="auth-password">
                            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M1.5 12s3.75-7.5 10.5-7.5S22.5 12 22.5 12 18.75 19.5 12 19.5 1.5 12 1.5 12z"></path><circle cx="12" cy="12" r="3" stroke-width="2"></circle></svg>
                        </button>
                    </div>
                    <p class="modal-field-error" id="auth-password-error"></p>
                </div>
                <div class="auth-field-row" style="margin-bottom: 1.5rem;">
                    <label for="auth-remember-me">
                        <input type="checkbox" id="auth-remember-me" name="remember_me" value="1">
                        <span>Remember me</span>
                    </label>
                    <a href="#" data-forgot-modal="open" style="font-size:0.875rem; color:#53C5E0; text-decoration:none;">Forgot password?</a>
                </div>
                <button type="submit" class="auth-btn-submit">Sign In</button>
            </form>
            <?php echo $pf_google_auth_btn; ?>
            <p class="auth-switch">Don't have an account? <a href="#" data-auth-open="register">Register now</a></p>
        </div>
    </div>
</div>

<!-- Register Modal — 2-Step OTP Flow -->
<div class="auth-modal auth-modal-register" id="auth-modal-register" role="dialog" aria-labelledby="auth-register-title" aria-modal="true">
    <button type="button" class="auth-modal-close" data-auth-close aria-label="Close">&times;</button>
    <div class="auth-modal-scrollable">
        <div class="auth-modal-inner">
            <h2 id="auth-register-title">Create Account</h2>
            <p class="auth-modal-sub">Join PrintFlow — verify your email to get started</p>
            <div id="auth-register-message"></div>

            <!-- Step indicator (Removed) -->

            <!-- ═══ DIRECT REGISTRATION FORM ═══ -->
            <form method="POST" action="<?php echo htmlspecialchars($base_url); ?>/process_register.php" id="reg-form-final" novalidate>
                <?php echo csrf_field(); ?>
                <input type="hidden" name="reg_type" value="direct">
                <input type="hidden" name="identifier_type" id="reg-h-type" value="email">

                <!-- Identifier input (email only) -->
                <div class="auth-field">
                    <label id="reg-id-label" for="reg-identifier">Email Address</label>
                    <input type="email" id="reg-identifier" name="identifier" class="input-field"
                           placeholder="Email address"
                           maxlength="150"
                           autocomplete="email">
                    <p class="modal-field-error" id="reg-id-error"></p>
                </div>

                <!-- Password fields -->
                <div class="auth-field">
                    <label for="reg-password">Password <span style="color:#dc2626;">*</span></label>
                    <div class="auth-password-wrap">
                        <input type="password" id="reg-password" name="password" class="input-field"
                               placeholder="Password"
                               maxlength="64"
                               autocomplete="new-password">
                        <button type="button" class="auth-password-toggle" data-toggle-password aria-label="Show password" aria-controls="reg-password">
                            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M1.5 12s3.75-7.5 10.5-7.5S22.5 12 22.5 12 18.75 19.5 12 19.5 1.5 12 1.5 12z"></path><circle cx="12" cy="12" r="3" stroke-width="2"></circle></svg>
                        </button>
                    </div>
                    <!-- Password strength checklist — 2 cols × 3 rows -->
                    <ul class="reg-pw-checklist" id="reg-pw-checklist">
                        <li id="reg-pw-len" class="neutral">8–64 characters</li>
                        <li id="reg-pw-upper" class="neutral">1 uppercase letter</li>
                        <li id="reg-pw-lower" class="neutral">1 lowercase letter</li>
                        <li id="reg-pw-number" class="neutral">1 number</li>
                        <li id="reg-pw-special" class="neutral">1 special character</li>
                    </ul>
                    <p class="modal-field-error" id="reg-password-error"></p>
                </div>

                <div class="auth-field">
                    <label for="reg-confirm-pw">Confirm Password <span style="color:#dc2626;">*</span></label>
                    <div class="auth-password-wrap">
                        <input type="password" id="reg-confirm-pw" name="confirm_password" class="input-field"
                               placeholder="Confirm password"
                               maxlength="64"
                               autocomplete="new-password">
                        <button type="button" class="auth-password-toggle" data-toggle-password aria-label="Show password" aria-controls="reg-confirm-pw">
                            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M1.5 12s3.75-7.5 10.5-7.5S22.5 12 22.5 12 18.75 19.5 12 19.5 1.5 12 1.5 12z"></path><circle cx="12" cy="12" r="3" stroke-width="2"></circle></svg>
                        </button>
                    </div>
                    <p class="modal-field-error" id="reg-confirm-error"></p>
                    <p class="reg-match-ok" id="reg-match-ok" style="display:none;">✓ Passwords match</p>
                </div>

                <button type="submit" class="auth-btn-submit">Create Account</button>
            </form>
            <?php echo $pf_google_auth_btn; ?>

            <div id="reg-otp-step" aria-live="polite">
                <div class="auth-field">
                    <p class="auth-modal-sub" id="reg-otp-sub" style="margin-bottom:0.9rem;">Enter the 6-digit code sent to your email.</p>
                    <label for="reg-otp-digit-0">Verification Code</label>
                    <div class="reg-code-inputs" id="reg-otp-code-inputs">
                        <input type="text" id="reg-otp-digit-0" class="reg-otp-digit" inputmode="numeric" pattern="[0-9]*" maxlength="1" autocomplete="one-time-code">
                        <input type="text" id="reg-otp-digit-1" class="reg-otp-digit" inputmode="numeric" pattern="[0-9]*" maxlength="1">
                        <input type="text" id="reg-otp-digit-2" class="reg-otp-digit" inputmode="numeric" pattern="[0-9]*" maxlength="1">
                        <input type="text" id="reg-otp-digit-3" class="reg-otp-digit" inputmode="numeric" pattern="[0-9]*" maxlength="1">
                        <input type="text" id="reg-otp-digit-4" class="reg-otp-digit" inputmode="numeric" pattern="[0-9]*" maxlength="1">
                        <input type="text" id="reg-otp-digit-5" class="reg-otp-digit" inputmode="numeric" pattern="[0-9]*" maxlength="1">
                    </div>
                    <p class="modal-field-error" id="reg-otp-error"></p>
                </div>
                <div class="reg-otp-actions">
                    <button type="button" class="auth-btn-submit" id="reg-otp-verify-btn">Verify Code</button>
                </div>
                <p class="reg-otp-resend" id="reg-otp-resend-wrap"></p>
            </div>

            <p class="auth-switch">Already have an account? <a href="#" data-auth-open="login">Sign in</a></p>
        </div>
    </div>
</div>

<!-- Forgot Password Modal -->
<div class="auth-modal-backdrop" id="forgot-modal-backdrop" style="z-index: 100000;"></div>
<div class="auth-modal" id="forgot-modal" role="dialog" aria-labelledby="forgot-modal-title" aria-modal="true" style="z-index: 100001;">
    <button type="button" class="auth-modal-close" data-forgot-close aria-label="Close">&times;</button>
    <div class="auth-modal-inner">
        <h2 id="forgot-modal-title">Reset Password</h2>
        <p class="auth-modal-sub">Enter your email and we'll send you a reset code</p>
        
        <div id="forgot-message"></div>
        
        <!-- Form -->
        <form id="forgot-form" onsubmit="handleForgotSubmit(event)" novalidate>
            <input type="hidden" id="forgot-type" value="email">
            
            <div class="auth-field">
                <label id="forgot-label" for="forgot-identifier">Email Address</label>
                <input type="email" id="forgot-identifier" name="identifier" class="input-field" placeholder="you@example.com" maxlength="150" autocomplete="email">
                <p class="modal-field-error" id="forgot-identifier-error"></p>
            </div>
            
            <button type="submit" class="auth-btn-submit" style="margin-top: 0.5rem;">Send Reset Code</button>
        </form>
        
        <p class="auth-switch" style="margin-top: 1rem;">
            <a href="#" data-forgot-close>Back to login</a>
        </p>
    </div>
</div>

<script>
(function() {
    var backdrop = document.getElementById('auth-modal-backdrop');
    var loginModal = document.getElementById('auth-modal-login');
    var registerModal = document.getElementById('auth-modal-register');
    var authModal = <?php echo json_encode($auth_modal); ?>;
    var authError = <?php echo json_encode($auth_error); ?>;
    var authSuccess = <?php echo json_encode($auth_success); ?>;

    function openModal(name) {
        // Close any already-open modal first so switching between login/register works
        if (loginModal) { loginModal.classList.remove('is-open'); }
        if (registerModal) { registerModal.classList.remove('is-open'); }
        var modal = name === 'register' ? registerModal : loginModal;
        if (!modal) return;
        if (name === 'register') resetRegisterFlow();
        backdrop.classList.add('is-open');
        modal.classList.add('is-open');
        document.body.style.overflow = 'hidden';
        
        // Focus first input for accessibility
        setTimeout(function() {
            var firstInput = modal.querySelector('input');
            if (firstInput) firstInput.focus();
        }, 100);
    }
    window.openModal = openModal;
    function closeModal() {
        if (!backdrop) return;
        backdrop.classList.remove('is-open');
        if (loginModal) { loginModal.classList.remove('is-open'); }
        if (registerModal) { registerModal.classList.remove('is-open'); }
        document.body.style.overflow = '';
    }
    window.closeModal = closeModal;
    function showMessage(modalName, type, text) {
        if (!text) return;
        var el = document.getElementById('auth-' + modalName + '-message');
        if (!el) return;
        el.innerHTML = '<div class="auth-alert-' + type + '">' + escapeHtml(text) + '</div>';
    }
    function escapeHtml(s) {
        var div = document.createElement('div');
        div.textContent = s;
        return div.innerHTML;
    }
    function setButtonLoading(btn, loading, label) {
        if (!btn) return;
        if (loading) {
            if (!btn.dataset.originalHtml) btn.dataset.originalHtml = btn.innerHTML;
            btn.disabled = true;
            btn.innerHTML = '<span class="btn-loading-wrap"><span class="btn-loading-spinner"></span><span>' + escapeHtml(label || 'Loading...') + '</span></span>';
            return;
        }
        btn.disabled = false;
        if (btn.dataset.originalHtml) {
            btn.innerHTML = btn.dataset.originalHtml;
        }
    }
    function getPasswordIcon(isVisible) {
        return isVisible
            ? '<svg fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 3l18 18"></path><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.584 10.587A2 2 0 0012 14a2 2 0 001.414-.586"></path><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9.878 5.099A10.45 10.45 0 0112 4.5c6.75 0 10.5 7.5 10.5 7.5a17.537 17.537 0 01-4.232 4.919M6.228 6.228A17.646 17.646 0 001.5 12s3.75 7.5 10.5 7.5a10.56 10.56 0 005.012-1.228"></path></svg>'
            : '<svg fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M1.5 12s3.75-7.5 10.5-7.5S22.5 12 22.5 12 18.75 19.5 12 19.5 1.5 12 1.5 12z"></path><circle cx="12" cy="12" r="3" stroke-width="2"></circle></svg>';
    }

    document.addEventListener('click', function(e) {
        var authTrigger = e.target.closest('[data-auth-modal], [data-auth-open]');
        if (authTrigger) {
            e.preventDefault();
            var name = (authTrigger.getAttribute('data-auth-modal') || authTrigger.getAttribute('data-auth-open') || '').toLowerCase();
            var redirectTarget = authTrigger.getAttribute('data-auth-redirect') || '';
            if (redirectTarget) {
                try {
                    var targetUrl = new URL(redirectTarget, window.location.origin);
                    if (targetUrl.origin === window.location.origin) {
                        localStorage.setItem('pf_auth_return_target', JSON.stringify({
                            ts: Date.now(),
                            returnUrl: targetUrl.href
                        }));
                    }
                } catch (err) {}
            }
            if (name === 'login' || name === 'register') openModal(name);
        }
        if (e.target.matches('[data-auth-close]') || e.target === backdrop) {
            e.preventDefault();
            closeModal();
        }
        var toggle = e.target.closest('[data-toggle-password]');
        if (toggle) {
            e.preventDefault();
            var inputId = toggle.getAttribute('aria-controls');
            var input = inputId ? document.getElementById(inputId) : null;
            if (!input) return;
            var isVisible = input.type === 'text';
            input.type = isVisible ? 'password' : 'text';
            toggle.setAttribute('aria-label', isVisible ? 'Show password' : 'Hide password');
            toggle.innerHTML = getPasswordIcon(!isVisible);
        }
    });
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape' && backdrop && backdrop.classList.contains('is-open')) closeModal();
    });

    if (authModal === 'login' || authModal === 'register') {
        openModal(authModal);
        if (authError) showMessage(authModal === 'login' ? 'login' : 'register', 'error', authError);
        if (authSuccess) showMessage(authModal === 'login' ? 'login' : 'register', 'success', authSuccess);
    }

    // ═══ Forgot Password Modal Logic ═══
    var forgotBackdrop = document.getElementById('forgot-modal-backdrop');
    var forgotModal = document.getElementById('forgot-modal');

    function showForgotMessage(type, text) {
        var msgEl = document.getElementById('forgot-message');
        if (msgEl) {
            msgEl.innerHTML = '<div class="auth-alert-' + type + '">' + escapeHtml(text) + '</div>';
        }
    }

    function openForgotModal() {
        closeModal();
        if (!forgotBackdrop || !forgotModal) return;
        var fidErr = document.getElementById('forgot-identifier-error');
        var fidInp = document.getElementById('forgot-identifier');
        if (fidErr) fidErr.textContent = '';
        if (fidInp) fidInp.style.borderColor = '';
        forgotBackdrop.classList.add('is-open');
        forgotModal.classList.add('is-open');
        document.body.style.overflow = 'hidden';
    }

    function closeForgotModal(backToLogin) {
        if (!forgotBackdrop || !forgotModal) return;
        forgotBackdrop.classList.remove('is-open');
        forgotModal.classList.remove('is-open');
        document.body.style.overflow = '';

        var form = document.getElementById('forgot-form');
        if (form) form.reset();
        var msg = document.getElementById('forgot-message');
        if (msg) msg.innerHTML = '';
        var fidErr = document.getElementById('forgot-identifier-error');
        if (fidErr) fidErr.textContent = '';
        var fidInp = document.getElementById('forgot-identifier');
        if (fidInp) { fidInp.style.borderColor = ''; }

        if (backToLogin) {
            openModal('login');
        }
    }

    function forgotValidEmail(val) {
        if (!val) return 'Email is required.';
        if (val.length > 150) return 'Email must not exceed 150 characters.';
        // Require at least 2 characters after the last dot in domain
        if (!/^[^\s@]+@[^\s@]+\.[a-zA-Z]{2,}$/.test(val)) return 'Invalid email address.';
        return null;
    }
    function forgotSetFieldError(inputEl, errEl, msg) {
        if (!inputEl || !errEl) return;
        errEl.textContent = msg || '';
        if (msg) {
            inputEl.style.borderColor = '#f87171';
        } else {
            inputEl.style.borderColor = '';
        }
    }

    window.handleForgotSubmit = function(e) {
        e.preventDefault();

        var type = document.getElementById('forgot-type').value;
        var idInput = document.getElementById('forgot-identifier');
        var idErrEl = document.getElementById('forgot-identifier-error');
        var identifier = (idInput && idInput.value || '').trim().replace(/\s/g, '');
        if (idInput) idInput.value = identifier;
        var submitBtn = e.target.querySelector('button[type="submit"]');
        var originalText = submitBtn ? submitBtn.textContent : '';

        var emailErr = type === 'email' ? forgotValidEmail(identifier) : null;
        forgotSetFieldError(idInput, idErrEl, emailErr || '');
        if (emailErr) {
            var msgTop = document.getElementById('forgot-message');
            if (msgTop) msgTop.innerHTML = '';
            return;
        }

        if (submitBtn) {
            submitBtn.disabled = true;
            submitBtn.textContent = 'Sending...';
        }

        var formData = new FormData();
        formData.append('type', type);
        formData.append('identifier', identifier);

        fetch('<?php echo htmlspecialchars($base_url); ?>/public/api_forgot_password.php', {
            method: 'POST',
            body: formData,
            credentials: 'same-origin'
        })
        .then(function(response) { return response.text(); })
        .then(function(text) {
            var data = null;
            try { data = JSON.parse(text); } catch (err) { data = null; }

            if (!data) {
                showForgotMessage('error', 'Server error. Please try again.');
                return;
            }
            if (data.success) {
                showForgotMessage('success', data.message || 'Reset code sent successfully!');
                setTimeout(function() {
                    closeForgotModal(true);
                }, 900);
            } else {
                showForgotMessage('error', data.message || 'Failed to send reset code. Please try again.');
            }
        })
        .catch(function() {
            showForgotMessage('error', 'Network error. Please try again.');
        })
        .finally(function() {
            if (submitBtn) {
                submitBtn.disabled = false;
                submitBtn.textContent = originalText || 'Send Reset Code';
            }
        });
    };

    var forgotIdInput = document.getElementById('forgot-identifier');
    if (forgotIdInput) {
        forgotIdInput.addEventListener('input', function() {
            this.value = this.value.replace(/\s/g, '');
            forgotSetFieldError(this, document.getElementById('forgot-identifier-error'), '');
        });
        forgotIdInput.addEventListener('blur', function() {
            var t = (document.getElementById('forgot-type') || {}).value || 'email';
            if (t !== 'email') return;
            var v = (this.value || '').trim();
            var err = forgotValidEmail(v);
            forgotSetFieldError(this, document.getElementById('forgot-identifier-error'), err || '');
        });
    }
    
    // Forgot modal event listeners
    document.addEventListener('click', function(e) {
        if (e.target.matches('[data-forgot-modal]')) {
            e.preventDefault();
            openForgotModal();
        }
        if (e.target.matches('[data-forgot-close]')) {
            e.preventDefault();
            closeForgotModal(true);
        }
        if (e.target === forgotBackdrop) {
            closeForgotModal();
        }
    });

    // ═══ Registration logic ═══
    var regTouched = { identifier: false, password: false, confirm: false };
    var regPendingEmail = '';
    var regPendingType = 'email';
    var regOtpCooldownTimer = null;
    var regOtpResendAttempts = 0;
    var regOtpCooldownEndMs = 0;   // absolute timestamp when cooldown ends
    var regOtpCreatedAtMs   = 0;   // when this OTP was first issued
    var REG_OTP_EXPIRY_MS   = 10 * 60 * 1000;   // 10 min matches server
    var REG_OTP_STORAGE_KEY = 'pf_otp_pending';

    function regGetCsrfToken() {
        // always read fresh from DOM so token is never stale
        var el = document.querySelector('#reg-form-final input[name="csrf_token"]')
               || document.querySelector('input[name="csrf_token"]');
        return el ? el.value : '';
    }

    // ── sessionStorage helpers ──────────────────────────────────
    function regSaveOtpState() {
        try {
            sessionStorage.setItem(REG_OTP_STORAGE_KEY, JSON.stringify({
                email:         regPendingEmail,
                type:          regPendingType,
                attempts:      regOtpResendAttempts,
                cooldownEndMs: regOtpCooldownEndMs,
                createdAtMs:   regOtpCreatedAtMs
            }));
        } catch (e) { /* ignore */ }
    }

    function regClearOtpState() {
        try { sessionStorage.removeItem(REG_OTP_STORAGE_KEY); } catch (e) { /* ignore */ }
    }

    function regRestoreOtpState() {
        var raw;
        try { raw = sessionStorage.getItem(REG_OTP_STORAGE_KEY); } catch (e) { return false; }
        if (!raw) return false;
        var state;
        try { state = JSON.parse(raw); } catch (e) { return false; }
        if (!state || !state.email) return false;

        var now = Date.now();
        var createdAt = state.createdAtMs || 0;

        // OTP expired on the server side (10 min window)
        if (createdAt && (now - createdAt) > REG_OTP_EXPIRY_MS) {
            regClearOtpState();
            setTimeout(function() {
                openModal('register');
                var msgEl = document.getElementById('auth-register-message');
                if (msgEl) msgEl.innerHTML = '<div class="auth-alert-error">Your verification code expired. Please register again to get a new code.</div>';
            }, 60);
            return true;
        }

        regPendingEmail         = state.email;
        regPendingType          = state.type || 'email';
        regOtpResendAttempts    = state.attempts || 0;
        regOtpCooldownEndMs     = state.cooldownEndMs || 0;
        regOtpCreatedAtMs       = createdAt;

        var remainingSecs = Math.max(0, Math.ceil((regOtpCooldownEndMs - now) / 1000));

        setTimeout(function() {
            openModal('register');
            var otpSub = document.getElementById('reg-otp-sub');
            if (otpSub) {
                otpSub.textContent = regPendingType === 'email'
                    ? 'Enter the 6-digit code sent to your email.'
                    : 'Enter the 6-digit code sent for verification.';
            }
            showRegisterStep('otp');
            regStartOtpCooldown(remainingSecs, true /* restored, skip re-save */);
        }, 60);
        return true;
    }

    window.regSwitchTab = function(type) {
        document.getElementById('reg-h-type').value = 'email';
        var idEl = document.getElementById('reg-identifier');
        var errEl = document.getElementById('reg-id-error');
        if (idEl) { idEl.placeholder = 'Email address'; idEl.type = 'email'; idEl.maxLength = 150; idEl.autocomplete = 'email'; }
        if (errEl) errEl.textContent = '';
        regTouched.identifier = false;
        regCheckForm(false);
    };

    // ── Register modal validators ────────────────────────────────
    /** True when server message means email/identifier is already registered (show under email field). */
    function regIsEmailInUseError(text) {
        if (!text) return false;
        var s = String(text).toLowerCase();
        var dupCue = ['already', 'exist', 'registered', 'in use', 'taken', 'duplicate'];
        var hasDup = false;
        for (var i = 0; i < dupCue.length; i++) {
            if (s.indexOf(dupCue[i]) !== -1) { hasDup = true; break; }
        }
        if (!hasDup) return false;
        return s.indexOf('email') !== -1 || s.indexOf('account') !== -1 || s.indexOf('address') !== -1
            || s.indexOf('sign in') !== -1 || s.indexOf('login') !== -1 || s.indexOf('sign-in') !== -1;
    }

    function regValidEmail(val) {
        if (!val) return 'Email is required.';
        if (val.length > 150) return 'Email must not exceed 150 characters.';
        // Require at least 2 characters after the last dot in domain
        if (!/^[^\s@]+@[^\s@]+\.[a-zA-Z]{2,}$/.test(val)) return 'Invalid email address.';
        return null;
    }
    function regValidPassword(val) {
        if (!val) return 'Password is required.';
        if (val.length < 8) return 'Password must be at least 8 characters.';
        if (val.length > 64) return 'Password must be at most 64 characters.';
        if (!/[A-Z]/.test(val)) return 'Need at least 1 uppercase letter.';
        if (!/[a-z]/.test(val)) return 'Need at least 1 lowercase letter.';
        if (!/[0-9]/.test(val)) return 'Need at least 1 number.';
        if (!/[^A-Za-z0-9]/.test(val)) return 'Need at least 1 special character.';
        return null;
    }

    function regUpdateChecklist() {
        var checklistEl = document.getElementById('reg-pw-checklist');
        var val = (document.getElementById('reg-password') || {}).value || '';
        var hasAny = val.length > 0;
        if (checklistEl) checklistEl.classList.toggle('is-visible', hasAny);
        /* Same rule row as reset-password.php setRule(pwRules.len, …) */
        var checks = {
            'reg-pw-len':     val.length >= 8 && val.length <= 64,
            'reg-pw-upper':   /[A-Z]/.test(val),
            'reg-pw-lower':   /[a-z]/.test(val),
            'reg-pw-number':  /[0-9]/.test(val),
            'reg-pw-special': /[^A-Za-z0-9]/.test(val)
        };
        Object.entries(checks).forEach(function(pair) {
            var el = document.getElementById(pair[0]);
            if (!el) return;
            if (!hasAny) {
                el.classList.add('neutral');
                el.classList.remove('ok', 'bad');
            } else {
                el.classList.remove('neutral');
                el.classList.toggle('ok', pair[1]);
                el.classList.toggle('bad', !pair[1]);
            }
        });
    }

    function regCheckConfirm(showErrors) {
        var pw = (document.getElementById('reg-password') || {}).value || '';
        var cpw = (document.getElementById('reg-confirm-pw') || {}).value || '';
        var confirmInput = document.getElementById('reg-confirm-pw');
        var errEl = document.getElementById('reg-confirm-error');
        var okEl = document.getElementById('reg-match-ok');
        var showMsg = Boolean(showErrors || regTouched.confirm);
        if (!confirmInput || !errEl) return false;
        if (!cpw) {
            errEl.textContent = showMsg ? 'Please confirm your password.' : '';
            confirmInput.classList.remove('is-valid');
            if (showMsg) confirmInput.classList.add('is-invalid');
            else confirmInput.classList.remove('is-invalid');
            if (okEl) okEl.style.display = 'none';
            return false;
        }
        if (cpw !== pw) {
            errEl.textContent = showMsg ? 'Passwords do not match.' : '';
            if (okEl) okEl.style.display = 'none';
            if (showMsg) {
                confirmInput.classList.add('is-invalid');
                confirmInput.classList.remove('is-valid');
            } else {
                confirmInput.classList.remove('is-invalid', 'is-valid');
            }
            return false;
        }
        errEl.textContent = '';
        confirmInput.classList.remove('is-invalid');
        confirmInput.classList.add('is-valid');
        if (okEl) {
            okEl.style.display = '';
            okEl.textContent = '✓ Passwords match';
        }
        return true;
    }

    function regSetFieldError(inputEl, errEl, msg) {
        if (!inputEl || !errEl) return;
        errEl.textContent = msg || '';
        if (msg) {
            inputEl.classList.add('is-invalid');
            inputEl.classList.remove('is-valid');
        } else {
            inputEl.classList.remove('is-invalid');
        }
    }

    function regCheckForm(showErrors) {
        showErrors = Boolean(showErrors);
        /* Checklist first on every pass — same order as reset-password updatePwChecklist → resetCheckForm */
        regUpdateChecklist();

        var idEl = document.getElementById('reg-identifier');
        var pwEl = document.getElementById('reg-password');
        var idErrEl = document.getElementById('reg-id-error');
        var pwErrEl = document.getElementById('reg-password-error');
        var submitBtn = document.querySelector('#reg-form-final button[type="submit"]');

        var idVal = idEl ? idEl.value.trim() : '';
        var pwVal = pwEl ? pwEl.value : '';

        var idErr = regValidEmail(idVal);
        regSetFieldError(idEl, idErrEl, (showErrors || regTouched.identifier) ? (idErr || '') : '');
        if (!idErr && idVal && idEl) {
            idEl.classList.add('is-valid');
            idEl.classList.remove('is-invalid');
        } else if (!idVal && idEl) {
            idEl.classList.remove('is-valid');
        }
        var idOk = !idErr;

        var pwErr = regValidPassword(pwVal);
        regSetFieldError(pwEl, pwErrEl, (showErrors || regTouched.password) ? (pwErr || '') : '');
        if (!pwErr && pwVal && pwEl) {
            pwEl.classList.add('is-valid');
            pwEl.classList.remove('is-invalid');
        } else if (!pwVal && pwEl) {
            pwEl.classList.remove('is-valid');
        }
        var pwOk = !pwErr;

        var cpwOk = regCheckConfirm(showErrors || regTouched.confirm);

        if (submitBtn) submitBtn.disabled = !(idOk && pwOk && cpwOk);
    }

    function regBlockSpaces(el) {
        if (!el) return;
        el.addEventListener('keydown', function(e) {
            if (e.key === ' ') e.preventDefault();
        });
        el.addEventListener('paste', function() {
            var self = this;
            setTimeout(function() {
                self.value = self.value.replace(/\s/g, '');
                if (self.id === 'reg-password') regTouched.password = true;
                else if (self.id === 'reg-confirm-pw') regTouched.confirm = true;
                regCheckForm(false);
            }, 0);
        });
    }

    function showRegisterStep(stepName) {
        var formEl = document.getElementById('reg-form-final');
        var otpEl = document.getElementById('reg-otp-step');
        if (!formEl || !otpEl) return;
        if (stepName === 'otp') {
            formEl.style.display = 'none';
            otpEl.classList.add('active');
            setTimeout(function() {
                var first = document.getElementById('reg-otp-digit-0');
                if (first) first.focus();
            }, 80);
        } else {
            formEl.style.display = '';
            otpEl.classList.remove('active');
        }
    }

    function regOtpCooldownSeconds(attempt) {
        if (attempt <= 0) return 60;       // initial wait
        if (attempt === 1) return 300;     // after 1st resend
        if (attempt === 2) return 900;     // after 2nd resend
        if (attempt === 3) return 1800;    // after 3rd resend
        return 3600;                       // after 4th+ resend
    }

    function regRenderResendUI(remainingSeconds) {
        var wrap = document.getElementById('reg-otp-resend-wrap');
        if (!wrap) return;
        if (remainingSeconds > 0) {
            var display;
            if (remainingSeconds >= 3600) {
                var h = Math.floor(remainingSeconds / 3600);
                var m = Math.floor((remainingSeconds % 3600) / 60);
                display = h + (h === 1 ? ' hour' : ' hours');
                if (m > 0) display += ' ' + m + ' min';
            } else if (remainingSeconds >= 60) {
                var m = Math.floor(remainingSeconds / 60);
                var s = remainingSeconds % 60;
                display = m + (m === 1 ? ' minute' : ' minutes');
                if (s > 0) display += ' ' + s + (s === 1 ? ' sec' : ' sec');
            } else {
                display = remainingSeconds + (remainingSeconds === 1 ? ' second' : ' seconds');
            }
            wrap.textContent = 'Resend available in ' + display;
        } else {
            wrap.innerHTML = '<a href="#" id="reg-otp-resend-link">Resend Code</a>';
            var resendLink = document.getElementById('reg-otp-resend-link');
            if (resendLink) {
                resendLink.addEventListener('click', function(e) {
                    e.preventDefault();
                    regHandleResendOtp();
                });
            }
        }
    }

    function regStartOtpCooldown(seconds, skipSave) {
        if (regOtpCooldownTimer) {
            clearInterval(regOtpCooldownTimer);
            regOtpCooldownTimer = null;
        }
        var remaining = Math.max(0, Number(seconds) || 0);
        // Record absolute end time for refresh-restore
        regOtpCooldownEndMs = remaining > 0 ? (Date.now() + remaining * 1000) : 0;
        regRenderResendUI(remaining);
        if (!skipSave) regSaveOtpState();
        if (remaining <= 0) return;
        regOtpCooldownTimer = setInterval(function() {
            remaining--;
            regRenderResendUI(remaining);
            if (remaining <= 0 && regOtpCooldownTimer) {
                clearInterval(regOtpCooldownTimer);
                regOtpCooldownTimer = null;
            }
        }, 1000);
    }

    function regHandleResendOtp() {
        if (!regPendingEmail) {
            // Fallback: recover pending state after refresh/manual modal reopen
            try {
                var raw = sessionStorage.getItem(REG_OTP_STORAGE_KEY);
                if (raw) {
                    var s = JSON.parse(raw);
                    if (s && s.email) regPendingEmail = s.email;
                }
            } catch (e) { /* ignore */ }
        }
        if (!regPendingEmail) {
            var missingStateErrEl = document.getElementById('reg-otp-error');
            if (missingStateErrEl) missingStateErrEl.textContent = 'Verification session missing. Please register again.';
            showRegisterStep('form');
            return;
        }
        var otpErrorEl = document.getElementById('reg-otp-error');
        var regMsgEl   = document.getElementById('auth-register-message');
        var wrap       = document.getElementById('reg-otp-resend-wrap');
        if (wrap) wrap.textContent = 'Sending new code…';

        var fd = new FormData();
        fd.append('email',      regPendingEmail);
        fd.append('csrf_token', regGetCsrfToken());

        fetch('<?php echo htmlspecialchars($base_url); ?>/public/resend_otp.php', {
            method: 'POST',
            body: fd,
            credentials: 'same-origin'
        })
        .then(function(res) { return res.text(); })
        .then(function(text) {
            // Strip any PHP notices/warnings prepended before JSON
            var jsonStart = text.indexOf('{');
            var jsonText  = jsonStart >= 0 ? text.slice(jsonStart) : '';
            var data = null;
            try { data = JSON.parse(jsonText); } catch (err) { data = null; }
            if (!data) {
                if (otpErrorEl) otpErrorEl.textContent = 'Server error while resending. Please try again.';
                regRenderResendUI(0);
                return;
            }
            if (!data.success) {
                if (otpErrorEl) otpErrorEl.textContent = data.message || 'Failed to resend code.';
                var waitSeconds = Number(data.remaining_seconds || 0);
                if (waitSeconds > 0) {
                    regStartOtpCooldown(waitSeconds);
                } else {
                    regRenderResendUI(0);
                }
                return;
            }
            if (otpErrorEl) otpErrorEl.textContent = '';
            if (regMsgEl) regMsgEl.innerHTML = '<div class="auth-alert-success">' + escapeHtml(data.message || 'A new code has been sent.') + '</div>';
            regOtpResendAttempts++;
            var nextSeconds = Math.max(60, Number(data.new_cooldown || regOtpCooldownSeconds(regOtpResendAttempts)));
            regStartOtpCooldown(nextSeconds);   // saves state internally
        })
        .catch(function(err) {
            if (otpErrorEl) otpErrorEl.textContent = 'Network error. Please try again.';
            regRenderResendUI(0);
        });
    }

    function resetRegisterFlow() {
        regTouched = { identifier: false, password: false, confirm: false };
        regPendingEmail      = '';
        regPendingType       = 'email';
        regOtpResendAttempts = 0;
        regOtpCooldownEndMs  = 0;
        regOtpCreatedAtMs    = 0;
        regPhoneVerified     = false;
        regClearOtpState();
        if (regOtpCooldownTimer) {
            clearInterval(regOtpCooldownTimer);
            regOtpCooldownTimer = null;
        }

        var finalFormEl = document.getElementById('reg-form-final');
        if (finalFormEl) finalFormEl.reset();
        showRegisterStep('form');

        var otpDigits = document.querySelectorAll('.reg-otp-digit');
        var otpErrEl = document.getElementById('reg-otp-error');
        var regMsgEl = document.getElementById('auth-register-message');
        if (otpDigits && otpDigits.length) {
            otpDigits.forEach(function(input) { input.value = ''; });
        }
        if (otpErrEl) otpErrEl.textContent = '';
        if (regMsgEl) regMsgEl.innerHTML = '';
        regRenderResendUI(0);

        regSwitchTab('email');
        regCheckForm(false);
    }

    var apiBase = '<?php echo htmlspecialchars($base_url); ?>/public';

    function openPhoneOtpModal() {
        var b = document.getElementById('phone-otp-backdrop');
        var m = document.getElementById('phone-otp-modal');
        if (b) b.classList.add('is-open');
        if (m) m.classList.add('is-open');
        document.body.style.overflow = 'hidden';
        setTimeout(function() {
            var f = document.getElementById('phone-otp-digit-0');
            if (f) f.focus();
        }, 80);
    }
    function closePhoneOtpModal() {
        var b = document.getElementById('phone-otp-backdrop');
        var m = document.getElementById('phone-otp-modal');
        if (b) b.classList.remove('is-open');
        if (m) m.classList.remove('is-open');
        document.body.style.overflow = '';
        var digits = document.querySelectorAll('#phone-otp-code-inputs input');
        if (digits) digits.forEach(function(i) { i.value = ''; });
        var err = document.getElementById('phone-otp-error');
        if (err) err.textContent = '';
    }

    document.addEventListener('click', function(e) {
        if (e.target.matches('[data-phone-otp-close]') || e.target.id === 'phone-otp-backdrop') {
            e.preventDefault();
            closePhoneOtpModal();
        }
    });

    var phoneOtpInputs = Array.prototype.slice.call(document.querySelectorAll('#phone-otp-code-inputs input'));
    var phoneOtpErr = document.getElementById('phone-otp-error');
    var phoneOtpVerifyBtn = document.getElementById('phone-otp-verify-btn');

    function readPhoneOtpCode() {
        return phoneOtpInputs.map(function(i) { return i.value || ''; }).join('');
    }
    function fillPhoneOtpDigits(raw, startIdx) {
        var digits = (raw || '').replace(/\D/g, '').split('');
        for (var i = 0; i < digits.length && startIdx + i < phoneOtpInputs.length; i++)
            phoneOtpInputs[startIdx + i].value = digits[i];
        if (phoneOtpInputs[Math.min(startIdx + digits.length, 5)]) phoneOtpInputs[Math.min(startIdx + digits.length, 5)].focus();
    }

    if (phoneOtpInputs.length) {
        phoneOtpInputs.forEach(function(inp, idx) {
            inp.addEventListener('input', function() {
                var d = this.value.replace(/\D/g, '');
                if (d.length > 1) { this.value = ''; fillPhoneOtpDigits(d, idx); } else { this.value = d; if (d && idx < 5) phoneOtpInputs[idx + 1].focus(); }
                if (phoneOtpErr) phoneOtpErr.textContent = '';
            });
            inp.addEventListener('keydown', function(e) {
                if (e.key === 'Backspace' && !this.value && idx > 0) { phoneOtpInputs[idx - 1].focus(); phoneOtpInputs[idx - 1].value = ''; }
            });
            inp.addEventListener('paste', function(e) {
                e.preventDefault();
                fillPhoneOtpDigits((e.clipboardData || window.clipboardData).getData('text'), idx);
            });
        });
    }

    if (phoneOtpVerifyBtn) {
        phoneOtpVerifyBtn.addEventListener('click', function() {
            var code = readPhoneOtpCode();
            if (!regPendingEmail) {
                if (phoneOtpErr) phoneOtpErr.textContent = 'Session expired. Please register again.';
                closePhoneOtpModal();
                openModal('register');
                return;
            }
            if (!/^\d{6}$/.test(code)) {
                if (phoneOtpErr) phoneOtpErr.textContent = 'Please enter a valid 6-digit code.';
                return;
            }
            var origText = phoneOtpVerifyBtn.textContent;
            phoneOtpVerifyBtn.disabled = true;
            phoneOtpVerifyBtn.textContent = 'Verifying...';
            var fd = new FormData();
            fd.append('email', regPendingEmail);
            fd.append('otp', code);
            fetch(apiBase + '/verify_otp.php', { method: 'POST', body: fd, credentials: 'same-origin', redirect: 'follow' })
            .then(function(r) {
                var finalUrl = new URL(r.url, window.location.origin);
                var success = finalUrl.searchParams.get('auth_modal') === 'login' || finalUrl.searchParams.get('success');
                var errParam = finalUrl.searchParams.get('error');
                if (success) {
                    regClearOtpState();
                    closePhoneOtpModal();
                    openModal('login');
                    showMessage('login', 'success', 'Phone verified. Please log in.');
                } else {
                    if (phoneOtpErr) phoneOtpErr.textContent = (errParam && (errParam.indexOf('incorrect') !== -1 || errParam.indexOf('wrong') !== -1)) ? 'Incorrect OTP. Please try again.' : (errParam || 'Invalid or expired code.');
                    phoneOtpVerifyBtn.disabled = false;
                    phoneOtpVerifyBtn.textContent = origText || 'Verify Code';
                }
            })
            .catch(function() {
                if (phoneOtpErr) phoneOtpErr.textContent = 'Network error.';
                phoneOtpVerifyBtn.disabled = false;
                phoneOtpVerifyBtn.textContent = origText || 'Verify Code';
            });
        });
    }

    // Wire up register modal events
    var regIdEl  = document.getElementById('reg-identifier');
    var regPwEl  = document.getElementById('reg-password');
    var regCpwEl = document.getElementById('reg-confirm-pw');

    if (regIdEl) {
        regIdEl.addEventListener('input', function() {
            regTouched.identifier = true;
            var type = (document.getElementById('reg-h-type') || {}).value || 'email';
            if (type === 'email') this.value = this.value.replace(/\s/g, '');
            regCheckForm(false);
        });
        regIdEl.addEventListener('blur', function() {
            regTouched.identifier = true;
            regCheckForm(true);
        });
    }
    if (regPwEl)  {
        regPwEl.addEventListener('input', function() {
            this.value = this.value.replace(/\s/g, '');
            regTouched.password = true;
            regCheckForm(false);
        });
        regPwEl.addEventListener('blur', function() {
            regTouched.password = true;
            regCheckForm(true);
        });
    }
    if (regCpwEl) {
        regCpwEl.addEventListener('input', function() {
            this.value = this.value.replace(/\s/g, '');
            regTouched.confirm = true;
            regCheckForm(false);
        });
        regCpwEl.addEventListener('blur', function() {
            regTouched.confirm = true;
            regCheckForm(true);
        });
    }
    if (regPwEl) regBlockSpaces(regPwEl);
    if (regCpwEl) regBlockSpaces(regCpwEl);

    // Initial state — sync disabled + field errors with validators
    regCheckForm(false);

    if (authModal === 'register' && authError && regIsEmailInUseError(authError)) {
        regTouched.identifier = true;
        regSetFieldError(
            document.getElementById('reg-identifier'),
            document.getElementById('reg-id-error'),
            authError
        );
        regCheckForm(true);
    }

    // Register submit -> keep flow in modal and show OTP step
    var finalForm = document.getElementById('reg-form-final');
    if (finalForm) {
        finalForm.addEventListener('submit', function(e) {
            e.preventDefault();
            var idType = (document.getElementById('reg-h-type') || {}).value || 'email';
            var idVal  = (document.getElementById('reg-identifier') || {}).value.trim() || '';
            var pw  = (document.getElementById('reg-password') || {}).value || '';
            var cpw = (document.getElementById('reg-confirm-pw') || {}).value || '';
            var msgEl = document.getElementById('auth-register-message');
            var submitBtn = finalForm.querySelector('button[type="submit"]');

            regTouched.identifier = true;
            regTouched.password = true;
            regTouched.confirm = true;
            regCheckForm(true);

            if (submitBtn && submitBtn.disabled) {
                if (msgEl) msgEl.innerHTML = '';
                return;
            }

            if (msgEl) msgEl.innerHTML = '';
            if (submitBtn) setButtonLoading(submitBtn, true, 'Creating...');

            fetch(finalForm.action, {
                method: 'POST',
                body: new FormData(finalForm),
                credentials: 'same-origin'
            })
            .then(function(response) {
                var finalUrl = new URL(response.url, window.location.origin);
                var errorText = finalUrl.searchParams.get('error');

                if (finalUrl.pathname.indexOf('/verify_email.php') !== -1) {
                    regPendingType       = idType;
                    regPendingEmail      = idType === 'email' ? idVal : (idVal.trim() + '@phone.local');
                    regOtpResendAttempts = 0;
                    regOtpCreatedAtMs    = Date.now();
                    if (idType === 'phone') {
                        closeModal();
                        regSaveOtpState();
                        openPhoneOtpModal();
                        regStartOtpCooldown(regOtpCooldownSeconds(0));
                        return;
                    }
                    var otpSub = document.getElementById('reg-otp-sub');
                    if (otpSub) otpSub.textContent = 'Enter the 6-digit code sent to your email.';
                    showRegisterStep('otp');
                    regStartOtpCooldown(regOtpCooldownSeconds(0));
                    return;
                }

                if (errorText && msgEl) {
                    msgEl.innerHTML = '<div class="auth-alert-error">' + escapeHtml(errorText) + '</div>';
                    if (idType === 'email' && regIsEmailInUseError(errorText)) {
                        regTouched.identifier = true;
                        regSetFieldError(
                            document.getElementById('reg-identifier'),
                            document.getElementById('reg-id-error'),
                            errorText
                        );
                        regCheckForm(true);
                    }
                } else if (msgEl) {
                    msgEl.innerHTML = '<div class="auth-alert-error">Registration failed. Please try again.</div>';
                }
            })
            .catch(function() {
                if (msgEl) msgEl.innerHTML = '<div class="auth-alert-error">Network error. Please try again.</div>';
            })
            .finally(function() {
                if (submitBtn) setButtonLoading(submitBtn, false);
            });
        });
    }

    var otpInputs = Array.prototype.slice.call(document.querySelectorAll('.reg-otp-digit'));
    var otpErrorEl = document.getElementById('reg-otp-error');
    var otpVerifyBtn = document.getElementById('reg-otp-verify-btn');

    function readOtpCode() {
        return otpInputs.map(function(input) { return input.value || ''; }).join('');
    }

    function fillOtpDigits(rawValue, startIndex) {
        var digits = (rawValue || '').replace(/\D/g, '').split('');
        if (!digits.length) return;
        var idx = typeof startIndex === 'number' ? startIndex : 0;
        for (var i = 0; i < digits.length && idx + i < otpInputs.length; i++) {
            otpInputs[idx + i].value = digits[i];
        }
        var focusIndex = Math.min(idx + digits.length, otpInputs.length - 1);
        if (otpInputs[focusIndex]) otpInputs[focusIndex].focus();
    }

    if (otpInputs.length) {
        otpInputs.forEach(function(input, index) {
            input.addEventListener('input', function() {
                var digits = this.value.replace(/\D/g, '');
                if (digits.length > 1) {
                    this.value = '';
                    fillOtpDigits(digits, index);
                } else {
                    this.value = digits;
                    if (digits && index < otpInputs.length - 1) otpInputs[index + 1].focus();
                }
                if (otpErrorEl) otpErrorEl.textContent = '';
            });

            input.addEventListener('keydown', function(e) {
                if (e.key === 'Backspace' && !this.value && index > 0) {
                    otpInputs[index - 1].focus();
                    otpInputs[index - 1].value = '';
                }
                if (e.key === 'ArrowLeft' && index > 0) {
                    e.preventDefault();
                    otpInputs[index - 1].focus();
                }
                if (e.key === 'ArrowRight' && index < otpInputs.length - 1) {
                    e.preventDefault();
                    otpInputs[index + 1].focus();
                }
            });

            input.addEventListener('paste', function(e) {
                e.preventDefault();
                fillOtpDigits((e.clipboardData || window.clipboardData).getData('text'), index);
                if (otpErrorEl) otpErrorEl.textContent = '';
            });
        });
    }

    if (otpVerifyBtn) {
        otpVerifyBtn.addEventListener('click', function() {
            var code = readOtpCode();
            if (!regPendingEmail) {
                if (otpErrorEl) otpErrorEl.textContent = 'Please submit registration first.';
                showRegisterStep('form');
                return;
            }
            if (!/^\d{6}$/.test(code)) {
                if (otpErrorEl) otpErrorEl.textContent = 'Please enter a valid 6-digit code.';
                return;
            }

            var originalText = otpVerifyBtn.textContent;
            otpVerifyBtn.disabled = true;
            otpVerifyBtn.textContent = 'Verifying...';

            var fd = new FormData();
            fd.append('email', regPendingEmail);
            fd.append('otp', code);

            fetch('<?php echo htmlspecialchars($base_url); ?>/public/verify_otp.php', {
                method: 'POST',
                body: fd,
                credentials: 'same-origin'
            })
            .then(function(response) {
                var finalUrl = new URL(response.url, window.location.origin);
                var successText = finalUrl.searchParams.get('success');
                var errorText = finalUrl.searchParams.get('error');

                if (finalUrl.searchParams.get('auth_modal') === 'login') {
                    regClearOtpState();
                    openModal('login');
                    showMessage('login', 'success', successText || 'Email verified. Please log in.');
                    return;
                }
                if (errorText && otpErrorEl) {
                    var lower = String(errorText || '').toLowerCase();
                    if (lower.indexOf('incorrect') !== -1 || lower.indexOf('wrong') !== -1) {
                        otpErrorEl.textContent = 'Incorrect OTP. Please try again.';
                    } else {
                        otpErrorEl.textContent = errorText;
                    }
                } else if (otpErrorEl) {
                    otpErrorEl.textContent = 'Invalid or expired code.';
                }
            })
            .catch(function() {
                if (otpErrorEl) otpErrorEl.textContent = 'Network error. Please try again.';
            })
            .finally(function() {
                otpVerifyBtn.disabled = false;
                otpVerifyBtn.textContent = originalText || 'Verify Code';
            });
        });
    }

    // ── Restore OTP state if user refreshed during verification ──
    regRestoreOtpState();

    // ── Login modal validation + async submit ───────────────────
    var loginEmailEl  = document.getElementById('auth-email');
    var loginPwEl     = document.getElementById('auth-password');
    var loginEmailErr = document.getElementById('auth-email-error');
    var loginPwErr    = document.getElementById('auth-password-error');
    var loginForm = document.querySelector('#auth-modal-login form');
    var loginSubmitBtn = loginForm ? loginForm.querySelector('button[type="submit"]') : null;
    var loginLockoutTimer = null;
    var loginLockoutRemaining = 0;

    function setLoginFieldError(inputEl, errEl, message) {
        if (!inputEl || !errEl) return;
        errEl.textContent = message || '';
        inputEl.style.borderColor = message ? '#f87171' : '';
    }

    function formatLockoutCountdown(totalSeconds) {
        totalSeconds = Math.max(0, parseInt(totalSeconds || 0, 10));
        var minutes = Math.floor(totalSeconds / 60);
        var seconds = totalSeconds % 60;
        return String(minutes) + ':' + String(seconds).padStart(2, '0');
    }

    function lockoutMessage(totalSeconds) {
        return 'Too many login attempts. Please try again in ' + formatLockoutCountdown(totalSeconds) + '.';
    }

    function stopLoginLockoutCountdown(clearError) {
        if (loginLockoutTimer) {
            clearInterval(loginLockoutTimer);
            loginLockoutTimer = null;
        }
        loginLockoutRemaining = 0;
        if (loginSubmitBtn) setButtonLoading(loginSubmitBtn, false);
        if (loginSubmitBtn) loginSubmitBtn.disabled = false;
        if (clearError) {
            setLoginFieldError(loginPwEl, loginPwErr, '');
        }
    }

    function startLoginLockoutCountdown(totalSeconds) {
        stopLoginLockoutCountdown(false);
        loginLockoutRemaining = Math.max(1, parseInt(totalSeconds || 0, 10));
        if (loginSubmitBtn) loginSubmitBtn.disabled = true;
        setLoginFieldError(loginPwEl, loginPwErr, lockoutMessage(loginLockoutRemaining));

        loginLockoutTimer = setInterval(function() {
            loginLockoutRemaining -= 1;
            if (loginLockoutRemaining <= 0) {
                stopLoginLockoutCountdown(true);
                return;
            }
            setLoginFieldError(loginPwEl, loginPwErr, lockoutMessage(loginLockoutRemaining));
        }, 1000);
    }

    function validateLoginEmail() {
        if (!loginEmailEl) return false;
        loginEmailEl.value = loginEmailEl.value.replace(/\s/g, '').trim();
        var val = loginEmailEl.value;
        if (!val) {
            setLoginFieldError(loginEmailEl, loginEmailErr, 'Email is required.');
            return false;
        }
        // Require at least 2 characters after the last dot in domain
        if (!/^[^\s@]+@[^\s@]+\.[a-zA-Z]{2,}$/.test(val)) {
            setLoginFieldError(loginEmailEl, loginEmailErr, 'Please enter a valid email address.');
            return false;
        }
        setLoginFieldError(loginEmailEl, loginEmailErr, '');
        return true;
    }

    function validateLoginPassword() {
        if (!loginPwEl) return false;
        var val = loginPwEl.value || '';
        if (!val.trim()) {
            setLoginFieldError(loginPwEl, loginPwErr, 'Password is required.');
            return false;
        }
        setLoginFieldError(loginPwEl, loginPwErr, '');
        return true;
    }

    if (loginEmailEl) {
        loginEmailEl.addEventListener('input', function() {
            this.value = this.value.replace(/\s/g, '');
            if (loginEmailErr && loginEmailErr.textContent) validateLoginEmail();
        });
        loginEmailEl.addEventListener('blur', validateLoginEmail);
    }
    if (loginPwEl) {
        loginPwEl.addEventListener('keydown', function(e) {
            if (e.key === ' ') e.preventDefault();
        });
        loginPwEl.addEventListener('input', function() {
            this.value = this.value.replace(/\s/g, '');
            if (loginPwErr && loginPwErr.textContent) validateLoginPassword();
        });
        loginPwEl.addEventListener('blur', validateLoginPassword);
    }

    function setLoginCsrfToken(token) {
        if (!loginForm || !token) return;
        var csrfInput = loginForm.querySelector('input[name="csrf_token"]');
        if (csrfInput) csrfInput.value = token;
    }

    function refreshLoginCsrfToken() {
        return fetch(window.location.href, {
            method: 'GET',
            credentials: 'same-origin',
            headers: {
                'X-Requested-With': 'XMLHttpRequest'
            }
        })
        .then(function(response) { return response.text(); })
        .then(function(html) {
            var parser = new DOMParser();
            var doc = parser.parseFromString(html, 'text/html');
            var freshInput = doc.querySelector('#auth-modal-login input[name="csrf_token"]');
            if (!freshInput || !freshInput.value) {
                throw new Error('Unable to refresh login security token.');
            }
            setLoginCsrfToken(freshInput.value);
            return freshInput.value;
        });
    }

    function submitLoginForm(attempt) {
        return fetch(loginForm.action, {
            method: 'POST',
            body: new FormData(loginForm),
            credentials: 'same-origin',
            headers: {
                'X-Requested-With': 'XMLHttpRequest',
                'Accept': 'application/json'
            }
        })
        .then(function(response) { return response.json(); })
        .then(function(data) {
            if (data && data.code === 'csrf_mismatch') {
                if (attempt === 0 && data.csrf_token) {
                    setLoginCsrfToken(data.csrf_token);
                    return submitLoginForm(1);
                }
                if (attempt <= 1) {
                    return refreshLoginCsrfToken().then(function() {
                        return submitLoginForm(2);
                    });
                }
            }

            function resolveChatbotResumeTarget(defaultTarget) {
                try {
                    var authRaw = localStorage.getItem('pf_auth_return_target');
                    if (authRaw) {
                        var authParsed = JSON.parse(authRaw);
                        var authAgeMs = Date.now() - (parseInt(authParsed.ts || 0, 10) || 0);
                        if (authAgeMs <= 30 * 60 * 1000 && authParsed.returnUrl) {
                            var authTargetUrl = new URL(authParsed.returnUrl, window.location.origin);
                            if (authTargetUrl.origin === window.location.origin) {
                                localStorage.removeItem('pf_auth_return_target');
                                return authTargetUrl.href;
                            }
                        }
                        localStorage.removeItem('pf_auth_return_target');
                    }

                    var raw = localStorage.getItem('pf_chatbot_login_resume');
                    if (!raw) return defaultTarget;
                    var parsed = JSON.parse(raw);
                    if (!parsed || !parsed.returnUrl) return defaultTarget;

                    var ageMs = Date.now() - (parseInt(parsed.ts || 0, 10) || 0);
                    if (ageMs > 30 * 60 * 1000) {
                        localStorage.removeItem('pf_chatbot_login_resume');
                        return defaultTarget;
                    }

                    var targetUrl = new URL(parsed.returnUrl, window.location.origin);
                    if (targetUrl.origin !== window.location.origin) {
                        localStorage.removeItem('pf_chatbot_login_resume');
                        return defaultTarget;
                    }

                    return targetUrl.href;
                } catch (e) {
                    return defaultTarget;
                }
            }

            if (data && data.success && data.redirect) {
                var target = data.redirect;
                if (target.indexOf('/') === 0 && target.indexOf('//') !== 0) {
                    target = window.location.origin + target;
                }
                target = resolveChatbotResumeTarget(target);
                try {
                    localStorage.setItem('pf_auth_sync', JSON.stringify({
                        ts: Date.now(),
                        redirect: target
                    }));
                } catch (e) {}
                window.location.replace(target);
                return;
            }
            if (data && data.success && !data.redirect) {
                var fallbackTarget = resolveChatbotResumeTarget('<?php echo $base_url; ?>/');
                try {
                    localStorage.setItem('pf_auth_sync', JSON.stringify({
                        ts: Date.now(),
                        redirect: fallbackTarget
                    }));
                } catch (e) {}
                window.location.replace(fallbackTarget);
                return;
            }
            var fieldErrors = (data && data.field_errors) ? data.field_errors : {};
            setLoginFieldError(loginEmailEl, loginEmailErr, fieldErrors.email || '');
            if (data && data.lockout_remaining_seconds) {
                startLoginLockoutCountdown(data.lockout_remaining_seconds);
            } else {
                stopLoginLockoutCountdown(false);
                setLoginFieldError(loginPwEl, loginPwErr, fieldErrors.password || (data && data.message ? data.message : 'Login failed.'));
            }
        });
    }

    if (loginForm) {
        loginForm.addEventListener('submit', function(e) {
            e.preventDefault();

            if (loginLockoutRemaining > 0) {
                setLoginFieldError(loginPwEl, loginPwErr, lockoutMessage(loginLockoutRemaining));
                return;
            }

            var emailOk = validateLoginEmail();
            var pwOk = validateLoginPassword();
            if (!emailOk || !pwOk) return;

            if (loginSubmitBtn) setButtonLoading(loginSubmitBtn, true, 'Signing in...');

            submitLoginForm(0)
            .catch(function() {
                setLoginFieldError(loginPwEl, loginPwErr, 'Network error. Please try again.');
            })
            .finally(function() {
                if (loginSubmitBtn) setButtonLoading(loginSubmitBtn, false);
            });
        });
    }
})();
</script>
