<?php
/**
 * Layout Design Service - Service Order Form
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/service_order_helper.php';
require_role('Customer');
require_once __DIR__ . '/../includes/require_customer_profile_complete.php';
$customer_id = get_user_id();
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $branch_id = trim($_POST['branch_id'] ?? '1');
    $layout_type = trim($_POST['layout_type'] ?? '');
    $rush = trim($_POST['rush'] ?? '');
    $needed_date = trim($_POST['needed_date'] ?? '');
    $description = trim($_POST['description'] ?? '');
    if (empty($layout_type) || empty($needed_date)) {
        $error = 'Please select type of layout and provide needed date.';
    } else {
        $tmp_dir = service_order_temp_dir();
        $tmp_path = null;
        $mime = null;
        $design_name = null;

        if (isset($_FILES['reference_file']) && $_FILES['reference_file']['error'] === UPLOAD_ERR_OK) {
            $valid = service_order_validate_file($_FILES['reference_file']);
            if ($valid['ok']) {
                $db_data = file_get_contents($_FILES['reference_file']['tmp_name']);
                $ext = pathinfo($_FILES['reference_file']['name'], PATHINFO_EXTENSION);
                $tmp_filename = uniqid('layout_') . '.' . $ext;
                $tmp_path = $tmp_dir . DIRECTORY_SEPARATOR . $tmp_filename;
                file_put_contents($tmp_path, $db_data);
                
                $finfo = finfo_open(FILEINFO_MIME_TYPE);
                $mime  = finfo_file($finfo, $_FILES['reference_file']['tmp_name']);
                finfo_close($finfo);
                $design_name = $_FILES['reference_file']['name'];
            }
        }

        if (!isset($_SESSION['cart'])) {
            $_SESSION['cart'] = [];
        }

        $item_key = 'layout_' . time();
        
        $_SESSION['cart'][$item_key] = [
            'product_id'     => 0,
            'source_page'    => 'services',
            'branch_id'      => $branch_id,
            'name'           => 'Layout Design Service',
            'category'       => 'Graphic Design',
            'price'          => 0, // Determined after review
            'quantity'       => 1,
            'image'          => '🎨',
            'customization'  => [
                'Layout_Type' => $layout_type,
                'Rush_Order'  => $rush ?: 'No',
                'needed_date' => $needed_date
            ],
            'design_notes'   => $description,
            'design_tmp_path'=> $tmp_path,
            'design_mime'    => $mime,
            'design_name'    => $design_name,
            'reference_tmp_path' => null,
            'reference_mime'     => null,
            'reference_name'     => null
        ];

        if (($_POST['action'] ?? '') === 'buy_now' || isset($_POST['buy_now'])) {
            redirect(BASE_URL . '/customer/order_review.php?item=' . urlencode($item_key));
        } else {
            redirect(BASE_URL . '/customer/cart.php');
        }
    }
}
$page_title = 'Layout Design Service - PrintFlow';
$use_customer_css = true;
require_once __DIR__ . '/../includes/header.php';

$branches = db_query("SELECT id, branch_name FROM branches WHERE status = 'Active'");
$_svc = db_query("SELECT hero_image FROM services WHERE customer_link LIKE '%order_layout%' LIMIT 1");
$display_img = (!empty($_svc) && !empty($_svc[0]['hero_image'])) ? $_svc[0]['hero_image'] : '';
if ($display_img !== '' && strpos($display_img, 'http') === false && $display_img[0] !== '/') { $display_img = '/' . ltrim($display_img, '/'); }
$layout_types = ['Logo', 'Banner', 'Invitation', 'Poster', 'Other'];
?>
<div class="min-h-screen py-8">
    <div class="shopee-layout-container">
        <!-- Breadcrumb -->
        <div class="text-sm text-gray-500 mb-6 flex items-center gap-2">
            <a href="services.php" class="hover:text-blue-600">Services</a>
            <span>/</span>
            <span class="font-semibold text-gray-900">Layout Design</span>
        </div>

        <div class="shopee-card">
            <!-- Left Side: Image -->
            <div class="shopee-image-section">
                <div class="sticky-image-container">
                    <div class="shopee-main-image-wrap">
                        <img src="<?php echo htmlspecialchars($display_img ?: 'https://placehold.co/600x600/f8fafc/0f172a?text=Layout+Design'); ?>" alt="Layout Design" class="shopee-main-image" onerror="this.src='https://placehold.co/600x600/f8fafc/0f172a?text=Layout+Design'">
                    </div>
                </div>
            </div>

            <!-- Right Side: Form -->
            <div class="shopee-form-section">
                <h1 class="text-2xl font-bold text-gray-900 mb-2">Layout & Graphic Design</h1>
                
                <?php
                $stats = service_order_get_page_stats('order_layout');
                $raw_avg = (float)($stats['avg_rating'] ?? 0);
                $review_count = (int)($stats['review_count'] ?? 0);
                $sold_count = (int)($stats['sold_count'] ?? 0);
                $sold_display = $sold_count >= 1000 ? number_format($sold_count / 1000, 1) . 'k' : $sold_count;
                
                $_s_name = 'PrintFlow Service';
                $_s_row = db_query("SELECT name FROM services WHERE customer_link LIKE '%order_layout%' LIMIT 1");
                if(!empty($_s_row)) { $_s_name = $_s_row[0]['name']; }
                ?>
                <div class="flex items-center gap-4 mb-6 pb-6 border-b border-gray-100">
                    <div class="flex items-center gap-1">
                        <?php for($i=1; $i<=5; $i++): ?>
                            <svg class="w-4 h-4" style="fill: <?php echo ($i <= round($raw_avg)) ? '#FBBF24' : '#E2E8F0'; ?>;" viewBox="0 0 20 20"><path d="M9.049 2.927c.3-.921 1.603-.921 1.902 0l1.07 3.292a1 1 0 00.95.69h3.462c.969 0 1.371 1.24.588 1.81l-2.8 2.034a1 1 0 00-.364 1.118l1.07 3.292c.3.921-.755 1.688-1.54 1.118l-2.8-2.034a1 1 0 00-1.176 0l-2.8 2.034c-.784.57-1.838-.197-1.539-1.118l1.07-3.292a1 1 0 00-.364-1.118L2.98 8.72c-.783-.57-.38-1.81.588-1.81h3.461a1 1 0 00.951-.69l1.07-3.292z"/></svg>
                        <?php endfor; ?>
                        
                        <?php if ($review_count > 0): ?>
                            <a href="reviews.php?service=<?php echo urlencode($_s_name); ?>" class="text-sm text-gray-500 hover:text-blue-500 hover:underline ml-1 cursor-pointer">(<?php echo number_format($review_count); ?> Reviews)</a>
                        <?php endif; ?>
                    </div>
                    <div class="h-4 w-px bg-gray-200"></div>
                    <div class="text-sm text-gray-500"><?php echo $sold_display; ?> Designed</div>
                </div>

                <?php if ($error): ?><div class="bg-red-50 border border-red-200 text-red-700 px-4 py-3 rounded-lg mb-6"><?php echo htmlspecialchars($error); ?></div><?php endif; ?>

                <form method="POST" enctype="multipart/form-data" id="layoutForm" novalidate>
                    <?php echo csrf_field(); ?>

                    <div class="shopee-form-row">
                        <label class="shopee-form-label">Branch *</label>
                        <select name="branch_id" class="input-field shopee-form-field" required>
                            <?php foreach($branches as $b): ?>
                                <option value="<?php echo $b['id']; ?>"><?php echo htmlspecialchars($b['branch_name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="shopee-form-row">
                        <label class="shopee-form-label pt-2">Type of Layout *</label>
                        <div class="shopee-opt-group shopee-form-field">
                            <?php foreach ($layout_types as $lt): ?>
                            <label class="shopee-opt-btn"><input type="radio" name="layout_type" value="<?php echo htmlspecialchars($lt); ?>" required style="display:none;" onchange="layoutUpdateOpt(this)" <?php echo (($_POST['layout_type'] ?? '') === $lt) ? 'checked' : ''; ?>> <span><?php echo htmlspecialchars($lt); ?></span></label>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <div class="shopee-form-row">
                        <label class="shopee-form-label pt-2">Rush Order? *</label>
                        <div class="shopee-opt-group shopee-form-field">
                            <label class="shopee-opt-btn"><input type="radio" name="rush" value="No" required style="display:none;" onchange="layoutUpdateOpt(this)" <?php echo (($_POST['rush'] ?? 'No') === 'No') ? 'checked' : ''; ?>> <span>No</span></label>
                            <label class="shopee-opt-btn"><input type="radio" name="rush" value="Yes" style="display:none;" onchange="layoutUpdateOpt(this)" <?php echo (($_POST['rush'] ?? '') === 'Yes') ? 'checked' : ''; ?>> <span>Yes (+Fee)</span></label>
                        </div>
                    </div>

                    <div class="shopee-form-row pt-4 border-t border-gray-50">
                        <label class="shopee-form-label pt-2">Needed Date *</label>
                        <div class="shopee-form-field">
                            <div class="w-1/2">
                                <input type="date" name="needed_date" id="layout_needed_date" class="input-field shopee-form-field" required min="<?php echo date('Y-m-d'); ?>" value="<?php echo htmlspecialchars($_POST['needed_date'] ?? ''); ?>">
                            </div>
                        </div>
                    </div>

                    <div class="shopee-form-row">
                        <label class="shopee-form-label">Description</label>
                        <textarea name="description" rows="4" class="input-field shopee-form-field" placeholder="Describe your layout needs, text to include, preferred colors..."><?php echo htmlspecialchars($_POST['description'] ?? ''); ?></textarea>
                    </div>

                    <div class="shopee-form-row">
                        <label class="shopee-form-label">Reference <span class="text-xs text-gray-400 font-normal">(Optional)</span></label>
                        <input type="file" name="reference_file" accept=".jpg,.jpeg,.png,.pdf" class="input-field shopee-form-field">
                    </div>

                    <div class="shopee-form-row pt-8">
                        <div style="width: 130px;"></div>
                        <div class="flex gap-4 flex-1">
                            <a href="<?php echo BASE_URL; ?>/customer/services.php" class="shopee-btn-outline" style="flex:1;">Back</a>
                            <button type="submit" name="action" value="add_to_cart" class="shopee-btn-outline" style="flex:1;">Add to Cart</button>
                            <button type="submit" name="action" value="buy_now" class="shopee-btn-primary" style="flex:1.5;">Buy Now</button>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<script>
function layoutUpdateOpt(input) {
    const name = input.name;
    document.querySelectorAll(`input[name="${name}"]`).forEach(function(r) {
        const wrap = r.closest('.shopee-opt-btn');
        if (wrap) wrap.classList.toggle('active', r.checked);
    });
}

document.addEventListener('DOMContentLoaded', function() {
    document.querySelectorAll('#layoutForm .shopee-opt-btn').forEach(function(w) {
        if (w.querySelector('input:checked')) w.classList.add('active');
    });
});
</script>

<style>
/* Any layout specific styles can go here */
</style>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
