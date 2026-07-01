<?php
require_once __DIR__ . '/../includes/auth.php';
SessionManager::setNoCacheHeaders();
redirect_logged_in_from_landing_page();

$page_title = 'Welcome - Mr. & Mrs. Print';
$use_landing_css = true;
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/shop_config.php';

$browse_url = rtrim($base_path, '/') . '/public/index.php';
$login_url = rtrim($base_path, '/') . '/?auth_modal=login';
$logo_src = !empty($shop_logo_url) ? $shop_logo_url . '?v=' . rawurlencode(printflow_logo_version()) : '';
?>
<style>
    .welcome-page {
        min-height: 100vh;
        min-height: 100dvh;
        display: flex;
        flex-direction: column;
        justify-content: space-between;
        background: var(--lp-bg);
        color: var(--lp-text);
        position: relative;
        overflow: hidden;
        padding: 2rem 1rem 1.25rem;
    }
    .welcome-page::before {
        content: '';
        position: absolute;
        inset: 0;
        background-image:
            linear-gradient(rgba(255, 255, 255, .03) 1px, transparent 1px),
            linear-gradient(90deg, rgba(255, 255, 255, .03) 1px, transparent 1px);
        background-size: 60px 60px;
        pointer-events: none;
    }
    .welcome-page::after {
        content: '';
        position: absolute;
        top: 8%;
        left: 50%;
        translate: -50% 0;
        width: min(720px, 92vw);
        height: 520px;
        background: radial-gradient(ellipse, var(--lp-glow) 0%, transparent 70%);
        pointer-events: none;
    }
    .welcome-shell {
        width: min(100%, 760px);
        margin: auto;
        position: relative;
        z-index: 1;
        text-align: center;
        animation: welcomeFadeUp .55s ease-out both;
    }
    .welcome-logo-wrap {
        width: 96px;
        height: 96px;
        margin: 0 auto 1.4rem;
        border-radius: 9999px;
        display: flex;
        align-items: center;
        justify-content: center;
        background: rgba(83, 197, 224, .12);
        border: 1px solid rgba(83, 197, 224, .28);
        box-shadow: 0 0 30px rgba(83, 197, 224, .18);
        overflow: hidden;
    }
    .welcome-logo {
        width: 100%;
        height: 100%;
        object-fit: cover;
        display: block;
    }
    .welcome-logo-fallback {
        color: var(--lp-accent-l);
        font-size: 2.1rem;
        font-weight: 800;
        line-height: 1;
    }
    .welcome-title {
        color: var(--lp-heading);
        font-size: clamp(2.35rem, 6vw, 4rem);
        line-height: 1.04;
        font-weight: 800;
        margin: 0;
    }
    .welcome-subtitle {
        color: var(--lp-accent-l);
        font-size: clamp(1rem, 2.4vw, 1.35rem);
        font-weight: 700;
        margin: .65rem 0 0;
    }
    .welcome-message {
        color: var(--lp-muted);
        font-size: 1rem;
        line-height: 1.65;
        margin: 1.1rem auto 0;
        max-width: 520px;
    }
    .welcome-actions {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 1rem;
        margin: 2.25rem auto 0;
        max-width: 600px;
    }
    .welcome-action {
        min-height: 132px;
        padding: 1.35rem 1.1rem;
        border-radius: 1rem;
        display: flex;
        flex-direction: column;
        align-items: center;
        justify-content: center;
        gap: .7rem;
        font-weight: 700;
        text-decoration: none;
        transition: transform .25s ease, box-shadow .25s ease, border-color .25s ease, background .25s ease;
    }
    .welcome-action svg {
        width: 2.7rem;
        height: 2.7rem;
        border-radius: 9999px;
        display: inline-flex;
        padding: .72rem;
        box-sizing: border-box;
        stroke-width: 1.9;
    }
    .welcome-action-primary {
        background: var(--lp-accent);
        color: #fff;
        box-shadow: 0 0 18px var(--lp-glow);
    }
    .welcome-action-primary svg {
        background: rgba(255,255,255,.14);
        color: #fff;
    }
    .welcome-action-primary:hover {
        background: #2a82a3;
        box-shadow: 0 0 30px rgba(83, 197, 224, .5);
        transform: translateY(-2px);
    }
    .welcome-action-secondary {
        background: rgba(255,255,255,.04);
        color: var(--lp-text);
        border: 1px solid rgba(255,255,255,.15);
    }
    .welcome-action-secondary svg {
        background: rgba(83, 197, 224, .12);
        color: var(--lp-accent-l);
    }
    .welcome-action-secondary:hover {
        background: rgba(255,255,255,.06);
        border-color: rgba(255,255,255,.3);
        color: #fff;
        transform: translateY(-2px);
    }
    .welcome-supporting {
        max-width: 620px;
        margin: 1.4rem auto 0;
        color: var(--lp-muted);
        font-size: .92rem;
        line-height: 1.65;
    }
    .welcome-footer {
        position: relative;
        z-index: 1;
        display: flex;
        flex-wrap: wrap;
        align-items: center;
        justify-content: center;
        gap: .5rem 1rem;
        color: rgba(224, 242, 254, .58);
        font-size: .78rem;
        padding-top: 1.5rem;
        animation: welcomeFadeUp .55s .12s ease-out both;
    }
    .welcome-footer span {
        white-space: nowrap;
    }
    @keyframes welcomeFadeUp {
        from { opacity: 0; transform: translateY(18px); }
        to { opacity: 1; transform: translateY(0); }
    }
    @media (max-width: 640px) {
        .welcome-page {
            padding: 1.4rem 1rem 1rem;
        }
        .welcome-logo-wrap {
            width: 82px;
            height: 82px;
            margin-bottom: 1.15rem;
        }
        .welcome-actions {
            grid-template-columns: 1fr;
            margin-top: 1.75rem;
        }
        .welcome-action {
            min-height: 108px;
        }
    }
</style>

<section class="welcome-page" aria-labelledby="welcome-title">
    <div class="welcome-shell">
        <div class="welcome-logo-wrap" aria-hidden="true">
            <?php if ($logo_src !== ''): ?>
                <img class="welcome-logo" src="<?php echo htmlspecialchars($logo_src); ?>" alt="">
            <?php else: ?>
                <span class="welcome-logo-fallback">M</span>
            <?php endif; ?>
        </div>

        <h1 class="welcome-title" id="welcome-title">Mr. &amp; Mrs. Print</h1>
        <p class="welcome-subtitle">Printing Management System</p>
        <p class="welcome-message">Welcome! Please choose how you'd like to continue.</p>

        <div class="welcome-actions" aria-label="Continue options">
            <a href="<?php echo htmlspecialchars($login_url); ?>" data-auth-modal="login" class="welcome-action welcome-action-primary">
                <svg aria-hidden="true" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 9V5.25A2.25 2.25 0 0013.5 3h-6A2.25 2.25 0 005.25 5.25v13.5A2.25 2.25 0 007.5 21h6a2.25 2.25 0 002.25-2.25V15" />
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 9l3 3m0 0l-3 3m3-3H3" />
                </svg>
                <span>Login</span>
            </a>
            <a href="<?php echo htmlspecialchars($browse_url); ?>" class="welcome-action welcome-action-secondary">
                <svg aria-hidden="true" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 21a9 9 0 100-18 9 9 0 000 18z" />
                    <path stroke-linecap="round" stroke-linejoin="round" d="M3.6 9h16.8M3.6 15h16.8M12 3c2.1 2.25 3.15 5.25 3.15 9S14.1 18.75 12 21M12 3C9.9 5.25 8.85 8.25 8.85 12S9.9 18.75 12 21" />
                </svg>
                <span>Browse Website</span>
            </a>
        </div>

        <p class="welcome-supporting">Customers can browse our services without signing in. Employees and administrators should log in to access the management system.</p>
    </div>

    <footer class="welcome-footer">
        <span>Version 1.0</span>
        <span>&copy; 2026 Mr. &amp; Mrs. Print</span>
        <span>Developed by the Capstone Team</span>
    </footer>
</section>

<?php if (!$is_logged_in): ?>
<?php
require_once __DIR__ . '/../includes/google-oauth-config.php';
$google_client_id = defined('GOOGLE_CLIENT_ID') && GOOGLE_CLIENT_ID !== '' ? GOOGLE_CLIENT_ID : null;
require_once __DIR__ . '/../includes/auth-modals.php';
?>
<?php endif; ?>
<?php require_once __DIR__ . '/../includes/success_modal.php'; ?>
<script src="<?php echo htmlspecialchars($base_url); ?>/public/assets/js/pwa.js?v=<?php echo $ver; ?>"></script>
</main>
</body>
</html>