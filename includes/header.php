<?php
/**
 * Header Component
 * PrintFlow - Printing Shop PWA
 */

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/functions.php';

$current_script_path = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '');
$current_script_dir = basename(trim(dirname($current_script_path), '/'));
if ($current_script_dir === 'public' && function_exists('redirect_logged_in_from_public_page')) {
    redirect_logged_in_from_public_page();
}

$current_user = get_logged_in_user();
$user_type = get_user_type();
$is_logged_in = is_logged_in();
$unread_count = $is_logged_in ? get_unread_notification_count(get_user_id(), $user_type) : 0;

require_once __DIR__ . '/shop_config.php';

// Load config for environment detection
if (file_exists(__DIR__ . '/../config.php')) {
    require_once __DIR__ . '/../config.php';
} else {
    // Fallback if config.php doesn't exist
    $is_prod = (isset($_SERVER['HTTP_HOST']) && strpos($_SERVER['HTTP_HOST'], 'mrandmrsprintflow.com') !== false);
    define('BASE_PATH', $is_prod ? '' : '/printflow');
    define('BASE_URL', $is_prod ? '' : '/printflow');
}

// Determine base URL and asset path
$base_url = defined('BASE_URL') ? BASE_URL : (defined('BASE_PATH') ? BASE_PATH : '');
$base_path = defined('BASE_PATH') ? BASE_PATH : (defined('BASE_URL') ? BASE_URL : '');
$asset_base = rtrim($base_url, '/') . '/public';
$pf_customer_chat_unread = 0;
if ($is_logged_in && $user_type === 'Customer') {
    require_once __DIR__ . '/chat_http.php';
    $pf_customer_chat_unread = printflow_chat_unread_count((int)get_user_id(), 'Customer');
}

// Stable asset versioning so pages can cache between navigations
$ver_seed = __DIR__ . '/../public/assets/css/output.css';
$ver = is_file($ver_seed) ? (string) filemtime($ver_seed) : '1';
$url_index    = $base_url . '/';
$url_products = $base_url . '/public/products.php';

$url_login    = $base_url . '/?auth_modal=login';
$url_register = $base_url . '/?auth_modal=register';
$url_logout   = $base_url . '/logout';
$url_forgot_password = $base_url . '/public/forgot-password.php';
$url_reset_password  = $base_url . '/public/reset-password.php';
$url_google_auth    = $base_url . '/public/google-auth.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="PrintFlow - Your trusted printing shop for tarpaulins, t-shirts, stickers, and more">
    <meta name="theme-color" content="#4F46E5">
    <title><?php echo $page_title ?? 'PrintFlow - Printing Shop'; ?></title>
    <?php include __DIR__ . '/favicon_links.php'; ?>
    <?php if (strpos($_SERVER['REQUEST_URI'] ?? '', '/staff/') !== false): ?>
    <script>(function(){document.documentElement.classList.add('printflow-staff');})();</script>
    <?php include __DIR__ . '/staff_theme.php'; ?>
    <?php endif; ?>

    <!-- Turbo: disable page cache to prevent double-encoded HTML on restore -->
    <meta name="turbo-cache-control" content="no-cache">
    <!-- PWA Manifest -->
    <link rel="manifest" href="<?php echo $asset_base; ?>/manifest.php?v=<?php echo rawurlencode(printflow_logo_version()); ?>">
    <!-- Tailwind CSS -->
    <link rel="stylesheet" href="<?php echo $asset_base; ?>/assets/css/output.css?v=<?php echo $ver; ?>">
    <?php if (!empty($use_landing_css)): ?>
    <link rel="stylesheet" href="<?php echo $asset_base; ?>/assets/css/landing.css?v=<?php echo $ver; ?>">
    <?php endif; ?>
    <?php if (!empty($use_customer_css)): ?>
    <link rel="stylesheet" href="<?php echo $asset_base; ?>/assets/css/customer-theme.css?v=<?php echo $ver; ?>">
    <?php endif; ?>

    <!-- Customer Burger Menu (Landing & Customer Pages) -->
    <script src="<?php echo $asset_base; ?>/assets/js/customer-burger.js" defer></script>
    <?php if ($is_logged_in && $user_type === 'Customer'): ?>
    <?php $chat_badge_ver = @filemtime(__DIR__ . '/../public/assets/js/chat_unread_badges.js') ?: '1'; ?>
    <script src="<?php echo $asset_base; ?>/assets/js/chat_unread_badges.js?v=<?php echo $chat_badge_ver; ?>" data-base-url="<?php echo htmlspecialchars($base_url, ENT_QUOTES); ?>" data-initial-count="<?php echo (int)($pf_customer_chat_unread ?? 0); ?>" defer></script>
    <?php endif; ?>
    
    <!-- Critical: base link/layout so page is never unstyled -->
    <style>
        a { color: inherit; text-decoration: none; }
        a:hover { text-decoration: none; }
        body { margin: 0; background: #f9fafb; color: #111827; font-family: Inter, system-ui, sans-serif; }
        /* Internal pages: landing-like transparent header by default */
        body:not(.lp-page) #main-header { box-shadow: none !important; position: sticky; top: 0; z-index: 50; border-bottom: 1px solid rgba(255,255,255,0.10); }
        
        /* Prevent content from scrolling under sticky header */
        body:not(.lp-page) #main-content { padding-top: 0; }
        body.customer-theme #main-content { scroll-margin-top: 80px; }
        
        /* Transparent hero nav for landing page only */
        body.lp-page #main-header.lp-hero-nav:not(.sticky-active) { background: transparent !important; border-bottom-color: rgba(255,255,255,0.1) !important; box-shadow: none !important; }
        body.lp-page #main-header.sticky-active { background: #0a2530 !important; box-shadow: 0 4px 20px rgba(0,0,0,0.3); border-bottom: 1px solid rgba(83,197,224,0.1); }
        body:not(.lp-page) #main-header.sticky-active { background: rgba(10,37,48,0.92) !important; box-shadow: 0 4px 20px rgba(0,0,0,0.28) !important; border-bottom: 1px solid rgba(83,197,224,0.16) !important; backdrop-filter: blur(6px); }
        
        body #main-header nav > div { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 1rem; }
        body #main-header nav > div > div:last-child { display: flex; align-items: center; gap: 1rem; }
        body #main-header a { color: rgba(255,255,255,0.8); font-weight: 500; }
        body #main-header a:hover { color: #53C5E0; }
        body #main-header a.nav-link { color: rgba(255,255,255,0.8); }
        body #main-header a.nav-link:hover { color: #53C5E0; }
        /* Unified header text style (except brand/site name) */
        body #main-header a.nav-link,
        body #main-header a[data-auth-modal="login"],
        body #main-header .btn-gradient-primary,
        body #main-header #pwa-install-btn {
            font-size: 0.88rem !important;
            text-transform: uppercase;
            letter-spacing: 0.04em;
        }
        body #main-header .text-2xl.font-bold { color: #53C5E0; }
        body #main-header .btn-gradient-primary { background: var(--lp-accent, #32a1c4) !important; color: #fff !important; padding: 0.5rem 1.25rem; border-radius: 0.5rem; font-weight: 500; }
        body #main-header .pf-auth-cta {
            width: 148px;
            height: 36px;
            display: inline-flex !important;
            align-items: center;
            justify-content: center;
            box-sizing: border-box;
            padding: 0 0.8rem !important;
            border-radius: 0.5rem;
            font-family: inherit;
            font-size: 0.82rem !important;
            font-weight: 500;
            line-height: 1;
            text-transform: uppercase;
            letter-spacing: 0.04em;
            white-space: nowrap;
        }
        body #main-header .pf-register-cta {
            width: 128px;
        }
        body #main-header #pwa-install-btn {
            font-family: inherit;
            font-size: 0.88rem !important;
            font-weight: 500;
            line-height: 1;
            padding: 0 1rem !important;
            border-radius: 0.5rem;
            border: 1px solid rgba(255,255,255,.12);
            background: linear-gradient(135deg,#22c55e,#16a34a) !important;
            color: #fff !important;
            cursor: pointer;
            transition: box-shadow .25s, background .25s, transform .2s;
        }
        body #main-header #pwa-install-btn:hover {
            background: linear-gradient(135deg,#16a34a,#15803d) !important;
            box-shadow: 0 0 20px rgba(34,197,94,.35);
            transform: translateY(-1px);
        }
        /* Active nav link — mirrors hover state (non-hero pages) */
        a.nav-link.nav-active { color: #53C5E0 !important; }
        a.nav-link.nav-active > span:last-child { width: 100% !important; }
        /* Dark hero nav: force white text overriding Tailwind text-gray-700 */
        body.lp-page #main-header.lp-hero-nav a,
        body.lp-page #main-header.lp-hero-nav a.nav-link { color: rgba(255,255,255,0.85) !important; }
        body.lp-page #main-header.lp-hero-nav a.nav-link:hover { color: #53C5E0 !important; }
        body.lp-page #main-header.lp-hero-nav a.nav-link.nav-active { color: #53C5E0 !important; }
        body.lp-page #main-header.lp-hero-nav a.nav-link.nav-active > span:last-child { width: 100% !important; }
        .pwa-install-btn { display: inline-flex; align-items: center; gap: 0.5rem; padding: 0.5rem 1rem; font-size: 0.875rem; font-weight: 500; color: rgba(255,255,255,0.8); background: transparent; border: 1px solid rgba(255,255,255,0.2); border-radius: 0.5rem; cursor: pointer; transition: all 0.2s; }
        .pwa-install-btn:hover { color: #53C5E0; border-color: #53C5E0; background: rgba(83,197,224,0.05); }
        .pwa-install-btn.hidden { display: none !important; }
        .modal,
        .modal-overlay,
        .modal-backdrop,
        .modal-content,
        .modal-panel,
        .modal-box,
        .auth-modal,
        .cart-info-modal-card,
        [role="dialog"] {
            min-width: 0;
        }
        .modal *:not(svg):not(path),
        .modal-overlay *:not(svg):not(path),
        .modal-backdrop *:not(svg):not(path),
        .modal-content *:not(svg):not(path),
        .modal-panel *:not(svg):not(path),
        .modal-box *:not(svg):not(path),
        .auth-modal *:not(svg):not(path),
        .cart-info-modal-card *:not(svg):not(path),
        [role="dialog"] *:not(svg):not(path) {
            min-width: 0;
            overflow-wrap: anywhere;
            word-break: break-word;
        }
        .modal-content [style*="white-space:nowrap"],
        .modal-panel [style*="white-space:nowrap"],
        .modal-box [style*="white-space:nowrap"],
        [role="dialog"] [style*="white-space:nowrap"] {
            white-space: normal !important;
        }
        /* Landing-page nav needs flex layout too */
        #main-header nav > div { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 1rem; }
        #main-header nav > div > div:last-child { display: flex; align-items: center; gap: 1rem; }
        /* Suppress browser-native :invalid styling globally — validation is JS-driven */
        input:invalid, select:invalid, textarea:invalid { box-shadow: none !important; outline-color: initial !important; }
    </style>
    
</head>
<body class="bg-gray-50<?php echo !empty($use_landing_css) ? ' lp-page' : ''; ?><?php echo !empty($use_customer_css) ? ' customer-theme' : ''; ?><?php echo !empty($is_chat_page) ? ' chat-page' : ''; ?>" data-user-type="<?php echo htmlspecialchars(get_user_type() ?? 'Guest'); ?>">
    <!-- Skip to main content (accessibility) - hidden until focused -->
    <a href="#main-content" style="position:absolute;left:-9999px;z-index:9999;padding:0.5rem 1rem;background:#4F46E5;color:#fff;font-weight:500;" id="skip-link">Skip to main content</a>
    <script>document.getElementById('skip-link').addEventListener('focus',function(){ this.style.left='0'; }); document.getElementById('skip-link').addEventListener('blur',function(){ this.style.left='-9999px'; });</script>

    <?php if (empty($use_landing_css)): ?>
    <?php 
    // Standard dark background for non-landing pages (customer, staff, admin)
    $nav_header_class = 'bg-[#0a2530] backdrop-blur-md shadow-lg sticky top-0 z-50 border-b border-white/5'; 
    require __DIR__ . '/nav-header.php'; 
    ?>
    <?php endif; ?>

    <!-- Main Content -->
    <main id="main-content" class="min-h-screen">
    
    <script>
    // Handle sticky header background transition on scroll (idempotent for Turbo)
    if (!window.__pfHeaderScrollInit) {
        window.__pfHeaderScrollInit = true;
        window.addEventListener('scroll', function() {
            const header = document.getElementById('main-header');
            if (!header) return;
            if (window.scrollY > 50) {
                header.classList.add('sticky-active');
            } else {
                header.classList.remove('sticky-active');
            }
        });
    }

    // Keep public tabs in sync with the real authenticated session state.
    if (!window.__pfAuthSyncInit) {
        window.__pfAuthSyncInit = true;

        (function() {
            var authUrl = <?php echo json_encode($base_url . '/public/api_session_status.php'); ?>;
            var loginUrl = <?php echo json_encode($base_url . '/?auth_modal=login'); ?>;
            var homePaths = new Set([
                '/',
                '/public/index.php',
                <?php echo json_encode(((string)$base_url !== '' ? rtrim((string)$base_url, '/') : '') ?: '/'); ?>,
                <?php echo json_encode((((string)$base_url !== '' ? rtrim((string)$base_url, '/') : '') ?: '') . '/public/index.php'); ?>
            ]);
            var protectedPrefixes = [
                <?php echo json_encode((((string)$base_url !== '' ? rtrim((string)$base_url, '/') : '')) . '/admin/'); ?>,
                <?php echo json_encode((((string)$base_url !== '' ? rtrim((string)$base_url, '/') : '')) . '/manager/'); ?>,
                <?php echo json_encode((((string)$base_url !== '' ? rtrim((string)$base_url, '/') : '')) . '/staff/'); ?>,
                <?php echo json_encode((((string)$base_url !== '' ? rtrim((string)$base_url, '/') : '')) . '/customer/'); ?>
            ];

            function getBasePath() {
                return window.PF_BASE_PATH || '';
            }

            function normalizePath(path) {
                var out = (path || '/').replace(/\/+$/, '');
                return out || '/';
            }

            function shouldRedirectCurrentPage() {
                return homePaths.has(normalizePath(window.location.pathname));
            }

            function getNotifUrl(type, dataId, message, notifId, orderType) {
                var base = getBasePath();
                // ...
            }

            function isProtectedCurrentPage() {
                var current = normalizePath(window.location.pathname) + '/';
                return protectedPrefixes.some(function(prefix) {
                    return prefix && current.indexOf(prefix) === 0;
                });
            }

            var checkInFlight = false;
            function syncSessionState() {
                if (checkInFlight) {
                    return;
                }
                checkInFlight = true;
                fetch(authUrl + '?_=' + Date.now(), {
                    credentials: 'same-origin',
                    cache: 'no-store',
                    headers: {
                        'Accept': 'application/json',
                        'X-Requested-With': 'XMLHttpRequest'
                    }
                })
                .then(function(response) {
                    return response.ok ? response.json() : null;
                })
                .then(function(data) {
                    if (!data) return;
                    if (data.logged_in && data.redirect && shouldRedirectCurrentPage()) {
                        window.location.replace(data.redirect);
                        return;
                    }
                    if (!data.logged_in && isProtectedCurrentPage()) {
                        window.location.replace(loginUrl + (loginUrl.indexOf('?') > -1 ? '&' : '?') + 'expired=1');
                    }
                })
                .catch(function() {})
                .finally(function() {
                    checkInFlight = false;
                });
            }

            window.addEventListener('storage', function(event) {
                if (event.key === 'pf_auth_sync') {
                    syncSessionState();
                }
            });

            document.addEventListener('click', function(event) {
                var link = event.target.closest('a[href]');
                if (!link) return;
                try {
                    var href = new URL(link.href, window.location.origin);
                    if (/\/(logout|signout)\/?$/.test(href.pathname)) {
                        localStorage.setItem('pf_auth_sync', JSON.stringify({
                            ts: Date.now(),
                            logout: true
                        }));
                    }
                } catch (e) {}
            });

            window.addEventListener('load', syncSessionState);
            window.addEventListener('pageshow', syncSessionState);
            window.addEventListener('focus', syncSessionState);
            document.addEventListener('visibilitychange', function() {
                if (document.visibilityState === 'visible') {
                    syncSessionState();
                }
            });

            syncSessionState();
        })();
    }
    </script>
