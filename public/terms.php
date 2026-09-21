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
            <p style="font-size:1.0625rem; color:var(--lp-muted); max-width:700px; margin:0 auto; line-height:1.7;">These Terms of Service explain the rules for using <?php echo $shop_name; ?> customer accounts, placing orders, making online payments, and using the printing services available through the PrintFlow system.</p>
        </div>
    </div>
</section>

<section class="lp-section-light" style="padding-top:4rem; padding-bottom:4rem;">
    <div class="lp-wrap" style="max-width:900px;">
        <article class="lp-card" style="display:flex; flex-direction:column; gap:1.6rem;">
            <p class="lp-card-text" style="margin:0;"><strong style="color:#fff;">Effective date:</strong> <?php echo htmlspecialchars($effective_date, ENT_QUOTES, 'UTF-8'); ?></p>
            <section>
                <h2 class="lp-card-title">Customer Orders</h2>
                <p class="lp-card-text">Customers are responsible for carefully reviewing their order details before submitting an order, including selected products or services, quantities, sizes, submitted notes, designs, and uploaded files.</p>
                <p class="lp-card-text">Once an order has been submitted, customers should review the order information and payment details carefully. Orders may require staff review before production, pricing confirmation, or payment verification.</p>
                <p class="lp-card-text">Customers are responsible for providing accurate information and ensuring that all submitted order details are correct.</p>
            </section>
            <section>
                <h2 class="lp-card-title">Online Payments</h2>
                <p class="lp-card-text">PrintFlow is an <strong style="color:#fff;">online transaction system</strong>. Payments for orders placed through PrintFlow must be completed using the online payment methods made available through the system.</p>
                <p class="lp-card-text"><strong style="color:#fff;">Cash payments are not accepted through PrintFlow for online orders.</strong></p>
                <p class="lp-card-text">Customers are responsible for completing the required online payment and ensuring that payment information is accurate before submitting or confirming an order.</p>
                <p class="lp-card-text">Payment status and payment verification are handled through the PrintFlow system and authorized shop staff.</p>
                <p class="lp-card-text">An order may not proceed to production or fulfillment until the required payment has been successfully completed and verified.</p>
            </section>
            <section>
                <h2 class="lp-card-title">Custom Printing and Design Submissions</h2>
                <p class="lp-card-text">Customers may submit designs, images, references, instructions, and related files for custom print orders.</p>
                <p class="lp-card-text">Customers are responsible for ensuring that submitted files are accurate, readable, appropriate for production, and authorized for their intended use.</p>
                <p class="lp-card-text">PrintFlow or the shop may review submitted materials before production. If submitted materials are unsuitable, incomplete, unclear, or cannot reasonably be produced, the order may require clarification or may be declined.</p>
            </section>
            <section>
                <h2 class="lp-card-title">Order Changes and Cancellations</h2>
                <p class="lp-card-text">Customers should carefully review their order before submitting it.</p>
                <p class="lp-card-text">Requests to change or cancel an order may be reviewed by the shop depending on the current status of the order and whether production or fulfillment has already begun.</p>
                <p class="lp-card-text">Because orders may involve customized products, materials, preparation, or production work, customers should contact the shop as soon as possible if they need to request a change or cancellation.</p>
                <p class="lp-card-text">A cancellation request does not automatically guarantee that an order can be cancelled.</p>
            </section>
            <section>
                <h2 class="lp-card-title">No Refunds</h2>
                <p class="lp-card-text"><strong style="color:#fff;">All payments made through PrintFlow are non-refundable.</strong></p>
                <p class="lp-card-text">PrintFlow and the shop <strong style="color:#fff;">do not provide refunds for completed payments or submitted orders</strong>, including customized printing, design, or other services purchased through the system.</p>
                <p class="lp-card-text">Customers are responsible for reviewing their order details, selected products or services, quantities, designs, uploaded files, and payment information before completing the transaction.</p>
                <p class="lp-card-text">Submitting an order and completing payment confirms the customer's acceptance of the order details and the shop's <strong style="color:#fff;">no-refund policy</strong>.</p>
                <p class="lp-card-text">If a customer encounters an issue with an order, they should contact the shop directly for assistance. Any resolution will be handled according to the shop's applicable policies and does not create an automatic right to a refund.</p>
            </section>
            <section>
                <h2 class="lp-card-title">Pickup and Delivery</h2>
                <p class="lp-card-text">Customers should follow the instructions provided by the shop regarding pickup, delivery, branch selection, and order completion.</p>
                <p class="lp-card-text">Pickup and delivery availability, schedules, charges, and requirements may vary depending on the order and selected branch.</p>
                <p class="lp-card-text">Customers are responsible for providing accurate contact and order information needed for pickup or delivery.</p>
            </section>
            <section>
                <h2 class="lp-card-title">Customer Responsibility</h2>
                <p class="lp-card-text">Customers are responsible for the accuracy of their account information, contact details, order instructions, file uploads, and design approvals.</p>
                <p class="lp-card-text">Incorrect or incomplete information may affect communication, production, payment verification, pickup, or delivery.</p>
                <p class="lp-card-text">Customers are also responsible for reviewing their order before completing the online transaction.</p>
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
                <p class="lp-card-text">Customers must not submit content that is illegal, harmful, fraudulent, abusive, or that they do not have permission to use.</p>
                <p class="lp-card-text">The shop may decline or cancel work that appears inappropriate, unlawful, unauthorized, or unsuitable for production.</p>
            </section>
            <section>
                <h2 class="lp-card-title">Intellectual Property</h2>
                <p class="lp-card-text">Customers retain responsibility for the designs, logos, images, and other materials they submit.</p>
                <p class="lp-card-text">Submitting files to PrintFlow confirms that the customer has the necessary rights or permission to use the submitted materials for the requested printing work.</p>
                <p class="lp-card-text">Customers are responsible for any claims or disputes arising from materials they submit without the necessary rights or authorization.</p>
            </section>
            <section>
                <h2 class="lp-card-title">Account Usage</h2>
                <p class="lp-card-text">Customers are responsible for keeping their login credentials secure and for activity conducted through their account.</p>
                <p class="lp-card-text">Accounts may be used to manage profile information, orders, uploaded files, messages, notifications, payments, and verification steps available through the PrintFlow system.</p>
                <p class="lp-card-text">Customers must not share their account credentials or use another customer's account without authorization.</p>
            </section>
            <section>
                <h2 class="lp-card-title">Changes to These Terms</h2>
                <p class="lp-card-text">These Terms of Service may be updated as the business and PrintFlow system change.</p>
                <p class="lp-card-text">Customers should review the latest version of these Terms when creating an account or placing orders.</p>
                <p class="lp-card-text">Continued use of the PrintFlow system after updated Terms are published may be subject to the updated Terms.</p>
            </section>
        </article>
    </div>
</section>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>