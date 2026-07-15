<?php
/**
 * Customer Customization Payment Page
 * PrintFlow - Printing Shop PWA
 */

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/runtime_config.php';
require_once __DIR__ . '/../includes/payment_verification.php';

require_role('Customer');

$job_id = (int)($_GET['id'] ?? 0);
$customer_id = get_customer_id();

// Get customization
$job = db_query("SELECT * FROM job_orders WHERE id = ? AND customer_id = ?", 'ii', [$job_id, $customer_id]);

if (empty($job)) {
    redirect('/printflow/customer/services.php');
}

$job = $job[0];

// Only allow payment if UNPAID or PARTIAL, and not fully completed
if (in_array($job['payment_proof_status'], ['VERIFIED']) && $job['payment_status'] === 'PAID') {
    redirect('/printflow/customer/services.php');
}

$success = '';
$error = '';

// Calculate balances
$estimated_total = (float)$job['estimated_total'];
$amount_paid = (float)$job['amount_paid'];
$required_payment = (float)$job['required_payment'];
$remaining_balance = $estimated_total - $amount_paid;
$remaining_required = max(0, $required_payment - $amount_paid);

// Handle payment confirmation upload
if ($_SERVER['REQUEST_METHOD'] === 'POST' && verify_csrf_token($_POST['csrf_token'] ?? '')) {
    $payment_method = sanitize($_POST['payment_method']);
    $reference_number = sanitize($_POST['reference_number']);
    $amount_submitted = (float)$_POST['amount_submitted'];
    
    // Validation
    if ($amount_submitted <= 0) {
        $error = 'Submitted amount must be greater than zero.';
    } elseif ($amount_submitted > $remaining_balance) {
        $error = 'Submitted amount cannot exceed the remaining balance.';
    } else {
        // Handle file upload
        if (isset($_FILES['proof_of_payment']) && $_FILES['proof_of_payment']['error'] === 0) {
            
            // Hardened upload rules
            $allowed_exts = ['jpg', 'jpeg', 'png', 'webp', 'pdf'];
            
            // Re-verify MIME using finfo just in case
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $mime_type = finfo_file($finfo, $_FILES['proof_of_payment']['tmp_name']);
            finfo_close($finfo);
            
            $allowed_mimes = ['image/jpeg', 'image/png', 'image/webp', 'application/pdf'];
            
            if (!in_array($mime_type, $allowed_mimes)) {
                $error = 'Security Error: Attempted to upload invalid file type.';
            } else {
                // We use the secure_payments destination which is protected by .htaccess
                $upload_result = upload_file($_FILES['proof_of_payment'], $allowed_exts, 'secure_payments');
                
                if ($upload_result['success']) {
                    // Extract just the basename instead of full URL path so it is safe in DB for the API reader
                    $file_name = basename($upload_result['file_path']);
                    
                    error_log('[Payment Upload] order_id=' . (int)($job['order_id'] ?? 0));
                    error_log('[Payment Upload] customer_id=' . (int)$customer_id);
                    error_log('[Payment Upload] file_saved=' . $file_name);

                    $update_success = db_execute("UPDATE job_orders SET
                                payment_proof_status = 'SUBMITTED',
                                payment_proof_path = ?,
                                payment_method = ?,
                                payment_reference = ?,
                                payment_submitted_amount = ?,
                                payment_proof_uploaded_at = NOW()
                                WHERE id = ?",
                        'sssdi', [$file_name, $payment_method, $reference_number, $amount_submitted, $job_id]);

                    if (!$update_success) {
                        $error = 'Payment proof was saved, but the order payment state could not be updated. Please try again.';
                        payment_verification_log('job_payment_update_failed', ['job_order_id' => $job_id, 'customer_id' => $customer_id]);
                    } else {
                        $receiptLocal = payment_verification_local_file($file_name);
                        $submission_id = payment_verification_create_submission([
                            'order_id' => (int)($job['order_id'] ?? 0),
                            'job_order_id' => $job_id,
                            'customer_id' => $customer_id,
                            'branch_id' => (int)($job['branch_id'] ?? 0),
                            'receipt_file' => $file_name,
                            'receipt_storage_path' => 'secure_payments/' . $file_name,
                            'receipt_url' => payment_verification_proof_url($file_name),
                            'receipt_original_name' => (string)($_FILES['proof_of_payment']['name'] ?? ''),
                            'receipt_mime' => $mime_type,
                            'receipt_size' => (int)($_FILES['proof_of_payment']['size'] ?? 0),
                            'receipt_sha256' => $receiptLocal ? (string)(@hash_file('sha256', $receiptLocal) ?: '') : '',
                            'selected_payment_method' => $payment_method,
                            'submitted_amount' => $amount_submitted,
                            'verification_status' => 'Pending Review',
                            'ocr_status' => 'Pending',
                        ]);

                        error_log('[Payment Upload] payment_record_id=' . (int)$submission_id);
                        if ($submission_id <= 0) {
                            $error = 'Payment proof was saved, but the verification queue record could not be created. Please try again.';
                            payment_verification_log('job_payment_submission_create_failed', ['job_order_id' => $job_id, 'customer_id' => $customer_id]);
                        } else {
                            payment_verification_notify_reviewers((int)($job['order_id'] ?? 0), $job_id, $submission_id);
                            $success = 'Payment proof uploaded successfully! Our staff will verify it shortly.';
                            // Refresh job data
                            $job = db_query("SELECT * FROM job_orders WHERE id = ?", 'i', [$job_id])[0];
                        }
                    }
                } else {
                    $error = $upload_result['error'];
                }
            }
        } else {
            $error = 'Please select a valid file to upload as proof of payment.';
        }
    }
}

$page_title = 'Customization Payment - PrintFlow';
$use_customer_css = true;
require_once __DIR__ . '/../includes/header.php';
?>

<div class="min-h-screen bg-gray-50 py-8">
    <div class="container mx-auto px-4 max-w-2xl">
        <h1 class="text-3xl font-bold mb-6">Payment Proof Upload</h1>

        <?php if ($success): ?>
            <div class="bg-green-50 border border-green-200 text-green-700 px-4 py-3 rounded-lg mb-4">
                <?php echo htmlspecialchars($success); ?>
            </div>
            <div class="mb-4">
                <a href="services.php" class="btn-primary w-full text-center block">Return to Services</a>
            </div>
        <?php else: ?>

            <?php if ($error): ?>
                <div class="bg-red-50 border border-red-200 text-red-700 px-4 py-3 rounded-lg mb-4">
                    <?php echo htmlspecialchars($error); ?>
                </div>
            <?php endif; ?>

            <?php if ($job['payment_proof_status'] === 'REJECTED'): ?>
                <div class="bg-red-50 border-l-4 border-red-500 text-red-700 p-4 mb-6" role="alert">
                    <p class="font-bold">Previous Upload Rejected</p>
                    <p><?php echo htmlspecialchars($job['payment_rejection_reason']); ?></p>
                    <p class="text-sm mt-2">Please upload a clearer or corrected proof of payment.</p>
                </div>
            <?php endif; ?>
            
            <?php if ($job['status'] !== 'TO_PAY' && $job['status'] !== 'IN_PRODUCTION' && $job['status'] !== 'COMPLETED' && $job['payment_proof_status'] !== 'SUBMITTED'): ?>
                <div class="bg-amber-50 border-l-4 border-amber-500 text-amber-800 p-6 mb-6 rounded-lg shadow-sm">
                    <div class="flex items-start gap-4">
                        <svg class="w-8 h-8 text-amber-500 shrink-0 mt-1" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                        <div>
                            <h2 class="font-bold text-lg mb-2">Waiting for Staff Review</h2>
                            <p class="text-amber-700">The final price and payment options will be available once the staff reviews and approves your order specifications.</p>
                            <p class="text-sm mt-3 font-semibold">You will be notified when your order is ready for payment.</p>
                        </div>
                    </div>
                </div>
            <?php else: ?>

            <?php if ($job['payment_proof_status'] === 'SUBMITTED'): ?>
                <div class="bg-blue-50 border-l-4 border-blue-500 text-blue-700 p-4 mb-6" role="alert">
                    <p class="font-bold">Proof Submitted</p>
                    <p>Your payment proof is currently under review by our staff.</p>
                    <!-- Provide a button to just go back, though they can still re-upload if they want -->
                    <a href="services.php" class="text-blue-800 underline text-sm mt-2 inline-block">Return to Services</a>
                </div>
            <?php endif; ?>

            <!-- Customization Summary -->
            <div class="card mb-6">
                <h2 class="text-xl font-bold mb-4">Customization #<?php echo $job['id']; ?> - <?php echo htmlspecialchars($job['service_type']); ?></h2>
                <div class="grid grid-cols-2 md:grid-cols-4 gap-4 text-sm mb-4">
                    <div>
                        <p class="text-gray-500 text-xs uppercase tracking-wider">Total Est.</p>
                        <p class="text-lg font-bold text-gray-900"><?php echo format_currency($estimated_total); ?></p>
                    </div>
                    <div>
                        <p class="text-gray-500 text-xs uppercase tracking-wider">Required</p>
                        <p class="text-lg font-bold text-indigo-600"><?php echo format_currency($required_payment); ?></p>
                    </div>
                    <div>
                        <p class="text-gray-500 text-xs uppercase tracking-wider">Paid</p>
                        <p class="text-lg font-bold text-green-600"><?php echo format_currency($amount_paid); ?></p>
                    </div>
                    <div>
                        <p class="text-gray-500 text-xs uppercase tracking-wider">Remaining</p>
                        <p class="text-lg font-bold text-red-600"><?php echo format_currency($remaining_balance); ?></p>
                    </div>
                </div>
                
                <?php if ($remaining_required > 0): ?>
                    <div class="bg-yellow-50 text-yellow-800 text-xs px-3 py-2 rounded border border-yellow-200">
                        <span class="font-bold">Note:</span> You must pay at least <strong><?php echo format_currency($remaining_required); ?></strong> to start production!
                    </div>
                <?php endif; ?>
            </div>

            <!-- Payment Instructions -->
            <?php
            $pm_path = __DIR__ . '/../public/assets/uploads/qr/payment_methods.json';
            $payment_methods = printflow_load_runtime_config('payment_methods', $pm_path);
            $active_pms = array_filter($payment_methods ?: [], fn($p) => !empty($p['enabled']));
            if (!empty($active_pms)):
            ?>
            <div class="card mb-6">
                <h3 class="text-lg font-bold mb-4">Available Payment Methods</h3>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <?php foreach ($active_pms as $pm): ?>
                    <div class="border rounded-lg p-4 flex gap-4 items-center bg-gray-50">
                        <?php if (!empty($pm['file'])): ?>
                        <img src="<?php echo (defined('BASE_PATH') ? BASE_PATH : ''); ?>/public/assets/uploads/qr/<?php echo htmlspecialchars($pm['file']); ?>?t=<?php echo time(); ?>" alt="QR" class="w-20 h-20 object-contain rounded border bg-white p-1 shadow-sm">
                        <?php endif; ?>
                        <div>
                            <p class="font-bold text-gray-900 leading-tight"><?php echo htmlspecialchars($pm['provider']); ?></p>
                            <p class="text-sm text-gray-700 mb-1"><?php echo htmlspecialchars($pm['label']); ?></p>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>

            <!-- Payment Form -->
            <div class="card">
                <h3 class="text-lg font-bold mb-4">Submit Payment Details</h3>
                
                <form method="POST" enctype="multipart/form-data" id="paymentForm">
                    <?php echo csrf_field(); ?>
                    
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
                        <div>
                            <label class="block text-sm font-medium mb-2">Amount Paid (?) *</label>
                            <input type="number" step="0.01" max="<?php echo $remaining_balance; ?>" name="amount_submitted" class="input-field font-bold text-lg" value="<?php echo htmlspecialchars($_POST['amount_submitted'] ?? ($remaining_required > 0 ? $remaining_required : $remaining_balance)); ?>" required>
                        </div>
                        <div>
                            <label class="block text-sm font-medium mb-2">Payment Method *</label>
                            <select name="payment_method" class="input-field" required>
                                <option value="">Select Method</option>
                                <?php foreach ($active_pms as $pm): ?>
                                    <option value="<?php echo htmlspecialchars($pm['provider']); ?>"><?php echo htmlspecialchars($pm['provider']); ?></option>
                                <?php endforeach; ?>
                                <option value="Bank Transfer">Bank Transfer</option>
                                <option value="Cash">Cash Hand-in</option>
                            </select>
                        </div>
                    </div>
                    
                    <div class="mb-4">
                        <label class="block text-sm font-medium mb-2">Reference / Tracking Number</label>
                        <input type="text" name="reference_number" class="input-field" placeholder="e.g. 10002934823" value="<?php echo htmlspecialchars($_POST['reference_number'] ?? ''); ?>">
                        <p class="text-xs text-gray-500 mt-1">Please include the reference number from your GCash/Bank receipt if applicable.</p>
                    </div>
                    
                    <div class="mb-6">
                        <label class="block text-sm font-medium mb-2">Upload Proof of Payment *</label>
                        <div class="border-2 border-dashed border-gray-300 rounded-lg p-6 text-center bg-gray-50 hover:bg-gray-100 transition-colors">
                            <input type="file" name="proof_of_payment" id="proof_file" class="hidden" accept="image/jpeg,image/png,image/webp,application/pdf" required>
                            <label for="proof_file" class="cursor-pointer cursor-block">
                                <svg class="w-10 h-10 text-gray-400 mx-auto mb-2" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L8 8m4-4v12"></path></svg>
                                <span class="text-indigo-600 font-medium cursor-pointer">Click to browse</span> or drag and drop
                                <p class="text-xs text-gray-500 mt-1">Accepted: JPG, PNG, WEBP, PDF (Max 5MB)</p>
                            </label>
                            <div id="file-name-display" class="mt-3 text-sm font-semibold text-gray-700 hidden"></div>
                        </div>
                    </div>
                    
                    <button type="submit" id="submitBtn" class="btn-primary w-full text-lg py-3">Submit Proof</button>
                    
                    <?php if ($remaining_required > 0): ?>
                    <p id="warning-text" class="text-xs text-red-600 mt-3 text-center hidden">
                        Warning: The amount you entered is less than the required downpayment. Your order might not begin production.
                    </p>
                    <?php endif; ?>
                </form>
            </div>
            
            <?php endif; // End of gating check ?>

            <div class="mt-4 text-center">
                <a href="services.php" class="text-indigo-600 hover:text-indigo-700 font-medium">Back to Services</a>
            </div>
            
        <?php endif; ?>
    </div>
</div>

<script>
    document.addEventListener('DOMContentLoaded', () => {
        const fileInput = document.getElementById('proof_file');
        const fileNameDisplay = document.getElementById('file-name-display');
        const amountInput = document.querySelector('input[name="amount_submitted"]');
        const requiredAmount = <?php echo $remaining_required; ?>;
        const warningText = document.getElementById('warning-text');
        
        if (fileInput) {
            fileInput.addEventListener('change', function() {
                if (this.files && this.files.length > 0) {
                    fileNameDisplay.textContent = 'Selected file: ' + this.files[0].name;
                    fileNameDisplay.classList.remove('hidden');
                } else {
                    fileNameDisplay.classList.add('hidden');
                }
            });
        }
        
        if (amountInput && requiredAmount > 0) {
            amountInput.addEventListener('input', function() {
                const val = parseFloat(this.value) || 0;
                if (val < requiredAmount && val > 0) {
                    warningText.classList.remove('hidden');
                } else {
                    warningText.classList.add('hidden');
                }
            });
        }
    });
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>

