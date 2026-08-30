<?php
/**
 * Privacy Policy Page
 * PrintFlow - Printing Shop PWA
 */
require_once __DIR__ . '/../includes/auth.php';
redirect_admin_staff_from_public();

$page_title = 'Privacy Policy - PrintFlow';
$use_landing_css = true;
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/runtime_config.php';

$shop_cfg_path = __DIR__ . '/../public/assets/uploads/shop_config.json';
$shop_cfg = printflow_load_runtime_config('shop', $shop_cfg_path);
$shop_name = htmlspecialchars($shop_cfg['name'] ?? 'PrintFlow', ENT_QUOTES, 'UTF-8');
$effective_date = 'August 30, 2026';
?>

<section class="lp-mini-hero" style="padding-top:0; padding-bottom:4rem;">
    <?php $nav_header_class = 'lp-hero-nav sticky top-0 z-50'; require __DIR__ . '/../includes/nav-header.php'; ?>
    <div class="lp-mini-hero-inner" style="padding-top:4rem;">
        <div class="lp-wrap" style="text-align:center;">
            <p class="lp-hero-tag" style="margin-bottom:1.5rem;">Customer Privacy</p>
            <h1 style="font-size:clamp(2.2rem,5vw,3.5rem); font-weight:800; color:#fff; margin-bottom:1.25rem; line-height:1.1;">Privacy Policy</h1>
            <p style="font-size:1.0625rem; color:var(--lp-muted); max-width:720px; margin:0 auto; line-height:1.7;">This policy describes the customer information <?php echo $shop_name; ?> uses in the PrintFlow system based on the current account, order, payment, upload, verification, and messaging features.</p>
        </div>
    </div>
</section>

<section class="lp-section-light" style="padding-top:4rem; padding-bottom:4rem;">
    <div class="lp-wrap" style="max-width:900px;">
        <article class="lp-card" style="display:flex; flex-direction:column; gap:1.6rem;">
            <p class="lp-card-text" style="margin:0;"><strong style="color:#fff;">Effective date:</strong> <?php echo htmlspecialchars($effective_date, ENT_QUOTES, 'UTF-8'); ?></p>
            <section>
                <h2 class="lp-card-title">Information Used by PrintFlow</h2>
                <p class="lp-card-text">The system may use customer name, email address, contact number, account password hash for local sign-in, Google Sign-In email and profile name when Google is used, profile details, address details, profile picture, ID verification details, order records, cart/order items, custom specifications, uploaded designs or reference files, payment proof details, OTP or verification codes, notifications, and customer support messages or attachments.</p>
            </section>
            <section>
                <h2 class="lp-card-title">How Information Is Used</h2>
                <p class="lp-card-text">Customer information is used to create and secure accounts, verify email or phone access, complete customer profiles, process orders, review submitted designs, coordinate with staff, manage order status, verify payment submissions, provide notifications, and support customer communication through the system.</p>
            </section>
            <section>
                <h2 class="lp-card-title">Orders, Files, and Designs</h2>
                <p class="lp-card-text">PrintFlow stores order information and may store uploaded design files, reference images, payment proof files, chat images, voice messages, or related media when customers use those features. These materials are used for order processing, staff review, production coordination, payment verification, and support.</p>
            </section>
            <section>
                <h2 class="lp-card-title">Payments</h2>
                <p class="lp-card-text">The system records payment-related order status, selected payment method information where available, payment reference information, payment proof files, submitted amounts, verification status, and related review timestamps. Payment handling may also involve configured payment providers when those features are used.</p>
            </section>
            <section>
                <h2 class="lp-card-title">OTP and Account Verification</h2>
                <p class="lp-card-text">For registration and account recovery features, the system may create and store OTP codes, OTP expiration times, last-sent timestamps, verification flags, and password reset tokens. These are used to confirm account access and protect account actions.</p>
            </section>
            <section>
                <h2 class="lp-card-title">Google Sign-In</h2>
                <p class="lp-card-text">When a customer uses Google Sign-In, the system requests Google profile access for sign-in and uses the email address and available first and last name to find or create the customer account. Google OAuth secrets are not shown on this page.</p>
            </section>
            <section>
                <h2 class="lp-card-title">Access and Security</h2>
                <p class="lp-card-text">Customer information is used within the PrintFlow system by authenticated roles for account management, order handling, verification, reporting, and customer support. The system includes session handling, CSRF protection on forms, password hashing, and role-based redirects or access checks in the existing codebase.</p>
            </section>
            <section>
                <h2 class="lp-card-title">Sharing and Selling</h2>
                <p class="lp-card-text">This policy does not claim that PrintFlow sells customer information. The current code supports internal use for the printing workflow and integrations needed for configured features such as email delivery, OTP delivery, address selection, payment processing, push notifications, or Google Sign-In.</p>
                <p class="lp-card-text"><strong style="color:#fbbf24;">For review:</strong> Add any business-approved third-party disclosure details only after confirming the deployed integrations and operational policy.</p>
            </section>
            <section>
                <h2 class="lp-card-title">Customer Responsibilities</h2>
                <p class="lp-card-text">Customers should keep account credentials private, provide accurate contact and order information, and avoid uploading files they are not authorized to use.</p>
            </section>
            <section>
                <h2 class="lp-card-title">Policy Updates</h2>
                <p class="lp-card-text">This Privacy Policy may be updated when business practices or PrintFlow features change. Customers should review the latest version when creating an account or using customer services.</p>
            </section>
        </article>
    </div>
</section>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>