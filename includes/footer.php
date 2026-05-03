<?php
// Public pages set this in header.php; many admin/manager pages use a custom head and omit it. Treat as logged out
// (wrong) and the footer will embed customer auth modals and overwrite window.openModal — breaking admin UIs
// (e.g. customers_management.php customer profile modal). Resolve from session when unset.
if (!function_exists('is_logged_in') && file_exists(__DIR__ . '/auth.php')) {
    require_once __DIR__ . '/auth.php';
}
require_once __DIR__ . '/runtime_config.php';
if (!isset($is_logged_in) && function_exists('is_logged_in')) {
    $is_logged_in = is_logged_in();
} elseif (!isset($is_logged_in)) {
    $is_logged_in = false;
}

$_ft_request_path = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?: '';
$_ft_current_script_name = strtolower(basename($_ft_request_path));
$_ft_current_script_dir = strtolower(basename(trim(str_replace('\\', '/', dirname($_ft_request_path)), '/')));
if ($_ft_current_script_name === '' || substr($_ft_current_script_name, -4) !== '.php') {
    $_ft_current_script_name = strtolower(basename($_SERVER['SCRIPT_NAME'] ?? 'index.php'));
}
if ($_ft_current_script_dir === '.' || $_ft_current_script_dir === '/' || $_ft_current_script_dir === '\\') {
    $_ft_current_script_dir = '';
}

$_ft_is_logged_in_customer = $is_logged_in && function_exists('get_user_type') && get_user_type() === 'Customer';
$_ft_customer_footer_pages = ['services.php', 'products.php'];
$_ft_show_footer = !$_ft_is_logged_in_customer
    || (
        in_array($_ft_current_script_name, $_ft_customer_footer_pages, true)
        && in_array($_ft_current_script_dir, ['public', 'customer'], true)
    );

// Load configs for the footer
$_ft_shop_path   = __DIR__ . '/../public/assets/uploads/shop_config.json';
$_ft_footer_path = __DIR__ . '/../public/assets/uploads/footer_config.json';
$_ft_shop   = printflow_load_runtime_config('shop', $_ft_shop_path);
$_ft_footer = printflow_load_runtime_config('footer', $_ft_footer_path);

$_ft_name            = !empty($_ft_shop['name'])               ? htmlspecialchars($_ft_shop['name'])          : 'PrintFlow';
$_ft_tagline         = !empty($_ft_footer['tagline'])          ? $_ft_footer['tagline']                       : 'Your trusted printing partner.';
$_ft_email           = !empty($_ft_footer['email'])            ? $_ft_footer['email']    : (!empty($_ft_shop['email'])  ? $_ft_shop['email']  : '');
$_ft_phone           = !empty($_ft_footer['phone'])            ? $_ft_footer['phone']    : (!empty($_ft_shop['phone'])  ? $_ft_shop['phone']  : '');
$_ft_hours           = !empty($_ft_footer['hours'])            ? $_ft_footer['hours']    : '';
$_ft_services        = !empty($_ft_footer['services'])         ? $_ft_footer['services'] : [];
$_ft_socials         = !empty($_ft_footer['social_links'])     ? $_ft_footer['social_links'] : [];
$_ft_branch_addrs    = !empty($_ft_footer['branch_addresses']) ? $_ft_footer['branch_addresses'] : [];

/**
 * Detect social platform name + SVG icon path from a URL.
 * Returns ['label'=>'Facebook', 'icon'=>'<path...>'] or null.
 */
function _ft_detect_social(string $url): array {
    $icons = [
        'facebook'  => ['Facebook',  '<path d="M24 12.073c0-6.627-5.373-12-12-12s-12 5.373-12 12c0 5.99 4.388 10.954 10.125 11.854v-8.385H7.078v-3.47h3.047V9.43c0-3.007 1.792-4.669 4.533-4.669 1.312 0 2.686.235 2.686.235v2.953H15.83c-1.491 0-1.956.925-1.956 1.874v2.25h3.328l-.532 3.47h-2.796v8.385C19.612 23.027 24 18.062 24 12.073z"/>'],
        'instagram' => ['Instagram', '<path d="M12 2.163c3.204 0 3.584.012 4.85.07 3.252.268 4.771 1.691 5.077 4.907.06 1.281.076 1.665.076 4.849 0 3.185-.015 3.569-.074 4.814-.306 3.218-1.825 4.634-5.066 4.921-1.277.058-1.649.07-4.859.07-3.211 0-3.586-.012-4.859-.074-3.302-.287-4.771-1.697-5.077-4.907-.06-1.281-.076-1.665-.076-4.849 0-3.185.015-3.569.074-4.814.306-3.218 1.825-4.634 5.066-4.921 1.277-.058 1.649-.07 4.859-.07zM12 0C8.741 0 8.333.014 7.053.072 2.695.272.273 2.69.073 7.052.014 8.333 0 8.741 0 12c0 3.259.014 3.668.072 4.948.2 4.358 2.618 6.78 6.98 6.98C8.333 23.986 8.741 24 12 24c3.259 0 3.668-.014 4.948-.072 4.354-.2 6.782-2.618 6.979-6.98.059-1.28.073-1.689.073-4.948 0-3.259-.014-3.667-.072-4.947-.196-4.354-2.617-6.78-6.979-6.98C15.668.014 15.259 0 12 0zm0 5.838a6.162 6.162 0 100 12.324 6.162 6.162 0 000-12.324zM12 16a4 4 0 110-8 4 4 0 010 8zm6.406-11.845a1.44 1.44 0 100 2.881 1.44 1.44 0 000-2.881z"/>'],
        'twitter'   => ['Twitter',   '<path d="M23.953 4.57a10 10 0 01-2.825.775 4.958 4.958 0 002.163-2.723c-.951.555-2.005.959-3.127 1.184a4.92 4.92 0 00-8.384 4.482C7.69 8.095 4.067 6.13 1.64 3.162a4.822 4.822 0 00-.666 2.475c0 1.71.87 3.213 2.188 4.096a4.904 4.904 0 01-2.228-.616v.06a4.923 4.923 0 003.946 4.827 4.996 4.996 0 01-2.212.085 4.936 4.936 0 004.604 3.417 9.867 9.867 0 01-6.102 2.105c-.39 0-.779-.023-1.17-.067a13.995 13.995 0 007.557 2.209c9.053 0 13.998-7.496 13.998-13.985 0-.21 0-.42-.015-.63A9.935 9.935 0 0024 4.59z"/>'],
        'x.com'     => ['X',         '<path d="M18.244 2.25h3.308l-7.227 8.26 8.502 11.24H16.17l-4.714-6.231-5.401 6.231H2.744l7.736-8.835L1.254 2.25H8.08l4.253 5.622L18.244 2.25zm-1.161 17.52h1.833L7.084 4.126H5.117z"/>'],
        'youtube'   => ['YouTube',   '<path d="M23.495 6.205a3.007 3.007 0 00-2.088-2.088c-1.87-.501-9.396-.501-9.396-.501s-7.507-.01-9.396.501A3.007 3.007 0 00.527 6.205a31.247 31.247 0 00-.522 5.805 31.247 31.247 0 00.522 5.783 3.007 3.007 0 002.088 2.088c1.868.502 9.396.502 9.396.502s7.506 0 9.396-.502a3.007 3.007 0 002.088-2.088 31.247 31.247 0 00.5-5.783 31.247 31.247 0 00-.5-5.805zM9.609 15.601V8.408l6.264 3.602z"/>'],
        'tiktok'    => ['TikTok',    '<path d="M19.59 6.69a4.83 4.83 0 01-3.77-4.25V2h-3.45v13.67a2.89 2.89 0 01-2.88 2.5 2.89 2.89 0 01-2.89-2.89 2.89 2.89 0 012.89-2.89c.28 0 .54.04.79.1V9.01a6.34 6.34 0 00-.79-.05 6.34 6.34 0 00-6.34 6.34 6.34 6.34 0 006.34 6.34 6.34 6.34 0 006.33-6.34V8.87a8.18 8.18 0 004.78 1.52V7a4.85 4.85 0 01-1.01-.31z"/>'],
        'linkedin'  => ['LinkedIn',  '<path d="M20.447 20.452h-3.554v-5.569c0-1.328-.027-3.037-1.852-3.037-1.853 0-2.136 1.445-2.136 2.939v5.667H9.351V9h3.414v1.561h.046c.477-.9 1.637-1.85 3.37-1.85 3.601 0 4.267 2.37 4.267 5.455v6.286zM5.337 7.433a2.062 2.062 0 01-2.063-2.065 2.064 2.064 0 112.063 2.065zm1.782 13.019H3.555V9h3.564v11.452zM22.225 0H1.771C.792 0 0 .774 0 1.729v20.542C0 23.227.792 24 1.771 24h20.451C23.2 24 24 23.227 24 22.271V1.729C24 .774 23.2 0 22.222 0h.003z"/>'],
        'pinterest' => ['Pinterest', '<path d="M12 0C5.373 0 0 5.373 0 12c0 5.084 3.163 9.426 7.627 11.174-.105-.949-.2-2.405.042-3.441.218-.937 1.407-5.965 1.407-5.965s-.359-.719-.359-1.782c0-1.668.967-2.914 2.171-2.914 1.023 0 1.518.769 1.518 1.69 0 1.029-.655 2.568-.994 3.995-.283 1.194.599 2.169 1.777 2.169 2.133 0 3.772-2.249 3.772-5.495 0-2.873-2.064-4.882-5.012-4.882-3.414 0-5.418 2.561-5.418 5.207 0 1.031.397 2.138.893 2.738a.36.36 0 01.083.345l-.333 1.36c-.053.22-.174.267-.402.161-1.499-.698-2.436-2.889-2.436-4.649 0-3.785 2.75-7.262 7.929-7.262 4.163 0 7.398 2.967 7.398 6.931 0 4.136-2.607 7.464-6.227 7.464-1.216 0-2.359-.632-2.75-1.378l-.748 2.853c-.271 1.043-1.002 2.35-1.492 3.146C9.57 23.812 10.763 24 12 24c6.627 0 12-5.373 12-12S18.627 0 12 0z"/>'],
        'shopee'    => ['Shopee',    '<path d="M12 2a5 5 0 015 5H7a5 5 0 015-5zm8.5 6H3.5l1 12.5A1.5 1.5 0 006 22h12a1.5 1.5 0 001.5-1.5L20.5 8zm-8.5 3a3 3 0 100 6 3 3 0 000-6z"/>'],
    ];
    $host = strtolower(parse_url($url, PHP_URL_HOST) ?: '');
    $host = preg_replace('/^www\./', '', $host);
    foreach ($icons as $domain => [$label, $path]) {
        if (strpos($host, $domain) !== false) {
            return ['label' => $label, 'icon' => $path];
        }
    }
    // Fallback: use host as label, no icon
    return ['label' => ucfirst(explode('.', $host)[0] ?? 'Link'), 'icon' => null];
}
?>
</main>

    <!-- Footer: layout and design (self-contained so it always displays correctly) -->
    <style>
        .ft-footer { width: 100%; background: #00151b; color: #e2e8f0; margin-top: 2.5rem; box-sizing: border-box; border-top: none; }
        .ft-wrap { max-width: 1100px; margin: 0 auto; padding: 2.5rem 1.5rem; box-sizing: border-box; }
        .ft-grid { display: grid; grid-template-columns: 1fr; gap: 2rem; }
        @media (min-width: 768px) { .ft-grid { grid-template-columns: repeat(4, 1fr); gap: 2.5rem; } }
        .ft-footer .ft-brand { font-size: 1.25rem; font-weight: 700; color: #e5e7eb !important; margin: 0 0 0.5rem 0; }
        .ft-footer h3.ft-brand { color: #e5e7eb !important; }
        .ft-desc { font-size: 0.875rem; color: #94a3b8; line-height: 1.55; margin: 0; max-width: 260px; }
        .ft-footer .ft-title { font-size: 0.9375rem; font-weight: 700; color: #e5e7eb !important; margin: 0 0 1rem 0; text-transform: uppercase; letter-spacing: 0.03em; }
        .ft-footer h3.ft-title { color: #e5e7eb !important; }
        .ft-list { list-style: none; padding: 0; margin: 0; }
        .ft-list li { margin-bottom: 0.5rem; }
        .ft-list a { font-size: 0.875rem; color: #94a3b8; text-decoration: none; transition: color 0.2s; }
        .ft-list a:hover { color: #53C5E0; }
        .ft-list-item { font-size: 0.875rem; color: #94a3b8; display: flex; align-items: flex-start; gap: 0.5rem; margin-bottom: 0.625rem; line-height: 1.4; }
        .ft-ico-svg { flex-shrink: 0; width: 15px; height: 15px; color: #53C5E0; margin-top: 1px; }
        .ft-social { display: flex; gap: 0.75rem; margin-top: 1rem; flex-wrap: wrap; }
        .ft-social a { display: inline-flex; align-items: center; justify-content: center; width: 38px; height: 38px; background: rgba(255,255,255,0.08); color: #e2e8f0; border-radius: 50%; text-decoration: none; transition: background 0.2s, color 0.2s; font-size: 0.75rem; font-weight: 700; }
        .ft-social a:hover { background: #32a1c4; color: #fff; }
        .ft-social svg { width: 18px; height: 18px; display: block; }
        .ft-hr { border: 0; border-top: 1px solid rgba(255,255,255,0.1); margin: 2rem 0 1.25rem 0; }
        .ft-bottom { display: flex; flex-direction: column; gap: 0.5rem; text-align: left; font-size: 0.8125rem; color: #94a3b8; }
        @media (min-width: 768px) { .ft-bottom { flex-direction: row; justify-content: space-between; align-items: center; text-align: left; } }
        @media (max-width: 767px) {
            .ft-wrap { padding: 2rem 1rem 6rem; }
            .ft-grid { gap: 2rem; }
            .ft-grid > div:first-child { text-align: left; }
            .ft-grid > div:first-child .ft-desc { margin: 0; max-width: 100%; text-align: left; }
            .ft-grid > div:first-child .ft-social { justify-content: flex-start; }
            .ft-brand, .ft-title { text-align: left; }
            .ft-desc {
                text-align: left;
                line-height: 1.65;
                max-width: none;
            }
            .ft-list-item, .ft-bottom p {
                text-align: left;
                line-height: 1.65;
                max-width: none;
            }
            .ft-list { text-align: left; }
            .ft-list li { text-align: left; }
            .ft-list a { display: inline-block; }
            .ft-list-item { justify-content: flex-start; text-align: left; display: flex; max-width: none; margin-left: 0; margin-right: 0; }
            .ft-services-list .ft-list-item {
                display: flex;
                width: 100%;
                max-width: none;
            }
            .ft-contact-list .ft-list-item {
                display: flex;
                width: 100%;
                gap: 0.4rem;
                justify-content: flex-start;
                align-items: flex-start;
            }
            .ft-social { margin-top: .85rem; }
            .ft-list li { margin-bottom: .65rem; }
            .ft-grid > div { padding: 0 0.5rem; text-align: left; }
        }
    </style>
    <?php if ($_ft_show_footer): ?>
    <footer class="ft-footer">
        <div class="ft-wrap">
            <div class="ft-grid">
                <!-- Brand + Tagline + Socials -->
                <div>
                    <h3 class="ft-brand"><?php echo $_ft_name; ?></h3>
                    <p class="ft-desc"><?php echo htmlspecialchars($_ft_tagline); ?></p>
                    <?php if (!empty($_ft_socials)): ?>
                    <div class="ft-social">
                        <?php foreach ($_ft_socials as $_s):
                            $_surl    = htmlspecialchars($_s['url'] ?? '');
                            $_sdetect = _ft_detect_social($_s['url'] ?? '');
                            $_slabel  = htmlspecialchars($_sdetect['label']);
                            $_sicon   = $_sdetect['icon'];
                        ?>
                        <a href="<?php echo $_surl; ?>" target="_blank" rel="noopener noreferrer" aria-label="<?php echo $_slabel; ?>">
                            <?php if ($_sicon): ?>
                                <svg width="18" height="18" fill="currentColor" viewBox="0 0 24 24"><?php echo $_sicon; ?></svg>
                            <?php else: ?>
                                <?php echo strtoupper(mb_substr($_slabel, 0, 2)); ?>
                            <?php endif; ?>
                        </a>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                </div>

                <!-- Quick Links -->
                <div>
                    <h3 class="ft-title">Quick Links</h3>
                    <ul class="ft-list">
                        <li><a href="<?php echo $base_path; ?>/public/index.php">Home</a></li>
                        <li><a href="<?php echo $base_path; ?>/public/about.php">About</a></li>
                        <li><a href="<?php echo $base_path; ?>/public/services.php">Services</a></li>
                        <li><a href="<?php echo $url_products; ?>">Products</a></li>
                        <?php if (!$is_logged_in): ?>
                        <li><a href="#" data-auth-modal="login">Login</a></li>
                        <li><a href="#" data-auth-modal="register">Register</a></li>
                        <?php endif; ?>
                    </ul>
                </div>

                <!-- Services (dynamic from admin) -->
                <div>
                    <h3 class="ft-title">Our Services</h3>
                    <?php if (!empty($_ft_services)): ?>
                    <ul class="ft-list ft-services-list">
                        <?php foreach ($_ft_services as $_svc): ?>
                        <li class="ft-list-item">
                            <span class="ft-ico">✓</span>
                            <?php echo htmlspecialchars($_svc); ?>
                        </li>
                        <?php endforeach; ?>
                    </ul>
                    <?php else: ?>
                    <p class="ft-desc" style="font-style:italic;opacity:.6;">No services listed yet.</p>
                    <?php endif; ?>
                </div>

                <!-- Contact (dynamic) -->
                <div>
                    <h3 class="ft-title">Contact</h3>
                    <ul class="ft-list ft-contact-list">
                        <?php if (!empty($_ft_email)): ?>
                        <li class="ft-list-item">
                            <svg class="ft-ico-svg" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/></svg>
                            <a href="mailto:<?php echo htmlspecialchars($_ft_email); ?>"><?php echo htmlspecialchars($_ft_email); ?></a>
                        </li>
                        <?php endif; ?>
                        <?php if (!empty($_ft_phone)): ?>
                        <li class="ft-list-item">
                            <svg class="ft-ico-svg" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 5a2 2 0 012-2h3.28a1 1 0 01.948.684l1.498 4.493a1 1 0 01-.502 1.21l-2.257 1.13a11.042 11.042 0 005.516 5.516l1.13-2.257a1 1 0 011.21-.502l4.493 1.498a1 1 0 01.684.949V19a2 2 0 01-2 2h-1C9.716 21 3 14.284 3 6V5z"/></svg>
                            <a href="tel:<?php echo htmlspecialchars(preg_replace('/\s+/','',$_ft_phone)); ?>"><?php echo htmlspecialchars($_ft_phone); ?></a>
                        </li>
                        <?php endif; ?>
                        <?php if (!empty($_ft_branch_addrs)): ?>
                            <?php foreach ($_ft_branch_addrs as $_ba): ?>
                            <li class="ft-list-item">
                                <svg class="ft-ico-svg" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
                                <span><?php echo nl2br(htmlspecialchars($_ba['address'] ?? '')); ?></span>
                            </li>
                            <?php endforeach; ?>
                        <?php endif; ?>
                        <?php if (!empty($_ft_hours)): ?>
                        <li class="ft-list-item">
                            <svg class="ft-ico-svg" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                            <?php echo htmlspecialchars($_ft_hours); ?>
                        </li>
                        <?php endif; ?>
                        <?php if (empty($_ft_email) && empty($_ft_phone) && empty($_ft_branch_addrs)): ?>
                        <li class="ft-list-item" style="opacity:.5;font-style:italic;">No contact info set yet.</li>
                        <?php endif; ?>
                    </ul>
                </div>
            </div>
            <hr class="ft-hr">
            <div class="ft-bottom">
                <p>&copy; <?php echo date('Y'); ?> <?php echo $_ft_name; ?>. All rights reserved.</p>
                <p>Made with ♥ for quality printing</p>
            </div>
        </div>
    </footer>
    <?php endif; ?>

    <?php if (!$is_logged_in): ?>
    <?php
    require_once __DIR__ . '/google-oauth-config.php';
    $google_client_id = defined('GOOGLE_CLIENT_ID') && GOOGLE_CLIENT_ID !== '' ? GOOGLE_CLIENT_ID : null;
    require_once __DIR__ . '/auth-modals.php';
    ?>
    <?php endif; ?>
    
    <?php require_once __DIR__ . '/success_modal.php'; ?>

    <!-- Scroll to Top (all non-admin pages) -->
    <?php if (!is_admin() && !is_staff()): ?>
    <?php
    $request_path = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?: '';
    $current_script_name = strtolower(basename($request_path));
    $current_script_dir = strtolower(basename(trim(str_replace('\\', '/', dirname($request_path)), '/')));
    if ($current_script_name === '' || substr($current_script_name, -4) !== '.php') {
        $current_script_name = strtolower(basename($_SERVER['SCRIPT_NAME'] ?? 'index.php'));
    }
    if ($current_script_dir === '.' || $current_script_dir === '/' || $current_script_dir === '\\') {
        $current_script_dir = '';
    }
    $chatbot_allowed_pages = ['index.php', 'about.php', 'services.php', 'products.php'];
    $is_customer_chatbot_page = $current_script_dir === 'customer';
    // Support chat is for customers / public site — not Manager panel or logged-in managers.
    $is_manager_context = $current_script_dir === 'manager'
        || (function_exists('is_manager') && is_manager());
    $show_chatbot_widget = empty($hide_chatbot) && !$is_manager_context && (
        in_array($current_script_name, $chatbot_allowed_pages, true)
        || $is_customer_chatbot_page
    );
    ?>
    <?php if (empty($use_landing_css)): ?>
    <style>
    </style>
    <?php endif; ?>

    <!-- =========== SCROLL TO TOP + CHATBOT WIDGET (SIDE BY SIDE) =========== -->
    <!-- Scroll to Top Button (LEFT) -->
    <a href="#" class="ft-bubble ft-bubble-left ft-bubble-hidden" id="lp-scroll-top" aria-label="Scroll to top" title="Scroll to top">
        <svg fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2" style="width: 28px; height: 28px;"><path stroke-linecap="round" stroke-linejoin="round" d="M5 10l7-7m0 0l7 7m-7-7v18"/></svg>
    </a>

    <!-- Support chat button (RIGHT) -->
    <?php if ($show_chatbot_widget): ?>
    <div id="chatbot-btn" class="ft-bubble ft-bubble-right" title="Open support chat">
        <svg fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2.5" style="width: 32px; height: 32px; color: #00232b; transition: all 0.3s ease;"><path stroke-linecap="round" stroke-linejoin="round" d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z"/></svg>
    </div>

    <!-- Support chat window -->
    <div id="chatbot-window" class="lp-chatbot-hidden" style="position: fixed; bottom: 90px; right: 20px; width: 380px; max-width: calc(100vw - 40px); max-height: 85vh; background: white; border-radius: 14px; box-shadow: 0 8px 32px rgba(0,0,0,0.2); display: flex; flex-direction: column; z-index: 9998; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; opacity: 0; transform: translateY(20px) scale(0.95); transition: all 0.3s cubic-bezier(0.34, 1.56, 0.64, 1); pointer-events: none;">
        <!-- Header -->
        <div style="padding: 18px; background: linear-gradient(135deg, #00232b, #1a5a6f); color: white; border-radius: 14px 14px 0 0; display: flex; justify-content: space-between; align-items: center; box-shadow: 0 2px 8px rgba(0,35,43,0.3); flex-shrink: 0;">
            <div style="display: flex; flex-direction: column; gap: 4px;">
                <h3 id="chatbot-title" style="margin: 0; font-size: 16px; font-weight: 700; letter-spacing: 0.3px; color: #ffffff !important; text-shadow: 0 1px 2px rgba(0,0,0,0.18);">Support chat</h3>
                <div id="chatbot-status" style="display: flex; align-items: center; gap: 6px;">
                    <span id="chatbot-status-dot" aria-hidden="true" style="width: 8px; height: 8px; border-radius: 999px; background: #22c55e; box-shadow: 0 0 0 4px rgba(34,197,94,0.16); display: inline-block;"></span>
                    <p id="chatbot-status-text" style="margin: 0; font-size: 11px; color: #ffffff; font-weight: 600;">Online</p>
                </div>
            </div>
            <button id="chatbot-close" style="background: none; border: none; color: white; font-size: 28px; cursor: pointer; padding: 0; width: 28px; height: 28px; transition: all 0.2s; display: flex; align-items: center; justify-content: center; opacity: 0.7;" type="button" title="Close chat" onmouseover="this.style.opacity='1'" onmouseout="this.style.opacity='0.7'">×</button>
        </div>

        <!-- Chat Content Only -->

        <!-- Chat Content -->
        <div id="chatbot-content-chat" style="display: flex; flex-direction: column; flex: 1; overflow: hidden;">
            <!-- Messages Area with Custom Scrollbar -->
            <div id="chatbot-messages" style="flex: 1; padding: 16px; overflow-y: auto; display: flex; flex-direction: column; gap: 14px; background: linear-gradient(to bottom, white, #f8fafb); position: relative;">
                <style>
                    @keyframes pulse { 0%, 100% { opacity: 1; } 50% { opacity: 0.5; } }
                    @keyframes slideInLeft { from { opacity: 0; transform: translateX(-10px); } to { opacity: 1; transform: translateX(0); } }
                    @keyframes slideInRight { from { opacity: 0; transform: translateX(10px); } to { opacity: 1; transform: translateX(0); } }
                    @keyframes typing { 0%, 60%, 100% { opacity: 0.5; } 30% { opacity: 1; } }
                    
                    #chatbot-messages::-webkit-scrollbar { width: 6px; }
                    #chatbot-messages::-webkit-scrollbar-track { background: linear-gradient(180deg, rgba(83,197,224,0.1), rgba(83,197,224,0.05)); border-radius: 10px; }
                    #chatbot-messages::-webkit-scrollbar-thumb { background: linear-gradient(180deg, #53C5E0, #32a1c4); border-radius: 10px; box-shadow: inset 0 0 6px rgba(0,0,0,0.1); }
                    #chatbot-messages::-webkit-scrollbar-thumb:hover { background: linear-gradient(180deg, #32a1c4, #1a7a94); }
                    #chatbot-messages { scrollbar-color: #53C5E0 rgba(83,197,224,0.1); scrollbar-width: thin; }
                    
                    .cb-msg-bot { animation: slideInLeft 0.3s ease-out; }
                    .cb-msg-user { animation: slideInRight 0.3s ease-out; }
                    .cb-typing { animation: typing 1.4s ease-in-out infinite; }
                </style>
                <div style="display: flex; justify-content: flex-start;">
                    <div style="background: #f0f0f0; color: #333; padding: 14px 16px; border-radius: 14px 14px 14px 4px; margin: 0; max-width: 85%; font-size: 14px; line-height: 1.4; box-shadow: 0 1px 3px rgba(0,0,0,0.05); animation: slideInLeft 0.3s ease-out;">Hello! How can we help you today?</div>
                </div>
            </div>

            <!-- Questions Area -->
            <div id="chatbot-suggested-section" style="display: none; border-top: 1px solid #e5e5e5; background: #f9f9fb; flex-shrink: 0;">
                <button id="chatbot-suggested-toggle" type="button" aria-expanded="false" style="width: 100%; display: flex; align-items: center; justify-content: space-between; gap: 12px; padding: 12px 16px; border: none; background: transparent; color: #1f2937; cursor: pointer; font-size: 13px; font-weight: 700; letter-spacing: 0.2px; text-align: left; transition: background 0.2s ease;">
                    <span>Suggested Questions</span>
                    <span id="chatbot-suggested-arrow" aria-hidden="true" style="font-size: 14px; line-height: 1; color: #4b5563; transition: transform 0.25s ease; transform-origin: center;">&#9654;</span>
                </button>
                <div id="chatbot-suggested-panel" style="overflow: hidden; max-height: 140px; transition: max-height 0.3s ease;">
                    <div id="chatbot-questions" style="padding: 0 16px 12px 16px; overflow-y: auto; max-height: 140px; background: #f9f9fb;">
                        <style>
                            #chatbot-questions::-webkit-scrollbar { width: 5px; }
                            #chatbot-questions::-webkit-scrollbar-track { background: rgba(83,197,224,0.08); border-radius: 10px; }
                            #chatbot-questions::-webkit-scrollbar-thumb { background: #b0d4e3; border-radius: 10px; }
                            #chatbot-questions::-webkit-scrollbar-thumb:hover { background: #8ec5d5; }
                            #chatbot-questions { scrollbar-width: thin; scrollbar-color: #b0d4e3 rgba(83,197,224,0.08); }
                        </style>
                        <!-- Questions loaded here -->
                    </div>
                </div>
            </div>

            <!-- Input Area -->
            <div style="padding: 12px 16px 16px 16px; border-top: 1px solid #e5e5e5; background: white; border-radius: 0 0 14px 14px; display: flex; gap: 8px; flex-shrink: 0;">
                <input id="chatbot-input" type="text" placeholder="Type your question..." style="flex: 1; padding: 12px 14px; border: 2px solid #e5e5e5; border-radius: 8px; font-size: 14px; outline: none; transition: all 0.3s ease; font-family: inherit; background: white;" />
                <button id="chatbot-send" style="padding: 12px 16px; background: linear-gradient(135deg, #53C5E0, #32a1c4); color: white; border: none; border-radius: 8px; cursor: pointer; font-weight: 600; transition: all 0.3s ease; display: flex; align-items: center; justify-content: center; min-width: 60px; box-shadow: 0 2px 8px rgba(83,197,224,0.2); font-size: 13px;" type="button" title="Send message">
                    Send
                </button>
            </div>
        </div>
    </div>
    <?php endif; ?>
    <?php endif; ?>

    <style>
    .ft-bubble {
        position: fixed;
        bottom: 20px;
        width: 60px;
        height: 60px;
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        z-index: 9999;
        transition: all 0.3s cubic-bezier(0.34, 1.56, 0.64, 1);
        cursor: pointer;
        text-decoration: none;
        box-sizing: border-box;
    }
    .ft-bubble-left { 
        left: 20px; 
        background: linear-gradient(135deg, #0a2530, #00232b);
        color: #53C5E0; 
        border: 1.5px solid rgba(83,197,224,.4);
        box-shadow: 0 4px 16px rgba(0,0,0,.5), 0 0 12px rgba(83,197,224,.15);
    }
    .ft-bubble-right { 
        right: 20px; 
        background: linear-gradient(135deg, #53C5E0, #32a1c4);
        color: #00151b; 
        border: none;
        box-shadow: 0 4px 16px rgba(83,197,224,.4), 0 0 12px rgba(83,197,224,.2);
    }
    
    .ft-bubble-hidden { 
        opacity: 0 !important; 
        transform: translateY(20px) scale(0.8) !important; 
        pointer-events: none !important; 
    }
    .ft-bubble-visible { 
        opacity: 1 !important; 
        transform: translateY(0) scale(1) !important; 
        pointer-events: auto !important; 
    }

    .lp-chatbot-hidden { display: none !important; }
    .lp-chatbot-visible { opacity: 1 !important; transform: translateY(0) scale(1) !important; pointer-events: auto !important; }
    
    #chatbot-btn:hover { background: #32a1c4; box-shadow: 0 8px 24px rgba(50,161,196,0.5); transform: scale(1.1) rotate(5deg); }
    #chatbot-btn:active { transform: scale(0.95); }
    
    #lp-scroll-top:hover { 
        border-color: rgba(83,197,224,.7); 
        color: #fff; 
        background: linear-gradient(135deg, #1a5a6f, #00313d); 
        box-shadow: 0 8px 28px rgba(83,197,224,.35), 0 0 20px rgba(83,197,224,.2); 
        transform: scale(1.1) translateY(-3px); 
    }
    #lp-scroll-top:active { transform: scale(0.95); }
    
    #chatbot-input:focus { border-color: #53C5E0; box-shadow: 0 0 0 3px rgba(83,197,224,0.15); background: #f8fcfd; }
    #chatbot-input::placeholder { color: #999; }
    
    #chatbot-send:hover { box-shadow: 0 4px 16px rgba(83,197,224,0.4); transform: translateY(-2px); }
    #chatbot-send:active { transform: translateY(0) scale(0.98); }

    #chatbot-tabs button:hover { background: #f9f9f9; }
    #chatbot-tabs button { transition: all 0.2s ease; }
    
    /* Mobile responsive styles for scroll-to-top and chatbot */
    @media (max-width: 768px) {
        .ft-bubble {
            width: 40px !important;
            height: 40px !important;
            bottom: 12px !important;
        }
        .ft-bubble-left {
            left: 12px !important;
        }
        .ft-bubble-right {
            right: 12px !important;
        }
        .ft-bubble svg {
            width: 18px !important;
            height: 18px !important;
        }
        #chatbot-window {
            width: calc(100vw - 24px) !important;
            right: 12px !important;
            bottom: 70px !important;
            max-height: 68dvh !important;
            height: auto !important;
        }
    }
    </style>

    <?php if (!is_admin() && !is_staff()): ?>
    <script>
    if (!window.__pfFooterScrollTopInit) {
        window.__pfFooterScrollTopInit = true;
        (function() {
            var scrollTop = document.getElementById('lp-scroll-top');
            if (!scrollTop) return;
            var scrollHideTimer = null;

            function showScrollTopWhileScrolling() {
                if (window.scrollY > 200) {
                    scrollTop.classList.remove('ft-bubble-hidden');
                    if (scrollHideTimer) {
                        clearTimeout(scrollHideTimer);
                    }
                    scrollHideTimer = setTimeout(function() {
                        scrollTop.classList.add('ft-bubble-hidden');
                    }, 700);
                }
            }

            scrollTop.classList.add('ft-bubble-hidden');
            window.addEventListener('scroll', function() {
                if (window.scrollY <= 200) {
                    if (scrollHideTimer) {
                        clearTimeout(scrollHideTimer);
                    }
                    scrollTop.classList.add('ft-bubble-hidden');
                    return;
                }
                showScrollTopWhileScrolling();
            }, { passive: true });
            scrollTop.addEventListener('click', function(e) {
                e.preventDefault();
                if (scrollHideTimer) {
                    clearTimeout(scrollHideTimer);
                }
                scrollTop.classList.add('ft-bubble-hidden');
                window.scrollTo({ top: 0, behavior: 'smooth' });
            });
        })();
    }
    </script>
    <?php endif; ?>

    <!-- Support chat widget script -->
    <?php if (!empty($show_chatbot_widget)): ?>
    <script>
    // Define BASE_PATH for JavaScript
    window.BASE_PATH = <?php echo json_encode($base_url ?? '/printflow'); ?>;
    (function() {
        var btn = document.getElementById('chatbot-btn');
        var win = document.getElementById('chatbot-window');
        var close = document.getElementById('chatbot-close');
        var msgs = document.getElementById('chatbot-messages');
        var suggestedSection = document.getElementById('chatbot-suggested-section');
        var suggestedToggle = document.getElementById('chatbot-suggested-toggle');
        var suggestedPanel = document.getElementById('chatbot-suggested-panel');
        var suggestedArrow = document.getElementById('chatbot-suggested-arrow');
        var ques = document.getElementById('chatbot-questions');
        var input = document.getElementById('chatbot-input');
        var sendBtn = document.getElementById('chatbot-send');
        var loaded = false;
        var isOpen = false;
        var areSuggestedQuestionsExpanded = false;
        var SUGGESTED_QUESTIONS_MAX_HEIGHT = 140;
        var isLoggedIn = <?php echo ($is_logged_in ? 'true' : 'false'); ?>;
        <?php
        $chatbot_customer_id = null;
        $chatbot_customer_name = 'Guest';
        $chatbot_customer_email = '';
        if (isset($is_logged_in) && $is_logged_in && function_exists('get_user_type') && get_user_type() === 'Customer') {
            $chatbot_customer_id = function_exists('get_user_id') ? get_user_id() : null;
            $chatbot_customer_name = isset($_SESSION['user_name']) ? $_SESSION['user_name'] : 'Guest';
            if (function_exists('db_query') && $chatbot_customer_id) {
                $cem = db_query("SELECT email FROM customers WHERE customer_id = ?", 'i', [$chatbot_customer_id]);
                $chatbot_customer_email = !empty($cem[0]['email']) ? $cem[0]['email'] : '';
            }
        }
        ?>
        var chatbotCustomerId = <?php echo $chatbot_customer_id ? (int)$chatbot_customer_id : 'null'; ?>;
        var chatbotCustomerName = <?php echo json_encode($chatbot_customer_name); ?>;
        var chatbotCustomerEmail = <?php echo json_encode($chatbot_customer_email); ?>;
        var CHATBOT_PENDING_KEY = 'pf_chatbot_pending_message';
        var CHATBOT_RESUME_KEY = 'pf_chatbot_login_resume';
        var CHATBOT_AUTH_STATE_KEY = 'pf_chatbot_last_auth_state';
        var CHATBOT_ACTIVE_CONVERSATION_KEY = 'pf_chatbot_active_conversation_id';
        var CHATBOT_LAST_NOTICE_KEY = 'pf_chatbot_last_notice_at';
        var activeConversationId = parseInt(localStorage.getItem(CHATBOT_ACTIVE_CONVERSATION_KEY) || '0', 10) || 0;
        var renderedMessageIds = {};
        var checkInterval = null;
        var syncInFlight = false;
        var lastConversationActivityAt = 0;

        function readStoredJson(key) {
            try {
                var raw = localStorage.getItem(key);
                return raw ? JSON.parse(raw) : null;
            } catch (e) {
                return null;
            }
        }

        function writeStoredJson(key, value) {
            try {
                localStorage.setItem(key, JSON.stringify(value));
            } catch (e) {}
        }

        function removeStored(key) {
            try {
                localStorage.removeItem(key);
            } catch (e) {}
        }

        function syncChatbotAuthState() {
            var last = readStoredJson(CHATBOT_AUTH_STATE_KEY);
            if (!isLoggedIn && last && last.loggedIn) {
                removeStored(CHATBOT_PENDING_KEY);
                removeStored(CHATBOT_RESUME_KEY);
                removeStored(CHATBOT_ACTIVE_CONVERSATION_KEY);
            }
            writeStoredJson(CHATBOT_AUTH_STATE_KEY, {
                loggedIn: !!isLoggedIn,
                ts: Date.now()
            });
        }

        syncChatbotAuthState();

        function appendUserMessage(text) {
            var um = document.createElement('div');
            um.className = 'cb-msg-user';
            um.style.cssText = 'display: flex; justify-content: flex-end; gap: 8px;';
            um.innerHTML = '<div style="background: #53C5E0; color: white; padding: 12px 14px; border-radius: 14px 4px 14px 14px; margin: 0; max-width: 85%; font-size: 14px; line-height: 1.4; box-shadow: 0 1px 3px rgba(83,197,224,0.25); word-wrap: break-word;">' + escapeHtml(text) + '</div>';
            msgs.appendChild(um);
            msgs.scrollTop = msgs.scrollHeight;
        }

        function getSuggestedQuestionsPanelHeight() {
            if (!ques) return 0;
            return Math.min(ques.scrollHeight, SUGGESTED_QUESTIONS_MAX_HEIGHT);
        }

        function updateSuggestedQuestionsScrollState() {
            if (!ques) return;
            ques.style.overflowY = ques.scrollHeight > SUGGESTED_QUESTIONS_MAX_HEIGHT ? 'auto' : 'hidden';
        }

        function updateSuggestedQuestionsPanel(forceExpandedState) {
            if (!suggestedSection || !suggestedPanel || !suggestedToggle || !suggestedArrow || !ques) return;
            if (typeof forceExpandedState === 'boolean') {
                areSuggestedQuestionsExpanded = forceExpandedState;
            }

            var hasQuestions = ques.children.length > 0;
            suggestedSection.style.display = hasQuestions ? 'block' : 'none';
            if (!hasQuestions) {
                suggestedPanel.style.maxHeight = '0px';
                suggestedToggle.setAttribute('aria-expanded', 'false');
                suggestedArrow.innerHTML = '&#9654;';
                suggestedArrow.style.transform = 'rotate(0deg)';
                return;
            }

            updateSuggestedQuestionsScrollState();
            suggestedToggle.setAttribute('aria-expanded', areSuggestedQuestionsExpanded ? 'true' : 'false');
            suggestedArrow.innerHTML = '&#9654;';
            suggestedArrow.style.transform = areSuggestedQuestionsExpanded ? 'rotate(90deg)' : 'rotate(0deg)';
            suggestedPanel.style.maxHeight = areSuggestedQuestionsExpanded ? (getSuggestedQuestionsPanelHeight() + 'px') : '0px';
        }

        function appendBotMessage(html) {
            var bm = document.createElement('div');
            bm.className = 'cb-msg-bot';
            bm.style.cssText = 'display: flex; justify-content: flex-start; gap: 8px;';
            bm.innerHTML = '<div style="background: #f0f0f0; color: #333; padding: 12px 14px; border-radius: 14px 14px 4px 14px; margin: 0; max-width: 90%; font-size: 14px; line-height: 1.5; box-shadow: 0 1px 3px rgba(0,0,0,0.05); word-wrap: break-word;">' + html + '</div>';
            msgs.appendChild(bm);
            msgs.scrollTop = msgs.scrollHeight;
            return bm;
        }

        function showTypingIndicator() {
            var typing = document.createElement('div');
            typing.style.cssText = 'display: flex; justify-content: flex-start; gap: 8px;';
            typing.innerHTML = '<div style="background: #f0f0f0; color: #999; padding: 12px 14px; border-radius: 14px 14px 4px 14px; display: flex; gap: 4px; align-items: center; box-shadow: 0 1px 3px rgba(0,0,0,0.05);"><span class="cb-typing" style="width: 8px; height: 8px; background: #999; border-radius: 50%; display: inline-block;"></span><span class="cb-typing" style="width: 8px; height: 8px; background: #999; border-radius: 50%; display: inline-block; animation-delay: 0.2s;"></span><span class="cb-typing" style="width: 8px; height: 8px; background: #999; border-radius: 50%; display: inline-block; animation-delay: 0.4s;"></span></div>';
            msgs.appendChild(typing);
            msgs.scrollTop = msgs.scrollHeight;
            return typing;
        }

        function storePendingChatbotMessage(text) {
            writeStoredJson(CHATBOT_PENDING_KEY, {
                message: text,
                ts: Date.now(),
                returnUrl: window.location.href
            });
            writeStoredJson(CHATBOT_RESUME_KEY, {
                ts: Date.now(),
                returnUrl: window.location.href
            });
        }

        function getPendingChatbotMessage() {
            var pending = readStoredJson(CHATBOT_PENDING_KEY);
            if (!pending || !pending.message) return null;
            var ageMs = Date.now() - (parseInt(pending.ts || 0, 10) || 0);
            if (ageMs > 30 * 60 * 1000) {
                removeStored(CHATBOT_PENDING_KEY);
                return null;
            }
            return pending;
        }

        function clearPendingChatbotMessage() {
            removeStored(CHATBOT_PENDING_KEY);
            removeStored(CHATBOT_RESUME_KEY);
        }

        function parseChatbotTimestamp(value) {
            if (!value) return 0;
            if (typeof value === 'number') return value;
            var normalized = String(value).replace(' ', 'T');
            var ts = Date.parse(normalized);
            return isNaN(ts) ? 0 : ts;
        }

        function updateConversationActivity(timestampValue) {
            var ts = parseChatbotTimestamp(timestampValue);
            if (ts > lastConversationActivityAt) {
                lastConversationActivityAt = ts;
            }
        }

        function noticeStorageKey(conversationId) {
            return CHATBOT_LAST_NOTICE_KEY + '_' + String(conversationId || 'new');
        }

        function rememberIdleNotice(conversationId, timestampValue) {
            if (!conversationId) return;
            try {
                localStorage.setItem(noticeStorageKey(conversationId), String(parseChatbotTimestamp(timestampValue) || Date.now()));
            } catch (e) {}
        }

        function getIdleNoticeAt(conversationId) {
            if (!conversationId) return 0;
            try {
                return parseInt(localStorage.getItem(noticeStorageKey(conversationId)) || '0', 10) || 0;
            } catch (e) {
                return 0;
            }
        }

        function maybeShowResumeNotice(conversationId, activityTimestamp) {
            var activityAt = parseChatbotTimestamp(activityTimestamp) || lastConversationActivityAt;
            if (!conversationId || !activityAt) return;
            if ((Date.now() - activityAt) < (5 * 60 * 1000)) return;
            if (getIdleNoticeAt(conversationId) >= activityAt) return;
            appendBotMessage('Thanks for your question! Your message has been sent to our team. We\'ll get back to you as soon as possible.');
            rememberIdleNotice(conversationId, activityAt);
        }

        function ensureRenderedBucket(conversationId) {
            var key = String(conversationId || 0);
            if (!renderedMessageIds[key] || typeof renderedMessageIds[key] !== 'object') {
                renderedMessageIds[key] = {};
            }
            return renderedMessageIds[key];
        }

        function markRenderedMessage(conversationId, messageId) {
            if (!conversationId || !messageId) return;
            ensureRenderedBucket(conversationId)[String(messageId)] = true;
        }

        function hasRenderedMessage(conversationId, messageId) {
            if (!conversationId || !messageId) return false;
            return !!ensureRenderedBucket(conversationId)[String(messageId)];
        }

        function setActiveConversationId(conversationId) {
            activeConversationId = parseInt(conversationId || 0, 10) || 0;
            if (activeConversationId > 0) {
                localStorage.setItem(CHATBOT_ACTIVE_CONVERSATION_KEY, String(activeConversationId));
                ensureRenderedBucket(activeConversationId);
            } else {
                localStorage.removeItem(CHATBOT_ACTIVE_CONVERSATION_KEY);
            }
        }

        function resetRenderedConversation(conversationId) {
            if (!conversationId) return;
            renderedMessageIds[String(conversationId)] = {};
            if (msgs) {
                msgs.innerHTML = '';
            }
        }

        function openChatbotWindow() {
            isOpen = true;
            win.classList.remove('lp-chatbot-hidden');
            setTimeout(function() { win.classList.add('lp-chatbot-visible'); }, 10);
            if (!loaded) loadFAQs();
            setTimeout(function() {
                if (input) input.focus();
                msgs.scrollTop = msgs.scrollHeight;
            }, 300);
        }

        function appendLiveConversationMessage(message) {
            if (!message || !message.id || hasRenderedMessage(activeConversationId, message.id)) {
                return;
            }
            updateConversationActivity(message.created_at);
            if (message.sender_type === 'customer') {
                appendUserMessage(message.message || '');
            } else {
                appendBotMessage(escapeHtml(message.message || ''));
            }
            markRenderedMessage(activeConversationId, message.id);
            msgs.scrollTop = msgs.scrollHeight;
        }

        function hydrateConversation(conversationId, messagesList) {
            setActiveConversationId(conversationId);
            resetRenderedConversation(activeConversationId);
            lastConversationActivityAt = 0;
            var items = Array.isArray(messagesList) ? messagesList : [];
            for (var i = 0; i < items.length; i++) {
                appendLiveConversationMessage(items[i]);
            }
        }

        function loadCurrentConversation(options) {
            options = options || {};
            var guestId = localStorage.getItem('chatbot_guest_id') || '';
            var basePath = window.BASE_PATH || '';
            var url = basePath + '/public/api/chatbot_inquiry.php?current=1';
            if (guestId) {
                url += '&guest_id=' + encodeURIComponent(guestId);
            }

            return fetch(url, { credentials: 'same-origin' })
                .then(function(r) { return r.json(); })
                .then(function(data) {
                    if (!data || !data.success || !data.data || !data.data.conversation) {
                        return null;
                    }
                    var conversation = data.data.conversation;
                    hydrateConversation(conversation.id, data.data.messages || []);
                    if (options.forceScroll) {
                        msgs.scrollTop = msgs.scrollHeight;
                    }
                    return conversation;
                })
                .catch(function() {
                    return null;
                });
        }

        function syncConversation(options) {
            options = options || {};
            if (!activeConversationId) {
                return loadCurrentConversation(options);
            }
            if (syncInFlight) {
                return Promise.resolve(null);
            }

            syncInFlight = true;
            var guestId = localStorage.getItem('chatbot_guest_id') || '';
            var basePath = window.BASE_PATH || '';
            var bucket = ensureRenderedBucket(activeConversationId);
            var renderedIds = Object.keys(bucket);
            var lastRenderedId = 0;
            for (var i = 0; i < renderedIds.length; i++) {
                var currentId = parseInt(renderedIds[i], 10) || 0;
                if (currentId > lastRenderedId) lastRenderedId = currentId;
            }

            var url = basePath + '/public/api/chatbot_inquiry.php?conversation_id=' + activeConversationId + '&sync=1&since_id=' + lastRenderedId;
            if (guestId) {
                url += '&guest_id=' + encodeURIComponent(guestId);
            }

            return fetch(url, { credentials: 'same-origin' })
                .then(function(r) { return r.json(); })
                .then(function(data) {
                    if (!data || !data.success || !data.data) return null;
                    var messages = Array.isArray(data.data.messages) ? data.data.messages : [];
                    messages.forEach(appendLiveConversationMessage);
                    if (options.forceScroll) {
                        msgs.scrollTop = msgs.scrollHeight;
                    }
                    return data;
                })
                .catch(function() {
                    return null;
                })
                .finally(function() {
                    syncInFlight = false;
                });
        }

        function openLoginFromChatbot() {
            if (window.openModal) {
                window.openModal('login');
                return;
            }
            var trigger = document.querySelector('[data-auth-modal="login"], [data-auth-open="login"]');
            if (trigger) {
                trigger.click();
                return;
            }
            window.location.href = (window.PFConfig && window.PFConfig.loginUrl) || (window.BASE_PATH || '') + '/?auth_modal=login';
        }

        function promptChatbotLogin(savedMessage) {
            appendBotMessage(
                'Please log in to continue. Your message will be sent after login.'
                + '<br><br><strong style="color:#0f172a;">Saved message:</strong> '
                + escapeHtml(savedMessage)
                + '<br><br><a href="#" id="chatbot-login-link" style="display:inline-block;padding:8px 18px;background:#111827;color:white;border-radius:8px;font-size:13px;font-weight:600;text-decoration:none;">Log in</a>'
                + '<button type="button" id="chatbot-cancel-pending" style="margin-left:8px;padding:8px 14px;background:#fff;color:#475569;border:1px solid #cbd5e1;border-radius:8px;font-size:13px;font-weight:600;cursor:pointer;">Cancel</button>'
            );

            setTimeout(function() {
                var loginLink = document.getElementById('chatbot-login-link');
                var cancelBtn = document.getElementById('chatbot-cancel-pending');
                if (loginLink) {
                    loginLink.addEventListener('click', function(e) {
                        e.preventDefault();
                        openLoginFromChatbot();
                    }, { once: true });
                }
                if (cancelBtn) {
                    cancelBtn.addEventListener('click', function() {
                        clearPendingChatbotMessage();
                        appendBotMessage('Saved message cancelled.');
                    }, { once: true });
                }
            }, 0);
        }

        function sendChatbotInquiry(question, options) {
            options = options || {};
            var showUserBubble = options.showUserBubble !== false;
            var activityBeforeSend = lastConversationActivityAt;
            var conversationBeforeSend = activeConversationId;
            var guestId = localStorage.getItem('chatbot_guest_id');
            if (!guestId) { guestId = 'g_' + Date.now() + '_' + Math.random().toString(36).substr(2, 9); localStorage.setItem('chatbot_guest_id', guestId); }
            var payload = { question: question, customer_name: (typeof chatbotCustomerName !== 'undefined' ? chatbotCustomerName : 'Guest'), customer_email: (typeof chatbotCustomerEmail !== 'undefined' ? chatbotCustomerEmail : '') };
            if (typeof chatbotCustomerId !== 'undefined' && chatbotCustomerId) payload.customer_id = chatbotCustomerId;
            else payload.guest_id = guestId;

            if (showUserBubble) {
                appendUserMessage(question);
            }

            var typing = showTypingIndicator();
            var basePath = window.BASE_PATH || '';

            return fetch(basePath + '/public/api/chatbot_inquiry.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload)
            })
            .then(function(r) { return r.json(); })
            .then(function(data) {
                typing.remove();
                if (data.success && data.inquiry_id) {
                    setActiveConversationId(data.conversation_id || data.inquiry_id);
                    maybeShowResumeNotice(activeConversationId, activityBeforeSend || data.message && data.message.created_at);
                    var ids = JSON.parse(localStorage.getItem('chatbot_inquiry_ids') || '[]');
                    if (ids.indexOf(data.inquiry_id) === -1) {
                        ids.push(data.inquiry_id);
                    }
                    localStorage.setItem('chatbot_inquiry_ids', JSON.stringify(ids));
                    if (data.message && data.message.id) {
                        if (!conversationBeforeSend) {
                            lastConversationActivityAt = 0;
                        }
                        updateConversationActivity(data.message.created_at);
                        markRenderedMessage(activeConversationId, data.message.id);
                    }
                    syncConversation({ forceScroll: true });
                }
                return data;
            })
            .catch(function() {
                typing.remove();
                appendBotMessage('Message sent. Replies should appear here automatically.');
            });
        }

        function tryResumePendingChatbotMessage() {
            if (!isLoggedIn) return;
            var pending = getPendingChatbotMessage();
            if (!pending || !pending.message || !pending.message.trim()) {
                clearPendingChatbotMessage();
                return;
            }

            openChatbotWindow();
            appendBotMessage('Resuming your saved message...');
            clearPendingChatbotMessage();
            sendChatbotInquiry(pending.message.trim(), { showUserBubble: true }).finally(function() {
                msgs.scrollTop = msgs.scrollHeight;
            });
        }

        // Toggle support chat on button click
        if (btn) {
        btn.addEventListener('click', function() {
            isOpen = !isOpen;
            if (isOpen) {
                openChatbotWindow();
                syncConversation({ forceScroll: true });
                const scheduleSync = () => { if (checkInterval) clearTimeout(checkInterval); checkInterval = setTimeout(() => { if (document.visibilityState === 'visible') syncConversation().finally(scheduleSync); else scheduleSync(); }, 3000); }; scheduleSync();
            } else {
                win.classList.remove('lp-chatbot-visible');
                setTimeout(() => win.classList.add('lp-chatbot-hidden'), 300);
                if (checkInterval) clearTimeout(checkInterval);
                checkInterval = null;
            }
        });
        }

        // Close support chat
        if (close) {
        close.addEventListener('click', function() {
            isOpen = false;
            win.classList.remove('lp-chatbot-visible');
            setTimeout(() => win.classList.add('lp-chatbot-hidden'), 300);
            if (checkInterval) clearTimeout(checkInterval);
            checkInterval = null;
        });
        }

        if (suggestedToggle) {
        suggestedToggle.addEventListener('click', function() {
            areSuggestedQuestionsExpanded = !areSuggestedQuestionsExpanded;
            updateSuggestedQuestionsPanel();
        });
        suggestedToggle.addEventListener('mouseover', function() {
            this.style.background = 'rgba(255,255,255,0.45)';
        });
        suggestedToggle.addEventListener('mouseout', function() {
            this.style.background = 'transparent';
        });
        }

        // Load FAQs and populate questions
        function loadFAQs() {
            loaded = true;
            var basePath = window.BASE_PATH || '';
            Promise.all([
                fetch(basePath + '/public/api/get_faqs.php').then(r => r.json()),
                fetch(basePath + '/public/api/get_chatbot_info.php').then(r => r.json())
            ])
            .then(([faqData, infoData]) => {
                var allQuestions = [];

                // Add FAQs
                if (faqData.success && faqData.data && faqData.data.length > 0) {
                    allQuestions = allQuestions.concat(faqData.data.map(faq => ({
                        text: faq.question,
                        answer: faq.answer
                    })));
                }

                // Add Services
                if (infoData.success && infoData.data && infoData.data.services && infoData.data.services.length > 0) {
                    allQuestions = allQuestions.concat(infoData.data.services.map(service => ({
                        text: service,
                        answer: 'We offer high-quality ' + service + ' printing services. Contact us for more details and pricing.'
                    })));
                }

                // Add Contact Info as question
                if (infoData.success && infoData.data) {
                    var d = infoData.data;
                    var contactAnswer = 'Contact Us\n\n';
                    if (d.email) contactAnswer += 'Email: ' + d.email + '\n';
                    if (d.phone) contactAnswer += 'Phone: ' + d.phone;
                    
                    allQuestions.push({
                        text: 'Contact Information',
                        answer: contactAnswer.trim()
                    });

                    // Add Branches as question
                    if (d.branches && d.branches.length > 0) {
                        var branchesAnswer = 'Our Branches\n\n';
                        branchesAnswer += d.branches.map(b => b.name + '\n' + b.address).join('\n\n');
                        
                        allQuestions.push({
                            text: 'Our Branches',
                            answer: branchesAnswer.trim()
                        });
                    }

                    // Add Hours as question
                    if (d.hours) {
                        allQuestions.push({
                            text: 'Business Hours',
                            answer: 'Business Hours\n\n' + d.hours
                        });
                    }
                }

                // Render all questions
                if (allQuestions.length > 0) {
                    ques.innerHTML = allQuestions.map((item, idx) => 
                        '<button style="display: block; width: 100%; text-align: left; padding: 12px 14px; margin: 6px 0; background: white; border: 1px solid #d5e8f0; border-radius: 8px; cursor: pointer; font-size: 13px; color: #333; transition: all 0.2s ease; font-weight: 500; line-height: 1.4; animation: slideInLeft 0.3s ease-out ' + (idx * 0.05) + 's both;" data-q="' + escapeHtml(item.text) + '" data-a="' + escapeHtml(item.answer) + '">' + escapeHtml(item.text) + '</button>'
                    ).join('');

                    updateSuggestedQuestionsPanel(false);
                    ques.querySelectorAll('button').forEach(b => {
                        b.addEventListener('click', function() {
                            handleQuestion(this.dataset.q, this.dataset.a);
                            this.style.background = '#e8f4f8';
                            this.style.borderColor = '#53C5E0';
                            this.style.transform = 'scale(0.98)';
                            setTimeout(() => { this.style.transform = 'scale(1)'; }, 100);
                        });
                        b.addEventListener('mouseover', function() {
                            if (this.style.background !== '#e8f4f8') {
                                this.style.background = '#f0f0f0';
                                this.style.transform = 'translateX(4px)';
                            }
                        });
                        b.addEventListener('mouseout', function() {
                            if (this.style.background === '#f0f0f0') {
                                this.style.background = 'white';
                                this.style.transform = 'translateX(0)';
                            }
                        });
                    });
                } else {
                    ques.innerHTML = '';
                    updateSuggestedQuestionsPanel(false);
                }
            })
            .catch(e => {
                ques.innerHTML = '';
                updateSuggestedQuestionsPanel(false);
                console.error('Load error:', e);
            });
        }

        // Handle question click or manual input
        function handleQuestion(question, answer) {
            // User message with animation
            var um = document.createElement('div');
            um.className = 'cb-msg-user';
            um.style.cssText = 'display: flex; justify-content: flex-end; gap: 8px;';
            um.innerHTML = '<div style="background: #53C5E0; color: white; padding: 12px 14px; border-radius: 14px 4px 14px 14px; margin: 0; max-width: 85%; font-size: 14px; line-height: 1.4; box-shadow: 0 1px 3px rgba(83,197,224,0.25); word-wrap: break-word;">' + escapeHtml(question) + '</div>';
            msgs.appendChild(um);
            input.value = '';
            msgs.scrollTop = msgs.scrollHeight;
            
            // Typing indicator
            var typing = document.createElement('div');
            typing.style.cssText = 'display: flex; justify-content: flex-start; gap: 8px;';
            typing.innerHTML = '<div style="background: #f0f0f0; color: #999; padding: 12px 14px; border-radius: 14px 14px 4px 14px; display: flex; gap: 4px; align-items: center; box-shadow: 0 1px 3px rgba(0,0,0,0.05);"><span class="cb-typing" style="width: 8px; height: 8px; background: #999; border-radius: 50%; display: inline-block;"></span><span class="cb-typing" style="width: 8px; height: 8px; background: #999; border-radius: 50%; display: inline-block; animation-delay: 0.2s;"></span><span class="cb-typing" style="width: 8px; height: 8px; background: #999; border-radius: 50%; display: inline-block; animation-delay: 0.4s;"></span></div>';
            msgs.appendChild(typing);
            msgs.scrollTop = msgs.scrollHeight;
            
            // Bot response with delay
            setTimeout(() => {
                typing.remove();
                var bm = document.createElement('div');
                bm.className = 'cb-msg-bot';
                bm.style.cssText = 'display: flex; justify-content: flex-start; gap: 8px;';
                bm.innerHTML = '<div style="background: #f0f0f0; color: #333; padding: 12px 14px; border-radius: 14px 14px 4px 14px; margin: 0; max-width: 85%; font-size: 14px; line-height: 1.5; box-shadow: 0 1px 3px rgba(0,0,0,0.05); word-wrap: break-word;">' + escapeHtml(answer) + '</div>';
                msgs.appendChild(bm);
                msgs.scrollTop = msgs.scrollHeight;
            }, 1000);
        }

        // Show login-required prompt in chat
        // Send button and input Enter key
        if (sendBtn) {
        sendBtn.addEventListener('click', function() {
            if (input.value.trim()) {
                var q = input.value.trim();
                input.value = '';
                if (!isLoggedIn) {
                    storePendingChatbotMessage(q);
                    appendUserMessage(q);
                    promptChatbotLogin(q);
                    return;
                }

                sendChatbotInquiry(q, { showUserBubble: true });
            }
        });
        }

        if (input) {
        input.addEventListener('keypress', function(e) {
            if (e.key === 'Enter' && input.value.trim()) {
                sendBtn.click();
            }
        });
        }

        if (input) {
        input.addEventListener('input', function() {
            if (this.value.trim()) {
                sendBtn.style.transform = 'scale(1)';
            }
        });
        }

        if (window.location.search.indexOf('chatbot=open') !== -1) {
            openChatbotWindow();
            syncConversation({ forceScroll: true });
            const scheduleSync = () => { if (checkInterval) clearTimeout(checkInterval); checkInterval = setTimeout(() => { if (document.visibilityState === 'visible') syncConversation().finally(scheduleSync); else scheduleSync(); }, 3000); }; scheduleSync();
        } else {
            setTimeout(function() {
                syncConversation();
            }, 1000);
        }
        setTimeout(tryResumePendingChatbotMessage, 300);
        updateSuggestedQuestionsPanel(false);
        window.addEventListener('resize', function() {
            if (areSuggestedQuestionsExpanded) {
                updateSuggestedQuestionsPanel(true);
            }
        });

        function escapeHtml(t) {
            var d = document.createElement('div');
            d.textContent = t;
            return d.innerHTML;
        }
    })();
    </script>
    <?php endif; ?>

    <!-- PWA -->
    <script src="<?php echo $base_url; ?>/public/assets/js/pwa.js?v=<?php echo time(); ?>"></script>

    <?php
    // Only load push/notification script for authenticated users
    if (!isset($auth_loaded)) {
        $auth_loaded = true;
        $auth_file = __DIR__ . '/auth.php';
        if (file_exists($auth_file) && !function_exists('is_logged_in')) {
            require_once $auth_file;
        }
    }
    if (function_exists('is_logged_in') && is_logged_in()):
        require_once __DIR__ . '/shop_config.php';
        $_pf_uid   = function_exists('get_user_id')   ? (int)(get_user_id() ?? 0)    : 0;
        $_pf_utype = function_exists('get_user_type') ? (get_user_type() ?? 'Customer') : 'Customer';
    ?>
    <script>
    window.PFConfig = Object.assign({}, window.PFConfig || {}, {
        userId: <?php echo $_pf_uid; ?>,
        userType: <?php echo json_encode($_pf_utype); ?>,
        sessionId: <?php echo json_encode(session_id()); ?>,
        basePath: <?php echo json_encode(isset($base_url) ? rtrim((string)$base_url, '/') : (defined('BASE_PATH') ? rtrim((string)BASE_PATH, '/') : '')); ?>,
        logoUrl: <?php echo json_encode($shop_logo_url); ?>,
        apiCartUrl: <?php echo json_encode((isset($base_url) ? rtrim((string)$base_url, '/') : (defined('BASE_PATH') ? rtrim((string)BASE_PATH, '/') : '')) . '/customer/api_cart.php'); ?>
    });
    </script>
    <script>
    (function() {
        function supportPresenceBeat() {
            var base = (window.PFConfig && window.PFConfig.basePath) || '';
            fetch(base + '/public/api/support_chat_presence.php', { credentials: 'same-origin' }).catch(function() {});
        }
        supportPresenceBeat();
        const schedulePresence = () => { setTimeout(() => { if (document.visibilityState === 'visible') supportPresenceBeat(); schedulePresence(); }, 10000); }; schedulePresence();
        window.addEventListener('focus', supportPresenceBeat);
        document.addEventListener('visibilitychange', function() {
            if (document.visibilityState === 'visible') supportPresenceBeat();
        });
    })();
    </script>
    <?php $notif_js_ver = @filemtime(__DIR__ . '/../public/assets/js/notifications.js') ?: time(); ?>
    <script src="<?php echo $base_url; ?>/public/assets/js/notifications.js?v=<?php echo $notif_js_ver; ?>" defer></script>
    <script src="<?php echo $base_url; ?>/public/assets/js/inactivity_logout.js" defer></script>
    <?php endif; ?>
    <script src="<?php echo $base_url ?? '/printflow'; ?>/public/assets/js/order_validation.js?v=<?php echo time(); ?>" defer></script>
</body>
</html>
