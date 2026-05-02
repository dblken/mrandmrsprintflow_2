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
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@400;500;600;700;800&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
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
            border-color: #0d9488;
            background: #f0fdfa;
        }

        .shopee-opt-btn.active {
            border-color: #0d9488;
            background: #0d9488;
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
            border-color: #0d9488;
        }

        .input-field.input-field-locked,
        .shopee-opt-btn select.input-field-locked {
            background: #0d9488 !important;
            color: #ffffff !important;
            border: 1px solid #0d9488 !important;
            cursor: not-allowed !important;
            opacity: 1 !important;
        }

        .shopee-opt-btn:has(select.input-field-locked) {
            background: #0d9488 !important;
            border-color: #0d9488 !important;
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
            transition: border-color 0.2s;
            height: 44px;
        }

        .pos-search-input:focus {
            border-color: #06A1A1;
            box-shadow: 0 0 0 3px rgba(99, 102, 241, 0.1);
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
            background: #f1f5f9;
        }

        /* Product Card */
        .pos-card {
            background: #ffffff;
            border: 1px solid rgba(226, 232, 240, 0.6);
            border-radius: 10px;
            overflow: hidden;
            cursor: pointer;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            display: flex;
            flex-direction: column;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.05);
            height: auto;
            min-height: 160px;
        }

        .pos-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.1), 0 10px 10px -5px rgba(0, 0, 0, 0.04);
            border-color: #06A1A1;
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
            background: linear-gradient(135deg, #06A1A1 0%, #048888 100%);
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
            font-family: 'Outfit', sans-serif;
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
            background: #fee2e2;
            color: #ef4444;
            border: none;
            padding: 6px 12px;
            border-radius: 6px;
            font-size: 12px;
            font-weight: 600;
            cursor: pointer;
            transition: background 0.2s;
        }

        .pos-btn-clear:hover {
            background: #fca5a5;
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
            color: #06A1A1;
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
            border: 1px solid #f1f5f9;
            border-radius: 10px;
            margin-bottom: 8px;
            background: #fff;
            box-shadow: 0 1px 2px rgba(0, 0, 0, 0.02);
            transition: all 0.2s;
        }

        .pos-cart-item:hover {
            border-color: #06A1A1;
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
            border-color: #06A1A1;
        }



        .pos-btn-checkout {
            width: 100%;
            padding: 16px;
            background: #4f46e5;
            color: white;
            border: none;
            border-radius: 8px;
            font-size: 16px;
            font-weight: 700;
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 8px;
            cursor: pointer;
            transition: background 0.2s;
        }

        .pos-btn-checkout:hover {
            background: #4338ca;
            transform: translateY(-1px);
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
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
            font-family: 'Outfit', sans-serif;
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
            border-color: #06A1A1;
            box-shadow: 0 20px 25px -5px rgba(99, 102, 241, 0.15), 0 10px 10px -5px rgba(99, 102, 241, 0.1);
        }

        .service-btn.active,
        .service-btn:active {
            border-color: #4f46e5;
            background: #eef2ff;
            color: #4f46e5;
            box-shadow: 0 0 0 2px rgba(79, 70, 229, 0.3);
        }

        .service-btn.btn-other {
            background: #f8fafc;
            border: 2px dashed #cbd5e1;
            color: #64748b;
        }

        .service-btn.btn-other:hover,
        .service-btn.btn-other.active {
            border-style: solid;
            border-color: #06A1A1;
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
            border-color: #06A1A1;
        }

        .select2-dropdown {
            border: 1px solid #cbd5e1;
            border-radius: 8px;
        }

        .select2-container--default .select2-results__option--highlighted[aria-selected] {
            background-color: #06A1A1;
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

        /* Success Transaction Modal */
        #success-modal-overlay {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(0, 0, 0, 0.5);
            backdrop-filter: blur(6px);
            z-index: 2000;
            align-items: center;
            justify-content: center;
            transition: opacity 0.3s ease;
            opacity: 0;
        }

        #success-modal-overlay.active {
            opacity: 1;
        }

        .success-modal {
            background: #ffffff;
            width: 360px;
            border-radius: 28px;
            padding: 44px 40px;
            text-align: center;
            box-shadow: 0 25px 60px rgba(0, 0, 0, 0.2), 0 8px 20px rgba(0, 0, 0, 0.08);
            border: 1px solid #e2e8f0;
            transform: scale(0.85) translateY(20px);
            transition: transform 0.4s cubic-bezier(0.175, 0.885, 0.32, 1.275), opacity 0.3s ease;
            opacity: 0;
        }

        #success-modal-overlay.active .success-modal {
            transform: scale(1) translateY(0);
            opacity: 1;
        }

        .success-icon {
            background: linear-gradient(135deg, #10b981, #059669);
            width: 88px;
            height: 88px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 28px;
            box-shadow: 0 12px 28px rgba(16, 185, 129, 0.45);
        }

        .success-icon svg {
            display: block;
        }

        /* Mobile & Tablet Responsive Layout */
        @media (max-width: 1024px) {
            .pos-wrapper {
                flex-direction: column !important;
                overflow-y: auto !important;
                display: flex !important;
            }
            .pos-products-area {
                flex: none !important;
                height: 55vh;
                border-right: none;
                border-bottom: 4px solid #e2e8f0;
            }
            .pos-cart-area {
                width: 100%;
                border-left: none;
                flex: none !important;
                height: auto;
            }
            .pos-cart-list {
                min-height: 350px;
            }
            
            /* Fix squished headers */
            .pos-search-header {
                flex-direction: column;
                align-items: stretch !important;
                padding: 16px !important;
                gap: 12px !important;
            }
            .pos-search-box {
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

<body data-turbo="false">

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

                                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px;">
                                    <!-- Products Button -->
                                    <button onclick="showPOSMode('products')"
                                        style="background: white; border: 2px solid #e2e8f0; border-radius: 12px; padding: 48px 24px; cursor: pointer; transition: all 0.2s; display: flex; flex-direction: column; align-items: center; gap: 16px;"
                                        onmouseover="this.style.borderColor='#06A1A1'; this.style.transform='translateY(-2px)'; this.style.boxShadow='0 8px 16px rgba(0,0,0,0.08)';"
                                        onmouseout="this.style.borderColor='#e2e8f0'; this.style.transform='translateY(0)'; this.style.boxShadow='none';">
                                        <div
                                            style="width: 64px; height: 64px; background: linear-gradient(135deg, #06A1A1 0%, #048888 100%); border-radius: 50%; display: flex; align-items: center; justify-content: center;">
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
                                        onmouseover="this.style.borderColor='#06A1A1'; this.style.transform='translateY(-2px)'; this.style.boxShadow='0 8px 16px rgba(0,0,0,0.08)';"
                                        onmouseout="this.style.borderColor='#e2e8f0'; this.style.transform='translateY(0)'; this.style.boxShadow='none';">
                                        <div
                                            style="width: 64px; height: 64px; background: linear-gradient(135deg, #06A1A1 0%, #048888 100%); border-radius: 50%; display: flex; align-items: center; justify-content: center;">
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
                                    style="min-width: auto; padding: 12px 20px; background: #f8fafc; border-color: #cbd5e1; cursor: pointer; width: auto; display: flex; align-items: center; gap: 8px;"
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
                                    style="min-width: auto; padding: 12px 16px; background: #f8fafc; border-color: #cbd5e1; cursor: pointer;"
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
                                    style="font-size: 20px; font-weight: 800; color: #06A1A1;">₱0.00</span>
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
                    <button onclick="confirmServiceModal()"
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
                    style="padding:12px 28px; border:none; background:#4f46e5; color:white; border-radius:12px; cursor:pointer; font-weight:700; font-size:14px; box-shadow:0 10px 15px -3px rgba(79,70,229,0.3); transition:all 0.2s;"
                    onmouseover="this.style.transform='translateY(-1px)';this.style.background='#4338ca'"
                    onmouseout="this.style.transform='translateY(0)';this.style.background='#4f46e5'">Add to
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
                <button id="pos-alert-confirm" class="pos-alert-btn" style="background:#4f46e5; color:white;"
                    onmouseover="this.style.background='#4338ca'"
                    onmouseout="this.style.background='#4f46e5'">OK</button>
            </div>
        </div>
    </div>

    <!-- Success Transaction Modal Overlay -->
    <div id="success-modal-overlay">
        <div class="success-modal">
            <div class="success-icon">
                <svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="2.5"
                    stroke-linecap="round" stroke-linejoin="round">
                    <polyline points="20 6 9 17 4 12"></polyline>
                </svg>
            </div>
            <div
                style="display:inline-flex;align-items:center;gap:6px;background:#dcfce7;color:#15803d;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:0.08em;padding:4px 12px;border-radius:100px;margin-bottom:16px;">
                <svg width="10" height="10" viewBox="0 0 10 10" fill="#15803d">
                    <circle cx="5" cy="5" r="5" />
                </svg>
                Transaction Complete
            </div>
            <h2 style="margin:0 0 10px; font-weight:800; color:#0f172a; font-size:26px; letter-spacing:-0.03em;">Sale
                Completed</h2>
            <p style="margin:0; color:#64748b; font-size:15px; line-height:1.6;">The transaction was
                processed<br>successfully.</p>
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
                    onfocus="this.style.borderColor='#06A1A1';this.style.background='#fff'"
                    onblur="this.style.borderColor='#e2e8f0';this.style.background='#f8fafc'">
            </div>
            <div style="margin-bottom:16px;">
                <label
                    style="display:block; font-size:12px; font-weight:700; color:#64748b; text-transform:uppercase; margin-bottom:6px;">Last
                    Name *</label>
                <input type="text" id="nc-last" placeholder="Enter last name"
                    style="width:100%; padding:12px; border:1px solid #e2e8f0; border-radius:8px; background:#f8fafc; color:#1e293b; outline:none; transition:all 0.2s;"
                    onfocus="this.style.borderColor='#06A1A1';this.style.background='#fff'"
                    onblur="this.style.borderColor='#e2e8f0';this.style.background='#f8fafc'">
            </div>
            <div style="margin-bottom:16px;">
                <label
                    style="display:block; font-size:12px; font-weight:700; color:#64748b; text-transform:uppercase; margin-bottom:6px;">Email
                    Address *</label>
                <input type="email" id="nc-email" placeholder="customer@example.com"
                    style="width:100%; padding:12px; border:1px solid #e2e8f0; border-radius:8px; background:#f8fafc; color:#1e293b; outline:none; transition:all 0.2s;"
                    onfocus="this.style.borderColor='#06A1A1';this.style.background='#fff'"
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
                    onfocus="this.style.borderColor='#06A1A1';this.style.background='#fff'"
                    onblur="this.style.borderColor='#e2e8f0';this.style.background='#f8fafc'">
            </div>
            <button onclick="saveCustomer()" id="nc-save-btn"
                style="width:100%; background:#4f46e5; color:white; padding:14px; border:none; border-radius:12px; font-weight:700; cursor:pointer; box-shadow:0 10px 15px -3px rgba(79,70,229,0.3); transition:all 0.2s;"
                onmouseover="this.style.background='#4338ca'" onmouseout="this.style.background='#4f46e5'">Create
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
                    style="flex:1; padding:14px; border:none; border-radius:12px; background:#4f46e5; color:white; font-weight:700; cursor:pointer; box-shadow:0 10px 15px -3px rgba(79,70,229,0.3); transition:all 0.2s;"
                    onmouseover="this.style.background='#4338ca';this.style.transform='translateY(-1px)'"
                    onmouseout="this.style.background='#4f46e5';this.style.transform='translateY(0)'">Add Item</button>
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
    <script>
        window.POS_BRANCHES = <?php echo json_encode(array_map(function ($b) {
            return ['id' => (int) $b['id'], 'name' => $b['branch_name']];
        }, $branches ?: [])); ?>;

        let products = [];
        let cart = [];
        let currentTotal = 0;
        let currentMode = null; // 'products' or 'services'
        const STAFF_BASE_PATH = <?php echo json_encode(BASE_PATH); ?>;
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
            const searchEl = document.getElementById('pos-search');
            const catEl = document.getElementById('pos-category');
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

        async function syncedCartAction(action, payload = {}) {
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
                    await showPOSAlert('Error', data.message || 'Action failed', 'error');
                    return { success: false, message: data.message };
                }
            } catch (e) {
                console.error('Cart Action Error:', e);
                await showPOSAlert('Network Error', 'Network error while updating cart.', 'error');
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

                // Lock branch to staff's assigned branch
                if (data.staff_branch_id) {
                    const branchSel = body.querySelector('select[name="branch_id"]');
                    if (branchSel) {
                        branchSel.value = data.staff_branch_id;
                        branchSel.disabled = true;
                        branchSel.classList.add('input-field-locked');
                        branchSel.style.background = '#0d9488';
                        branchSel.style.color = '#ffffff';
                        branchSel.style.borderColor = '#0d9488';
                        branchSel.style.opacity = '1';
                        branchSel.style.cursor = 'not-allowed';
                        // Add hidden input so disabled value is still submitted
                        const hidden = document.createElement('input');
                        hidden.type = 'hidden';
                        hidden.name = 'branch_id';
                        hidden.value = data.staff_branch_id;
                        branchSel.parentNode.appendChild(hidden);
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
            } catch (e) {
                body.innerHTML = '<p style="color:#ef4444;text-align:center;padding:1rem;">Network error. Please try again.</p>';
            }
        }

        function closeServiceModal() {
            document.getElementById('service-modal-overlay').style.display = 'none';
        }

        async function confirmServiceModal() {
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

                const fileInput = row.querySelector('input[type="file"]');
                if (fileInput && fileInput.files && fileInput.files.length > 0) {
                    const fileName = fileInput.files[0].name;
                    customization[labelText] = fileName;
                    if (fileInput.name === 'design_file') customization.design_upload = fileName;
                    if (fileInput.name === 'reference_file') customization.reference_upload = fileName;
                }

                // Text / number
                const textInput = row.querySelector('input[type="text"], input[type="number"]:not(#quantity-input)');
                if (textInput && !textInput.id.includes('hidden') && textInput.value.trim()) {
                    customization[labelText] = textInput.value.trim();
                }
            });

            // Add service to cart with price = 0 (will be set in customizations page)
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
                    <i class="fas ${outOfStock ? 'fa-times-circle' : 'fa-check-circle'}" style="color:${outOfStock ? '#ef4444' : '#06A1A1'}; font-size:8px;"></i>
                    <span>${outOfStock ? 'Out' : stockQty + ' left'}</span>
                </div>
            </div>
        `;
                grid.appendChild(card);
            });
        }

        async function addToCart(p, overridePrice = null, overrideName = null) {
            const name = overrideName || p.product_name;
            const price = overridePrice !== null ? overridePrice : parseFloat(p.price);

            if (p.price == 0 && overridePrice === null) {
                openPriceModal(p);
                return;
            }

            await syncedCartAction('add', {
                product_id: p.product_id,
                name: name,
                price: price,
                qty: 1,
                is_service: false
            });
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

        function collectRequirementsToCustomization(requirements, startIdx, customization, validation) {
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
                if (req.type === 'file' && el.files && el.files.length > 0) {
                    val = el.files[0].name;
                    if (name === 'design_file') customization.design_upload = val;
                    if (name === 'reference_file') customization.reference_upload = val;
                }
                if (req.required && !val) validation.valid = false;
                if (val) customization[name] = val;
            });
        }

        async function confirmCustomization() {
            if (!pendingCustomProduct || !currentCustomRequirements) return;

            const customization = {};
            const validation = { valid: true };

            collectRequirementsToCustomization(currentCustomRequirements, 0, customization, validation);
            if (posDynamicRequirements) {
                collectRequirementsToCustomization(posDynamicRequirements, posDynamicFieldStartIndex, customization, validation);
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
            await syncedCartAction('add', {
                product_id: p.product_id,
                name: itemName,
                price: price,
                qty: 1,
                customization: customization,
                is_service: (customization !== null)
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
                        ? `<button onclick="redirectToSetPrice(${index})" style="display:inline-flex;align-items:center;gap:4px;margin-top:3px;padding:2px 8px;background:#fef3c7;border:1px solid #f59e0b;border-radius:5px;font-size:12px;font-weight:700;color:#d97706;text-decoration:none;cursor:pointer;border:none;" title="Click to set price in Customizations">
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
            updateCheckoutState();
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
            changeEl.style.color = (tendered < currentTotal && tendered > 0) ? '#ef4444' : '#06A1A1';

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
            const ref = '';

            if (pm !== 'Cash' && !ref) {
                canCheckout = false;
                message = 'Enter Ref Number';
            }

            // Regular products require payment
            const tendered = parseFloat(document.getElementById('pos-tendered').value) || 0;
            if (tendered < currentTotal || tendered > 1000000) {
                canCheckout = false;
                if (message === 'Complete Sale') message = 'Enter Valid Amount';
            }

            btn.disabled = !canCheckout;
            icon.className = canCheckout ? 'fas fa-check-circle' : 'fas fa-lock';
            text.textContent = message;
        }

        async function processCheckout() {
            if (cart.length === 0) return;

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
            const ref = '';
            if (pm !== 'Cash' && !ref) {
                await showPOSAlert('Reference Required', "Reference number is required for " + pm, 'warning');
                return;
            }

            const tendered = parseFloat(document.getElementById('pos-tendered').value) || 0;

            if (tendered < currentTotal || tendered > 1000000) {
                await showPOSAlert('Invalid Amount', "Amount paid must be at least " + formatMoney(currentTotal) + " and not exceed ₱1,000,000.", 'warning');
                return;
            }

            const changeAmount = tendered - currentTotal;
            const confirmMsg = `Confirm sale of ${formatMoney(currentTotal)} using ${pm}?\nChange due: ${formatMoney(changeAmount)}`;

            if (!(await showPOSConfirm('Confirm Transaction', confirmMsg))) {
                return;
            }

            const btn = document.getElementById('pos-checkout-btn');
            btn.disabled = true;
            document.getElementById('checkout-icon').className = 'fas fa-spinner fa-spin';
            document.getElementById('checkout-text').textContent = 'Processing...';

            const payload = {
                action: 'walkin_checkout',
                customer_id: $('#pos-customer').val(),
                payment_method: pm,
                reference_number: '',
                amount_tendered: tendered,
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

                    // Show Success Modal
                    const overlay = document.getElementById('success-modal-overlay');
                    overlay.style.display = 'flex';
                    setTimeout(() => overlay.classList.add('active'), 10);

                    // Reset payment method
                    document.getElementById('pos-payment-method').value = 'Cash';
                    document.getElementById('pos-tendered').value = '';
                    toggleReferenceField();
                    calculateChange();
                    updateCheckoutState();

                    // Hide modal and resume
                    setTimeout(() => {
                        overlay.classList.remove('active');
                        setTimeout(() => overlay.style.display = 'none', 300);
                    }, 2000);
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
                if (!checkoutCompleted) {
                    updateCheckoutState();
                }
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
                if (data.success && data.customization_id) {
                    // Redirect to customizations page
                    const redirectUrl = new URL(<?php echo json_encode(BASE_PATH . '/staff/customizations.php'); ?>, window.location.origin);
                    redirectUrl.searchParams.set('status', 'APPROVED');
                    redirectUrl.searchParams.set('order_id', data.customization_id);
                    redirectUrl.searchParams.set('job_type', 'CUSTOMIZATION');
                    redirectUrl.searchParams.set('return_to_pos', '1');
                    if (data.order_id) {
                        redirectUrl.searchParams.set('source_order_id', data.order_id);
                    }
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
                confirmBtn.textContent = 'OK';
                confirmBtn.style.background = '#4f46e5';

                if (type === 'error') {
                    iconCont.style.background = '#fee2e2';
                    icon.style.color = '#ef4444';
                    icon.className = 'fas fa-exclamation-circle';
                } else if (type === 'warning') {
                    iconCont.style.background = '#fef3c7';
                    icon.style.color = '#f59e0b';
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

        async function showPOSConfirm(title, message) {
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
                confirmBtn.textContent = 'Confirm';
                confirmBtn.style.background = '#06A1A1';

                iconCont.style.background = '#eef2ff';
                icon.style.color = '#4f46e5';
                icon.className = 'fas fa-question-circle';

                overlay.style.display = 'flex';
                setTimeout(() => {
                    overlay.style.opacity = '1';
                    box.style.transform = 'translateY(0)';
                }, 10);

                cancelBtn.onclick = () => {
                    closePOSAlert();
                    resolve(false);
                };
                confirmBtn.onclick = () => {
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
