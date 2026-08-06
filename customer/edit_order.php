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
$revisionLoadError = '';
$revisionReference = 'REV-' . $order_id . '-' . strtoupper(substr(hash('sha256', $order_id . '|' . $customer_id . '|' . date('Y-m-d-H')), 0, 8));
if ($revision === null) {
    error_log("[{$revisionReference}] Customer revision form could not load an active request for Order #{$order_id}.");
    $revisionLoadError = 'This revision request is missing editable fields. The shop has been notified.';
}
$permissions = is_array($revision['permitted_fields_array'] ?? null) ? $revision['permitted_fields_array'] : [];
if ($revision !== null && empty($permissions)) {
    error_log("[{$revisionReference}] Customer revision form found no permitted fields for Order #{$order_id}, Request #" . (int)$revision['revision_request_id']);
    $revisionLoadError = 'This revision request is missing editable fields. The shop has been notified.';
}
if ($revisionLoadError !== '') {
    $page_title = "Revision Request Error";
    $use_customer_css = true;
    require_once __DIR__ . '/../includes/header.php';
    ?>
    <main style="max-width:720px;margin:0 auto;padding:3rem 1rem;">
        <section style="background:#fff;border:1px solid #fecaca;border-left:5px solid #dc2626;border-radius:14px;padding:1.4rem;box-shadow:0 8px 24px rgba(15,42,54,.08);">
            <h1 style="margin:0 0 .65rem;color:#991b1b;font-size:1.35rem;">Revision Request Unavailable</h1>
            <p style="margin:0 0 .8rem;color:#7f1d1d;line-height:1.55;"><?php echo htmlspecialchars($revisionLoadError); ?></p>
            <p style="margin:0 0 1.2rem;color:#64748b;font-size:.85rem;">Reference ID: <strong><?php echo htmlspecialchars($revisionReference); ?></strong></p>
            <a href="orders.php?highlight=<?php echo $order_id; ?>" style="display:inline-flex;min-height:42px;align-items:center;padding:0 1rem;border-radius:8px;background:#0b3441;color:#fff;text-decoration:none;font-weight:700;">Back to My Orders</a>
        </section>
    </main>
    <?php
    require_once __DIR__ . '/../includes/footer.php';
    exit;
}
printflow_revision_mark_customer_updating((int)$revision['revision_request_id'], $order_id, $customer_id);
$permissionLabels = printflow_revision_permission_labels($permissions, $revision['previous_values_array']);
$revisionActionLabel = printflow_revision_action_label($permissions);

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
            $staffNotified = false;
            if ($staffId > 0) {
                $staffRows = db_query('SELECT role FROM users WHERE user_id = ? LIMIT 1', 'i', [$staffId]) ?: [];
                $staffRole = (string)($staffRows[0]['role'] ?? 'Staff');
                $staffNotified = (bool)create_notification(
                    $staffId,
                    $staffRole,
                    "{$customerName} submitted revised details for Order #{$order_id}. Review is required.",
                    'Design',
                    false,
                    false,
                    $order_id
                );
            }
            if (!$staffNotified) {
                notify_shop_users(
                    "{$customerName} submitted revised details for Order #{$order_id}. Review is required.",
                    'Design', false, false, $order_id, ['Staff', 'Admin', 'Manager']
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
.revision-page{max-width:920px;margin:0 auto;padding:2rem 1rem 3rem}.revision-head{margin-bottom:1.5rem}.revision-title{font-size:1.55rem;font-weight:800;color:#102a36;margin:0 0 .35rem}.revision-sub{color:#64748b;font-size:.92rem}.revision-feedback{background:#fff8e6;border:1px solid #f5d88b;border-left:4px solid #e8a317;border-radius:12px;padding:1rem 1.1rem;margin-bottom:1.25rem}.revision-feedback h2{font-size:.82rem;text-transform:uppercase;letter-spacing:.04em;color:#92400e;margin:0 0 .45rem}.revision-feedback p{margin:.25rem 0;color:#78350f;line-height:1.55}.revision-permissions{display:flex;flex-wrap:wrap;gap:.45rem;margin-top:.75rem}.revision-permission{padding:.35rem .6rem;border-radius:999px;background:#fff;border:1px solid #f5d88b;color:#78350f;font-size:.72rem;font-weight:700}.revision-card{background:#fff;border:1px solid #dce5ea;border-radius:14px;padding:1.25rem;margin-bottom:1rem;box-shadow:0 6px 20px rgba(15,42,54,.06)}.revision-card-head{display:flex;justify-content:space-between;align-items:center;gap:1rem;padding-bottom:.9rem;margin-bottom:1rem;border-bottom:1px solid #e7edf0}.revision-card-head h2{margin:0;color:#102a36;font-size:1.05rem}.revision-readonly{font-size:.78rem;color:#64748b}.revision-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:.9rem}.revision-field{padding:.8rem;border:1px solid #e5eaed;border-radius:10px;background:#f8fafb;min-width:0}.revision-field.required{background:#f0fbfd;border-color:#53c5e0;box-shadow:0 0 0 2px rgba(83,197,224,.08)}.revision-label{display:block;font-size:.69rem;font-weight:800;text-transform:uppercase;letter-spacing:.035em;color:#648897;margin-bottom:.4rem}.revision-value{color:#243c47;font-size:.9rem;font-weight:600;overflow-wrap:anywhere}.revision-input{width:100%;box-sizing:border-box;border:1px solid #9dcbd6;border-radius:8px;background:#fff;color:#18333e;padding:.68rem .75rem;font:inherit}.revision-help{display:block;color:#0e7490;font-size:.72rem;margin-top:.4rem}.revision-design{display:flex;gap:1rem;align-items:flex-start;flex-wrap:wrap}.revision-design img{width:180px;max-height:180px;object-fit:contain;border:1px solid #cbd5e1;border-radius:10px;background:#f8fafc}.revision-new-preview{display:none;margin-top:.8rem;padding:.75rem;border:1px solid #bae6fd;border-radius:10px;background:#f0f9ff}.revision-new-preview.open{display:block}.revision-new-preview img{display:block;width:180px;max-height:180px;object-fit:contain;margin-bottom:.65rem}.revision-file-actions{display:flex;align-items:center;gap:.65rem;flex-wrap:wrap}.revision-remove-file{border:1px solid #fca5a5;background:#fff;color:#b91c1c;border-radius:7px;padding:.45rem .65rem;font-weight:700;cursor:pointer}.revision-file-error{display:none;color:#b91c1c;font-size:.75rem;font-weight:700;margin-top:.5rem}.revision-actions{display:flex;justify-content:flex-end;gap:.75rem;margin-top:1.25rem}.revision-btn{display:inline-flex;align-items:center;justify-content:center;min-height:44px;padding:0 1.15rem;border-radius:9px;font-weight:700;text-decoration:none;cursor:pointer}.revision-btn.secondary{border:1px solid #cbd5e1;color:#475569;background:#fff}.revision-btn.primary{border:1px solid #0e94a8;color:#fff;background:#0e94a8}.revision-btn:disabled{opacity:.6;cursor:wait}.revision-alert{padding:.9rem 1rem;border-radius:10px;background:#fef2f2;border:1px solid #fecaca;color:#b91c1c;margin-bottom:1rem}.revision-meta{display:flex;gap:1.25rem;flex-wrap:wrap;color:#526b77;font-size:.82rem;margin-top:.55rem}
@media(max-width:640px){.revision-page{padding-top:1.1rem}.revision-grid{grid-template-columns:1fr}.revision-card{padding:1rem}.revision-card-head{align-items:flex-start;flex-direction:column}.revision-actions{flex-direction:column-reverse}.revision-btn{width:100%;box-sizing:border-box}.revision-design img{width:100%;max-width:260px}}
</style>

<main class="revision-page">
    <div class="revision-head">
        <a href="orders.php" style="display:inline-block;color:#526b77;text-decoration:none;margin-bottom:.8rem;">&larr; Back to Orders</a>
        <h1 class="revision-title">Revise Order #<?php echo $order_id; ?></h1>
        <p class="revision-sub">Only fields authorized by staff can be changed. All other order information remains read-only.</p>
        <div class="revision-meta">
            <span>Branch: <strong><?php echo htmlspecialchars((string)($order['branch_name'] ?? 'Not specified')); ?></strong></span>
            <span>Status: <strong>Customer Updating Details</strong></span>
            <span>Requested: <strong><?php echo htmlspecialchars(format_datetime((string)$revision['requested_at'])); ?></strong></span>
        </div>
    </div>

    <section class="revision-feedback">
        <h2>Reason for Revision</h2>
        <p><strong><?php echo htmlspecialchars((string)$revision['revision_reason']); ?></strong></p>
        <p><?php echo nl2br(htmlspecialchars((string)$revision['staff_instruction'])); ?></p>
        <?php if (!empty($permissionLabels)): ?>
            <div class="revision-permissions" aria-label="Fields permitted for editing">
                <?php foreach ($permissionLabels as $permissionLabel): ?>
                    <span class="revision-permission"><?php echo htmlspecialchars($permissionLabel); ?></span>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
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
                <?php if (!empty($items)):
                    $currentDesignUrl = (function_exists('pf_app_base_path') ? pf_app_base_path() : '') . '/public/serve_design.php?type=order_item&id=' . (int)$items[0]['order_item_id'];
                    $currentDesignMime = strtolower((string)($items[0]['design_image_mime'] ?? ''));
                ?>
                    <?php if (str_starts_with($currentDesignMime, 'image/')): ?>
                        <img src="<?php echo htmlspecialchars($currentDesignUrl); ?>" alt="Current uploaded design">
                    <?php else: ?>
                        <a class="revision-btn secondary" href="<?php echo htmlspecialchars($currentDesignUrl); ?>" target="_blank" rel="noopener noreferrer">View Current Design</a>
                    <?php endif; ?>
                <?php endif; ?>
                <div style="flex:1;min-width:220px;">
                    <?php if ($designEditable): ?>
                        <label class="revision-label" for="design_file">Upload Replacement Design</label>
                        <input class="revision-input" type="file" id="design_file" name="design_file" accept="image/jpeg,image/png,image/gif,application/pdf" required>
                        <span class="revision-help">JPG, PNG, GIF, or PDF, up to 10 MB. The existing design remains stored until submission succeeds.</span>
                        <div id="design_file_error" class="revision-file-error" role="alert"></div>
                        <div id="design_new_preview" class="revision-new-preview" aria-live="polite">
                            <span class="revision-label">Selected replacement</span>
                            <img id="design_new_preview_image" alt="Replacement design preview">
                            <div id="design_new_preview_pdf" class="revision-value" style="display:none;margin-bottom:.65rem;">PDF document selected</div>
                            <div class="revision-file-actions">
                                <span id="design_new_preview_name" class="revision-value"></span>
                                <button type="button" id="design_remove_file" class="revision-remove-file">Remove selected file</button>
                            </div>
                        </div>
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
                        if (printflow_revision_is_protected_spec($key) || printflow_revision_key_group($key) === 'order_notes') continue;
                        $config = customer_revision_field_config($item, $custom, $key);
                        $label = customer_revision_field_label($key, $config);
                        $editable = printflow_revision_permission_allows($permissions, $itemId, $key);
                        $fieldType = strtolower((string)($config['type'] ?? 'text'));
                        $options = is_array($config['options'] ?? null) ? $config['options'] : [];
                        $isRequired = !empty($config['required']);
                        $name = 'spec[' . $itemId . '][' . printflow_revision_form_key($key) . ']';
                        $textValue = customer_revision_scalar_text($value);
                    ?>
                        <div class="revision-field <?php echo $editable ? 'required' : ''; ?>">
                            <label class="revision-label"><?php echo htmlspecialchars($label); ?></label>
                            <?php if (!$editable): ?>
                                <div class="revision-value"><?php echo htmlspecialchars($textValue); ?></div>
                            <?php elseif ($fieldType === 'radio' && !empty($options)): ?>
                                <div style="display:flex;gap:.55rem;flex-wrap:wrap;">
                                    <?php foreach ($options as $option):
                                        $optionValue = is_array($option) ? (string)($option['value'] ?? $option['label'] ?? '') : (string)$option;
                                        if ($optionValue === '') continue;
                                    ?>
                                        <label style="display:inline-flex;align-items:center;gap:.35rem;padding:.5rem .6rem;background:#fff;border:1px solid #bae6fd;border-radius:7px;color:#334155;font-size:.82rem;">
                                            <input type="radio" name="<?php echo htmlspecialchars($name); ?>" value="<?php echo htmlspecialchars($optionValue); ?>" <?php echo $optionValue === $textValue ? 'checked' : ''; ?> <?php echo $isRequired ? 'required' : ''; ?>>
                                            <?php echo htmlspecialchars($optionValue); ?>
                                        </label>
                                    <?php endforeach; ?>
                                </div>
                            <?php elseif (in_array($fieldType, ['select', 'dimension'], true) && !empty($options)): ?>
                                <select class="revision-input" name="<?php echo htmlspecialchars($name); ?>" <?php echo $isRequired ? 'required' : ''; ?>>
                                    <option value="">Select an option</option>
                                    <?php foreach ($options as $option):
                                        $optionValue = is_array($option) ? (string)($option['value'] ?? $option['label'] ?? '') : (string)$option;
                                        if ($optionValue === '') continue;
                                    ?>
                                        <option value="<?php echo htmlspecialchars($optionValue); ?>" <?php echo $optionValue === $textValue ? 'selected' : ''; ?>><?php echo htmlspecialchars($optionValue); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            <?php elseif ($fieldType === 'textarea' || is_array($value)): ?>
                                <textarea class="revision-input" name="<?php echo htmlspecialchars($name); ?>" rows="3" <?php echo $isRequired ? 'required' : ''; ?>><?php echo htmlspecialchars($textValue); ?></textarea>
                            <?php else: ?>
                                <input class="revision-input" type="<?php echo $fieldType === 'date' || printflow_revision_key_group($key) === 'needed_date' ? 'date' : ($fieldType === 'number' || $fieldType === 'quantity' ? 'number' : 'text'); ?>" name="<?php echo htmlspecialchars($name); ?>" value="<?php echo htmlspecialchars($textValue); ?>" <?php echo $fieldType === 'number' || $fieldType === 'quantity' ? 'min="1"' : ''; ?> <?php echo $isRequired ? 'required' : ''; ?>>
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
            <button type="submit" name="resubmit_order" value="1" id="revision_submit_button" class="revision-btn primary">Submit Updated Details</button>
        </div>
    </form>
</main>

<script>
(function () {
    const form = document.querySelector('.revision-page form');
    const fileInput = document.getElementById('design_file');
    const preview = document.getElementById('design_new_preview');
    const previewImage = document.getElementById('design_new_preview_image');
    const previewPdf = document.getElementById('design_new_preview_pdf');
    const previewName = document.getElementById('design_new_preview_name');
    const removeButton = document.getElementById('design_remove_file');
    const fileError = document.getElementById('design_file_error');
    const submitButton = document.getElementById('revision_submit_button');
    let objectUrl = '';

    function clearSelectedFile() {
        if (objectUrl) URL.revokeObjectURL(objectUrl);
        objectUrl = '';
        if (fileInput) fileInput.value = '';
        if (preview) preview.classList.remove('open');
        if (previewImage) { previewImage.removeAttribute('src'); previewImage.style.display = 'none'; }
        if (previewPdf) previewPdf.style.display = 'none';
        if (previewName) previewName.textContent = '';
    }

    function showFileError(message) {
        if (!fileError) return;
        fileError.textContent = message || '';
        fileError.style.display = message ? 'block' : 'none';
    }

    if (fileInput) {
        fileInput.addEventListener('change', function () {
            showFileError('');
            const file = fileInput.files && fileInput.files[0];
            if (!file) { clearSelectedFile(); return; }
            const allowed = ['image/jpeg', 'image/png', 'image/gif', 'application/pdf'];
            if (!allowed.includes(file.type)) {
                clearSelectedFile();
                showFileError('Choose a JPG, PNG, GIF, or PDF file.');
                return;
            }
            if (file.size > 10 * 1024 * 1024) {
                clearSelectedFile();
                showFileError('The replacement design must not exceed 10 MB.');
                return;
            }
            if (objectUrl) URL.revokeObjectURL(objectUrl);
            objectUrl = URL.createObjectURL(file);
            preview.classList.add('open');
            previewName.textContent = file.name;
            if (file.type === 'application/pdf') {
                previewImage.style.display = 'none';
                previewPdf.style.display = 'block';
            } else {
                previewPdf.style.display = 'none';
                previewImage.src = objectUrl;
                previewImage.style.display = 'block';
            }
        });
        if (removeButton) removeButton.addEventListener('click', function () {
            clearSelectedFile();
            showFileError('Select a replacement design before submitting.');
            fileInput.focus();
        });
    }

    if (form) form.addEventListener('submit', function (event) {
        if (fileInput && (!fileInput.files || !fileInput.files.length)) {
            event.preventDefault();
            showFileError('Select a replacement design before submitting.');
            fileInput.focus();
            return;
        }
        if (!form.checkValidity()) {
            event.preventDefault();
            form.reportValidity();
            return;
        }
        if (submitButton) {
            submitButton.disabled = true;
            submitButton.textContent = 'Submitting Updates…';
        }
    });
})();
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
