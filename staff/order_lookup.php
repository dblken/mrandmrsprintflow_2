<?php

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/branch_context.php';

require_role(['Staff', 'Manager', 'Admin']);
if (in_array((string)get_user_type(), ['Staff', 'Manager'], true)) {
    require_once __DIR__ . '/../includes/staff_pending_check.php';
}

$base_path = defined('BASE_PATH') ? BASE_PATH : '/printflow';
$userType = (string)get_user_type();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="turbo-visit-control" content="reload">
    <title>Receipt Order Lookup</title>
    <link rel="stylesheet" href="<?php echo htmlspecialchars($base_path . '/public/assets/css/output.css'); ?>">
    <?php include __DIR__ . '/../includes/admin_style.php'; ?>
    <style>
        .receipt-lookup-shell { max-width: 720px; margin: 40px auto; padding: 0 20px; }
        .receipt-lookup-card { background: #fff; border: 1px solid #e5e7eb; border-radius: 18px; padding: 32px; box-shadow: 0 18px 45px rgba(15, 23, 42, .08); }
        .receipt-lookup-icon { width: 58px; height: 58px; display: grid; place-items: center; border-radius: 16px; background: #eaf2ff; color: #1d4ed8; margin-bottom: 18px; }
        .receipt-lookup-card h1 { margin: 0 0 8px; color: #172033; font-size: 26px; }
        .receipt-lookup-card p { margin: 0; color: #64748b; line-height: 1.6; }
        .receipt-lookup-form { margin-top: 26px; }
        .receipt-lookup-label { display: block; margin-bottom: 8px; color: #334155; font-weight: 700; font-size: 14px; }
        .receipt-lookup-row { display: flex; gap: 10px; }
        .receipt-lookup-input { flex: 1; min-width: 0; border: 2px solid #cbd5e1; border-radius: 12px; padding: 14px 16px; font: inherit; letter-spacing: .02em; text-transform: uppercase; }
        .receipt-lookup-input:focus { outline: none; border-color: #2563eb; box-shadow: 0 0 0 4px rgba(37, 99, 235, .12); }
        .receipt-lookup-button { border: 0; border-radius: 12px; padding: 0 22px; background: #2451a6; color: #fff; font-weight: 700; cursor: pointer; }
        .receipt-lookup-button:disabled { opacity: .6; cursor: wait; }
        .receipt-lookup-message { display: none; margin-top: 16px; padding: 13px 15px; border-radius: 10px; font-size: 14px; line-height: 1.45; }
        .receipt-lookup-message.error { display: block; background: #fef2f2; color: #991b1b; border: 1px solid #fecaca; }
        .receipt-lookup-message.success { display: block; background: #ecfdf5; color: #166534; border: 1px solid #bbf7d0; }
        .receipt-lookup-help { margin-top: 18px !important; font-size: 13px; }
        @media (max-width: 640px) { .receipt-lookup-card { padding: 24px 18px; } .receipt-lookup-row { flex-direction: column; } .receipt-lookup-button { min-height: 48px; } }
    </style>
</head>
<body>
<?php
if ($userType === 'Admin') {
    include __DIR__ . '/../includes/admin_sidebar.php';
} elseif ($userType === 'Manager') {
    include __DIR__ . '/../includes/manager_sidebar.php';
} else {
    include __DIR__ . '/../includes/staff_sidebar.php';
}
?>
<main class="main-content">
    <div class="receipt-lookup-shell">
        <section class="receipt-lookup-card" aria-labelledby="lookup-heading">
            <div class="receipt-lookup-icon" aria-hidden="true">
                <svg width="30" height="30" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 5a2 2 0 012-2h3M3 19a2 2 0 002 2h3M21 5a2 2 0 00-2-2h-3m5 16a2 2 0 01-2 2h-3M7 7h3v3H7V7zm7 0h3v3h-3V7zM7 14h3v3H7v-3zm7 0h1m2 0v3h-3"/></svg>
            </div>
            <h1 id="lookup-heading">Scan receipt QR</h1>
            <p>Scan a customer receipt with a USB scanner, or enter its Receipt No. or visible order code. The existing order will open in the correct staff module.</p>
            <form id="receipt-lookup-form" class="receipt-lookup-form" novalidate>
                <label class="receipt-lookup-label" for="receipt-lookup-input">Receipt QR / order identifier</label>
                <div class="receipt-lookup-row">
                    <input id="receipt-lookup-input" class="receipt-lookup-input" type="text" maxlength="120" autocomplete="off" spellcheck="false" autofocus placeholder="Scan now, then press Enter">
                    <button id="receipt-lookup-button" class="receipt-lookup-button" type="submit">Find Order</button>
                </div>
                <div id="receipt-lookup-message" class="receipt-lookup-message" role="status" aria-live="polite"></div>
            </form>
            <p class="receipt-lookup-help">This is lookup-only. It will not create a sale, payment, receipt, or customization.</p>
        </section>
    </div>
</main>
<script>
(function () {
    const form = document.getElementById('receipt-lookup-form');
    const input = document.getElementById('receipt-lookup-input');
    const button = document.getElementById('receipt-lookup-button');
    const message = document.getElementById('receipt-lookup-message');
    const endpoint = <?php echo json_encode(rtrim($base_path, '/') . '/staff/api/order_receipt_lookup.php'); ?>;

    function showMessage(text, type) {
        message.textContent = text;
        message.className = 'receipt-lookup-message ' + type;
    }

    form.addEventListener('submit', async function (event) {
        event.preventDefault();
        const identifier = String(input.value || '').trim();
        if (!identifier) {
            showMessage('Scan the receipt QR or enter an order identifier first.', 'error');
            input.focus();
            return;
        }
        button.disabled = true;
        input.disabled = true;
        showMessage('Looking up the existing order…', 'success');
        try {
            const response = await fetch(endpoint + '?identifier=' + encodeURIComponent(identifier) + '&_=' + Date.now(), {
                credentials: 'same-origin',
                cache: 'no-store',
                headers: { 'Accept': 'application/json' }
            });
            const data = await response.json().catch(function () { return {}; });
            if (!response.ok || !data.success || !data.route) {
                throw new Error(data.message || 'Order lookup failed. Please try again.');
            }
            showMessage(data.warning || ('Order ' + data.identifier + ' found. Opening now…'), 'success');
            window.setTimeout(function () { window.location.assign(data.route); }, data.warning ? 900 : 150);
        } catch (error) {
            showMessage(error && error.message ? error.message : 'Network error. Please try again.', 'error');
            button.disabled = false;
            input.disabled = false;
            input.select();
        }
    });

    window.addEventListener('pageshow', function () {
        input.disabled = false;
        button.disabled = false;
        input.focus();
    });
})();
</script>
</body>
</html>
