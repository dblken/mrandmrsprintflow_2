<?php
/**
 * Customer Payment Confirmation Page
 * PrintFlow - Printing Shop PWA
 */

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

require_role('Customer');

$order_id = (int)($_GET['order_id'] ?? 0);
$customer_id = get_customer_id();

// Get order
$order = db_query("SELECT * FROM orders WHERE order_id = ? AND customer_id = ?", 'ii', [$order_id, $customer_id]);

if (empty($order)) {
    redirect('/printflow/customer/orders.php');
}

$order = $order[0];
$success = '';
$error = '';

// Handle payment confirmation upload
if ($_SERVER['REQUEST_METHOD'] === 'POST' && verify_csrf_token($_POST['csrf_token'] ?? '')) {
    $payment_method = sanitize($_POST['payment_method']);
    $reference_number = sanitize($_POST['reference_number']);
    
    // Handle file upload
    if (isset($_FILES['proof_of_payment']) && $_FILES['proof_of_payment']['error'] === 0) {
        $upload_result = upload_file($_FILES['proof_of_payment'], ['jpg', 'jpeg', 'png', 'pdf'], 'payments');
        
        if ($upload_result['success']) {
            $file_path = $upload_result['file_path'];
            
            db_execute("UPDATE orders SET status = 'To Verify', payment_status = 'Unpaid', payment_method = ?, payment_reference = ?, payment_proof_path = ?, updated_at = NOW() WHERE order_id = ?",
                'sssi', [$payment_method, $reference_number, $file_path, $order_id]);
            
            if (db_table_has_column('orders', 'payment_proof_needs_resubmit')) {
                db_execute('UPDATE orders SET payment_proof_needs_resubmit = 0 WHERE order_id = ?', 'i', [$order_id]);
            }
            
            notify_shop_users("Payment proof uploaded for Order #{$order_id}", 'Payment', true, false, $order_id);
            
            $success = 'Payment proof uploaded successfully! Admin will verify shortly.';
        } else {
            $error = $upload_result['error'];
        }
    } else {
        $error = 'Please upload payment proof';
    }
}

$page_title = 'Payment Confirmation - PrintFlow';
$use_customer_css = true;
require_once __DIR__ . '/../includes/header.php';
?>

<div class="min-h-screen bg-gray-50 py-8">
    <div class="container mx-auto px-4 max-w-2xl">
        <h1 class="text-3xl font-bold mb-6">Payment Confirmation</h1>

        <?php if ($success): ?>
            <div class="bg-green-50 border border-green-200 text-green-700 px-4 py-3 rounded-lg mb-4">
                <?php echo htmlspecialchars($success); ?>
            </div>
        <?php endif; ?>

        <?php if ($error): ?>
            <div class="bg-red-50 border border-red-200 text-red-700 px-4 py-3 rounded-lg mb-4">
                <?php echo htmlspecialchars($error); ?>
            </div>
        <?php endif; ?>

        <!-- Order Summary -->
        <div class="card mb-6">
            <h2 class="text-xl font-bold mb-4">Order #<?php echo $order_id; ?></h2>
            <div class="grid grid-cols-2 gap-4 text-sm">
                <div>
                    <p class="text-gray-600">Total Amount</p>
                    <p class="text-2xl font-bold text-indigo-600"><?php echo format_currency($order['total_amount']); ?></p>
                </div>
                <div>
                    <p class="text-gray-600">Payment Status</p>
                    <p><?php echo status_badge($order['payment_status'], 'payment'); ?></p>
                </div>
            </div>
        </div>

        <!-- Payment Form -->
        <div class="card">
            <h3 class="text-lg font-bold mb-4">Upload Payment Proof</h3>
            
<form method="POST" enctype="multipart/form-data">
                <?php echo csrf_field(); ?>
                
                <div class="mb-4">
                    <label class="block text-sm font-medium mb-2">Payment Method *</label>
                    <select name="payment_method" class="input-field" required>
                        <option value="">Select Payment Method</option>
                        <option value="GCash">GCash</option>
                        <option value="Maya">Maya (Paymaya)</option>
                        <option value="Bank Transfer">Bank Transfer</option>
                        <option value="Cash">Cash</option>
                    </select>
                </div>
                
                <div class="mb-4">
                    <label class="block text-sm font-medium mb-2">Reference Number</label>
                    <input type="text" name="reference_number" class="input-field" placeholder="Transaction/Reference Number">
                </div>
                
                <div class="mb-6">
                    <label class="block text-sm font-medium mb-2">Upload Proof of Payment *</label>
                    <input type="file" name="proof_of_payment" class="input-field" accept="image/*,.pdf" required>
                    <p class="text-xs text-gray-500 mt-1">Accepted: JPG, PNG, PDF (Max 5MB)</p>
                </div>
                
                <button type="submit" class="btn-primary w-full">Submit Payment Confirmation</button>
            </form>
        </div>

        <div class="mt-4">
            <a href="orders.php" class="text-indigo-600 hover:text-indigo-700">← Back to Orders</a>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
