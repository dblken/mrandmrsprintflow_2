<?php
/**
 * Dynamic Service Order Page
 * Uses admin-configured forms when available, falls back to hardcoded forms
 */

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/dynamic_form_helpers.php';

require_role('Customer');
require_once __DIR__ . '/../includes/require_customer_profile_complete.php';

$product_id = $_GET['product_id'] ?? 0;
$product = null;

if ($product_id) {
    $result = db_query("SELECT * FROM products WHERE product_id = ? AND status = 'Activated'", 'i', [$product_id]);
    if (!empty($result)) {
        $product = $result[0];
    }
}

if (!$product) {
    header("Location: products.php");
    exit;
}

// Check if dynamic form exists and is active
$dynamic_form = get_active_service_form($product_id);

// If no dynamic form, redirect to hardcoded form
if (!$dynamic_form) {
    header("Location: order_create.php?product_id=" . $product_id);
    exit;
}

// Load form configuration
$steps = get_form_steps($dynamic_form['config_id']);
$fields = get_form_fields($dynamic_form['config_id']);
$branches = db_query("SELECT id, branch_name FROM branches WHERE status = 'Active'");

// Group fields by step
$fields_by_step = [];
foreach ($fields as $field) {
    $fields_by_step[$field['step_number']][] = $field;
}

$page_title = $product['name'] . ' - PrintFlow';
$use_customer_css = true;
require_once __DIR__ . '/../includes/header.php';
?>

<div class="min-h-screen py-8 bg-gray-50" x-data="dynamicOrderForm()">
    <div class="shopee-layout-container">
        <!-- Breadcrumb -->
        <div class="text-sm text-gray-500 mb-6 flex items-center gap-2">
            <a href="products.php" class="hover:text-blue-600">Products</a>
            <span>/</span>
            <span class="font-semibold text-gray-900"><?= htmlspecialchars($product['name']) ?></span>
        </div>

        <div class="shopee-card">
            <!-- Left: Image -->
            <div class="shopee-image-section">
                <div class="sticky-image-container">
                    <div class="shopee-main-image-wrap">
                        <?php 
                        $display_img = "";
                        if (!empty($product['photo_path'])) {
                            $display_img = $product['photo_path'];
                        } elseif (!empty($product['product_image'])) {
                            $display_img = "/printflow/" . ltrim($product['product_image'], '/');
                        }
                        
                        if ($display_img): ?>
                            <img src="<?= htmlspecialchars($display_img) ?>" alt="<?= htmlspecialchars($product['name']) ?>" class="shopee-main-image">
                        <?php else: ?>
                            <div class="w-full h-full flex items-center justify-center bg-gray-50 text-5xl">📦</div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Right: Form -->
            <div class="shopee-form-section">
                <!-- Product Header -->
                <div class="mb-6 pb-6 border-b border-gray-100">
                    <h1 class="text-2xl font-bold text-gray-900 mb-2"><?= htmlspecialchars($product['name']) ?></h1>
                    <div class="text-3xl font-bold text-blue-600 mb-4">
                        <?= format_currency($product['base_price']) ?>
                    </div>
                    <div class="text-sm text-gray-600 leading-relaxed">
                        <?= nl2br(htmlspecialchars($product['description'])) ?>
                    </div>
                </div>

                <?php if ($product['stock_quantity'] > 0): ?>
                
                <!-- Dynamic Multi-Step Form -->
                <div class="bg-white rounded-xl border border-gray-200 overflow-hidden">
                    <!-- Form Header -->
                    <div class="p-4 border-b border-gray-200 bg-gray-50 flex justify-between items-center">
                        <div>
                            <h2 class="text-lg font-bold text-gray-900 m-0 uppercase tracking-wide">Service Customization</h2>
                            <div class="flex items-center gap-2 mt-1">
                                <span class="bg-gray-900 text-white text-xs font-bold px-2 py-0.5 rounded" x-text="'STEP ' + currentStep"></span>
                                <p class="text-sm text-gray-500 font-semibold uppercase m-0" x-text="stepTitle"></p>
                            </div>
                        </div>
                    </div>

                    <!-- Progress Bar -->
                    <div style="height:4px; width:100%; background:#f1f5f9; display:flex;">
                        <div :style="'width: ' + (currentStep/<?= count($steps) ?>*100) + '%; transition: width 0.4s cubic-bezier(0.4, 0, 0.2, 1);'" style="height:100%; background:black;"></div>
                    </div>

                    <form method="POST" action="process_dynamic_order.php" enctype="multipart/form-data" id="dynamic-form" style="display:flex; flex-direction:column; flex:1;">
                        <?= csrf_field() ?>
                        <input type="hidden" name="product_id" value="<?= $product_id ?>">
                        <input type="hidden" name="config_id" value="<?= $dynamic_form['config_id'] ?>">
                        
                        <!-- Scrollable Form Content -->
                        <div style="padding:2rem; flex:1;">
                            
                            <!-- Branch Selection (Always First) -->
                            <div x-show="currentStep === 1" class="mb-6">
                                <div class="bg-gray-50 p-5 border border-gray-200">
                                    <label class="block text-sm font-bold text-gray-900 mb-3 uppercase">Select Branch *</label>
                                    <select name="branch_id" class="form-input w-full" required>
                                        <option value="" selected disabled>Select Branch</option>
                                        <?php foreach($branches as $b): ?>
                                            <option value="<?= $b['id'] ?>"><?= htmlspecialchars($b['branch_name']) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>

                            <!-- Dynamic Steps -->
                            <?php foreach ($steps as $index => $step): ?>
                            <div x-show="currentStep === <?= $index + 1 ?>" x-transition>
                                <div class="text-center pb-4 mb-6 border-b">
                                    <h3 class="font-bold text-gray-900 text-lg uppercase"><?= htmlspecialchars($step['step_title']) ?></h3>
                                    <?php if ($step['step_description']): ?>
                                    <p class="text-sm text-gray-600 mt-2"><?= htmlspecialchars($step['step_description']) ?></p>
                                    <?php endif; ?>
                                </div>

                                <?php if (isset($fields_by_step[$step['step_number']])): ?>
                                    <?php foreach ($fields_by_step[$step['step_number']] as $field): ?>
                                        <?= render_dynamic_field($field) ?>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </div>
                            <?php endforeach; ?>

                            <!-- Final Review Step -->
                            <div x-show="currentStep === <?= count($steps) + 1 ?>" x-transition>
                                <div class="text-center pb-4 mb-6">
                                    <h3 class="font-bold text-gray-900 text-lg uppercase">Final Review</h3>
                                </div>

                                <div class="bg-gray-50 border border-gray-200 p-6 text-center">
                                    <p class="text-sm text-gray-600 mb-1 font-bold uppercase">Total Amount</p>
                                    <div class="text-3xl font-black text-gray-900 mb-4">
                                        <?= format_currency($product['base_price']) ?>
                                    </div>
                                    
                                    <div class="h-px bg-gray-200 my-4"></div>

                                    <label class="block text-sm font-bold text-gray-900 mb-3 uppercase">Select Quantity</label>
                                    <div class="flex items-center justify-center gap-4">
                                        <button type="button" @click="if(quantity > 1) quantity--" class="w-10 h-10 border border-gray-900 bg-white font-bold hover:bg-gray-900 hover:text-white transition">-</button>
                                        <input type="number" name="quantity" x-model="quantity" min="1" max="<?= $product['stock_quantity'] ?>" class="w-20 h-10 border-2 border-gray-900 text-center font-bold">
                                        <button type="button" @click="if(quantity < <?= $product['stock_quantity'] ?>) quantity++" class="w-10 h-10 border border-gray-900 bg-white font-bold hover:bg-gray-900 hover:text-white transition">+</button>
                                    </div>
                                    <p class="text-xs text-gray-500 mt-2 font-semibold"><?= $product['stock_quantity'] ?> items in stock</p>
                                </div>
                            </div>

                        </div>

                        <!-- Form Footer -->
                        <div class="p-6 border-t border-gray-100 bg-gray-50 flex gap-4">
                            <button type="button" 
                                    x-show="currentStep > 1" 
                                    @click="currentStep--" 
                                    class="shopee-btn-outline flex-1">
                                Back
                            </button>
                            
                            <button type="button" 
                                    x-show="currentStep < <?= count($steps) + 1 ?>" 
                                    @click="nextStep()" 
                                    class="shopee-btn-primary flex-2">
                                Next Step
                            </button>

                            <div x-show="currentStep === <?= count($steps) + 1 ?>" class="flex-2 flex gap-3 w-full">
                                <button type="submit" name="action" value="add_to_cart" class="shopee-btn-outline flex-1">
                                    Add to Cart
                                </button>
                                <button type="submit" name="action" value="buy_now" class="shopee-btn-primary flex-1">
                                    Buy Now
                                </button>
                            </div>
                        </div>
                    </form>
                </div>

                <?php else: ?>
                <div class="p-3 bg-red-50 text-red-700 rounded-lg text-center font-bold">
                    Out of Stock
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<script>
function dynamicOrderForm() {
    const steps = <?= json_encode($steps) ?>;
    const totalSteps = steps.length + 1; // +1 for final review
    
    return {
        currentStep: 1,
        quantity: 1,
        
        get stepTitle() {
            if (this.currentStep === totalSteps) {
                return 'Final Review';
            }
            const step = steps[this.currentStep - 1];
            return step ? step.step_title : '';
        },
        
        nextStep() {
            // Validate current step
            const form = document.getElementById('dynamic-form');
            const currentStepEl = form.querySelector(`[x-show="currentStep === ${this.currentStep}"]`);
            
            if (currentStepEl) {
                const inputs = currentStepEl.querySelectorAll('input[required], select[required], textarea[required]');
                let isValid = true;
                
                inputs.forEach(input => {
                    if (!input.checkValidity()) {
                        input.reportValidity();
                        isValid = false;
                    }
                });
                
                if (!isValid) return;
            }
            
            if (this.currentStep < totalSteps) {
                this.currentStep++;
                window.scrollTo({ top: 0, behavior: 'smooth' });
            }
        }
    }
}
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
