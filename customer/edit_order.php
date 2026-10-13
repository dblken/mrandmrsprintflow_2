<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/revision_workflow.php';
require_once __DIR__ . '/../includes/service_field_config_helper.php';
require_once __DIR__ . '/../includes/product_field_config_helper.php';

require_role('Customer');

$order_id = (int)($_GET['order_id'] ?? $_POST['order_id'] ?? 0);
$customer_id = (int)get_user_id();
$revisionReference = 'ADD-' . max(0, $order_id) . '-' . strtoupper(substr(hash('sha256', $order_id . '|' . $customer_id . '|' . date('Y-m-d-H')), 0, 8));

function customer_revision_stop(string $message, string $reference, int $orderId): never
{
    if (function_exists('printflow_add_details_replace_phrases')) {
        $message = printflow_add_details_replace_phrases($message);
    }
    $page_title = 'Request Add Details Error';
    $use_customer_css = true;
    require __DIR__ . '/../includes/header.php';
    ?>
    <main style="max-width:720px;margin:0 auto;padding:3rem 1rem;">
        <section style="background:#fff;border:1px solid #fecaca;border-left:5px solid #dc2626;border-radius:14px;padding:1.4rem;box-shadow:0 8px 24px rgba(15,42,54,.08);">
            <h1 style="margin:0 0 .65rem;color:#991b1b;font-size:1.35rem;">Request Add Details Unavailable</h1>
            <p style="margin:0 0 .8rem;color:#7f1d1d;line-height:1.55;"><?php echo htmlspecialchars($message); ?></p>
            <p style="margin:0 0 1.2rem;color:#64748b;font-size:.85rem;">Reference ID: <strong><?php echo htmlspecialchars($reference); ?></strong></p>
            <a href="orders.php<?php echo $orderId > 0 ? '?highlight=' . $orderId : ''; ?>" style="display:inline-flex;min-height:42px;align-items:center;padding:0 1rem;border-radius:8px;background:#0b3441;color:#fff;text-decoration:none;font-weight:700;">Back to My Orders</a>
        </section>
    </main>
    <?php
    require __DIR__ . '/../includes/footer.php';
    exit;
}

if ($order_id <= 0) {
    customer_revision_stop('No valid internal order ID was provided for this revision.', $revisionReference, 0);
}

$orderRows = db_query(
    "SELECT o.*, b.branch_name
     FROM orders o
     LEFT JOIN branches b ON b.id = o.branch_id
     WHERE o.order_id = ? AND o.customer_id = ? LIMIT 1",
    'ii',
    [$order_id, $customer_id]
) ?: [];
if (empty($orderRows)) {
    $orderExists = db_query('SELECT customer_id FROM orders WHERE order_id = ? LIMIT 1', 'i', [$order_id]) ?: [];
    $message = empty($orderExists)
        ? 'This order does not exist.'
        : 'This order does not belong to the logged-in customer.';
    error_log("[{$revisionReference}] Customer revision ownership check failed for Order #{$order_id}.");
    customer_revision_stop($message, $revisionReference, $order_id);
}
$order = $orderRows[0];
$revisionRequestIdHint = (int)($_GET['revision_request_id'] ?? 0);
$revision = printflow_revision_get_customer_pending_request(
    $order_id,
    $customer_id,
    $revisionRequestIdHint > 0 ? $revisionRequestIdHint : null
);
$revisionLoadError = '';
if ($revision === null) {
    error_log("[{$revisionReference}] Customer Request Add Details form could not load an active request for Order #{$order_id}.");
    $latestRevision = printflow_revision_get_latest($order_id);
    $latestStatus = (string)($latestRevision['request_status'] ?? '');
    if ($revisionRequestIdHint > 0 && $latestRevision !== null
        && (int)($latestRevision['revision_request_id'] ?? 0) !== $revisionRequestIdHint) {
        $revisionLoadError = 'This notification links to a different Request Add Details record. Open My Orders and use the latest staff message for this order.';
    } elseif (in_array($latestStatus, ['Resubmitted for Review', 'Staff Reviewing', 'Approved to Set Price'], true)) {
        $revisionLoadError = 'Staff already received your response for this Request Add Details. You cannot upload again until staff sends a new request.';
    } elseif ($latestStatus !== '') {
        $revisionLoadError = 'This Request Add Details is no longer open (status: ' . printflow_add_details_status_label($latestStatus) . ').';
    } else {
        $revisionLoadError = 'No active Request Add Details exists for this order. Ask staff to send the request again from Customizations.';
    }
} elseif (!in_array((string)($revision['request_status'] ?? ''), ['Requested', 'Customer Updating Details'], true)) {
    error_log("[{$revisionReference}] Customer attempted to open a closed Request Add Details state for Order #{$order_id}.");
    $revisionLoadError = 'This Request Add Details is no longer open for editing (status: '
        . printflow_add_details_status_label((string)($revision['request_status'] ?? ''))
        . '). Refresh My Orders to see its current status.';
}
$permissions = ($revision !== null && is_array($revision['permitted_fields_array'] ?? null))
    ? $revision['permitted_fields_array']
    : [];
if ($revision !== null && empty($permissions)) {
    error_log("[{$revisionReference}] Customer Request Add Details form found no permitted fields for Order #{$order_id}, Request #" . (int)$revision['revision_request_id']);
    $revisionLoadError = 'Staff did not authorize any editable fields for this request. Please ask the shop to resend Request Add Details with the correct fields selected.';
}
if ($revision !== null && !printflow_order_status_is_for_revision((string)($order['status'] ?? ''))) {
    if (printflow_revision_repair_customer_order_access($order_id, $customer_id, $revision)) {
        $orderRows = db_query(
            "SELECT o.*, b.branch_name
             FROM orders o
             LEFT JOIN branches b ON b.id = o.branch_id
             WHERE o.order_id = ? AND o.customer_id = ? LIMIT 1",
            'ii',
            [$order_id, $customer_id]
        ) ?: [];
        if (!empty($orderRows[0])) {
            $order = $orderRows[0];
        }
    } elseif (!printflow_order_status_is_for_revision((string)($order['status'] ?? ''))) {
        error_log("[{$revisionReference}] Pending Request Add Details #{$revisionRequestIdHint} for Order #{$order_id} while order status remains "
            . (string)($order['status'] ?? '') . '; allowing customer upload from active request record.');
    }
}
if ($revisionLoadError !== '') {
    customer_revision_stop($revisionLoadError, $revisionReference, $order_id);
}
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
if (empty($items)) {
    error_log("[{$revisionReference}] Customer revision form found no order items for Order #{$order_id}.");
    customer_revision_stop('The requested field is unavailable because this order has no editable item record.', $revisionReference, $order_id);
}
printflow_revision_mark_customer_updating((int)$revision['revision_request_id'], $order_id, $customer_id);

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

function customer_revision_field_config(array $item, array $custom, string $key, int $serviceIdFallback = 0): array
{
    global $order;
    $serviceId = (int)($custom['service_id'] ?? 0);
    if ($serviceId <= 0) $serviceId = $serviceIdFallback;
    if ($serviceId <= 0 && function_exists('printflow_resolve_service_catalog_service_id_for_order_line')) {
        $serviceId = printflow_resolve_service_catalog_service_id_for_order_line($custom, $order, $item);
    }
    if ($serviceId > 0) {
        $configs = get_service_field_config($serviceId);
        $config = printflow_revision_find_field_config($configs, $key);
        if ($config !== []) return $config;
    }
    $productId = (int)($item['product_id'] ?? 0);
    if ($productId > 0) {
        $configs = get_product_field_config($productId);
        $config = printflow_revision_find_field_config($configs, $key);
        if ($config !== []) return $config;
    }
    return [];
}

/** Collapse canonical keys and human-label aliases into one logical control. */
function customer_revision_dedupe_specs(array $item, array $custom, int $serviceIdFallback = 0): array
{
    $selected = [];
    $scores = [];
    foreach ($custom as $key => $value) {
        $key = (string)$key;
        $normalized = printflow_revision_normalize_key($key);
        if ($normalized === '') continue;
        $config = customer_revision_field_config($item, $custom, $key, $serviceIdFallback);
        $score = (customer_revision_scalar_text($value) !== '' ? 16 : 0)
            + ($config !== [] ? 8 : 0)
            + ($key === $normalized ? 4 : 0)
            + (strtolower($key) === $key ? 2 : 0)
            + (!str_contains($key, ' ') ? 1 : 0);
        if (!isset($selected[$normalized]) || $score > $scores[$normalized]) {
            $selected[$normalized] = [$key, $value];
            $scores[$normalized] = $score;
        }
    }
    $result = [];
    foreach ($selected as [$key, $value]) {
        $result[$key] = $value;
    }
    return $result;
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
            $revisionRequestId = (int)($result['revision_request_id'] ?? 0);
            $staffNotified = false;
            if ($staffId > 0) {
                $staffRows = db_query('SELECT role FROM users WHERE user_id = ? LIMIT 1', 'i', [$staffId]) ?: [];
                $staffRole = (string)($staffRows[0]['role'] ?? 'Staff');
                $staffNotified = (bool)create_notification(
                    $staffId,
                    $staffRole,
                    "{$customerName} submitted Request Add Details for Order #{$order_id}. Review is required.",
                    'Design',
                    false,
                    false,
                    $order_id,
                    $revisionRequestId
                );
            }
            if (!$staffNotified) {
                notify_shop_users(
                    "{$customerName} submitted Request Add Details for Order #{$order_id}. Review is required.",
                    'Design', false, false, $order_id, ['Staff', 'Admin', 'Manager'], $revisionRequestId
                );
            }
            $_SESSION['success'] = "Order #{$order_id} Request Add Details was submitted for staff review.";
            redirect('orders.php');
        } catch (Throwable $e) {
            $error = $e->getMessage();
            if (function_exists('printflow_add_details_replace_phrases')) {
                $error = printflow_add_details_replace_phrases($error);
            }
        }
    }
}

$page_title = function_exists('printflow_add_details_order_page_title')
    ? printflow_add_details_order_page_title($order_id)
    : "Request Add Details — Order #{$order_id}";
$use_customer_css = true;
require_once __DIR__ . '/../includes/header.php';
?>

<style>
.revision-page{max-width:920px;margin:0 auto;padding:2rem 1rem 3rem}.revision-head{margin-bottom:1.5rem}.revision-title{font-size:1.55rem;font-weight:800;color:#102a36;margin:0 0 .35rem}.revision-sub{color:#64748b;font-size:.92rem}.revision-feedback{background:#fff8e6;border:1px solid #f5d88b;border-left:4px solid #e8a317;border-radius:12px;padding:1rem 1.1rem;margin-bottom:1.25rem}.revision-feedback h2{font-size:.82rem;text-transform:uppercase;letter-spacing:.04em;color:#92400e;margin:0 0 .45rem}.revision-feedback p{margin:.25rem 0;color:#78350f;line-height:1.55}.revision-permissions{display:flex;flex-wrap:wrap;gap:.45rem;margin-top:.75rem}.revision-permission{padding:.35rem .6rem;border-radius:999px;background:#fff;border:1px solid #f5d88b;color:#78350f;font-size:.72rem;font-weight:700}.revision-card{background:#fff;border:1px solid #dce5ea;border-radius:14px;padding:1.25rem;margin-bottom:1rem;box-shadow:0 6px 20px rgba(15,42,54,.06)}.revision-card-head{display:flex;justify-content:space-between;align-items:center;gap:1rem;padding-bottom:.9rem;margin-bottom:1rem;border-bottom:1px solid #e7edf0}.revision-card-head h2{margin:0;color:#102a36;font-size:1.05rem}.revision-readonly{font-size:.78rem;color:#64748b}.revision-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:.9rem}.revision-field{padding:.8rem;border:1px solid #e5eaed;border-radius:10px;background:#f8fafb;min-width:0}.revision-field.required{background:#f0fbfd;border-color:#53c5e0;box-shadow:0 0 0 2px rgba(83,197,224,.08)}.revision-label{display:block;font-size:.69rem;font-weight:800;text-transform:uppercase;letter-spacing:.035em;color:#648897;margin-bottom:.4rem}.revision-value{color:#243c47;font-size:.9rem;font-weight:600;overflow-wrap:anywhere}.revision-input{width:100%;box-sizing:border-box;border:1px solid #9dcbd6;border-radius:8px;background:#fff;color:#18333e;padding:.68rem .75rem;font:inherit}.revision-help{display:block;color:#0e7490;font-size:.72rem;margin-top:.4rem}.revision-design{display:flex;gap:1rem;align-items:flex-start;flex-wrap:wrap}.revision-design img{width:180px;max-height:180px;object-fit:contain;border:1px solid #cbd5e1;border-radius:10px;background:#f8fafc}.revision-new-preview{display:none;margin-top:.8rem;padding:.75rem;border:1px solid #bae6fd;border-radius:10px;background:#f0f9ff}.revision-new-preview.open{display:block}.revision-new-preview img{display:block;width:180px;max-height:180px;object-fit:contain;margin-bottom:.65rem}.revision-file-actions{display:flex;align-items:center;gap:.65rem;flex-wrap:wrap}.revision-remove-file{border:1px solid #fca5a5;background:#fff;color:#b91c1c;border-radius:7px;padding:.45rem .65rem;font-weight:700;cursor:pointer}.revision-file-error{display:none;color:#b91c1c;font-size:.75rem;font-weight:700;margin-top:.5rem}.revision-design-list{display:grid;gap:.75rem;margin-top:.75rem}.revision-design-pick-row{display:flex;align-items:center;justify-content:space-between;gap:.75rem;flex-wrap:wrap;margin-bottom:.5rem}.revision-design-count{font-size:.78rem;font-weight:800;color:#0e7490}.revision-design-pick-card{display:flex;gap:.75rem;align-items:flex-start;padding:.75rem;border:1px solid #dbeafe;border-radius:10px;background:#fff}.revision-design-pick-card img{width:72px;height:72px;object-fit:contain;border-radius:8px;border:1px solid #e2e8f0;background:#f8fafc;flex-shrink:0}.revision-design-pick-meta{flex:1;min-width:0}.revision-design-pick-name{font-size:.82rem;font-weight:700;color:#0f172a;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;max-width:100%}.revision-design-pick-size{font-size:.72rem;color:#64748b;margin-top:.2rem}.revision-actions{display:flex;justify-content:flex-end;gap:.75rem;margin-top:1.25rem}.revision-btn{display:inline-flex;align-items:center;justify-content:center;min-height:44px;padding:0 1.15rem;border-radius:9px;font-weight:700;text-decoration:none;cursor:pointer}.revision-btn.secondary{border:1px solid #cbd5e1;color:#475569;background:#fff}.revision-btn.primary{border:1px solid #0e94a8;color:#fff;background:#0e94a8}.revision-btn:disabled{opacity:.6;cursor:wait}.revision-input--pick-blocked{pointer-events:none;opacity:.55}.revision-alert{padding:.9rem 1rem;border-radius:10px;background:#fef2f2;border:1px solid #fecaca;color:#b91c1c;margin-bottom:1rem}.revision-meta{display:flex;gap:1.25rem;flex-wrap:wrap;color:#526b77;font-size:.82rem;margin-top:.55rem}
@media(max-width:640px){.revision-page{padding-top:1.1rem}.revision-grid{grid-template-columns:1fr}.revision-card{padding:1rem}.revision-card-head{align-items:flex-start;flex-direction:column}.revision-actions{flex-direction:column-reverse}.revision-btn{width:100%;box-sizing:border-box}.revision-design img{width:100%;max-width:260px}}
.revision-page .pf-revision-design-section{margin-top:.35rem}
.revision-page .pf-revision-design-section+.pf-revision-design-section{margin-top:.65rem;padding-top:.65rem;border-top:1px solid #e7edf0}
.revision-page .pf-revision-design-section__label{font-size:.68rem;font-weight:800;text-transform:uppercase;letter-spacing:.04em;color:#648897;margin:0 0 .35rem}
.revision-page .pf-design-files{margin:0}
.revision-page .pf-design-files .pf-spec-label{display:none}
.revision-page .pf-design-files__toolbar{margin-bottom:6px}
.revision-page .pf-design-files__grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(132px,1fr));gap:8px}
.revision-page .pf-design-file-card{padding:8px}
.revision-page .pf-design-file-card__preview{min-height:88px;max-height:112px;margin-bottom:6px}
.revision-page .pf-design-file-card__img{max-height:112px}
.revision-page .pf-design-file-card__meta{margin-bottom:4px}
.revision-page .pf-design-file-card__name{font-size:12px;margin-bottom:2px}
</style>
<?php
$pfAssetBase = rtrim((function_exists('pf_app_base_path') ? pf_app_base_path() : (defined('BASE_URL') ? BASE_URL : '')), '/');
?>
<link rel="stylesheet" href="<?php echo htmlspecialchars($pfAssetBase . '/public/assets/css/pf-order-item-design-files.css?v=' . (int)@filemtime(__DIR__ . '/../public/assets/css/pf-order-item-design-files.css')); ?>">

<main class="revision-page">
    <div class="revision-head">
        <a href="orders.php" style="display:inline-block;color:#526b77;text-decoration:none;margin-bottom:.8rem;">&larr; Back to Orders</a>
        <h1 class="revision-title">Request Add Details — Order #<?php echo $order_id; ?></h1>
        <p class="revision-sub">Only fields authorized by staff can be changed. All other order information remains read-only.</p>
        <div class="revision-meta">
            <span>Branch: <strong><?php echo htmlspecialchars((string)($order['branch_name'] ?? 'Not specified')); ?></strong></span>
            <span>Status: <strong><?php echo htmlspecialchars(function_exists('printflow_add_details_status_label') ? printflow_add_details_status_label('Customer Updating Details') : 'Customer Updating Details'); ?></strong></span>
            <span>Requested: <strong><?php echo htmlspecialchars(format_datetime((string)$revision['requested_at'])); ?></strong></span>
        </div>
    </div>

    <section class="revision-feedback">
        <h2>Additional Details Requested by Staff</h2>
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
        <input type="hidden" name="resubmit_order" value="1">

        <?php
        $designEditable = in_array('uploaded_design', $permissions, true);
        $revisionMaxDesignFiles = printflow_order_item_design_files_max_per_item();
        $primaryItemId = (int)($items[0]['order_item_id'] ?? 0);
        ?>
        <section class="revision-card">
            <div class="revision-card-head">
                <h2>Design Files</h2>
                <?php if (!$designEditable): ?><span class="revision-readonly">Read-only</span><?php endif; ?>
            </div>
            <?php
            $revisionOrderItemId = (int)($revision['order_item_id'] ?? 0);
            if ($revisionOrderItemId <= 0) {
                $revisionOrderItemId = $primaryItemId;
            }
            $selectedSourceFiles = printflow_revision_resolve_selected_design_files(
                $order_id,
                $revisionOrderItemId,
                $revision['selected_design_file_ids_array'] ?? []
            );
            if (function_exists('pf_order_ui_dedupe_design_file_rows')) {
                $selectedSourceFiles = pf_order_ui_dedupe_design_file_rows($selectedSourceFiles);
            }
            $selectedDesignFileIds = [];
            foreach ($selectedSourceFiles as $selectedRow) {
                $selectedFileId = (int)($selectedRow['file_id'] ?? 0);
                if ($selectedFileId > 0) {
                    $selectedDesignFileIds[$selectedFileId] = true;
                }
            }
            if ($selectedSourceFiles !== []) {
                echo '<div class="pf-revision-design-section"><p class="pf-revision-design-section__label">Images staff selected for this request</p>';
                echo pf_order_ui_render_design_files_html($selectedSourceFiles, $revisionOrderItemId, 'Images staff selected for this request');
                echo '</div>';
            }
            foreach ($items as $revisionItem):
                $revisionItemId = (int)($revisionItem['order_item_id'] ?? 0);
                $currentDesignFiles = pf_order_ui_item_design_files_list($revisionItem, false, customer_revision_item_custom($revisionItem));
                if ($selectedDesignFileIds !== []) {
                    $currentDesignFiles = array_values(array_filter(
                        $currentDesignFiles,
                        static function (array $file) use ($selectedDesignFileIds): bool {
                            $fileId = (int)($file['file_id'] ?? 0);
                            return $fileId <= 0 || !isset($selectedDesignFileIds[$fileId]);
                        }
                    ));
                }
                if (function_exists('pf_order_ui_dedupe_design_file_rows')) {
                    $currentDesignFiles = pf_order_ui_dedupe_design_file_rows($currentDesignFiles);
                }
                if ($currentDesignFiles !== []) {
                    echo '<div class="pf-revision-design-section"><p class="pf-revision-design-section__label">Original designs on order</p>';
                    echo pf_order_ui_render_design_files_html($currentDesignFiles, $revisionItemId, 'Original designs on order');
                    echo '</div>';
                }
            endforeach; ?>
            <?php if ($designEditable): ?>
                <div style="margin-top:1rem;">
                    <input type="hidden" name="design_order_item_id" value="<?php echo $primaryItemId; ?>">
                    <input type="hidden" name="design_upload_idempotency" id="design_upload_idempotency" value="">
                    <div class="revision-design-pick-row">
                        <label class="revision-label" for="design_file" style="margin:0;">Upload replacement design(s)</label>
                        <span class="revision-design-count" id="revision_design_count">0/<?php echo (int)$revisionMaxDesignFiles; ?> files</span>
                    </div>
                    <input class="revision-input" type="file" id="design_file" name="design_file[]" accept="image/jpeg,image/png,image/gif,application/pdf" multiple>
                    <span class="revision-help">JPG, PNG, GIF, or PDF, up to 10 MB each. You may upload up to <?php echo (int)$revisionMaxDesignFiles; ?> replacement files. Original order designs stay on file until staff accepts your update.</span>
                    <div id="design_file_error" class="revision-file-error" role="alert"></div>
                    <div id="revision_design_pick_list" class="revision-design-list" aria-live="polite"></div>
                </div>
            <?php elseif ($primaryItemId > 0): ?>
                <div class="revision-value" style="margin-top:.75rem;">Design updates are read-only for this request.</div>
            <?php endif; ?>
        </section>

        <?php foreach ($items as $item):
            $itemId = (int)$item['order_item_id'];
            $rawCustom = customer_revision_item_custom($item);
            $itemName = customer_revision_item_name($item, $rawCustom);
            $itemServiceId = function_exists('printflow_resolve_service_catalog_service_id_for_order_line')
                ? printflow_resolve_service_catalog_service_id_for_order_line($rawCustom, $order, $item)
                : 0;
            $custom = customer_revision_dedupe_specs($item, $rawCustom, $itemServiceId);
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
                        if (!is_scalar($value)) continue;
                        if (function_exists('printflow_is_internal_customization_value') && printflow_is_internal_customization_value($value)) continue;
                        $config = customer_revision_field_config($item, $custom, $key, $itemServiceId);
                        $label = customer_revision_field_label($key, $config);
                        $editable = printflow_revision_permission_allows(
                            $permissions,
                            $itemId,
                            $key,
                            $item,
                            $rawCustom,
                            $itemServiceId
                        );
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
            <button type="submit" id="revision_submit_button" class="revision-btn primary">Submit Request Add Details</button>
        </div>
    </form>
</main>

<script src="<?php echo htmlspecialchars($pfAssetBase . '/public/assets/js/pf-order-item-design-files.js?v=' . (int)@filemtime(__DIR__ . '/../public/assets/js/pf-order-item-design-files.js')); ?>"></script>
<script>
(function () {
    const MAX_FILES = <?php echo (int)$revisionMaxDesignFiles; ?>;
    const designUploadRequired = <?php echo !empty($designEditable) ? 'true' : 'false'; ?>;
    const form = document.querySelector('.revision-page form');
    const fileInput = document.getElementById('design_file');
    const pickList = document.getElementById('revision_design_pick_list');
    const countLabel = document.getElementById('revision_design_count');
    const idempotencyInput = document.getElementById('design_upload_idempotency');
    const fileError = document.getElementById('design_file_error');
    const submitButton = document.getElementById('revision_submit_button');
    const allowed = ['image/jpeg', 'image/png', 'image/gif', 'application/pdf'];
    let selected = [];
    let submitLock = false;
    const submitDefaultLabel = submitButton ? submitButton.textContent : 'Submit Request Add Details';

    function resetSubmitState() {
        submitLock = false;
        if (submitButton) {
            submitButton.disabled = false;
            submitButton.textContent = submitDefaultLabel;
        }
    }

    function showFileError(message) {
        if (!fileError) return;
        fileError.textContent = message || '';
        fileError.style.display = message ? 'block' : 'none';
    }

    function formatSize(bytes) {
        const n = Number(bytes || 0);
        if (n < 1024) return n + ' B';
        if (n < 1024 * 1024) return (n / 1024).toFixed(n >= 10240 ? 0 : 1) + ' KB';
        return (n / (1024 * 1024)).toFixed(1) + ' MB';
    }

    function validateFile(file) {
        if (!allowed.includes(file.type)) {
            return 'Choose JPG, PNG, GIF, or PDF files only.';
        }
        if (file.size > 10 * 1024 * 1024) {
            return 'Each replacement file must be 10 MB or smaller.';
        }
        return '';
    }

    function syncInputFiles() {
        if (!fileInput || typeof DataTransfer === 'undefined') return;
        const dt = new DataTransfer();
        selected.forEach(function (entry) { dt.items.add(entry.file); });
        fileInput.files = dt.files;
    }

    function renderSelected() {
        if (countLabel) {
            countLabel.textContent = selected.length + '/' + MAX_FILES + ' files';
        }
        if (!pickList) return;
        pickList.innerHTML = '';
        selected.forEach(function (entry, index) {
            const card = document.createElement('div');
            card.className = 'revision-design-pick-card';
            const isPdf = entry.file.type === 'application/pdf';
            const preview = isPdf
                ? '<div class="revision-value" style="width:72px;height:72px;display:flex;align-items:center;justify-content:center;font-size:.7rem;">PDF</div>'
                : '<img src="' + entry.url + '" alt="">';
            card.innerHTML = preview
                + '<div class="revision-design-pick-meta"><div class="revision-design-pick-name" title="' + entry.file.name.replace(/"/g, '&quot;') + '">' + entry.file.name + '</div>'
                + '<div class="revision-design-pick-size">' + formatSize(entry.file.size) + '</div></div>'
                + '<button type="button" class="revision-remove-file" data-index="' + index + '">Remove</button>';
            pickList.appendChild(card);
        });
        pickList.querySelectorAll('[data-index]').forEach(function (btn) {
            btn.addEventListener('click', function () {
                const idx = Number(btn.getAttribute('data-index'));
                const entry = selected[idx];
                if (entry && entry.url) URL.revokeObjectURL(entry.url);
                selected.splice(idx, 1);
                syncInputFiles();
                renderSelected();
            });
        });
        if (fileInput) {
            fileInput.classList.toggle('revision-input--pick-blocked', selected.length >= MAX_FILES);
            fileInput.setAttribute('aria-disabled', selected.length >= MAX_FILES ? 'true' : 'false');
        }
    }

    if (idempotencyInput && !idempotencyInput.value) {
        idempotencyInput.value = 'rev-ui-' + Date.now().toString(36) + '-' + Math.random().toString(36).slice(2, 10);
    }

    if (fileInput) {
        fileInput.addEventListener('change', function () {
            showFileError('');
            const incoming = Array.from(fileInput.files || []);
            if (!incoming.length) return;
            const remaining = MAX_FILES - selected.length;
            if (remaining <= 0) {
                showFileError('Maximum ' + MAX_FILES + ' design files allowed for this order item.');
                syncInputFiles();
                return;
            }
            const accepted = incoming.slice(0, remaining);
            const rejectedCount = incoming.length - accepted.length;
            for (const file of accepted) {
                const err = validateFile(file);
                if (err) {
                    showFileError(err);
                    continue;
                }
                const duplicate = selected.some(function (entry) {
                    return entry.file.name === file.name && entry.file.size === file.size && entry.file.lastModified === file.lastModified;
                });
                if (duplicate) continue;
                selected.push({ file: file, url: URL.createObjectURL(file) });
            }
            if (rejectedCount > 0) {
                showFileError('Only ' + remaining + ' more file(s) can be added (maximum ' + MAX_FILES + ').');
            }
            syncInputFiles();
            renderSelected();
        });
    }

    if (form) form.addEventListener('submit', function (event) {
        showFileError('');
        if (designUploadRequired && fileInput && selected.length === 0) {
            event.preventDefault();
            showFileError('Select at least one design file before submitting your Request Add Details.');
            fileInput.focus();
            resetSubmitState();
            return;
        }
        if (designUploadRequired && fileInput) {
            syncInputFiles();
            if (!fileInput.files || fileInput.files.length === 0) {
                event.preventDefault();
                showFileError('Your selected files could not be attached. Please choose them again and submit.');
                resetSubmitState();
                return;
            }
        }
        if (!form.checkValidity()) {
            event.preventDefault();
            form.reportValidity();
            resetSubmitState();
            return;
        }
        if (submitLock) {
            event.preventDefault();
            return;
        }
        submitLock = true;
        if (submitButton) {
            submitButton.disabled = true;
            submitButton.textContent = 'Submitting Request Add Details…';
        }
    });

    if (window.PrintflowOrderItemDesignFiles) {
        window.PrintflowOrderItemDesignFiles.bindDesignFilesBlocks(document);
    }
})();
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
