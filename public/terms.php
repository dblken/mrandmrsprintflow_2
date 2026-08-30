<?php
/**
 * Terms of Service Page
 * PrintFlow - Printing Shop PWA
 */
require_once __DIR__ . '/../includes/auth.php';
redirect_admin_staff_from_public();

$page_title = 'Terms of Service - PrintFlow';
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
            <p class="lp-hero-tag" style="margin-bottom:1.5rem;">Customer Terms</p>
            <h1 style="font-size:clamp(2.2rem,5vw,3.5rem); font-weight:800; color:#fff; margin-bottom:1.25rem; line-height:1.1;">Terms of Service</h1>
            <p style="font-size:1.0625rem; color:var(--lp-muted); max-width:700px; margin:0 auto; line-height:1.7;">These terms explain the general rules for using <?php echo $shop_name; ?> customer accounts and printing services. Business-specific items marked for review should be confirmed by the shop owner or administrator.</p>
        </div>
    </div>
</section>

<section class="lp-section-light" style="padding-top:4rem; padding-bottom:4rem;">
    <div class="lp-wrap" style="max-width:900px;">
        <article class="lp-card" style="display:flex; flex-direction:column; gap:1.6rem;">
            <p class="lp-card-text" style="margin:0;"><strong style="color:#fff;">Effective date:</strong> <?php echo htmlspecialchars($effective_date, ENT_QUOTES, 'UTF-8'); ?></p>
            <section>
                <h2 class="lp-card-title">Customer Orders</h2>
                <p class="lp-card-text">Customers are responsible for reviewing order details, selected products or services, quantities, sizes, submitted notes, and uploaded files before submitting an order. Orders may require staff review before production, pricing confirmation, or payment completion.</p>
            </section>
            <section>
                <h2 class="lp-card-title">Payments</h2>
                <p class="lp-card-text">Payment requirements, accepted payment methods, and payment verification are handled through the PrintFlow system and shop staff. Any business-specific payment timing, deposit, or balance rules should be reviewed and confirmed by the shop.</p>
                <p class="lp-card-text"><strong style="color:#fbbf24;">For review:</strong> Add the shop's exact payment terms only after they are formally approved.</p>
            </section>
            <section>
                <h2 class="lp-card-title">Custom Printing and Design Submissions</h2>
                <p class="lp-card-text">Customers may submit designs, images, references, instructions, and related files for custom print orders. Customers are responsible for making sure submitted files are accurate, readable, appropriate for production, and authorized for their intended use.</p>
            </section>
            <section>
                <h2 class="lp-card-title">Order Changes and Cancellations</h2>
                <p class="lp-card-text">Order changes and cancellation requests may be reviewed by staff based on the current order status. The system supports order status tracking and cancellation reasons, but exact approval rules, deadlines, and production cutoffs must be confirmed by the business.</p>
                <p class="lp-card-text"><strong style="color:#fbbf24;">For review:</strong> Add any exact cancellation windows or change deadlines only after approval.</p>
            </section>
            <section>
                <h2 class="lp-card-title">Refunds</h2>
                <p class="lp-card-text">Refund requests may depend on payment status, order status, and the nature of the order. PrintFlow includes payment and order tracking, but this page does not define any refund amount, fee, or guaranteed refund period.</p>
                <p class="lp-card-text"><strong style="color:#fbbf24;">For review:</strong> Add the shop's exact refund policy when available.</p>
            </section>
            <section>
                <h2 class="lp-card-title">Pickup and Delivery</h2>
                <p class="lp-card-text">Customers should follow staff instructions for pickup, delivery, branch selection, and order completion. Delivery availability, schedules, charges, and pickup requirements should be confirmed directly with the shop.</p>
                <p class="lp-card-text"><strong style="color:#fbbf24;">For review:</strong> Add specific pickup or delivery rules after the business confirms them.</p>
            </section>
            <section>
                <h2 class="lp-card-title">Customer Responsibility</h2>
                <p class="lp-card-text">Customers are responsible for the accuracy of their account information, contact details, order instructions, file uploads, and design approvals. Incorrect or incomplete information may affect communication, production, payment verification, pickup, or delivery.</p>
            </section>
            <section>
                <h2 class="lp-card-title">Identity Verification</h2>
                <p class="lp-card-text">Customers are required to complete identity verification before they can place an order through PrintFlow. Customers must upload a valid government-issued identification document through their customer profile for verification and review.</p>
                <p class="lp-card-text">The submitted identification document will be reviewed by an authorized administrator. Customers may proceed with ordering only after their identity verification has been approved.</p>
                <p class="lp-card-text">If the submitted identification document is pending review, rejected, missing, unreadable, or otherwise not approved, the customer may be unable to proceed with ordering until the verification requirement has been completed.</p>
                <p class="lp-card-text">Customers are responsible for providing accurate and valid identification information and for ensuring that the uploaded document is clear and readable.</p>
                <p class="lp-card-text">PrintFlow reserves the right to reject an identification submission if it cannot be adequately verified or does not meet the applicable verification requirements.</p>
            </section>
            <section>
                <h2 class="lp-card-title">Prohibited Content</h2>
                <p class="lp-card-text">Customers must not submit content that is illegal, harmful, fraudulent, abusive, or that they do not have permission to use. The shop may decline or cancel work that appears inappropriate or not suitable for production.</p>
            </section>
            <section>
                <h2 class="lp-card-title">Intellectual Property</h2>
                <p class="lp-card-text">Customers retain responsibility for the designs, logos, images, and other materials they submit. Submitting files to PrintFlow confirms that the customer has the needed rights or permission for the requested printing work.</p>
            </section>
            <section>
                <h2 class="lp-card-title">Account Usage</h2>
                <p class="lp-card-text">Customers are responsible for keeping their login credentials secure and for activity under their account. Accounts may be used to manage profile information, orders, uploaded files, messages, notifications, payments, and verification steps available in the system.</p>
            </section>
            <section>
                <h2 class="lp-card-title">Changes to These Terms</h2>
                <p class="lp-card-text">These Terms may be updated as the business and system features change. Customers should review the latest version when creating an account or placing orders.</p>
            </section>
        </article>
    </div>
</section>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>