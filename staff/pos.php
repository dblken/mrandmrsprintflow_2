<?php
/**
 * Point of Sale (POS) - Staff Walk-in Interface
 * PrintFlow - Printing Shop PWA
 */
$GLOBALS['PRINTFLOW_DISABLE_TURBO'] = true;

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/branch_context.php';
require_once __DIR__ . '/../includes/provider_payments.php';
require_once __DIR__ . '/../includes/pos_set_price_helpers.php';

$posPayMongoMode = printflow_paymongo_mode();
$posPayMongoAvailable = in_array($posPayMongoMode, ['test', 'live'], true)
    && printflow_paymongo_secret_key_for_mode($posPayMongoMode) !== '';
$posPayMongoQrphAvailable = $posPayMongoAvailable
    && in_array('qrph', printflow_paymongo_enabled_methods($posPayMongoMode), true);

// Require staff or admin role
require_role(['Admin', 'Staff']);
printflow_require_staff_module('pos');
$GLOBALS['printflow_allow_past_needed_date'] = true;

// Resolve and lock staff branch into session
$_pos_branch_ctx = init_branch_context(false);
$pos_staff_branch_id = (int) $_pos_branch_ctx['selected_branch_id'];
if ($pos_staff_branch_id > 0) {
    $_SESSION['branch_id'] = $pos_staff_branch_id;
}

$page_title = "Point of Sale (POS)";
$current_page = "pos";
$user_name = $_SESSION['user_name'] ?? 'Staff';

// Fetch Categories
$categories = [];
try {
    $categories = db_query("SELECT DISTINCT category FROM products WHERE status = 'Activated' AND category IS NOT NULL ORDER BY category ASC");
} catch (Exception $e) {
}

// Fetch Customers
$customers = [];
try {
    $customers = db_query("SELECT customer_id, first_name, last_name, email, contact_number FROM customers WHERE " . printflow_customer_exclusion_customer_scope_sql('customers.customer_id') . " ORDER BY first_name ASC, last_name ASC");
    $customers = array_values(array_filter($customers ?: [], static function (array $row): bool {
        $email = strtolower(trim((string)($row['email'] ?? '')));
        if ($email === 'walkin@pos.local') {
            return false;
        }
        return !preg_match('/^pos\.guest\.[a-f0-9]+@pos\.local$/', $email);
    }));
} catch (Exception $e) {
}

// Fetch Branches (for service modals)
$branches = [];
try {
    $branches = db_query("SELECT id, branch_name FROM branches WHERE status = 'Active' ORDER BY branch_name ASC");
} catch (Exception $e) {
}

// Fetch active services from DB (same catalog fields as customer/services.php)
$pos_services = [];
$pos_set_price_service_ids = [];
$pos_default_catalog_img = (defined('BASE_PATH') ? BASE_PATH : '/printflow') . '/public/assets/images/services/default.png';
$pos_base_path = defined('BASE_PATH') ? BASE_PATH : '/printflow';
try {
    require_once __DIR__ . '/../includes/customer_service_catalog.php';
    $service_rows = db_query(
        "SELECT service_id, name, category, display_image, hero_image
         FROM services WHERE status = 'Activated' ORDER BY name ASC"
    ) ?: [];
    $service_ids = array_map(static fn($r) => (int) ($r['service_id'] ?? 0), $service_rows);
    $pricing_by_service = printflow_catalog_pricing_metadata_map($service_ids);
    foreach ($service_rows as $row) {
        $sid = (int) ($row['service_id'] ?? 0);
        if ($sid < 1) {
            continue;
        }
        $pricing = $pricing_by_service[$sid] ?? printflow_catalog_pricing_metadata_from_fields([]);
        $img = pf_service_card_primary_image(
            (string) ($row['display_image'] ?? ''),
            (string) ($row['hero_image'] ?? ''),
            $pos_base_path,
            $pos_default_catalog_img
        );
        $image_url = pf_normalize_service_image_path($img, $pos_base_path, $pos_default_catalog_img);
        $pos_services[] = [
            'service_id' => $sid,
            'name' => $row['name'],
            'category' => $row['category'] ?? '',
            'image_url' => $image_url,
            'pricing_type' => (string) ($pricing['pricing_type'] ?? 'custom'),
        ];
        if (printflow_service_requires_pos_set_price($sid, (string) ($row['name'] ?? ''), (string) ($row['category'] ?? ''))) {
            $pos_set_price_service_ids[] = $sid;
        }
    }
} catch (Exception $e) {
}

$pos_csrf_token = generate_csrf_token();
if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($page_title); ?> - PrintFlow</title>
    <link rel="stylesheet" href="<?php echo htmlspecialchars(BASE_PATH . '/public/assets/css/output.css'); ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" crossorigin="anonymous" referrerpolicy="no-referrer">
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
    <?php include __DIR__ . '/../includes/admin_style.php'; ?>
    <style>
        /* Field styles for service modal (mirrors order_service_dynamic.php) */
        .shopee-form-row {
            display: flex;
            gap: 1rem;
            margin-bottom: 1.25rem;
            align-items: flex-start;
            flex-wrap: wrap;
        }

        .shopee-form-label {
            min-width: 120px;
            padding-top: .5rem;
            font-size: .85rem;
            font-weight: 600;
            color: #374151;
            flex-shrink: 0;
        }

        .shopee-form-field {
            flex: 1;
            display: flex;
            flex-direction: column;
            min-width: 0;
            gap: 4px;
        }

        .shopee-opt-group {
            display: flex;
            flex-wrap: wrap;
            gap: .5rem;
            align-items: flex-start;
        }

        .shopee-opt-btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: .45rem .9rem;
            border: 2px solid #e5e7eb;
            border-radius: .5rem;
            background: #fff;
            cursor: pointer;
            transition: all .2s;
            font-size: .85rem;
            font-weight: 500;
            color: #374151;
            min-height: 2.25rem;
        }

        .shopee-opt-btn:hover {
            border-color: var(--staff-primary);
            background: var(--staff-toolbar-hover-bg);
        }

        .shopee-opt-btn.active {
            border-color: var(--staff-primary);
            background: var(--staff-primary);
            color: #fff;
        }

        .shopee-opt-btn select,
        .shopee-opt-btn input[type=date] {
            border: none;
            background: transparent;
            outline: none;
            font-size: .85rem;
            font-weight: 500;
            color: #374151;
            cursor: pointer;
        }

        .input-field {
            width: 100%;
            padding: .6rem .75rem;
            border: 1px solid #cbd5e1;
            border-radius: .5rem;
            font-size: .875rem;
            outline: none;
            transition: border-color .2s;
            max-width: 400px;
        }

        .input-field:focus {
            border-color: var(--staff-primary);
            box-shadow: 0 0 0 3px rgba(var(--staff-accent-rgb), 0.12);
        }

        .field-invalid {
            border-color: #dc2626 !important;
            box-shadow: 0 0 0 3px rgba(220, 38, 38, 0.12) !important;
        }

        .shopee-opt-group.field-invalid,
        .quantity-container.field-invalid {
            border: 1px solid #dc2626 !important;
            border-radius: .65rem;
            padding: .45rem;
            box-shadow: 0 0 0 3px rgba(220, 38, 38, 0.12);
        }

        .field-error-message {
            color: #dc2626;
            font-size: 13px;
            margin-top: 6px;
            line-height: 1.35;
        }

        .input-field.input-field-locked,
        .shopee-opt-btn select.input-field-locked {
            background: var(--staff-primary) !important;
            color: #ffffff !important;
            border: 1px solid var(--staff-primary) !important;
            cursor: not-allowed !important;
            opacity: 1 !important;
        }

        .shopee-opt-btn:has(select.input-field-locked) {
            background: var(--staff-primary) !important;
            border-color: var(--staff-primary) !important;
            color: #ffffff !important;
        }

        .input-field.input-field-locked option,
        .shopee-opt-btn select.input-field-locked option {
            color: #0f172a;
            background: #ffffff;
        }

        .qty-input-field::-webkit-outer-spin-button,
        .qty-input-field::-webkit-inner-spin-button {
            -webkit-appearance: none;
            margin: 0;
        }

        .qty-input-field[type=number] {
            -moz-appearance: textfield;
            appearance: textfield;
        }

        .notes-textarea {
            font-size: .875rem;
            font-weight: 500;
            color: #374151;
            resize: none !important;
            min-height: 80px !important;
            max-height: 80px !important;
        }

        /* Service modal: match Customer choice/select styling */
        #service-modal-overlay .shopee-opt-btn {
            min-height: 40px;
            min-width: 80px;
            padding: 0 12px !important;
            border: 1px solid #e2e8f0 !important;
            background: #ffffff !important;
            color: #333 !important;
            font-size: 0.875rem !important;
            border-radius: 2px !important;
            position: relative !important;
            overflow: hidden !important;
        }

        #service-modal-overlay .shopee-opt-btn:hover {
            border-color: #0a2530 !important;
            color: #0a2530 !important;
            background: #ffffff !important;
        }

        #service-modal-overlay button.shopee-opt-btn.active,
        #service-modal-overlay label.shopee-opt-btn.active,
        #service-modal-overlay button.shopee-opt-btn:has(input:checked),
        #service-modal-overlay label.shopee-opt-btn:has(input:checked) {
            border-color: #0a2530 !important;
            background: #ffffff !important;
            color: #0a2530 !important;
        }

        #service-modal-overlay button.shopee-opt-btn.active::after,
        #service-modal-overlay label.shopee-opt-btn.active::after,
        #service-modal-overlay button.shopee-opt-btn:has(input:checked)::after,
        #service-modal-overlay label.shopee-opt-btn:has(input:checked)::after {
            content: '';
            position: absolute;
            bottom: -10px;
            right: -10px;
            width: 20px;
            height: 20px;
            background: #0a2530;
            transform: rotate(45deg);
        }

        #service-modal-overlay textarea.shopee-opt-btn:hover,
        #service-modal-overlay textarea.shopee-opt-btn:focus,
        #service-modal-overlay .quantity-container.shopee-opt-btn:hover {
            border-color: #e5e7eb !important;
            background: #ffffff !important;
            color: #374151 !important;
        }

        #service-modal-overlay select.shopee-opt-btn {
            appearance: none;
            -webkit-appearance: none;
            -moz-appearance: none;
            color: #0f172a !important;
            background-color: #ffffff !important;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' fill='none' viewBox='0 0 24 24' stroke='%236b7280'%3E%3Cpath stroke-linecap='round' stroke-linejoin='round' stroke-width='2' d='M19 9l-7 7-7-7'/%3E%3C/svg%3E");
            background-repeat: no-repeat;
            background-position: right 0.75rem center;
            background-size: 1rem;
            padding-right: 2.5rem !important;
        }

        #service-modal-overlay select.shopee-opt-btn option {
            color: #0f172a;
            background: #ffffff;
        }

        #service-modal-overlay select.shopee-opt-btn:focus {
            border-color: #0a2530 !important;
            outline: none;
            box-shadow: 0 0 0 3px rgba(10, 37, 48, 0.08);
        }

        #service-modal-overlay .sm-footer-actions {
            padding: 14px 20px;
            border-top: 1px solid #e2e8f0;
            background: #f8fafc;
            flex-shrink: 0;
        }

        #service-modal-overlay .sm-footer-inner {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 16px;
            width: 100%;
            flex-wrap: wrap;
        }

        #service-modal-overlay .pos-estimated-price-display {
            flex: 1 1 220px;
            min-width: 0;
        }

        #service-modal-overlay .pos-est-price-label {
            font-size: 10px;
            font-weight: 800;
            color: #64748b;
            text-transform: uppercase;
            letter-spacing: 0.06em;
            line-height: 1.2;
        }

        #service-modal-overlay .pos-est-price-amount {
            font-size: 1.25rem;
            color: #0f172a;
            font-weight: 900;
            line-height: 1.2;
            margin-top: 2px;
        }

        #service-modal-overlay .pos-est-price-meta {
            font-size: 0.75rem;
            color: #64748b;
            margin-top: 2px;
            font-weight: 500;
        }

        #service-modal-overlay .sm-footer-buttons {
            display: flex;
            align-items: center;
            justify-content: flex-end;
            gap: 10px;
            flex: 0 0 auto;
        }

        #service-modal-overlay .sm-btn-cancel {
            min-width: 108px;
            padding: 11px 20px;
            border: 1px solid #dc2626;
            border-radius: 10px;
            background: #ffffff;
            color: #dc2626;
            font-weight: 700;
            font-size: 14px;
            cursor: pointer;
            line-height: 1.2;
            transition: background 0.2s, border-color 0.2s, color 0.2s;
        }

        #service-modal-overlay .sm-btn-cancel:hover {
            background: #fef2f2;
            border-color: #b91c1c;
            color: #b91c1c;
        }

        #service-modal-overlay .sm-btn-cancel:focus-visible {
            outline: 2px solid #dc2626;
            outline-offset: 2px;
        }

        #service-modal-overlay .sm-btn-add {
            min-width: 148px;
            padding: 11px 22px;
            border: none;
            border-radius: 10px;
            background: #00232b;
            color: #fff;
            font-weight: 700;
            font-size: 14px;
            cursor: pointer;
            line-height: 1.2;
            box-shadow: 0 10px 24px rgba(0, 35, 43, 0.28);
            transition: background 0.2s;
        }

        #service-modal-overlay .sm-btn-add:hover {
            background: #003a47;
        }

        #service-modal-overlay .sm-btn-add:focus-visible {
            outline: 2px solid #00232b;
            outline-offset: 2px;
        }

        @media (max-width: 560px) {
            #service-modal-overlay .sm-footer-inner {
                flex-direction: column;
                align-items: stretch;
            }

            #service-modal-overlay .sm-footer-buttons {
                width: 100%;
                justify-content: stretch;
            }

            #service-modal-overlay .sm-btn-cancel,
            #service-modal-overlay .sm-btn-add {
                flex: 1 1 0;
                min-width: 0;
            }
        }

        .dim-label {
            font-size: .7rem;
            color: #94a3b8;
            font-weight: 600;
            margin-bottom: 4px;
            display: block;
            text-transform: uppercase;
        }

        .nested-fields-container {
            display: none;
            margin-top: 12px;
            padding: 12px;
            background: #f9fafb;
            border: 1px solid #e5e7eb;
            border-radius: 8px;
        }

        .quantity-container {
            display: inline-flex;
            justify-content: space-between;
            gap: 1rem;
            width: 160px;
            cursor: default;
        }

        /* 
         * STABLE POS LAYOUT
         * We use absolute positioning inside a relative container to prevent ALL jumping/height shifts.
         */

        /* The container takes up exactly the available height minus the top bar */
        .pos-wrapper {
            position: relative;
            flex: 1;
            height: 100%;
            display: grid;
            grid-template-columns: minmax(0, 1fr) minmax(360px, 34vw);
            background: #ffffff;
            border: none;
            overflow: hidden;
            margin: 0;
            min-height: 0;
        }

        /* Left Side: Products */
        .pos-products-area {
            flex: 1;
            display: flex;
            flex-direction: column;
            border-right: 1px solid #e2e8f0;
            background: #f8fafc;
            min-width: 0;
            min-height: 0;
        }

        .pos-search-header {
            padding: 16px 20px;
            background: #ffffff;
            border-bottom: 1px solid #e2e8f0;
            display: grid;
            grid-template-columns: minmax(220px, 1.45fr) minmax(160px, 1fr) minmax(150px, 0.75fr) auto;
            gap: 12px;
            align-items: center;
        }

        .pos-toolbar-field {
            min-width: 0;
        }

        .pos-toolbar-sr-label {
            position: absolute;
            width: 1px;
            height: 1px;
            padding: 0;
            margin: -1px;
            overflow: hidden;
            clip: rect(0, 0, 0, 0);
            white-space: nowrap;
            border: 0;
        }

        .pos-search-box {
            position: relative;
            width: 100%;
        }

        .pos-barcode-scan {
            display: flex;
            flex-direction: column;
            gap: 6px;
            min-width: 0;
        }

        .pos-search-header .pos-barcode-scan {
            gap: 0;
        }

        .pos-barcode-scan label {
            font-size: 12px;
            font-weight: 700;
            color: #475569;
            line-height: 1;
        }

        .pos-btn-back {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            height: 44px;
            padding: 0 16px;
            border: 1px solid #e2e8f0;
            border-radius: 10px;
            background: #f8fafc;
            color: #475569;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            transition: border-color 0.2s, background 0.2s, color 0.2s;
            white-space: nowrap;
        }

        .pos-btn-back:hover {
            background: #f1f5f9;
            border-color: #cbd5e1;
            color: #334155;
        }

        .pos-btn-back i {
            color: var(--staff-primary);
            font-size: 13px;
        }

        @media (max-width: 1100px) {
            .pos-search-header {
                grid-template-columns: 1fr 1fr;
            }

            .pos-toolbar-sku {
                grid-column: 1 / -1;
            }

            .pos-btn-back {
                justify-self: start;
            }
        }

        .pos-barcode-box {
            position: relative;
        }

        .pos-barcode-box .pos-search-input {
            padding-left: 16px;
        }

        .pos-search-box i {
            position: absolute;
            left: 14px;
            top: 50%;
            transform: translateY(-50%);
            color: #94a3b8;
            width: 16px;
            text-align: center;
            pointer-events: none;
            z-index: 1;
        }

        .pos-search-input {
            width: 100%;
            padding: 12px 16px 12px 36px;
            border: 1px solid #cbd5e1;
            border-radius: 10px;
            font-size: 14px;
            outline: none;
            transition: border-color 0.2s, box-shadow 0.2s, background 0.2s;
            height: 44px;
            box-sizing: border-box;
            background: #ffffff;
            color: #334155;
        }

        .pos-search-box .pos-search-input {
            padding-left: 44px;
        }

        .pos-search-input:focus {
            border-color: var(--staff-primary);
            box-shadow: 0 0 0 3px rgba(var(--staff-accent-rgb), 0.12);
        }

        .pos-category-select {
            padding: 0 16px;
            border: 1px solid #cbd5e1;
            border-radius: 10px;
            background: #ffffff;
            font-size: 14px;
            width: 100%;
            outline: none;
            cursor: pointer;
            height: 44px;
            box-sizing: border-box;
        }

        .pos-catalog-grid,
        .pos-products-grid {
            flex: 1;
            overflow-y: auto;
            padding: 16px 18px;
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(min(100%, 152px), 1fr));
            gap: 14px;
            align-content: start;
            background: #f8fafc;
        }

        @media (min-width: 768px) {
            .pos-catalog-grid,
            .pos-products-grid {
                grid-template-columns: repeat(auto-fill, minmax(168px, 1fr));
            }
        }

        @media (min-width: 1200px) {
            .pos-catalog-grid,
            .pos-products-grid {
                grid-template-columns: repeat(auto-fill, minmax(176px, 1fr));
            }
        }

        @media (min-width: 1280px) {
            .pos-catalog-grid,
            .pos-products-grid {
                grid-template-columns: repeat(4, minmax(0, 1fr));
            }
        }

        /* Catalog card (products + services) */
        .pos-catalog-card {
            background: #ffffff;
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            overflow: hidden;
            cursor: pointer;
            transition: transform 0.22s ease, box-shadow 0.22s ease, border-color 0.22s ease;
            display: flex;
            flex-direction: column;
            box-shadow: 0 4px 14px rgba(15, 23, 42, 0.05);
            min-height: 0;
            text-align: left;
            padding: 0;
            font: inherit;
            font-family: var(--pf-ui-font-sans, inherit);
            color: inherit;
            width: 100%;
        }

        .pos-catalog-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 12px 28px rgba(15, 23, 42, 0.1);
            border-color: rgba(var(--staff-accent-rgb), 0.45);
        }

        .pos-catalog-card.no-stock {
            opacity: 0.62;
            cursor: not-allowed;
            filter: grayscale(55%);
        }

        .pos-catalog-card.no-stock:hover {
            transform: none;
            box-shadow: 0 4px 14px rgba(15, 23, 42, 0.05);
            border-color: #e2e8f0;
        }

        .pos-catalog-card__media {
            position: relative;
            width: 100%;
            aspect-ratio: 4 / 3;
            background: #f1f5f9;
            overflow: hidden;
            flex-shrink: 0;
        }

        .pos-catalog-card__media img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            display: block;
        }

        .pos-catalog-card__badge {
            position: absolute;
            left: 8px;
            top: 8px;
            padding: 3px 8px;
            border-radius: 999px;
            font-size: 10px;
            font-weight: 700;
            letter-spacing: 0.02em;
            background: rgba(15, 23, 42, 0.78);
            color: #fff;
        }

        .pos-catalog-card__badge.is-danger {
            background: rgba(220, 38, 38, 0.92);
        }

        .pos-catalog-card__body {
            padding: 10px 12px 12px;
            display: flex;
            flex-direction: column;
            gap: 6px;
            flex: 1;
            min-height: 72px;
        }

        .pos-catalog-card__body,
        .pos-catalog-card__name,
        .pos-catalog-card__price,
        .pos-catalog-card__meta,
        .pos-catalog-card__badge {
            font-family: inherit;
        }

        .pos-catalog-card__name {
            font-size: 13px;
            font-weight: 700;
            color: #0f172a;
            line-height: 1.35;
            display: -webkit-box;
            -webkit-line-clamp: 2;
            -webkit-box-orient: vertical;
            overflow: hidden;
            word-break: break-word;
            margin: 0;
        }

        .pos-catalog-card__price {
            font-size: 14px;
            font-weight: 800;
            color: #0f172a;
            margin-top: auto;
        }

        .pos-catalog-card__meta {
            font-size: 10px;
            font-weight: 600;
            color: #64748b;
            text-transform: uppercase;
            letter-spacing: 0.04em;
        }

        /* Product catalog grid — fixed card anatomy so name/price never clip */
        #products-view {
            min-height: 0;
            overflow: hidden;
        }

        .pos-products-grid {
            gap: 10px;
            padding: 12px;
            scroll-padding: 12px;
            grid-template-columns: repeat(2, minmax(0, 1fr));
        }

        @media (min-width: 769px) {
            .pos-products-grid {
                grid-template-columns: repeat(auto-fill, minmax(140px, 1fr));
            }
        }

        .pos-products-grid .pos-catalog-card {
            min-height: 0;
            align-self: stretch;
            max-width: 100%;
        }

        .pos-products-grid .pos-catalog-card__media {
            aspect-ratio: 1;
            height: 112px;
            min-height: 112px;
            max-height: 112px;
            background: #f8fafc;
        }

        .pos-products-grid .pos-catalog-card__media img {
            object-fit: contain;
            object-position: center;
            padding: 8px;
            box-sizing: border-box;
        }

        .pos-products-grid .pos-catalog-card__body {
            flex: 1 0 auto;
            flex-shrink: 0;
            min-height: 0;
            padding: 8px 10px;
            gap: 4px;
            border-top: 1px solid #eef2f6;
            background: #ffffff;
        }

        .pos-products-grid .pos-catalog-card__meta {
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
            max-width: 100%;
            flex-shrink: 0;
        }

        .pos-products-grid .pos-catalog-card__name {
            min-height: calc(2 * 1.35 * 13px);
            line-clamp: 2;
            flex-shrink: 0;
        }

        .pos-products-grid .pos-catalog-card__price {
            margin-top: auto;
            flex-shrink: 0;
            padding-top: 2px;
            font-size: 15px;
            color: #0f172a;
        }

        .pos-products-grid .pos-product-stock {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            gap: 3px 5px;
            font-size: 12px;
            line-height: 1.4;
            font-weight: 600;
            color: #374151;
            overflow-wrap: anywhere;
        }

        .pos-products-grid .pos-product-stock .pf-stock-status-badge {
            max-width: 100%;
            white-space: normal;
            box-sizing: border-box;
        }

        .pos-products-grid {
            grid-auto-rows: max-content;
        }

        .pos-products-grid .pos-catalog-card.is-selecting {
            opacity: 0.88;
            transform: scale(0.985);
        }

        /* Services catalog grid — same stable card anatomy as products */
        #services-view {
            min-height: 0;
            overflow: hidden;
        }

        .pos-services-grid {
            gap: 16px;
            padding: 18px 20px 24px;
            scroll-padding: 12px;
            min-height: 0;
            grid-template-columns: repeat(2, minmax(0, 1fr));
        }

        @media (min-width: 900px) {
            .pos-services-grid {
                grid-template-columns: repeat(3, minmax(0, 1fr));
            }
        }

        @media (min-width: 1280px) {
            .pos-services-grid {
                grid-template-columns: repeat(4, minmax(0, 1fr));
            }
        }

        .pos-services-grid .pos-catalog-card {
            min-height: 248px;
            align-self: start;
            max-width: 100%;
        }

        .pos-services-grid .pos-catalog-card__media {
            aspect-ratio: 1;
            height: 118px;
            min-height: 118px;
            max-height: 118px;
            background: #f8fafc;
        }

        .pos-services-grid .pos-catalog-card__media img {
            object-fit: contain;
            object-position: center;
            padding: 8px;
            box-sizing: border-box;
        }

        .pos-services-grid .pos-catalog-card__body {
            flex: 0 0 auto;
            flex-shrink: 0;
            min-height: 92px;
            padding: 10px 12px 14px;
            border-top: 1px solid #eef2f6;
            background: #ffffff;
        }

        .pos-services-grid .pos-catalog-card__meta {
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
            max-width: 100%;
            flex-shrink: 0;
        }

        .pos-services-grid .pos-catalog-card__name {
            min-height: calc(2 * 1.35 * 13px);
            line-clamp: 2;
            -webkit-line-clamp: 2;
            flex-shrink: 0;
        }

        .pos-services-grid .pos-catalog-card__price {
            margin-top: auto;
            flex-shrink: 0;
            padding-top: 6px;
            font-size: 14px;
            color: #0f172a;
        }

        /* Legacy alias for JS filters */
        .pos-card.no-stock {
            opacity: 0.62;
            cursor: not-allowed;
        }

        /* Right Side: Cart */
        .pos-cart-area {
            width: auto;
            max-width: 520px;
            background: #ffffff;
            display: flex;
            flex-direction: column;
            flex-shrink: 0;
            border-left: 1px solid #e2e8f0;
            min-height: 0;
            height: 100%;
            max-height: 100%;
            overflow: hidden;
        }

        .pos-cart-body {
            flex: 1 1 0;
            min-height: 0;
            overflow-y: auto;
            overflow-x: hidden;
            -webkit-overflow-scrolling: touch;
            display: flex;
            flex-direction: column;
        }

        .pos-cart-header {
            padding: 20px;
            border-bottom: 1px solid #e2e8f0;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-shrink: 0;
        }

        .pos-cart-header h2 {
            margin: 0;
            font-size: 18px;
            font-weight: 700;
            color: #1e293b;
        }

        .pos-btn-clear {
            background: #fff7f7;
            color: #c2414d;
            border: 1px solid #fecdd3;
            padding: 6px 12px;
            border-radius: 10px;
            font-size: 12px;
            font-weight: 700;
            cursor: pointer;
            transition: all 0.2s;
        }

        .pos-btn-clear:hover {
            background: #ffe4e6;
            border-color: #fda4af;
        }

        .pos-customer-section {
            padding: 16px 20px;
            border-bottom: 1px solid #e2e8f0;
            background: #f8fafc;
            flex-shrink: 0;
        }

        .pos-guest-name-wrap {
            margin-top: 10px;
        }

        .pos-guest-name-wrap label {
            display: block;
            font-size: 0.72rem;
            font-weight: 600;
            letter-spacing: 0.04em;
            text-transform: uppercase;
            color: #64748b;
            margin-bottom: 6px;
        }

        .pos-guest-name-input {
            width: 100%;
            box-sizing: border-box;
            border: 1px solid #cbd5e1;
            border-radius: 10px;
            padding: 10px 12px;
            font-size: 0.95rem;
            color: #0f172a;
            background: #fff;
        }

        .pos-guest-name-input:focus {
            outline: none;
            border-color: var(--staff-primary, #2563eb);
            box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.12);
        }

        .pos-customer-label {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 10px;
            font-size: 12px;
            font-weight: 700;
            color: #64748b;
            text-transform: uppercase;
            letter-spacing: 0.04em;
        }

        .pos-section-label {
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }

        .pos-section-label i {
            color: var(--staff-primary);
            font-size: 13px;
            opacity: 0.9;
            width: 16px;
            text-align: center;
        }

        .pos-btn-link {
            background: none;
            border: none;
            color: var(--staff-primary);
            font-size: 12px;
            font-weight: 600;
            cursor: pointer;
            padding: 0;
        }

        .pos-btn-link:hover {
            text-decoration: underline;
        }

        .pos-cart-list {
            flex: 0 0 auto;
            max-height: min(260px, 32vh);
            min-height: 72px;
            overflow-y: auto;
            overflow-x: hidden;
            padding: 12px 18px 14px;
            -webkit-overflow-scrolling: touch;
        }

        .pos-empty-state {
            min-height: 140px;
            padding: 24px 12px;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            color: #94a3b8;
            text-align: center;
        }

        .pos-empty-state i {
            font-size: 40px;
            margin-bottom: 10px;
            color: #cbd5e1;
        }

        .pos-empty-state p {
            margin: 0;
            font-size: 14px;
            font-weight: 600;
        }

        .pos-cart-item {
            display: block;
            padding: 12px 14px;
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            margin-bottom: 8px;
            background: #fff;
            box-shadow: 0 2px 10px rgba(15, 23, 42, 0.04);
            transition: border-color 0.2s, box-shadow 0.2s, background 0.2s;
        }

        .pos-cart-item:hover {
            border-color: rgba(var(--staff-accent-rgb), 0.35);
            background: #fcfdfe;
            box-shadow: 0 8px 20px rgba(15, 23, 42, 0.06);
        }

        .pos-cart-item-top {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 10px;
            margin-bottom: 10px;
        }

        .pos-cart-item-bottom {
            display: grid;
            grid-template-columns: minmax(0, 1fr) auto minmax(72px, auto);
            gap: 10px;
            align-items: center;
        }

        .pos-item-details {
            flex: 1;
            min-width: 0;
        }

        .pos-item-name {
            font-size: 15px;
            font-weight: 700;
            color: #0f172a;
            margin-bottom: 4px;
            line-height: 1.35;
            display: -webkit-box;
            -webkit-line-clamp: 2;
            -webkit-box-orient: vertical;
            overflow: hidden;
        }

        .pos-item-meta {
            display: none !important;
        }

        .pos-item-price {
            font-size: 13px;
            color: #475569;
            font-weight: 600;
        }

        .pos-btn-set-price {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            margin-top: 2px;
            padding: 6px 12px;
            background: #edf4fc;
            border: 1px solid #bfdbfe;
            border-radius: 999px;
            font-size: 12px;
            font-weight: 700;
            color: #2f6fae;
            cursor: pointer;
            transition: background 0.2s, border-color 0.2s, color 0.2s;
        }

        .pos-btn-set-price:hover {
            background: #dbeafe;
            border-color: #93c5fd;
            color: #1d4ed8;
        }

        .pos-btn-set-price i {
            font-size: 11px;
        }

        .pos-item-controls {
            display: flex;
            align-items: center;
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 10px;
            overflow: hidden;
            flex-shrink: 0;
        }

        .pos-qty-btn {
            background: none;
            border: none;
            width: 28px;
            height: 28px;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            color: #475569;
        }

        .pos-qty-btn:hover {
            background: #e2e8f0;
        }

        .pos-qty-val {
            width: 48px;
            text-align: center;
            font-size: 14px;
            font-weight: 600;
            border: 1px solid transparent;
            border-radius: 5px;
            background: transparent;
            color: #0f172a;
            outline: none;
            padding: 3px 2px;
        }

        .pos-qty-val:focus {
            border-color: #93c5fd;
            background: #fff;
        }

        .pos-qty-warning {
            color: #b91c1c;
            font-size: 11px;
            font-weight: 600;
            margin-top: 3px;
            min-height: 0;
        }

        .pos-qty-warning:empty {
            display: none;
        }

        .pos-item-total {
            font-weight: 800;
            font-size: 15px;
            min-width: 72px;
            text-align: right;
            color: #0f172a;
            flex-shrink: 0;
        }

        .pos-item-remove {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            color: #dc2626;
            background: #fef2f2;
            border: 1px solid #fecaca;
            border-radius: 8px;
            cursor: pointer;
            padding: 6px 10px;
            font-size: 12px;
            font-weight: 600;
            line-height: 1;
            flex-shrink: 0;
            transition: background 0.2s, border-color 0.2s, color 0.2s, box-shadow 0.2s;
        }

        .pos-item-remove i {
            font-size: 12px;
        }

        .pos-item-remove:hover {
            background: #fee2e2;
            border-color: #fca5a5;
            color: #b91c1c;
            box-shadow: 0 2px 8px rgba(220, 38, 38, 0.12);
        }

        .pos-checkout-section {
            padding: 14px 20px 16px;
            background: #f8fafc;
            border-top: 1px solid #e2e8f0;
            flex: 0 0 auto;
        }

        .pos-payment-summary {
            display: flex;
            flex-direction: column;
            gap: 10px;
            margin-bottom: 0;
        }

        .pos-checkout-footer {
            padding-top: 10px;
            margin-top: 10px;
            border-top: 1px dashed #e2e8f0;
        }

        @media (max-height: 800px) {
            .pos-checkout-section {
                padding: 10px 16px 12px;
            }

            .pos-cart-list {
                max-height: min(220px, 28vh);
                min-height: 64px;
            }

            .pos-payment-tabs {
                margin: 12px 0;
            }

            .pos-summary-total {
                margin-top: 12px;
                padding-top: 12px;
                font-size: 18px;
            }

            .service-btn {
                padding: 20px 16px;
                border-radius: 12px;
                font-size: 14px;
                gap: 8px;
            }

            .service-btn i {
                width: 48px;
                height: 48px;
                font-size: 24px;
                border-radius: 10px;
            }

            .pos-tender-group {
                margin-bottom: 12px;
            }

            .pos-btn-checkout {
                padding: 12px;
            }
        }

        .pos-summary-line {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 12px;
            font-size: 14px;
            color: #475569;
        }

        .pos-summary-label {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            font-weight: 600;
            color: #475569;
        }

        .pos-summary-label i {
            color: var(--staff-primary);
            font-size: 13px;
            opacity: 0.9;
            width: 16px;
            text-align: center;
        }

        .pos-summary-icon {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 18px;
            height: 18px;
            color: var(--staff-primary);
            flex-shrink: 0;
        }

        .pos-summary-icon svg {
            width: 16px;
            height: 16px;
            stroke: currentColor;
            fill: none;
            stroke-width: 2;
            stroke-linecap: round;
            stroke-linejoin: round;
        }

        .pos-summary-amount {
            font-weight: 700;
            color: #1e293b;
            text-align: right;
        }

        .pos-summary-total {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 12px;
            padding-top: 14px;
            border-top: 1px solid #e2e8f0;
            font-size: 22px;
            font-weight: 800;
            color: #0f172a;
        }

        .pos-summary-total .pos-summary-label {
            font-size: 15px;
            font-weight: 700;
            color: #334155;
        }

        .pos-summary-total .pos-summary-amount {
            font-size: 22px;
            font-weight: 800;
            color: #0f172a;
        }

        .pos-summary-change {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 12px;
        }

        .pos-summary-change .pos-summary-amount {
            font-size: 20px;
            font-weight: 800;
            color: var(--staff-primary);
        }

        .pos-tender-group {
            display: grid;
            grid-template-columns: minmax(0, 1fr) auto;
            gap: 12px;
            align-items: center;
            margin-bottom: 0;
        }

        .pos-payment-field {
            min-width: 148px;
            width: 100%;
            max-width: 180px;
            justify-self: end;
        }

        .pos-qrph-payment-section {
            display: none;
            margin-top: 12px;
            padding: 12px 14px;
            border: 1px solid #99f6e4;
            border-radius: 12px;
            background: #ecfdf5;
            text-align: left;
        }

        .pos-qrph-payment-section.is-visible {
            display: block;
        }

        .pos-qrph-payment-section[hidden] {
            display: none !important;
        }

        .pos-qrph-generate-btn {
            margin-top: 0;
            width: 100%;
            padding: 10px 12px;
            border: 0;
            border-radius: 10px;
            background: #0f766e;
            color: #fff;
            font-weight: 800;
            font-size: 13px;
            cursor: pointer;
        }

        .pos-qrph-generate-btn:disabled {
            background: #94a3b8;
            cursor: not-allowed;
        }

        .pos-tender-wrap {
            position: relative;
            min-width: 148px;
            max-width: 180px;
            width: 100%;
            justify-self: end;
        }

        .pos-tender-prefix {
            position: absolute;
            left: 12px;
            top: 50%;
            transform: translateY(-50%);
            font-weight: 700;
            color: #94a3b8;
            pointer-events: none;
        }

        .pos-tender-input {
            width: 100%;
            min-width: 148px;
            max-width: 180px;
            height: 44px;
            padding: 0 12px 0 28px;
            border: 1px solid #cbd5e1;
            border-radius: 10px;
            text-align: right;
            font-weight: 700;
            font-size: 16px;
            outline: none;
            box-sizing: border-box;
            background: #ffffff;
        }

        .pos-tender-input:focus {
            border-color: var(--staff-primary);
            box-shadow: 0 0 0 3px rgba(var(--staff-accent-rgb), 0.12);
        }



        .pos-btn-checkout {
            width: 100%;
            min-height: 52px;
            padding: 14px 18px;
            background: var(--staff-pos-button-bg);
            color: white;
            border: none;
            border-radius: 12px;
            font-size: 16px;
            font-weight: 700;
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 10px;
            cursor: pointer;
            transition: all 0.2s;
            box-shadow: 0 10px 24px var(--staff-pos-button-shadow);
        }

        .pos-btn-checkout:hover {
            filter: brightness(0.98);
            transform: translateY(-1px);
            box-shadow: 0 16px 32px rgba(15, 23, 42, 0.14);
        }

        .pos-btn-checkout:disabled {
            background: #94a3b8;
            cursor: not-allowed;
        }

        /* Clean text-based service buttons - Shopee style */
        .service-btn {
            background: #ffffff;
            border: 2px solid #e2e8f0;
            padding: 14px 20px;
            border-radius: 10px;
            font-size: 13px;
            font-weight: 700;
            font-family: var(--pf-ui-font-sans);
            text-transform: uppercase;
            letter-spacing: 0.02em;
            text-align: center;
            cursor: pointer;
            transition: all 0.25s cubic-bezier(0.4, 0, 0.2, 1);
            color: #475569;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            position: relative;
            min-width: 0;
            min-height: 80px;
            overflow: hidden;
        }

        .service-btn span {
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
            max-width: 100%;
            display: block;
        }

        .service-btn:hover {
            transform: translateY(-4px) scale(1.02);
            border-color: var(--staff-primary);
            box-shadow: 0 20px 25px -5px rgba(var(--staff-accent-rgb), 0.18), 0 10px 10px -5px rgba(var(--staff-accent-rgb), 0.12);
        }

        .service-btn.active,
        .service-btn:active {
            border-color: var(--staff-primary);
            background: var(--staff-toolbar-active-bg);
            color: var(--staff-primary);
            box-shadow: 0 0 0 2px rgba(var(--staff-accent-rgb), 0.24);
        }

        .service-btn.btn-other {
            background: #f8fafc;
            border: 2px dashed #cbd5e1;
            color: #64748b;
        }

        .service-btn.btn-other:hover,
        .service-btn.btn-other.active {
            border-style: solid;
            border-color: var(--staff-primary);
        }

        .pos-services-grid {
            flex: 1;
            overflow-y: auto;
            align-content: start;
            min-height: 0;
        }

        .pos-cart-header .pf-pos-cart-icon {
            color: var(--staff-primary);
            margin-right: 8px;
            font-size: 16px;
        }

        /* Price Input Modal */
        #price-modal-overlay {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(0, 0, 0, 0.75);
            z-index: 1000;
            align-items: center;
            justify-content: center;
        }

        .price-modal {
            background: #fff;
            width: 320px;
            border-radius: 20px;
            padding: 28px;
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.15);
            color: #1e293b;
            border: 1px solid #e2e8f0;
        }

        /* Hide scrollbar for grid to look cleaner */
        .pos-products-grid::-webkit-scrollbar,
        .pos-services-grid::-webkit-scrollbar,
        .pos-cart-list::-webkit-scrollbar {
            width: 6px;
        }

        .pos-products-grid::-webkit-scrollbar-thumb,
        .pos-services-grid::-webkit-scrollbar-thumb,
        .pos-cart-list::-webkit-scrollbar-thumb {
            background: #cbd5e1;
            border-radius: 3px;
        }

        /* Select2 Custom Styling */
        .pos-customer-select-wrap .select2-container {
            width: 100% !important;
        }

        .pos-customer-select-wrap .select2-container--default .select2-selection--single {
            border: 1px solid #cbd5e1;
            border-radius: 10px;
            height: 44px;
            padding: 8px 40px 8px 12px;
            background: #ffffff;
            cursor: pointer;
            transition: border-color 0.2s, box-shadow 0.2s;
            position: relative;
        }

        .pos-customer-select-wrap .select2-container--default .select2-selection--single .select2-selection__rendered {
            line-height: 26px;
            color: #1e293b;
            padding-left: 28px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            display: block;
            position: relative;
        }

        .pos-customer-select-wrap .select2-container--default .select2-selection--single .select2-selection__rendered::before {
            content: '\f007';
            font-family: 'Font Awesome 6 Free';
            font-weight: 900;
            position: absolute;
            left: 12px;
            top: 50%;
            transform: translateY(-50%);
            color: var(--staff-primary);
            font-size: 13px;
        }

        .pos-customer-select-wrap .select2-container--default .select2-selection--single .select2-selection__arrow {
            height: 42px;
            right: 8px;
        }

        .pos-customer-select-wrap .select2-container--default.select2-container--focus .select2-selection--single,
        .pos-customer-select-wrap .select2-container--default.select2-container--open .select2-selection--single {
            border-color: var(--staff-primary);
            box-shadow: 0 0 0 3px rgba(var(--staff-accent-rgb), 0.12);
        }

        .select2-dropdown.pos-customer-select-dropdown {
            border: 1px solid #cbd5e1;
            border-radius: 12px;
            box-shadow: 0 16px 40px rgba(15, 23, 42, 0.12);
            overflow: hidden;
            z-index: 10060 !important;
        }

        .select2-dropdown.pos-customer-select-dropdown .select2-search--dropdown {
            padding: 12px 12px 8px;
            position: relative;
        }

        .select2-dropdown.pos-customer-select-dropdown .select2-search--dropdown::before {
            content: '\f002';
            font-family: 'Font Awesome 6 Free';
            font-weight: 900;
            position: absolute;
            left: 22px;
            top: calc(50% - 2px);
            transform: translateY(-50%);
            color: #94a3b8;
            font-size: 13px;
            pointer-events: none;
            z-index: 2;
        }

        .select2-dropdown.pos-customer-select-dropdown .select2-search__field {
            width: 100% !important;
            padding: 11px 12px 11px 36px;
            border: 1px solid #cbd5e1;
            border-radius: 10px;
            background: #f8fafc;
            color: #1e293b;
            font-size: 14px;
            outline: none;
            box-sizing: border-box;
        }

        .select2-dropdown.pos-customer-select-dropdown .select2-search__field:focus {
            border-color: var(--staff-primary);
            background: #ffffff;
            box-shadow: 0 0 0 3px rgba(var(--staff-accent-rgb), 0.12);
        }

        .select2-dropdown.pos-customer-select-dropdown .select2-results__option {
            padding: 10px 14px;
        }

        .pos-customer-option__name {
            font-size: 14px;
            font-weight: 700;
            color: #0f172a;
            line-height: 1.35;
        }

        .pos-customer-option__email {
            font-size: 12px;
            color: #64748b;
            margin-top: 2px;
            line-height: 1.35;
            word-break: break-word;
        }

        .pos-customer-option--guest .pos-customer-option__name {
            font-weight: 600;
        }

        .select2-container--default .select2-results__option--highlighted[aria-selected] .pos-customer-option__name,
        .select2-container--default .select2-results__option--highlighted[aria-selected] .pos-customer-option__email {
            color: #ffffff;
        }

        .select2-container--default .select2-selection--single {
            border: 1px solid #cbd5e1;
            border-radius: 8px;
            height: 44px;
            padding: 8px 12px;
        }

        .select2-container--default .select2-selection--single .select2-selection__rendered {
            line-height: 28px;
            color: #1e293b;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            display: block;
        }

        .select2-container--default .select2-selection--single .select2-selection__arrow {
            height: 42px;
        }

        .select2-container--default.select2-container--focus .select2-selection--single {
            border-color: var(--staff-primary);
        }

        .select2-dropdown {
            border: 1px solid #cbd5e1;
            border-radius: 8px;
        }

        .select2-container--default .select2-results__option--highlighted[aria-selected] {
            background-color: var(--staff-primary);
        }

        #customer-modal {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(0, 0, 0, 0.75);
            z-index: 10070;
            align-items: center;
            justify-content: center;
            padding: 16px;
            box-sizing: border-box;
        }

        #customer-modal .pos-customer-modal-card {
            background: #ffffff;
            width: min(450px, calc(100vw - 32px));
            max-height: calc(100dvh - 32px);
            overflow-y: auto;
            border-radius: 20px;
            padding: 28px;
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.15);
            color: #1e293b;
            border: 1px solid #e2e8f0;
        }

        #customer-modal .pos-customer-modal-lead {
            margin: 0 0 8px;
            font-size: 14px;
            color: #475569;
            line-height: 1.55;
        }

        #customer-modal .pos-customer-modal-help {
            margin: 0 0 20px;
            padding: 12px 14px;
            border-radius: 10px;
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            font-size: 12px;
            color: #64748b;
            line-height: 1.5;
        }

        /* Custom Alert/Confirm Modal */
        #pos-alert-overlay {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(0, 0, 0, 0.4);
            backdrop-filter: blur(4px);
            z-index: 10100;
            align-items: center;
            justify-content: center;
            opacity: 0;
            transition: opacity 0.2s ease;
        }

        #pos-alert-box {
            background: #ffffff;
            width: 400px;
            border-radius: 24px;
            padding: 32px;
            text-align: center;
            box-shadow: 0 20px 50px rgba(0, 0, 0, 0.15);
            border: 1px solid rgba(255, 255, 255, 0.8);
            transform: translateY(20px);
            transition: transform 0.3s cubic-bezier(0.175, 0.885, 0.32, 1.275);
        }

        .pos-alert-icon-container {
            width: 64px;
            height: 64px;
            border-radius: 32px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 20px;
        }

        #pos-alert-icon {
            font-size: 30px;
            line-height: 1;
            display: block;
        }

        .pos-alert-title {
            margin: 0 0 10px;
            font-weight: 800;
            color: #0f172a;
            font-size: 20px;
            letter-spacing: -0.02em;
        }

        .pos-alert-message {
            margin: 0 0 24px;
            color: #64748b;
            font-size: 15px;
            line-height: 1.6;
        }

        .pos-alert-btn {
            flex: 1;
            padding: 12px;
            border: none;
            border-radius: 12px;
            font-weight: 700;
            cursor: pointer;
            transition: all 0.2s;
        }

        #pos-scan-toast-container {
            position: fixed;
            top: 18px;
            right: 18px;
            z-index: 10050;
            display: flex;
            flex-direction: column;
            gap: 10px;
            pointer-events: none;
            max-width: min(380px, calc(100vw - 32px));
        }

        .pos-scan-toast {
            background: #ffffff;
            border: 1px solid #e2e8f0;
            border-left: 4px solid var(--staff-primary);
            border-radius: 10px;
            box-shadow: 0 12px 30px rgba(15, 23, 42, 0.16);
            padding: 12px 14px;
            display: flex;
            gap: 10px;
            align-items: flex-start;
            transform: translateX(16px);
            opacity: 0;
            transition: opacity .18s ease, transform .18s ease;
            pointer-events: auto;
        }

        .pos-scan-toast.show { opacity: 1; transform: translateX(0); }
        .pos-scan-toast.warning { border-left-color: #f59e0b; }
        .pos-scan-toast.error { border-left-color: #ef4444; }
        .pos-scan-toast.success { border-left-color: #10b981; }
        .pos-scan-toast-icon { width: 30px; height: 30px; border-radius: 999px; display:flex; align-items:center; justify-content:center; flex-shrink:0; }
        .pos-scan-toast.warning .pos-scan-toast-icon { background:#fef3c7; color:#f59e0b; }
        .pos-scan-toast.error .pos-scan-toast-icon { background:#fee2e2; color:#ef4444; }
        .pos-scan-toast.success .pos-scan-toast-icon { background:#dcfce7; color:#10b981; }
        .pos-scan-toast-title { font-size: 14px; font-weight: 800; color: #0f172a; margin-bottom: 2px; }
        .pos-scan-toast-message { font-size: 12px; color: #475569; line-height: 1.35; }
        /* Receipt Modal */
        #receipt-modal-overlay {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(15, 23, 42, 0.55);
            backdrop-filter: blur(6px);
            z-index: 2000;
            align-items: center;
            justify-content: center;
            padding: 24px;
        }

        .receipt-modal {
            width: min(920px, 100%);
            max-height: calc(100vh - 48px);
            background: #ffffff;
            border: 1px solid #dbe4f0;
            border-radius: 28px;
            box-shadow: 0 30px 80px rgba(15, 23, 42, 0.18);
            overflow: hidden;
            display: flex;
            flex-direction: column;
        }

        .receipt-modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 16px;
            padding: 24px 28px;
            border-bottom: 1px solid #e2e8f0;
            background: linear-gradient(135deg, #f8fffe 0%, #eefaf8 100%);
        }

        .receipt-modal-actions {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }

        .receipt-action-btn {
            border: 1px solid #dbe4f0;
            background: #ffffff;
            color: #0f172a;
            border-radius: 12px;
            padding: 10px 16px;
            font-size: 13px;
            font-weight: 700;
            cursor: pointer;
        }

        .receipt-action-btn--primary {
            background: var(--staff-pos-button-bg);
            border-color: var(--staff-primary);
            color: #ffffff;
        }

        .receipt-modal-body {
            padding: 28px;
            overflow: auto;
            background: linear-gradient(180deg, #f4f8f7 0%, #eef4f3 100%);
        }

        .receipt-printer-stage {
            position: relative;
            width: min(320px, 100%);
            margin: 0 auto;
        }

        .receipt-printer-slot {
            height: 12px;
            background: linear-gradient(180deg, #334155 0%, #0f172a 55%, #020617 100%);
            border-radius: 5px 5px 0 0;
            box-shadow:
                inset 0 2px 5px rgba(0, 0, 0, 0.55),
                0 2px 6px rgba(15, 23, 42, 0.22);
            position: relative;
            z-index: 3;
            flex-shrink: 0;
        }

        .receipt-printer-viewport {
            overflow: hidden;
            position: relative;
            z-index: 1;
            margin-top: -1px;
        }

        .receipt-sheet.receipt-feed-active {
            will-change: transform;
        }

        .receipt-printer-viewport.receipt-feed-empty {
            min-height: 0;
        }

        @media (prefers-reduced-motion: reduce) {
            .receipt-sheet.receipt-feed-active {
                transition: none !important;
            }
        }

        .receipt-sheet {
            width: 58mm;
            max-width: 100%;
            margin: 0 auto;
            background: #ffffff;
            border: 1px solid #d8e2df;
            border-radius: 8px;
            padding: 4mm;
            box-shadow: 0 16px 40px rgba(15, 23, 42, 0.08);
            font-family: "Courier New", "Liberation Mono", Consolas, monospace;
            color: #0f172a;
            font-size: 11px;
            line-height: 1.25;
            overflow-wrap: anywhere;
            word-break: break-word;
            box-sizing: border-box;
        }

        .receipt-header {
            text-align: center;
            padding-bottom: 6px;
            border-bottom: 1px dashed #111827;
        }

        .receipt-logo {
            width: 34px;
            height: 34px;
            border-radius: 4px;
            object-fit: cover;
            border: 1px solid #dbe4f0;
            background: #ffffff;
            margin: 0 auto 4px;
        }

        .receipt-brand-name {
            font-size: 13px;
            font-weight: 800;
            letter-spacing: 0;
            text-transform: uppercase;
            color: #00232b;
        }

        .receipt-branch {
            margin-top: 2px;
            font-size: 10px;
            font-weight: 700;
            color: #0f766e;
        }

        .receipt-company-meta {
            margin-top: 4px;
            font-size: 9px;
            line-height: 1.25;
            color: #475569;
        }

        .receipt-pill {
            display: block;
            padding: 0;
            border: 0;
            background: transparent;
            color: #111827;
            font-size: 9px;
            font-weight: 800;
            letter-spacing: 0;
            text-transform: uppercase;
            margin-top: 5px;
        }

        .receipt-subtext {
            margin-top: 2px;
            font-size: 8px;
            font-weight: 700;
            color: #334155;
            text-transform: uppercase;
        }

        .receipt-disclaimer {
            margin-top: 4px;
            font-size: 8px;
            line-height: 1.35;
            color: #475569;
        }

        .receipt-section {
            padding-top: 6px;
            margin-top: 6px;
            border-top: 1px dashed #111827;
        }

        .receipt-section-title {
            font-size: 9px;
            color: #111827;
            text-transform: uppercase;
            letter-spacing: 0;
            font-weight: 800;
            margin-bottom: 4px;
            text-align: center;
        }

        .receipt-info-grid {
            display: block;
        }

        .receipt-qr-wrap { text-align:center; margin:0 auto 12px; }
        .receipt-qr-wrap > div { display:inline-block; padding:6px; background:#fff; border:1px solid #e2e8f0; border-radius:8px; }
        .receipt-qr-wrap canvas, .receipt-qr-wrap img { display:block; width:116px !important; height:116px !important; }
        .receipt-qr-caption { margin-top:4px; color:#64748b; font-size:9px; }

        .receipt-online-store {
            margin-top: 8px;
            padding-top: 8px;
            border-top: 1px dashed #111827;
            text-align: center;
            overflow: hidden;
        }

        .receipt-online-store .receipt-qr-wrap { margin: 6px auto; }
        .receipt-online-store-title { font-size: 10px; font-weight: 800; color: #00232b; }
        .receipt-online-store-url { margin-top: 2px; font-size: 9px; color: #0f172a; overflow-wrap: anywhere; }

        .receipt-info-card {
            display: flex;
            justify-content: space-between;
            gap: 6px;
            border: 0;
            border-radius: 0;
            padding: 1px 0;
            background: transparent;
            min-width: 0;
        }

        .receipt-label {
            flex: 0 0 auto;
            font-size: 10px;
            color: #111827;
            text-transform: none;
            letter-spacing: 0;
            font-weight: 700;
            margin-bottom: 0;
        }

        .receipt-value {
            color: #0f172a;
            font-size: 10px;
            line-height: 1.25;
            text-align: right;
            min-width: 0;
            overflow-wrap: anywhere;
        }

        .receipt-value--strong {
            font-size: 10px;
            font-weight: 800;
        }

        .receipt-customer {
            display: block;
        }

        .receipt-customer-name {
            font-size: 11px;
            font-weight: 800;
            color: #0f172a;
            overflow-wrap: anywhere;
        }

        .receipt-payment-chip {
            display: block;
            margin-top: 3px;
            padding: 0;
            border-radius: 0;
            background: transparent;
            color: #111827;
            font-size: 10px;
            font-weight: 700;
            letter-spacing: 0;
            text-transform: uppercase;
        }

        .receipt-items {
            width: 100%;
            table-layout: fixed;
            border-collapse: collapse;
            margin-top: 2px;
            border-top: 1px solid #111827;
        }

        .receipt-items th,
        .receipt-items td {
            padding: 3px 1px;
            border-bottom: 1px dashed #111827;
            font-size: 9px;
            line-height: 1.2;
            vertical-align: top;
            overflow-wrap: anywhere;
            word-break: break-word;
        }

        .receipt-items th {
            color: #111827;
            font-size: 8px;
            text-transform: uppercase;
            letter-spacing: 0;
            font-weight: 800;
            padding-top: 2px;
            padding-bottom: 2px;
        }

        .receipt-items th:first-child,
        .receipt-items td:first-child {
            width: 43%;
            text-align: left;
        }

        .receipt-items th:nth-child(2),
        .receipt-items td:nth-child(2) {
            width: 10%;
            text-align: center;
            white-space: nowrap;
        }

        .receipt-items th:nth-child(3),
        .receipt-items td:nth-child(3) {
            width: 22%;
            text-align: right;
            white-space: nowrap;
            font-variant-numeric: tabular-nums;
        }

        .receipt-items th:nth-child(4),
        .receipt-items td:nth-child(4) {
            width: 25%;
            text-align: right;
            white-space: nowrap;
            font-variant-numeric: tabular-nums;
        }

        .receipt-item-name {
            font-weight: 800;
            color: #0f172a;
            font-size: 10px;
            overflow-wrap: anywhere;
        }

        .receipt-item-meta {
            margin-top: 2px;
            font-size: 9px;
            line-height: 1.25;
            color: #64748b;
            white-space: normal;
            overflow-wrap: anywhere;
        }

        .receipt-line-items {
            border-top: 1px solid #111827;
        }

        .receipt-item {
            padding: 4px 0;
            border-bottom: 1px dashed #111827;
        }

        .receipt-item-amounts {
            display: grid;
            grid-template-columns: minmax(0, 1fr) auto;
            gap: 6px;
            margin-top: 2px;
            font-size: 10px;
        }

        .receipt-money {
            text-align: right;
            white-space: nowrap;
            font-variant-numeric: tabular-nums;
        }

        .receipt-summary {
            margin-top: 4px;
            margin-left: 0;
            width: 100%;
        }

        .receipt-total-line {
            display: flex;
            justify-content: space-between;
            gap: 6px;
            padding: 2px 0;
            border-bottom: 0;
            font-size: 10px;
            overflow-wrap: anywhere;
        }

        .receipt-total-line > span:first-child {
            min-width: 0;
        }

        .receipt-total-line > strong,
        .receipt-total-line > span:last-child {
            text-align: right;
            font-variant-numeric: tabular-nums;
        }

        .receipt-total-line--grand {
            margin-top: 4px;
            padding: 4px 0 2px;
            border-top: 1px solid #111827;
            border-radius: 0;
            background: transparent;
            font-size: 12px;
            font-weight: 800;
            color: #00232b;
        }

        .receipt-payment-breakdown {
            margin-top: 4px;
            padding: 4px 0 0;
            border-top: 1px dashed #111827;
            border-radius: 0;
            background: transparent;
        }

        .receipt-footer {
            margin-top: 8px;
            padding-top: 6px;
            border-top: 1px dashed #111827;
            text-align: center;
        }

        .receipt-footer strong {
            display: block;
            font-size: 10px;
            color: #00232b;
            margin-bottom: 2px;
        }

        .receipt-footer p {
            margin: 0;
            font-size: 9px;
            line-height: 1.25;
            color: #64748b;
        }

        @media print {
            @page {
                size: 58mm auto;
                margin: 0;
            }

            html,
            body {
                width: 58mm !important;
                min-width: 58mm !important;
                margin: 0 !important;
                padding: 0 !important;
                background: #ffffff !important;
                overflow: visible !important;
            }

            body * {
                visibility: hidden !important;
            }

            #receipt-print-area {
                visibility: visible !important;
                position: absolute !important;
                left: 0;
                top: 0;
                width: 58mm !important;
                max-width: 58mm !important;
                margin: 0 !important;
                padding: 0 3mm !important;
                box-sizing: border-box !important;
                color: #000000 !important;
                background: #ffffff !important;
            }

            #receipt-print-area,
            #receipt-print-area * {
                visibility: visible !important;
                box-shadow: none !important;
                text-shadow: none !important;
                color: #000000 !important;
            }

            .receipt-sheet {
                width: 52mm !important;
                max-width: 52mm !important;
                border: none !important;
                border-radius: 0 !important;
                box-shadow: none !important;
                padding: 0 !important;
                margin: 0 auto !important;
                font-size: 10px !important;
                line-height: 1.2 !important;
                overflow: visible !important;
            }

            .receipt-modal-body {
                background: #ffffff !important;
                padding: 0 !important;
            }

            .receipt-modal,
            #receipt-modal-overlay {
                display: block !important;
                position: static !important;
                background: #ffffff !important;
                border: 0 !important;
                padding: 0 !important;
                margin: 0 !important;
                width: 58mm !important;
                max-width: 58mm !important;
                max-height: none !important;
                overflow: visible !important;
            }

            .receipt-modal-header,
            .receipt-action-btn,
            .receipt-modal-actions {
                display: none !important;
            }
        }

        @media (max-width: 720px) {
            .receipt-modal-header,
            .receipt-modal-body {
                padding: 18px;
            }

            .receipt-sheet {
                padding: 18px 16px;
                border-radius: 16px;
            }

            .receipt-info-grid {
                grid-template-columns: 1fr;
            }

            .receipt-customer {
                flex-direction: column;
            }
        }

        /* Tablet: slightly narrower cart, keep side-by-side */
        @media (min-width: 768px) and (max-width: 1024px) {
            .pos-wrapper {
                grid-template-columns: minmax(0, 1fr) minmax(300px, 36vw);
            }
        }

        /* Mobile: stack catalog above cart — single page scroll */
        @media (max-width: 768px) {
            html:has(body.pf-pos-page),
            body.pf-pos-page {
                height: auto !important;
                min-height: 100dvh;
                min-height: 100svh;
                overflow-x: hidden !important;
                overflow-y: auto !important;
                -webkit-overflow-scrolling: touch;
            }

            body.pf-pos-page .dashboard-container {
                height: auto !important;
                min-height: 100dvh;
                min-height: 100svh;
                overflow: visible !important;
            }

            body.pf-pos-page .main-content.pos-main-shell {
                height: auto !important;
                min-height: 0 !important;
                max-height: none !important;
                overflow: visible !important;
                overflow-y: visible !important;
                padding-bottom: calc(16px + env(safe-area-inset-bottom, 0px));
            }

            .pos-main-shell > main {
                flex: none !important;
                height: auto !important;
                min-height: 0 !important;
                overflow: visible !important;
            }

            .pos-main-shell {
                height: auto !important;
                min-height: 0 !important;
                overflow: visible !important;
            }

            .pos-wrapper {
                grid-template-columns: 1fr !important;
                height: auto !important;
                min-height: 0 !important;
                flex: none !important;
                overflow: visible !important;
            }

            .pos-products-area {
                flex: none !important;
                height: auto !important;
                min-height: 0 !important;
                overflow: visible !important;
                border-right: none;
                border-bottom: none;
            }

            #selection-view,
            #products-view,
            #services-view {
                height: auto !important;
                min-height: 0 !important;
                flex: none !important;
            }

            #selection-view {
                align-items: stretch !important;
                justify-content: flex-start !important;
                padding: 24px 16px 16px !important;
            }

            .pos-catalog-grid,
            .pos-products-grid,
            .pos-services-grid {
                flex: none !important;
                min-height: 0 !important;
                overflow: visible !important;
                grid-template-columns: repeat(2, minmax(0, 1fr)) !important;
                padding: 14px !important;
                gap: 10px !important;
            }

            .pos-products-grid .pos-catalog-card {
                min-height: 0;
            }

            .pos-products-grid .pos-catalog-card__media {
                height: 108px;
                min-height: 108px;
                max-height: 108px;
            }

            .pos-products-grid .pos-catalog-card__body {
                min-height: 0;
            }

            .pos-services-grid .pos-catalog-card {
                min-height: 228px;
            }

            .pos-services-grid .pos-catalog-card__media {
                height: 104px;
                min-height: 104px;
                max-height: 104px;
            }

            .pos-services-grid .pos-catalog-card__body {
                min-height: 88px;
            }

            .pos-cart-area {
                width: 100%;
                max-width: none;
                border-left: none;
                border-top: 4px solid #e2e8f0;
                flex: none !important;
                height: auto;
                min-height: 0 !important;
                max-height: none;
                overflow: visible;
            }

            .pos-cart-body {
                flex: none;
                min-height: 0;
                overflow: visible;
            }

            .pos-cart-list {
                max-height: min(240px, 40vh) !important;
                overflow-y: auto !important;
                min-height: 72px;
            }

            .pos-checkout-section {
                position: static;
                box-shadow: none;
            }

            .pos-cart-area {
                padding-bottom: env(safe-area-inset-bottom, 0px);
            }

            .pos-search-header {
                grid-template-columns: 1fr;
                padding: 14px !important;
                gap: 10px !important;
            }

            .pos-toolbar-sku,
            .pos-toolbar-search,
            .pos-toolbar-category,
            .pos-btn-back {
                grid-column: 1 / -1;
            }

            .pos-search-box,
            .pos-barcode-scan,
            .pos-search-header .pos-category-select,
            .pos-search-header .pos-btn-back {
                width: 100% !important;
                max-width: none !important;
            }

            .pos-btn-back {
                justify-content: center;
            }

            .pos-cart-item-bottom {
                grid-template-columns: 1fr;
                gap: 10px;
            }

            .pos-item-total {
                text-align: left;
            }

            .pos-tender-group {
                grid-template-columns: 1fr;
            }

            .pos-payment-field,
            .pos-tender-wrap,
            .pos-tender-input {
                max-width: none !important;
                justify-self: stretch;
            }

            .pos-services-header {
                flex-direction: column;
                align-items: stretch !important;
                text-align: center;
                gap: 12px !important;
                padding: 14px !important;
            }

            .pos-services-header button {
                width: 100% !important;
                justify-content: center;
            }

            .pos-qty-btn {
                width: 44px;
                height: 44px;
            }

            .pos-item-remove,
            .pos-btn-clear,
            .pos-btn-checkout {
                min-height: 44px;
            }
        }
    </style>
</head>

<body class="pf-pos-page" data-turbo="false" data-csrf="<?php echo htmlspecialchars($pos_csrf_token, ENT_QUOTES, 'UTF-8'); ?>">

    <div class="dashboard-container">
        <?php
        if ($_SESSION['user_type'] === 'Staff') {
            include __DIR__ . '/../includes/staff_sidebar.php';
        } else {
            include __DIR__ . '/../includes/admin_sidebar.php';
        }
        ?>

        <div class="main-content pos-main-shell"
            style="padding: 0; height: 100vh; overflow: hidden; display: flex; flex-direction: column; width: 100%; min-height: 0;">
            
            <!-- Mobile Header for Burger Menu Injection -->
            <header style="display: none; border-bottom: 1px solid #e2e8f0;">
                <div class="pf-mobile-title-group" style="display:flex; align-items:center; flex:1; min-width:0; margin-left: 12px;">
                    <h1 class="page-title" style="margin: 0; font-size: 18px; color: #1e293b; font-weight: 700;">Point of Sale</h1>
                </div>
            </header>

            <main style="flex: 1; display: flex; flex-direction: column; width: 100%; min-height: 0;">
                <div class="pos-wrapper" style="width: 100%; flex: 1; min-height: 0;">

                    <!-- LEFT: PRODUCTS/SERVICES (Dynamic) -->
                    <div class="pos-products-area" id="pos-left-panel" style="background:#fff;">
                        <!-- Selection Screen (Default) -->
                        <div id="selection-view"
                            style="display: flex; align-items: center; justify-content: center; height: 100%; background: #f8fafc; padding: 40px;">
                            <div style="max-width: 600px; width: 100%;">
                                <div style="text-align: center; margin-bottom: 48px;">
                                    <h1
                                        style="font-size: 1.75rem; font-weight: 700; color: #1e293b; margin-bottom: 8px;">
                                        Select Category</h1>
                                    <p style="font-size: 0.9rem; color: #64748b;">Choose products or services to add to
                                        order</p>
                                </div>

                                <div class="pos-barcode-scan" style="max-width:none;width:100%;margin:0 0 18px;">
                                    <label for="pos-barcode-input-home">Scan Barcode or Enter SKU</label>
                                    <div class="pos-barcode-box">
                                        <input type="text" id="pos-barcode-input-home" class="pos-search-input pos-barcode-entry"
                                            placeholder="Scan or type product SKU, then press Enter" autocomplete="off" inputmode="text">
                                    </div>
                                </div>

                                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px;">
                                    <!-- Products Button -->
                                    <button onclick="showPOSMode('products')"
                                        style="background: #ffffff; border: 1px solid #e2e8f0; border-radius: 18px; padding: 48px 24px; cursor: pointer; transition: all 0.2s; display: flex; flex-direction: column; align-items: center; gap: 16px; box-shadow: 0 12px 26px rgba(15,23,42,0.05);"
                                        onmouseover="this.style.borderColor='var(--staff-primary)'; this.style.transform='translateY(-2px)'; this.style.boxShadow='0 18px 34px rgba(15,23,42,0.10)';"
                                        onmouseout="this.style.borderColor='#e2e8f0'; this.style.transform='translateY(0)'; this.style.boxShadow='0 12px 26px rgba(15,23,42,0.05)';">
                                        <div
                                            style="width: 64px; height: 64px; background: var(--staff-pos-button-bg); border-radius: 50%; display: flex; align-items: center; justify-content: center;">
                                            <svg xmlns="http://www.w3.org/2000/svg" width="32" height="32"
                                                viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="2"
                                                stroke-linecap="round" stroke-linejoin="round">
                                                <path
                                                    d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z">
                                                </path>
                                                <polyline points="3.27 6.96 12 12.01 20.73 6.96"></polyline>
                                                <line x1="12" y1="22.08" x2="12" y2="12"></line>
                                            </svg>
                                        </div>
                                        <div>
                                            <h2
                                                style="font-size: 1.125rem; font-weight: 700; color: #1e293b; margin: 0 0 6px 0;">
                                                Products</h2>
                                            <p style="font-size: 0.8rem; color: #64748b; margin: 0;">Browse catalog
                                                items</p>
                                        </div>
                                    </button>

                                    <!-- Services Button -->
                                    <button onclick="showPOSMode('services')"
                                        style="background: white; border: 2px solid #e2e8f0; border-radius: 12px; padding: 48px 24px; cursor: pointer; transition: all 0.2s; display: flex; flex-direction: column; align-items: center; gap: 16px;"
                                        onmouseover="this.style.borderColor='var(--staff-primary)'; this.style.transform='translateY(-2px)'; this.style.boxShadow='0 8px 16px rgba(0,0,0,0.08)';"
                                        onmouseout="this.style.borderColor='#e2e8f0'; this.style.transform='translateY(0)'; this.style.boxShadow='none';">
                                        <div
                                            style="width: 64px; height: 64px; background: var(--staff-pos-button-bg); border-radius: 50%; display: flex; align-items: center; justify-content: center;">
                                            <svg xmlns="http://www.w3.org/2000/svg" width="32" height="32"
                                                viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="2"
                                                stroke-linecap="round" stroke-linejoin="round">
                                                <rect x="2" y="3" width="20" height="14" rx="2" ry="2"></rect>
                                                <line x1="8" y1="21" x2="16" y2="21"></line>
                                                <line x1="12" y1="17" x2="12" y2="21"></line>
                                            </svg>
                                        </div>
                                        <div>
                                            <h2
                                                style="font-size: 1.125rem; font-weight: 700; color: #1e293b; margin: 0 0 6px 0;">
                                                Services</h2>
                                            <p style="font-size: 0.8rem; color: #64748b; margin: 0;">Custom printing</p>
                                        </div>
                                    </button>
                                </div>
                            </div>
                        </div>

                        <!-- Products View -->
                        <div id="products-view" style="display: none; height: 100%; min-height: 0; flex-direction: column; overflow: hidden;">
                            <div class="pos-search-header">
                                <div class="pos-toolbar-field pos-toolbar-search" style="grid-column: 1 / span 2;">
                                    <div class="pos-search-box">
                                        <i class="fas fa-search"></i>
                                        <input type="text" id="pos-search" class="pos-search-input pos-barcode-entry"
                                            placeholder="Search by product name or SKU (Enter to scan SKU)" autocomplete="off" inputmode="search">
                                    </div>
                                </div>
                                <div class="pos-toolbar-field pos-toolbar-category">
                                    <select id="pos-category" class="pos-category-select" aria-label="Product category">
                                        <option value="">All Categories</option>
                                        <?php foreach ($categories as $cat): ?>
                                            <option value="<?= htmlspecialchars($cat['category']) ?>">
                                                <?= htmlspecialchars($cat['category']) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <button type="button" onclick="backToSelection()" class="pos-btn-back" title="Back to selection">
                                    <i class="fas fa-arrow-left"></i> <span>Back</span>
                                </button>
                            </div>
                            <div class="pos-catalog-grid pos-products-grid" id="pos-products-grid"></div>
                        </div>

                        <!-- Services View -->
                        <div id="services-view" style="display: none; height: 100%; min-height: 0; flex-direction: column; overflow: hidden;">
                            <div class="pos-services-header"
                                style="padding: 24px; border-bottom: 1px solid #e2e8f0; background: #fff; display: flex; justify-content: space-between; align-items: center;">
                                <div>
                                    <h2 style="font-weight:700; font-size:18px; color:#1e293b; margin:0;">Available
                                        Services</h2>
                                    <p style="font-size:13px; color:#64748b; margin-top:4px;">Quickly add a printing
                                        service to the order.</p>
                                </div>
                                <button type="button" onclick="backToSelection()" class="pos-btn-back"
                                    title="Back to selection">
                                    <i class="fas fa-arrow-left"></i> Back
                                </button>
                            </div>

                            <div class="pos-catalog-grid pos-services-grid" id="pos-services-grid">
                                <?php if (empty($pos_services)): ?>
                                    <div style="grid-column:1/-1;text-align:center;color:#64748b;padding:2rem;">No services
                                        available.</div>
                                <?php else: ?>
                                    <?php foreach ($pos_services as $svc):
                                        $svc_img = htmlspecialchars($svc['image_url'] ?? $pos_default_catalog_img, ENT_QUOTES);
                                        $svc_name = htmlspecialchars($svc['name'] ?? '', ENT_QUOTES);
                                    ?>
                                        <button type="button" class="pos-catalog-card"
                                            data-service-id="<?php echo (int) $svc['service_id']; ?>"
                                            data-service-name="<?php echo $svc_name; ?>"
                                            onclick="posOpenServiceFromCard(this, Number(this.dataset.serviceId), this.dataset.serviceName)"
                                            title="<?php echo $svc_name; ?>">
                                            <div class="pos-catalog-card__media">
                                                <img src="<?php echo $svc_img; ?>" alt="" loading="lazy" decoding="async"
                                                    onerror="this.onerror=null;this.src='<?php echo htmlspecialchars($pos_default_catalog_img, ENT_QUOTES); ?>';">
                                            </div>
                                            <div class="pos-catalog-card__body">
                                                <?php if (!empty($svc['category'])): ?>
                                                    <span class="pos-catalog-card__meta"><?php echo htmlspecialchars($svc['category']); ?></span>
                                                <?php endif; ?>
                                                <p class="pos-catalog-card__name" title="<?php echo $svc_name; ?>"><?php echo $svc_name; ?></p>
                                            </div>
                                        </button>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <!-- RIGHT: CART -->
                    <div class="pos-cart-area pf-pos-cart-panel" data-pos-cart-panel>
                        <div class="pos-cart-header pf-pos-cart-target">
                            <h2><i class="fas fa-shopping-cart pf-pos-cart-icon" aria-hidden="true"></i> Current Order</h2>
                            <button class="pos-btn-clear" onclick="clearCart()"><i class="fas fa-trash"></i>
                                Clear</button>
                        </div>

                        <div class="pos-cart-body">
                        <div class="pos-customer-section">
                            <div class="pos-customer-label">
                                <span class="pos-section-label"><i class="fas fa-user"></i> Customer *</span>
                                <button type="button" class="pos-btn-link" onclick="openNewCustomerModal()">+ New Customer</button>
                            </div>
                            <div class="pos-customer-select-wrap">
                            <select id="pos-customer" class="pos-category-select" style="width: 100%; min-width: unset;"
                                required>
                                <option value="">-- Select Customer --</option>
                                <option value="guest" data-first="" data-last="" data-email="" data-phone="">Walk-in Customer (Guest)</option>
                                <?php foreach ($customers as $c): ?>
                                    <option value="<?= (int)$c['customer_id'] ?>"
                                        data-first="<?= htmlspecialchars($c['first_name'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                                        data-last="<?= htmlspecialchars($c['last_name'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                                        data-email="<?= htmlspecialchars($c['email'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                                        data-phone="<?= htmlspecialchars($c['contact_number'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
                                        <?= htmlspecialchars(trim(($c['first_name'] ?? '') . ' ' . ($c['last_name'] ?? ''))) ?>
                                        <?= !empty($c['email']) ? ' - ' . htmlspecialchars($c['email']) : (!empty($c['contact_number']) ? ' - ' . htmlspecialchars($c['contact_number']) : ' - No contact') ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            </div>
                            <div class="pos-guest-name-wrap" id="pos-guest-name-wrap">
                                <label for="pos-guest-name">Customer name</label>
                                <input type="text" id="pos-guest-name" class="pos-guest-name-input" maxlength="120"
                                    placeholder="Enter name for receipt (no account required)" autocomplete="name">
                            </div>
                        </div>

                        <div class="pos-cart-list" id="pos-cart-items">
                            <div class="pos-empty-state">
                                <i class="fas fa-shopping-cart"></i>
                                <p>Cart is empty</p>
                            </div>
                        </div>

                        <div class="pos-checkout-section">
                            <div class="pos-payment-summary">
                                <div class="pos-summary-line">
                                    <span class="pos-summary-label">
                                        Subtotal
                                    </span>
                                    <span class="pos-summary-amount" id="pos-subtotal">₱0.00</span>
                                </div>

                                <div class="pos-summary-total">
                                    <span class="pos-summary-label">
                                        Total
                                    </span>
                                    <span class="pos-summary-amount" id="pos-total">₱0.00</span>
                                </div>

                                <div class="pos-tender-group">
                                    <label class="pos-summary-label" for="pos-payment-method">
                                        Payment Method
                                    </label>
                                    <select id="pos-payment-method" class="pos-category-select pos-payment-field"
                                        aria-controls="pos-qrph-payment-section tender-group change-group">
                                        <option value="Cash">Cash</option>
                                        <?php if ($posPayMongoQrphAvailable): ?><option value="PayMongo QRPh">QR PH</option><?php endif; ?>
                                    </select>
                                </div>

                                <?php if ($posPayMongoQrphAvailable): ?>
                                <div id="pos-qrph-payment-section" class="pos-qrph-payment-section" hidden>
                                    <button type="button" id="pos-qrph-generate-btn" class="pos-qrph-generate-btn" onclick="launchPosQrphPayment('button')">Generate QR PH Payment</button>
                                </div>
                                <?php endif; ?>

                                <div class="pos-tender-group" id="tender-group">
                                    <label class="pos-summary-label" for="pos-tendered">
                                        Amount Paid
                                    </label>
                                    <div class="pos-tender-wrap">
                                        <span class="pos-tender-prefix">₱</span>
                                        <input type="number" id="pos-tendered" name="amount_tendered"
                                            class="pos-tender-input" placeholder="0.00" oninput="calculateChange()">
                                    </div>
                                </div>

                                <div class="pos-summary-change" id="change-group">
                                    <span class="pos-summary-label">
                                        Change
                                    </span>
                                    <span class="pos-summary-amount" id="pos-change">₱0.00</span>
                                </div>

                            </div>

                            <div class="pos-checkout-footer">
                            <button class="pos-btn-checkout" id="pos-checkout-btn" disabled onclick="processCheckout()">
                                <i class="fas fa-lock" id="checkout-icon"></i> <span id="checkout-text">Select
                                    Items</span>
                            </button>
                            </div>
                        </div>
                        </div>
                    </div>

                </div> <!-- END pos-wrapper -->
            </main>
        </div>
    </div>

    <div id="paymongo-pos-modal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.72);z-index:10020;align-items:center;justify-content:center;padding:16px;">
        <div style="background:#fff;width:min(360px,100%);padding:24px;text-align:center;box-shadow:0 24px 60px rgba(0,0,0,.28);">
            <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:12px;">
                <strong id="paymongo-pos-title" style="color:#0f172a;">PayMongo Payment</strong>
                <?php if ($posPayMongoMode === 'test'): ?><span style="padding:3px 7px;background:#fef3c7;color:#92400e;font-size:9px;font-weight:800;text-transform:uppercase;">Test Mode</span><?php endif; ?>
            </div>
            <div id="paymongo-pos-order" style="font-size:13px;font-weight:800;color:#0f172a;margin-bottom:10px;"></div>
            <img id="paymongo-pos-qr" alt="PayMongo QR Ph payment code" style="display:none;width:220px;height:220px;object-fit:contain;margin:0 auto 10px;border:1px solid #e2e8f0;">
            <div id="paymongo-pos-test-note" style="display:none;padding:10px;margin-bottom:10px;background:#fffbeb;border:1px solid #fcd34d;color:#92400e;font-size:12px;line-height:1.5;">Test Mode payment. Do not scan or pay this QR. Use PayMongo's test simulator to confirm the test payment.</div>
            <a id="paymongo-pos-test-simulation-link" href="#" target="_blank" rel="noopener noreferrer" style="display:none;margin-bottom:10px;color:#0369a1;font-weight:800;">Open Test Payment Simulator</a>
            <div id="paymongo-pos-countdown" style="font-size:12px;font-weight:800;color:#0f766e;margin-bottom:8px;min-height:18px;"></div>
            <div id="paymongo-pos-status" style="font-size:13px;color:#475569;margin-bottom:14px;">Waiting for payment confirmation.</div>
            <div id="paymongo-pos-reference" style="font-size:12px;color:#0f766e;font-weight:700;margin-bottom:14px;min-height:16px;"></div>
            <button id="paymongo-pos-retry" type="button" onclick="retryPayMongoPosQr()" style="display:none;width:100%;padding:11px;border:1px solid #0f766e;background:#fff;color:#0f766e;font-weight:800;margin-bottom:9px;">Generate New QR</button>
            <button id="paymongo-pos-complete" type="button" onclick="completePayMongoPosTransaction()" disabled style="width:100%;padding:11px;border:0;background:#94a3b8;color:#fff;font-weight:800;margin-bottom:9px;cursor:not-allowed;">Complete Transaction</button>
            <button type="button" onclick="closePayMongoPosModal()" style="width:100%;padding:10px;border:1px solid #cbd5e1;background:#fff;color:#475569;font-weight:700;">Close</button>
        </div>
    </div>

    <!-- Service Order Modal (DB-driven fields) -->
    <div id="service-modal-overlay"
        style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.75);z-index:9999;align-items:center;justify-content:center;padding:16px;"
        onclick="if(event.target===this)closeServiceModal()">
        <div
            style="background:#fff;width:100%;max-width:680px;border-radius:20px;padding:0;box-shadow:0 25px 50px -12px rgba(0,0,0,0.25);border:1px solid #e2e8f0;display:flex;flex-direction:column;max-height:90vh;overflow:hidden;">
            <div
                style="padding:20px 24px;border-bottom:1px solid #e2e8f0;display:flex;justify-content:space-between;align-items:center;flex-shrink:0;">
                <h3 id="sm-title" style="margin:0;font-size:18px;font-weight:800;color:#0f172a;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;max-width:calc(100% - 40px);"></h3>
                <button onclick="closeServiceModal()"
                    style="background:none;border:none;font-size:22px;cursor:pointer;color:#94a3b8;padding:4px;"
                    onmouseover="this.style.color='#1e293b'" onmouseout="this.style.color='#94a3b8'">&times;</button>
            </div>
            <div id="sm-fields-body" style="overflow-y:auto;flex:1;padding:20px 24px;"></div>
            <div id="sm-footer-actions" class="sm-footer-actions" style="display:none;">
                <div class="sm-footer-inner">
                    <div id="pos-estimated-price-display" class="pos-estimated-price-display">
                        <div class="pos-est-price-label">Estimated Price</div>
                        <div id="pos-estimated-total" class="pos-est-price-amount">₱0.00</div>
                        <div class="pos-est-price-meta">Based on selected options · Qty <span id="pos-qty-display">1</span></div>
                    </div>
                    <div id="pos-service-stock-feedback" style="display:none; flex:1 0 100%; margin-top:8px; font-size:12px; line-height:1.4;" aria-live="polite">
                        <div id="pos-service-stock-indicator" style="color:#475569;font-weight:700;"></div>
                        <div id="pos-service-stock-warning" style="color:#b91c1c;font-weight:700;"></div>
                    </div>
                    <div class="sm-footer-buttons">
                        <button type="button" class="sm-btn-cancel" onclick="closeServiceModal()">Cancel</button>
                        <button type="button" id="sm-add-to-order-btn" class="sm-btn-add" onclick="confirmServiceModal()">Add to Order</button>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <div id="custom-modal-overlay"
        style="display:none; position:fixed; inset:0; background:rgba(0,0,0,0.75); z-index:9999; align-items:center; justify-content:center; flex-direction:column;">
        <div
            style="background:#ffffff; width:450px; border-radius:20px; padding:28px; box-shadow:0 25px 50px -12px rgba(0,0,0,0.15); border:1px solid #e2e8f0; transform:translateY(0); transition:all 0.3s; margin:16px; color:#1e293b;">
            <h3 id="cm-title"
                style="margin:0 0 20px 0; font-size:18px; font-weight:800; color:#0f172a; letter-spacing:-0.02em; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; max-width:100%;" title="Product Customization">
                Product Customization</h3>

            <div id="cm-dynamic-fields"
                style="display:flex; flex-direction:column; gap:16px; margin-bottom:24px; max-height: 450px; overflow-y:auto; padding-right:8px;">
                <!-- Fields generated dynamically via JS -->
            </div>

            <div
                style="display:flex; justify-content:flex-end; gap:12px; border-top:1px solid #f1f5f9; padding-top:20px;">
                <button onclick="closeCustomModal()"
                    style="padding:12px 20px; border:1px solid #e2e8f0; background:#f8fafc; border-radius:12px; cursor:pointer; font-weight:600; font-size:14px; color:#64748b; transition:all 0.2s;"
                    onmouseover="this.style.background='#f1f5f9';this.style.color='#1e293b'"
                    onmouseout="this.style.background='#f8fafc';this.style.color='#64748b'">Cancel</button>
                <button onclick="confirmCustomization()"
                    style="padding:12px 28px; border:none; background:var(--staff-pos-button-bg); color:white; border-radius:12px; cursor:pointer; font-weight:700; font-size:14px; box-shadow:0 12px 24px rgba(15,23,42,0.14); transition:all 0.2s;"
                    onmouseover="this.style.transform='translateY(-1px)';this.style.filter='brightness(0.98)'"
                    onmouseout="this.style.transform='translateY(0)';this.style.filter='none'">Add to
                    Cart</button>
            </div>
        </div>
    </div>

    <!-- Custom Alert/Confirm Modal Overlay -->
    <div id="pos-alert-overlay">
        <div id="pos-alert-box">
            <div id="pos-alert-icon-container" class="pos-alert-icon-container">
                <i id="pos-alert-icon" class="fas fa-info" style="font-size:30px;"></i>
            </div>
            <h3 id="pos-alert-title" class="pos-alert-title">Alert</h3>
            <p id="pos-alert-message" class="pos-alert-message"></p>
            <div id="pos-alert-actions" style="display:flex; gap:12px; justify-content:center;">
                <button id="pos-alert-cancel" class="pos-alert-btn"
                    style="display:none; border:1px solid #e2e8f0; background:#f8fafc; color:#64748b;"
                    onmouseover="this.style.background='#f1f5f9'"
                    onmouseout="this.style.background='#f8fafc'">Cancel</button>
                <button id="pos-alert-confirm" class="pos-alert-btn" style="background:var(--staff-pos-button-bg); color:white; box-shadow:0 10px 24px rgba(15,23,42,0.14);"
                    onmouseover="this.style.filter='brightness(0.98)'"
                    onmouseout="this.style.filter='none'">OK</button>
            </div>
        </div>
    </div>
    <div id="pos-scan-toast-container" aria-live="polite" aria-atomic="true"></div>



    <!-- Receipt Modal Overlay -->
    <div id="receipt-modal-overlay">
        <div class="receipt-modal">
            <div class="receipt-modal-header">
                <div>
                    <div
                        style="display:inline-flex;align-items:center;gap:8px;background:#dcfce7;color:#15803d;font-size:11px;font-weight:800;text-transform:uppercase;letter-spacing:0.08em;padding:5px 12px;border-radius:999px;margin-bottom:10px;">
                        Transaction Complete
                    </div>
                    <h2 style="margin:0;font-size:26px;font-weight:800;color:#0f172a;letter-spacing:-0.03em;">Sale
                        Receipt</h2>
                    <p style="margin:6px 0 0;color:#64748b;font-size:14px;">Review the completed transaction before printing.</p>
                    <p id="receipt-print-result" role="status" style="margin:8px 0 0;color:#475569;font-size:13px;font-weight:700;"></p>
                </div>
                <div class="receipt-modal-actions">
                    <button type="button" class="receipt-action-btn" onclick="closeReceiptModal()">Close</button>
                    <button id="pos-print-receipt-btn" type="button" class="receipt-action-btn receipt-action-btn--primary" onclick="printReceipt()">Print Receipt</button>
                    <button id="pos-reprint-receipt-btn" type="button" class="receipt-action-btn receipt-action-btn--primary" onclick="confirmReprintReceipt()" style="display:none;">Reprint Receipt</button>
                </div>
            </div>
            <div class="receipt-modal-body">
                <div class="receipt-printer-stage">
                    <div class="receipt-printer-slot" aria-hidden="true"></div>
                    <div class="receipt-printer-viewport" id="receipt-printer-viewport">
                        <div id="receipt-print-area" class="receipt-sheet"></div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Modal for New Customer -->
    <div id="customer-modal">
        <div class="pos-customer-modal-card">
            <div style="display:flex; justify-content:space-between; margin-bottom:12px; gap:12px;">
                <h3 style="margin:0; font-weight:800; color:#0f172a; font-size:20px; letter-spacing:-0.02em;">Add Customer</h3>
                <button type="button" onclick="closeCustomerModal()"
                    style="background:none; border:none; font-size:24px; cursor:pointer; color:#94a3b8; padding:4px; line-height:1;"
                    onmouseover="this.style.color='#1e293b'" onmouseout="this.style.color='#94a3b8'" aria-label="Close">&times;</button>
            </div>
            <p class="pos-customer-modal-lead">Create a customer account for this walk-in customer.</p>
            <p class="pos-customer-modal-help">Create an account so this customer can be selected for the POS order and receive their account setup email.</p>
            <div style="margin-bottom:16px;">
                <label
                    style="display:block; font-size:12px; font-weight:700; color:#64748b; text-transform:uppercase; margin-bottom:6px;">First
                    Name *</label>
                <input type="text" id="nc-first" placeholder="Enter first name"
                    style="width:100%; padding:12px; border:1px solid #e2e8f0; border-radius:8px; background:#f8fafc; color:#1e293b; outline:none; transition:all 0.2s;"
                    onfocus="this.style.borderColor='var(--staff-primary)';this.style.background='#fff';this.style.boxShadow='0 0 0 3px rgba(var(--staff-accent-rgb),0.12)'"
                    onblur="this.style.borderColor='#e2e8f0';this.style.background='#f8fafc'">
            </div>
            <div style="margin-bottom:16px;">
                <label
                    style="display:block; font-size:12px; font-weight:700; color:#64748b; text-transform:uppercase; margin-bottom:6px;">Last
                    Name *</label>
                <input type="text" id="nc-last" placeholder="Enter last name"
                    style="width:100%; padding:12px; border:1px solid #e2e8f0; border-radius:8px; background:#f8fafc; color:#1e293b; outline:none; transition:all 0.2s;"
                    onfocus="this.style.borderColor='var(--staff-primary)';this.style.background='#fff';this.style.boxShadow='0 0 0 3px rgba(var(--staff-accent-rgb),0.12)'"
                    onblur="this.style.borderColor='#e2e8f0';this.style.background='#f8fafc'">
            </div>
            <div style="margin-bottom:16px;">
                <label
                    style="display:block; font-size:12px; font-weight:700; color:#64748b; text-transform:uppercase; margin-bottom:6px;">Email
                    Address *</label>
                <input type="email" id="nc-email" placeholder="customer@example.com"
                    style="width:100%; padding:12px; border:1px solid #e2e8f0; border-radius:8px; background:#f8fafc; color:#1e293b; outline:none; transition:all 0.2s;"
                    onfocus="this.style.borderColor='var(--staff-primary)';this.style.background='#fff';this.style.boxShadow='0 0 0 3px rgba(var(--staff-accent-rgb),0.12)'"
                    onblur="this.style.borderColor='#e2e8f0';this.style.background='#f8fafc'">
                <small style="display:block; margin-top:4px; font-size:11px; color:#64748b;">A password setup link will
                    be sent to this email</small>
            </div>
            <div style="margin-bottom:24px;">
                <label
                    style="display:block; font-size:12px; font-weight:700; color:#64748b; text-transform:uppercase; margin-bottom:6px;">Phone
                    Number (Optional)</label>
                <input type="tel" id="nc-phone" placeholder="09XX XXX XXXX"
                    style="width:100%; padding:12px; border:1px solid #e2e8f0; border-radius:8px; background:#f8fafc; color:#1e293b; outline:none; transition:all 0.2s;"
                    onfocus="this.style.borderColor='var(--staff-primary)';this.style.background='#fff';this.style.boxShadow='0 0 0 3px rgba(var(--staff-accent-rgb),0.12)'"
                    onblur="this.style.borderColor='#e2e8f0';this.style.background='#f8fafc'">
            </div>
            <button type="button" onclick="saveCustomer()" id="nc-save-btn"
                style="width:100%; background:var(--staff-pos-button-bg); color:white; padding:14px; border:none; border-radius:12px; font-weight:700; cursor:pointer; box-shadow:0 12px 24px rgba(15,23,42,0.14); transition:all 0.2s;"
                onmouseover="this.style.filter='brightness(0.98)'" onmouseout="this.style.filter='none'">Create
                Customer & Send Email</button>
        </div>
    </div>

    <!-- Modal for variant / size selection on option-stock products -->
    <div id="variant-modal-overlay"
        style="display:none; position:fixed; inset:0; background:rgba(0,0,0,0.75); z-index:1000; align-items:center; justify-content:center;">
        <div class="price-modal" style="border-radius:20px; border:1px solid #e2e8f0; width:min(360px, calc(100vw - 32px));">
            <h3 id="vm-title"
                style="margin:0 0 8px 0; font-size:20px; font-weight:800; color:#0f172a; letter-spacing:-0.02em;">Select Option</h3>
            <p id="vm-subtitle" style="margin:0 0 18px 0; font-size:13px; color:#64748b; line-height:1.45;"></p>
            <div id="vm-options" style="display:flex; flex-direction:column; gap:10px; margin-bottom:24px; max-height:280px; overflow-y:auto;"></div>
            <div style="display:flex; gap:12px;">
                <button type="button" onclick="closeVariantModal()"
                    style="flex:1; padding:14px; border:1px solid #e2e8f0; border-radius:12px; background:#f8fafc; color:#64748b; font-weight:700; cursor:pointer;">Cancel</button>
                <button type="button" onclick="confirmVariantSelection()"
                    style="flex:1; padding:14px; border:none; border-radius:12px; background:var(--staff-pos-button-bg); color:white; font-weight:700; cursor:pointer;">Add to Cart</button>
            </div>
        </div>
    </div>

    <!-- Modal for Custom Price -->
    <div id="price-modal-overlay"
        style="display:none; position:fixed; inset:0; background:rgba(0,0,0,0.75); z-index:1000; align-items:center; justify-content:center;">
        <div class="price-modal" style="border-radius:20px; border:1px solid #e2e8f0;">
            <h3 id="pm-title"
                style="margin:0 0 12px 0; font-size:20px; font-weight:800; color:#0f172a; letter-spacing:-0.02em;">Set
                Price</h3>
            <div id="pm-name-group" style="margin-bottom: 24px; display:none;">
                <label
                    style="display:block; font-size:12px; font-weight:700; color:#64748b; text-transform:uppercase; margin-bottom:8px; letter-spacing:0.05em;">Service
                    Name</label>
                <input type="text" id="pm-name-input" name="custom_service_name"
                    style="width:100%; padding:14px; border:1px solid #e2e8f0; border-radius:12px; background:#f8fafc; color:#1e293b; outline:none;"
                    placeholder="e.g. Custom Frame">
            </div>
            <div style="margin-bottom:28px;">
                <label
                    style="display:block; font-size:12px; font-weight:700; color:#64748b; text-transform:uppercase; margin-bottom:8px; letter-spacing:0.05em;">Negotiated
                    Price</label>
                <div style="position: relative;">
                    <span style="position: absolute; left: 16px; top: 14px; font-weight: 700; color: #94a3b8;">₱</span>
                    <input type="number" id="pm-price-input" name="custom_service_price"
                        style="width:100%; padding:14px 14px 14px 32px; border:1px solid #e2e8f0; border-radius:12px; font-weight:800; font-size:24px; background:#f8fafc; color:#1e293b; outline:none;"
                        placeholder="0.00" step="0.01">
                </div>
            </div>
            <div style="display:flex; gap:12px;">
                <button onclick="closePriceModal()"
                    style="flex:1; padding:14px; border:1px solid #e2e8f0; border-radius:12px; background:#f8fafc; color:#64748b; font-weight:700; cursor:pointer; transition:all 0.2s;"
                    onmouseover="this.style.background='#f1f5f9';this.style.color='#1e293b'"
                    onmouseout="this.style.background='#f8fafc';this.style.color='#64748b'">Cancel</button>
                <button onclick="confirmPrice()"
                    style="flex:1; padding:14px; border:none; border-radius:12px; background:var(--staff-pos-button-bg); color:white; font-weight:700; cursor:pointer; box-shadow:0 12px 24px rgba(15,23,42,0.14); transition:all 0.2s;"
                    onmouseover="this.style.filter='brightness(0.98)';this.style.transform='translateY(-1px)'"
                    onmouseout="this.style.filter='none';this.style.transform='translateY(0)'">Add Item</button>
            </div>
        </div>
    </div>

    <?php
    // Inject the same field interaction scripts used by order_service_dynamic.php
    require_once __DIR__ . '/../includes/service_field_renderer.php';
    echo get_service_field_scripts();
    ?>

    <script src="<?php echo htmlspecialchars(BASE_PATH . '/public/assets/js/service_estimated_price.js'); ?>"></script>
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <?php $pos_add_fx_ver = @filemtime(__DIR__ . '/../public/assets/js/add_to_cart_fx.js') ?: '1'; ?>
    <script src="<?php echo htmlspecialchars(BASE_PATH . '/public/assets/js/add_to_cart_fx.js'); ?>?v=<?php echo (int) $pos_add_fx_ver; ?>"></script>
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
    <script>
        window.POS_BRANCHES = <?php echo json_encode(array_map(function ($b) {
            return ['id' => (int) $b['id'], 'name' => $b['branch_name']];
        }, $branches ?: [])); ?>;
        window.POS_SET_PRICE_SERVICE_IDS = <?php echo json_encode(array_values(array_unique($pos_set_price_service_ids))); ?>;
        window.POS_SERVICE_CATALOG = <?php echo json_encode(array_map(static function ($svc) {
            return [
                'service_id' => (int) ($svc['service_id'] ?? 0),
                'name' => (string) ($svc['name'] ?? ''),
                'pricing_type' => (string) ($svc['pricing_type'] ?? 'custom'),
            ];
        }, $pos_services ?: [])); ?>;

        let products = [];
        let cart = [];
        let currentTotal = 0;
        let currentMode = null; // 'products' or 'services'
        let barcodeScanBusy = false;
        let barcodeScanQueueRunning = false;
        const barcodeScanQueue = [];
        let posBarcodeDebugEnabled = false;
        let isAddingToOrder = false;
        let posServiceStockRequestVersion = 0;
        let posServiceStockRequestTimer = null;
        let posServiceStockBlocking = false;
        let posEstimatedPriceController = null;
        const STAFF_BASE_PATH = <?php echo json_encode(BASE_PATH); ?>;
        const POS_DEFAULT_CATALOG_IMG = <?php echo json_encode($pos_default_catalog_img); ?>;
        const POS_ADD_FX_OPTS = {
            cartTargetSelector: '[data-pos-cart-panel]',
            cardSelector: '.pos-catalog-card',
            message: 'Added to cart ✓'
        };
        let posLastServiceCardEl = null;
        const POS_CSRF_TOKEN = document.body.dataset.csrf || '';

        function posCatalogImageUrl(product) {
            if (!product) return POS_DEFAULT_CATALOG_IMG;
            if (product.image_url) return product.image_url;
            const raw = String(product.photo_path || product.product_image || '').trim();
            if (!raw) return POS_DEFAULT_CATALOG_IMG;
            if (/^https?:\/\//i.test(raw)) return raw;
            const path = raw.startsWith('/') ? raw : '/' + raw;
            return STAFF_BASE_PATH + path;
        }

        function mergePosProductRecord(existing, incoming) {
            if (!existing) return incoming || null;
            if (!incoming) return existing;

            const merged = { ...existing, ...incoming };
            ['image_url', 'photo_path', 'product_image'].forEach(function(key) {
                if (!String(merged[key] || '').trim() && String(existing[key] || '').trim()) {
                    merged[key] = existing[key];
                }
            });
            return merged;
        }

        function posPlayAddAnimation(sourceEl) {
            if (!sourceEl || !window.PFAddToCartFx) return;
            window.PFAddToCartFx.run(sourceEl, POS_ADD_FX_OPTS);
        }

        function posOpenServiceFromCard(cardEl, serviceId, serviceName) {
            posLastServiceCardEl = cardEl || null;
            if (cardEl && cardEl.classList) {
                cardEl.classList.add('is-selecting');
                window.setTimeout(function () { cardEl.classList.remove('is-selecting'); }, 320);
            }
            openServiceModal(serviceId, serviceName);
        }
        try {
            posBarcodeDebugEnabled = new URLSearchParams(window.location.search).get('pos_barcode_debug') === '1'
                || window.localStorage.getItem('printflow_pos_barcode_debug') === '1';
        } catch (ignore) {}
        function posBarcodeDebug(message, details) {
            if (!posBarcodeDebugEnabled || !window.console || typeof window.console.info !== 'function') return;
            window.console.info('[POS Barcode] ' + message, details || {});
        }
        let paymongoPollTimer = null;
        let paymongoCountdownTimer = null;
        /** Bumped when the cart or staff dismisses QR PH; stale checkout/poll handlers must ignore results. */
        let posQrphFlowEpoch = 0;
        let paymongoModalEpoch = null;
        let pendingPayMongoPayment = null;
        let pendingPayMongoReceipt = null;
        let pendingPayMongoOrderId = 0;
        let posCheckoutRequestInFlight = false;
        let posCheckoutConfirmOpen = false;
        let pendingPayMongoPrintJob = null;
        let posPayMongoCheckoutPending = false;
        let posPayMongoCheckoutAttemptToken = null;
        let posSaveCustomerInFlight = false;
        function staffUrl(path) {
            return (STAFF_BASE_PATH || '') + '/' + String(path || '').replace(/^\/+/, '');
        }
        async function fetchWithTimeout(url, options = {}, timeoutMs = 30000) {
            const controller = new AbortController();
            const timeoutId = setTimeout(() => controller.abort(), timeoutMs);
            try {
                return await fetch(url, { ...options, signal: controller.signal });
            } finally {
                clearTimeout(timeoutId);
            }
        }

        function posCheckoutCustomizationPayload(customization) {
            if (!customization || typeof customization !== 'object') {
                return null;
            }
            const payload = { ...customization };
            const stripKeys = [
                'design_upload_data',
                'reference_upload_data',
                'design_data',
                'reference_data',
                'design_blob',
                'reference_blob'
            ];
            stripKeys.forEach(key => delete payload[key]);
            if (payload.design_upload_path || payload.design_file || payload.design_tmp_path) {
                delete payload.design_upload_data;
            }
            if (payload.reference_upload_path || payload.reference_file) {
                delete payload.reference_upload_data;
            }
            return Object.keys(payload).length ? payload : null;
        }

        function posCheckoutItemPayload(item) {
            return {
                id: item.product_id,
                qty: item.qty,
                price: item.price,
                name: item.name || null,
                customization: posCheckoutCustomizationPayload(item.customization),
                is_service: item.is_service || false,
                price_set: item.price_set === true,
                pending_order_id: item.pending_order_id || 0,
                pending_customization_id: item.pending_customization_id || 0,
                pending_order_item_id: item.pending_order_item_id || 0,
                pos_cart_line_id: item.pos_cart_line_id || null
            };
        }

        function posCartItemHasMaterialSet(item) {
            const c = item && item.customization ? item.customization : {};
            return !!(
                c['Material Selection']
                || c['Material Brand']
                || c['Material']
                || c.material_name
                || c.temp_plate_material
                || c.material_type
            );
        }

        function posCartItemMaterialLabel(item) {
            const c = item && item.customization ? item.customization : {};
            for (const key of ['Material Selection', 'Material Brand', 'Material', 'material_name', 'temp_plate_material', 'material_type']) {
                const val = String(c[key] || '').trim();
                if (val) return val;
            }
            return '';
        }

        function posCartItemStaffPricingComplete(item) {
            return !!(item && item.is_service === true && item.price_set === true && posCartItemHasMaterialSet(item));
        }

        function posServiceCatalogMeta(serviceId) {
            const sid = Number(serviceId) || 0;
            return (window.POS_SERVICE_CATALOG || []).find(entry => Number(entry.service_id) === sid) || null;
        }

        function posServiceUsesStaffPricingFlow(serviceId, serviceName, customization) {
            if (posServiceRequiresSetPrice(serviceId, serviceName, customization)) {
                return true;
            }
            const meta = posServiceCatalogMeta(serviceId);
            return !!(meta && String(meta.pricing_type || '').toLowerCase() === 'custom');
        }

        function posServiceRequiresSetPrice(serviceId, serviceName, customization) {
            const ids = window.POS_SET_PRICE_SERVICE_IDS || [];
            const sid = Number(serviceId) || 0;
            if (sid > 0 && ids.includes(sid)) return true;
            const name = String(serviceName || '').toLowerCase();
            if (name.includes('sintraboard') || name.includes('standee') || name.includes('sintra board')) return true;
            const c = customization || {};
            if (c.sintra_type || c['Board Thickness'] || c.board_thickness) return true;
            const serviceType = String(c.service_type || '').toLowerCase();
            return serviceType.includes('sintra') || serviceType.includes('standee');
        }

        function posCartItemRequiresSetPrice(item) {
            return !!(item && item.is_service === true);
        }

        function posCartItemShowsSetPriceButton(item) {
            if (posCartItemRequiresSetPrice(item)) {
                return !posCartItemStaffPricingComplete(item);
            }
            const unitPrice = parseFloat(item?.price) || 0;
            const priceWasSet = item?.price_set === true;
            return unitPrice <= 0 && !priceWasSet && !posCartItemHasMaterialSet(item);
        }

        function posCartItemShowsEditPriceButton(item) {
            return posCartItemStaffPricingComplete(item);
        }

        function posCartItemCountsInCheckoutTotal(item) {
            if (posCartItemRequiresSetPrice(item)) {
                return posCartItemStaffPricingComplete(item);
            }
            return (parseFloat(item.price) || 0) > 0;
        }

        function posCartItemEstimatedUnitPrice(item) {
            const c = item && item.customization ? item.customization : {};
            const qty = Math.max(1, parseInt(item?.qty, 10) || 1);
            const lineTotal = parseFloat(c.calculated_estimated_price);
            if (Number.isFinite(lineTotal) && lineTotal > 0) {
                return lineTotal / qty;
            }
            const unit = parseFloat(c.calculated_unit_price);
            if (Number.isFinite(unit) && unit > 0) {
                return unit;
            }
            const cartUnit = parseFloat(item?.price);
            return Number.isFinite(cartUnit) ? cartUnit : 0;
        }

        function posVariantOptionsList(product) {
            if (!product || !product.has_variant_stock) return [];
            if (Array.isArray(product.variant_stock_options)) {
                return product.variant_stock_options;
            }
            return Object.entries(product.variant_stock_options || {}).map(([optionValue, meta]) => ({
                option_value: optionValue,
                stock_quantity: meta && meta.stock_quantity != null ? meta.stock_quantity : 0
            }));
        }

        function posVariantOptionsInStock(product) {
            return posVariantOptionsList(product).filter(option => (parseInt(option.stock_quantity, 10) || 0) > 0);
        }

        function posBuildVariantCustomization(product, optionValue) {
            const fieldKey = product.variant_stock_field_key || 'size';
            const fieldLabel = product.variant_stock_field_label || fieldKey;
            const normalized = String(optionValue || '').trim();
            const customization = {};
            customization[fieldKey] = normalized;
            customization[fieldLabel] = normalized;
            return customization;
        }

        function posCartItemVariantLabel(item) {
            const customization = item && item.customization && typeof item.customization === 'object'
                ? item.customization
                : null;
            if (!customization) return '';
            const keys = Object.keys(customization);
            for (const key of keys) {
                const normalized = String(key || '').trim().toLowerCase();
                if (['size', 'sizes', 'variant', 'variants'].includes(normalized) && customization[key]) {
                    return String(customization[key]);
                }
            }
            return '';
        }

        function posResolveVariantBeforeAdd(product) {
            if (!product || !product.has_variant_stock) {
                return { action: 'add', customization: null };
            }
            const inStock = posVariantOptionsInStock(product);
            if (inStock.length === 0) {
                return { action: 'error', message: (product.product_name || 'This product') + ' is out of stock.' };
            }
            if (inStock.length === 1) {
                const optionValue = inStock[0].option_value;
                return {
                    action: 'add',
                    customization: posBuildVariantCustomization(product, optionValue)
                };
            }
            return {
                action: 'prompt',
                options: inStock,
                fieldLabel: product.variant_stock_field_label || 'Option'
            };
        }

        let pendingVariantProduct = null;
        let pendingVariantAddOptions = null;

        function openVariantModal(product, options, fieldLabel, addOptions = {}) {
            pendingVariantProduct = product;
            pendingVariantAddOptions = addOptions;
            const overlay = document.getElementById('variant-modal-overlay');
            const title = document.getElementById('vm-title');
            const subtitle = document.getElementById('vm-subtitle');
            const optionsEl = document.getElementById('vm-options');
            const productName = product.product_name || product.name || 'Product';
            title.textContent = 'Select ' + fieldLabel;
            subtitle.textContent = productName + ' requires a ' + fieldLabel.toLowerCase() + ' before it can be added to the cart.';
            optionsEl.innerHTML = options.map((option, index) => {
                const value = String(option.option_value || '').replace(/"/g, '&quot;');
                const stock = parseInt(option.stock_quantity, 10) || 0;
                const checked = index === 0 ? 'checked' : '';
                return '<label style="display:flex; align-items:center; justify-content:space-between; gap:12px; padding:12px 14px; border:1px solid #e2e8f0; border-radius:12px; background:#f8fafc; cursor:pointer;">'
                    + '<span style="display:flex; align-items:center; gap:10px; font-weight:600; color:#0f172a;">'
                    + '<input type="radio" name="pos_variant_option" value="' + value + '" ' + checked + '>'
                    + '<span>' + value + '</span>'
                    + '</span>'
                    + '<span style="font-size:12px; color:#64748b;">' + stock + ' left</span>'
                    + '</label>';
            }).join('');
            overlay.style.display = 'flex';
        }

        function closeVariantModal() {
            document.getElementById('variant-modal-overlay').style.display = 'none';
            pendingVariantProduct = null;
            pendingVariantAddOptions = null;
        }

        async function confirmVariantSelection() {
            if (!pendingVariantProduct) return;
            const selected = document.querySelector('#variant-modal-overlay input[name="pos_variant_option"]:checked');
            if (!selected || !selected.value) {
                await showPOSAlert('Selection Required', 'Please choose an option before adding this product.', 'warning');
                return;
            }
            const customization = posBuildVariantCustomization(pendingVariantProduct, selected.value);
            const addOptions = pendingVariantAddOptions || {};
            const product = pendingVariantProduct;
            closeVariantModal();
            return await posAddProductToCart(product, null, null, customization, addOptions);
        }

        async function posAddProductToCart(p, overridePrice = null, overrideName = null, customization = null, options = {}) {
            const name = overrideName || p.product_name;
            const price = overridePrice !== null ? overridePrice : parseFloat(p.price);
            return await syncedCartAction('add', {
                product_id: p.product_id,
                name: name,
                price: price,
                qty: 1,
                customization: customization,
                is_service: false
            }, options);
        }
        function formatMoney(value) {
            const amount = Number.parseFloat(value);
            const safeAmount = Number.isFinite(amount) ? amount : 0;
            return '₱' + safeAmount.toLocaleString('en-US', {
                minimumFractionDigits: 2,
                maximumFractionDigits: 2
            });
        }

        function escapeHtml(value) {
            return String(value ?? '').replace(/[&<>"']/g, char => ({
                '&': '&amp;',
                '<': '&lt;',
                '>': '&gt;',
                '"': '&quot;',
                "'": '&#39;'
            }[char] || char));
        }

        function formatReceiptDateTime(value) {
            if (!value) return 'Not available';
            const parsed = new Date(String(value).replace(' ', 'T'));
            if (Number.isNaN(parsed.getTime())) return value;
            return parsed.toLocaleString('en-PH', {
                year: 'numeric',
                month: 'short',
                day: 'numeric',
                hour: 'numeric',
                minute: '2-digit',
                hour12: true
            });
        }

        function cleanReceiptContact(customer = {}) {
            const phone = String(customer.phone || '').trim();
            if (phone) return phone;
            const email = String(customer.email || '').trim();
            if (!email || email.toLowerCase() === 'walkin@pos.local') return '';
            return email;
        }

        function compactReceiptSpecLabel(key) {
            const map = {
                material: 'Material',
                material_type: 'Material',
                temp_plate_material: 'Material',
                dimensions: 'Size',
                size: 'Size',
                layout: 'Layout',
                finish: 'Finish',
                lamination: 'Lamination',
                laminate_option: 'Lamination',
                needed_date: 'Needed',
                quantity: 'Qty'
            };
            return map[key] || '';
        }

        function buildReceiptItemDetails(item) {
            const custom = item && item.customization && typeof item.customization === 'object' ? item.customization : {};
            const details = [];
            const pushDetail = (label, value) => {
                const text = String(value || '').trim();
                if (!text) return;
                details.push(`${label}: ${text}`);
            };

            Object.entries(custom).forEach(([key, value]) => {
                if (details.length >= 4 || value == null || value === '' || typeof value === 'object') return;
                pushDetail(String(key), value);
            });

            return details.slice(0, 4);
        }

        function buildReceiptHtml(receipt) {
            const company = receipt?.company || {};
            const customer = receipt?.customer || {};
            const payment = receipt?.payment || {};
            const items = Array.isArray(receipt?.items) ? receipt.items : [];
            const receiptContact = cleanReceiptContact(customer);
            const cashierName = <?php echo json_encode($user_name ?? ''); ?>;
            const itemRows = items.map(item => `
                <tr>
                    <td>
                        <div class="receipt-item-name">${escapeHtml(item.name || 'Item')}</div>
                        ${buildReceiptItemDetails(item).length ? `<div class="receipt-item-meta">${escapeHtml(buildReceiptItemDetails(item).join(' • '))}</div>` : ''}
                    </td>
                    <td>${escapeHtml(item.quantity || 0)}</td>
                    <td>${formatMoney(item.unit_price || 0)}</td>
                    <td style="font-weight:800;color:#0f172a;">${formatMoney(item.line_total || 0)}</td>
                </tr>
            `).join('');
            return `
                <div class="receipt-header">
                    ${company.logo_url ? `<img src="${escapeHtml(company.logo_url)}" alt="${escapeHtml(company.name || 'Company')}" class="receipt-logo">` : ''}
                    <div class="receipt-brand-name">PrintFlow</div>
                    <div class="receipt-branch">${escapeHtml(company.branch_name || 'Main Branch')}</div>
                    <div class="receipt-company-meta">
                        ${company.address ? `<div>${escapeHtml(company.address)}</div>` : ''}
                        ${company.contact ? `<div>${escapeHtml(company.contact)}</div>` : ''}
                    </div>
                    <div class="receipt-pill">UNOFFICIAL SALES RECEIPT</div>
                    <div class="receipt-subtext">Transaction reference only</div>
                    <div class="receipt-disclaimer">This is an unofficial sales receipt for transaction reference only. It is not an official receipt or sales invoice.</div>
                    ${receipt?.reprint ? '<div class="receipt-pill">REPRINT COPY</div>' : ''}
                </div>

                <div class="receipt-section">
                    <div class="receipt-section-title">Receipt Info</div>
                    ${receipt.qr_payload ? `<div class="receipt-qr-wrap"><div id="pos-receipt-qr"></div><div class="receipt-qr-caption">Scan for order details</div></div>` : ''}
                    <div class="receipt-info-grid">
                        <div class="receipt-info-card">
                            <div class="receipt-label">Receipt Reference No.</div>
                            <div class="receipt-value receipt-value--strong">${escapeHtml(receipt.receipt_number || '')}</div>
                        </div>
                        <div class="receipt-info-card">
                            <div class="receipt-label">Date & Time</div>
                            <div class="receipt-value">${escapeHtml(receipt.date_time_display || formatReceiptDateTime(receipt.date_time))}</div>
                        </div>
                        <div class="receipt-info-card">
                            <div class="receipt-label">Cashier</div>
                            <div class="receipt-value">${escapeHtml(cashierName || 'Staff')}</div>
                        </div>
                    </div>
                </div>

                <div class="receipt-section">
                    <div class="receipt-section-title">Customer</div>
                    <div class="receipt-customer">
                        <div>
                            <div class="receipt-customer-name">${escapeHtml(customer.name || 'Walk-in Guest')}</div>
                            ${receiptContact ? `<div class="receipt-value" style="margin-top:4px;">${escapeHtml(receiptContact)}</div>` : ''}
                        </div>
                    </div>
                </div>

                <div class="receipt-section">
                    <div class="receipt-section-title">Items</div>
                    <table class="receipt-items">
                        <thead>
                            <tr>
                                <th>Item / Service</th>
                                <th>Qty</th>
                                <th>Price</th>
                                <th>Amount</th>
                            </tr>
                        </thead>
                        <tbody>${itemRows}</tbody>
                    </table>
                </div>

                <div class="receipt-section">
                    <div class="receipt-section-title">Payment Summary</div>
                    <div class="receipt-summary">
                        <div class="receipt-total-line"><span>Subtotal</span><strong>${formatMoney(receipt.subtotal || 0)}</strong></div>
                        <div class="receipt-total-line receipt-total-line--grand"><span>Total</span><span>${formatMoney(receipt.total || 0)}</span></div>
                    </div>
                    <div class="receipt-payment-breakdown">
                        <div class="receipt-total-line"><span>Payment Method</span><strong>${escapeHtml(payment.method || 'Cash')}</strong></div>
                        <div class="receipt-total-line"><span>Amount Paid</span><strong>${formatMoney(payment.amount_paid || 0)}</strong></div>
                        <div class="receipt-total-line"><span>Change</span><strong style="color:#0f766e;">${formatMoney(payment.change || 0)}</strong></div>
                        ${(payment.balance || 0) > 0 ? `<div class="receipt-total-line"><span>Balance</span><strong>${formatMoney(payment.balance || 0)}</strong></div>` : ''}
                        ${payment.reference ? `<div class="receipt-total-line"><span>Provider Reference</span><span>${escapeHtml(payment.reference)}</span></div>` : ''}
                        ${payment.paid_at ? `<div class="receipt-total-line"><span>Paid Date</span><span>${escapeHtml(formatReceiptDateTime(payment.paid_at))}</span></div>` : ''}
                    </div>
                </div>

                <div class="receipt-footer">
                    <strong>Thank you for choosing PrintFlow!</strong>
                    <p>Please keep this transaction reference for your records.</p>
                </div>

                <div class="receipt-online-store">
                    <div class="receipt-online-store-title">Visit our Online Store</div>
                    <div class="receipt-qr-wrap"><div id="pos-online-store-qr"></div></div>
                    <div class="receipt-qr-caption">Scan the QR code to order online</div>
                    <div class="receipt-online-store-url">mrandmrsprintflow.com</div>
                </div>
            `;
        }

        let activePosReceipt = null;
        let activePosPrintJob = null;
        let posReceiptPrintProcessing = false;
        let posReceiptPrintAttempted = false;

        const POS_RECEIPT_PRINTER_SPEC = {
            speedMmPerSec: 50,
            paperWidthMm: 58,
            printWidthMm: 48,
            avgLineHeightMm: 3.75,
            calibrationBufferSec: 0.4,
        };

        /**
         * Best-effort print duration from rated thermal head speed (50 mm/s @ 58 mm paper).
         * PushPrinter / browser print APIs do not expose live paper-feed progress.
         * Frame-perfect sync would need WebUSB/WebSerial printer status polling (separate scope).
         */
        function estimatePosReceiptPrintDurationMs(receiptEl) {
            if (!receiptEl) {
                return Math.round((POS_RECEIPT_PRINTER_SPEC.calibrationBufferSec + 1.5) * 1000);
            }

            const widthPx = receiptEl.offsetWidth || receiptEl.getBoundingClientRect().width || 1;
            const heightPx = receiptEl.scrollHeight || receiptEl.offsetHeight || 0;
            const lengthMm = (heightPx / widthPx) * POS_RECEIPT_PRINTER_SPEC.paperWidthMm;
            const printTimeSec = lengthMm / POS_RECEIPT_PRINTER_SPEC.speedMmPerSec;
            const totalSec = POS_RECEIPT_PRINTER_SPEC.calibrationBufferSec + printTimeSec;
            return Math.max(900, Math.round(totalSec * 1000));
        }

        function getReceiptPrinterViewport() {
            return document.getElementById('receipt-printer-viewport');
        }

        function resetReceiptFeedAnimation(receiptEl) {
            const viewport = getReceiptPrinterViewport();
            if (viewport) {
                viewport.classList.remove('receipt-feed-reveal', 'receipt-feed-active', 'receipt-feed-empty');
                viewport.style.transition = 'none';
                viewport.style.height = '';
                viewport.style.maxHeight = '';
                viewport.style.minHeight = '';
            }
            if (receiptEl) {
                receiptEl.classList.remove('receipt-feed-active');
                receiptEl.style.transition = 'none';
                receiptEl.style.transform = '';
            }
        }

        /**
         * Paper consumed into printer: fixed slot at top; receipt block moves upward
         * and is clipped by the viewport (overflow hidden) so content disappears into
         * the slot top-first, footer last. Best-effort duration from 50 mm/s rated speed.
         */
        function runReceiptFeedAnimation(receiptEl, durationMs) {
            const viewport = getReceiptPrinterViewport();
            if (!receiptEl || !viewport) return Promise.resolve();

            const fullHeight = Math.ceil(receiptEl.scrollHeight || receiptEl.offsetHeight || 0);
            if (fullHeight <= 0) return Promise.resolve();

            if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
                receiptEl.style.transition = 'none';
                receiptEl.style.transform = `translateY(-${fullHeight}px)`;
                viewport.classList.add('receipt-feed-empty');
                viewport.style.height = '0px';
                return Promise.resolve();
            }

            return new Promise(resolve => {
                viewport.classList.remove('receipt-feed-empty');
                viewport.style.transition = 'none';
                viewport.style.height = `${fullHeight}px`;
                viewport.style.maxHeight = `${fullHeight}px`;
                viewport.style.overflow = 'hidden';

                receiptEl.classList.remove('receipt-feed-active');
                receiptEl.style.transition = 'none';
                receiptEl.style.transform = 'translateY(0)';
                void receiptEl.offsetHeight;

                receiptEl.classList.add('receipt-feed-active');
                requestAnimationFrame(() => {
                    receiptEl.style.transition = `transform ${durationMs}ms linear`;
                    receiptEl.style.transform = `translateY(-${fullHeight}px)`;
                });

                window.setTimeout(() => {
                    receiptEl.style.transition = 'none';
                    viewport.style.transition = 'none';
                    viewport.style.height = '0px';
                    viewport.style.maxHeight = '0px';
                    viewport.classList.add('receipt-feed-empty');
                    resolve();
                }, durationMs);
            });
        }

        function setPosReceiptPrintState(message = '', failed = false) {
            const status = document.getElementById('receipt-print-result');
            const button = document.getElementById('pos-print-receipt-btn');
            const reprintButton = document.getElementById('pos-reprint-receipt-btn');
            if (status) {
                status.textContent = message;
                status.style.color = failed ? '#b91c1c' : (posReceiptPrintAttempted ? '#0f766e' : '#475569');
            }
            if (button) {
                button.disabled = posReceiptPrintProcessing;
                button.style.display = (!failed && posReceiptPrintAttempted && !posReceiptPrintProcessing) ? 'none' : '';
                button.textContent = posReceiptPrintProcessing ? 'Printing...' : (failed ? 'Retry Print' : 'Print Receipt');
            }
            if (reprintButton) {
                reprintButton.disabled = posReceiptPrintProcessing;
                reprintButton.style.display = (!failed && posReceiptPrintAttempted && !posReceiptPrintProcessing) ? '' : 'none';
            }
        }

        function openReceiptModal(receipt) {
            const overlay = document.getElementById('receipt-modal-overlay');
            const printArea = document.getElementById('receipt-print-area');
            if (!overlay || !printArea) return;
            activePosReceipt = receipt || {};
            activePosPrintJob = null;
            posReceiptPrintProcessing = false;
            posReceiptPrintAttempted = false;
            setPosReceiptPrintState('No physical receipt has been printed yet.');
            printArea.innerHTML = buildReceiptHtml(activePosReceipt);
            resetReceiptFeedAnimation(printArea);
            renderPosReceiptQr(receipt?.qr_payload);
            renderPosOnlineStoreQr();
            overlay.style.display = 'flex';
            document.body.style.overflow = 'hidden';
        }

        function renderPosReceiptQr(payload) {
            const target = document.getElementById('pos-receipt-qr');
            if (!target || !payload || typeof QRCode === 'undefined') return;
            target.innerHTML = '';
            new QRCode(target, { text: String(payload), width: 116, height: 116, correctLevel: QRCode.CorrectLevel.M });
        }

        function renderPosOnlineStoreQr() {
            const target = document.getElementById('pos-online-store-qr');
            const onlineStoreUrl = 'https://mrandmrsprintflow.com/';
            if (!target || typeof QRCode === 'undefined') return;
            target.innerHTML = '';
            new QRCode(target, { text: onlineStoreUrl, width: 116, height: 116, correctLevel: QRCode.CorrectLevel.M });
        }

        function closeReceiptModal() {
            const overlay = document.getElementById('receipt-modal-overlay');
            if (!overlay) return;
            overlay.style.display = 'none';
            document.body.style.overflow = '';
        }

        async function printReceipt() {
            if (posReceiptPrintProcessing || !activePosReceipt?.order_id) return;
            const printArea = document.getElementById('receipt-print-area');
            resetReceiptFeedAnimation(printArea);
            void printArea.offsetHeight;
            const durationMs = estimatePosReceiptPrintDurationMs(printArea);

            posReceiptPrintProcessing = true;
            setPosReceiptPrintState('Printing receipt...');

            const animationPromise = runReceiptFeedAnimation(printArea, durationMs);
            const printTaskPromise = activePosPrintJob?.job_id
                ? retryReceiptPrintJob(activePosPrintJob, { silentStatus: true })
                : (async () => {
                    const response = await fetch(staffUrl('staff/api/pos_receipt_print.php'), {
                        method: 'POST',
                        headers: {'Content-Type': 'application/json'},
                        body: JSON.stringify({
                            action: 'print',
                            order_id: Number(activePosReceipt.order_id),
                            csrf_token: POS_CSRF_TOKEN
                        })
                    });
                    const result = await response.json();
                    if (!response.ok || !result.success || !result.print_job?.ok) {
                        throw new Error(result.message || 'Receipt printing failed.');
                    }
                    activePosPrintJob = result.print_job;
                    const confirmed = await monitorReceiptPrintJob(result.print_job, { silentSuccess: true });
                    if (confirmed === false) {
                        throw new Error('Receipt printing failed.');
                    }
                })();

            try {
                await animationPromise;
                await printTaskPromise;
                posReceiptPrintAttempted = true;
                activePosPrintJob = null;
                posReceiptPrintProcessing = false;
                setPosReceiptPrintState('Print attempt completed. If the physical copy failed, use Reprint Receipt.');
                showPOSScanNotice('Transaction completed', 'Receipt print attempt completed.', 'success');
            } catch (error) {
                console.error('Receipt printing failed:', error);
                posReceiptPrintProcessing = false;
                resetReceiptFeedAnimation(printArea);
                setPosReceiptPrintState('Receipt printing failed.', true);
            }
        }

        async function confirmReprintReceipt() {
            if (posReceiptPrintProcessing || !activePosReceipt?.order_id) return;
            const confirmed = await showPOSConfirm(
                'Reprint Receipt?',
                'Are you sure you want to reprint this receipt?',
                'Reprint',
                'confirm'
            );
            if (!confirmed) return;
            await reprintReceipt();
        }

        async function reprintReceipt() {
            if (posReceiptPrintProcessing || !activePosReceipt?.order_id) return;
            const printArea = document.getElementById('receipt-print-area');
            const receiptForDisplay = { ...activePosReceipt, reprint: true };
            resetReceiptFeedAnimation(printArea);
            if (printArea) {
                printArea.innerHTML = buildReceiptHtml(receiptForDisplay);
            }
            renderPosReceiptQr(activePosReceipt?.qr_payload);
            renderPosOnlineStoreQr();
            void printArea.offsetHeight;

            const durationMs = estimatePosReceiptPrintDurationMs(printArea);
            posReceiptPrintProcessing = true;
            setPosReceiptPrintState('Reprinting receipt...');

            const animationPromise = runReceiptFeedAnimation(printArea, durationMs);
            const reprintTaskPromise = (async () => {
                const response = await fetch(staffUrl('staff/api/pos_receipt_print.php'), {
                    method: 'POST',
                    headers: {'Content-Type': 'application/json'},
                    body: JSON.stringify({
                        action: 'reprint',
                        order_id: Number(activePosReceipt.order_id),
                        csrf_token: POS_CSRF_TOKEN
                    })
                });
                const result = await response.json();
                if (!response.ok || !result.success || !result.print_job?.ok) {
                    throw new Error(result.message || 'Receipt reprint failed.');
                }
                activePosPrintJob = result.print_job;
                const confirmed = await monitorReceiptPrintJob(result.print_job, { silentSuccess: true });
                if (confirmed === false) {
                    throw new Error('Receipt reprint failed.');
                }
            })();

            try {
                await animationPromise;
                await reprintTaskPromise;
                activePosPrintJob = null;
                posReceiptPrintProcessing = false;
                setPosReceiptPrintState('Reprint attempt completed. If the physical copy failed, you can reprint again.');
                showPOSScanNotice('Receipt reprint', 'Receipt reprint attempt completed.', 'success');
            } catch (error) {
                console.error('Receipt reprint failed:', error);
                posReceiptPrintProcessing = false;
                resetReceiptFeedAnimation(printArea);
                setPosReceiptPrintState('Receipt reprint failed.', true);
            }
        }

        async function retryReceiptPrintJob(printJob, options = {}) {
            const silentStatus = !!options.silentStatus;
            const jobId = Number(printJob?.job_id || 0);
            if (jobId <= 0) {
                if (!silentStatus) {
                    await showPOSAlert(
                        'Receipt printing failed',
                        'The sale is complete, but no printable receipt job is available. Please contact an administrator.',
                        'error'
                    );
                }
                throw new Error('No printable receipt job is available.');
            }
            try {
                const response = await fetch(staffUrl('staff/api/pos_receipt_print_retry.php'), {
                    method: 'POST',
                    headers: {'Content-Type': 'application/json'},
                    body: JSON.stringify({job_id: jobId, csrf_token: POS_CSRF_TOKEN})
                });
                const result = await response.json();
                if (!response.ok || !result.success) {
                    throw new Error(result.message || 'Receipt print job could not be retried.');
                }
                activePosPrintJob = result.print_job || {ok: true, job_id: jobId};
                const confirmed = await monitorReceiptPrintJob(activePosPrintJob, { silentSuccess: silentStatus });
                if (confirmed === false) {
                    throw new Error('Receipt print job failed.');
                }
            } catch (error) {
                console.error('Receipt print retry failed:', error);
                if (!silentStatus) {
                    posReceiptPrintProcessing = false;
                    setPosReceiptPrintState('Receipt printing failed.', true);
                }
                throw error;
            }
        }

        async function showReceiptPrintFailure(printJob, detail, statusResult = null) {
            const message = String(detail || 'The printer did not confirm the receipt.');
            const job = statusResult?.job || {};
            const failure = {
                success: statusResult?.success ?? false,
                status: statusResult?.status ?? job.status ?? printJob?.status ?? 'unknown',
                message: statusResult?.message ?? message,
                error: statusResult?.error ?? job.error_message ?? null,
                job_id: Number(printJob?.job_id || 0),
                print_job_id: Number(statusResult?.print_job_id || job.print_job_id || printJob?.job_id || 0),
                printer_id: Number(statusResult?.printer_id || job.printer_id || 0),
                attempts: Number(statusResult?.attempts ?? job.attempts ?? 0),
                provider: statusResult?.provider ?? job.provider ?? 'pushy',
                delivery_status: statusResult?.delivery_status ?? job.delivery_status ?? 'unknown',
                order_number: job.order_number ?? null,
                pushy_secret_configured: job.pushy_secret_configured ?? null,
                pushy_device_registered: job.pushy_device_registered ?? null,
                printer_last_seen_at: job.printer_last_seen_at ?? null,
                events: Array.isArray(job.events) ? job.events : [],
                http_status: statusResult?._http_status ?? null
            };
            console.error('Receipt printing failed:', failure);
            if (!printJob?.job_id) {
                posReceiptPrintProcessing = false;
                setPosReceiptPrintState('Receipt printing failed.', true);
                return;
            }
            posReceiptPrintProcessing = false;
            activePosPrintJob = printJob?.job_id ? printJob : activePosPrintJob;
            setPosReceiptPrintState('Receipt printing failed.', true);
        }

        async function monitorReceiptPrintJob(printJob, options = {}) {
            const silentSuccess = !!options.silentSuccess;
            if (!printJob?.ok || !printJob?.job_id) {
                await showReceiptPrintFailure(printJob, printJob?.message || 'The receipt could not be queued for the configured printer.');
                return false;
            }

            if (!silentSuccess) {
                showPOSScanNotice('Transaction completed', 'Printing receipt...', 'success');
            }
            let lastStatusResult = null;
            for (let attempt = 0; attempt < 15; attempt += 1) {
                await new Promise(resolve => window.setTimeout(resolve, 1500));
                try {
                    const response = await fetch(
                        staffUrl('staff/api/pos_receipt_print_status.php') + '?job_id=' + encodeURIComponent(printJob.job_id) + '&_=' + Date.now(),
                        {cache: 'no-store'}
                    );
                    const result = await response.json();
                    lastStatusResult = {...result, _http_status: response.status};
                    const status = result?.job?.status;
                    if (response.ok && status === 'printed') {
                        posReceiptPrintProcessing = false;
                        activePosPrintJob = null;
                        if (!silentSuccess) {
                            setPosReceiptPrintState('Receipt printed successfully.');
                            showPOSScanNotice('Transaction completed', 'Receipt printed successfully.', 'success');
                        }
                        return true;
                    }
                    if (response.ok && status === 'failed') {
                        await showReceiptPrintFailure(
                            printJob,
                            result?.job?.error_message || 'PushPrinter reported that the receipt could not be printed.',
                            lastStatusResult
                        );
                        return false;
                    }
                } catch (error) {
                    lastStatusResult = {
                        success: false,
                        status: 'status_request_failed',
                        message: error?.message || 'Receipt print status request failed.',
                        error: error?.message || String(error),
                        job_id: Number(printJob.job_id || 0),
                        print_job_id: Number(printJob.job_id || 0),
                        provider: 'pushy',
                        delivery_status: 'unknown'
                    };
                    console.warn('Receipt print status check failed:', error);
                }
            }
            await showReceiptPrintFailure(
                printJob,
                'PushPrinter did not confirm the receipt in time.',
                lastStatusResult
            );
            return false;
        }

        async function downloadReceiptPdf() {
            const element = document.getElementById('receipt-print-area');
            if (!element) return;
            const receiptNumber = (element.textContent.match(/POS-\d+/) || ['receipt'])[0];
            await html2pdf().set({
                margin: 8,
                filename: `${receiptNumber}.pdf`,
                image: { type: 'jpeg', quality: 0.98 },
                html2canvas: { scale: 2, useCORS: true, backgroundColor: '#ffffff' },
                jsPDF: { unit: 'mm', format: [58, 210], orientation: 'portrait' }
            }).from(element).save();
        }

        function posCustomerSearchText(data) {
            if (!data || !data.element) {
                return (data && data.text) ? String(data.text).toLowerCase() : '';
            }
            const el = data.element;
            return [
                data.text,
                el.getAttribute('data-first'),
                el.getAttribute('data-last'),
                el.getAttribute('data-email'),
                el.getAttribute('data-phone'),
            ].filter(Boolean).join(' ').toLowerCase();
        }

        function isPosGuestCustomerSelected() {
            return $('#pos-customer').val() === 'guest';
        }

        function getPosGuestDisplayName() {
            const el = document.getElementById('pos-guest-name');
            return el ? String(el.value || '').trim() : '';
        }

        function togglePosGuestNameField() {
            const wrap = document.getElementById('pos-guest-name-wrap');
            if (!wrap) return;
            const show = isPosGuestCustomerSelected();
            wrap.style.display = show ? 'block' : 'none';
        }

        function posCheckoutCustomerPayload() {
            const customerId = $('#pos-customer').val();
            const payload = { customer_id: customerId };
            if (customerId === 'guest') {
                payload.guest_display_name = getPosGuestDisplayName();
            }
            return payload;
        }

        function formatPosCustomerOption(customer) {
            if (!customer.id) return customer.text;
            if (customer.id === 'guest') {
                return $('<div class="pos-customer-option pos-customer-option--guest"><div class="pos-customer-option__name">Walk-in Customer (Guest)</div></div>');
            }
            const el = customer.element;
            const first = el ? (el.getAttribute('data-first') || '') : '';
            const last = el ? (el.getAttribute('data-last') || '') : '';
            const email = el ? (el.getAttribute('data-email') || '') : '';
            const phone = el ? (el.getAttribute('data-phone') || '') : '';
            const name = (first + ' ' + last).trim();
            const primary = name || email || customer.text;
            const secondary = name ? (email || phone || '') : (phone || '');
            const wrap = $('<div class="pos-customer-option"><div class="pos-customer-option__name"></div></div>');
            wrap.find('.pos-customer-option__name').text(primary);
            if (secondary) {
                wrap.append($('<div class="pos-customer-option__email"></div>').text(secondary));
            }
            return wrap;
        }

        function formatPosCustomerSelection(customer) {
            if (!customer.id) return customer.text;
            if (customer.id === 'guest') return 'Walk-in Customer (Guest)';
            const el = customer.element;
            const first = el ? (el.getAttribute('data-first') || '') : '';
            const last = el ? (el.getAttribute('data-last') || '') : '';
            const name = (first + ' ' + last).trim();
            return name || customer.text;
        }

        // Initialize Select2 for customer dropdown
        $(document).ready(function () {
            $('#pos-customer').select2({
                placeholder: 'Select customer...',
                allowClear: false,
                width: '100%',
                minimumResultsForSearch: 0,
                dropdownParent: $('body'),
                dropdownCssClass: 'pos-customer-select-dropdown',
                templateResult: formatPosCustomerOption,
                templateSelection: formatPosCustomerSelection,
                matcher: function(params, data) {
                    if ($.trim(params.term) === '') return data;
                    if (typeof data.text === 'undefined') return null;
                    return posCustomerSearchText(data).indexOf(params.term.toLowerCase()) > -1 ? data : null;
                },
                language: {
                    noResults: function() { return 'No matching customers'; }
                }
            }).on('select2:open', function() {
                setTimeout(function() {
                    var field = document.querySelector('.select2-container--open .select2-search__field');
                    if (!field) return;
                    field.setAttribute('placeholder', 'Search customer by name or email...');
                    field.focus({ preventScroll: true });
                }, 0);
            }).on('change', function() {
                togglePosGuestNameField();
                updateCheckoutState();
            });

            const guestNameEl = document.getElementById('pos-guest-name');
            if (guestNameEl) {
                guestNameEl.addEventListener('input', updateCheckoutState);
            }

            // Set default to guest
            $('#pos-customer').val('guest').trigger('change');
            togglePosGuestNameField();
        });

        function showPOSMode(mode) {
            currentMode = mode;
            document.getElementById('selection-view').style.display = 'none';

            if (mode === 'products') {
                document.getElementById('products-view').style.display = 'flex';
                document.getElementById('services-view').style.display = 'none';
                fetchProducts();
                setTimeout(focusBarcodeInput, 40);
            } else if (mode === 'services') {
                document.getElementById('products-view').style.display = 'none';
                document.getElementById('services-view').style.display = 'flex';
            }
        }
        window.showPOSMode = showPOSMode;

        function backToSelection() {
            currentMode = null;
            document.getElementById('selection-view').style.display = 'flex';
            document.getElementById('products-view').style.display = 'none';
            document.getElementById('services-view').style.display = 'none';
        }
        window.backToSelection = backToSelection;

        document.addEventListener('DOMContentLoaded', async () => {
            initPosPaymentMethodHandlers();
            toggleReferenceField();
            fetchProducts();
            await refreshCart(); // Initialize cart from session
            dismissPosQrphFlow({ closeModal: true, clearToken: false, bumpEpoch: false });
            posSyncQrphStateWithCart();
            const barcodeInputs = Array.from(document.querySelectorAll('.pos-barcode-entry'));
            const searchEl = document.getElementById('pos-search');
            const catEl = document.getElementById('pos-category');
            barcodeInputs.forEach(function(barcodeEl) {
                barcodeEl.addEventListener('keydown', function(e) {
                    if (!isBarcodeTerminatorKey(e.key)) return;
                    e.preventDefault();
                    handleBarcodeScan(barcodeEl.value, barcodeEl, {terminator: e.key, source: 'barcode-input'});
                });
                barcodeEl.addEventListener('paste', function() {
                    setTimeout(function() { barcodeEl.select(); }, 0);
                });
            });
            installPosBarcodeKeyboardCapture();
            setTimeout(focusBarcodeInput, 80);
            if (searchEl) searchEl.addEventListener('input', renderProducts);
            if (catEl) catEl.addEventListener('change', renderProducts);

            // Check if returning from customizations page with updated price
            const urlParams = new URLSearchParams(window.location.search);
            if (urlParams.get('from_customizations') === '1') {
                // Restore customer selection if saved
                const savedState = sessionStorage.getItem('pos_cart_state');
                let state = null;
                if (savedState) {
                    try {
                        state = JSON.parse(savedState);
                        if (state.customer) {
                            $('#pos-customer').val(state.customer).trigger('change');
                        }
                        if (state.guest_display_name) {
                            const guestNameInput = document.getElementById('pos-guest-name');
                            if (guestNameInput) {
                                guestNameInput.value = state.guest_display_name;
                            }
                        }
                        if (state.item_index !== undefined && state.updated_price !== undefined) {
                            await syncedCartAction('update_price', {
                                index: state.item_index,
                                price: state.updated_price
                            });
                            sessionStorage.removeItem('pos_cart_state');
                        }
                    } catch (e) { }
                }
                await refreshCart();
                if (state && state.updated_price === undefined && Array.isArray(state.cart) && state.cart.length > 0 && cart.length === 0) {
                    for (const savedItem of state.cart) {
                        await syncedCartAction('add', {
                            product_id: savedItem.product_id,
                            name: savedItem.name,
                            price: posCartItemEstimatedUnitPrice(savedItem),
                            qty: savedItem.qty || 1,
                            customization: savedItem.customization || {},
                            is_service: savedItem.is_service === true,
                            price_set: savedItem.price_set === true
                        }, { silentErrors: true });
                    }
                }
                // Clean URL
                window.history.replaceState({}, document.title, window.location.pathname);
            }
        });

        async function syncedCartAction(action, payload = {}, options = {}) {
            if (posCheckoutRequestInFlight && action !== 'clear' && !options.allowDuringCheckout) {
                console.warn('Skipped cart sync during checkout:', action);
                return { success: false, skipped: true, message: 'Checkout in progress.' };
            }
            console.log('syncedCartAction:', action, payload);
            try {
                const response = await fetchWithTimeout(staffUrl('staff/api/pos_cart_handler.php'), {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ action, ...payload, csrf_token: POS_CSRF_TOKEN })
                }, Number(options.timeoutMs || 15000));
                const responseText = await response.text();
                let data;
                try {
                    data = JSON.parse(responseText);
                } catch (parseError) {
                    throw new Error('Cart server returned an invalid response.');
                }
                console.log('syncedCartAction Response:', data);
                if (data && data.errors && typeof data.errors === 'object') {
                    console.log('syncedCartAction errors:', data.errors);
                }
                if (response.ok && data.success) {
                    cart = data.cart || [];
                    console.log('Updated local cart:', cart);
                    renderCart();
                    if (action === 'add' && options.fxSourceEl) {
                        posPlayAddAnimation(options.fxSourceEl);
                    }
                    return { success: true };
                } else {
                    console.error('syncedCartAction Error:', data.message);
                    if (!options.silentErrors) await showPOSAlert('Error', data.message || 'Action failed', 'error');
                    return { success: false, message: data.message, errors: data.errors || null };
                }
            } catch (e) {
                console.error('Cart Action Error:', e);
                if (!options.silentErrors) await showPOSAlert('Network Error', 'Network error while updating cart.', 'error');
                return { success: false };
            }
        }

        async function refreshCart() {
            await syncedCartAction('get');
        }

        let posProductFetchId = 0;
        let posProductFetchBusy = false;

        function refreshVisibleProductStock() {
            if (currentMode === 'products' && !document.hidden) {
                fetchProducts({ background: true });
            }
        }
        window.addEventListener('focus', refreshVisibleProductStock);
        document.addEventListener('visibilitychange', refreshVisibleProductStock);
        window.setInterval(refreshVisibleProductStock, 30000);

        async function fetchProducts({ background = false } = {}) {
            if (background && posProductFetchBusy) return;
            const fetchId = ++posProductFetchId;
            posProductFetchBusy = true;
            const grid = document.getElementById('pos-products-grid');
            if (grid && !background) {
                grid.innerHTML = '<div style="grid-column:1/-1; text-align:center; padding:40px; color:#94a3b8;"><i class="fas fa-spinner fa-spin" style="font-size:32px; margin-bottom:16px;"></i><br>Loading products...</div>';
            }
            try {
                const res = await fetch(staffUrl('staff/api/get_products.php'), { cache: 'no-store' });
                const data = await res.json();
                if (fetchId !== posProductFetchId) return;
                if (res.ok && data.success) {
                    const changed = JSON.stringify(products) !== JSON.stringify(data.products || []);
                    products = data.products || [];
                    if (grid && (!background || changed)) {
                        const focusedId = document.activeElement?.dataset?.productId;
                        const scrollTop = grid.scrollTop;
                        renderProducts();
                        grid.scrollTop = scrollTop;
                        if (focusedId) {
                            Array.from(grid.children).find(card => card.dataset.productId === focusedId)
                                ?.focus({ preventScroll: true });
                        }
                    }
                } else {
                    if (grid && !background) grid.textContent = 'Failed to load products. Please reopen Products to retry.';
                }
            } catch (e) {
                console.error('Fetch error:', e);
                if (fetchId === posProductFetchId && grid && !background) grid.textContent = 'Unable to load products. Please reopen Products to retry.';
            } finally {
                if (fetchId === posProductFetchId) posProductFetchBusy = false;
            }
        }

        // ── Service Modal (DB-driven fields) ────────────────────────────────────────
        function getBranchField() {
            const branches = (window.POS_BRANCHES || []).map(b => ({ value: b.id, label: b.name }));
            const hasBranches = branches && branches.length > 0;
            return { label: 'Branch *', type: 'select', name: 'branch_id', options: branches, required: hasBranches };
        }

        function destroyPosEstimatedPrice() {
            if (posEstimatedPriceController && typeof posEstimatedPriceController.destroy === 'function') {
                posEstimatedPriceController.destroy();
            }
            posEstimatedPriceController = null;
            resetPosEstimatedPriceDisplay();
        }

        function resetPosEstimatedPriceDisplay() {
            const footer = document.getElementById('pos-estimated-price-display');
            if (!footer) return;
            const totalEl = footer.querySelector('#pos-estimated-total');
            const qtyEl = footer.querySelector('#pos-qty-display');
            if (totalEl) totalEl.textContent = '₱0.00';
            if (qtyEl) qtyEl.textContent = '1';
        }

        function initPosEstimatedPrice(body, basePrice) {
            destroyPosEstimatedPrice();
            const footer = document.getElementById('pos-estimated-price-display');
            if (!footer || typeof window.printflowInitServiceEstimatedPrice !== 'function') {
                return;
            }
            posEstimatedPriceController = window.printflowInitServiceEstimatedPrice(body, {
                basePrice: basePrice,
                form: body,
                estimatedTotalEl: footer.querySelector('#pos-estimated-total'),
                qtyDisplayEl: footer.querySelector('#pos-qty-display')
            });
        }

        function resolveServiceModalPricing() {
            let unitPrice = 0;
            let quantity = 1;
            if (posEstimatedPriceController && typeof posEstimatedPriceController.recalculate === 'function') {
                const result = posEstimatedPriceController.recalculate();
                unitPrice = Number(result.unitPrice);
                quantity = Number(result.quantity);
            } else if (typeof window.calculateEstimatedPrice === 'function') {
                const result = window.calculateEstimatedPrice();
                unitPrice = Number(result.unitPrice);
                quantity = Number(result.quantity);
            }
            if (!Number.isFinite(unitPrice)) unitPrice = 0;
            if (!Number.isFinite(quantity) || quantity < 1) quantity = 1;
            return { unitPrice, quantity };
        }

        const POS_MISSING_ESTIMATE_MESSAGE = 'This specification does not have an estimated price yet. The final price will be set by Staff before payment or order completion. Do you want to continue?';

        async function confirmMissingEstimatedPrice() {
            return showPOSConfirm('Estimated Price', POS_MISSING_ESTIMATE_MESSAGE, 'Continue', 'warning');
        }

        function applyServicePricingToCustomization(customization, unitPrice, quantity) {
            const safeUnit = Math.round(unitPrice * 100) / 100;
            const safeQty = Math.max(1, parseInt(String(quantity), 10) || 1);
            customization.calculated_unit_price = safeUnit.toFixed(2);
            customization.calculated_estimated_price = (safeUnit * safeQty).toFixed(2);
            customization.quantity = String(safeQty);
            if (safeUnit > 0) {
                delete customization.price_unconfigured;
            } else {
                customization.price_unconfigured = '1';
            }
            return { unitPrice: safeUnit, quantity: safeQty };
        }

        async function openServiceModal(serviceId, serviceName) {
            console.log('openServiceModal called:', serviceId, serviceName);
            const overlay = document.getElementById('service-modal-overlay');
            const title = document.getElementById('sm-title');
            const body = document.getElementById('sm-fields-body');
            const footerActions = document.getElementById('sm-footer-actions');

            destroyPosEstimatedPrice();
            posServiceStockBlocking = true;
            const stockFeedback = document.getElementById('pos-service-stock-feedback');
            const stockIndicator = document.getElementById('pos-service-stock-indicator');
            const stockWarning = document.getElementById('pos-service-stock-warning');
            if (stockFeedback) stockFeedback.style.display = 'block';
            if (stockIndicator) stockIndicator.textContent = 'Checking current material stock…';
            if (stockWarning) stockWarning.textContent = '';
            setServiceAddButtonBusy(false);
            title.textContent = serviceName + ' — Order Details';
            body.innerHTML = '<div style="text-align:center;padding:2rem;color:#64748b;"><i class="fas fa-spinner fa-spin"></i> Loading fields...</div>';
            footerActions.style.display = 'none';
            overlay.style.display = 'flex';
            overlay.dataset.serviceId = serviceId;
            overlay.dataset.serviceName = serviceName;

            try {
                const res = await fetch(staffUrl('staff/api/pos_service_fields.php?service_id=') + serviceId);
                const data = await res.json();
                console.log('Service fields response:', data);
                if (!data.success) {
                    body.innerHTML = '<p style="color:#ef4444;text-align:center;padding:1rem;">' + (data.error || 'Failed to load fields.') + '</p>';
                    return;
                }
                overlay.dataset.csrfToken = data.csrf_token;
                body.innerHTML = data.fields_html;
                if (Number(serviceId) === 40) posEnsureTarpaulinJoiningControls(body);
                footerActions.style.display = 'block';
                isAddingToOrder = false;
                setServiceAddButtonBusy(false);

                // Lock branch to staff's assigned branch
                if (data.staff_branch_id) {
                    const branchSel = body.querySelector('select[name="branch_id"]');
                    if (branchSel) {
                        const branchField = branchSel.closest('.shopee-form-field');
                        const branchName = data.staff_branch_name || branchSel.options[branchSel.selectedIndex]?.text || 'Assigned Branch';
                        if (branchField) {
                            branchField.innerHTML = `
                                <div class="input-field-locked" style="width:175px; max-width:175px; display:inline-flex; align-items:center; justify-content:space-between; gap:10px; padding:.6rem .85rem; border-radius:.5rem; background:var(--staff-primary); color:#ffffff; border:1px solid var(--staff-primary); font-size:.95rem; font-weight:700; box-shadow:0 8px 18px rgba(var(--staff-accent-rgb), 0.22);">
                                    <span style="overflow:hidden; text-overflow:ellipsis; white-space:nowrap;">${branchName}</span>
                                    <i class="fas fa-building" style="font-size:.85rem; opacity:.9;"></i>
                                </div>
                            `;
                        }

                        const hidden = document.createElement('input');
                        hidden.type = 'hidden';
                        hidden.name = 'branch_id';
                        hidden.value = data.staff_branch_id;
                        (branchField || branchSel.parentNode).appendChild(hidden);
                    }
                }

                // Re-run the field scripts (conditional logic, qty buttons, etc.)
                if (typeof updateConditionalFields === 'function') updateConditionalFields();
                body.querySelectorAll('.shopee-opt-btn input[type="radio"]').forEach(r => {
                    r.addEventListener('change', function () {
                        if (typeof updateOptVisual === 'function') updateOptVisual(this);
                        if (typeof updateConditionalFields === 'function') updateConditionalFields();
                    });
                });
                body.querySelectorAll('select').forEach(s => {
                    if (typeof pfSyncSelectOthersWrap === 'function') pfSyncSelectOthersWrap(s);
                    s.addEventListener('change', function () {
                        if (typeof pfSyncSelectOthersWrap === 'function') pfSyncSelectOthersWrap(this);
                        if (typeof updateConditionalFields === 'function') updateConditionalFields();
                    });
                });
                bindServiceValidationClearers(body);
                if (typeof initServiceFieldRenderer === 'function') initServiceFieldRenderer(body);
                else if (typeof initPfDesignUploadGroups === 'function') initPfDesignUploadGroups(body);
                initPosEstimatedPrice(body, parseFloat(data.base_price) || 0);
                posBindServiceStockValidation(serviceId);
                if (typeof posAllowPastNeededDateInputs === 'function') {
                    posAllowPastNeededDateInputs(body);
                }
            } catch (e) {
                body.innerHTML = '<p style="color:#ef4444;text-align:center;padding:1rem;">Network error. Please try again.</p>';
            }
        }

        function closeServiceModal() {
            isAddingToOrder = false;
            setServiceAddButtonBusy(false);
            destroyPosEstimatedPrice();
            const body = document.getElementById('sm-fields-body');
            if (body) body.innerHTML = '';
            const overlay = document.getElementById('service-modal-overlay');
            if (overlay) {
                delete overlay.dataset.serviceId;
                delete overlay.dataset.serviceName;
                overlay.style.display = 'none';
            }
        }

        async function posReadFilePayload(file) {
            return new Promise((resolve, reject) => {
                const reader = new FileReader();
                reader.onload = () => resolve({
                    name: file.name || '',
                    mime: file.type || '',
                    data: typeof reader.result === 'string' ? reader.result : ''
                });
                reader.onerror = () => reject(new Error('Failed to read file.'));
                reader.readAsDataURL(file);
            });
        }

        function posNewUploadIdempotencyKey() {
            if (typeof crypto !== 'undefined' && typeof crypto.randomUUID === 'function') {
                return crypto.randomUUID();
            }
            return 'pf-' + Date.now() + '-' + Math.random().toString(36).slice(2);
        }

        async function posStageMediaUpload(file, field = 'design', options = {}) {
            const fd = new FormData();
            fd.append('field', field);
            fd.append('csrf_token', POS_CSRF_TOKEN);
            if (options.idempotency_key) {
                fd.append('idempotency_key', String(options.idempotency_key));
            }
            if (options.upload_sort !== undefined && options.upload_sort !== null) {
                fd.append('upload_sort', String(options.upload_sort));
            }
            if (options.order_item_id) {
                fd.append('order_item_id', String(options.order_item_id));
            }
            fd.append(field === 'reference' ? 'reference_file' : 'design_file', file);
            const res = await fetch(staffUrl('staff/api/pos_upload_design.php'), {
                method: 'POST',
                body: fd
            });
            const data = await res.json();
            if (!data || !data.success || !data.path) {
                throw new Error((data && data.message) ? data.message : 'Failed to stage upload.');
            }
            return data;
        }

        function posEnsureDesignUploadFiles(customization) {
            if (!Array.isArray(customization.design_upload_files)) {
                customization.design_upload_files = [];
            }
            return customization.design_upload_files;
        }

        function posUpsertDesignUploadFile(customization, staged, idempotencyKey) {
            const list = posEnsureDesignUploadFiles(customization);
            const key = String(staged.idempotency_key || idempotencyKey || '');
            if (key && list.some((entry) => String(entry.idempotency_key || '') === key)) {
                return staged;
            }
            const entry = {
                path: staged.path,
                name: staged.name,
                mime: staged.mime,
                size: staged.size || 0,
                upload_sort: typeof staged.upload_sort === 'number' ? staged.upload_sort : list.length,
                idempotency_key: key || posNewUploadIdempotencyKey(),
                file_id: staged.file_id || 0,
            };
            list.push(entry);
            list.sort((a, b) => (Number(a.upload_sort || 0) - Number(b.upload_sort || 0)) || (Number(a.file_id || 0) - Number(b.file_id || 0)));
            customization.design_upload = entry.name;
            customization.design_upload_name = entry.name;
            customization.design_upload_mime = entry.mime;
            customization.design_upload_path = entry.path;
            customization.design_upload_idempotency_key = entry.idempotency_key;
            delete customization.design_upload_data;
            return staged;
        }

        async function posApplyStagedUpload(customization, file, field = 'design') {
            const idempotencyKey = posNewUploadIdempotencyKey();
            const uploadSort = field === 'design' ? posEnsureDesignUploadFiles(customization).length : 0;
            const staged = await posStageMediaUpload(file, field, {
                idempotency_key: idempotencyKey,
                upload_sort: uploadSort,
            });
            if (field === 'reference') {
                customization.reference_upload = staged.name;
                customization.reference_upload_name = staged.name;
                customization.reference_upload_mime = staged.mime;
                customization.reference_upload_path = staged.path;
                delete customization.reference_upload_data;
            } else {
                posUpsertDesignUploadFile(customization, staged, idempotencyKey);
            }
            return staged;
        }

        const PF_MAX_DESIGN_FILES_PER_ITEM = 10;
        let posDesignUploadInFlight = false;

        function posCountUniqueDesignFiles(customization) {
            const list = posEnsureDesignUploadFiles(customization);
            const seen = new Set();
            let count = 0;
            list.forEach((entry) => {
                const fileId = Number(entry && entry.file_id ? entry.file_id : 0);
                if (fileId > 0) {
                    if (seen.has(fileId)) {
                        return;
                    }
                    seen.add(fileId);
                }
                count++;
            });
            return count;
        }

        function posApplyAuthoritativeDesignFiles(customization, apiFiles) {
            if (!Array.isArray(apiFiles)) {
                return;
            }
            const PF = window.PrintflowOrderItemDesignFiles;
            const normalized = PF ? PF.dedupe(apiFiles) : apiFiles;
            customization.design_upload_files = normalized.map((row) => ({
                file_id: row.file_id || 0,
                path: row.storage_path || row.path || '',
                name: row.display_name || row.name || '',
                mime: row.mime_type || row.mime || '',
                size: row.file_size || row.size || 0,
                upload_sort: row.upload_sort || 0,
                idempotency_key: row.idempotency_key || '',
            }));
            const first = customization.design_upload_files[0];
            if (first) {
                customization.design_upload = first.name;
                customization.design_upload_name = first.name;
                customization.design_upload_mime = first.mime;
                customization.design_upload_path = first.path;
                customization.design_upload_idempotency_key = first.idempotency_key || '';
            }
        }

        async function posApplyStagedUploadMany(customization, fileList, field = 'design') {
            if (field === 'design' && posDesignUploadInFlight) {
                throw new Error('An upload is already in progress. Please wait.');
            }
            const files = Array.from(fileList || []).filter(Boolean);
            if (files.length === 0) return;
            const existing = field === 'design' ? posCountUniqueDesignFiles(customization) : 0;
            const remaining = PF_MAX_DESIGN_FILES_PER_ITEM - existing;
            if (field === 'design' && remaining <= 0) {
                throw new Error('Maximum ' + PF_MAX_DESIGN_FILES_PER_ITEM + ' design files allowed for this order item.');
            }
            let batch = files;
            if (field === 'design' && files.length > remaining) {
                batch = files.slice(0, remaining);
                if (batch.length === 0) {
                    throw new Error('Maximum ' + PF_MAX_DESIGN_FILES_PER_ITEM + ' design files allowed for this order item.');
                }
                await showPOSAlert(
                    'Upload limit',
                    'Only ' + remaining + ' more design file(s) can be added (maximum ' + PF_MAX_DESIGN_FILES_PER_ITEM + '). Extra selected files were not uploaded.',
                    'warning'
                );
            }
            const fd = new FormData();
            fd.append('field', field);
            fd.append('csrf_token', POS_CSRF_TOKEN);
            fd.append('upload_sort', String(existing));
            fd.append('existing_design_file_count', String(existing));
            const pendingOrderItemId = Number(customization.pending_order_item_id || customization.order_item_id || 0);
            if (pendingOrderItemId > 0) {
                fd.append('order_item_id', String(pendingOrderItemId));
            }
            batch.forEach((file) => {
                fd.append(field === 'reference' ? 'reference_file[]' : 'design_file[]', file);
            });
            if (field === 'design') {
                posDesignUploadInFlight = true;
            }
            try {
                const res = await fetch(staffUrl('staff/api/pos_upload_design.php'), { method: 'POST', body: fd });
                const data = await res.json();
                if (!data || !data.success) {
                    const detail = (data && data.errors && data.errors.length)
                        ? data.errors.map((row) => row.message || row.name).join(' ')
                        : ((data && data.message) ? data.message : 'Failed to stage uploads.');
                    throw new Error(detail);
                }
                if (field === 'reference') {
                    const stagedList = Array.isArray(data.staged) ? data.staged : (data.path ? [data] : []);
                    stagedList.forEach((staged) => {
                        customization.reference_upload = staged.name;
                        customization.reference_upload_name = staged.name;
                        customization.reference_upload_mime = staged.mime;
                        customization.reference_upload_path = staged.path;
                        delete customization.reference_upload_data;
                    });
                    return;
                }
                if (Array.isArray(data.files)) {
                    posApplyAuthoritativeDesignFiles(customization, data.files);
                }
            } finally {
                if (field === 'design') {
                    posDesignUploadInFlight = false;
                }
            }
        }

        function posClearDesignCustomizationKeys(customization) {
            if (!customization || typeof customization !== 'object') return;
            delete customization.design_upload_files;
            delete customization.design_upload_path;
            delete customization.design_upload_name;
            delete customization.design_upload_mime;
            delete customization.design_upload;
            delete customization.design_upload_idempotency_key;
            delete customization.design_upload_data;
            delete customization.design_file;
            delete customization.design_tmp_path;
            delete customization.design_external_link;
            delete customization.design_link;
            delete customization.design_file_link;
            delete customization.design_input_mode;
            delete customization.uploaded_files;
            delete customization._uploaded_files;
            Object.keys(customization).forEach((key) => {
                if (/design.*link$/i.test(String(key))) {
                    delete customization[key];
                }
            });
        }

        function posStripInactiveDesignUploads(body, customization) {
            if (!body) return;
            let shouldStrip = false;
            body.querySelectorAll('.pf-file-upload-group').forEach((group) => {
                const row = group.closest('.shopee-form-row');
                if (row && row.dataset.pfFieldInactive === '1') {
                    shouldStrip = true;
                }
            });
            if (shouldStrip) {
                posClearDesignCustomizationKeys(customization);
            }
        }

        async function posStageDesignUploadsFromModal(body, customization) {
            const groups = body.querySelectorAll('.pf-file-upload-group');
            for (const group of groups) {
                const row = group.closest('.shopee-form-row');
                if (row && row.dataset.pfFieldInactive === '1') {
                    continue;
                }
                const modeSelect = group.querySelector('.pf-design-mode-select');
                const designMode = String((modeSelect && modeSelect.value) || group.dataset.pfDesignMode || '').trim();
                if (designMode !== 'file') continue;
                const files = typeof window.pfDesignQueueValidFiles === 'function'
                    ? window.pfDesignQueueValidFiles(group)
                    : Array.from((group.querySelector('.pf-design-file-input') || {}).files || []);
                if (!files.length) continue;
                await posApplyStagedUploadMany(customization, files, 'design');
                if (row) {
                    setCustomizationValue(customization, row, group.querySelector('.pf-design-file-input'), customization.design_upload_name);
                }
            }
        }

        async function confirmServiceModalLegacy() {
            const overlay = document.getElementById('service-modal-overlay');
            const serviceId = parseInt(overlay.dataset.serviceId);
            const serviceName = overlay.dataset.serviceName;
            const body = document.getElementById('sm-fields-body');

            // Collect all field values from the rendered form
            const customization = {};
            let valid = true;

            // Branch — prefer hidden input (set when locked for staff)
            const branchHidden = body.querySelector('input[type="hidden"][name="branch_id"]');
            const branchSel = body.querySelector('select[name="branch_id"]');
            const branchVal = (branchHidden && branchHidden.value) ? branchHidden.value : (branchSel ? branchSel.value : '');
            if (!branchVal) {
                await showPOSAlert('Branch Required', 'Please select a branch.', 'warning');
                if (branchSel) branchSel.focus();
                return;
            }
            customization['branch_id'] = branchVal;
            customization['service_id'] = serviceId;
            customization['service_type'] = serviceName;

            // All visible rows
            body.querySelectorAll('.shopee-form-row').forEach(row => {
                if (row.style.display === 'none') return; // skip hidden conditional rows
                if (row.dataset.pfFieldInactive === '1' || row.classList.contains('pf-field-conditionally-disabled')) return;
                const label = row.querySelector('.shopee-form-label');
                if (!label) return;
                const labelText = label.innerText.replace('*', '').trim();
                const isRequired = label.innerText.includes('*');

                // Radio
                const checkedRadio = row.querySelector('input[type="radio"]:checked');
                if (checkedRadio) {
                    customization[labelText] = normalizeLayoutCustomizationValue(row, checkedRadio.value);
                    return;
                }

                // Select (non-branch)
                const sel = row.querySelector('select:not([name="branch_id"])');
                if (sel && sel.value) { customization[labelText] = sel.value; }
                if (sel && isRequired && !sel.value) {
                    showPOSAlert('Required Field', labelText + ' is required.', 'warning');
                    valid = false;
                }

                // Date
                const dateInput = row.querySelector('input[type="date"]');
                if (dateInput && dateInput.value) { customization[labelText] = dateInput.value; }
                if (dateInput && isRequired && !dateInput.value) {
                    showPOSAlert('Required Field', labelText + ' is required.', 'warning');
                    valid = false;
                }

                // Quantity
                const qtyInput = row.querySelector('#quantity-input');
                if (qtyInput) { customization['quantity'] = qtyInput.value || 1; }

                // Textarea (notes)
                const textarea = row.querySelector('textarea');
                if (textarea && textarea.value.trim()) { customization[labelText] = textarea.value.trim(); }

                // Dimension hidden fields
                const wh = row.querySelector('#width_hidden');
                const hh = row.querySelector('#height_hidden');
                if (wh && hh) {
                    if (wh.value && hh.value) { customization[labelText] = wh.value + '×' + hh.value; }
                    else if (isRequired) {
                        showPOSAlert('Required Field', labelText + ' is required.', 'warning');
                        valid = false;
                    }
                }

                // Text / number / design link
                const textInput = row.querySelector('input[type="text"], input[type="number"]:not(#quantity-input), input[type="url"].pf-design-link-input');
                if (textInput && !textInput.id.includes('hidden') && textInput.value.trim()) {
                    if (textInput.classList.contains('pf-design-link-input')) {
                        posStoreDesignLink(customization, row, textInput);
                    } else {
                        customization[labelText] = textInput.value.trim();
                    }
                }

                const uploadGroup = row.querySelector('.pf-file-upload-group[data-pf-required="1"]');
                if (uploadGroup && isRequired && isServiceUploadGroupRequired(uploadGroup)) {
                    const fileInput = uploadGroup.querySelector('.pf-design-file-input');
                    const linkInput = uploadGroup.querySelector('.pf-design-link-input');
                    const queueFiles = (typeof window.pfDesignQueueValidFiles === 'function')
                        ? window.pfDesignQueueValidFiles(uploadGroup)
                        : [];
                    const hasFile = queueFiles.length > 0 || !!(fileInput && fileInput.files && fileInput.files.length > 0);
                    const hasLink = !!(linkInput && linkInput.value.trim());
                    if (!hasFile && !hasLink) {
                        showPOSAlert('Required Field', 'Please upload a design or paste a design link.', 'warning');
                        valid = false;
                    } else if (hasLink && !isValidDesignLink(linkInput.value.trim())) {
                        showPOSAlert('Invalid Link', 'Please enter a valid HTTPS design link.', 'warning');
                        valid = false;
                    }
                }
            });

            if (typeof window.pfValidateDesignUploadGroups === 'function') {
                const designValidation = window.pfValidateDesignUploadGroups(body);
                if (!designValidation.ok) {
                    await showPOSAlert('Upload Design', designValidation.errors[0] || 'Please fix your design uploads.', 'warning');
                    return;
                }
            }
            body.querySelectorAll('.pf-file-upload-group').forEach((group) => {
                const row = group.closest('.shopee-form-row');
                if (row && row.dataset.pfFieldInactive === '1') {
                    if (typeof window.pfClearDesignUploadGroup === 'function') {
                        window.pfClearDesignUploadGroup(group);
                    }
                    return;
                }
                if (typeof window.pfSyncDesignQueueToFileInput === 'function') {
                    window.pfSyncDesignQueueToFileInput(group);
                }
            });

            try {
                posStripInactiveDesignUploads(body, customization);
                await posStageDesignUploadsFromModal(body, customization);
                posStripInactiveDesignUploads(body, customization);
                const serviceFileInputs = Array.from(body.querySelectorAll('input[type="file"]'));
                for (const fileInput of serviceFileInputs) {
                    if (fileInput.classList.contains('pf-design-file-input')) continue;
                    if (!(fileInput.files && fileInput.files.length > 0)) continue;
                    const row = fileInput.closest('.shopee-form-row');
                    if (row && row.dataset.pfFieldInactive === '1') continue;
                    const label = row ? row.querySelector('.shopee-form-label') : null;
                    const labelText = label ? label.innerText.replace('*', '').trim() : (fileInput.name || 'File');
                    const nameLc = String(fileInput.name || '').toLowerCase();
                    const labelLc = String(labelText || '').toLowerCase();
                    const isReferenceField = nameLc === 'reference_file'
                        || nameLc.includes('reference')
                        || (labelLc.includes('upload') && labelLc.includes('reference'));
                    if (isReferenceField) {
                        await posApplyStagedUploadMany(customization, fileInput.files, 'reference');
                        customization[labelText] = customization.reference_upload_name;
                    } else {
                        const payload = await posReadFilePayload(fileInput.files[0]);
                        customization[labelText] = payload.name;
                    }
                }
                posStripInactiveDesignUploads(body, customization);
            } catch (uploadErr) {
                await showPOSAlert('Upload Failed', uploadErr.message || 'Could not save the design file.', 'error');
                return;
            }

            // Add service to cart with calculated unit price from the modal estimator
            const pricing = resolveServiceModalPricing();
            const priced = applyServicePricingToCustomization(customization, pricing.unitPrice, customization['quantity'] || pricing.quantity);
            if (priced.unitPrice <= 0) {
                const proceed = await confirmMissingEstimatedPrice();
                if (!proceed) return;
            }

            const result = await syncedCartAction('add', {
                product_id: serviceId,
                name: serviceName,
                price: priced.unitPrice,
                qty: priced.quantity,
                customization: customization,
                is_service: true,
                price_set: false
            }, { fxSourceEl: posLastServiceCardEl });

            if (result.success) closeServiceModal();
        }

        function setServiceAddButtonBusy(isBusy) {
            const btn = document.getElementById('sm-add-to-order-btn');
            if (!btn) return;
            const disabled = !!isBusy || posServiceStockBlocking;
            btn.disabled = disabled;
            btn.style.opacity = disabled ? '0.7' : '1';
            btn.style.cursor = disabled ? 'not-allowed' : 'pointer';
            btn.textContent = isBusy ? 'Adding...' : 'Add to Order';
        }

        function posServiceQuantityInput() {
            const body = document.getElementById('sm-fields-body');
            return body ? body.querySelector('#quantity-input, .pf-service-quantity-input, input[name="quantity"]') : null;
        }

        function posSetServiceStockState(blocking, indicatorText = '', warningText = '') {
            posServiceStockBlocking = !!blocking;
            const feedback = document.getElementById('pos-service-stock-feedback');
            const indicator = document.getElementById('pos-service-stock-indicator');
            const warning = document.getElementById('pos-service-stock-warning');
            if (feedback) feedback.style.display = indicatorText || warningText ? 'block' : 'none';
            if (indicator) indicator.textContent = indicatorText;
            if (warning) warning.textContent = warningText;
            setServiceAddButtonBusy(isAddingToOrder);
        }

        function posCollectTarpaulinStockCustomization() {
            const body = document.getElementById('sm-fields-body');
            const customization = {};
            if (!body) return customization;
            if (typeof pfPrepareServiceFormDimensions === 'function') {
                pfPrepareServiceFormDimensions(body);
            }
            const visibleCustom = body.querySelector('.pf-custom-size-panel:not([hidden]) .custom-dim-width, .dim-others-inputs[style*="display: block"] .custom-dim-width');
            const visibleCustomHeight = body.querySelector('.pf-custom-size-panel:not([hidden]) .custom-dim-height, .dim-others-inputs[style*="display: block"] .custom-dim-height');
            if (visibleCustom && visibleCustomHeight && String(visibleCustom.value || '').trim() && String(visibleCustomHeight.value || '').trim()) {
                customization.width = String(visibleCustom.value).trim();
                customization.height = String(visibleCustomHeight.value).trim();
            }
            body.querySelectorAll('[data-dimension-role="width"], [data-dimension-role="height"]').forEach((field) => {
                const role = String(field.dataset.dimensionRole || '').trim().toLowerCase();
                if ((role === 'width' || role === 'height') && !customization[role]) customization[role] = field.value;
            });
            const parsePair = (raw) => {
                const match = String(raw || '').trim().match(/^\s*(\d+(?:\.\d+)?)\s*[x×*]\s*(\d+(?:\.\d+)?)\s*(?:ft|feet|foot)?\s*$/i);
                return match ? { width: match[1], height: match[2] } : null;
            };
            const dimensionControl = body.querySelector('input[name="dimensions"], input[name*="dimension" i], select[name="dimensions"], select[name*="dimension" i], textarea[name*="dimension" i]');
            if (dimensionControl) {
                customization.dimensions = String(dimensionControl.value || '').trim();
                const isCustomActive = !!(visibleCustom && visibleCustomHeight);
                const activePreset = body.querySelector('.shopee-opt-btn.active[data-width][data-height]');
                const pair = activePreset
                    ? { width: activePreset.dataset.width, height: activePreset.dataset.height }
                    : parsePair(dimensionControl.value);
                if (!isCustomActive && pair) {
                    customization.width = pair.width;
                    customization.height = pair.height;
                }
            }
            const joiningDirection = body.querySelector('[name="joining_direction"]');
            const allowanceMode = body.querySelector('[name="joining_allowance_mode"]');
            const allowance = body.querySelector('[name="joining_allowance_ft"]');
            customization.joining_direction = joiningDirection ? joiningDirection.value : 'automatic';
            customization.joining_allowance_mode = allowanceMode ? allowanceMode.value : 'included';
            customization.joining_allowance_ft = allowance ? allowance.value : '0';
            const quantity = posServiceQuantityInput();
            if (quantity) customization.quantity = quantity.value;
            return customization;
        }

        function posEnsureTarpaulinJoiningControls(body) {
            if (!body || body.querySelector('.pos-tarpaulin-joining-controls')) return;
            const wrap = document.createElement('div');
            wrap.className = 'pos-tarpaulin-joining-controls';
            wrap.style.cssText = 'display:grid;gap:10px;margin:12px 0;padding:12px;border:1px solid #e2e8f0;border-radius:10px;background:#f8fafc';
            wrap.innerHTML = '<label style="display:grid;gap:5px;font-size:12px;font-weight:700;color:#334155">Joining Direction <select name="joining_direction" class="input-field"><option value="automatic">Automatic</option><option value="vertical">Vertical seams</option><option value="horizontal">Horizontal seams</option></select></label>'
                + '<label style="display:grid;gap:5px;font-size:12px;font-weight:700;color:#334155">Joining Allowance <select name="joining_allowance_mode" class="input-field"><option value="included">Included in requested size</option><option value="additional">Additional allowance</option></select></label>'
                + '<label data-tarp-allowance-wrap style="display:none;gap:5px;font-size:12px;font-weight:700;color:#334155">Additional Allowance (ft) <input name="joining_allowance_ft" type="number" min="0" step="any" value="0" class="input-field"><small style="font-weight:400">Total per finished Tarpaulin, not per seam.</small></label>';
            const firstQuantity = body.querySelector('.quantity-container, input[name="quantity"]')?.closest('.shopee-form-row');
            body.insertBefore(wrap, firstQuantity || null);
            const mode = wrap.querySelector('[name="joining_allowance_mode"]');
            const allowanceWrap = wrap.querySelector('[data-tarp-allowance-wrap]');
            const syncAllowance = () => { allowanceWrap.style.display = mode.value === 'additional' ? 'grid' : 'none'; };
            mode.addEventListener('change', syncAllowance);
            syncAllowance();
        }

        async function posValidateServiceStockNow(serviceId, quantityRaw) {
            const requestVersion = ++posServiceStockRequestVersion;
            const input = posServiceQuantityInput();
            const qty = posReadPositiveWholeQuantity(quantityRaw);
            if (input) input.setCustomValidity(qty === null ? 'Enter a positive whole number.' : '');
            if (qty === null) {
                posSetServiceStockState(true, '', 'Enter a positive whole number.');
                return { valid: false, message: 'Enter a positive whole number.' };
            }
            if (Number(serviceId) === 40) {
                const dims = posCollectTarpaulinStockCustomization();
                const width = Number(dims.width);
                const height = Number(dims.height);
                const allowance = Number(dims.joining_allowance_ft);
                const validDim = (value) => Number.isFinite(value) && value > 0 && value <= 100;
                if (!validDim(width) || !validDim(height)) {
                    const message = !validDim(width) ? 'Width must be greater than 0 and at most 100 feet.' : 'Height must be greater than 0 and at most 100 feet.';
                    posSetServiceStockState(true, '', message);
                    return { valid: false, message };
                }
                if (dims.joining_allowance_mode === 'additional' && (!Number.isFinite(allowance) || allowance < 0)) {
                    const message = 'Additional allowance must be a finite nonnegative number of feet.';
                    posSetServiceStockState(true, '', message);
                    return { valid: false, message };
                }
            }

            posSetServiceStockState(true, 'Checking current material stock…', '');
            try {
                const response = await fetchWithTimeout(staffUrl('staff/api/pos_cart_handler.php'), {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
                    body: JSON.stringify({
                        action: 'validate_service_stock',
                        service_id: Number(serviceId),
                        qty,
                        ...(Number(serviceId) === 40 ? { customization: posCollectTarpaulinStockCustomization() } : {}),
                        csrf_token: POS_CSRF_TOKEN
                    })
                }, 12000);
                const data = await response.json().catch(() => ({}));
                if (requestVersion !== posServiceStockRequestVersion) return { valid: false, stale: true };
                if (!response.ok || data.success === false) {
                    const message = data.message || 'Material stock could not be verified. Try again.';
                    posSetServiceStockState(true, '', message);
                    return { valid: false, message };
                }
                const validation = data.stock_validation || {};
                if (!validation.tracked) {
                    posSetServiceStockState(false, '', '');
                    return { valid: true, tracked: false };
                }
                const materials = Array.isArray(validation.materials) ? validation.materials : [];
                const formatQty = value => String(Number(value || 0));
                const indicator = [40, 63].includes(Number(serviceId)) ? '' : materials.map(material => `${material.name}: ${formatQty(material.available)} ${material.uom || 'units'} available · ${formatQty(material.required)} required`).join(' | ');
                const issues = Array.isArray(validation.issues) ? validation.issues : [];
                if (!validation.ok || issues.length) {
                    const issue = issues[0] || {};
                    const message = `Insufficient ${issue.name || 'material'} stock. Available: ${formatQty(issue.available)} ${issue.uom || 'units'}. Required: ${formatQty(issue.required)} ${issue.uom || 'units'}.`;
                    posSetServiceStockState(true, indicator, message);
                    return { valid: false, message };
                }
                posSetServiceStockState(false, indicator, '');
                return { valid: true, tracked: true };
            } catch (error) {
                if (requestVersion !== posServiceStockRequestVersion) return { valid: false, stale: true };
                const message = 'Material stock could not be verified. Try again.';
                posSetServiceStockState(true, '', message);
                return { valid: false, message };
            }
        }

        function posBindServiceStockValidation(serviceId) {
            const input = posServiceQuantityInput();
            const body = document.getElementById('sm-fields-body');
            if (input) {
                input.min = '1';
                input.step = '1';
                input.addEventListener('input', () => {
                    if (posServiceStockRequestTimer) clearTimeout(posServiceStockRequestTimer);
                    if (Number(serviceId) === 40) ++posServiceStockRequestVersion;
                    posServiceStockBlocking = true;
                    setServiceAddButtonBusy(isAddingToOrder);
                    const feedback = document.getElementById('pos-service-stock-feedback');
                    const warning = document.getElementById('pos-service-stock-warning');
                    const indicator = document.getElementById('pos-service-stock-indicator');
                    if (feedback) feedback.style.display = 'block';
                    if (indicator) indicator.textContent = 'Checking current material stock…';
                    if (warning) warning.textContent = '';
                    posServiceStockRequestTimer = setTimeout(() => {
                        posValidateServiceStockNow(serviceId, input.value);
                    }, 180);
                });
                input.addEventListener('change', () => posValidateServiceStockNow(serviceId, input.value));

                // The service quantity +/- controls update the input value from
                // inline handlers and do not dispatch input/change events.
                // Revalidate after those handlers have finished updating it.
                const quantityControls = input.closest('.quantity-container');
                if (quantityControls) {
                    quantityControls.querySelectorAll('button').forEach(button => {
                        button.addEventListener('click', () => {
                            setTimeout(() => {
                                if (document.getElementById('sm-fields-body')?.contains(input)) {
                                    if (Number(serviceId) === 40) ++posServiceStockRequestVersion;
                                    posValidateServiceStockNow(serviceId, input.value);
                                }
                            }, 0);
                        });
                    });
                }
            }
            if (Number(serviceId) === 40 && body) {
                const revalidateDimensions = (event) => {
                    const target = event.target;
                    const dimensionField = target && typeof target.matches === 'function'
                        && target.matches('[data-dimension-role="width"], [data-dimension-role="height"], .custom-dim-width, .custom-dim-height, input[name="dimensions"], input[name*="dimension" i], textarea[name*="dimension" i], [name="joining_allowance_ft"]');
                    const dimensionSelect = event.type === 'change' && target && typeof target.matches === 'function'
                        && target.matches('select.pf-select-with-others, select.pf-select-custom-size, select[name="dimensions"], select[name*="dimension" i], [name="joining_direction"], [name="joining_allowance_mode"]');
                    const dimensionChoice = dimensionSelect || (target && typeof target.closest === 'function'
                        && target.closest('.shopee-opt-btn[data-dimension-choice], .shopee-opt-btn[data-dimension-others]'));
                    if (!dimensionField && !dimensionChoice) return;
                    if (posServiceStockRequestTimer) clearTimeout(posServiceStockRequestTimer);
                    ++posServiceStockRequestVersion;
                    posServiceStockBlocking = true;
                    setServiceAddButtonBusy(isAddingToOrder);
                    const feedback = document.getElementById('pos-service-stock-feedback');
                    const indicator = document.getElementById('pos-service-stock-indicator');
                    const warning = document.getElementById('pos-service-stock-warning');
                    if (feedback) feedback.style.display = 'block';
                    if (indicator) indicator.textContent = 'Checking current material stock…';
                    if (warning) warning.textContent = '';
                    posServiceStockRequestTimer = setTimeout(() => {
                        const currentQty = posServiceQuantityInput();
                        posValidateServiceStockNow(serviceId, currentQty ? currentQty.value : '');
                    }, 180);
                };
                body.addEventListener('input', revalidateDimensions);
                body.addEventListener('change', revalidateDimensions);
                body.addEventListener('click', revalidateDimensions);
            }
            const pricing = resolveServiceModalPricing();
            const quantity = input ? input.value : pricing.quantity;
            posValidateServiceStockNow(serviceId, quantity);
        }

        function pfLayoutOptionCanonical(value) {
            const raw = String(value || '').trim().toLowerCase();
            if (!raw) return null;
            const slug = raw.replace(/[\s-]+/g, '_').replace(/_+/g, '_');
            if (slug === 'without_layout' || slug === 'withoutlayout' || raw === 'without layout') return 'without_layout';
            if (slug === 'with_layout' || slug === 'withlayout' || raw === 'with layout') return 'with_layout';
            if (slug.includes('without') && slug.includes('layout')) return 'without_layout';
            if (slug.includes('with') && slug.includes('layout')) return 'with_layout';
            return null;
        }

        function normalizeLayoutCustomizationValue(row, value) {
            if (!row) return value;
            const key = String(serviceFieldKey(row) || '').toLowerCase();
            const label = serviceFieldLabel(row).toLowerCase();
            if (key.includes('layout') || label === 'layout') {
                const canon = pfLayoutOptionCanonical(value);
                if (canon) return canon;
            }
            return value;
        }

        function isServiceFieldVisible(row) {
            if (!row || row.hidden) return false;
            const style = window.getComputedStyle(row);
            if (style.display === 'none' || style.visibility === 'hidden') return false;
            const hiddenParent = row.parentElement ? row.parentElement.closest('[style*="display:none"], [style*="display: none"]') : null;
            return !hiddenParent;
        }

        function isServiceFieldActive(row) {
            if (!isServiceFieldVisible(row)) return false;
            if (row.dataset.pfFieldInactive === '1') return false;
            if (row.classList.contains('pf-field-conditionally-disabled')) return false;
            return true;
        }

        function isServiceUploadGroupRequired(group) {
            if (!group || group.getAttribute('data-pf-required') !== '1') return false;
            const row = group.closest('.shopee-form-row');
            if (!isServiceFieldActive(row)) return false;
            if (group.dataset.pfConditionallyRequired === '0') return false;
            return true;
        }

        function serviceFieldLabel(row) {
            const label = row ? row.querySelector('.shopee-form-label') : null;
            return (label ? label.innerText : 'This field').replace(/\*/g, '').trim() || 'This field';
        }

        function serviceFieldKey(row, fallback = '') {
            if (!row) return fallback || 'field';
            if (row.dataset.fieldKey) return row.dataset.fieldKey;
            const named = row.querySelector('[name]');
            return named ? named.name : (fallback || serviceFieldLabel(row).toLowerCase().replace(/[^a-z0-9]+/g, '_'));
        }

        function posServiceFieldIsNeededDate(fieldName, row) {
            const name = String(fieldName || '').toLowerCase();
            if (name.includes('needed_date')) {
                return true;
            }
            if (row && serviceFieldLabel(row).toLowerCase().includes('needed date')) {
                return true;
            }
            return false;
        }

        function serviceValidationMessage(row, input) {
            const label = serviceFieldLabel(row);
            const name = String((input && input.name) || serviceFieldKey(row) || label).toLowerCase();
            const type = input ? String(input.type || '').toLowerCase() : '';
            if (row && row.querySelector('.pf-file-upload-group')) {
                return 'Please upload a design or paste a design link.';
            }
            if (name.includes('design') || type === 'file') return 'Please upload a design or paste a design link.';
            if (name.includes('layout') || label.toLowerCase().includes('layout')) return 'Please select a layout.';
            if (name.includes('needed_date') || label.toLowerCase().includes('needed date')) return 'Please select a needed date.';
            if (name.includes('quantity') || label.toLowerCase().includes('quantity')) return 'Quantity must be at least 1.';
            if (type === 'radio' || row.querySelector('input[type="radio"]')) return 'Please select ' + label.toLowerCase() + '.';
            if (input && input.tagName === 'SELECT') return 'Please select ' + label.toLowerCase() + '.';
            return 'Please enter ' + label.toLowerCase() + '.';
        }

        function clearServiceFieldError(row) {
            if (!row) return;
            row.classList.remove('field-invalid');
            row.querySelectorAll('.field-invalid').forEach(el => el.classList.remove('field-invalid'));
            row.querySelectorAll('.field-error-message').forEach(el => el.remove());
        }

        function clearAllServiceValidationErrors() {
            const body = document.getElementById('sm-fields-body');
            if (!body) return;
            body.querySelectorAll('.field-invalid').forEach(el => el.classList.remove('field-invalid'));
            body.querySelectorAll('.field-error-message').forEach(el => el.remove());
        }

        function markServiceFieldInvalid(row, message) {
            if (!row) return;
            clearServiceFieldError(row);
            row.classList.add('field-invalid');
            const targetGroup = row.querySelector('.shopee-opt-group') || row.querySelector('.quantity-container');
            const targetControl = row.querySelector('input:not([type="hidden"]), select, textarea');
            if (targetGroup) targetGroup.classList.add('field-invalid');
            if (targetControl) targetControl.classList.add('field-invalid');
            const field = row.querySelector('.shopee-form-field') || row;
            const msg = document.createElement('div');
            msg.className = 'field-error-message';
            msg.textContent = message;
            field.appendChild(msg);
        }

        function showValidationErrors(errors) {
            clearAllServiceValidationErrors();
            Object.values(errors || {}).forEach(error => {
                if (error && error.row) markServiceFieldInvalid(error.row, error.message);
            });
        }

        function serviceSelectorEscape(value) {
            if (window.CSS && typeof window.CSS.escape === 'function') return window.CSS.escape(String(value));
            return String(value).replace(/["\\]/g, '\\$&');
        }

        function showBackendValidationErrors(errors) {
            const body = document.getElementById('sm-fields-body');
            if (!body || !errors) return;
            clearAllServiceValidationErrors();
            Object.entries(errors).forEach(([key, message]) => {
                const keyLc = String(key || '').toLowerCase();
                const escapedKey = serviceSelectorEscape(key);
                let row = body.querySelector('.shopee-form-row[data-field-key="' + escapedKey + '"]');
                if (!row) {
                    const input = body.querySelector('[name="' + escapedKey + '"]');
                    row = input ? input.closest('.shopee-form-row') : null;
                }
                if (!row) {
                    row = Array.from(body.querySelectorAll('.shopee-form-row')).find(candidate => {
                        return serviceFieldLabel(candidate).toLowerCase().replace(/[^a-z0-9]+/g, '_').includes(keyLc);
                    }) || null;
                }
                if (row) markServiceFieldInvalid(row, message);
            });
        }

        function focusFirstInvalidField(errors) {
            const first = Object.values(errors || {}).find(error => error && error.row);
            if (!first) return;
            first.row.scrollIntoView({ behavior: 'smooth', block: 'center' });
            const focusable = first.row.querySelector('input:not([type="hidden"]):not([disabled]), select:not([disabled]), textarea:not([disabled]), button:not([disabled]), .shopee-opt-btn');
            if (focusable && typeof focusable.focus === 'function') {
                setTimeout(() => focusable.focus({ preventScroll: true }), 180);
            }
        }

        function bindServiceValidationClearers(container) {
            if (!container) return;
            container.querySelectorAll('input, select, textarea, button[data-dimension-choice], button[data-dimension-others]').forEach(el => {
                ['input', 'change', 'click'].forEach(evt => {
                    el.addEventListener(evt, function () {
                        const row = this.closest('.shopee-form-row');
                        if (row) clearServiceFieldError(row);
                    });
                });
            });
        }

        function isPublicDesignLinkHost(hostname) {
            const host = String(hostname || '').replace(/^\[|\]$/g, '').toLowerCase();
            if (!host || host === 'localhost' || host === '0.0.0.0' || host === '::1') return false;
            if (host.endsWith('.localhost') || host.endsWith('.local') || host.endsWith('.internal')) return false;
            if (host.startsWith('127.') || host.startsWith('10.') || host.startsWith('192.168.') || host.startsWith('169.254.')) return false;
            if (/^172\.(1[6-9]|2\d|3[0-1])\./.test(host)) return false;
            return true;
        }

        function isValidDesignLink(value) {
            const text = String(value || '').trim();
            if (!text) return true;
            if (/^(javascript|data|file|vbscript):/i.test(text)) return false;
            try {
                const parsed = new URL(text);
                return parsed.protocol === 'https:' && isPublicDesignLinkHost(parsed.hostname);
            } catch (err) {
                return false;
            }
        }

        function posStoreDesignLink(customization, row, linkInput) {
            const linkValue = String(linkInput && linkInput.value || '').trim();
            if (!linkValue) return;
            const fieldKey = serviceFieldKey(row, '');
            const linkName = String(linkInput && linkInput.name || '').trim() || (fieldKey ? fieldKey + '_link' : 'design_link');
            customization[linkName] = linkValue;
            if (fieldKey) {
                customization[fieldKey + '_link'] = linkValue;
                if (customization[fieldKey] === 'link' || customization[fieldKey] === 'file' || customization[fieldKey] === linkValue) {
                    delete customization[fieldKey];
                }
            }
            customization.design_external_link = linkValue;
            const labelEl = row ? row.querySelector('.pf-field-label-text') : null;
            const cleanLabel = String(labelEl ? labelEl.textContent : '').replace(/\*/g, '').replace(/\s+/g, ' ').trim();
            if (cleanLabel) {
                customization[cleanLabel + ' Link'] = linkValue;
            }
        }

        function validateServiceOrderForm() {
            if (typeof updateConditionalFields === 'function') {
                updateConditionalFields();
            }
            const body = document.getElementById('sm-fields-body');
            const errors = {};
            if (!body) return { valid: false, errors };
            if (typeof pfPrepareServiceFormDimensions === 'function') {
                pfPrepareServiceFormDimensions(body);
            }

            const addError = (row, input, keyOverride, messageOverride) => {
                const key = keyOverride || serviceFieldKey(row, input ? input.name : '');
                if (!errors[key]) errors[key] = { row, field: key, message: messageOverride || serviceValidationMessage(row, input) };
            };

            body.querySelectorAll('.shopee-form-row').forEach(row => {
                if (!isServiceFieldActive(row)) return;
                const requiredInputs = Array.from(row.querySelectorAll('[required]')).filter(input => {
                    if (input.disabled) return false;
                    const hiddenParent = input.closest('[style*="display:none"], [style*="display: none"]');
                    return !hiddenParent;
                });
                if (requiredInputs.length === 0) return;

                const radiosByName = new Map();
                requiredInputs.forEach(input => {
                    if (input.type === 'radio') {
                        if (!radiosByName.has(input.name)) radiosByName.set(input.name, []);
                        radiosByName.get(input.name).push(input);
                    }
                });
                radiosByName.forEach((radios, name) => {
                    const checked = radios.find(radio => radio.checked);
                    if (!checked) {
                        addError(row, radios[0], name);
                        return;
                    }
                    if (checked.value === 'Others') {
                        const otherInput = row.querySelector('input[name="' + name + '_other"]');
                        if (otherInput && !String(otherInput.value || '').trim()) {
                            addError(row, otherInput, name + '_other');
                        }
                    }
                });

                const dimensionInputs = requiredInputs.filter(input => input.type === 'hidden' && input.dataset.dimensionRole);
                if (dimensionInputs.length) {
                    const widthHidden = dimensionInputs.find(input => input.dataset.dimensionRole === 'width') || dimensionInputs[0];
                    const heightHidden = dimensionInputs.find(input => input.dataset.dimensionRole === 'height') || dimensionInputs[1] || dimensionInputs[0];
                    const customPanel = row.querySelector('.pf-custom-size-panel');
                    const usesCustomPanel = customPanel
                        && typeof pfIsCustomSizePanelVisible === 'function'
                        && pfIsCustomSizePanelVisible(customPanel);
                    if (usesCustomPanel && typeof pfValidateCustomSizePanel === 'function') {
                        const dimCheck = pfValidateCustomSizePanel(customPanel, false);
                        if (!dimCheck.ok) {
                            addError(row, customPanel.querySelector('.custom-dim-width') || widthHidden, row.dataset.fieldKey || widthHidden.name, dimCheck.message || 'Please enter dimensions.');
                        }
                    } else if (!pfDimensionPairIsValid(widthHidden ? widthHidden.value : '', heightHidden ? heightHidden.value : '')) {
                        addError(row, widthHidden || heightHidden, row.dataset.fieldKey || (widthHidden && widthHidden.name) || 'dimensions', 'Please enter dimensions.');
                    }
                }

                requiredInputs.forEach(input => {
                    if (input.type === 'radio' || (input.type === 'hidden' && input.dataset.dimensionRole)) return;
                    const value = input.type === 'file'
                        ? (input.files && input.files.length > 0 ? input.files[0].name : '')
                        : String(input.value || '').trim();
                    if (input.tagName === 'SELECT') {
                        const otherValue = input.getAttribute('data-other-option') || 'Others';
                        if (value === otherValue) {
                            const othersWrap = document.getElementById('select-others-' + input.name)
                                || row.querySelector('#select-others-' + input.name);
                            const customPanel = othersWrap ? othersWrap.querySelector('.pf-custom-size-panel') : null;
                            if (customPanel) {
                                if (typeof pfSyncSelectCustomSizeHidden === 'function') {
                                    pfSyncSelectCustomSizeHidden(input);
                                }
                                const dimCheck = typeof pfValidateCustomSizePanel === 'function'
                                    ? pfValidateCustomSizePanel(customPanel, false)
                                    : { ok: true };
                                if (!dimCheck.ok) {
                                    addError(row, customPanel.querySelector('.custom-dim-width') || input, input.name + '_dimensions', dimCheck.message || 'Please enter dimensions.');
                                }
                            } else {
                                const rowOtherInput = row.querySelector('input[name="' + input.name + '_other"]');
                                if (rowOtherInput && !String(rowOtherInput.value || '').trim()) {
                                    addError(row, rowOtherInput, input.name + '_other');
                                }
                            }
                        }
                    }
                    if (input.name === 'quantity' || input.classList.contains('pf-service-quantity-input')) {
                        const qty = parseInt(value, 10);
                        if (!Number.isFinite(qty) || qty < 1) addError(row, input);
                        return;
                    }
                    if (input.type === 'date' && value && posServiceFieldIsNeededDate(input.name, row)) {
                        const today = new Date().toISOString().split('T')[0];
                        if (value < today) {
                            addError(row, input, input.name, 'Needed date cannot be in the past.');
                            return;
                        }
                    }
                    if (!value) addError(row, input);
                });
            });

            body.querySelectorAll('.pf-file-upload-group[data-pf-required="1"]').forEach(group => {
                if (!isServiceUploadGroupRequired(group)) return;
                const row = group.closest('.shopee-form-row');
                const modeSelect = group.querySelector('.pf-design-mode-select');
                const designMode = String(
                    (modeSelect && modeSelect.value) || group.dataset.pfDesignMode || ''
                ).trim();
                const fileInput = group.querySelector('.pf-design-file-input');
                const linkInput = group.querySelector('.pf-design-link-input');
                const queueFiles = (typeof window.pfDesignQueueValidFiles === 'function')
                    ? window.pfDesignQueueValidFiles(group)
                    : [];
                const hasFile = queueFiles.length > 0 || !!(fileInput && fileInput.files && fileInput.files.length > 0);
                const hasInvalid = (typeof window.pfGetDesignUploadQueue === 'function')
                    ? window.pfGetDesignUploadQueue(group).some(entry => !entry.valid)
                    : false;
                if (hasInvalid) {
                    addError(row, fileInput || group, null, 'Fix invalid design files before continuing.');
                    return;
                }
                const linkValue = linkInput ? String(linkInput.value || '').trim() : '';
                const linkFieldKey = (linkInput && linkInput.name) ? linkInput.name : 'design_link';

                if (designMode === 'file') {
                    if (!hasFile) {
                        addError(row, fileInput || group);
                    }
                    return;
                }
                if (designMode === 'link') {
                    if (!linkValue) {
                        addError(row, linkInput || group, linkFieldKey);
                        return;
                    }
                    if (!isValidDesignLink(linkValue)) {
                        addError(row, linkInput || group, linkFieldKey, 'Please enter a valid HTTPS design link.');
                    }
                    return;
                }

                if (!hasFile && !linkValue) {
                    addError(row, fileInput || linkInput || group);
                    return;
                }
                if (linkValue && !hasFile && !isValidDesignLink(linkValue)) {
                    addError(row, linkInput || group, linkFieldKey, 'Please enter a valid HTTPS design link.');
                }
            });

            return { valid: Object.keys(errors).length === 0, errors };
        }

        function setCustomizationValue(customization, row, input, value) {
            const key = serviceFieldKey(row, input ? input.name : '');
            if (key) customization[key] = value;
        }

        async function confirmServiceModal() {
            if (isAddingToOrder) return;
            const overlay = document.getElementById('service-modal-overlay');
            const serviceId = parseInt(overlay.dataset.serviceId);
            const serviceName = overlay.dataset.serviceName;
            const body = document.getElementById('sm-fields-body');

            const validationResult = validateServiceOrderForm();
            if (!validationResult.valid) {
                showValidationErrors(validationResult.errors);
                focusFirstInvalidField(validationResult.errors);
                return;
            }
            const serviceQuantityInput = posServiceQuantityInput();
            const serviceStockCheck = await posValidateServiceStockNow(
                serviceId,
                serviceQuantityInput ? serviceQuantityInput.value : resolveServiceModalPricing().quantity
            );
            if (!serviceStockCheck.valid) {
                if (serviceQuantityInput && !serviceQuantityInput.checkValidity()) serviceQuantityInput.reportValidity();
                return;
            }
            if (typeof window.pfValidateDesignUploadGroups === 'function') {
                const designValidation = window.pfValidateDesignUploadGroups(body);
                if (!designValidation.ok) {
                    await showPOSAlert('Upload Design', designValidation.errors[0] || 'Please fix your design uploads.', 'warning');
                    return;
                }
            }
            body.querySelectorAll('.pf-file-upload-group').forEach((group) => {
                const row = group.closest('.shopee-form-row');
                if (row && row.dataset.pfFieldInactive === '1') {
                    if (typeof window.pfClearDesignUploadGroup === 'function') {
                        window.pfClearDesignUploadGroup(group);
                    }
                    return;
                }
                if (typeof window.pfSyncDesignQueueToFileInput === 'function') {
                    window.pfSyncDesignQueueToFileInput(group);
                }
            });
            if (typeof pfPrepareServiceFormDimensions === 'function') {
                pfPrepareServiceFormDimensions(body);
            }

            isAddingToOrder = true;
            setServiceAddButtonBusy(true);

            const customization = {};
            const branchHidden = body.querySelector('input[type="hidden"][name="branch_id"]');
            const branchSel = body.querySelector('select[name="branch_id"]');
            const branchVal = (branchHidden && branchHidden.value) ? branchHidden.value : (branchSel ? branchSel.value : '');
            if (!branchVal) {
                await showPOSAlert('Branch Required', 'Please select a branch.', 'warning');
                if (branchSel) branchSel.focus();
                isAddingToOrder = false;
                setServiceAddButtonBusy(false);
                return;
            }
            customization.branch_id = branchVal;
            customization.service_id = serviceId;
            customization.service_type = serviceName;
            if (Number(serviceId) === 40) Object.assign(customization, posCollectTarpaulinStockCustomization());

            body.querySelectorAll('.shopee-form-row').forEach(row => {
                if (!isServiceFieldActive(row)) return;

                const checkedRadio = row.querySelector('input[type="radio"]:checked');
                if (checkedRadio) {
                    let radioValue = normalizeLayoutCustomizationValue(row, checkedRadio.value);
                    if (radioValue === 'Others') {
                        const radioOther = row.querySelector('input[name="' + checkedRadio.name + '_other"]');
                        if (radioOther && radioOther.value.trim()) {
                            radioValue = radioOther.value.trim();
                            customization[serviceFieldLabel(row) + ' (Other)'] = radioValue;
                        }
                    }
                    setCustomizationValue(customization, row, checkedRadio, radioValue);
                }

                const sel = row.querySelector('select:not([name="branch_id"])');
                if (sel && sel.value) {
                    let selectValue = normalizeLayoutCustomizationValue(row, sel.value);
                    const otherValue = sel.getAttribute('data-other-option') || 'Others';
                    if (selectValue === otherValue) {
                        const othersWrap = document.getElementById('select-others-' + sel.name)
                            || row.querySelector('#select-others-' + sel.name);
                        const customPanel = othersWrap ? othersWrap.querySelector('.pf-custom-size-panel') : null;
                        if (customPanel && typeof pfSyncSelectCustomSizeHidden === 'function') {
                            pfSyncSelectCustomSizeHidden(sel);
                            const widthHidden = othersWrap.querySelector('[data-dimension-role="width"]');
                            const heightHidden = othersWrap.querySelector('[data-dimension-role="height"]');
                            const widthVal = String(widthHidden ? widthHidden.value : '').trim();
                            const heightVal = String(heightHidden ? heightHidden.value : '').trim();
                            if (pfDimensionPairIsValid(widthVal, heightVal)) {
                                const fieldKey = serviceFieldKey(row, sel.name);
                                const combined = widthVal + 'x' + heightVal;
                                selectValue = combined;
                                customization.width = widthVal;
                                customization.height = heightVal;
                                if (fieldKey) {
                                    customization[fieldKey + '_width'] = widthVal;
                                    customization[fieldKey + '_height'] = heightVal;
                                }
                                const selectOther = othersWrap.querySelector('.pf-custom-size-other-hidden, input[name="' + sel.name + '_other"]');
                                if (selectOther && selectOther.value.trim()) {
                                    customization[serviceFieldLabel(row) + ' (Other)'] = selectOther.value.trim();
                                }
                            }
                        } else {
                            const selectOther = row.querySelector('input[name="' + sel.name + '_other"]');
                            if (selectOther && selectOther.value.trim()) {
                                selectValue = selectOther.value.trim();
                                customization[serviceFieldLabel(row) + ' (Other)'] = selectValue;
                            }
                        }
                    }
                    setCustomizationValue(customization, row, sel, selectValue);
                }

                const dateInput = row.querySelector('input[type="date"]');
                if (dateInput && dateInput.value) setCustomizationValue(customization, row, dateInput, dateInput.value);

                const qtyInput = row.querySelector('#quantity-input, .pf-service-quantity-input, input[name="quantity"]');
                if (qtyInput) customization.quantity = qtyInput.value || 1;

                const textarea = row.querySelector('textarea');
                if (textarea && textarea.value.trim()) setCustomizationValue(customization, row, textarea, textarea.value.trim());

                const uploadGroup = row.querySelector('.pf-file-upload-group');
                if (uploadGroup) {
                    const modeSelect = uploadGroup.querySelector('.pf-design-mode-select');
                    const designMode = String(
                        (modeSelect && modeSelect.value) || uploadGroup.dataset.pfDesignMode || ''
                    ).trim();
                    const fieldKey = serviceFieldKey(row, '');
                    if (designMode) {
                        customization.design_input_mode = designMode;
                        if (fieldKey) {
                            customization[fieldKey + '_design_input_mode'] = designMode;
                        }
                    }
                }

                const wh = row.querySelector('[data-dimension-role="width"], #width_hidden');
                const hh = row.querySelector('[data-dimension-role="height"], #height_hidden');
                if (wh && hh && wh.value && hh.value) {
                    const fieldKey = serviceFieldKey(row, wh.name || hh.name || '');
                    const widthVal = String(wh.value).trim();
                    const heightVal = String(hh.value).trim();
                    if (fieldKey) {
                        customization[fieldKey] = widthVal + 'x' + heightVal;
                        customization[fieldKey + '_width'] = widthVal;
                        customization[fieldKey + '_height'] = heightVal;
                    }
                    if (fieldKey === 'dimensions') {
                        customization.width = widthVal;
                        customization.height = heightVal;
                    }
                }

                const textInput = row.querySelector('input[type="text"]:not(.pf-service-quantity-input):not(.select-others-input):not(.radio-others-input), input[type="number"]:not(#quantity-input):not(.pf-service-quantity-input), input[type="url"].pf-design-link-input');
                if (textInput && !textInput.id.includes('hidden') && textInput.value.trim()) {
                    if (textInput.classList.contains('pf-design-link-input')) {
                        const linkUploadGroup = row.querySelector('.pf-file-upload-group');
                        const modeSelect = linkUploadGroup ? linkUploadGroup.querySelector('.pf-design-mode-select') : null;
                        const designMode = String(
                            (modeSelect && modeSelect.value) || (linkUploadGroup && linkUploadGroup.dataset.pfDesignMode) || ''
                        ).trim();
                        if (designMode === 'link') {
                            posStoreDesignLink(customization, row, textInput);
                        }
                    } else {
                        setCustomizationValue(customization, row, textInput, textInput.value.trim());
                    }
                }
            });

            try {
                posStripInactiveDesignUploads(body, customization);
                await posStageDesignUploadsFromModal(body, customization);
                posStripInactiveDesignUploads(body, customization);
                const serviceFileInputs = Array.from(body.querySelectorAll('input[type="file"]'));
                for (const fileInput of serviceFileInputs) {
                    if (fileInput.classList.contains('pf-design-file-input')) continue;
                    if (!(fileInput.files && fileInput.files.length > 0)) continue;
                    const row = fileInput.closest('.shopee-form-row');
                    if (row && row.dataset.pfFieldInactive === '1') continue;
                    const labelText = row ? serviceFieldLabel(row) : (fileInput.name || 'File');
                    const nameLc = String(fileInput.name || '').toLowerCase();
                    const labelLc = String(labelText || '').toLowerCase();
                    const isReferenceField = nameLc === 'reference_file' || nameLc.includes('reference') || (labelLc.includes('upload') && labelLc.includes('reference'));
                    if (isReferenceField) {
                        await posApplyStagedUploadMany(customization, fileInput.files, 'reference');
                        setCustomizationValue(customization, row, fileInput, customization.reference_upload_name);
                    } else {
                        const payload = await posReadFilePayload(fileInput.files[0]);
                        setCustomizationValue(customization, row, fileInput, payload.name);
                    }
                }
                posStripInactiveDesignUploads(body, customization);
            } catch (uploadErr) {
                await showPOSAlert('Upload Failed', uploadErr.message || 'Could not save the design file.', 'error');
                isAddingToOrder = false;
                setServiceAddButtonBusy(false);
                return;
            }

            const pricing = resolveServiceModalPricing();
            const priced = applyServicePricingToCustomization(customization, pricing.unitPrice, customization.quantity || pricing.quantity);

            body.querySelectorAll('input[type="radio"]:checked').forEach(radio => {
                const row = radio.closest('.shopee-form-row');
                if (!row || !isServiceFieldActive(row)) return;
                const radioValue = normalizeLayoutCustomizationValue(row, radio.value);
                setCustomizationValue(customization, row, radio, radioValue);
            });

            if (priced.unitPrice <= 0) {
                setServiceAddButtonBusy(false);
                const addBtn = document.getElementById('sm-add-to-order-btn');
                if (addBtn) addBtn.disabled = true;
                const proceed = await confirmMissingEstimatedPrice();
                if (!proceed) {
                    isAddingToOrder = false;
                    if (addBtn) addBtn.disabled = false;
                    return;
                }
                isAddingToOrder = true;
                setServiceAddButtonBusy(true);
            }

            const result = await syncedCartAction('add', {
                product_id: serviceId,
                name: serviceName,
                price: priced.unitPrice,
                qty: priced.quantity,
                customization: customization,
                is_service: true,
                price_set: false
            }, { silentErrors: true, fxSourceEl: posLastServiceCardEl });

            if (result.success) {
                closeServiceModal();
            } else {
                if (result.errors) {
                    showBackendValidationErrors(result.errors);
                    focusFirstInvalidField(Object.fromEntries(Object.entries(result.errors).map(([key, message]) => {
                        const body = document.getElementById('sm-fields-body');
                        const escapedKey = serviceSelectorEscape(key);
                        let row = null;
                        if (body) {
                            row = body.querySelector('.shopee-form-row[data-field-key="' + escapedKey + '"]');
                            if (!row) {
                                const named = body.querySelector('[name="' + escapedKey + '"]');
                                row = named && named.closest ? named.closest('.shopee-form-row') : null;
                            }
                        }
                        return [key, { row, message }];
                    })));
                }
                const errorDetail = result.errors
                    ? Object.values(result.errors).filter(Boolean).join('\n')
                    : '';
                await showPOSAlert(
                    'Incomplete Fields',
                    errorDetail || result.message || 'Some required order details are missing.',
                    'warning'
                );
                isAddingToOrder = false;
                setServiceAddButtonBusy(false);
            }
        }

        // ── Legacy service requirements (kept for product-based services) ─────────────
        const serviceRequirements = {
            'Tarpaulin': [
                getBranchField,
                { label: 'Dimensions (ft)', type: 'dimensions_ft', name: 'dimensions', subNames: ['width', 'height'], placeholders: ['Width', 'Height'], required: true },
                { label: 'Finish Type', type: 'select', name: 'finish', options: ['Matte', 'Glossy'] },
                { label: 'Lamination', type: 'select', name: 'lamination', options: ['With Laminate', 'Without Laminate'] },
                { label: 'Eyelets', type: 'select', name: 'with_eyelets', options: ['Yes', 'No'] },
                { label: 'Layout', type: 'select', name: 'layout', options: ['With Layout', 'Without Layout'] },
                { label: 'Quantity', type: 'number', name: 'quantity', placeholder: '1', step: '1', required: true },
                { label: 'Needed Date', type: 'date', name: 'needed_date', required: true },
                { label: 'Upload Design (JPG, PNG, PDF - max 5MB)', type: 'file', name: 'design_file', accept: '.jpg,.jpeg,.png,.pdf' }
            ],
            'T-Shirt': [
                getBranchField,
                { label: 'Shirt Source', type: 'select', name: 'shirt_source', options: ['Shop will provide the shirt', 'Customer will provide the shirt'], required: true },
                { label: 'Shirt Type', type: 'select', name: 'shirt_type', options: ['Crew Neck', 'V-Neck', 'Polo', 'Raglan', 'Long Sleeve', 'Others'] },
                { label: 'Shirt Type (if Others)', type: 'text', name: 'shirt_type_other', placeholder: 'Enter custom shirt type', conditionalOn: { field: 'shirt_type', value: 'Others' } },
                { label: 'Shirt Color', type: 'select', name: 'shirt_color', options: ['Black', 'White', 'Red', 'Blue', 'Navy', 'Grey', 'Other'] },
                { label: 'Color (if Other)', type: 'text', name: 'color_other', placeholder: 'Enter custom color', conditionalOn: { field: 'shirt_color', value: 'Other' } },
                { label: 'Sizes', type: 'select_other', name: 'sizes', options: ['XS', 'S', 'M', 'L', 'XL', 'XXL', 'XXXL', 'Others'], otherOption: 'Others', otherName: 'sizes_other', otherPlaceholder: 'Enter custom size', disabledWhen: { field: 'shirt_source', value: 'Customer will provide the shirt' } },
                { label: 'Print Placement', type: 'select', name: 'print_placement', options: ['Front Center Print', 'Back Upper Print', 'Left/Right Chest Print', 'Bottom Hem Print', 'Sleeve Print', 'Long Sleeve Arm Print'] },
                { label: 'Lamination', type: 'select', name: 'lamination', options: ['With Laminate', 'Without Laminate'] },
                { label: 'Quantity', type: 'number', name: 'quantity', placeholder: '1', step: '1', required: true },
                { label: 'Upload Design (JPG, PNG, PDF - max 5MB)', type: 'file', name: 'design_file', accept: '.jpg,.jpeg,.png,.pdf' }
            ],
            'Stickers': [
                getBranchField,
                { label: 'Dimensions (W × H, inches)', type: 'text', name: 'size', placeholder: 'e.g. 2x2', required: true },
                { label: 'Finish', type: 'select', name: 'finish', options: ['Glossy', 'Matte'] },
                { label: 'Laminate', type: 'select', name: 'laminate_option', options: ['With Laminate', 'Without Laminate'] },
                { label: 'Quantity', type: 'number', name: 'quantity', placeholder: '1', step: '1', required: true },
                { label: 'Needed Date', type: 'date', name: 'needed_date', required: true },
                { label: 'Upload Design (JPG, PNG, PDF - max 5MB)', type: 'file', name: 'design_file', accept: '.jpg,.jpeg,.png,.pdf' }
            ],
            'Decals / Stickers': [
                getBranchField,
                { label: 'Dimensions (W × H, inches)', type: 'text', name: 'size', placeholder: 'e.g. 2x2', required: true },
                { label: 'Finish', type: 'select', name: 'finish', options: ['Glossy', 'Matte'] },
                { label: 'Laminate', type: 'select', name: 'laminate_option', options: ['With Laminate', 'Without Laminate'] },
                { label: 'Quantity', type: 'number', name: 'quantity', placeholder: '1', step: '1', required: true },
                { label: 'Needed Date', type: 'date', name: 'needed_date', required: true },
                { label: 'Upload Design (JPG, PNG, PDF - max 5MB)', type: 'file', name: 'design_file', accept: '.jpg,.jpeg,.png,.pdf' }
            ],
            'Glass/Wall': [
                getBranchField,
                { label: 'Dimensions (ft)', type: 'dimensions_ft', name: 'dimensions', subNames: ['width', 'height'], placeholders: ['Width', 'Height'], required: true },
                { label: 'Surface Type', type: 'select', name: 'surface_type', options: ['Glass (Window/Door/Storefront)', 'Wall (Painted/Concrete)', 'Frosted Glass', 'Mirror', 'Acrylic/Panel', 'Others'] },
                { label: 'Surface Type (if Others)', type: 'text', name: 'surface_type_other', placeholder: 'Specify surface type', conditionalOn: { field: 'surface_type', value: 'Others' } },
                { label: 'Lamination', type: 'select', name: 'lamination', options: ['With Laminate', 'Without Laminate'] },
                { label: 'Installation', type: 'select', name: 'installation', options: ['Without Installation', 'With Installation'] },
                { label: 'Quantity', type: 'number', name: 'quantity', placeholder: '1', step: '1', required: true },
                { label: 'Needed Date', type: 'date', name: 'needed_date', required: true },
                { label: 'Upload Design (JPG, PNG, PDF - max 5MB)', type: 'file', name: 'design_file', accept: '.jpg,.jpeg,.png,.pdf' }
            ],
            'Transparent Stickers': [
                getBranchField,
                { label: 'Surface Application', type: 'select', name: 'surface_application', options: ['Glass (Window/Door/Storefront)', 'Plastic / Acrylic', 'Metal', 'Smooth Painted Wall', 'Mirror', 'Others'] },
                { label: 'Surface (if Others)', type: 'text', name: 'surface_other', placeholder: 'Specify surface', conditionalOn: { field: 'surface_application', value: 'Others' } },
                { label: 'Dimensions (e.g. 2x2, 3x4 ft)', type: 'text', name: 'dimensions', placeholder: 'e.g. 2x2', required: true },
                { label: 'Layout', type: 'select', name: 'layout', options: ['With Layout', 'Without Layout'] },
                { label: 'Lamination', type: 'select', name: 'lamination', options: ['With Laminate', 'Without Laminate'] },
                { label: 'Quantity', type: 'number', name: 'quantity', placeholder: '1', step: '1', required: true },
                { label: 'Needed Date', type: 'date', name: 'needed_date', required: true },
                { label: 'Upload Design (JPG, PNG, PDF - max 5MB)', type: 'file', name: 'design_file', accept: '.jpg,.jpeg,.png,.pdf' }
            ],
            'Reflectorized': {
                isDynamic: true,
                base: [
                    getBranchField,
                    { label: 'Product Type *', type: 'select', name: 'product_type', options: ['Subdivision / Gate Pass (Vehicle Sticker)', 'Plate Number / Temporary Plate', 'Custom Reflectorized Sign'], required: true, dynamicTrigger: true }
                ],
                'Subdivision / Gate Pass (Vehicle Sticker)': [
                    { label: 'Subdivision / Company Name *', type: 'text', name: 'gate_pass_subdivision', placeholder: 'GREEN VALLEY SUBDIVISION', required: true },
                    { label: 'Gate Pass Number *', type: 'text', name: 'gate_pass_number', placeholder: 'GP-0215', required: true },
                    { label: 'Plate Number *', type: 'text', name: 'gate_pass_plate', placeholder: 'ABC 1234', required: true },
                    { label: 'Year / Validity *', type: 'text', name: 'gate_pass_year', placeholder: 'VALID UNTIL: 2026', required: true },
                    { label: 'Vehicle Type', type: 'select', name: 'gate_pass_vehicle_type', options: [{ value: '', label: 'Select' }, { value: 'Car', label: 'Car' }, { value: 'Motorcycle', label: 'Motorcycle' }] },
                    { label: 'Exact Size (Width × Height)', type: 'text', name: 'dimensions', placeholder: 'e.g. 12 x 18' },
                    { label: 'Unit', type: 'select', name: 'unit', options: ['in', 'ft'] },
                    { label: 'Needed Date * (dd/mm/yyyy)', type: 'date', name: 'needed_date', required: true },
                    { label: 'Upload Design * (JPG, PNG, PDF - max 5MB)', type: 'file', name: 'design_file', accept: '.jpg,.jpeg,.png,.pdf' },
                    { label: 'Quantity Required *', type: 'number', name: 'quantity', placeholder: '1', step: '1', required: true }
                ],
                'Plate Number / Temporary Plate': [
                    { label: 'Material Selection *', type: 'select', name: 'temp_plate_material', options: ['Acrylic', 'Aluminum Sheet', 'Aluminum Coated (Steel)'], required: true },
                    { label: 'Plate Number * (must match OR/CR)', type: 'text', name: 'temp_plate_number', placeholder: 'Must match OR/CR', required: true },
                    { label: 'TEMPORARY PLATE text', type: 'text', name: 'temp_plate_text', placeholder: 'Auto-displayed on design', defaultValue: 'TEMPORARY PLATE' },
                    { label: 'MV File Number', type: 'text', name: 'mv_file_number', placeholder: 'Optional' },
                    { label: 'Dealer Name', type: 'text', name: 'dealer_name', placeholder: 'Optional' },
                    { label: 'Needed Date * (dd/mm/yyyy)', type: 'date', name: 'needed_date', required: true },
                    { label: 'Quantity Required *', type: 'number', name: 'quantity', placeholder: '1', step: '1', required: true }
                ],
                'Custom Reflectorized Sign': [
                    { label: 'Needed Date * (dd/mm/yyyy)', type: 'date', name: 'needed_date', required: true },
                    { label: 'Dimensions *', type: 'select_other', name: 'dimensions', options: ['6 x 12 in', '9 x 12 in', '12 x 18 in', '18 x 24 in', '24 x 36 in'], otherOption: 'Others', otherName: 'dimensions_other', otherPlaceholder: 'e.g. 10 x 14 in', required: true },
                    { label: 'Lamination', type: 'select', name: 'laminate_option', options: ['With Lamination', 'Without Lamination'] },
                    { label: 'Layout', type: 'select', name: 'layout', options: ['With Layout', 'Without Layout'] },
                    { label: 'Material Brand', type: 'select', name: 'material_type', options: ['Kiwalite (Japan Brand)', '3M Brand'] },
                    { label: 'Upload Design * (JPG, PNG, PDF - max 5MB)', type: 'file', name: 'design_file', accept: '.jpg,.jpeg,.png,.pdf' },
                    { label: 'Quantity Required *', type: 'number', name: 'quantity', placeholder: '1', step: '1', required: true }
                ]
            },
            'Sintraboard': [
                getBranchField,
                { label: 'Sintraboard Type', type: 'select', name: 'sintra_type', options: ['Flat Type', '2D Type (with Frame)', 'Standee (Back Stand Support)'], required: true },
                { label: 'Dimensions (e.g. 12 x 18)', type: 'text', name: 'dimensions', placeholder: 'e.g. 12 x 18', required: true },
                { label: 'Unit', type: 'select', name: 'unit', options: ['in', 'ft'] },
                { label: 'Thickness', type: 'select', name: 'thickness', options: ['3mm', '5mm'], required: true },
                { label: 'Lamination', type: 'select', name: 'lamination', options: ['With Lamination', 'Without Lamination'] },
                { label: 'Layout', type: 'select', name: 'layout', options: ['With Layout', 'Without Layout'] },
                { label: 'Quantity', type: 'number', name: 'quantity', placeholder: '1', step: '1', required: true },
                { label: 'Upload Design (JPG, PNG, PDF - max 5MB)', type: 'file', name: 'design_file', accept: '.jpg,.jpeg,.png,.pdf' }
            ],
            'Standees': [
                getBranchField,
                { label: 'Size', type: 'text', name: 'size', placeholder: 'e.g. 22x28 inches', required: true },
                { label: 'With Stand?', type: 'select', name: 'with_stand', options: ['No', 'Yes'] },
                { label: 'Needed Date', type: 'date', name: 'needed_date', required: true },
                { label: 'Quantity', type: 'number', name: 'quantity', placeholder: '1', step: '1', required: true },
                { label: 'Upload Design (JPG, PNG, PDF - max 5MB)', type: 'file', name: 'design_file', accept: '.jpg,.jpeg,.png,.pdf' }
            ],
            'Souvenirs': [
                getBranchField,
                { label: 'Type', type: 'select', name: 'souvenir_type', options: ['Mug', 'Keychain', 'Tote Bag', 'Pen', 'Tumbler', 'T-Shirt', 'Others'] },
                { label: 'Custom Print?', type: 'select', name: 'custom_print', options: ['No', 'Yes – I have a design'] },
                { label: 'Lamination', type: 'select', name: 'lamination', options: ['With Lamination', 'Without Lamination'] },
                { label: 'Needed Date', type: 'date', name: 'needed_date', required: true },
                { label: 'Quantity', type: 'number', name: 'quantity', placeholder: '1', step: '1', required: true },
                { label: 'Upload Design (JPG, PNG, PDF - max 5MB)', type: 'file', name: 'design_file', accept: '.jpg,.jpeg,.png,.pdf' }
            ]
        };

        function getRequirementsForProduct(productName, category) {
            const term = (productName + ' ' + (category || '')).toLowerCase();
            const svc = productName || category || '';
            if (term.includes('tarpaulin') || term.includes('tarp')) return expandRequirements('Tarpaulin');
            if (term.includes('t-shirt') || term.includes('tshirt')) return expandRequirements('T-Shirt');
            if (term.includes('sticker') || term.includes('decal') || svc === 'Decals / Stickers') return expandRequirements(svc === 'Decals / Stickers' ? 'Decals / Stickers' : 'Stickers');
            if (term.includes('glass') || term.includes('wall')) return expandRequirements('Glass/Wall');
            if (term.includes('transparent')) return expandRequirements('Transparent Stickers');
            if (term.includes('reflectorized') || term.includes('signage')) return expandRequirements('Reflectorized');
            if (term.includes('sintraboard') && !term.includes('standee')) return expandRequirements('Sintraboard');
            if (term.includes('standee')) return expandRequirements('Standees');
            if (term.includes('souvenir')) return expandRequirements('Souvenirs');
            return null;
        }
        function expandRequirements(key, productType) {
            const raw = serviceRequirements[key];
            if (!raw) return [];
            if (raw.isDynamic) {
                const base = (raw.base || []).map(r => typeof r === 'function' ? r() : r).filter(Boolean);
                if (productType && raw[productType]) {
                    return base.concat(raw[productType]);
                }
                return base;
            }
            const arr = Array.isArray(raw) ? raw : [];
            return arr.map(r => typeof r === 'function' ? r() : r).filter(Boolean);
        }

        function renderProducts() {
            const grid = document.getElementById('pos-products-grid');
            if (!grid) {
                console.error('Grid element not found!');
                return;
            }
            const searchEl = document.getElementById('pos-search');
            const catEl = document.getElementById('pos-category');
            const search = (searchEl ? searchEl.value : '').toLowerCase();
            const cat = catEl ? catEl.value : '';

            console.log('Rendering products. Total products:', products.length);

            grid.innerHTML = '';

            const filtered = products.filter(p => {
                const mSearch = p.product_name.toLowerCase().includes(search) || (p.sku && p.sku.toLowerCase().includes(search));
                const mCat = cat === '' || p.category === cat;
                return mSearch && mCat;
            });

            console.log('Filtered products:', filtered.length);
            if (filtered.length > 0) {
                console.log('Sample product:', filtered[0]);
            }

            if (filtered.length === 0) {
                grid.innerHTML = '<div style="grid-column:1/-1; text-align:center; padding:40px; color:#94a3b8;">No products found.</div>';
                return;
            }

            filtered.sort(function (a, b) {
                return String(a.product_name || '').localeCompare(String(b.product_name || ''), undefined, { sensitivity: 'base' });
            });

            filtered.forEach((p) => {
                const outOfStock = p.stock_quantity <= 0;
                const imgUrl = escapeHtml(posCatalogImageUrl(p));
                const defaultImg = escapeHtml(POS_DEFAULT_CATALOG_IMG);

                const card = document.createElement('button');
                card.type = 'button';
                card.dataset.productId = String(p.product_id);
                card.className = `pos-catalog-card pos-product-card pos-card ${outOfStock ? 'no-stock' : ''}`;
                const nameForTitle = String(p.product_name || 'Unnamed Product');
                card.title = nameForTitle;
                if (!outOfStock) {
                    card.onclick = async () => {
                        card.classList.add('is-selecting');
                        try {
                            await addToCart(p, null, null, { fxSourceEl: card });
                        } finally {
                            card.classList.remove('is-selecting');
                        }
                    };
                }

                const priceFormatted = formatMoney(p.price || 0);
                const productName = escapeHtml(p.product_name || 'Unnamed Product');
                const category = escapeHtml(p.category || 'Product');
                // Admin Products supplies the status badge; never derive stock from the cart.
                const stockIndicator = p.stock_display && p.stock_quantity != null
                    ? `<div class="pos-product-stock"><span>Stock: <strong>${escapeHtml(String(p.stock_quantity))}</strong></span>${p.stock_display.key === 'in' ? '' : p.stock_display.badge_html}</div>`
                    : '';

                card.innerHTML = `
            <div class="pos-catalog-card__media">
                <img src="${imgUrl}" alt="" loading="lazy" decoding="async"
                    onerror="this.onerror=null;this.src='${defaultImg}';">
            </div>
            <div class="pos-catalog-card__body">
                <span class="pos-catalog-card__meta">${category}</span>
                <p class="pos-catalog-card__name" title="${productName}">${productName}</p>
                <div class="pos-catalog-card__price" aria-label="Price">${priceFormatted}</div>
                ${stockIndicator}
            </div>
        `;
                grid.appendChild(card);
            });
        }

        function focusBarcodeInput(preferredInput = null) {
            const inputs = Array.from(document.querySelectorAll('.pos-barcode-entry'));
            const preferredIsVisible = preferredInput
                && !!(preferredInput.offsetWidth || preferredInput.offsetHeight || preferredInput.getClientRects().length);
            const input = (preferredIsVisible ? preferredInput : null) || inputs.find(function(el) {
                return !!(el.offsetWidth || el.offsetHeight || el.getClientRects().length);
            }) || inputs[0];
            if (!input) return;
            input.focus();
            input.select();
        }

        function clearBarcodeInputs() {
            document.querySelectorAll('.pos-barcode-entry').forEach(function(input) { input.value = ''; });
        }

        function showPOSScanNotice(title, message, type = 'warning') {
            const container = document.getElementById('pos-scan-toast-container');
            if (!container) return;
            const toast = document.createElement('div');
            const icon = type === 'success' ? 'fa-check-circle' : (type === 'error' ? 'fa-exclamation-circle' : 'fa-exclamation-triangle');
            toast.className = 'pos-scan-toast ' + type;
            toast.innerHTML = '<div class="pos-scan-toast-icon"><i class="fas ' + icon + '"></i></div>'
                + '<div><div class="pos-scan-toast-title"></div><div class="pos-scan-toast-message"></div></div>';
            toast.querySelector('.pos-scan-toast-title').textContent = title;
            toast.querySelector('.pos-scan-toast-message').innerHTML = String(message || '').replace(/\n/g, '<br>');
            container.appendChild(toast);
            requestAnimationFrame(function() { toast.classList.add('show'); });
            setTimeout(function() {
                toast.classList.remove('show');
                setTimeout(function() { toast.remove(); }, 220);
            }, type === 'success' ? 1800 : 3200);
        }

        function scannedCartQuantity(product) {
            const productId = String(product && product.product_id != null ? product.product_id : '');
            if (!productId) return 0;
            return cart.reduce(function(total, item) {
                if (String(item.product_id) !== productId || item.is_service) return total;
                return total + (parseInt(item.qty, 10) || 0);
            }, 0);
        }

        function finishBarcodeScan(input) {
            clearBarcodeInputs();
            focusBarcodeInput(input);
        }

        function isBarcodeTerminatorKey(key) {
            return key === 'Enter' || key === 'Tab' || key === '\r' || key === '\n';
        }

        function normalizeProductBarcode(code) {
            return String(code || '').replace(/[\u0000-\u001F\u007F\u200B-\u200D\u2060\uFEFF]+/g, '').trim();
        }

        function isProtectedPosBarcodeTarget(target) {
            if (!target || target === document.body) return false;
            if (target.closest && target.closest('.select2-container--open, .select2-dropdown, .select2-search__field')) return true;
            if (target.closest && target.closest('#customer-modal')) return true;
            if (target.closest && target.closest('.pos-barcode-entry')) return true;
            if (target.isContentEditable) return true;
            const tag = String(target.tagName || '').toLowerCase();
            return tag === 'input' || tag === 'textarea' || tag === 'select';
        }

        function installPosBarcodeKeyboardCapture() {
            if (window.__printflowPosProductScannerCaptureInstalled) return;
            window.__printflowPosProductScannerCaptureInstalled = true;
            let buffer = '';
            let startedAt = 0;
            let lastKeyAt = 0;
            const maxGapMs = 120;
            const maxScanMs = 2500;

            function resetBuffer() {
                buffer = '';
                startedAt = 0;
                lastKeyAt = 0;
            }

            document.addEventListener('keydown', function(event) {
                if (event.defaultPrevented || event.ctrlKey || event.altKey || event.metaKey) {
                    resetBuffer();
                    return;
                }
                if (event.target && event.target.closest && event.target.closest('.pos-barcode-entry')) {
                    resetBuffer();
                    return;
                }
                if (isProtectedPosBarcodeTarget(event.target)) {
                    resetBuffer();
                    return;
                }

                const now = Date.now();
                if (isBarcodeTerminatorKey(event.key)) {
                    const raw = buffer;
                    const duration = startedAt ? now - startedAt : Number.MAX_SAFE_INTEGER;
                    resetBuffer();
                    const normalized = normalizeProductBarcode(raw);
                    posBarcodeDebug('key terminator', {terminator: event.key, characters: raw.length, duration_ms: duration});
                    if (!normalized || normalized.length < 3 || duration > maxScanMs) return;
                    // The shared receipt scanner owns canonical receipt payloads
                    // outside the dedicated product input.
                    if (/^PF1:ORDER:[1-9][0-9]{0,9}$/i.test(normalized)) return;
                    event.preventDefault();
                    if (typeof event.stopImmediatePropagation === 'function') event.stopImmediatePropagation();
                    handleBarcodeScan(raw, null, {terminator: event.key, source: 'pos-keyboard-buffer'});
                    return;
                }
                if (event.key === 'Shift' || event.key === 'CapsLock' || event.key === 'NumLock' || event.key === 'Process') return;
                if (event.key.length !== 1 || !/[\x20-\x7E]/.test(event.key)) {
                    resetBuffer();
                    return;
                }
                if (!startedAt || now - lastKeyAt > maxGapMs) {
                    resetBuffer();
                    startedAt = now;
                    posBarcodeDebug('scan started', {source: 'pos-keyboard-buffer'});
                }
                buffer += event.key;
                lastKeyAt = now;
                if (buffer.length > 96) resetBuffer();
            }, true);
        }

        function handleBarcodeScan(code, sourceInput = null, scanMeta = {}) {
            const barcodeEl = sourceInput || Array.from(document.querySelectorAll('.pos-barcode-entry')).find(function(input) {
                return !!(input.offsetWidth || input.offsetHeight || input.getClientRects().length);
            }) || document.getElementById('pos-barcode-input-home');
            const raw = String(code || '');
            const sku = normalizeProductBarcode(raw);
            posBarcodeDebug('raw value', {value: raw, source: scanMeta.source || 'manual'});
            posBarcodeDebug('normalized value', {value: sku, terminator: scanMeta.terminator || ''});
            if (!sku) {
                finishBarcodeScan(barcodeEl);
                return Promise.resolve(false);
            }
            clearBarcodeInputs();
            focusBarcodeInput(barcodeEl);
            return new Promise(function(resolve) {
                barcodeScanQueue.push({sku, barcodeEl, scanMeta: {...scanMeta, queuedAt: Date.now()}, resolve});
                posBarcodeDebug('scan queued', {value: sku, queue_length: barcodeScanQueue.length});
                drainBarcodeScanQueue();
            });
        }

        async function drainBarcodeScanQueue() {
            if (barcodeScanQueueRunning) return;
            barcodeScanQueueRunning = true;
            barcodeScanBusy = true;
            try {
                while (barcodeScanQueue.length > 0) {
                    const scan = barcodeScanQueue.shift();
                    let result = false;
                    try {
                        result = await processBarcodeScan(scan.sku, scan.barcodeEl, scan.scanMeta);
                    } catch (error) {
                        console.error('[POS Barcode] scan processing failed', {
                            value: scan.sku,
                            name: error instanceof Error ? error.name : 'UnknownError',
                            message: error instanceof Error ? error.message : 'Unknown scan error'
                        });
                        showPOSScanNotice('Scan Error', 'Unable to scan product. Please try again.', 'error');
                    } finally {
                        scan.resolve(result);
                        focusBarcodeInput(scan.barcodeEl);
                    }
                }
            } finally {
                barcodeScanBusy = false;
                barcodeScanQueueRunning = false;
                if (barcodeScanQueue.length > 0) drainBarcodeScanQueue();
            }
        }

        async function processBarcodeScan(sku, barcodeEl, scanMeta = {}) {
            posBarcodeDebug('lookup request started', {value: sku, source: scanMeta.source || 'manual'});
            // Printed PrintFlow receipts use this canonical payload. Reuse the
                // existing focused scanner input without adding a competing global
                // keyboard listener or treating the receipt as a product SKU.
                if (/^PF1:ORDER:[1-9][0-9]{0,9}$/i.test(sku)) {
                    try {
                        const lookupResponse = await fetch(
                            staffUrl('staff/api/order_receipt_lookup.php?identifier=') + encodeURIComponent(sku) + '&_=' + Date.now(),
                            { credentials: 'same-origin', cache: 'no-store', headers: { 'Accept': 'application/json' } }
                        );
                        const lookupData = await lookupResponse.json().catch(function() { return {}; });
                        if (!lookupResponse.ok || !lookupData.success || !lookupData.route) {
                            showPOSScanNotice('Receipt Lookup', lookupData.message || 'Order lookup failed. Please scan the receipt again.', 'error');
                            return;
                        }
                        showPOSScanNotice('Order Found', lookupData.warning || ('Opening ' + lookupData.identifier + '…'), 'success');
                        window.setTimeout(function() { window.location.assign(lookupData.route); }, lookupData.warning ? 900 : 150);
                    } catch (e) {
                        showPOSScanNotice('Network Error', 'Network error while looking up the receipt.', 'error');
                    }
                    return;
                }

                let product = null;
                let availability = null;
                try {
                    const res = await fetch(
                        staffUrl('staff/api/get_product_by_sku.php?sku=') + encodeURIComponent(sku),
                        {credentials: 'same-origin', cache: 'no-store', headers: {'Accept': 'application/json'}}
                    );
                    const contentType = res.headers && typeof res.headers.get === 'function'
                        ? String(res.headers.get('content-type') || '')
                        : '';
                    const data = await res.json().catch(function() { return null; });
                    posBarcodeDebug('lookup response', {
                        value: sku,
                        http_status: Number(res.status || 0),
                        content_type: contentType,
                        success: !!(data && data.success),
                        availability: data && data.availability ? data.availability : '',
                        product_id: data && data.product && data.product.product_id ? data.product.product_id : null,
                        response_sku: data && data.product && data.product.sku ? data.product.sku : ''
                    });
                    if (!res.ok || !data || (contentType && !contentType.toLowerCase().includes('application/json'))) {
                        showPOSScanNotice('Scan Error', 'Unable to scan product. Please try again.', 'error');
                        return;
                    }
                    if (!data.success) {
                        showPOSScanNotice('Scan Error', data.message || 'Could not scan barcode.', 'error');
                        return;
                    }
                    product = data.product || null;
                    availability = data.availability || (product ? 'available' : null);
                    if (product && availability === 'available') {
                        const existingIndex = products.findIndex(p => String(p.product_id) === String(product.product_id));
                        if (existingIndex >= 0) {
                            product = mergePosProductRecord(products[existingIndex], product);
                            products[existingIndex] = product;
                        } else {
                            products.push(product);
                        }
                    }
                } catch (e) {
                    showPOSScanNotice('Network Error', 'Network error while scanning barcode.', 'error');
                    return;
                }
                if (!product) {
                    showPOSScanNotice('Product Not Found', 'No product matches the scanned barcode or SKU.', 'warning');
                    return;
                }
                if (availability === 'archived' || String(product.status || '').toLowerCase() === 'archived') {
                    showPOSScanNotice('Product Unavailable', 'This product has been archived and cannot be sold.', 'warning');
                    return;
                }
                if (availability === 'inactive' || String(product.status || '').toLowerCase() === 'deactivated') {
                    showPOSScanNotice('Product Inactive', 'This product is currently inactive and cannot be sold.', 'warning');
                    return;
                }
                if (availability === 'pos_unavailable') {
                    showPOSScanNotice('Product Unavailable', 'This product is not available for POS sale.', 'warning');
                    return;
                }

                const stock = parseInt(product.stock_quantity, 10) || 0;
                if (stock <= 0) {
                    showPOSScanNotice('Out of Stock', 'Product: ' + (product.product_name || 'Product') + '\nSKU: ' + (product.sku || sku) + '\nThis product is currently out of stock and cannot be added to the cart.', 'warning');
                    return;
                }
                if (scannedCartQuantity(product) >= stock) {
                    showPOSScanNotice('Insufficient Stock', 'Only ' + stock + ' item(s) are currently available.', 'warning');
                    return;
                }

                const result = await addToCart(product, null, null, { silentErrors: true });
                posBarcodeDebug('add-to-cart result', {
                    value: sku,
                    product_id: product.product_id,
                    success: !!(result && result.success),
                    message: result && result.message ? result.message : '',
                    elapsed_ms: scanMeta.queuedAt ? Date.now() - scanMeta.queuedAt : null
                });
                if (result && result.success) {
                    showPOSScanNotice('Added to Cart', (product.product_name || 'Product') + ' was added to the cart.', 'success');
                    renderProducts();
                } else if (result && result.message && result.message.toLowerCase().includes('out of stock')) {
                    showPOSScanNotice('Out of Stock', 'Product: ' + (product.product_name || 'Product') + '\nSKU: ' + (product.sku || sku) + '\nThis product is currently out of stock and cannot be added to the cart.', 'warning');
                } else if (result && result.message && result.message.toLowerCase().includes('stock')) {
                    showPOSScanNotice('Insufficient Stock', 'Only ' + stock + ' item(s) are currently available.', 'warning');
                } else if (result && result.message) {
                    showPOSScanNotice('Scan Error', result.message, 'error');
                } else if (result && result.success === false) {
                    showPOSScanNotice('Scan Error', 'Could not add this product to the cart.', 'error');
                }
            return true;
        }
        async function addToCart(p, overridePrice = null, overrideName = null, options = {}) {
            if ((parseFloat(p.price) || 0) <= 0 && overridePrice === null) {
                const proceed = await confirmMissingEstimatedPrice();
                if (!proceed) return { success: false, cancelled: true };
                overridePrice = 0;
            }

            const variantPlan = posResolveVariantBeforeAdd(p);
            if (variantPlan.action === 'error') {
                if (!options.silentErrors) {
                    await showPOSAlert('Cannot Add Product', variantPlan.message, 'warning');
                }
                return { success: false, message: variantPlan.message };
            }
            if (variantPlan.action === 'prompt') {
                openVariantModal(p, variantPlan.options, variantPlan.fieldLabel, options);
                return { success: true, pending_variant: true };
            }

            return await posAddProductToCart(p, overridePrice, overrideName, variantPlan.customization, options);
        }

        let pendingCustomProduct = null;
        let currentCustomRequirements = null;
        let posDynamicRequirements = null;
        let posDynamicFieldStartIndex = 500;

        function renderPosField(container, req, idx, baseStyle) {
            const div = document.createElement('div');
            div.style.display = 'flex';
            div.style.flexDirection = 'column';
            div.style.gap = '4px';
            const reqLabel = req.label || '';
            const reqName = req.name || ('field_' + idx);
            const isOpt = reqLabel.includes('(if ');
            let label = `<label style="font-size:12px; font-weight:600; color:#475569; text-transform:uppercase; letter-spacing:0.05em;">${reqLabel}</label>`;
            let inputHtml = '';
            if (req.type === 'dimensions_ft') {
                const subNames = req.subNames || ['width', 'height'];
                const placeholders = req.placeholders || ['Width', 'Height'];
                div.innerHTML = `<label style="font-size:12px; font-weight:600; color:#475569; text-transform:uppercase; letter-spacing:0.05em;">${reqLabel}</label>
            <div style="display:flex; align-items:center; gap:8px;">
                <input type="number" id="custom_field_${idx}_0" name="${subNames[0]}" placeholder="${placeholders[0]}" step="0.1" style="${baseStyle}; flex:1;" data-field-name="${subNames[0]}">
                <span style="flex-shrink:0; font-weight:700; color:#94a3b8;">×</span>
                <input type="number" id="custom_field_${idx}_1" name="${subNames[1]}" placeholder="${placeholders[1]}" step="0.1" style="${baseStyle}; flex:1;" data-field-name="${subNames[1]}">
            </div>`;
                div.dataset.fieldType = 'dimensions_ft';
                div.dataset.fieldIndex = String(idx);
            } else if (req.type === 'select_other') {
                const opts = req.options || [];
                const otherOpt = req.otherOption || 'Others';
                const otherName = req.otherName || (reqName + '_other');
                const otherPh = (req.otherPlaceholder || 'Enter custom').replace(/"/g, '&quot;');
                inputHtml = `<select id="custom_field_${idx}" name="${reqName}" style="${baseStyle}" data-field-name="${reqName}" data-other-option="${otherOpt}" data-other-name="${otherName}" onchange="togglePosOtherInput(${idx}, '${otherOpt}', '${otherName}')">`;
                inputHtml += `<option value="">Select...</option>`;
                opts.forEach(opt => {
                    const val = (typeof opt === 'object' && opt !== null && 'value' in opt) ? opt.value : opt;
                    const lab = (typeof opt === 'object' && opt !== null && 'label' in opt) ? opt.label : opt;
                    inputHtml += `<option value="${String(val).replace(/"/g, '&quot;')}">${String(lab).replace(/</g, '&lt;')}</option>`;
                });
                inputHtml += `</select>`;
                inputHtml += `<div id="custom_other_${idx}" style="display:none; margin-top:6px;"><input type="text" id="custom_field_${idx}_other" name="${otherName}" placeholder="${otherPh}" style="${baseStyle}" data-field-name="${otherName}"></div>`;
                div.innerHTML = label + inputHtml;
                div.dataset.fieldType = 'select_other';
                if (req.disabledWhen && req.disabledWhen.field && req.disabledWhen.value) {
                    const wrap = document.createElement('div');
                    wrap.dataset.disabledWhenField = req.disabledWhen.field;
                    wrap.dataset.disabledWhenValue = req.disabledWhen.value;
                    wrap.dataset.disabledWhenDisplay = req.disabledWhen.display || 'Provided by Customer';
                    const readonlyDiv = document.createElement('div');
                    readonlyDiv.className = 'pos-disabled-when-display';
                    readonlyDiv.style.cssText = 'display:none; padding:10px 12px; background:#f1f5f9; border:1px solid #e2e8f0; border-radius:8px; font-size:14px; color:#64748b;';
                    readonlyDiv.innerHTML = reqLabel + ': <strong style="color:#475569;">' + (req.disabledWhen.display || 'Provided by Customer') + '</strong>';
                    wrap.appendChild(div);
                    wrap.appendChild(readonlyDiv);
                    container.appendChild(wrap);
                    return wrap;
                }
            } else if (req.type === 'select') {
                inputHtml = `<select id="custom_field_${idx}" name="${reqName}" style="${baseStyle}" data-field-name="${reqName}">`;
                inputHtml += `<option value="">Select...</option>`;
                const opts = req.options || [];
                opts.forEach(opt => {
                    const val = (typeof opt === 'object' && opt !== null && 'value' in opt) ? opt.value : opt;
                    const lab = (typeof opt === 'object' && opt !== null && 'label' in opt) ? opt.label : opt;
                    inputHtml += `<option value="${String(val).replace(/"/g, '&quot;')}">${String(lab).replace(/</g, '&lt;')}</option>`;
                });
                inputHtml += `</select>`;
                div.innerHTML = label + inputHtml;
            } else if (req.type === 'file') {
                const reqNameLc = String(reqName || '').toLowerCase();
                const allowMultiple = reqNameLc === 'design_file' || reqNameLc.includes('design');
                const multipleAttr = allowMultiple ? ' multiple' : '';
                inputHtml = `<input type="file" id="custom_field_${idx}" name="${reqName}"${multipleAttr} accept="${(req.accept || '').replace(/"/g, '&quot;')}" style="${baseStyle}" data-field-name="${reqName}">`;
                div.innerHTML = label + inputHtml;
            } else if (req.type === 'date') {
                const minAttr = ` min="${new Date().toISOString().split('T')[0]}"`;
                inputHtml = `<input type="date" id="custom_field_${idx}" name="${reqName}"${minAttr} style="${baseStyle}" data-field-name="${reqName}">`;
                div.innerHTML = label + inputHtml;
            } else {
                const ph = (req.placeholder || '').replace(/"/g, '&quot;');
                const st = req.step ? ` step="${req.step}"` : '';
                const dv = req.defaultValue ? ` value="${String(req.defaultValue).replace(/"/g, '&quot;')}"` : '';
                inputHtml = `<input type="${req.type || 'text'}" id="custom_field_${idx}" name="${reqName}" placeholder="${ph}"${st}${dv} style="${baseStyle}" data-field-name="${reqName}">`;
                div.innerHTML = label + inputHtml;
            }
            div.dataset.fieldName = reqName;
            if (req.conditionalOn && req.conditionalOn.field && req.conditionalOn.value) {
                const wrap = document.createElement('div');
                wrap.style.display = 'none';
                wrap.dataset.conditionalField = req.conditionalOn.field;
                wrap.dataset.conditionalValue = req.conditionalOn.value;
                wrap.appendChild(div);
                container.appendChild(wrap);
                return wrap;
            }
            container.appendChild(div);
            return div;
        }

        function renderReflectorizedDynamicFields(productType) {
            const dynContainer = document.getElementById('cm-dynamic-product-fields');
            if (!dynContainer) return;
            dynContainer.innerHTML = '';
            posDynamicRequirements = null;
            if (!productType) return;
            const refl = serviceRequirements['Reflectorized'];
            if (!refl || !refl[productType]) return;
            posDynamicRequirements = refl[productType];
            const baseStyle = 'width:100%; padding:10px 12px; border:1px solid #cbd5e1; border-radius:8px; font-size:14px; outline:none;';
            posDynamicRequirements.forEach((req, i) => {
                const idx = posDynamicFieldStartIndex + i;
                renderPosField(dynContainer, req, idx, baseStyle);
            });
        }

        function openCustomModal(product, requirements) {
            pendingCustomProduct = product;
            currentCustomRequirements = requirements;
            posDynamicRequirements = null;

            const isReflectorized = (product.category === 'Reflectorized' || product.product_name === 'Reflectorized');

            document.getElementById('cm-title').textContent = (product.product_name || product.name) + ' Details';
            const container = document.getElementById('cm-dynamic-fields');
            container.innerHTML = '';

            const baseStyle = 'width:100%; padding:10px 12px; border:1px solid #cbd5e1; border-radius:8px; font-size:14px; outline:none;';

            requirements.forEach((req, idx) => {
                const r = typeof req === 'function' ? req() : req;
                if (r) renderPosField(container, r, idx, baseStyle);
            });
            wireUpConditionalFields(container);
            wireUpDisabledWhenFields(container);
            if (isReflectorized) {
                const dynWrap = document.createElement('div');
                dynWrap.id = 'cm-dynamic-product-fields';
                dynWrap.style.marginTop = '12px';
                container.appendChild(dynWrap);
                const ptSelect = container.querySelector('select[name="product_type"]');
                if (ptSelect) {
                    ptSelect.addEventListener('change', function () {
                        const val = this.value;
                        renderReflectorizedDynamicFields(val);
                    });
                    if (ptSelect.value) renderReflectorizedDynamicFields(ptSelect.value);
                }
            }

            // Inject Price Input directly into the form if product price is 0
            const initialPrice = parseFloat(product.price) || 0;
            if (initialPrice === 0) {
                const priceHtml = `
            <div id="cm-price-section" style="margin-top:16px; padding-top:16px; border-top:1px dashed #cbd5e1;">
                <label style="display:block; font-size:12px; font-weight:700; color:#64748b; text-transform:uppercase; margin-bottom:8px; letter-spacing:0.05em;">Negotiated Price *</label>
                <div style="position: relative;">
                    <span style="position: absolute; left: 16px; top: 14px; font-weight: 700; color: #94a3b8;">₱</span>
                    <input type="text" id="cm-price-input" 
                           oninput="let v = this.value.replace(/[^0-9.]/g, ''); let p = v.split('.'); p[0] = p[0].replace(/\B(?=(\d{3})+(?!\d))/g, ','); this.value = p.join('.');"
                           onblur="if(this.value){ let n = parseFloat(this.value.replace(/,/g, '')) || 0; this.value = n.toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2}); }"
                           style="width:100%; padding:14px 14px 14px 32px; border:1px solid #e2e8f0; border-radius:12px; font-weight:800; font-size:24px; background:#f8fafc; color:#1e293b; outline:none;" placeholder="0.00">
                </div>
            </div>`;
                container.insertAdjacentHTML('beforeend', priceHtml);

                // Ensure scrolling works properly
                container.style.maxHeight = '55vh';
            }

            document.getElementById('custom-modal-overlay').style.display = 'flex';
        }

        function closeCustomModal() {
            document.getElementById('custom-modal-overlay').style.display = 'none';
            pendingCustomProduct = null;
            currentCustomRequirements = null;
            posDynamicRequirements = null;
        }
        function togglePosOtherInput(idx, otherOption, otherName) {
            const sel = document.getElementById('custom_field_' + idx);
            const wrap = document.getElementById('custom_other_' + idx);
            if (sel && wrap) {
                wrap.style.display = (sel.value === otherOption) ? 'block' : 'none';
                if (sel.value !== otherOption) {
                    const inp = wrap.querySelector('input');
                    if (inp) inp.value = '';
                }
            }
        }

        function wireUpConditionalFields(container) {
            if (!container) return;
            container.querySelectorAll('[data-conditional-field]').forEach(wrap => {
                const fieldName = wrap.dataset.conditionalField;
                const showValue = wrap.dataset.conditionalValue;
                const parentSelect = container.querySelector('select[name="' + fieldName + '"]');
                const input = wrap.querySelector('input');
                if (!parentSelect) return;
                function sync() {
                    const show = parentSelect.value === showValue;
                    wrap.style.display = show ? 'block' : 'none';
                    if (!show && input) input.value = '';
                }
                parentSelect.addEventListener('change', sync);
                sync();
            });
        }

        function wireUpDisabledWhenFields(container) {
            if (!container) return;
            container.querySelectorAll('[data-disabled-when-field]').forEach(wrap => {
                const fieldName = wrap.dataset.disabledWhenField;
                const triggerValue = wrap.dataset.disabledWhenValue;
                const triggerSelect = container.querySelector('select[name="' + fieldName + '"]');
                const editableChild = wrap.firstElementChild;
                const readonlyChild = wrap.querySelector('.pos-disabled-when-display');
                if (!triggerSelect || !editableChild || !readonlyChild) return;
                function sync() {
                    const disabled = triggerSelect.value === triggerValue;
                    editableChild.style.display = disabled ? 'none' : 'flex';
                    readonlyChild.style.display = disabled ? 'block' : 'none';
                    const sel = editableChild.querySelector('select');
                    const otherWrap = editableChild.querySelector('[id^="custom_other_"]');
                    const otherInp = otherWrap ? otherWrap.querySelector('input') : null;
                    if (sel) sel.disabled = disabled;
                    if (disabled) {
                        if (sel) sel.value = '';
                        if (otherInp) otherInp.value = '';
                        if (otherWrap) otherWrap.style.display = 'none';
                    }
                }
                triggerSelect.addEventListener('change', sync);
                sync();
            });
        }

        async function collectRequirementsToCustomization(requirements, startIdx, customization, validation) {
            if (!requirements) return;
            requirements.forEach((req, i) => {
                const resolvedReq = typeof req === 'function' ? req() : req;
                if (!resolvedReq) return;
                req = resolvedReq;
                const idx = startIdx + i;
                const name = req.name || ('field_' + idx);
                let val = null;

                if (req.type === 'dimensions_ft') {
                    const w = document.getElementById(`custom_field_${idx}_0`);
                    const h = document.getElementById(`custom_field_${idx}_1`);
                    if (w && h) {
                        const wv = w.value, hv = h.value;
                        if (req.required && (!wv || !hv)) validation.valid = false;
                        if (wv) customization['width'] = wv;
                        if (hv) customization['height'] = hv;
                    }
                    return;
                }
                if (req.type === 'select_other') {
                    const sel = document.getElementById(`custom_field_${idx}`);
                    const other = document.getElementById(`custom_field_${idx}_other`);
                    const otherOpt = req.otherOption || 'Others';
                    if (req.disabledWhen && req.disabledWhen.field) {
                        const overlay = document.getElementById('custom-modal-overlay');
                        const trigger = overlay ? overlay.querySelector('select[name="' + req.disabledWhen.field + '"]') : null;
                        if (trigger && trigger.value === req.disabledWhen.value) {
                            customization[name] = 'Provided by Customer';
                            return;
                        }
                    }
                    if (sel) {
                        val = sel.value;
                        if (val === otherOpt && other && other.value) {
                            val = other.value;
                        } else if (val === otherOpt && req.required) {
                            validation.valid = false;
                            return;
                        }
                        if (req.required && !val) validation.valid = false;
                        if (val) customization[name] = val;
                    }
                    return;
                }

                const el = document.getElementById(`custom_field_${idx}`);
                if (!el) return;
                val = el.value;
                if (req.required && !val) validation.valid = false;
                if (val) customization[name] = val;
            });

            for (let i = 0; i < requirements.length; i++) {
                const resolvedReq = typeof requirements[i] === 'function' ? requirements[i]() : requirements[i];
                if (!resolvedReq || resolvedReq.type !== 'file') {
                    continue;
                }
                const req = resolvedReq;
                const idx = startIdx + i;
                const name = req.name || ('field_' + idx);
                const el = document.getElementById(`custom_field_${idx}`);
                if (!(el && el.files && el.files.length > 0)) {
                    continue;
                }
                try {
                    if (name === 'design_file') {
                        await posApplyStagedUploadMany(customization, el.files, 'design');
                        customization[name] = customization.design_upload_name;
                    } else if (name === 'reference_file') {
                        await posApplyStagedUploadMany(customization, el.files, 'reference');
                        customization[name] = customization.reference_upload_name;
                    } else {
                        const payload = await posReadFilePayload(el.files[0]);
                        customization[name] = payload.name;
                    }
                } catch (uploadErr) {
                    await showPOSAlert('Upload Failed', uploadErr.message || 'Could not save the design file.', 'error');
                    return;
                }
            }
        }

        async function confirmCustomization() {
            if (!pendingCustomProduct || !currentCustomRequirements) return;

            const customization = {};
            const validation = { valid: true };

            await collectRequirementsToCustomization(currentCustomRequirements, 0, customization, validation);
            if (posDynamicRequirements) {
                await collectRequirementsToCustomization(posDynamicRequirements, posDynamicFieldStartIndex, customization, validation);
            }

            if (pendingCustomProduct.category === 'Reflectorized' || pendingCustomProduct.product_name === 'Reflectorized') {
                customization.service_type = 'Reflectorized Signage';
                if (!customization.product_type) {
                    validation.valid = false;
                }
            }

            if (!validation.valid) {
                await showPOSAlert('Incomplete Fields', 'Please complete all required fields (marked *) before proceeding.', 'warning');
                return;
            }

            let price = parseFloat(pendingCustomProduct.price) || 0;
            if (price === 0) {
                const pInput = document.getElementById('cm-price-input');
                const typedPrice = pInput ? String(pInput.value || '').trim() : '';
                if (typedPrice !== '') {
                    price = parseFloat(typedPrice.replace(/,/g, ''));
                    if (isNaN(price) || price < 0) {
                        await showPOSAlert('Invalid Price', 'Please enter a valid price.', 'warning');
                        pInput.focus();
                        return;
                    }
                }
            }
            if (price <= 0) {
                customization.price_unconfigured = '1';
                price = 0;
                const proceed = await confirmMissingEstimatedPrice();
                if (!proceed) return;
            } else {
                delete customization.price_unconfigured;
            }

            const result = await syncedCartAction('add', {
                product_id: pendingCustomProduct.product_id,
                name: pendingCustomProduct.product_name || pendingCustomProduct.name,
                price: price,
                qty: 1,
                customization: customization,
                is_service: true
            });

            if (result.success) {
                closeCustomModal();
            }
        }

        let pendingProduct = null;
        let isOtherService = false;
        let pendingCustomization = null;

        function openPriceModal(p, isOther = false, customization = null) {
            pendingProduct = p;
            isOtherService = isOther;
            pendingCustomization = customization || null;

            document.getElementById('pm-title').textContent = isOther ? 'Custom Service' : 'Set Service Price';
            document.getElementById('pm-name-group').style.display = isOther ? 'block' : 'none';
            document.getElementById('pm-name-input').value = isOther ? '' : (p.product_name || p.name || '');
            document.getElementById('pm-price-input').value = p.price > 0 ? p.price : '';
            document.getElementById('price-modal-overlay').style.display = 'flex';

            const focusEl = isOther ? 'pm-name-input' : 'pm-price-input';
            setTimeout(() => document.getElementById(focusEl).focus(), 100);
        }

        function closePriceModal() {
            document.getElementById('price-modal-overlay').style.display = 'none';
            pendingProduct = null;
            isOtherService = false;
            pendingCustomization = null;
        }

        async function confirmPrice() {
            const name = document.getElementById('pm-name-input').value.trim();
            const price = parseFloat(document.getElementById('pm-price-input').value);

            if (isOtherService && !name) {
                await showPOSAlert('Missing Information', 'Please enter a service name.', 'warning');
                return;
            }
            if (isNaN(price) || price <= 0) {
                await showPOSAlert('Invalid Price', 'Please enter a valid price.', 'warning');
                return;
            }

            addToCartWithCustomization(pendingProduct, price, name, pendingCustomization);
            closePriceModal();
        }

        async function addToCartWithCustomization(p, price, name, customization) {
            const itemName = name || p.product_name || p.name;
            const srcPage = String(customization?.source_page || '').trim().toLowerCase();
            const serviceLike = !!(
                customization
                && (
                    customization.service_id
                    || customization.service_type
                    || srcPage === 'services'
                    || srcPage === 'service'
                )
            );
            await syncedCartAction('add', {
                product_id: p.product_id,
                name: itemName,
                price: price,
                qty: 1,
                customization: customization,
                is_service: serviceLike
            });
        }

        function setActiveService(btn) {
            document.querySelectorAll('.pos-services-grid .service-btn').forEach(b => b.classList.remove('active'));
            if (btn) btn.classList.add('active');
            setTimeout(() => btn && btn.classList.remove('active'), 400);
        }

        // addQuickService kept as no-op for legacy compatibility
        function addQuickService(serviceName) { }

        function posReadPositiveWholeQuantity(value) {
            const raw = String(value ?? '').trim();
            if (!/^[1-9]\d*$/.test(raw)) return null;
            const parsed = Number(raw);
            return Number.isSafeInteger(parsed) && parsed <= 2147483647 ? parsed : null;
        }

        function validateCartQuantityInput(index, input) {
            const warning = document.getElementById(`pos-qty-warning-${index}`);
            const quantity = posReadPositiveWholeQuantity(input.value);
            const item = cart[index];
            let message = quantity === null ? 'Enter a positive whole number.' : '';
            const available = Number(item?.stock);
            if (!message && item && !item.is_service && Number.isFinite(available) && quantity > available) {
                message = `Only ${available} items are available.`;
            }
            if (warning) warning.textContent = message;
            input.setCustomValidity(message);
            return { quantity, message };
        }

        async function commitCartQuantityInput(index, input) {
            const result = validateCartQuantityInput(index, input);
            if (result.quantity === null || result.message) {
                input.reportValidity();
                return;
            }
            if (result.quantity === Number(cart[index]?.qty)) return;
            const response = await syncedCartAction('update', { index, qty: result.quantity }, { silentErrors: true });
            if (!response.success) {
                if (input.isConnected) {
                    const warning = document.getElementById(`pos-qty-warning-${index}`);
                    const message = response.message || 'Unable to validate available stock.';
                    if (warning) warning.textContent = message;
                    input.setCustomValidity(message);
                }
                await showPOSAlert('Stock Validation', response.message || 'Unable to update quantity.', 'warning');
            }
        }

        async function updateQtyByCartIndex(index, delta) {
            const item = cart[index];
            if (!item) return;
            let newQty = parseInt(item.qty) + delta;
            if (newQty < 1) newQty = 1;
            if (!Number.isSafeInteger(newQty) || newQty > 2147483647) return;
            await syncedCartAction('update', { index, qty: newQty });
        }

        async function removeByCartIndex(index) {
            await syncedCartAction('remove', { index });
        }

        async function clearCart() {
            if (cart.length > 0 && (await showPOSConfirm('Clear Order', 'Are you sure you want to clear the current order?', 'Clear', 'danger'))) {
                await syncedCartAction('clear');
                document.getElementById('pos-tendered').value = '';
            }
        }

        function renderCart() {
            const cont = document.getElementById('pos-cart-items');
            currentTotal = 0;

            if (cart.length === 0) {
                cont.innerHTML = `<div class="pos-empty-state"><i class="fas fa-shopping-basket"></i><p>Cart is empty</p></div>`;
            } else {
                cont.innerHTML = '';
                cart.forEach((item, index) => {
                    const unitPrice = posCartItemEstimatedUnitPrice(item);
                    const pricingComplete = posCartItemStaffPricingComplete(item);
                    const countsInTotal = posCartItemCountsInCheckoutTotal(item);
                    const confirmedLineTotal = (parseFloat(item.price) || 0) * (item.qty || 1);
                    if (countsInTotal) {
                        currentTotal += confirmedLineTotal;
                    }
                    const div = document.createElement('div');
                    div.className = 'pos-cart-item';

                    const showSetPrice = posCartItemShowsSetPriceButton(item);
                    const showEditPrice = posCartItemShowsEditPriceButton(item);
                    const isServiceLine = posCartItemRequiresSetPrice(item);
                    const materialLabel = posCartItemMaterialLabel(item);
                    const showEstimateBesideRemove = showSetPrice && item.price_set !== true;
                    const estimateBesideRemoveHtml = showEstimateBesideRemove
                        ? `<span class="pos-item-estimate-label" style="font-size:12px;font-weight:600;color:#64748b;white-space:nowrap;">Estimated Price: ${formatMoney(unitPrice)}</span>`
                        : '';

                    if (item.customization && typeof item.customization === 'object') {
                        try {
                            div.dataset.customization = JSON.stringify(item.customization);
                        } catch (_) {}
                    }

                    const variantLabel = posCartItemVariantLabel(item);
                    let priceHtml;
                    if (showSetPrice) {
                        priceHtml = `<button type="button" class="pos-btn-set-price" onclick="redirectToSetPrice(${index})" title="Set material and final price">
                    <i class="fas fa-tag"></i> Set Price
                  </button>`;
                    } else if (showEditPrice) {
                        priceHtml = `<button type="button" class="pos-btn-set-price" style="font-size:12px;padding:4px 8px;" onclick="redirectToSetPrice(${index})" title="Edit material and final price">
                    <i class="fas fa-pen"></i> Edit Price
                  </button>`;
                    } else {
                        priceHtml = `<div class="pos-item-price">${formatMoney(item.price)}</div>`;
                    }

                    let totalDisplay;
                    if (pricingComplete || (!isServiceLine && (parseFloat(item.price) || 0) > 0)) {
                        totalDisplay = formatMoney(confirmedLineTotal);
                    } else if (isServiceLine) {
                        totalDisplay = '<span style="font-size:12px;color:#94a3b8;">—</span>';
                    } else {
                        totalDisplay = formatMoney(confirmedLineTotal);
                    }

                    div.innerHTML = `
                <div class="pos-cart-item-top">
                    <div class="pos-item-details">
                        <div class="pos-item-name">${escapeHtml(item.name)}${variantLabel ? `<div style="font-size:11px; color:#64748b; margin-top:2px;">${escapeHtml(variantLabel)}</div>` : ''}${materialLabel ? `<div style="font-size:11px;color:#64748b;margin-top:2px;">Material: ${escapeHtml(materialLabel)}</div>` : ''}</div>
                    </div>
                    <div class="pos-cart-item-top-actions" style="display:flex;align-items:center;gap:10px;flex-shrink:0;">
                        ${estimateBesideRemoveHtml}
                        <button type="button" class="pos-item-remove" onclick="removeByCartIndex(${index})" title="Remove item" aria-label="Remove item">
                            <i class="fas fa-trash-alt"></i> Remove
                        </button>
                    </div>
                </div>
                <div class="pos-cart-item-bottom">
                    <div class="pos-item-action">${priceHtml}</div>
                    <div class="pos-item-controls">
                        <button type="button" class="pos-qty-btn" onclick="updateQtyByCartIndex(${index}, -1)" aria-label="Decrease quantity">&minus;</button>
                        <input class="pos-qty-val" type="text" inputmode="numeric" autocomplete="off" value="${escapeHtml(String(item.qty))}" aria-label="Quantity" aria-describedby="pos-qty-warning-${index}" oninput="validateCartQuantityInput(${index}, this)" onblur="commitCartQuantityInput(${index}, this)" onkeydown="if(event.key==='Enter'){event.preventDefault();this.blur();}">
                        <button type="button" class="pos-qty-btn" onclick="updateQtyByCartIndex(${index}, 1)" aria-label="Increase quantity">&plus;</button>
                    </div>
                    <div class="pos-item-total">${totalDisplay}</div>
                </div>
                <div class="pos-qty-warning" id="pos-qty-warning-${index}" role="status" aria-live="polite"></div>
            `;
                    cont.appendChild(div);
                });
            }

            const fTotal = formatMoney(currentTotal);
            document.getElementById('pos-subtotal').textContent = fTotal;
            document.getElementById('pos-total').textContent = fTotal;

            calculateChange();
            posSyncQrphStateWithCart();
            updateCheckoutState();
        }

        function posCartTotalCentavos() {
            return Math.round((Number(currentTotal) || 0) * 100);
        }

        function posQrphFlowStillValid(expectedEpoch, expectedTotalCentavos = null) {
            if (expectedEpoch !== posQrphFlowEpoch) {
                return false;
            }
            if (!Array.isArray(cart) || cart.length === 0) {
                return false;
            }
            if (expectedTotalCentavos !== null && posCartTotalCentavos() !== expectedTotalCentavos) {
                return false;
            }
            return true;
        }

        function clearPayMongoPosModalPresentation() {
            const modal = document.getElementById('paymongo-pos-modal');
            if (modal) {
                modal.style.display = 'none';
            }
            const qrImage = document.getElementById('paymongo-pos-qr');
            if (qrImage) {
                qrImage.style.display = 'none';
                qrImage.removeAttribute('src');
            }
            const reference = document.getElementById('paymongo-pos-reference');
            if (reference) {
                reference.textContent = '';
            }
            const countdown = document.getElementById('paymongo-pos-countdown');
            if (countdown) {
                countdown.textContent = '';
            }
        }

        async function posAbandonPayMongoCheckout(options = {}) {
            const checkoutToken = sessionStorage.getItem('pos_paymongo_checkout_token') || '';
            const orderId = Number(pendingPayMongoOrderId || 0);
            if (!checkoutToken && orderId <= 0) {
                return { success: true, skipped: true };
            }
            try {
                const response = await fetchWithTimeout(staffUrl('staff/api/pos_cart_handler.php'), {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        action: 'abandon_qrph',
                        checkout_token: checkoutToken,
                        order_id: orderId,
                        csrf_token: POS_CSRF_TOKEN
                    })
                }, Number(options.timeoutMs || 8000));
                const data = await response.json().catch(() => ({}));
                return { success: response.ok && data.success !== false, data };
            } catch (err) {
                if (!options.silent) {
                    console.warn('posAbandonPayMongoCheckout failed:', err);
                }
                return { success: false };
            }
        }

        async function dismissPosQrphFlow(options = {}) {
            const closeModal = options.closeModal !== false;
            const clearToken = options.clearToken !== false;
            const bumpEpoch = options.bumpEpoch !== false;
            const abandonCheckout = clearToken && (
                pendingPayMongoOrderId > 0
                || !!sessionStorage.getItem('pos_paymongo_checkout_token')
                || !!sessionStorage.getItem('pos_paymongo_pending')
            );
            if (abandonCheckout) {
                await posAbandonPayMongoCheckout({ silent: true });
            }
            if (bumpEpoch) {
                posQrphFlowEpoch += 1;
            }
            paymongoModalEpoch = null;
            if (closeModal) {
                clearPayMongoPosModalPresentation();
            }
            stopPayMongoPosPolling();
            pendingPayMongoPayment = null;
            pendingPayMongoReceipt = null;
            pendingPayMongoPrintJob = null;
            pendingPayMongoOrderId = 0;
            posPayMongoCheckoutPending = false;
            posQrphLaunchInFlight = false;
            posQrphPanelError = '';
            resetPayMongoPosCheckoutState(clearToken);
            syncPosQrphPaymentSection();
        }

        function posSyncQrphStateWithCart() {
            const centavos = posCartTotalCentavos();
            const tokenTotalCentavos = Number(sessionStorage.getItem('pos_paymongo_checkout_token_total') || -1);
            const hasPendingQrSession = !!sessionStorage.getItem('pos_paymongo_pending')
                || pendingPayMongoOrderId > 0
                || isPayMongoPosModalOpen();
            if (!Array.isArray(cart) || cart.length === 0) {
                if (hasPendingQrSession || isPayMongoPosModalOpen()) {
                    dismissPosQrphFlow({ closeModal: true, clearToken: true });
                } else {
                    syncPosQrphPaymentSection();
                }
                return;
            }
            if (hasPendingQrSession && tokenTotalCentavos >= 0 && tokenTotalCentavos !== centavos) {
                dismissPosQrphFlow({ closeModal: true, clearToken: true });
                return;
            }
            syncPosQrphPaymentSection();
        }

        // Handlers are used above in renderCart via 'index' directly


        // Handlers are used above in renderCart via 'index' directly


        const POS_PAYMENT_METHOD_QRPH = 'PayMongo QRPh';

        function normalizePosPaymentMethod(method) {
            const key = String(method || '').trim().toLowerCase().replace(/\s+/g, ' ');
            if (key === 'paymongo qrph' || key === 'paymongo qr ph') {
                return POS_PAYMENT_METHOD_QRPH;
            }
            return String(method || '').trim();
        }

        function isPayMongoPaymentMethod(method) {
            return normalizePosPaymentMethod(method) === POS_PAYMENT_METHOD_QRPH;
        }

        let posQrphPanelError = '';
        let posQrphLaunchInFlight = false;

        function posQrphReadinessMessage() {
            if (!Array.isArray(cart) || cart.length === 0) {
                return 'Add items to the cart to generate the QR PH payment.';
            }
            if (!$('#pos-customer').val()) {
                return 'Select a customer to generate the QR PH payment.';
            }
            if (cart.some(i => posCartItemShowsSetPriceButton(i))
                || cart.some(i => !posCartItemCountsInCheckoutTotal(i) || (parseFloat(i.price) || 0) <= 0)) {
                return 'Set a price for every item before generating the QR PH payment.';
            }
            if (!(currentTotal > 0)) {
                return 'Cart total must be greater than ₱0.00.';
            }
            return '';
        }

        function setPosQrphPanelError(message) {
            posQrphPanelError = String(message || '');
            syncPosQrphPaymentSection();
        }

        function syncPosQrphPaymentSection() {
            const section = document.getElementById('pos-qrph-payment-section');
            const generateBtn = document.getElementById('pos-qrph-generate-btn');
            if (!section) {
                return;
            }
            const pm = document.getElementById('pos-payment-method')?.value || '';
            const isPayMongo = isPayMongoPaymentMethod(pm);
            const cartHasItems = Array.isArray(cart) && cart.length > 0;
            const showSection = isPayMongo && cartHasItems;
            section.hidden = !showSection;
            section.classList.toggle('is-visible', showSection);
            section.style.display = showSection ? '' : 'none';
            if (!isPayMongo) {
                posQrphPanelError = '';
            }
            const readiness = isPayMongo ? posQrphReadinessMessage() : '';
            const busy = posQrphLaunchInFlight || posCheckoutRequestInFlight || posCheckoutConfirmOpen;
            if (generateBtn) {
                generateBtn.disabled = !isPayMongo || !!readiness || busy;
                generateBtn.textContent = busy ? 'Generating…' : 'Generate QR PH Payment';
            }
        }

        function isPayMongoPosModalOpen() {
            const modal = document.getElementById('paymongo-pos-modal');
            return !!modal && modal.style.display !== 'none' && modal.style.display !== '';
        }

        /**
         * Reuse the existing POS checkout -> PayMongo Dynamic QR Ph flow.
         * Guards against duplicate Payment Intent creation when the dropdown
         * changes or the button is clicked repeatedly.
         */
        async function launchPosQrphPayment(source = 'select') {
            const pm = document.getElementById('pos-payment-method')?.value || '';
            if (!isPayMongoPaymentMethod(pm)) {
                return;
            }
            if (posQrphLaunchInFlight || posCheckoutRequestInFlight || posCheckoutConfirmOpen || isPayMongoPosModalOpen()) {
                return;
            }
            const readiness = posQrphReadinessMessage();
            if (readiness) {
                if (source === 'button') {
                    showPOSAlert('QR PH Payment', readiness, 'warning');
                }
                return;
            }
            posQrphLaunchInFlight = true;
            posQrphPanelError = '';
            syncPosQrphPaymentSection();
            try {
                await processCheckout();
            } catch (error) {
                setPosQrphPanelError((error && error.message) ? error.message : 'The QR PH payment could not be created.');
            } finally {
                posQrphLaunchInFlight = false;
                syncPosQrphPaymentSection();
            }
        }

        function initPosPaymentMethodHandlers() {
            const paymentSelect = document.getElementById('pos-payment-method');
            if (!paymentSelect || paymentSelect.dataset.posPaymentBound === '1') {
                return;
            }
            paymentSelect.dataset.posPaymentBound = '1';
            paymentSelect.addEventListener('change', function() {
                if (!isPayMongoPaymentMethod(paymentSelect.value)) {
                    dismissPosQrphFlow({ closeModal: true, clearToken: true });
                    toggleReferenceField();
                    return;
                }
                toggleReferenceField();
                launchPosQrphPayment('select');
            });
        }

        function toggleReferenceField() {
            const paymentSelect = document.getElementById('pos-payment-method');
            const pm = paymentSelect ? paymentSelect.value : '';
            const isPayMongo = isPayMongoPaymentMethod(pm);
            const tenderGroup = document.getElementById('tender-group');
            const changeGroup = document.getElementById('change-group');
            if (tenderGroup) {
                tenderGroup.style.display = isPayMongo ? 'none' : '';
                tenderGroup.hidden = isPayMongo;
            }
            if (changeGroup) {
                changeGroup.style.display = isPayMongo ? 'none' : '';
                changeGroup.hidden = isPayMongo;
            }
            syncPosQrphPaymentSection();
            if (isPayMongo) {
                const section = document.getElementById('pos-qrph-payment-section');
                if (section && typeof section.scrollIntoView === 'function') {
                    section.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
                }
            }
            updateCheckoutState();
        }

        function calculateChange() {
            if (currentTotal === 0) {
                document.getElementById('pos-change').textContent = formatMoney(0);
                return;
            }
            const tenderedInput = document.getElementById('pos-tendered');
            let tendered = parseFloat(tenderedInput.value) || 0;

            if (tendered > 1000000) {
                tendered = 1000000;
                tenderedInput.value = tendered;
            }

            let change = tendered - currentTotal;
            if (change < 0) change = 0; // Must never be negative on display

            const changeEl = document.getElementById('pos-change');
            changeEl.textContent = formatMoney(change);
            changeEl.style.color = (tendered < currentTotal && tendered > 0) ? '#ef4444' : 'var(--staff-primary)';

            updateCheckoutState();
        }

        function updateCheckoutState() {
            const btn = document.getElementById('pos-checkout-btn');
            const icon = document.getElementById('checkout-icon');
            const text = document.getElementById('checkout-text');

            if (posCheckoutRequestInFlight) {
                btn.disabled = true;
                icon.className = 'fas fa-spinner fa-spin';
                text.textContent = 'Processing...';
                return;
            }

            if (cart.length === 0) {
                btn.disabled = true;
                icon.className = 'fas fa-lock';
                text.textContent = 'Select Items';
                return;
            }

            let canCheckout = true;
            let message = 'Complete Sale';

            // Check if customer is selected
            const customer = $('#pos-customer').val();
            if (!customer) {
                canCheckout = false;
                message = 'Select Customer';
            }

            const needsSetPrice = cart.some(i => posCartItemShowsSetPriceButton(i));
            if (needsSetPrice) {
                canCheckout = false;
                message = 'Set Price First';
                icon.className = 'fas fa-lock';
                text.textContent = message;
                btn.disabled = true;
                return;
            }

            // Block checkout if any item has no valid price
            const hasUnpricedItem = cart.some(i => !posCartItemCountsInCheckoutTotal(i) || (parseFloat(i.price) || 0) <= 0);
            if (hasUnpricedItem) {
                canCheckout = false;
                message = 'Set Price First';
                icon.className = 'fas fa-lock';
                text.textContent = message;
                btn.disabled = true;
                return;
            }

            const pm = document.getElementById('pos-payment-method').value;

            // Regular products require payment
            const tendered = parseFloat(document.getElementById('pos-tendered').value) || 0;
            if (!isPayMongoPaymentMethod(pm) && (tendered < currentTotal || tendered > 1000000)) {
                canCheckout = false;
                if (message === 'Complete Sale') message = 'Enter Valid Amount';
            }

            btn.disabled = !canCheckout;
            icon.className = canCheckout ? 'fas fa-check-circle' : 'fas fa-lock';
            text.textContent = message;
        }

        function posAllowPastNeededDateInputs(root) {
            const scope = root || document;
            scope.querySelectorAll('input[type="date"]').forEach(function(input) {
                const name = String(input.name || '').toLowerCase();
                const id = String(input.id || '').toLowerCase();
                if (name === 'needed_date' || id === 'needed_date' || name.indexOf('needed_date') !== -1) {
                    input.removeAttribute('min');
                }
            });
        }

        async function processCheckout() {
            if (cart.length === 0 || posCheckoutRequestInFlight || posCheckoutConfirmOpen) return;

            console.log('[POS CHECKOUT] started');
            console.log('[POS CHECKOUT] validating cart', { items: cart.length, total: currentTotal });

            for (const item of cart) {
                if (item.is_service) continue;
                const catalogProduct = products.find(p => String(p.product_id) === String(item.product_id));
                if (!catalogProduct || !catalogProduct.has_variant_stock) continue;
                if (!posCartItemVariantLabel(item)) {
                    const fieldLabel = catalogProduct.variant_stock_field_label || 'stock option';
                    await showPOSAlert(
                        'Selection Required',
                        item.name + ': please select a ' + fieldLabel + ' before checkout. Remove this item and add it again.',
                        'warning'
                    );
                    return;
                }
            }

            // Validate customer selection
            const customer = $('#pos-customer').val();
            if (!customer) {
                await showPOSAlert('Select Customer', 'Please select a customer before checkout.', 'warning');
                return;
            }

            const needsSetPrice = cart.some(i => posCartItemShowsSetPriceButton(i));
            if (needsSetPrice) {
                await showPOSAlert('Price Required', 'Please set the material and final price for service items before completing the sale.\n\nClick "Set Price" on the cart item to continue.', 'warning');
                return;
            }

            const stockValidation = await syncedCartAction('validate_stock', {}, { silentErrors: true });
            if (!stockValidation.success) {
                await showPOSAlert('Stock Validation', stockValidation.message || 'Available stock changed. Review the cart and try again.', 'warning');
                return;
            }

            // Block checkout if any item has no valid price
            const hasUnpricedItem = cart.some(i => !posCartItemCountsInCheckoutTotal(i) || (parseFloat(i.price) || 0) <= 0);
            if (hasUnpricedItem) {
                await showPOSAlert('Price Required', 'Please set the price for all items before completing the sale.\n\nClick the yellow "Set Price" button on items to set their price in Customizations.', 'warning');
                return;
            }

            const pm = document.getElementById('pos-payment-method').value;
            const tendered = parseFloat(document.getElementById('pos-tendered').value) || 0;

            if (!isPayMongoPaymentMethod(pm) && (tendered < currentTotal || tendered > 1000000)) {
                await showPOSAlert('Invalid Amount', "Amount paid must be at least " + formatMoney(currentTotal) + " and not exceed ₱1,000,000.", 'warning');
                return;
            }

            const changeAmount = isPayMongoPaymentMethod(pm) ? 0 : tendered - currentTotal;
            const confirmMsg = isPayMongoPaymentMethod(pm)
                ? `Create a Dynamic QR Ph payment for ${formatMoney(currentTotal)}? The sale remains unpaid until PayMongo confirms it.`
                : `Confirm sale of ${formatMoney(currentTotal)} using ${pm}?\nChange due: ${formatMoney(changeAmount)}`;

            posCheckoutConfirmOpen = true;
            const confirmed = await showPOSConfirm('Confirm Transaction', confirmMsg, 'Confirm', 'confirm');
            posCheckoutConfirmOpen = false;
            if (!confirmed) return;

            posCheckoutRequestInFlight = true;
            updateCheckoutState();
            syncPosQrphPaymentSection();

            const checkoutFlowEpoch = posQrphFlowEpoch;
            resetPayMongoPosCheckoutState(false);
            // A retry token may only be reused for the same cart total; otherwise the
            // server would hand back the earlier order's QR with a stale amount.
            const requestTotalCentavos = Math.round(currentTotal * 100);
            const tokenTotalCentavos = Number(sessionStorage.getItem('pos_paymongo_checkout_token_total') || -1);
            const checkoutToken = getPosPayMongoCheckoutToken(
                isPayMongoPaymentMethod(pm) && tokenTotalCentavos >= 0 && tokenTotalCentavos !== requestTotalCentavos
            );
            sessionStorage.setItem('pos_paymongo_checkout_token_total', String(requestTotalCentavos));
            posPayMongoCheckoutPending = true;
            const payload = {
                action: 'walkin_checkout',
                ...posCheckoutCustomerPayload(),
                payment_method: normalizePosPaymentMethod(pm),
                reference_number: '',
                amount_tendered: tendered,
                csrf_token: POS_CSRF_TOKEN,
                checkout_token: checkoutToken,
                items: cart.map(posCheckoutItemPayload)
            };

            let checkoutData = null;
            let checkoutErrorMessage = '';
            const checkoutUrl = staffUrl('staff/api/pos_checkout.php');
            try {
                console.log('[POS CHECKOUT] request sent', checkoutUrl, { items: payload.items.length });
                const startedAt = performance.now();
                const res = await fetchWithTimeout(checkoutUrl, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(payload)
                }, 45000);
                console.log('[POS CHECKOUT] response received', {
                    status: res.status,
                    ms: Math.round(performance.now() - startedAt)
                });
                const text = await res.text();
                let data;
                try {
                    data = JSON.parse(text);
                } catch (parseErr) {
                    console.error('[POS CHECKOUT] ERROR invalid JSON response', text.slice(0, 500));
                    checkoutErrorMessage = 'Server returned an invalid response. Check the browser console for details.';
                    return;
                }
                if (res.ok && data.success) {
                    console.log('[POS CHECKOUT] checkout completed', { orderId: data.order_id });
                    checkoutData = data;
                } else {
                    const failStage = (data && data.stage) ? String(data.stage) : '';
                    const failMessage = (data && data.message) ? String(data.message) : '';
                    console.error('[POS CHECKOUT] ERROR', {
                        httpStatus: res.status,
                        stage: failStage,
                        message: failMessage,
                        body: data
                    });
                    checkoutErrorMessage = failMessage
                        || (failStage ? ('Checkout failed at ' + failStage + ' (HTTP ' + res.status + ').') : ('Checkout failed with HTTP ' + res.status + '.'));
                }
            } catch (e) {
                const errMsg = (e && e.message) ? String(e.message) : String(e);
                console.error('[POS CHECKOUT] ERROR', errMsg, e);
                checkoutErrorMessage = e.name === 'AbortError'
                    ? 'Checkout took too long to respond. Please refresh the POS and check Store Orders before trying again.'
                    : ('Network error: ' + e.message);
            } finally {
                posCheckoutConfirmOpen = false;
                posCheckoutRequestInFlight = false;
                updateCheckoutState();
                syncPosQrphPaymentSection();
                console.log('[POS CHECKOUT] UI unlocked');
            }

            if (checkoutErrorMessage) {
                if (isPayMongoPaymentMethod(pm)) {
                    setPosQrphPanelError(checkoutErrorMessage);
                }
                await showPOSAlert('Checkout Failed', checkoutErrorMessage, 'error');
                return;
            }
            if (!checkoutData) return;

            // Read fresh inventory after checkout; never calculate stock in the UI.
            fetchProducts();

            posPayMongoCheckoutPending = false;

            if (checkoutData.payment_pending && checkoutData.payment) {
                if (!posQrphFlowStillValid(checkoutFlowEpoch, requestTotalCentavos)) {
                    console.warn('[POS CHECKOUT] Ignoring stale PayMongo QR response after cart or flow change.');
                    return;
                }
                const paymentAmountCentavos = Number(checkoutData.payment.amount || 0);
                if (paymentAmountCentavos > 0 && paymentAmountCentavos !== requestTotalCentavos) {
                    console.warn('[POS CHECKOUT] PayMongo amount differs from cart total', {
                        cart_total_centavos: requestTotalCentavos,
                        paymongo_amount_centavos: paymentAmountCentavos,
                        order_id: checkoutData.order_id
                    });
                }
                posQrphPanelError = '';
                openPayMongoPosModal(checkoutData.order_id, checkoutData.payment, checkoutFlowEpoch);
                return;
            }

            resetPayMongoPosCheckoutState();

            if (checkoutData.receipt && checkoutData.order_id) {
                try {
                    openReceiptModal(checkoutData.receipt);
                } catch (receiptError) {
                    console.error('[POS CHECKOUT] receipt modal failed:', receiptError);
                    await showPOSAlert(
                        'Sale Completed',
                        'Order #' + checkoutData.order_id + ' was saved, but the receipt preview could not be opened.',
                        'warning'
                    );
                }
            } else {
                await showPOSAlert(
                    'Sale Completed',
                    (checkoutData.message || 'Sale completed successfully.')
                        + (checkoutData.order_id ? ' Order #' + checkoutData.order_id + '.' : ''),
                    checkoutData.warning ? 'warning' : 'success'
                );
            }

            await clearPosCartAfterSuccessfulSale();
        }

        async function clearPosCartAfterSuccessfulSale() {
            document.getElementById('pos-payment-method').value = 'Cash';
            document.getElementById('pos-tendered').value = '';
            toggleReferenceField();
            calculateChange();
            const clearResult = await syncedCartAction('clear', {}, {silentErrors: true, timeoutMs: 10000});
            if (!clearResult.success) {
                cart = [];
                renderCart();
            }
            const guestNameInput = document.getElementById('pos-guest-name');
            if (guestNameInput) {
                guestNameInput.value = '';
            }
            updateCheckoutState();
        }

        function getPosPayMongoCheckoutToken(forceNew = false) {
            if (forceNew) {
                const bytes = new Uint8Array(24);
                crypto.getRandomValues(bytes);
                posPayMongoCheckoutAttemptToken = Array.from(bytes, value => value.toString(16).padStart(2, '0')).join('');
                sessionStorage.setItem('pos_paymongo_checkout_token', posPayMongoCheckoutAttemptToken);
                return posPayMongoCheckoutAttemptToken;
            }
            if (posPayMongoCheckoutAttemptToken) {
                return posPayMongoCheckoutAttemptToken;
            }
            const stored = sessionStorage.getItem('pos_paymongo_checkout_token');
            if (stored) {
                posPayMongoCheckoutAttemptToken = stored;
                return stored;
            }
            const bytes = new Uint8Array(24);
            crypto.getRandomValues(bytes);
            posPayMongoCheckoutAttemptToken = Array.from(bytes, value => value.toString(16).padStart(2, '0')).join('');
            sessionStorage.setItem('pos_paymongo_checkout_token', posPayMongoCheckoutAttemptToken);
            return posPayMongoCheckoutAttemptToken;
        }

        function stopPayMongoPosPolling() {
            if (paymongoPollTimer) window.clearInterval(paymongoPollTimer);
            if (paymongoCountdownTimer) window.clearInterval(paymongoCountdownTimer);
            paymongoPollTimer = null;
            paymongoCountdownTimer = null;
        }

        function resetPayMongoPosCheckoutState(clearCheckoutToken = true) {
            stopPayMongoPosPolling();
            pendingPayMongoPayment = null;
            pendingPayMongoReceipt = null;
            pendingPayMongoPrintJob = null;
            pendingPayMongoOrderId = 0;
            posPayMongoCheckoutPending = false;
            sessionStorage.removeItem('pos_paymongo_pending');
            if (clearCheckoutToken) {
                posPayMongoCheckoutAttemptToken = null;
                sessionStorage.removeItem('pos_paymongo_checkout_token');
                sessionStorage.removeItem('pos_paymongo_checkout_token_total');
            }
        }

        function closePayMongoPosModal(options = {}) {
            if (options.fullReset === true) {
                paymongoModalEpoch = null;
                clearPayMongoPosModalPresentation();
                stopPayMongoPosPolling();
                resetPayMongoPosCheckoutState(true);
                syncPosQrphPaymentSection();
                return;
            }
            dismissPosQrphFlow({ closeModal: true, clearToken: true });
        }

        function renderPayMongoPosPayment(payment) {
            pendingPayMongoPayment = payment || null;
            const isQr = payment?.payment_flow === 'payment_intent' && payment?.payment_method === 'qrph';
            const isVerifiedTest = isQr && payment?.mode === 'test' && payment?.livemode === false;
            const hasLiveQr = isQr && payment?.mode === 'live' && payment?.livemode === true && Boolean(payment?.qr_image_url);
            const qrImage = document.getElementById('paymongo-pos-qr');
            qrImage.style.display = hasLiveQr ? 'block' : 'none';
            if (hasLiveQr) qrImage.src = payment.qr_image_url;
            else qrImage.removeAttribute('src');
            const testNote = document.getElementById('paymongo-pos-test-note');
            const testLink = document.getElementById('paymongo-pos-test-simulation-link');
            const simulationUrl = isVerifiedTest ? String(payment?.test_simulation_url || '') : '';
            testNote.style.display = isVerifiedTest ? 'block' : 'none';
            if (simulationUrl) {
                testLink.href = simulationUrl;
                testLink.style.display = 'inline-block';
            } else {
                testLink.removeAttribute('href');
                testLink.style.display = 'none';
            }
            document.getElementById('paymongo-pos-title').textContent = 'Dynamic QR Ph';
            document.getElementById('paymongo-pos-order').textContent =
                `Order #${pendingPayMongoOrderId} - ${formatMoney(Number(payment?.amount || 0) / 100)}`;
            const referenceLabel = document.getElementById('paymongo-pos-reference');
            const reference = payment?.reference_number || payment?.payment_reference || '';
            referenceLabel.textContent = reference ? 'Reference Number: ' + reference : '';
            const retryButton = document.getElementById('paymongo-pos-retry');
            const status = String(payment?.status || '').toLowerCase();
            retryButton.style.display = isQr && ['failed', 'expired', 'cancelled'].includes(status) ? 'block' : 'none';
            const statusLabel = document.getElementById('paymongo-pos-status');
            if (status === 'failed') statusLabel.textContent = 'Payment was not completed. Generate a new QR to try again.';
            else if (status === 'expired') statusLabel.textContent = 'QR code expired. Generate a new QR to continue.';
            else if (status === 'paid') statusLabel.textContent = 'Payment confirmed. Complete the transaction to continue.';
            else if (isVerifiedTest) statusLabel.textContent = simulationUrl ? 'Test Mode: use the simulator; do not scan or pay a QR code.' : 'Test Mode: simulator link unavailable until PayMongo confirms this Test payment.';
            else statusLabel.textContent = 'Waiting for payment confirmation.';

            if (paymongoCountdownTimer) window.clearInterval(paymongoCountdownTimer);
            const countdown = document.getElementById('paymongo-pos-countdown');
            countdown.textContent = '';
            if (isQr && payment?.qr_expires_at_epoch && status === 'awaiting_payment') {
                const renderCountdown = () => {
                    const remaining = Math.max(0, Number(payment.qr_expires_at_epoch) - Math.floor(Date.now() / 1000));
                    const minutes = String(Math.floor(remaining / 60)).padStart(2, '0');
                    const seconds = String(remaining % 60).padStart(2, '0');
                    countdown.textContent = remaining > 0 ? `QR expires in ${minutes}:${seconds}` : 'QR code expired';
                    if (remaining <= 0 && paymongoCountdownTimer) {
                        window.clearInterval(paymongoCountdownTimer);
                        paymongoCountdownTimer = null;
                    }
                };
                renderCountdown();
                paymongoCountdownTimer = window.setInterval(renderCountdown, 1000);
            }
        }

        async function retryPayMongoPosQr() {
            if (pendingPayMongoOrderId <= 0 || !Array.isArray(cart) || cart.length === 0) return;
            const retryButton = document.getElementById('paymongo-pos-retry');
            retryButton.disabled = true;
            document.getElementById('paymongo-pos-status').textContent = 'Generating a new QR Ph code...';
            try {
                const response = await fetch(staffUrl('staff/api/paymongo_payment.php'), {
                    method: 'POST',
                    headers: {'Content-Type': 'application/json'},
                    body: JSON.stringify({
                        action: 'create_qrph',
                        subject_type: 'order',
                        subject_id: pendingPayMongoOrderId,
                        channel: 'pos',
                        csrf_token: POS_CSRF_TOKEN
                    })
                });
                const data = await response.json();
                if (!response.ok || !data.success || !data.payment) throw new Error(data.message || 'A new QR could not be generated.');
                openPayMongoPosModal(pendingPayMongoOrderId, data.payment, posQrphFlowEpoch);
            } catch (error) {
                document.getElementById('paymongo-pos-status').textContent = error.message || 'A new QR could not be generated.';
            } finally {
                retryButton.disabled = false;
            }
        }

        function openPayMongoPosModal(orderId, payment, flowEpoch = posQrphFlowEpoch) {
            if (!posQrphFlowStillValid(flowEpoch)) {
                return;
            }
            const modal = document.getElementById('paymongo-pos-modal');
            const completeButton = document.getElementById('paymongo-pos-complete');
            completeButton.disabled = true;
            completeButton.style.background = '#94a3b8';
            completeButton.style.cursor = 'not-allowed';
            pendingPayMongoReceipt = null;
            pendingPayMongoPrintJob = null;
            pendingPayMongoOrderId = Number(orderId);
            paymongoModalEpoch = flowEpoch;
            sessionStorage.setItem('pos_paymongo_pending', JSON.stringify({order_id: pendingPayMongoOrderId}));
            renderPayMongoPosPayment(payment);
            modal.style.display = 'flex';
            if (paymongoPollTimer) window.clearInterval(paymongoPollTimer);
            const finishPaidPosTransaction = async (data) => {
                stopPayMongoPosPolling();
                if (data?.receipt_available && data?.receipt) {
                    pendingPayMongoReceipt = data.receipt;
                    pendingPayMongoPrintJob = data.print_job || null;
                    sessionStorage.removeItem('pos_paymongo_pending');
                    sessionStorage.removeItem('pos_paymongo_checkout_token');
                    sessionStorage.removeItem('pos_paymongo_checkout_token_total');
                    closePayMongoPosModal({ fullReset: true });
                    openReceiptModal(data.receipt);
                    await clearPosCartAfterSuccessfulSale();
                    pendingPayMongoOrderId = 0;
                    return true;
                }
                if (data?.can_complete) {
                    document.getElementById('paymongo-pos-status').textContent = 'Payment confirmed. Complete the transaction to issue the receipt.';
                    completeButton.disabled = false;
                    completeButton.style.background = '#059669';
                    completeButton.style.cursor = 'pointer';
                }
                return false;
            };
            const poll = async () => {
                if (paymongoModalEpoch !== posQrphFlowEpoch || !Array.isArray(cart) || cart.length === 0) {
                    stopPayMongoPosPolling();
                    clearPayMongoPosModalPresentation();
                    return;
                }
                try {
                    const url = staffUrl('staff/api/paymongo_payment.php')
                        + '?subject_type=order&subject_id=' + encodeURIComponent(orderId)
                        + '&channel=pos&_=' + Date.now();
                    const response = await fetch(url, {cache: 'no-store'});
                    const data = await response.json();
                    if (paymongoModalEpoch !== posQrphFlowEpoch) {
                        stopPayMongoPosPolling();
                        return;
                    }
                    if (data.success && data.payment) {
                        renderPayMongoPosPayment(data.payment);
                        if (['failed', 'expired', 'cancelled'].includes(String(data.payment.status || '').toLowerCase())) {
                            if (paymongoPollTimer) window.clearInterval(paymongoPollTimer);
                            paymongoPollTimer = null;
                            return;
                        }
                    }
                    if (data.success && data.payment && data.payment.status === 'paid') {
                        const completed = await finishPaidPosTransaction(data);
                        if (completed) {
                            return;
                        }
                    }
                } catch (error) {
                    // Keep polling; transient network failures do not change payment state.
                }
            };
            poll();
            paymongoPollTimer = window.setInterval(poll, 4000);
        }

        async function completePayMongoPosTransaction() {
            if (pendingPayMongoReceipt) {
                sessionStorage.removeItem('pos_paymongo_pending');
                sessionStorage.removeItem('pos_paymongo_checkout_token');
                sessionStorage.removeItem('pos_paymongo_checkout_token_total');
                closePayMongoPosModal({ fullReset: true });
                openReceiptModal(pendingPayMongoReceipt);
                await clearPosCartAfterSuccessfulSale();
                pendingPayMongoOrderId = 0;
                return;
            }
            if (pendingPayMongoOrderId <= 0) return;
            const completeButton = document.getElementById('paymongo-pos-complete');
            completeButton.disabled = true;
            document.getElementById('paymongo-pos-status').textContent = 'Completing transaction…';
            try {
                const response = await fetch(staffUrl('staff/api/paymongo_payment.php'), {
                    method: 'POST',
                    headers: {'Content-Type': 'application/json'},
                    body: JSON.stringify({
                        action: 'complete_pos',
                        subject_type: 'order',
                        subject_id: pendingPayMongoOrderId,
                        channel: 'pos',
                        csrf_token: POS_CSRF_TOKEN
                    })
                });
                const data = await response.json();
                if (!response.ok || !data.success || !data.receipt) {
                    throw new Error(data.message || 'The transaction could not be completed.');
                }
                stopPayMongoPosPolling();
                pendingPayMongoReceipt = data.receipt;
                pendingPayMongoPrintJob = data.print_job || null;
                sessionStorage.removeItem('pos_paymongo_pending');
                sessionStorage.removeItem('pos_paymongo_checkout_token');
                sessionStorage.removeItem('pos_paymongo_checkout_token_total');
                closePayMongoPosModal({ fullReset: true });
                openReceiptModal(data.receipt);
                await clearPosCartAfterSuccessfulSale();
                pendingPayMongoOrderId = 0;
            } catch (error) {
                completeButton.disabled = false;
                document.getElementById('paymongo-pos-status').textContent = error.message;
            }
        }

        function openNewCustomerModal() {
            document.getElementById('customer-modal').style.display = 'flex';
            setTimeout(function() {
                var first = document.getElementById('nc-first');
                if (first) first.focus();
            }, 50);
        }
        function closeCustomerModal() {
            document.getElementById('customer-modal').style.display = 'none';
        }
        async function saveCustomer() {
            const first = document.getElementById('nc-first').value.trim();
            const last = document.getElementById('nc-last').value.trim();
            const email = document.getElementById('nc-email').value.trim();
            const phone = document.getElementById('nc-phone').value.trim();

            if (!email) {
                await showPOSAlert('Missing Info', 'Email address is required.', 'warning');
                document.getElementById('nc-email').focus();
                return;
            }

            // Email validation
            const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
            if (!emailRegex.test(email)) {
                await showPOSAlert('Invalid Email', 'Please enter a valid email address.', 'warning');
                document.getElementById('nc-email').focus();
                return;
            }

            if (posSaveCustomerInFlight) {
                console.log('[POS] saveCustomer: ignored — request already in progress');
                return;
            }

            const btn = document.getElementById('nc-save-btn');
            posSaveCustomerInFlight = true;
            btn.disabled = true;
            btn.textContent = 'Creating customer...';

            let successAlert = null;
            let errorAlert = null;

            try {
                const apiUrl = staffUrl('staff/api/pos_add_customer.php');
                console.log('[POS] saveCustomer: request start', apiUrl);

                const res = await fetchWithTimeout(apiUrl, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    credentials: 'same-origin',
                    body: JSON.stringify({
                        first_name: first,
                        last_name: last,
                        email: email,
                        contact_number: phone,
                        csrf_token: POS_CSRF_TOKEN
                    })
                }, 45000);

                console.log('[POS] saveCustomer: response status', res.status);

                const raw = await res.text();
                // TEMP DEBUG: remove after Add Customer failure is diagnosed
                console.log('[POS] saveCustomer: raw response', raw);

                let data;
                try {
                    data = JSON.parse(raw);
                } catch (parseErr) {
                    throw new Error(raw && raw.trim() ? raw.trim().slice(0, 240) : 'Invalid server response.');
                }
                if (data.success) {
                    const linkedExisting = !!data.linked_existing;
                    const resolvedFirst = (data.first_name || first || '').trim();
                    const resolvedLast = (data.last_name || last || '').trim();
                    const sel = $('#pos-customer');
                    const existingOpt = sel.find('option[value="' + String(data.customer_id) + '"]');
                    if (existingOpt.length) {
                        existingOpt
                            .attr('data-first', resolvedFirst)
                            .attr('data-last', resolvedLast)
                            .attr('data-email', email)
                            .attr('data-phone', phone);
                    } else {
                        const opt = $('<option></option>')
                            .attr('value', data.customer_id)
                            .attr('data-first', resolvedFirst)
                            .attr('data-last', resolvedLast)
                            .attr('data-email', email)
                            .attr('data-phone', phone);
                        const labelName = (resolvedFirst + ' ' + resolvedLast).trim() || email;
                        opt.text(labelName + ' - ' + email);
                        sel.append(opt);
                    }
                    sel.val(String(data.customer_id)).trigger('change');
                    closeCustomerModal();

                    document.getElementById('nc-first').value = '';
                    document.getElementById('nc-last').value = '';
                    document.getElementById('nc-email').value = '';
                    document.getElementById('nc-phone').value = '';

                    const msg = data.message
                        || (linkedExisting
                            ? 'The existing customer account was selected for this order.'
                            : `Customer created successfully!\n\nA password setup email will be sent to ${email}.\nThe customer can use this email to create their account password.`);
                    successAlert = {
                        title: linkedExisting ? 'Customer Linked' : 'Customer Created',
                        message: msg,
                        type: 'success'
                    };
                    console.log('[POS] saveCustomer: success', data.customer_id);
                } else {
                    errorAlert = {
                        title: 'Could Not Create Customer',
                        message: data.message || 'Unknown error',
                        type: 'error'
                    };
                }
            } catch (e) {
                console.error('[POS] saveCustomer: error', e);
                const isTimeout = e && (e.name === 'AbortError' || String(e.message || '').toLowerCase().includes('abort'));
                errorAlert = {
                    title: isTimeout ? 'Request Timed Out' : 'Network Error',
                    message: isTimeout
                        ? 'Creating the customer took too long. Check your connection and try again, or verify the customer was not already created.'
                        : (e.message || 'Network error. Please try again.'),
                    type: 'error'
                };
            } finally {
                posSaveCustomerInFlight = false;
            }

            if (successAlert) {
                console.log('[POS] saveCustomer: showing success alert');
                await showPOSAlert(successAlert.title, successAlert.message, successAlert.type);
            } else if (errorAlert) {
                console.log('[POS] saveCustomer: showing error alert');
                await showPOSAlert(errorAlert.title, errorAlert.message, errorAlert.type);
            }

            if (btn) {
                btn.textContent = 'Create Customer & Send Email';
                btn.disabled = false;
            }
        }

        // Expose key handlers globally for reliability (Turbo/SPA compatibility)
        window.confirmCustomization = confirmCustomization;
        window.closeCustomModal = closeCustomModal;
        window.confirmPrice = confirmPrice;
        window.closePriceModal = closePriceModal;
        window.processCheckout = processCheckout;
        window.addQuickService = addQuickService;
        window.addToCart = addToCart;
        window.closeVariantModal = closeVariantModal;
        window.confirmVariantSelection = confirmVariantSelection;
        window.togglePosOtherInput = togglePosOtherInput;
        window.updateQtyByCartIndex = updateQtyByCartIndex;
        window.validateCartQuantityInput = validateCartQuantityInput;
        window.commitCartQuantityInput = commitCartQuantityInput;
        window.removeByCartIndex = removeByCartIndex;
        window.clearCart = clearCart;

        async function redirectToSetPrice(index) {
            const item = cart[index];
            if (!item) return;

            // Validate customer is selected
            const customer = $('#pos-customer').val();
            if (!customer) {
                await showPOSAlert('Customer Required', 'Please select a customer first.', 'warning');
                return;
            }

            // Store cart state in session storage
            sessionStorage.setItem('pos_cart_state', JSON.stringify({
                cart: cart,
                customer: customer,
                guest_display_name: customer === 'guest' ? getPosGuestDisplayName() : '',
                item_index: index
            }));

            // Create a temporary customization entry
            const payload = {
                action: 'create_pending_customization',
                ...posCheckoutCustomerPayload(),
                csrf_token: POS_CSRF_TOKEN,
                item: {
                    id: item.product_id,
                    name: item.name,
                    qty: item.qty,
                    customization: item.customization || null,
                    is_service: item.is_service || false,
                    pos_cart_line_id: item.pos_cart_line_id || null
                }
            };

            try {
                const res = await fetch(staffUrl('staff/api/pos_checkout.php'), {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(payload)
                });
                const data = await res.json();
                if (data.success && data.order_id) {
                    await syncedCartAction('update_service_link', {
                        index,
                        pending_order_id: parseInt(data.order_id, 10) || 0,
                        customization_id: parseInt(data.customization_id, 10) || 0,
                        pending_order_item_id: parseInt(data.order_item_id, 10) || 0
                    }, { silentErrors: true });
                    if (cart[index] && cart[index].customization && parseInt(data.order_item_id, 10) > 0) {
                        cart[index].customization.pending_order_item_id = parseInt(data.order_item_id, 10);
                        cart[index].pending_order_item_id = parseInt(data.order_item_id, 10);
                    }

                    // Deep-link into the pricing/material flow using a POS-specific context.
                    const redirectUrl = new URL(<?php echo json_encode(BASE_PATH . '/staff/customizations.php'); ?>, window.location.origin);
                    redirectUrl.searchParams.set('mode', 'pos_pricing');
                    redirectUrl.searchParams.set('status', 'APPROVED');
                    redirectUrl.searchParams.set('source_order_id', data.order_id);
                    redirectUrl.searchParams.set('return_to_pos', '1');
                    window.location.href = redirectUrl.toString();
                } else {
                    await showPOSAlert('Error', 'Failed to create customization: ' + (data.message || 'Unknown error'), 'error');
                }
            } catch (e) {
                console.error('Error:', e);
                await showPOSAlert('Network Error', 'Network error. Please try again.', 'error');
            }
        }


        let posAlertResolve = null;

        function applyPOSModalIcon(type = 'info') {
            const iconCont = document.getElementById('pos-alert-icon-container');
            const icon = document.getElementById('pos-alert-icon');
            if (!iconCont || !icon) return;

            const presets = {
                error: { bg: '#fee2e2', color: '#ef4444', icon: 'fa-circle-exclamation' },
                warning: { bg: '#fef3c7', color: '#d97706', icon: 'fa-triangle-exclamation' },
                success: { bg: '#dcfce7', color: '#10b981', icon: 'fa-circle-check' },
                info: { bg: '#e0f2fe', color: '#0ea5e9', icon: 'fa-circle-info' },
                confirm: { bg: '#edf4fc', color: '#2f6fae', icon: 'fa-circle-check' },
                danger: { bg: '#fee2e2', color: '#ef4444', icon: 'fa-trash-can' },
            };
            const preset = presets[type] || presets.info;
            iconCont.style.background = preset.bg;
            icon.style.color = preset.color;
            icon.className = 'fas ' + preset.icon;
        }

        async function showPOSAlert(title, message, type = 'info') {
            return new Promise(resolve => {
                const overlay = document.getElementById('pos-alert-overlay');
                const box = document.getElementById('pos-alert-box');
                const titleEl = document.getElementById('pos-alert-title');
                const msgEl = document.getElementById('pos-alert-message');
                const cancelBtn = document.getElementById('pos-alert-cancel');
                const confirmBtn = document.getElementById('pos-alert-confirm');

                titleEl.textContent = title;
                msgEl.innerHTML = (message || "").replace(/\n/g, '<br>');
                cancelBtn.style.display = 'none';
                cancelBtn.disabled = false;
                confirmBtn.disabled = false;
                confirmBtn.textContent = 'OK';
                confirmBtn.style.background = 'var(--staff-pos-button-bg)';
                applyPOSModalIcon(type);

                overlay.style.display = 'flex';
                setTimeout(() => {
                    overlay.style.opacity = '1';
                    box.style.transform = 'translateY(0)';
                }, 10);

                confirmBtn.onclick = () => {
                    closePOSAlert();
                    resolve(true);
                };
            });
        }

        async function showPOSConfirm(title, message, confirmLabel = 'Confirm', variant = 'confirm') {
            return new Promise(resolve => {
                const overlay = document.getElementById('pos-alert-overlay');
                const box = document.getElementById('pos-alert-box');
                const titleEl = document.getElementById('pos-alert-title');
                const msgEl = document.getElementById('pos-alert-message');
                const cancelBtn = document.getElementById('pos-alert-cancel');
                const confirmBtn = document.getElementById('pos-alert-confirm');

                titleEl.textContent = title;
                msgEl.innerHTML = (message || "").replace(/\n/g, '<br>');
                cancelBtn.style.display = 'block';
                cancelBtn.disabled = false;
                confirmBtn.disabled = false;
                confirmBtn.textContent = confirmLabel;
                confirmBtn.style.background = variant === 'danger'
                    ? '#ef4444'
                    : 'var(--staff-pos-button-bg)';
                applyPOSModalIcon(variant === 'danger' ? 'danger' : (variant === 'warning' ? 'warning' : 'confirm'));

                overlay.style.display = 'flex';
                setTimeout(() => {
                    overlay.style.opacity = '1';
                    box.style.transform = 'translateY(0)';
                }, 10);

                cancelBtn.onclick = () => {
                    cancelBtn.disabled = true;
                    confirmBtn.disabled = true;
                    closePOSAlert();
                    resolve(false);
                };
                confirmBtn.onclick = () => {
                    confirmBtn.disabled = true;
                    cancelBtn.disabled = true;
                    closePOSAlert();
                    resolve(true);
                };
            });
        }

        function closePOSAlert() {
            const overlay = document.getElementById('pos-alert-overlay');
            const box = document.getElementById('pos-alert-box');
            overlay.style.opacity = '0';
            box.style.transform = 'translateY(20px)';
            setTimeout(() => {
                overlay.style.display = 'none';
            }, 200);
        }

        window.redirectToSetPrice = redirectToSetPrice;
        window.posOpenServiceFromCard = posOpenServiceFromCard;
        window.openServiceModal = openServiceModal;
        window.confirmServiceModal = confirmServiceModal;
        window.showPOSMode = showPOSMode;
        window.backToSelection = backToSelection;
    </script>

    <script>window.PFConfig = Object.assign({}, window.PFConfig || {}, { basePath: <?php echo json_encode(rtrim(defined('BASE_PATH') ? (string)BASE_PATH : '', '/')); ?> });</script>
    <?php $pos_pwa_js_ver = @filemtime(__DIR__ . '/../public/assets/js/pwa.js') ?: time(); ?>
    <script src="<?php echo htmlspecialchars(rtrim(defined('BASE_PATH') ? BASE_PATH : '', '/') . '/public/assets/js/pwa.js'); ?>?v=<?php echo (int)$pos_pwa_js_ver; ?>" defer></script>

</body>

</html>


