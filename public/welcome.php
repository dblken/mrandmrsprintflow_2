<?php
require_once __DIR__ . '/../includes/auth.php';
SessionManager::setNoCacheHeaders();
redirect_logged_in_from_landing_page();

$page_title = 'Mr. & Mrs. Print';
$use_landing_css = true;
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/shop_config.php';

$logo_src = !empty($shop_logo_url) ? $shop_logo_url . '?v=' . rawurlencode(printflow_logo_version()) : '';
$home_url = rtrim($base_path, '/') . '/public/index.php';
?>
<style>
    .splash-page {
        min-height: 100vh;
        min-height: 100dvh;
        display: flex;
        align-items: center;
        justify-content: center;
        background: var(--lp-bg);
        color: var(--lp-text);
        position: relative;
        overflow: hidden;
        padding: 1.5rem;
    }
    .splash-page::before {
        content: '';
        position: absolute;
        inset: 0;
        background-image:
            linear-gradient(rgba(255, 255, 255, .03) 1px, transparent 1px),
            linear-gradient(90deg, rgba(255, 255, 255, .03) 1px, transparent 1px);
        background-size: 60px 60px;
        pointer-events: none;
    }
    .splash-page::after {
        content: '';
        position: absolute;
        top: 14%;
        left: 50%;
        translate: -50% 0;
        width: min(620px, 90vw);
        height: 440px;
        background: radial-gradient(ellipse, rgba(83, 197, 224, .3) 0%, transparent 70%);
        pointer-events: none;
    }
    .splash-shell {
        width: min(100%, 560px);
        position: relative;
        z-index: 1;
        text-align: center;
        animation: splashIn .55s ease-out both;
    }
    .splash-logo-wrap {
        width: 104px;
        height: 104px;
        margin: 0 auto 1.45rem;
        border-radius: 9999px;
        display: flex;
        align-items: center;
        justify-content: center;
        background: rgba(83, 197, 224, .12);
        border: 1px solid rgba(83, 197, 224, .28);
        box-shadow: 0 0 34px rgba(83, 197, 224, .22);
        overflow: hidden;
    }
    .splash-logo {
        width: 100%;
        height: 100%;
        object-fit: cover;
        display: block;
    }
    .splash-logo-fallback {
        color: var(--lp-accent-l);
        font-size: 2.2rem;
        font-weight: 800;
        line-height: 1;
    }
    .splash-title {
        color: var(--lp-heading);
        font-size: clamp(2.1rem, 7vw, 3.8rem);
        line-height: 1.05;
        font-weight: 800;
        margin: 0;
    }
    .splash-subtitle {
        color: var(--lp-accent-l);
        font-size: clamp(.98rem, 2.5vw, 1.25rem);
        font-weight: 700;
        margin: .65rem 0 0;
    }
    .splash-loader {
        width: min(220px, 62vw);
        height: 3px;
        margin: 2rem auto 0;
        border-radius: 9999px;
        background: rgba(255, 255, 255, .09);
        overflow: hidden;
    }
    .splash-loader span {
        display: block;
        width: 42%;
        height: 100%;
        border-radius: inherit;
        background: var(--lp-accent-l);
        box-shadow: 0 0 18px rgba(83, 197, 224, .5);
        animation: splashLoad 1.15s ease-in-out infinite;
    }
    .splash-note {
        color: var(--lp-muted);
        font-size: .86rem;
        margin: .9rem 0 0;
    }
    @keyframes splashIn {
        from { opacity: 0; transform: translateY(14px) scale(.98); }
        to { opacity: 1; transform: translateY(0) scale(1); }
    }
    @keyframes splashLoad {
        0% { transform: translateX(-105%); }
        100% { transform: translateX(260%); }
    }
    @media (max-width: 640px) {
        .splash-logo-wrap {
            width: 86px;
            height: 86px;
            margin-bottom: 1.2rem;
        }
        .splash-loader {
            margin-top: 1.6rem;
        }
    }
</style>

<section class="splash-page" aria-labelledby="splash-title">
    <div class="splash-shell">
        <div class="splash-logo-wrap" aria-hidden="true">
            <?php if ($logo_src !== ''): ?>
                <img class="splash-logo" src="<?php echo htmlspecialchars($logo_src); ?>" alt="">
            <?php else: ?>
                <span class="splash-logo-fallback">M</span>
            <?php endif; ?>
        </div>

        <h1 class="splash-title" id="splash-title">Mr. &amp; Mrs. Print</h1>
        <p class="splash-subtitle">Printing Management System</p>
        <div class="splash-loader" aria-hidden="true"><span></span></div>
        <p class="splash-note">Loading home page...</p>
    </div>
</section>

<script>
    window.addEventListener('load', function () {
        window.setTimeout(function () {
            window.location.replace(<?php echo json_encode($home_url); ?>);
        }, 1600);
    });
</script>
<?php require_once __DIR__ . '/../includes/success_modal.php'; ?>
<script src="<?php echo htmlspecialchars($base_url); ?>/public/assets/js/pwa.js?v=<?php echo $ver; ?>"></script>
</main>
</body>
</html>