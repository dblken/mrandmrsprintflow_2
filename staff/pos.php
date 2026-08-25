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
require_once __DIR__ . '/../includes/runtime_config.php';
require_once __DIR__ . '/../includes/provider_payments.php';

$posPayMongoMode = printflow_paymongo_mode();
$posPayMongoAvailable = in_array($posPayMongoMode, ['test', 'live'], true)
    && printflow_paymongo_secret_key_for_mode($posPayMongoMode) !== '';
$posPayMongoQrphAvailable = $posPayMongoAvailable
    && in_array('qrph', printflow_paymongo_enabled_methods($posPayMongoMode), true);

// Load GCash QR from admin payment settings
$_pos_payment_cfg = printflow_load_runtime_config('payment_methods');
$_pos_gcash_qr = '';
foreach ($_pos_payment_cfg as $_pm) {
    if (strcasecmp(trim((string)($_pm['provider'] ?? '')), 'gcash') === 0 && !empty($_pm['file']) && !empty($_pm['enabled'])) {
        $_pos_gcash_qr = BASE_PATH . '/public/assets/uploads/qr/' . htmlspecialchars($_pm['file']);
        break;
    }
}

// Require staff or admin role
require_role(['Admin', 'Staff']);
printflow_require_staff_module('pos');

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
    $customers = db_query("SELECT customer_id, first_name, last_name, email, contact_number FROM customers ORDER BY first_name ASC, last_name ASC");
} catch (Exception $e) {
}

// Fetch Branches (for service modals)
$branches = [];
try {
    $branches = db_query("SELECT id, branch_name FROM branches WHERE status = 'Active' ORDER BY branch_name ASC");
} catch (Exception $e) {
}

// Fetch active services from DB (same source as customer/services.php)
$pos_services = [];
try {
    $pos_services = db_query("SELECT service_id, name, category FROM services WHERE status = 'Activated' ORDER BY name ASC") ?: [];
} catch (Exception $e) {
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($page_title); ?> - PrintFlow</title>
    <link rel="stylesheet" href="<?php echo htmlspecialchars(BASE_PATH . '/public/assets/css/output.css'); ?>">
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
            display: flex;
            background: #ffffff;
            border: none;
            overflow: hidden;
            margin: 0;
            min-height: 0;
            /* Critical for vertical scroll */
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
            padding: 20px;
            background: #ffffff;
            border-bottom: 1px solid #e2e8f0;
            display: flex;
            gap: 10px;
            align-items: center;
        }

        .pos-search-box {
            position: relative;
            flex: 1;
            max-width: 400px;
        }

        .pos-barcode-scan {
            display: flex;
            flex-direction: column;
            gap: 6px;
            flex: 0 1 300px;
            min-width: 240px;
        }

        .pos-barcode-scan label {
            font-size: 12px;
            font-weight: 700;
            color: #475569;
            line-height: 1;
        }

        .pos-barcode-box {
            position: relative;
        }

        .pos-barcode-box i {
            position: absolute;
            left: 14px;
            top: 50%;
            transform: translateY(-50%);
            color: #94a3b8;
        }

        .pos-search-box i {
            position: absolute;
            left: 14px;
            top: 50%;
            transform: translateY(-50%);
            color: #94a3b8;
        }

        .pos-search-input {
            width: 100%;
            padding: 12px 16px 12px 36px;
            border: 1px solid #cbd5e1;
            border-radius: 8px;
            font-size: 14px;
            outline: none;
            transition: border-color 0.2s, box-shadow 0.2s, background 0.2s;
            height: 44px;
            background: #ffffff;
            color: #334155;
        }

        .pos-search-input:focus {
            border-color: var(--staff-primary);
            box-shadow: 0 0 0 3px rgba(var(--staff-accent-rgb), 0.12);
        }

        .pos-category-select {
            padding: 12px 16px;
            border: 1px solid #cbd5e1;
            border-radius: 8px;
            background: #ffffff;
            font-size: 14px;
            width: 160px;
            flex-shrink: 0;
            outline: none;
            cursor: pointer;
            height: 44px;
        }

        .pos-products-grid {
            flex: 1;
            overflow-y: auto;
            padding: 16px;
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(140px, 1fr));
            gap: 12px;
            align-content: start;
            background: #f8fafc;
        }

        /* Product Card */
        .pos-card {
            background: #ffffff;
            border: 1px solid #e2e8f0;
            border-radius: 14px;
            overflow: hidden;
            cursor: pointer;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            display: flex;
            flex-direction: column;
            box-shadow: 0 10px 24px rgba(15, 23, 42, 0.05);
            height: auto;
            min-height: 160px;
        }

        .pos-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 18px 34px rgba(15, 23, 42, 0.1);
            border-color: var(--staff-primary);
        }

        .pos-card.no-stock {
            opacity: 0.5;
            cursor: not-allowed;
            filter: grayscale(80%);
        }

        .pos-card.no-stock:hover {
            transform: none;
            box-shadow: none;
        }

        .pos-card-icon-container {
            width: 100%;
            min-height: 110px;
            position: relative;
            background: var(--staff-pos-button-bg);
            flex: 1;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            padding: 12px;
            gap: 8px;
        }

        .pos-card-price-top {
            background: rgba(255, 255, 255, 0.25);
            backdrop-filter: blur(8px);
            padding: 4px 10px;
            border-radius: 6px;
            font-weight: 700;
            color: white;
            font-size: 13px;
            border: 1px solid rgba(255, 255, 255, 0.3);
            text-shadow: 0 1px 2px rgba(0, 0, 0, 0.1);
        }

        .pos-card-product-name {
            font-family: var(--pf-ui-font-sans);
            font-size: 14px;
            font-weight: 700;
            color: white;
            text-align: center;
            line-height: 1.3;
            display: -webkit-box;
            -webkit-line-clamp: 2;
            -webkit-box-orient: vertical;
            overflow: hidden;
            word-break: break-word;
            text-shadow: 0 1px 2px rgba(0, 0, 0, 0.15);
        }



        .pos-card-body {
            padding: 8px 10px;
            display: flex;
            flex-direction: column;
            gap: 4px;
            background: white;
            flex-shrink: 0;
        }

        .pos-card-title {
            font-size: 11px;
            font-weight: 600;
            color: #64748b;
            text-align: center;
            line-height: 1.2;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        .pos-card-stock {
            font-size: 9px;
            color: #64748b;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 3px;
        }

        /* Right Side: Cart */
        .pos-cart-area {
            width: 420px;
            background: #ffffff;
            display: flex;
            flex-direction: column;
            flex-shrink: 0;
            border-left: 1px solid #e2e8f0;
            min-height: 0;
        }

        .pos-cart-header {
            padding: 20px;
            border-bottom: 1px solid #e2e8f0;
            display: flex;
            justify-content: space-between;
            align-items: center;
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
        }

        .pos-customer-label {
            display: flex;
            justify-content: space-between;
            margin-bottom: 8px;
            font-size: 12px;
            font-weight: 700;
            color: #64748b;
            text-transform: uppercase;
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
            flex: 1;
            overflow-y: auto;
            padding: 16px 20px;
        }

        .pos-empty-state {
            height: 100%;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            color: #94a3b8;
            text-align: center;
        }

        .pos-empty-state i {
            font-size: 48px;
            margin-bottom: 16px;
            color: #cbd5e1;
        }

        .pos-cart-item {
            display: flex;
            align-items: center;
            padding: 10px 14px;
            border: 1px solid #e2e8f0;
            border-radius: 10px;
            margin-bottom: 8px;
            background: #fff;
            box-shadow: 0 6px 18px rgba(15, 23, 42, 0.04);
            transition: all 0.2s;
        }

        .pos-cart-item:hover {
            border-color: var(--staff-primary);
            background: #f8fafc;
        }

        .pos-item-details {
            flex: 1;
            padding-right: 12px;
            min-width: 0;
        }

        .pos-item-name {
            font-size: 14px;
            font-weight: 600;
            color: #1e293b;
            margin-bottom: 4px;
            line-height: 1.2;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .pos-item-price {
            font-size: 12px;
            color: #64748b;
        }

        .pos-item-controls {
            display: flex;
            align-items: center;
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 6px;
            overflow: hidden;
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
            width: 30px;
            text-align: center;
            font-size: 14px;
            font-weight: 600;
            border: none;
            background: transparent;
            pointer-events: none;
        }

        .pos-item-total {
            font-weight: 700;
            font-size: 14px;
            min-width: 60px;
            text-align: right;
            margin-left: 12px;
        }

        .pos-item-remove {
            color: #ef4444;
            background: none;
            border: none;
            cursor: pointer;
            margin-left: 12px;
            padding: 4px;
            opacity: 0.6;
        }

        .pos-item-remove:hover {
            opacity: 1;
        }

        .pos-checkout-section {
            padding: 16px 20px;
            background: #f8fafc;
            border-top: 1px solid #e2e8f0;
            flex-shrink: 0;
        }

        @media (max-height: 800px) {
            .pos-checkout-section {
                padding: 12px 20px;
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
            margin-bottom: 8px;
            font-size: 14px;
            color: #475569;
        }

        .pos-summary-total {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-top: 16px;
            padding-top: 16px;
            border-top: 1px dashed #cbd5e1;
            font-size: 20px;
            font-weight: 800;
            color: #1e293b;
        }



        .pos-tender-group {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }

        .pos-tender-input {
            width: 140px;
            padding: 10px;
            border: 1px solid #cbd5e1;
            border-radius: 8px;
            text-align: right;
            font-weight: 700;
            font-size: 16px;
            outline: none;
        }

        .pos-tender-input:focus {
            border-color: var(--staff-primary);
            box-shadow: 0 0 0 3px rgba(var(--staff-accent-rgb), 0.12);
        }



        .pos-btn-checkout {
            width: 100%;
            padding: 16px;
            background: var(--staff-pos-button-bg);
            color: white;
            border: none;
            border-radius: 12px;
            font-size: 16px;
            font-weight: 700;
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 8px;
            cursor: pointer;
            transition: all 0.2s;
            box-shadow: 0 12px 26px var(--staff-pos-button-shadow);
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
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 12px;
            padding: 24px;
            align-content: start;
            height: 100%;
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
        .pos-cart-list::-webkit-scrollbar {
            width: 6px;
        }

        .pos-products-grid::-webkit-scrollbar-thumb,
        .pos-cart-list::-webkit-scrollbar-thumb {
            background: #cbd5e1;
            border-radius: 3px;
        }

        /* Select2 Custom Styling */
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

        /* Custom Alert/Confirm Modal */
        #pos-alert-overlay {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(0, 0, 0, 0.4);
            backdrop-filter: blur(4px);
            z-index: 3000;
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

        /* Mobile & Tablet Responsive Layout */
        @media (max-width: 1024px) {
            /* The POS wrapper is the single mobile scroll container. */
            .main-content {
                overflow-y: hidden !important;
            }

            .pos-wrapper {
                flex-direction: column !important;
                overflow-y: auto !important;
                overflow-x: hidden !important;
                display: flex !important;
                height: auto !important;
                min-height: 0 !important;
                overscroll-behavior-y: contain;
                -webkit-overflow-scrolling: touch;
            }

            .pos-products-area {
                flex: none !important;
                height: auto !important;
                min-height: auto !important;
                overflow: visible !important;
                border-right: none;
                border-bottom: 4px solid #e2e8f0;
            }

            #selection-view,
            #products-view,
            #services-view {
                height: auto !important;
                min-height: 0 !important;
            }

            .pos-products-grid,
            .pos-services-grid {
                flex: none !important;
                min-height: 0 !important;
                overflow: visible !important;
            }

            .pos-cart-area {
                width: 100%;
                border-left: none;
                flex: none !important;
                height: auto;
                min-height: 0 !important;
            }
            .pos-cart-list {
                min-height: 350px;
                overflow: visible !important;
            }
            
            /* Fix squished headers */
            .pos-search-header {
                flex-direction: column;
                align-items: stretch !important;
                padding: 16px !important;
                gap: 12px !important;
            }
            .pos-search-box,
            .pos-barcode-scan {
                max-width: none !important;
                width: 100%;
            }
            .pos-search-header .pos-category-select,
            .pos-search-header button {
                width: 100% !important;
                justify-content: center;
            }
            
            .pos-services-header {
                flex-direction: column;
                align-items: stretch !important;
                text-align: center;
                gap: 16px !important;
                padding: 16px !important;
            }
            .pos-services-header button {
                width: 100% !important;
                justify-content: center;
            }
            
            /* Fix grid squishing */
            .pos-services-grid {
                grid-template-columns: 1fr 1fr !important;
                padding: 16px !important;
                gap: 8px !important;
            }
        }
    </style>
</head>

<body data-turbo="false" data-csrf="<?php echo htmlspecialchars(generate_csrf_token(), ENT_QUOTES, 'UTF-8'); ?>">

    <div class="dashboard-container">
        <?php
        if ($_SESSION['user_type'] === 'Staff') {
            include __DIR__ . '/../includes/staff_sidebar.php';
        } else {
            include __DIR__ . '/../includes/admin_sidebar.php';
        }
        ?>

        <div class="main-content"
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
                                        <i class="fas fa-barcode"></i>
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
                        <div id="products-view" style="display: none; height: 100%; flex-direction: column;">
                            <div class="pos-search-header">
                                <div class="pos-barcode-scan">
                                    <label for="pos-barcode-input">Scan Barcode or Enter SKU</label>
                                    <div class="pos-barcode-box">
                                        <i class="fas fa-barcode"></i>
                                        <input type="text" id="pos-barcode-input" class="pos-search-input pos-barcode-entry"
                                            placeholder="Scan or type product SKU, then press Enter" autocomplete="off" inputmode="text">
                                    </div>
                                </div>
                                <div class="pos-search-box">
                                    <i class="fas fa-search"></i>
                                    <input type="text" id="pos-search" class="pos-search-input"
                                        placeholder="Search products...">
                                </div>
                                <select id="pos-category" class="pos-category-select">
                                    <option value="">All Categories</option>
                                    <?php foreach ($categories as $cat): ?>
                                        <option value="<?= htmlspecialchars($cat['category']) ?>">
                                            <?= htmlspecialchars($cat['category']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <div style="flex: 1;"></div>
                                <button onclick="backToSelection()" class="pos-category-select"
                                    style="min-width: auto; padding: 12px 20px; background: #f8fafc; border-color: #e2e8f0; color:#475569; cursor: pointer; width: auto; display: flex; align-items: center; gap: 8px;"
                                    title="Back to selection">
                                    <i class="fas fa-arrow-left"></i> <span>Back</span>
                                </button>
                            </div>
                            <div class="pos-products-grid" id="pos-products-grid"></div>
                        </div>

                        <!-- Services View -->
                        <div id="services-view" style="display: none; height: 100%; flex-direction: column;">
                            <div class="pos-services-header"
                                style="padding: 24px; border-bottom: 1px solid #e2e8f0; background: #fff; display: flex; justify-content: space-between; align-items: center;">
                                <div>
                                    <h2 style="font-weight:700; font-size:18px; color:#1e293b; margin:0;">Available
                                        Services</h2>
                                    <p style="font-size:13px; color:#64748b; margin-top:4px;">Quickly add a printing
                                        service to the order.</p>
                                </div>
                                <button onclick="backToSelection()" class="pos-category-select"
                                    style="min-width: auto; padding: 12px 16px; background: #f8fafc; border-color: #e2e8f0; color:#475569; cursor: pointer;"
                                    title="Back to selection">
                                    <i class="fas fa-arrow-left"></i> Back
                                </button>
                            </div>

                            <div class="pos-services-grid" style="border-bottom:none; padding: 24px; overflow-y: auto;">
                                <?php if (empty($pos_services)): ?>
                                    <div style="grid-column:1/-1;text-align:center;color:#64748b;padding:2rem;">No services
                                        available.</div>
                                <?php else: ?>
                                    <?php foreach ($pos_services as $svc): ?>
                                        <button type="button" class="service-btn"
                                            onclick="openServiceModal(<?php echo (int) $svc['service_id']; ?>, '<?php echo addslashes($svc['name']); ?>'); setActiveService(this)"
                                            data-service="<?php echo htmlspecialchars($svc['name']); ?>"
                                            title="<?php echo htmlspecialchars($svc['name']); ?>">
                                            <span><?php echo htmlspecialchars($svc['name']); ?></span>
                                        </button>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <!-- RIGHT: CART -->
                    <div class="pos-cart-area">
                        <div class="pos-cart-header">
                            <h2>Current Order</h2>
                            <button class="pos-btn-clear" onclick="clearCart()"><i class="fas fa-trash"></i>
                                Clear</button>
                        </div>

                        <div class="pos-customer-section">
                            <div class="pos-customer-label">
                                <span>Customer *</span>
                                <button class="pos-btn-link" onclick="openNewCustomerModal()">+ New</button>
                            </div>
                            <select id="pos-customer" class="pos-category-select" style="width: 100%; min-width: unset;"
                                required>
                                <option value="">-- Select Customer --</option>
                                <option value="guest">Walk-in Customer (Guest)</option>
                                <?php foreach ($customers as $c): ?>
                                    <option value="<?= $c['customer_id'] ?>">
                                        <?= htmlspecialchars($c['first_name'] . ' ' . $c['last_name']) ?>
                                        <?= !empty($c['email']) ? ' - ' . htmlspecialchars($c['email']) : (!empty($c['contact_number']) ? ' - ' . htmlspecialchars($c['contact_number']) : ' - No contact') ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="pos-cart-list" id="pos-cart-items">
                            <div class="pos-empty-state">
                                <i class="fas fa-shopping-basket"></i>
                                <p>Cart is empty</p>
                            </div>
                        </div>

                        <div class="pos-checkout-section">
                            <div class="pos-summary-line">
                                <span>Subtotal</span>
                                <span id="pos-subtotal">₱0.00</span>
                            </div>

                            <div class="pos-summary-total">
                                <span id="pos-total">₱0.00</span>
                            </div>

                            <div class="pos-tender-group" style="margin-bottom: 12px;">
                                <span style="font-weight: 600; font-size: 14px; color: #475569;">Payment Method</span>
                                <select id="pos-payment-method" class="pos-category-select"
                                    style="min-width: 140px; text-align: right; padding: 10px;"
                                    onchange="toggleReferenceField()">
                                    <option value="Cash">Cash</option>
                                    <option value="GCash">GCash</option>
                                    <?php if ($posPayMongoQrphAvailable): ?><option value="PayMongo QRPh">PayMongo QR Ph</option><?php endif; ?>
                                    <?php if ($posPayMongoAvailable): ?><option value="PayMongo Checkout">PayMongo Checkout</option><?php endif; ?>
                                </select>
                            </div>



                            <div class="pos-tender-group" id="tender-group">
                                <span style="font-weight: 600; font-size: 14px; color: #475569;">Amount Paid</span>
                                <div style="position: relative;">
                                    <span
                                        style="position: absolute; left: 12px; top: 12px; font-weight: 600; color: #94a3b8;">₱</span>
                                    <input type="number" id="pos-tendered" name="amount_tendered"
                                        class="pos-tender-input" placeholder="0.00" oninput="calculateChange()"
                                        style="padding-left: 28px;">
                                </div>
                            </div>

                            <div class="pos-summary-line" id="change-group"
                                style="margin-bottom: 20px; align-items: center;">
                                <span style="font-weight: 600; color: #475569;">Change</span>
                                <span id="pos-change"
                                    style="font-size: 20px; font-weight: 800; color: var(--staff-primary);">₱0.00</span>
                            </div>

                            <button class="pos-btn-checkout" id="pos-checkout-btn" disabled onclick="processCheckout()">
                                <i class="fas fa-lock" id="checkout-icon"></i> <span id="checkout-text">Select
                                    Items</span>
                            </button>
                        </div>
                    </div>

                </div> <!-- END pos-wrapper -->
            </main>
        </div>
    </div>

    <!-- GCash QR Modal -->
    <?php if (!empty($_pos_gcash_qr)): ?>
    <div id="gcash-qr-modal" style="display:none; position:fixed; inset:0; background:rgba(0,0,0,0.7); z-index:10000; align-items:center; justify-content:center;">
        <div style="background:#fff; border-radius:20px; padding:28px 24px; text-align:center; box-shadow:0 25px 50px -12px rgba(0,0,0,0.25); position:relative; max-width:320px; width:90%;">
            <button onclick="closeGcashQr()" style="position:absolute; top:12px; right:14px; background:none; border:none; font-size:22px; cursor:pointer; color:#94a3b8; line-height:1;" onmouseover="this.style.color='#1e293b'" onmouseout="this.style.color='#94a3b8'">&times;</button>
            <div style="font-size:13px; font-weight:700; color:#64748b; text-transform:uppercase; letter-spacing:0.05em; margin-bottom:14px;">GCash QR Code</div>
            <img src="<?php echo $_pos_gcash_qr; ?>" alt="GCash QR" style="width:220px; height:220px; object-fit:contain; border:1px solid #e2e8f0; border-radius:12px; display:block; margin:0 auto 16px;">
            <button onclick="closeGcashQr()" style="width:100%; padding:11px; background:#00232b; color:#fff; border:none; border-radius:10px; font-weight:700; font-size:14px; cursor:pointer;">Close</button>
        </div>
    </div>
    <?php endif; ?>

    <div id="paymongo-pos-modal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.72);z-index:10020;align-items:center;justify-content:center;padding:16px;">
        <div style="background:#fff;width:min(360px,100%);padding:24px;text-align:center;box-shadow:0 24px 60px rgba(0,0,0,.28);">
            <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:12px;">
                <strong id="paymongo-pos-title" style="color:#0f172a;">PayMongo Payment</strong>
                <?php if ($posPayMongoMode === 'test'): ?><span style="padding:3px 7px;background:#fef3c7;color:#92400e;font-size:9px;font-weight:800;text-transform:uppercase;">Test Mode</span><?php endif; ?>
            </div>
            <div id="paymongo-pos-order" style="font-size:13px;font-weight:800;color:#0f172a;margin-bottom:10px;"></div>
            <img id="paymongo-pos-qr" alt="PayMongo QR Ph payment code" style="display:none;width:220px;height:220px;object-fit:contain;margin:0 auto 10px;border:1px solid #e2e8f0;">
            <div id="paymongo-pos-countdown" style="font-size:12px;font-weight:800;color:#0f766e;margin-bottom:8px;min-height:18px;"></div>
            <div id="paymongo-pos-status" style="font-size:13px;color:#475569;margin-bottom:14px;">Waiting for payment confirmation.</div>
            <div id="paymongo-pos-reference" style="font-size:12px;color:#0f766e;font-weight:700;margin-bottom:14px;min-height:16px;"></div>
            <a id="paymongo-pos-open" href="#" target="_blank" rel="noopener noreferrer" style="display:block;padding:11px;background:#00232b;color:#fff;text-decoration:none;font-weight:800;margin-bottom:9px;">Open Checkout</a>
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
            <div id="sm-footer-actions"
                style="display:none;padding:16px 24px;border-top:1px solid #e2e8f0;background:#f8fafc;flex-shrink:0;">
                <div style="display:flex;gap:10px;">
                    <button onclick="closeServiceModal()"
                        style="flex:1;padding:12px;border:1px solid #cbd5e1;border-radius:10px;background:#ffffff;color:#475569;font-weight:700;cursor:pointer;font-size:14px;"
                        onmouseover="this.style.background='#f8fafc';this.style.borderColor='#94a3b8';this.style.color='#334155'"
                        onmouseout="this.style.background='#ffffff';this.style.borderColor='#cbd5e1';this.style.color='#475569'">Cancel</button>
                    <button id="sm-add-to-order-btn" onclick="confirmServiceModal()"
                        style="flex:2;padding:12px;border:none;border-radius:10px;background:#00232b;color:#fff;font-weight:700;cursor:pointer;font-size:14px;box-shadow:0 10px 24px rgba(0,35,43,0.28);"
                        onmouseover="this.style.background='#003a47'" onmouseout="this.style.background='#00232b'">Add
                        to Order</button>
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
                </div>
            </div>
            <div class="receipt-modal-body">
                <div id="receipt-print-area" class="receipt-sheet"></div>
            </div>
        </div>
    </div>

    <!-- Modal for New Customer -->
    <div id="customer-modal"
        style="display:none; position:fixed; inset:0; background:rgba(0,0,0,0.75); z-index:999; align-items:center; justify-content:center;">
        <div
            style="background:#ffffff; width:450px; border-radius:20px; padding:28px; box-shadow:0 25px 50px -12px rgba(0,0,0,0.15); color:#1e293b; border:1px solid #e2e8f0;">
            <div style="display:flex; justify-content:space-between; margin-bottom:24px;">
                <h3 style="margin:0; font-weight:800; color:#0f172a; font-size:20px; letter-spacing:-0.02em;">Add
                    Customer</h3>
                <button onclick="closeCustomerModal()"
                    style="background:none; border:none; font-size:24px; cursor:pointer; color:#94a3b8; padding:4px;"
                    onmouseover="this.style.color='#1e293b'" onmouseout="this.style.color='#94a3b8'">&times;</button>
            </div>
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
            <button onclick="saveCustomer()" id="nc-save-btn"
                style="width:100%; background:var(--staff-pos-button-bg); color:white; padding:14px; border:none; border-radius:12px; font-weight:700; cursor:pointer; box-shadow:0 12px 24px rgba(15,23,42,0.14); transition:all 0.2s;"
                onmouseover="this.style.filter='brightness(0.98)'" onmouseout="this.style.filter='none'">Create
                Customer & Send Email</button>
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

    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
    <script>
        window.POS_BRANCHES = <?php echo json_encode(array_map(function ($b) {
            return ['id' => (int) $b['id'], 'name' => $b['branch_name']];
        }, $branches ?: [])); ?>;

        let products = [];
        let cart = [];
        let currentTotal = 0;
        let currentMode = null; // 'products' or 'services'
        let barcodeScanBusy = false;
        let isAddingToOrder = false;
        const STAFF_BASE_PATH = <?php echo json_encode(BASE_PATH); ?>;
        const POS_CSRF_TOKEN = document.body.dataset.csrf || '';
        let paymongoPollTimer = null;
        let paymongoCountdownTimer = null;
        let pendingPayMongoPayment = null;
        let pendingPayMongoReceipt = null;
        let pendingPayMongoOrderId = 0;
        let posCheckoutRequestInFlight = false;
        let posCheckoutConfirmOpen = false;
        let pendingPayMongoPrintJob = null;
        let posPayMongoCheckoutPending = false;
        let posPayMongoCheckoutAttemptToken = null;
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
            const discount = receipt?.discount || {};
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
            const discountLabel = discount.code
                ? `${escapeHtml(discount.code)}${discount.percent ? ` (${Number(discount.percent)}%)` : ''}`
                : 'No discount';

            return `
                <div class="receipt-header">
                    ${company.logo_url ? `<img src="${escapeHtml(company.logo_url)}" alt="${escapeHtml(company.name || 'Company')}" class="receipt-logo">` : ''}
                    <div class="receipt-brand-name">PrintFlow</div>
                    <div class="receipt-branch">${escapeHtml(company.branch_name || 'Main Branch')}</div>
                    <div class="receipt-company-meta">
                        ${company.address ? `<div>${escapeHtml(company.address)}</div>` : ''}
                        ${company.contact ? `<div>${escapeHtml(company.contact)}</div>` : ''}
                    </div>
                    <div class="receipt-pill">Official POS Receipt</div>
                </div>

                <div class="receipt-section">
                    <div class="receipt-section-title">Receipt Info</div>
                    ${receipt.qr_payload ? `<div class="receipt-qr-wrap"><div id="pos-receipt-qr"></div><div class="receipt-qr-caption">Scan for order details</div></div>` : ''}
                    <div class="receipt-info-grid">
                        <div class="receipt-info-card">
                            <div class="receipt-label">Receipt No.</div>
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
                        <div class="receipt-payment-chip">${escapeHtml(payment.method || 'Cash')}</div>
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
                        <div class="receipt-total-line"><span>Discount</span><strong>${formatMoney(discount.amount || 0)}</strong></div>
                        ${discount.code || discount.description ? `<div class="receipt-total-line" style="font-size:11px;color:#64748b;"><span>Discount Details</span><span>${discountLabel}</span></div>` : ''}
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
                    <p>Please keep this receipt for your records.</p>
                </div>
            `;
        }

        let activePosReceipt = null;
        let activePosPrintJob = null;
        let posReceiptPrintProcessing = false;

        function setPosReceiptPrintState(message = '', failed = false) {
            const status = document.getElementById('receipt-print-result');
            const button = document.getElementById('pos-print-receipt-btn');
            if (status) {
                status.textContent = message;
                status.style.color = failed ? '#b91c1c' : '#0f766e';
            }
            if (button) {
                button.disabled = posReceiptPrintProcessing;
                button.textContent = failed ? 'Retry Print' : (posReceiptPrintProcessing ? 'Printing...' : 'Print Receipt');
            }
        }

        function openReceiptModal(receipt) {
            const overlay = document.getElementById('receipt-modal-overlay');
            const printArea = document.getElementById('receipt-print-area');
            if (!overlay || !printArea) return;
            activePosReceipt = receipt || {};
            activePosPrintJob = null;
            posReceiptPrintProcessing = false;
            setPosReceiptPrintState('No physical receipt has been printed yet.');
            printArea.innerHTML = buildReceiptHtml(activePosReceipt);
            renderPosReceiptQr(receipt?.qr_payload);
            overlay.style.display = 'flex';
            document.body.style.overflow = 'hidden';
        }

        function renderPosReceiptQr(payload) {
            const target = document.getElementById('pos-receipt-qr');
            if (!target || !payload || typeof QRCode === 'undefined') return;
            target.innerHTML = '';
            new QRCode(target, { text: String(payload), width: 116, height: 116, correctLevel: QRCode.CorrectLevel.M });
        }

        function closeReceiptModal() {
            const overlay = document.getElementById('receipt-modal-overlay');
            if (!overlay) return;
            overlay.style.display = 'none';
            document.body.style.overflow = '';
        }

        async function printReceipt() {
            if (posReceiptPrintProcessing || !activePosReceipt?.order_id) return;
            posReceiptPrintProcessing = true;
            setPosReceiptPrintState('Sending receipt to POS-58...');
            try {
                if (activePosPrintJob?.job_id) {
                    await retryReceiptPrintJob(activePosPrintJob);
                    return;
                }
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
                await monitorReceiptPrintJob(result.print_job);
            } catch (error) {
                console.error('Receipt printing failed:', error);
                posReceiptPrintProcessing = false;
                setPosReceiptPrintState('Receipt printing failed.', true);
            }
        }

        async function retryReceiptPrintJob(printJob) {
            const jobId = Number(printJob?.job_id || 0);
            if (jobId <= 0) {
                await showPOSAlert(
                    'Receipt printing failed',
                    'The sale is complete, but no printable receipt job is available. Please contact an administrator.',
                    'error'
                );
                return;
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
                await monitorReceiptPrintJob(activePosPrintJob);
            } catch (error) {
                console.error('Receipt print retry failed:', error);
                posReceiptPrintProcessing = false;
                setPosReceiptPrintState('Receipt printing failed.', true);
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

        async function monitorReceiptPrintJob(printJob) {
            if (!printJob?.ok || !printJob?.job_id) {
                await showReceiptPrintFailure(printJob, printJob?.message || 'The receipt could not be queued for the configured printer.');
                return;
            }

            showPOSScanNotice('Transaction completed', 'Printing receipt...', 'success');
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
                        setPosReceiptPrintState('Receipt printed successfully.');
                        showPOSScanNotice('Transaction completed', 'Receipt printed successfully.', 'success');
                        return;
                    }
                    if (response.ok && status === 'failed') {
                        await showReceiptPrintFailure(
                            printJob,
                            result?.job?.error_message || 'PushPrinter reported that the receipt could not be printed.',
                            lastStatusResult
                        );
                        return;
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

        // Initialize Select2 for customer dropdown
        $(document).ready(function () {
            $('#pos-customer').select2({
                placeholder: '-- Select Customer --',
                allowClear: false,
                width: '100%',
                minimumResultsForSearch: 0 // Always show search box
            });

            // Set default to guest
            $('#pos-customer').val('guest').trigger('change');
        });

        function showPOSMode(mode) {
            currentMode = mode;
            document.getElementById('selection-view').style.display = 'none';

            if (mode === 'products') {
                document.getElementById('products-view').style.display = 'flex';
                document.getElementById('services-view').style.display = 'none';
                // Force re-render products to ensure they show with icons
                if (products.length > 0) {
                    renderProducts();
                } else {
                    fetchProducts();
                }
                setTimeout(focusBarcodeInput, 40);
            } else if (mode === 'services') {
                document.getElementById('products-view').style.display = 'none';
                document.getElementById('services-view').style.display = 'flex';
            }
        }

        function backToSelection() {
            currentMode = null;
            document.getElementById('selection-view').style.display = 'flex';
            document.getElementById('products-view').style.display = 'none';
            document.getElementById('services-view').style.display = 'none';
        }

        document.addEventListener('DOMContentLoaded', async () => {
            fetchProducts();
            refreshCart(); // Initialize cart from session
            const pendingPayMongo = sessionStorage.getItem('pos_paymongo_pending');
            if (pendingPayMongo) {
                try {
                    const savedPayment = JSON.parse(pendingPayMongo);
                    if (Number(savedPayment.order_id) > 0) {
                        resumePayMongoPosModal(Number(savedPayment.order_id));
                    }
                } catch (error) {
                    sessionStorage.removeItem('pos_paymongo_pending');
                }
            }
            const barcodeInputs = Array.from(document.querySelectorAll('.pos-barcode-entry'));
            const searchEl = document.getElementById('pos-search');
            const catEl = document.getElementById('pos-category');
            barcodeInputs.forEach(function(barcodeEl) {
                barcodeEl.addEventListener('keydown', function(e) {
                    if (!isBarcodeTerminatorKey(e.key)) return;
                    e.preventDefault();
                    handleBarcodeScan(barcodeEl.value, barcodeEl);
                });
                barcodeEl.addEventListener('paste', function() {
                    setTimeout(function() { barcodeEl.select(); }, 0);
                });
            });
            setTimeout(focusBarcodeInput, 80);
            if (searchEl) searchEl.addEventListener('input', renderProducts);
            if (catEl) catEl.addEventListener('change', renderProducts);

            // Check if returning from customizations page with updated price
            const urlParams = new URLSearchParams(window.location.search);
            if (urlParams.get('from_customizations') === '1') {
                // Restore customer selection if saved
                const savedState = sessionStorage.getItem('pos_cart_state');
                if (savedState) {
                    try {
                        const state = JSON.parse(savedState);
                        if (state.customer) {
                            $('#pos-customer').val(state.customer).trigger('change');
                        }
                        // Update cart item price if available
                        if (state.item_index !== undefined && state.updated_price !== undefined) {
                            await syncedCartAction('update_price', { 
                                index: state.item_index, 
                                price: state.updated_price 
                            });
                        }
                    } catch (e) { }
                    sessionStorage.removeItem('pos_cart_state');
                }
                // Cart price already updated in session — just refresh silently
                await refreshCart();
                // Clean URL
                window.history.replaceState({}, document.title, window.location.pathname);
            }
        });

        async function syncedCartAction(action, payload = {}, options = {}) {
            console.log('syncedCartAction:', action, payload);
            try {
                const response = await fetch(staffUrl('staff/api/pos_cart_handler.php'), {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ action, ...payload })
                });
                const data = await response.json();
                console.log('syncedCartAction Response:', data);
                if (data.success) {
                    cart = data.cart || [];
                    console.log('Updated local cart:', cart);
                    renderCart();
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

        async function fetchProducts() {
            const grid = document.getElementById('pos-products-grid');
            if (grid) {
                grid.innerHTML = '<div style="grid-column:1/-1; text-align:center; padding:40px; color:#94a3b8;"><i class="fas fa-spinner fa-spin" style="font-size:32px; margin-bottom:16px;"></i><br>Loading products...</div>';
            }
            try {
                const res = await fetch(staffUrl('staff/api/get_products.php'));
                const data = await res.json();
                console.log('Products API Response:', data);
                if (data.success) {
                    products = data.products || [];
                    console.log('Total products loaded:', products.length);
                    if (products.length > 0) {
                        console.log('Sample product:', products[0]);
                    }
                    if (grid) renderProducts();
                } else {
                    if (grid) grid.innerHTML = '<p style="color:red; text-align:center; padding:20px;">Failed to load products: ' + (data.message || 'Unknown error') + '</p>';
                }
            } catch (e) {
                console.error('Fetch error:', e);
                if (grid) grid.innerHTML = '<p style="color:red; text-align:center; padding:20px;">Network error: ' + e.message + '</p>';
            }
        }

        // ── Service Modal (DB-driven fields) ────────────────────────────────────────
        function getBranchField() {
            const branches = (window.POS_BRANCHES || []).map(b => ({ value: b.id, label: b.name }));
            const hasBranches = branches && branches.length > 0;
            return { label: 'Branch *', type: 'select', name: 'branch_id', options: branches, required: hasBranches };
        }

        async function openServiceModal(serviceId, serviceName) {
            console.log('openServiceModal called:', serviceId, serviceName);
            const overlay = document.getElementById('service-modal-overlay');
            const title = document.getElementById('sm-title');
            const body = document.getElementById('sm-fields-body');
            const footerActions = document.getElementById('sm-footer-actions');

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
                    s.addEventListener('change', function () {
                        if (typeof updateConditionalFields === 'function') updateConditionalFields();
                    });
                });
                bindServiceValidationClearers(body);
            } catch (e) {
                body.innerHTML = '<p style="color:#ef4444;text-align:center;padding:1rem;">Network error. Please try again.</p>';
            }
        }

        function closeServiceModal() {
            isAddingToOrder = false;
            setServiceAddButtonBusy(false);
            document.getElementById('service-modal-overlay').style.display = 'none';
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

        async function posStageMediaUpload(file, field = 'design') {
            const fd = new FormData();
            fd.append('field', field);
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

        async function posApplyStagedUpload(customization, file, field = 'design') {
            const staged = await posStageMediaUpload(file, field);
            if (field === 'reference') {
                customization.reference_upload = staged.name;
                customization.reference_upload_name = staged.name;
                customization.reference_upload_mime = staged.mime;
                customization.reference_upload_path = staged.path;
                delete customization.reference_upload_data;
            } else {
                customization.design_upload = staged.name;
                customization.design_upload_name = staged.name;
                customization.design_upload_mime = staged.mime;
                customization.design_upload_path = staged.path;
                delete customization.design_upload_data;
            }
            return staged;
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
                const label = row.querySelector('.shopee-form-label');
                if (!label) return;
                const labelText = label.innerText.replace('*', '').trim();
                const isRequired = label.innerText.includes('*');

                // Radio
                const checkedRadio = row.querySelector('input[type="radio"]:checked');
                if (checkedRadio) { customization[labelText] = checkedRadio.value; return; }

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

                // Text / number
                const textInput = row.querySelector('input[type="text"], input[type="number"]:not(#quantity-input)');
                if (textInput && !textInput.id.includes('hidden') && textInput.value.trim()) {
                    customization[labelText] = textInput.value.trim();
                }
            });

            const serviceFileInputs = Array.from(body.querySelectorAll('input[type="file"]'));
            for (const fileInput of serviceFileInputs) {
                if (!(fileInput.files && fileInput.files.length > 0)) {
                    continue;
                }
                const file = fileInput.files[0];
                const row = fileInput.closest('.shopee-form-row');
                const label = row ? row.querySelector('.shopee-form-label') : null;
                const labelText = label ? label.innerText.replace('*', '').trim() : (fileInput.name || 'File');

                const nameLc = String(fileInput.name || '').toLowerCase();
                const labelLc = String(labelText || '').toLowerCase();
                const isDesignField = nameLc === 'design_file'
                    || nameLc.includes('design')
                    || (labelLc.includes('upload') && labelLc.includes('design'));
                const isReferenceField = nameLc === 'reference_file'
                    || nameLc.includes('reference')
                    || (labelLc.includes('upload') && labelLc.includes('reference'));

                try {
                    if (isDesignField) {
                        await posApplyStagedUpload(customization, file, 'design');
                        customization[labelText] = customization.design_upload_name;
                    } else if (isReferenceField) {
                        await posApplyStagedUpload(customization, file, 'reference');
                        customization[labelText] = customization.reference_upload_name;
                    } else {
                        const payload = await posReadFilePayload(file);
                        customization[labelText] = payload.name;
                    }
                } catch (uploadErr) {
                    await showPOSAlert('Upload Failed', uploadErr.message || 'Could not save the design file.', 'error');
                    return;
                }
            }

            // Add service to cart with price = 0 (will be set in Customizations V2)
            const result = await syncedCartAction('add', {
                product_id: serviceId,
                name: serviceName,
                price: 0,
                qty: parseInt(customization['quantity'] || 1),
                customization: customization,
                is_service: true
            });

            if (result.success) closeServiceModal();
        }

        function setServiceAddButtonBusy(isBusy) {
            const btn = document.getElementById('sm-add-to-order-btn');
            if (!btn) return;
            btn.disabled = !!isBusy;
            btn.style.opacity = isBusy ? '0.7' : '1';
            btn.style.cursor = isBusy ? 'not-allowed' : 'pointer';
            btn.textContent = isBusy ? 'Adding...' : 'Add to Order';
        }

        function isServiceFieldVisible(row) {
            if (!row || row.hidden) return false;
            const style = window.getComputedStyle(row);
            if (style.display === 'none' || style.visibility === 'hidden') return false;
            const hiddenParent = row.parentElement ? row.parentElement.closest('[style*="display:none"], [style*="display: none"]') : null;
            return !hiddenParent;
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

        function serviceValidationMessage(row, input) {
            const label = serviceFieldLabel(row);
            const name = String((input && input.name) || serviceFieldKey(row) || label).toLowerCase();
            const type = input ? String(input.type || '').toLowerCase() : '';
            if (name.includes('design') || type === 'file') return 'Please upload a design.';
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

        function validateServiceOrderForm() {
            const body = document.getElementById('sm-fields-body');
            const errors = {};
            if (!body) return { valid: false, errors };

            const addError = (row, input, keyOverride) => {
                const key = keyOverride || serviceFieldKey(row, input ? input.name : '');
                if (!errors[key]) errors[key] = { row, field: key, message: serviceValidationMessage(row, input) };
            };

            body.querySelectorAll('.shopee-form-row').forEach(row => {
                if (!isServiceFieldVisible(row)) return;
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
                    if (!radios.some(radio => radio.checked)) addError(row, radios[0], name);
                });

                const dimensionInputs = requiredInputs.filter(input => input.type === 'hidden' && input.dataset.dimensionRole);
                if (dimensionInputs.length && dimensionInputs.some(input => !String(input.value || '').trim())) {
                    addError(row, dimensionInputs[0]);
                }

                requiredInputs.forEach(input => {
                    if (input.type === 'radio' || (input.type === 'hidden' && input.dataset.dimensionRole)) return;
                    const value = input.type === 'file'
                        ? (input.files && input.files.length > 0 ? input.files[0].name : '')
                        : String(input.value || '').trim();
                    if (input.name === 'quantity' || input.classList.contains('pf-service-quantity-input')) {
                        const qty = parseInt(value, 10);
                        if (!Number.isFinite(qty) || qty < 1) addError(row, input);
                        return;
                    }
                    if (!value) addError(row, input);
                });
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

            body.querySelectorAll('.shopee-form-row').forEach(row => {
                if (!isServiceFieldVisible(row)) return;

                const checkedRadio = row.querySelector('input[type="radio"]:checked');
                if (checkedRadio) setCustomizationValue(customization, row, checkedRadio, checkedRadio.value);

                const sel = row.querySelector('select:not([name="branch_id"])');
                if (sel && sel.value) setCustomizationValue(customization, row, sel, sel.value);

                const dateInput = row.querySelector('input[type="date"]');
                if (dateInput && dateInput.value) setCustomizationValue(customization, row, dateInput, dateInput.value);

                const qtyInput = row.querySelector('#quantity-input, .pf-service-quantity-input, input[name="quantity"]');
                if (qtyInput) customization.quantity = qtyInput.value || 1;

                const textarea = row.querySelector('textarea');
                if (textarea && textarea.value.trim()) setCustomizationValue(customization, row, textarea, textarea.value.trim());

                const wh = row.querySelector('[data-dimension-role="width"], #width_hidden');
                const hh = row.querySelector('[data-dimension-role="height"], #height_hidden');
                if (wh && hh && wh.value && hh.value) setCustomizationValue(customization, row, wh, wh.value + 'x' + hh.value);

                const textInput = row.querySelector('input[type="text"]:not(.pf-service-quantity-input), input[type="number"]:not(#quantity-input):not(.pf-service-quantity-input)');
                if (textInput && !textInput.id.includes('hidden') && textInput.value.trim()) {
                    setCustomizationValue(customization, row, textInput, textInput.value.trim());
                }
            });

            try {
                const serviceFileInputs = Array.from(body.querySelectorAll('input[type="file"]'));
                for (const fileInput of serviceFileInputs) {
                    if (!(fileInput.files && fileInput.files.length > 0)) continue;
                    const file = fileInput.files[0];
                    const row = fileInput.closest('.shopee-form-row');
                    const labelText = row ? serviceFieldLabel(row) : (fileInput.name || 'File');
                    const nameLc = String(fileInput.name || '').toLowerCase();
                    const labelLc = String(labelText || '').toLowerCase();
                    const isDesignField = nameLc === 'design_file' || nameLc.includes('design') || (labelLc.includes('upload') && labelLc.includes('design'));
                    const isReferenceField = nameLc === 'reference_file' || nameLc.includes('reference') || (labelLc.includes('upload') && labelLc.includes('reference'));

                    if (isDesignField) {
                        await posApplyStagedUpload(customization, file, 'design');
                        setCustomizationValue(customization, row, fileInput, customization.design_upload_name);
                    } else if (isReferenceField) {
                        await posApplyStagedUpload(customization, file, 'reference');
                        setCustomizationValue(customization, row, fileInput, customization.reference_upload_name);
                    } else {
                        const payload = await posReadFilePayload(file);
                        setCustomizationValue(customization, row, fileInput, payload.name);
                    }
                }
            } catch (uploadErr) {
                await showPOSAlert('Upload Failed', uploadErr.message || 'Could not save the design file.', 'error');
                isAddingToOrder = false;
                setServiceAddButtonBusy(false);
                return;
            }

            const result = await syncedCartAction('add', {
                product_id: serviceId,
                name: serviceName,
                price: 0,
                qty: parseInt(customization.quantity || 1, 10),
                customization: customization,
                is_service: true
            }, { silentErrors: true });

            if (result.success) {
                closeServiceModal();
            } else {
                if (result.errors) {
                    showBackendValidationErrors(result.errors);
                    focusFirstInvalidField(Object.fromEntries(Object.entries(result.errors).map(([key, message]) => {
                        const body = document.getElementById('sm-fields-body');
                        const escapedKey = serviceSelectorEscape(key);
                        const row = body ? (body.querySelector('.shopee-form-row[data-field-key="' + escapedKey + '"]') || body.querySelector('[name="' + escapedKey + '"]')?.closest('.shopee-form-row')) : null;
                        return [key, { row, message }];
                    })));
                }
                await showPOSAlert('Incomplete Fields', result.message || 'Some required order details are missing.', 'warning');
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

            filtered.forEach((p, index) => {
                const outOfStock = p.stock_quantity <= 0;

                const card = document.createElement('div');
                card.className = `pos-card ${outOfStock ? 'no-stock' : ''}`;
                if (!outOfStock) card.onclick = () => addToCart(p);

                const priceFormatted = formatMoney(p.price || 0);
                const productName = p.product_name || 'Unnamed Product';
                const stockQty = parseInt(p.stock_quantity) || 0;

                card.innerHTML = `
            <div class="pos-card-icon-container">
                <div class="pos-card-price-top">${priceFormatted}</div>
                <div class="pos-card-product-name">${productName}</div>
            </div>
            <div class="pos-card-body">
                <div class="pos-card-title">${p.category || 'Product'}</div>
                <div class="pos-card-stock">
                    <i class="fas ${outOfStock ? 'fa-times-circle' : 'fa-check-circle'}" style="color:${outOfStock ? '#ef4444' : 'var(--staff-primary)'}; font-size:8px;"></i>
                    <span>${outOfStock ? 'Out' : stockQty + ' left'}</span>
                </div>
            </div>
        `;
                grid.appendChild(card);
            });
        }

        function focusBarcodeInput(preferredInput = null) {
            const inputs = Array.from(document.querySelectorAll('.pos-barcode-entry'));
            const input = preferredInput || inputs.find(function(el) {
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

        async function handleBarcodeScan(code, sourceInput = null) {
            const barcodeEl = sourceInput || document.getElementById('pos-barcode-input') || document.getElementById('pos-barcode-input-home');
            const sku = String(code || '').replace(/[\r\n]+/g, '').trim();
            if (!sku) {
                finishBarcodeScan(barcodeEl);
                return;
            }
            if (barcodeScanBusy) return;
            barcodeScanBusy = true;
            document.querySelectorAll('.pos-barcode-entry').forEach(function(input) { input.disabled = true; });
            try {
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
                    const res = await fetch(staffUrl('staff/api/get_product_by_sku.php?sku=') + encodeURIComponent(sku));
                    const data = await res.json();
                    if (!data.success) {
                        showPOSScanNotice('Scan Error', data.message || 'Could not scan barcode.', 'error');
                        return;
                    }
                    product = data.product || null;
                    availability = data.availability || (product ? 'available' : null);
                    if (product && availability === 'available') {
                        const existingIndex = products.findIndex(p => String(p.product_id) === String(product.product_id));
                        if (existingIndex >= 0) products[existingIndex] = product;
                        else products.push(product);
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
            } finally {
                barcodeScanBusy = false;
                document.querySelectorAll('.pos-barcode-entry').forEach(function(input) { input.disabled = false; });
                finishBarcodeScan(barcodeEl);
            }
        }
        async function addToCart(p, overridePrice = null, overrideName = null, options = {}) {
            const name = overrideName || p.product_name;
            const price = overridePrice !== null ? overridePrice : parseFloat(p.price);

            if (p.price == 0 && overridePrice === null) {
                openPriceModal(p);
                return;
            }

            return await syncedCartAction('add', {
                product_id: p.product_id,
                name: name,
                price: price,
                qty: 1,
                is_service: false
            }, options);
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
                inputHtml = `<input type="file" id="custom_field_${idx}" name="${reqName}" accept="${(req.accept || '').replace(/"/g, '&quot;')}" style="${baseStyle}" data-field-name="${reqName}">`;
                div.innerHTML = label + inputHtml;
            } else if (req.type === 'date') {
                const minDate = new Date().toISOString().split('T')[0];
                inputHtml = `<input type="date" id="custom_field_${idx}" name="${reqName}" min="${minDate}" style="${baseStyle}" data-field-name="${reqName}">`;
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
                        await posApplyStagedUpload(customization, el.files[0], 'design');
                        customization[name] = customization.design_upload_name;
                    } else if (name === 'reference_file') {
                        await posApplyStagedUpload(customization, el.files[0], 'reference');
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
                if (pInput) {
                    price = parseFloat(pInput.value.replace(/,/g, ''));
                    if (isNaN(price) || price <= 0) {
                        await showPOSAlert('Invalid Price', 'Please enter a valid negotiated price.', 'warning');
                        pInput.focus();
                        return;
                    }
                } else {
                    // Fallback if input somehow didn't render
                    const p = pendingCustomProduct;
                    closeCustomModal();
                    openPriceModal(p, false, customization);
                    return;
                }
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

        async function updateQtyByCartIndex(index, delta) {
            const item = cart[index];
            if (!item) return;
            let newQty = parseInt(item.qty) + delta;
            if (newQty < 1) newQty = 1;
            if (newQty > 100) {
                await showPOSAlert('Quantity Limit', "Maximum quantity per item is 100.", 'warning');
                newQty = 100;
            }
            await syncedCartAction('update', { index, qty: newQty });
        }

        async function removeByCartIndex(index) {
            await syncedCartAction('remove', { index });
        }

        async function clearCart() {
            if (cart.length > 0 && (await showPOSConfirm('Clear Order', 'Are you sure you want to clear the current order?'))) {
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
                    const rowTotal = item.price * item.qty;
                    currentTotal += rowTotal;
                    const div = document.createElement('div');
                    div.className = 'pos-cart-item';

                    // Check if item is a service (price = 0 or is_service flag)
                    const isService = item.is_service || item.price === 0;
                    const priceWasSet = item.price_set === true;

                    // Check if material has been set in customization
                    const hasMaterialSet = item.customization && (
                        item.customization['Material Selection'] || 
                        item.customization['Material Brand'] || 
                        item.customization['Material'] ||
                        item.customization['temp_plate_material'] ||
                        item.customization['material_type']
                    );

                    let customHtml = '';
                    if (item.customization) {
                        const parts = [];
                        for (const [key, val] of Object.entries(item.customization)) {
                            if (val) parts.push(`${key}: ${val}`);
                        }
                        if (parts.length > 0) {
                            customHtml = `<div style="font-size:11px; color:#64748b; margin-top:2px; line-height:1.2; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;">${parts.join(' | ')}</div>`;
                        }
                    }

                    const priceHtml = (isService && !priceWasSet && !hasMaterialSet)
                        ? `<button onclick="redirectToSetPrice(${index})" style="display:inline-flex;align-items:center;gap:4px;margin-top:3px;padding:2px 8px;background:#edf4fc;border:1px solid #bfdbfe;border-radius:999px;font-size:12px;font-weight:700;color:#2f6fae;text-decoration:none;cursor:pointer;border:none;" title="Click to set price in Customizations">
                    <i class="fas fa-tag" style="font-size:10px;"></i> Set Price
                  </button>`
                        : `<div class="pos-item-price" style="margin-top:2px;">${formatMoney(item.price)}</div>`;

                    div.innerHTML = `
                <div class="pos-item-details" style="flex:1;">
                    <div class="pos-item-name">${item.name}</div>
                    ${priceHtml}
                    ${customHtml}
                </div>
                <div class="pos-item-controls">
                    <button class="pos-qty-btn" style="font-size:16px; line-height:1; font-weight:bold;" onclick="updateQtyByCartIndex(${index}, -1)">&minus;</button>
                    <input class="pos-qty-val" value="${item.qty}" readonly>
                    <button class="pos-qty-btn" style="font-size:16px; line-height:1; font-weight:bold;" onclick="updateQtyByCartIndex(${index}, 1)">&plus;</button>
                </div>
                <div class="pos-item-total" style="width:70px; text-align:right;">${formatMoney(rowTotal)}</div>
                <button class="pos-item-remove" style="font-size:18px; line-height:1; font-weight:bold;" onclick="removeByCartIndex(${index})">&times;</button>
            `;
                    cont.appendChild(div);
                });
            }

            const fTotal = formatMoney(currentTotal);
            document.getElementById('pos-subtotal').textContent = fTotal;
            document.getElementById('pos-total').textContent = fTotal;

            calculateChange();
            updateCheckoutState();
        }

        // Handlers are used above in renderCart via 'index' directly


        // Handlers are used above in renderCart via 'index' directly


        function toggleReferenceField() {
            const pm = document.getElementById('pos-payment-method').value;
            // Show GCash QR modal when GCash is selected
            if (pm === 'GCash') {
                const qrModal = document.getElementById('gcash-qr-modal');
                if (qrModal) qrModal.style.display = 'flex';
            }
            const isPayMongo = isPayMongoPaymentMethod(pm);
            document.getElementById('tender-group').style.display = isPayMongo ? 'none' : '';
            document.getElementById('change-group').style.display = isPayMongo ? 'none' : '';
            updateCheckoutState();
        }

        /** Cash and GCash complete without a transaction reference (QR / over-the-counter flow). */
        function posPaymentNeedsRefNumber(pm) {
            const v = (pm || '').trim();
            return v !== 'Cash' && v !== 'GCash' && !isPayMongoPaymentMethod(v);
        }

        function isPayMongoPaymentMethod(method) {
            return ['PayMongo QRPh', 'PayMongo Checkout', 'PayMongo Test'].includes(String(method || '').trim());
        }

        function closeGcashQr() {
            const qrModal = document.getElementById('gcash-qr-modal');
            if (qrModal) qrModal.style.display = 'none';
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

            // Check if cart has any services with price = 0
            const hasUnpricedService = cart.some(i => (i.is_service || i.price === 0) && i.price === 0);

            if (hasUnpricedService) {
                canCheckout = false;
                message = 'Set Price First';
                icon.className = 'fas fa-lock';
                text.textContent = message;
                btn.disabled = true;
                return;
            }

            const pm = document.getElementById('pos-payment-method').value;
            const ref = (document.getElementById('pos-payment-ref')?.value || '').trim();

            if (posPaymentNeedsRefNumber(pm) && !ref) {
                canCheckout = false;
                message = 'Enter Ref Number';
            }

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

        async function processCheckout() {
            if (cart.length === 0 || posCheckoutRequestInFlight || posCheckoutConfirmOpen) return;

            // Validate customer selection
            const customer = $('#pos-customer').val();
            if (!customer) {
                await showPOSAlert('Select Customer', 'Please select a customer before checkout.', 'warning');
                return;
            }

            // Block checkout if any item has price = 0
            const hasUnpricedService = cart.some(i => (i.is_service || i.price === 0) && i.price === 0);
            if (hasUnpricedService) {
                await showPOSAlert('Price Required', 'Please set the price for all items before completing the sale.\n\nClick the yellow "Set Price" button on items to set their price in Customizations.', 'warning');
                return;
            }

            const pm = document.getElementById('pos-payment-method').value;
            const ref = (document.getElementById('pos-payment-ref')?.value || '').trim();
            if (posPaymentNeedsRefNumber(pm) && !ref) {
                await showPOSAlert('Reference Required', "Reference number is required for " + pm, 'warning');
                return;
            }

            const tendered = parseFloat(document.getElementById('pos-tendered').value) || 0;

            if (!isPayMongoPaymentMethod(pm) && (tendered < currentTotal || tendered > 1000000)) {
                await showPOSAlert('Invalid Amount', "Amount paid must be at least " + formatMoney(currentTotal) + " and not exceed ₱1,000,000.", 'warning');
                return;
            }

            const changeAmount = isPayMongoPaymentMethod(pm) ? 0 : tendered - currentTotal;
            const confirmMsg = isPayMongoPaymentMethod(pm)
                ? `Create a ${pm === 'PayMongo QRPh' ? 'Dynamic QR Ph payment' : 'PayMongo checkout'} for ${formatMoney(currentTotal)}? The sale remains unpaid until PayMongo confirms it.`
                : `Confirm sale of ${formatMoney(currentTotal)} using ${pm}?\nChange due: ${formatMoney(changeAmount)}`;

            posCheckoutConfirmOpen = true;
            const confirmed = await showPOSConfirm('Confirm Transaction', confirmMsg);
            posCheckoutConfirmOpen = false;
            if (!confirmed) return;

            const btn = document.getElementById('pos-checkout-btn');
            btn.disabled = true;
            document.getElementById('checkout-icon').className = 'fas fa-spinner fa-spin';
            document.getElementById('checkout-text').textContent = 'Processing...';

            posCheckoutRequestInFlight = true;

            resetPayMongoPosCheckoutState();
            const checkoutToken = getPosPayMongoCheckoutToken(true);
            posPayMongoCheckoutPending = true;
            const payload = {
                action: 'walkin_checkout',
                customer_id: $('#pos-customer').val(),
                payment_method: pm,
                reference_number: (document.getElementById('pos-payment-ref')?.value || '').trim(),
                amount_tendered: tendered,
                csrf_token: POS_CSRF_TOKEN,
                checkout_token: checkoutToken,
                items: cart.map(i => ({
                    id: i.product_id,
                    qty: i.qty,
                    price: i.price,
                    name: i.name || null,
                    customization: i.customization || null,
                    is_service: i.is_service || false,
                    pending_order_id: i.pending_order_id || 0,
                    pending_customization_id: i.pending_customization_id || 0
                }))
            };

            let checkoutCompleted = false;
            try {
                const res = await fetchWithTimeout(staffUrl('staff/api/pos_checkout.php'), {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(payload)
                }, 45000);
                const text = await res.text();
                let data;
                try {
                    data = JSON.parse(text);
                } catch (parseErr) {
                    console.error('Non-JSON response from checkout:', text);
                    await showPOSAlert('Server Error', 'Server error. Check browser console for details.', 'error');
                    updateCheckoutState();
                    return;
                }
                if (data.success) {
                    await syncedCartAction('clear');
                    checkoutCompleted = true;

                    if (data.payment_pending && data.payment) {
                        openPayMongoPosModal(data.order_id, data.payment);
                        updateCheckoutState();
                        return;
                    }

                    posPayMongoCheckoutPending = false;
                    document.getElementById('pos-payment-method').value = 'Cash';
                    document.getElementById('pos-tendered').value = '';
                    const refInput = document.getElementById('pos-payment-ref');
                    if (refInput) refInput.value = '';
                    toggleReferenceField();
                    calculateChange();
                    updateCheckoutState();
                    openReceiptModal(data.receipt);
                } else {
                    await showPOSAlert('Error', 'Checkout failed: ' + (data.message || 'Error'), 'error');
                    updateCheckoutState();
                }
            } catch (e) {
                console.error('Checkout error:', e);
                const message = e.name === 'AbortError'
                    ? 'Checkout took too long to respond. Please refresh the POS and check Store Orders before trying again.'
                    : 'Network error: ' + e.message;
                await showPOSAlert('Network Error', message, 'error');
                updateCheckoutState();
            } finally {
                posCheckoutConfirmOpen = false;
                posCheckoutRequestInFlight = false;
                if (!checkoutCompleted) {
                    updateCheckoutState();
                }
            }
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

        function resetPayMongoPosCheckoutState() {
            if (paymongoPollTimer) window.clearInterval(paymongoPollTimer);
            if (paymongoCountdownTimer) window.clearInterval(paymongoCountdownTimer);
            paymongoPollTimer = null;
            paymongoCountdownTimer = null;
            pendingPayMongoPayment = null;
            pendingPayMongoReceipt = null;
            pendingPayMongoPrintJob = null;
            pendingPayMongoOrderId = 0;
            posPayMongoCheckoutPending = false;
            posPayMongoCheckoutAttemptToken = null;
            sessionStorage.removeItem('pos_paymongo_pending');
            sessionStorage.removeItem('pos_paymongo_checkout_token');
        }

        function closePayMongoPosModal() {
            document.getElementById('paymongo-pos-modal').style.display = 'none';
            document.getElementById('paymongo-pos-reference').textContent = '';
            resetPayMongoPosCheckoutState();
        }

        function renderPayMongoPosPayment(payment) {
            pendingPayMongoPayment = payment || null;
            const isQr = payment?.payment_flow === 'payment_intent' && payment?.payment_method === 'qrph';
            const qrImage = document.getElementById('paymongo-pos-qr');
            qrImage.style.display = isQr && payment?.qr_image_url ? 'block' : 'none';
            if (isQr && payment?.qr_image_url) qrImage.src = payment.qr_image_url;
            document.getElementById('paymongo-pos-title').textContent = isQr ? 'Dynamic QR Ph' : 'PayMongo Checkout';
            document.getElementById('paymongo-pos-order').textContent =
                `Order #${pendingPayMongoOrderId} - ${formatMoney(Number(payment?.amount || 0) / 100)}`;
            const checkoutLink = document.getElementById('paymongo-pos-open');
            checkoutLink.style.display = payment?.checkout_url ? 'block' : 'none';
            if (payment?.checkout_url) checkoutLink.href = payment.checkout_url;
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
            else statusLabel.textContent = isQr ? 'Waiting for payment confirmation.' : 'Waiting for secure checkout payment.';

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

        async function resumePayMongoPosModal(orderId) {
            try {
                const url = staffUrl('staff/api/paymongo_payment.php')
                    + '?subject_type=order&subject_id=' + encodeURIComponent(orderId)
                    + '&channel=pos&_=' + Date.now();
                const response = await fetch(url, {cache: 'no-store'});
                const data = await response.json();
                if (response.ok && data.success && data.payment) openPayMongoPosModal(orderId, data.payment);
                else sessionStorage.removeItem('pos_paymongo_pending');
            } catch (error) {
                // Keep the saved order reference so a page refresh can retry safely.
            }
        }

        async function retryPayMongoPosQr() {
            if (pendingPayMongoOrderId <= 0) return;
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
                openPayMongoPosModal(pendingPayMongoOrderId, data.payment);
            } catch (error) {
                document.getElementById('paymongo-pos-status').textContent = error.message || 'A new QR could not be generated.';
            } finally {
                retryButton.disabled = false;
            }
        }

        function openPayMongoPosModal(orderId, payment) {
            const modal = document.getElementById('paymongo-pos-modal');
            const completeButton = document.getElementById('paymongo-pos-complete');
            completeButton.disabled = true;
            completeButton.style.background = '#94a3b8';
            completeButton.style.cursor = 'not-allowed';
            pendingPayMongoReceipt = null;
            pendingPayMongoPrintJob = null;
            pendingPayMongoOrderId = Number(orderId);
            sessionStorage.setItem('pos_paymongo_pending', JSON.stringify({order_id: pendingPayMongoOrderId}));
            renderPayMongoPosPayment(payment);
            modal.style.display = 'flex';
            if (paymongoPollTimer) window.clearInterval(paymongoPollTimer);
            const finishPaidPosTransaction = async (data) => {
                if (paymongoPollTimer) window.clearInterval(paymongoPollTimer);
                paymongoPollTimer = null;
                if (data?.receipt_available && data?.receipt) {
                    pendingPayMongoReceipt = data.receipt;
                    pendingPayMongoPrintJob = data.print_job || null;
                    closePayMongoPosModal();
                    openReceiptModal(data.receipt);
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
                try {
                    const url = staffUrl('staff/api/paymongo_payment.php')
                        + '?subject_type=order&subject_id=' + encodeURIComponent(orderId)
                        + '&channel=pos&_=' + Date.now();
                    const response = await fetch(url, {cache: 'no-store'});
                    const data = await response.json();
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
                closePayMongoPosModal();
                openReceiptModal(pendingPayMongoReceipt);
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
                if (paymongoPollTimer) window.clearInterval(paymongoPollTimer);
                paymongoPollTimer = null;
                pendingPayMongoReceipt = data.receipt;
                pendingPayMongoPrintJob = data.print_job || null;
                sessionStorage.removeItem('pos_paymongo_pending');
                sessionStorage.removeItem('pos_paymongo_checkout_token');
                closePayMongoPosModal();
                openReceiptModal(data.receipt);
                pendingPayMongoOrderId = 0;
            } catch (error) {
                completeButton.disabled = false;
                document.getElementById('paymongo-pos-status').textContent = error.message;
            }
        }

        function openNewCustomerModal() {
            document.getElementById('customer-modal').style.display = 'flex';
        }
        function closeCustomerModal() {
            document.getElementById('customer-modal').style.display = 'none';
        }
        async function saveCustomer() {
            const first = document.getElementById('nc-first').value.trim();
            const last = document.getElementById('nc-last').value.trim();
            const email = document.getElementById('nc-email').value.trim();
            const phone = document.getElementById('nc-phone').value.trim();

            // Validation
            if (!first) {
                await showPOSAlert('Missing Info', 'First name is required.', 'warning');
                document.getElementById('nc-first').focus();
                return;
            }
            if (!last) {
                await showPOSAlert('Missing Info', 'Last name is required.', 'warning');
                document.getElementById('nc-last').focus();
                return;
            }
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

            const btn = document.getElementById('nc-save-btn');
            btn.textContent = 'Creating customer...';
            btn.disabled = true;

            try {
                const res = await fetch(staffUrl('staff/api/pos_add_customer.php'), {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        first_name: first,
                        last_name: last,
                        email: email,
                        contact_number: phone
                    })
                });
                const data = await res.json();
                if (data.success) {
                    const sel = $('#pos-customer');
                    const displayText = `${first} ${last} - ${email}`;
                    const opt = $('<option></option>').attr('value', data.customer_id).text(displayText);
                    sel.append(opt);
                    sel.val(data.customer_id).trigger('change');
                    closeCustomerModal();

                    // Clear form
                    document.getElementById('nc-first').value = '';
                    document.getElementById('nc-last').value = '';
                    document.getElementById('nc-email').value = '';
                    document.getElementById('nc-phone').value = '';

                    // Show success message
                    await showPOSAlert('Customer Created', `Customer created successfully!\n\nA password setup email has been sent to ${email}.\nThe customer can use this email to create their account password.`, 'success');
                } else {
                    await showPOSAlert('Error', 'Failed: ' + (data.message || 'Unknown error'), 'error');
                }
            } catch (e) {
                console.error('Error:', e);
                await showPOSAlert('Network Error', 'Network error. Please try again.', 'error');
            } finally {
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
        window.togglePosOtherInput = togglePosOtherInput;
        window.updateQtyByCartIndex = updateQtyByCartIndex;
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
                item_index: index
            }));

            // Create a temporary customization entry
            const payload = {
                action: 'create_pending_customization',
                customer_id: customer,
                csrf_token: POS_CSRF_TOKEN,
                item: {
                    id: item.product_id,
                    name: item.name,
                    qty: item.qty,
                    customization: item.customization || null,
                    is_service: item.is_service || false
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
                    const hadDesignUpload = !!(
                        item.customization?.design_upload_data
                        || item.customization?.design_upload_path
                        || item.customization?.design_upload
                    );
                    if (hadDesignUpload && !data.design_saved) {
                        await showPOSAlert(
                            'Upload Warning',
                            'Your design file may not have saved correctly. If the preview is wrong in Customizations, re-add the item with the image and try Set Price again.',
                            'warning'
                        );
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

        async function showPOSAlert(title, message, type = 'info') {
            return new Promise(resolve => {
                const overlay = document.getElementById('pos-alert-overlay');
                const box = document.getElementById('pos-alert-box');
                const iconCont = document.getElementById('pos-alert-icon-container');
                const icon = document.getElementById('pos-alert-icon');
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

                if (type === 'error') {
                    iconCont.style.background = '#fee2e2';
                    icon.style.color = '#ef4444';
                    icon.className = 'fas fa-exclamation-circle';
                } else if (type === 'warning') {
                    iconCont.style.background = '#edf4fc';
                    icon.style.color = '#2f6fae';
                    icon.className = 'fas fa-exclamation-triangle';
                } else if (type === 'success') {
                    iconCont.style.background = '#dcfce7';
                    icon.style.color = '#10b981';
                    icon.className = 'fas fa-check-circle';
                } else {
                    iconCont.style.background = '#e0f2fe';
                    icon.style.color = '#0ea5e9';
                    icon.className = 'fas fa-info-circle';
                }

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

        async function showPOSConfirm(title, message, confirmLabel = 'Confirm') {
            return new Promise(resolve => {
                const overlay = document.getElementById('pos-alert-overlay');
                const box = document.getElementById('pos-alert-box');
                const iconCont = document.getElementById('pos-alert-icon-container');
                const icon = document.getElementById('pos-alert-icon');
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
                confirmBtn.style.background = 'var(--staff-pos-button-bg)';

                iconCont.style.background = '#eef2ff';
                icon.style.color = '#2f6fae';
                icon.className = 'fas fa-question-circle';

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
    </script>

</body>

</html>


