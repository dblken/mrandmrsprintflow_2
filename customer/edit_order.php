<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/revision_workflow.php';
require_once __DIR__ . '/../includes/service_field_config_helper.php';
require_once __DIR__ . '/../includes/product_field_config_helper.php';

require_role('Customer');

$order_id = (int)($_GET['order_id'] ?? $_POST['order_id'] ?? 0);
$customer_id = (int)get_user_id();
if ($order_id <= 0) {
    redirect('orders.php');
}

$orderRows = db_query(
    "SELECT o.*, b.branch_name
     FROM orders o
     LEFT JOIN branches b ON b.branch_id = o.branch_id
     WHERE o.order_id = ? AND o.customer_id = ? LIMIT 1",
    'ii',
    [$order_id, $customer_id]
) ?: [];
if (empty($orderRows)) {
    redirect('orders.php');
}
$order = $orderRows[0];
$revision = printflow_revision_get_active_or_legacy($order_id, $customer_id);
if (($order['status'] ?? '') !== 'For Revision' || $revision === null) {
    $_SESSION['error'] = 'This order does not have an active revision request.';
    redirect('orders.php');
}
$permissions = $revision['permitted_fields_array'];

$editSpecSelect = printflow_revision_column_exists('order_items', 'specifications')
    ? 'oi.specifications'
    : "'' AS specifications";
$items = db_query(
    "SELECT oi.order_item_id, oi.order_id, oi.product_id, oi.quantity, oi.customization_data,
            {$editSpecSelect}, oi.design_image_name, oi.design_image_mime, oi.design_file,
            p.name AS product_name, p.category AS product_category
     FROM order_items oi
     LEFT JOIN products p ON p.product_id = oi.product_id
     WHERE oi.order_id = ? ORDER BY oi.order_item_id ASC",
    'i',
    [$order_id]
) ?: [];

function customer_revision_item_custom(array $item): array
{
    $custom = printflow_revision_decode_json($item['customization_data'] ?? '');
    $specs = printflow_revision_decode_json($item['specifications'] ?? '');
    foreach ($specs as $key => $value) {
        if ($value !== '' && $value !== null) {
            $custom[$key] = $value;
        }
    }
    return $custom;
}

function customer_revision_item_name(array $item, array $custom): string
{
    $name = trim((string)($custom['service_type'] ?? $item['product_name'] ?? ''));
    return $name !== '' ? $name : 'Order Item';
}

function customer_revision_field_label(string $key, array $config = []): string
{
    $configured = trim((string)($config['label'] ?? ''));
    return $configured !== '' ? $configured : ucwords(str_replace(['_', '-'], ' ', $key));
}

function customer_revision_field_config(array $item, array $custom, string $key): array
{
    $serviceId = (int)($custom['service_id'] ?? 0);
    if ($serviceId > 0) {
        $configs = get_service_field_config($serviceId);
        if (isset($configs[$key]) && is_array($configs[$key])) {
            return $configs[$key];
        }
    }
    $productId = (int)($item['product_id'] ?? 0);
    if ($productId > 0) {
        $configs = get_product_field_config($productId);
        if (isset($configs[$key]) && is_array($configs[$key])) {
            return $configs[$key];
        }
    }
    return [];
}

function customer_revision_scalar_text($value): string
{
    if (is_bool($value)) return $value ? 'Yes' : 'No';
    if (is_array($value)) {
        $flat = [];
        array_walk_recursive($value, static function ($entry) use (&$flat): void {
            if (is_scalar($entry)) $flat[] = (string)$entry;
        });
        return implode(', ', $flat);
    }
    return trim((string)$value);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['resubmit_order'])) {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $error = 'Invalid security token. Please refresh and try again.';
    } else {
        try {
            $result = printflow_revision_submit($order_id, $customer_id, $_POST, $_FILES);
            log_activity($customer_id, 'Order Resubmitted', "Customer resubmitted Order #{$order_id} for staff review.");

            $customerRows = db_query('SELECT first_name, last_name FROM customers WHERE customer_id = ? LIMIT 1', 'i', [$customer_id]) ?: [];
            $customerName = trim((string)($customerRows[0]['first_name'] ?? '') . ' ' . (string)($customerRows[0]['last_name'] ?? ''));
            if ($customerName === '') $customerName = 'Customer';
            $staffId = (int)($result['requesting_staff_id'] ?? 0);
            if ($staffId > 0) {
                $staffRows = db_query('SELECT role FROM users WHERE user_id = ? LIMIT 1', 'i', [$staffId]) ?: [];
                $staffRole = (string)($staffRows[0]['role'] ?? 'Staff');
                create_notification(
                    $staffId,
                    $staffRole,
                    "{$customerName} submitted revised details for Order #{$order_id}. Review is required.",
                    'Order',
                    false,
                    false,
                    $order_id
                );
            }
            $_SESSION['success'] = "Order #{$order_id} was resubmitted for staff review.";
            redirect('orders.php');
        } catch (Throwable $e) {
            $error = $e->getMessage();
        }
    }
}

$page_title = "Revise Order #{$order_id}";
$use_customer_css = true;
require_once __DIR__ . '/../includes/header.php';
?>

<style>
.revision-page{max-width:920px;margin:0 auto;padding:2rem 1rem 3rem}.revision-head{margin-bottom:1.5rem}.revision-title{font-size:1.55rem;font-weight:800;color:#102a36;margin:0 0 .35rem}.revision-sub{color:#64748b;font-size:.92rem}.revision-feedback{background:#fff8e6;border:1px solid #f5d88b;border-left:4px solid #e8a317;border-radius:12px;padding:1rem 1.1rem;margin-bottom:1.25rem}.revision-feedback h2{font-size:.82rem;text-transform:uppercase;letter-spacing:.04em;color:#92400e;margin:0 0 .45rem}.revision-feedback p{margin:.25rem 0;color:#78350f;line-height:1.55}.revision-card{background:#fff;border:1px solid #dce5ea;border-radius:14px;padding:1.25rem;margin-bottom:1rem;box-shadow:0 6px 20px rgba(15,42,54,.06)}.revision-card-head{display:flex;justify-content:space-between;align-items:center;gap:1rem;padding-bottom:.9rem;margin-bottom:1rem;border-bottom:1px solid #e7edf0}.revision-card-head h2{margin:0;color:#102a36;font-size:1.05rem}.revision-readonly{font-size:.78rem;color:#64748b}.revision-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:.9rem}.revision-field{padding:.8rem;border:1px solid #e5eaed;border-radius:10px;background:#f8fafb;min-width:0}.revision-field.required{background:#f0fbfd;border-color:#53c5e0;box-shadow:0 0 0 2px rgba(83,197,224,.08)}.revision-label{display:block;font-size:.69rem;font-weight:800;text-transform:uppercase;letter-spacing:.035em;color:#648897;margin-bottom:.4rem}.revision-value{color:#243c47;font-size:.9rem;font-weight:600;overflow-wrap:anywhere}.revision-input{width:100%;box-sizing:border-box;border:1px solid #9dcbd6;border-radius:8px;background:#fff;color:#18333e;padding:.68rem .75rem;font:inherit}.revision-help{display:block;color:#0e7490;font-size:.72rem;margin-top:.4rem}.revision-design{display:flex;gap:1rem;align-items:flex-start;flex-wrap:wrap}.revision-design img{width:180px;max-height:180px;object-fit:contain;border:1px solid #cbd5e1;border-radius:10px;background:#f8fafc}.revision-actions{display:flex;justify-content:flex-end;gap:.75rem;margin-top:1.25rem}.revision-btn{display:inline-flex;align-items:center;justify-content:center;min-height:44px;padding:0 1.15rem;border-radius:9px;font-weight:700;text-decoration:none;cursor:pointer}.revision-btn.secondary{border:1px solid #cbd5e1;color:#475569;background:#fff}.revision-btn.primary{border:1px solid #0e94a8;color:#fff;background:#0e94a8}.revision-alert{padding:.9rem 1rem;border-radius:10px;background:#fef2f2;border:1px solid #fecaca;color:#b91c1c;margin-bottom:1rem}.revision-meta{display:flex;gap:1.25rem;flex-wrap:wrap;color:#526b77;font-size:.82rem;margin-top:.55rem}
@media(max-width:640px){.revision-page{padding-top:1.1rem}.revision-grid{grid-template-columns:1fr}.revision-card{padding:1rem}.revision-card-head{align-items:flex-start;flex-direction:column}.revision-actions{flex-direction:column-reverse}.revision-btn{width:100%;box-sizing:border-box}.revision-design img{width:100%;max-width:260px}}
</style>

<main class="revision-page">
    <div class="revision-head">
        <a href="orders.php" style="display:inline-block;color:#526b77;text-decoration:none;margin-bottom:.8rem;">&larr; Back to Orders</a>
        <h1 class="revision-title">Revise Order #<?php echo $order_id; ?></h1>
        <p class="revision-sub">Only fields authorized by staff can be changed. All other order information remains read-only.</p>
        <div class="revision-meta">
            <span>Branch: <strong><?php echo htmlspecialchars((string)($order['branch_name'] ?? 'Not specified')); ?></strong></span>
            <span>Status: <strong>Revision Requested</strong></span>
        </div>
    </div>

    <section class="revision-feedback">
        <h2>Reason for Revision</h2>
        <p><strong><?php echo htmlspecialchars((string)$revision['revision_reason']); ?></strong></p>
        <p><?php echo nl2br(htmlspecialchars((string)$revision['staff_instruction'])); ?></p>
    </section>

    <?php if (!empty($error)): ?><div class="revision-alert"><?php echo htmlspecialchars($error); ?></div><?php endif; ?>

    <form method="POST" enctype="multipart/form-data" novalidate>
        <?php echo csrf_field(); ?>
        <input type="hidden" name="order_id" value="<?php echo $order_id; ?>">

        <?php $designEditable = in_array('uploaded_design', $permissions, true); ?>
        <section class="revision-card">
            <div class="revision-card-head">
                <h2>Uploaded Design</h2>
                <span class="revision-readonly"><?php echo $designEditable ? 'Correction required' : 'Read-only'; ?></span>
            </div>
            <div class="revision-design">
                <?php if (!empty($items)): ?>
                    <img src="<?php echo htmlspecialchars((function_exists('pf_app_base_path') ? pf_app_base_path() : '') . '/public/serve_design.php?type=order_item&id=' . (int)$items[0]['order_item_id']); ?>" alt="Current uploaded design">
                <?php endif; ?>
                <div style="flex:1;min-width:220px;">
                    <?php if ($designEditable): ?>
                        <label class="revision-label" for="design_file">Upload corrected design</label>
                        <input class="revision-input" type="file" id="design_file" name="design_file" accept="image/*,application/pdf" required>
                        <span class="revision-help">The existing design remains stored until this revised order is saved successfully.</span>
                    <?php else: ?>
                        <span class="revision-label">Current file</span>
                        <div class="revision-value"><?php echo htmlspecialchars((string)($items[0]['design_image_name'] ?? 'Uploaded design')); ?></div>
                    <?php endif; ?>
                </div>
            </div>
        </section>

        <?php foreach ($items as $item):
            $itemId = (int)$item['order_item_id'];
            $custom = customer_revision_item_custom($item);
            $itemName = customer_revision_item_name($item, $custom);
        ?>
            <section class="revision-card">
                <div class="revision-card-head">
                    <h2><?php echo htmlspecialchars($itemName); ?></h2>
                    <span class="revision-readonly">Product/service cannot be changed</span>
                </div>
                <div class="revision-grid">
                    <?php $quantityEditable = in_array('quantity', $permissions, true); ?>
                    <div class="revision-field <?php echo $quantityEditable ? 'required' : ''; ?>">
                        <label class="revision-label">Quantity</label>
                        <?php if ($quantityEditable): ?>
                            <input class="revision-input" type="number" min="1" max="100000" name="quantity[<?php echo $itemId; ?>]" value="<?php echo (int)$item['quantity']; ?>" required>
                            <span class="revision-help">Staff requested a correction.</span>
                        <?php else: ?>
                            <div class="revision-value"><?php echo (int)$item['quantity']; ?></div>
                        <?php endif; ?>
                    </div>

                    <?php foreach ($custom as $key => $value):
                        $key = (string)$key;
                        if (printflow_revision_is_protected_spec($key)) continue;
                        $config = customer_revision_field_config($item, $custom, $key);
                        $label = customer_revision_field_label($key, $config);
                        $editable = printflow_revision_permission_allows($permissions, $itemId, $key);
                        $fieldType = strtolower((string)($config['type'] ?? 'text'));
                        $options = is_array($config['options'] ?? null) ? $config['options'] : [];
                        $name = 'spec[' . $itemId . '][' . printflow_revision_form_key($key) . ']';
                        $textValue = customer_revision_scalar_text($value);
                    ?>
                        <div class="revision-field <?php echo $editable ? 'required' : ''; ?>">
                            <label class="revision-label"><?php echo htmlspecialchars($label); ?></label>
                            <?php if (!$editable): ?>
                                <div class="revision-value"><?php echo htmlspecialchars($textValue); ?></div>
                            <?php elseif (in_array($fieldType, ['select', 'radio'], true) && !empty($options)): ?>
                                <select class="revision-input" name="<?php echo htmlspecialchars($name); ?>">
                                    <?php foreach ($options as $option):
                                        $optionValue = is_array($option) ? (string)($option['value'] ?? $option['label'] ?? '') : (string)$option;
                                        if ($optionValue === '') continue;
                                    ?>
                                        <option value="<?php echo htmlspecialchars($optionValue); ?>" <?php echo $optionValue === $textValue ? 'selected' : ''; ?>><?php echo htmlspecialchars($optionValue); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            <?php elseif ($fieldType === 'textarea' || is_array($value)): ?>
                                <textarea class="revision-input" name="<?php echo htmlspecialchars($name); ?>" rows="3"><?php echo htmlspecialchars($textValue); ?></textarea>
                            <?php else: ?>
                                <input class="revision-input" type="<?php echo $fieldType === 'date' || printflow_revision_key_group($key) === 'needed_date' ? 'date' : ($fieldType === 'number' || $fieldType === 'quantity' ? 'number' : 'text'); ?>" name="<?php echo htmlspecialchars($name); ?>" value="<?php echo htmlspecialchars($textValue); ?>">
                            <?php endif; ?>
                            <?php if ($editable): ?><span class="revision-help">Staff requested a correction.</span><?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            </section>
        <?php endforeach; ?>

        <?php $notesEditable = in_array('order_notes', $permissions, true); ?>
        <section class="revision-card">
            <div class="revision-field <?php echo $notesEditable ? 'required' : ''; ?>" style="border:0;padding:0;background:transparent;box-shadow:none;">
                <label class="revision-label">Order Notes</label>
                <?php if ($notesEditable): ?>
                    <textarea class="revision-input" name="order_notes" rows="4"><?php echo htmlspecialchars((string)($order['notes'] ?? '')); ?></textarea>
                    <span class="revision-help">Staff requested a correction.</span>
                <?php else: ?>
                    <div class="revision-value"><?php echo nl2br(htmlspecialchars((string)($order['notes'] ?? 'No order notes provided.'))); ?></div>
                <?php endif; ?>
            </div>
        </section>

        <div class="revision-actions">
            <a href="orders.php" class="revision-btn secondary">Cancel</a>
            <button type="submit" name="resubmit_order" value="1" class="revision-btn primary">Submit Revised Order</button>
        </div>
    </form>
</main>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
