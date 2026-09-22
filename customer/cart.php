<?php
/**
 * Shopping Cart Page
 * PrintFlow - Printing Shop PWA
 */

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/order_ui_helper.php';

require_role('Customer');

// Handle updates/removals
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['update_cart'])) {
        foreach ($_POST['quantities'] as $pid => $qty) {
            $qty = max(1, (int)$qty);
            if (isset($_SESSION['cart'][$pid])) {
                $_SESSION['cart'][$pid]['quantity'] = $qty;
            }
        }
        // Enforce 99 total after update
        $total = array_sum(array_column($_SESSION['cart'], 'quantity'));
        if ($total > 99) {
            $excess = $total - 99;
            foreach ($_SESSION['cart'] as $pid => &$item) {
                $reduce = min($excess, $item['quantity'] - 1);
                if ($reduce > 0) { $item['quantity'] -= $reduce; $excess -= $reduce; }
                if ($excess <= 0) break;
            }
            unset($item);
        }
        header("Location: cart.php");
        exit;
    }
    // Remove item handler removed - now handled via AJAX only
}

$cart_items = $_SESSION['cart'] ?? [];
$total = 0;
$has_custom = false;
foreach ($cart_items as $item) {
    $item_price = (float)($item['price'] ?? 0);
    $is_unpriced_item = ($item_price <= 0);
    if (!$is_unpriced_item) {
        $total += $item['price'] * $item['quantity'];
    } else {
        $has_custom = true;
    }
}

$page_title = 'Shopping Cart - PrintFlow';
$use_customer_css = true;
$base_url = defined('BASE_URL') ? BASE_URL : '/printflow';

if (!function_exists('printflow_cart_asset_url')) {
    function printflow_cart_asset_url(string $path, string $base_url): string {
        $path = trim($path);
        if ($path === '') return '';
        if (preg_match('#^https?://#i', $path)) return $path;

        $normalized_base = rtrim($base_url, '/');
        $normalized_path = '/' . ltrim($path, '/');

        if ($normalized_base !== '' && ($normalized_path === $normalized_base || strpos($normalized_path, $normalized_base . '/') === 0)) {
            return $normalized_path;
        }

        return $normalized_base . $normalized_path;
    }
}

// Ensure all items have a selection state
if (isset($_SESSION['cart']) && is_array($_SESSION['cart'])) {
    foreach ($_SESSION['cart'] as $pid => &$item) {
        if (!isset($item['selected'])) {
            $item['selected'] = true;
        }
    }
    unset($item);
}

require_once __DIR__ . '/../includes/header.php';
?>

<style>
    /* body.customer-theme bg handled by customer-theme.css */
    .cart-theme-page {
        color: #1e293b;
    }
    .cart-theme-page .ct-page-title {
        color: #0f172a !important;
    }
    .cart-theme-page .card {
        background: rgba(0,49,61,0.85) !important;
        border: none !important;
        border-radius: 0 !important;
        box-shadow: 0 8px 32px rgba(0,0,0,0.4) !important;
        overflow: hidden;
        backdrop-filter: blur(8px);
    }
    .cart-theme-page table {
        background: transparent !important;
        color: #1e293b !important;
    }
    .cart-theme-page .cart-table {
        width: 100%;
        table-layout: auto;
        border-collapse: collapse;
    }
    .cart-theme-page thead {
        background: #f8fafc !important;
        color: #475569 !important;
    }
    .cart-row {
        cursor: pointer !important;
    }
    .cart-row td {
        pointer-events: auto !important;
    }
    .cart-theme-page .cart-row {
        border-bottom: 1px solid #e2e8f0 !important;
    }
    .cart-theme-page .cart-row td {
        color: #1e293b !important;
    }
    .cart-theme-page .cart-row td:nth-child(2) {
        position: relative;
        min-width: 320px;
        padding-right: 6.5rem !important;
    }
    .cart-theme-page .cart-row:hover {
        background: #f1f5f9 !important;
    }
    .cart-theme-page .btn-primary {
        background: linear-gradient(135deg, #53C5E0, #32a1c4) !important;
        color: #fff !important;
        border: none !important;
        box-shadow: 0 10px 22px rgba(50,161,196,0.3);
    }
    .cart-theme-page #checkout-btn {
        background: linear-gradient(135deg, #53C5E0, #32a1c4) !important;
        color: #ffffff !important;
        border: none !important;
        box-shadow: 0 10px 22px rgba(50,161,196,0.3) !important;
    }
    .cart-theme-page #checkout-btn:hover { filter: brightness(1.05); }
    .cart-theme-page .btn-secondary {
        background: #f1f5f9 !important;
        color: #334155 !important;
        border: 1px solid #cbd5e1 !important;
    }
    .cart-theme-page .btn-secondary:hover {
        background: #e2e8f0 !important;
        color: #0f172a !important;
    }
    .cart-theme-page .ct-empty { color: #64748b; }
    .cart-theme-page .ct-empty p { color: #64748b; }

    /* Modern Circular Quantity Selector */
    .qty-control {
        display: inline-flex;
        align-items: center;
        background: #f1f5f9;
        border-radius: 999px;
        padding: 4px 6px;
        gap: 8px;
        border: 1px solid #dbe4ee;
    }
    .qty-btn {
        width: 32px;
        height: 32px;
        border-radius: 999px;
        border: 1px solid #d6dee8;
        background: #e2e8f0;
        color: #334155;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 1.1rem;
        cursor: pointer;
        transition: all 0.2s;
    }
    .qty-btn:hover:not(:disabled) {
        background: #0a2530;
        color: #fff;
        transform: scale(1.05);
    }
    .qty-btn:active:not(:disabled) { transform: scale(0.95); }
    .qty-btn:disabled { opacity: 0.35; cursor: not-allowed; }
    .qty-val {
        font-weight: 700;
        font-size: 0.95rem;
        color: #0f172a;
        min-width: 26px;
        text-align: center;
    }

    .cart-item-type-badge {
        position: absolute;
        top: 50%;
        right: 1rem;
        transform: translateY(-50%);
        display: inline-flex;
        align-items: center;
        justify-content: center;
        height: 28px;
        padding: 0 0.75rem;
        border-radius: 999px;
        font-size: 0.72rem;
        font-weight: 700;
        letter-spacing: 0.02em;
        text-transform: uppercase;
    }

    .cart-item-meta {
        flex: 1;
        min-width: 0;
        max-width: 100%;
    }

    .cart-item-name {
        display: block;
        min-width: 0;
        max-width: 100%;
        overflow: hidden;
        white-space: nowrap;
        text-overflow: ellipsis;
        font-weight: 600;
        color: #0f172a;
    }

    .cart-total-wrap {
        display: flex;
        align-items: center;
        justify-content: flex-end;
        min-height: 40px;
    }

    .cart-delete-cell {
        text-align: center;
        width: 60px;
    }

    .cart-delete-mobile {
        display: none !important;
    }

    /* Checkbox Styling */
    .cart-checkbox {
        width: 20px;
        height: 20px;
        border-radius: 0;
        border: 2px solid rgba(83, 197, 224, 0.35);
        cursor: pointer;
        accent-color: #53c5e0;
    }

    /* Trash Icon Styling */
    .trash-btn {
        color: #dc2626;
        background: rgba(239, 68, 68, 0.1);
        border: none;
        padding: 8px;
        border-radius: 10px;
        cursor: pointer;
        transition: all 0.2s;
        display: flex;
        align-items: center;
        justify-content: center;
    }
    .trash-btn:hover {
        background: rgba(239, 68, 68, 0.2);
        transform: scale(1.1);
    }

    /* Mobile Responsive Card Layout */
    @media (max-width: 768px) {
        .cart-theme-page .card {
            background: #ffffff !important;
            box-shadow: 0 8px 24px rgba(15, 23, 42, 0.08) !important;
            backdrop-filter: none !important;
        }

        .cart-theme-page table {
            display: block;
            background: #ffffff !important;
        }
        
        .cart-theme-page thead {
            display: none;
        }
        
        .cart-theme-page tbody {
            display: block;
            background: #ffffff !important;
        }
        
        .cart-theme-page .cart-row {
            display: block;
            background: #ffffff !important;
            border: 1px solid #e2e8f0 !important;
            border-radius: 0;
            margin-bottom: 0.5rem;
            padding: 0.5rem;
            box-shadow: 0 4px 12px rgba(15,23,42,0.06);
            position: relative;
        }

        .cart-theme-page .cart-row:hover {
            background: #f8fafc !important;
        }
        
        .cart-theme-page .cart-row td {
            display: block;
            padding: 0 !important;
            border: none !important;
            text-align: left !important;
        }
        
        /* TOP SECTION: Checkbox + Image + Name + Tag */
        .cart-theme-page .cart-row td:nth-child(1) {
            position: absolute;
            top: 0.5rem;
            left: 0.5rem;
            width: auto;
        }
        
        .cart-theme-page .cart-row td:nth-child(2) {
            padding-left: 2rem !important;
            padding-right: 0.5rem !important;
            padding-bottom: 0.4rem !important;
            margin-bottom: 0.4rem;
            border-bottom: 1px solid #e2e8f0 !important;
            position: relative !important;
        }
        
        .cart-theme-page .cart-row td:nth-child(2) > div {
            flex-direction: row !important;
            align-items: center !important;
            gap: 0.4rem !important;
        }
        
        /* Reduce image size */
        .cart-theme-page .cart-row td:nth-child(2) > div:first-child {
            width: 36px !important;
            height: 36px !important;
            min-width: 36px;
            border-radius: 4px;
        }
        
        .cart-theme-page .cart-row td:nth-child(2) > div:nth-child(2) {
            flex: 1;
            min-width: 0;
            overflow: hidden;
        }

        .cart-item-meta {
            padding-right: 4.5rem;
        }

        /* Product name */
        .cart-theme-page .cart-row td:nth-child(2) > div:nth-child(2) > div:first-child,
        .cart-item-name {
            font-size: 0.85rem !important;
            font-weight: 600 !important;
            line-height: 1.3;
            color: #0f172a !important;
            padding-right: 0 !important;
            white-space: nowrap !important;
            overflow: hidden !important;
            text-overflow: ellipsis !important;
        }
        
        /* Product badge - position in upper right */
        .cart-theme-page .cart-row td:nth-child(2) > span {
            position: absolute !important;
            top: 0.5rem !important;
            right: 0.5rem !important;
            transform: none !important;
            height: 16px !important;
            padding: 0 0.35rem !important;
            font-size: 0.55rem !important;
            line-height: 16px !important;
        }
        
        /* Category text - smaller */
        .cart-theme-page .cart-row td:nth-child(2) > div:nth-child(2) > div:nth-child(2),
        .cart-theme-page .cart-row td:nth-child(2) > div:nth-child(2) > div:nth-child(3) {
            font-size: 0.65rem !important;
            line-height: 1.3;
        }
        
        /* MIDDLE SECTION: Price and Quantity in rows */
        .cart-theme-page .cart-row td:nth-child(3),
        .cart-theme-page .cart-row td:nth-child(4) {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0.3rem 0 !important;
        }
        
        .cart-theme-page .cart-row td:nth-child(3)::before {
            content: 'Price:';
            font-weight: 500;
            color: #64748b;
            font-size: 0.75rem;
            flex-shrink: 0;
        }
        
        .cart-theme-page .cart-row td:nth-child(3) span {
            font-size: 0.8rem !important;
            font-weight: 600 !important;
            text-align: right;
        }
        
        .cart-theme-page .cart-row td:nth-child(4) {
            padding-bottom: 0.4rem !important;
            margin-bottom: 0.4rem;
            border-bottom: 1px solid #e2e8f0 !important;
        }
        
        .cart-theme-page .cart-row td:nth-child(4)::before {
            content: 'Qty:';
            font-weight: 500;
            color: #64748b;
            font-size: 0.75rem;
            flex-shrink: 0;
        }
        
        /* BOTTOM SECTION: Total + Delete in same row */
        .cart-theme-page .cart-row td:nth-child(5) {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0.3rem 0 0 !important;
            border: none !important;
        }
        
        .cart-theme-page .cart-row td:nth-child(5)::before {
            content: 'Total:';
            font-weight: 500;
            color: #64748b;
            font-size: 0.75rem;
            flex-shrink: 0;
        }
        
        .cart-theme-page .cart-row td:nth-child(5) > div {
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        .cart-theme-page .cart-row td:nth-child(5) span {
            font-size: 0.9rem !important;
            font-weight: 700 !important;
            color: #0f172a !important;
            text-align: right;
        }
        
        .cart-theme-page .cart-row td:nth-child(6) {
            display: none !important;
        }

        .cart-delete-mobile {
            display: inline-flex !important;
        }
        
        /* Quantity controls - compact */
        .qty-control {
            gap: 6px;
            padding: 2px 4px;
        }
        
        .qty-btn {
            width: 26px;
            height: 26px;
            font-size: 0.95rem;
        }
        
        .qty-val {
            font-size: 0.85rem;
            min-width: 18px;
        }
        
        /* Trash button - compact */
        .trash-btn {
            padding: 6px;
            min-height: 32px;
            min-width: 32px;
            border-radius: 6px;
        }
        
        .trash-btn svg {
            width: 14px;
            height: 14px;
        }
        
        /* Checkbox - compact */
        .cart-checkbox {
            width: 16px;
            height: 16px;
        }
        
        /* Footer actions */
        .cart-theme-page .card > div:last-child {
            background: #ffffff !important;
            flex-direction: column;
            gap: 1rem;
            align-items: center !important;
            justify-content: center !important;
            padding: 1rem !important;
            text-align: center !important;
        }
        
        .cart-theme-page .card > div:last-child > a,
        .cart-theme-page .card > div:last-child > div {
            width: 100%;
            text-align: center !important;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
        }
        
        .cart-theme-page .card > div:last-child > a {
            justify-content: center;
            align-items: center;
        }
        
        .cart-theme-page #checkout-btn {
            width: 100%;
            padding: 0.875rem 1.5rem;
            font-size: 0.95rem;
            min-height: 46px;
        }
        
        .cart-theme-page .btn-secondary {
            width: 100%;
            padding: 0.75rem 1.25rem;
            text-align: center;
            display: block;
            min-height: 46px;
            font-size: 0.9rem;
        }
        
        /* Cart total section */
        .cart-theme-page .card > div:last-child > div {
            display: flex;
            flex-direction: column;
            gap: 0.25rem;
            align-items: center;
        }
        
        .cart-theme-page .card > div:last-child > div > div:first-child {
            order: 2;
            font-size: 0.8rem !important;
            text-align: center;
        }
        
        .cart-theme-page .card > div:last-child > div > div:nth-child(2) {
            order: 1;
            font-size: 1.35rem !important;
            text-align: center;
        }
        
        .cart-theme-page .card > div:last-child > div > div:nth-child(3) {
            order: 3;
            font-size: 0.7rem !important;
            text-align: center;
        }
        
        .cart-theme-page .card > div:last-child > div > #mixed-items-warning {
            order: 4;
            margin-left: auto !important;
            margin-right: auto !important;
            text-align: center !important;
            align-items: center !important;
            justify-content: center !important;
        }
        
        .cart-theme-page .card > div:last-child > div > #mixed-items-warning > div {
            text-align: center !important;
        }
        
        .cart-theme-page .card > div:last-child > div > #mixed-items-warning > div > div {
            text-align: center !important;
        }
        
        .cart-theme-page .card > div:last-child > div > #checkout-btn {
            order: 5;
        }
        
        /* Page title */
        .cart-theme-page h1 {
            font-size: 1.5rem !important;
            margin-bottom: 1rem !important;
        }
    }

    #removeModal > div:last-child {
        background: rgba(10, 37, 48, 0.97) !important;
        border: 1px solid rgba(83, 197, 224, 0.26) !important;
        box-shadow: 0 24px 42px rgba(0,0,0,0.45) !important;
    }
    #removeModal h3 { color: #eaf6fb !important; }
    #removeModal p { color: #b9d4df !important; }
    .cart-info-modal {
        position: fixed;
        inset: 0;
        z-index: 99999;
        display: none;
        align-items: center;
        justify-content: center;
        padding: 1rem;
    }
    .cart-info-modal-backdrop {
        position: absolute;
        inset: 0;
        background: rgba(2, 12, 18, 0.65);
    }
    .cart-info-modal-card {
        position: relative;
        width: min(560px, 100%);
        max-height: 85vh;
        overflow: hidden;
        background: rgba(10, 37, 48, 0.97);
        border: none;
        border-radius: 0;
        box-shadow: 0 18px 44px rgba(0,0,0,0.45);
    }
    .cart-info-scroll {
        max-height: 85vh;
        overflow-y: auto;
        overflow-x: hidden;
        padding: 1.1rem 1.15rem 1rem;
        scrollbar-gutter: stable;
        scrollbar-width: thin;
        scrollbar-color: rgba(83, 197, 224, 0.72) rgba(255, 255, 255, 0.08);
    }
    .cart-info-scroll::-webkit-scrollbar {
        width: 10px;
    }
    .cart-info-scroll::-webkit-scrollbar-track {
        background: rgba(255, 255, 255, 0.08);
        border-radius: 999px;
    }
    .cart-info-scroll::-webkit-scrollbar-thumb {
        background: rgba(83, 197, 224, 0.72);
        border-radius: 999px;
        border: 2px solid rgba(10, 37, 48, 0.95);
    }
    .cart-info-scroll::-webkit-scrollbar-thumb:hover {
        background: rgba(83, 197, 224, 0.9);
    }
    .cart-info-title {
        margin: 0 2rem 0.4rem 0;
        color: #eaf6fb;
        font-size: 1.05rem;
        font-weight: 800;
    }
    .cart-info-sub {
        margin: 0 0 0.85rem;
        color: #9fc6d9;
        font-size: 0.85rem;
    }
    .cart-info-list {
        margin: 0;
        padding: 0;
        list-style: none;
        display: grid;
        gap: 0.55rem;
    }
    .cart-info-list li {
        padding: 0.6rem 0.7rem;
        border: none;
        border-radius: 0;
        background: rgba(255, 255, 255, 0.04);
        color: #d9e6ef;
        font-size: 0.86rem;
        line-height: 1.45;
    }
    .cart-info-close {
        position: absolute;
        top: 0.6rem;
        right: 0.65rem;
        width: 30px;
        height: 30px;
        border-radius: 0;
        border: none;
        background: rgba(255,255,255,0.08);
        color: #d8edf5;
        font-size: 1.05rem;
        cursor: pointer;
    }
    .cart-info-actions {
        margin-top: 0.9rem;
        display: flex;
        justify-content: flex-end;
        gap: 0.65rem;
    }
    .cart-info-btn {
        height: 42px;
        min-width: 120px;
        padding: 0 1rem;
        border-radius: 0;
        border: none;
        background: rgba(255,255,255,0.05);
        color: #d9e6ef;
        font-weight: 700;
        font-size: 0.86rem;
        text-decoration: none;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        cursor: pointer;
    }
    .cart-info-btn-primary {
        border: none;
        background: linear-gradient(135deg, #53C5E0, #32a1c4);
        color: #fff;
        box-shadow: 0 10px 22px rgba(50,161,196,0.3);
    }
</style>

<div class="min-h-screen py-8 cart-theme-page">
    <div class="container mx-auto px-4" style="max-width:1100px;">
        <h1 class="text-2xl font-bold text-gray-800">Shopping Cart</h1>

        <?php 
        $customer_id = get_user_id();
        ?>

        <?php if (empty($cart_items)): ?>
            <div class="ct-empty">
                <div class="ct-empty-icon">🛒</div>
                <p>Your cart is empty</p>
                <a href="products.php" class="btn-primary">Start Shopping</a>
            </div>
        <?php else: ?>
            <form method="POST">
                <div class="card" style="padding:0;">
                    <div class="overflow-x-auto">
                        <table class="cart-table">
                            <thead style="background:rgba(8,30,39,0.85); font-size:0.875rem; text-transform:uppercase; letter-spacing:0.05em; color:#9fc6d9;">
                                <tr>
                                    <th style="padding:1rem; text-align:center; width: 50px;">
                                        <input type="checkbox" id="selectAll" class="cart-checkbox" onchange="toggleAll(this.checked)" <?php 
                                            $all_selected = true;
                                            foreach($cart_items as $item) if(!($item['selected']??true)) $all_selected = false;
                                            echo $all_selected ? 'checked' : '';
                                        ?>>
                                    </th>
                                    <th style="padding:1rem; text-align:left;">Product</th>
                                    <th style="padding:1rem; text-align:center;">Price</th>
                                    <th style="padding:1rem; text-align:center;">Quantity</th>
                                    <th style="padding:1rem; text-align:right;">Total</th>
                                    <th style="padding:1rem; width: 60px;"></th>
                                </tr>
                            </thead>
                            <tbody style="font-size:0.95rem;">
                                <?php foreach ($cart_items as $pid => $item): 
                                    $is_selected = $item['selected'] ?? true;
                                    $cat_lower = strtolower(($item['category'] ?? '') . ' ' . ($item['name'] ?? ''));
                                    $is_custom_row = false;
                                    $custom_keywords = ['tarpaulin', 't-shirt', 'shirt', 'sticker', 'decal', 'reflectorized', 'signage', 'glass', 'frosted', 'sintraboard', 'souvenir'];
                                    foreach($custom_keywords as $kw) { if(strpos($cat_lower, $kw) !== false) { $is_custom_row = true; break; } }
                                    $item_price = (float)($item['price'] ?? 0);
                                    $is_unpriced_row = ($item_price <= 0);
                                    $has_customization = !empty($item['customization']) && is_array($item['customization']);
                                    $has_service_artifacts = !empty($item['design_tmp_path']) || !empty($item['reference_tmp_path']);
                                    $explicit_service_type = (strcasecmp((string)($item['type'] ?? ''), 'Service') === 0);
                                    $is_service_item = ((int)($item['product_id'] ?? 0) <= 0) || $has_customization || $has_service_artifacts || $explicit_service_type || $is_custom_row;
                                    $source_page = strtolower(trim((string)($item['source_page'] ?? '')));
                                    if ($source_page === 'services') {
                                        $item_origin = 'Service';
                                    } elseif ($source_page === 'products') {
                                        $item_origin = 'Product';
                                    } else {
                                        $item_origin = $is_service_item ? 'Service' : 'Product';
                                    }
                                    $item_type_bg = ($item_origin === 'Product') ? 'rgba(16, 185, 129, 0.12)' : 'rgba(168, 85, 247, 0.12)';
                                    $item_type_border = ($item_origin === 'Product') ? 'rgba(16, 185, 129, 0.5)' : 'rgba(168, 85, 247, 0.5)';
                                    $item_type_text = ($item_origin === 'Product') ? '#065f46' : '#581c87';
                                    $item_name = (string)($item['name'] ?? 'Unknown Product');
                                    $item_name_limit = 24;
                                    if (function_exists('mb_strlen') && function_exists('mb_substr')) {
                                        $item_display_name = mb_strlen($item_name) > $item_name_limit
                                            ? mb_substr($item_name, 0, $item_name_limit) . '...'
                                            : $item_name;
                                    } else {
                                        $item_display_name = strlen($item_name) > $item_name_limit
                                            ? substr($item_name, 0, $item_name_limit) . '...'
                                            : $item_name;
                                    }
                                    $item_category = (string)($item['category'] ?? '');
                                    $qty_for_edit = max(1, (int)($item['quantity'] ?? 1));
                                    $modify_link = $base_url . '/customer/products.php';
                                    if (strpos($cat_lower, 't-shirt') !== false || strpos($cat_lower, 'shirt') !== false) {
                                        $modify_link = $base_url . '/customer/order_tshirt.php?qty=' . $qty_for_edit;
                                    } elseif (strpos($cat_lower, 'sintraboard') !== false || strpos($cat_lower, 'standee') !== false) {
                                        $modify_link = $base_url . '/customer/order_sintraboard.php?qty=' . $qty_for_edit;
                                    } elseif (strpos($cat_lower, 'reflectorized') !== false || strpos($cat_lower, 'signage') !== false) {
                                        $modify_link = $base_url . '/customer/order_reflectorized.php?qty=' . $qty_for_edit;
                                    } elseif (strpos($cat_lower, 'glass') !== false || strpos($cat_lower, 'frosted') !== false) {
                                        $modify_link = $base_url . '/customer/order_glass_stickers.php?qty=' . $qty_for_edit;
                                    } elseif (strpos($cat_lower, 'tarpaulin') !== false) {
                                        $modify_link = $base_url . '/customer/order_tarpaulin.php?qty=' . $qty_for_edit;
                                    } elseif (strpos($cat_lower, 'transparent') !== false) {
                                        $modify_link = $base_url . '/customer/order_transparent.php?qty=' . $qty_for_edit;
                                    } elseif (strpos($cat_lower, 'sticker') !== false || strpos($cat_lower, 'decal') !== false) {
                                        $modify_link = $base_url . '/customer/order_stickers.php?qty=' . $qty_for_edit;
                                    } elseif (strpos($cat_lower, 'souvenir') !== false) {
                                        $modify_link = $base_url . '/customer/order_souvenirs.php?qty=' . $qty_for_edit;
                                    }
                                    $modify_link .= (strpos($modify_link, '?') !== false ? '&' : '?') . 'edit_item=' . rawurlencode((string)$pid);
                                    $info_lines = [];
                                    $info_lines[] = ['label' => 'Category', 'value' => $item_category !== '' ? $item_category : 'N/A'];
                                    $info_lines[] = ['label' => 'Quantity', 'value' => (string)$qty_for_edit];
                                    if (!empty($item['customization']) && is_array($item['customization'])) {
                                        foreach ($item['customization'] as $k => $v) {
                                            if (is_array($v)) continue;
                                            $label = trim(str_replace('_', ' ', (string)$k));
                                            $value = trim((string)$v);
                                            if ($value === '') continue;
                                            $info_lines[] = ['label' => $label, 'value' => $value];
                                        }
                                    }
                                    $info_json = htmlspecialchars(json_encode($info_lines), ENT_QUOTES, 'UTF-8');
                                    
                                    // Determine product_id or service_id for redirect
                                    $data_product_id = '';
                                    $data_service_id = '';
                                    $onclick_handler = '';
                                    
                                    if ($item_origin === 'Product' && !empty($item['product_id'])) {
                                        $data_product_id = (int)$item['product_id'];
                                        $onclick_handler = "window.location.href='order_create.php?product_id={$data_product_id}&buy_now=1';";
                                    } elseif ($item_origin === 'Service') {
                                        // Try to get service_id from multiple sources
                                        if (!empty($item['customization']['service_id'])) {
                                            $data_service_id = (int)$item['customization']['service_id'];
                                        } elseif (!empty($item['service_id'])) {
                                            $data_service_id = (int)$item['service_id'];
                                        } elseif (!empty($item['product_id'])) {
                                            $data_service_id = (int)$item['product_id'];
                                        }
                                        
                                        // Fallback: Extract service_id from cart key (e.g., service_5_1234567890_123)
                                        if ($data_service_id <= 0 && preg_match('/^service_(\d+)_/', $pid, $matches)) {
                                            $data_service_id = (int)$matches[1];
                                        }
                                        
                                        if ($data_service_id > 0) {
                                            $onclick_handler = "window.location.href='<?php echo (defined('BASE_PATH') ? BASE_PATH : ''); ?>/customer/order/{$data_service_id}';";
                                        }
                                    }
                                ?>
                                    <tr class="cart-row" 
                                        data-id="<?php echo $pid; ?>" 
                                        data-price="<?php echo $item['price']; ?>" 
                                        data-custom="<?php echo $is_unpriced_row ? '1' : '0'; ?>" 
                                        data-product-id="<?php echo $data_product_id; ?>" 
                                        data-service-id="<?php echo $data_service_id; ?>" 
                                        data-item-origin="<?php echo $item_origin; ?>" 
                                        data-item-name="<?php echo htmlspecialchars($item_name, ENT_QUOTES, 'UTF-8'); ?>" 
                                        data-item-category="<?php echo htmlspecialchars($item_category, ENT_QUOTES, 'UTF-8'); ?>" 
                                        data-info-lines="<?php echo $info_json; ?>" 
                                        data-modify-link="<?php echo htmlspecialchars($modify_link, ENT_QUOTES, 'UTF-8'); ?>" 
                                        style="border-bottom:1px solid rgba(83,197,224,.14); transition: background 0.2s; <?php echo !$is_selected ? 'opacity: 0.6; background: rgba(255,255,255,.035);' : ''; ?>">
                                        <td style="padding:1rem; text-align:center;" onclick="event.stopPropagation();">
                                            <input type="checkbox" class="cart-checkbox item-checkbox" onchange="toggleItem('<?php echo $pid; ?>', this.checked)" <?php echo $is_selected ? 'checked' : ''; ?>>
                                        </td>
                                        <td style="padding:1rem; display:flex; align-items:center; gap:1rem; cursor:pointer; position:relative;" onclick="handleCartRowClick(this.closest('tr'), event);">
                                            <?php
                                            $prod_id = (int)($item['product_id'] ?? 0);
                                            $product_img = "";

                                            $cart_item_for_ui = $item;
                                            $cart_item_for_ui['_cart_key'] = (string)$pid;
                                            if ($item_origin === 'Service' || !empty($item['design_tmp_path']) || !empty($item['uploaded_files'])) {
                                                $design_media = pf_order_ui_resolve_item_design_urls(
                                                    $cart_item_for_ui,
                                                    true,
                                                    (string)$item_display_name
                                                );
                                                if (!empty($design_media['upload_is_previewable_image']) && !empty($design_media['upload_url'])) {
                                                    $product_img = $design_media['upload_url'];
                                                }
                                            }
                                            
                                            // 1. Prefer admin-uploaded photo_path, then legacy product_image
                                            if ($product_img === '' && $prod_id > 0) {
                                                $prod_data = db_query("SELECT photo_path, product_image FROM products WHERE product_id = ? LIMIT 1", 'i', [$prod_id]);
                                                if (!empty($prod_data)) {
                                                    $photo_path = trim((string)($prod_data[0]['photo_path'] ?? ''));
                                                    $legacy_image = trim((string)($prod_data[0]['product_image'] ?? ''));
                                                    if ($photo_path !== '') {
                                                        $product_img = printflow_cart_asset_url($photo_path, $base_url);
                                                    } elseif ($legacy_image !== '') {
                                                        $product_img = printflow_cart_asset_url($legacy_image, $base_url);
                                                    }
                                                }
                                            }
                                            
                                            // 2. Try explicit product ID (file-based fallback)
                                            if ($product_img === '' && $prod_id > 0) {
                                                $img_base = "../public/images/products/product_" . $prod_id;
                                                if (file_exists($img_base . ".jpg")) {
                                                    $product_img = $base_url . "/public/images/products/product_" . $prod_id . ".jpg";
                                                } elseif (file_exists($img_base . ".png")) {
                                                    $product_img = $base_url . "/public/images/products/product_" . $prod_id . ".png";
                                                }
                                            }
                                            
                                            // 3. Fallback based on category/service_type for Service Orders
                                            if ($product_img === '') {
                                                $cat_lower = strtolower(($item['category'] ?? '') . ' ' . ($item['name'] ?? ''));
                                                if (strpos($cat_lower, 'reflectorized') !== false || strpos($cat_lower, 'signage') !== false) {
                                                    $product_img = $base_url . "/public/images/products/signage.jpg";
                                                } elseif (strpos($cat_lower, 'tarpaulin') !== false) {
                                                    $product_img = $base_url . "/public/images/products/product_41.jpg";
                                                } elseif (strpos($cat_lower, 'sintraboard') !== false || strpos($cat_lower, 'standee') !== false) {
                                                    $product_img = $base_url . "/public/images/services/Sintraboard Standees.jpg";
                                                } elseif (strpos($cat_lower, 't-shirt') !== false || strpos($cat_lower, 'shirt') !== false) {
                                                    $product_img = $base_url . "/public/images/products/product_31.jpg";
                                                } elseif (strpos($cat_lower, 'sticker') !== false || strpos($cat_lower, 'decal') !== false) {
                                                    if (strpos($cat_lower, 'glass') !== false || strpos($cat_lower, 'frosted') !== false) {
                                                        $product_img = $base_url . "/public/images/products/Glass Stickers  Wall  Frosted Stickers.png";
                                                    } else {
                                                        $product_img = $base_url . "/public/images/products/product_21.jpg";
                                                    }
                                                } elseif (strpos($cat_lower, 'souvenir') !== false) {
                                                    $product_img = $base_url . "/public/assets/images/icon-192.png";
                                                }
                                            }
                                            ?>
                                            <div style="width:48px; height:48px; border-radius:6px; overflow:hidden; border:1px solid rgba(83,197,224,.22); background:rgba(255,255,255,.05); display:flex; align-items:center; justify-content:center; flex-shrink:0;">
                                                <?php if (!empty($product_img)): ?>
                                                    <img src="<?php echo $product_img; ?>" style="width:100%; height:100%; object-fit:cover;" alt="Product">
                                                <?php else: ?>
                                                    <img src="<?php echo $base_url; ?>/public/assets/images/icon-192.png" style="width:70%; height:70%; object-fit:contain; opacity:0.8;" alt="Logo">
                                                <?php endif; ?>
                                            </div>
                                            <div class="cart-item-meta">
                                                <div class="cart-item-name"><?php echo htmlspecialchars($item_display_name); ?></div>
                                                <?php if (!empty($item['variant_name'])): ?>
                                                    <div style="font-size:0.75rem; color:#475569;"><?php echo htmlspecialchars((string)$item['variant_name']); ?></div>
                                                <?php endif; ?>
                                                <?php if (!empty($item['category'])): ?>
                                                    <div style="font-size:0.75rem; color:#64748b;"><?php echo htmlspecialchars($item['category']); ?></div>
                                                <?php endif; ?>
                                            </div>
                                            <span class="cart-item-type-badge" style="border:1px solid <?php echo $item_type_border; ?>; background:<?php echo $item_type_bg; ?>; color:<?php echo $item_type_text; ?>;">
                                                <?php echo $item_origin; ?>
                                            </span>
                                        </td>
                                        <td style="padding:1rem; text-align:center;" onclick="event.stopPropagation();">
                                            <?php 
                                            if ($is_unpriced_row): ?>
                                                <span style="font-size:0.75rem; color:#64748b; font-style:italic;">To be confirmed</span>
                                            <?php else: ?>
                                                <span style="color:#0f172a; font-weight:600;"><?php echo str_replace('PHP', '₱', format_currency($item['price'])); ?></span>
                                            <?php endif; ?>
                                        </td>
                                        <td style="padding:1rem; text-align:center;" onclick="event.stopPropagation();">
                                            <div class="qty-control">
                                                <button type="button" class="qty-btn" onclick="updateQty('<?php echo $pid; ?>', -1)" <?php echo $item['quantity'] <= 1 ? 'disabled' : ''; ?>>−</button>
                                                <span class="qty-val" id="qty-<?php echo $pid; ?>"><?php echo $item['quantity']; ?></span>
                                                <button type="button" class="qty-btn" onclick="updateQty('<?php echo $pid; ?>', 1)" <?php echo $item['quantity'] >= 99 ? 'disabled' : ''; ?>>+</button>
                                            </div>
                                        </td>
                                        <td style="padding:1rem; text-align:right; font-weight:600;" id="total-<?php echo $pid; ?>" onclick="event.stopPropagation();">
                                            <div class="cart-total-wrap">
                                                <?php if ($is_unpriced_row): ?>
                                                    <span class="cart-line-total" style="font-size:0.75rem; color:#64748b; font-style:italic;">To be confirmed</span>
                                                <?php else: ?>
                                                    <span style="color:#0f172a; font-weight:600;"><?php echo str_replace('PHP', '₱', format_currency($item['price'] * $item['quantity'])); ?></span>
                                                <?php endif; ?>
                                                <button type="button" class="trash-btn cart-delete-mobile" onclick="confirmRemove('<?php echo $pid; ?>')" title="Remove">
                                                    <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                                        <path stroke-linecap="round" stroke-linejoin="round" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                                                    </svg>
                                                </button>
                                            </div>
                                        </td>
                                        <td class="cart-delete-cell" style="padding:1rem;" onclick="event.stopPropagation();">
                                            <button type="button" class="trash-btn" onclick="confirmRemove('<?php echo $pid; ?>')" title="Remove">
                                                <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                                    <path stroke-linecap="round" stroke-linejoin="round" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                                                </svg>
                                            </button>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    
                    <div style="padding:1.5rem; background:#f8fafc; border-top:1px solid #e2e8f0; display:flex; justify-content:space-between; align-items:center;">
                        <a href="products.php" class="btn-secondary" style="padding:0.5rem 1.25rem; border-radius:6px; font-weight: 500; text-decoration: none;">Continue Shopping</a>
                        
                        <div style="text-align:right;">
                            <div style="font-size:0.875rem; color:#64748b; margin-bottom:0.25rem;">Subtotal <?php echo $has_custom ? '(Priced Items only)' : ''; ?></div>
                            <div style="font-size:1.5rem; font-weight:700; color:#0f172a; margin-bottom:1rem;" id="cart-total"><?php echo str_replace('PHP', '₱', format_currency($total)); ?></div>
                            <?php if ($has_custom): ?>
                                <div style="font-size:0.75rem; color:#64748b; font-style:italic; margin-top:-0.5rem; margin-bottom:1rem;">+ Custom items (Price will be confirmed by the shop)</div>
                            <?php endif; ?>
                            
                            <!-- Mixed Items Warning -->
                            <div id="mixed-items-warning" style="display:none; align-items:flex-start; gap:0.5rem; background:#fffbeb; border:1px solid #fde68a; border-radius:8px; padding:0.75rem; margin-bottom:1rem; text-align:left;">
                                <span style="font-size:1.25rem; flex-shrink:0; line-height:1;">⚠️</span>
                                <div style="flex:1;">
                                    <div style="font-size:0.8rem; font-weight:700; color:#92400e; margin-bottom:0.25rem;">Products and custom services cannot be processed together.</div>
                                    <div style="font-size:0.75rem; color:#b45309; line-height:1.4;">Please checkout products separately or customize services to get the price first.</div>
                                </div>
                            </div>
                            
                            <button type="button" onclick="proceedToReview()" id="checkout-btn" class="btn-primary" style="padding:0.75rem 2rem; <?php echo $total <= 0 ? 'opacity: 0.5; pointer-events: none;' : ''; ?>">Proceed to Checkout</button>
                        </div>
                    </div>
                </div>
            </form>
        <?php endif; ?>
    </div>
</div>

<!-- Remove Confirmation Modal -->
<div id="removeModal" style="display:none; position:fixed; inset:0; z-index:50; align-items:center; justify-content:center;">
    <div style="position:absolute; inset:0; background:rgba(15,23,42,0.45);" onclick="closeRemoveModal()"></div>
    <div style="position:relative; background:rgba(10,37,48,.97); padding:2rem; border-radius:0; max-width:400px; width:90%; box-shadow:0 20px 25px -5px rgba(0,0,0,0.4); z-index:51;">
        <h3 style="font-size:1.25rem; font-weight:700; color:#eaf6fb; margin-bottom:0.5rem;">Remove from Cart?</h3>
        <p style="color:#b9d4df; margin-bottom:1.5rem; line-height:1.5;">Are you sure you want to remove this item from your shopping cart?</p>
        <div style="display:flex; justify-content:flex-end; gap:0.75rem;">
            <button type="button" onclick="closeRemoveModal()" style="padding:0.5rem 1.25rem; border-radius:0; background:rgba(255,255,255,.08); color:#d9e6ef; font-weight:600; border:none; cursor:pointer; transition:background 0.2s;">Cancel</button>
            <button type="button" onclick="handleRemoveItem()" style="padding:0.5rem 1.25rem; border-radius:0; background:#ef4444; color:white; font-weight:600; border:none; cursor:pointer; transition:background 0.2s;">Delete</button>
        </div>
    </div>
</div>

<div id="cartInfoModal" class="cart-info-modal">
    <div class="cart-info-modal-backdrop" onclick="closeCartInfoModal()"></div>
    <div class="cart-info-modal-card" role="dialog" aria-modal="true" aria-labelledby="cartInfoTitle">
        <button type="button" class="cart-info-close" onclick="closeCartInfoModal()" aria-label="Close">×</button>
        <div class="cart-info-scroll">
            <h3 id="cartInfoTitle" class="cart-info-title"></h3>
            <p id="cartInfoSub" class="cart-info-sub"></p>
            <ul id="cartInfoList" class="cart-info-list"></ul>
            <div class="cart-info-actions">
                <button type="button" class="cart-info-btn" onclick="closeCartInfoModal()">Close</button>
                <button id="cartInfoModifyBtn" type="button" class="cart-info-btn cart-info-btn-primary">View Only</button>
            </div>
        </div>
    </div>
</div>

<script>
var PF_CSRF_TOKEN = '<?php echo generate_csrf_token(); ?>';

function openCartInfoModal(row) {
    if (!row) return;
    const titleEl = document.getElementById('cartInfoTitle');
    const subEl = document.getElementById('cartInfoSub');
    const listEl = document.getElementById('cartInfoList');
    const modal = document.getElementById('cartInfoModal');
    const modifyBtn = document.getElementById('cartInfoModifyBtn');
    if (!titleEl || !subEl || !listEl || !modal || !modifyBtn) return;

    const itemName = row.getAttribute('data-item-name') || 'Cart Item';
    const itemCategory = row.getAttribute('data-item-category') || '';
    const infoJson = row.getAttribute('data-info-lines') || '[]';
    const modifyLink = row.getAttribute('data-modify-link') || '<?php echo $base_url; ?>/customer/services.php';

    titleEl.textContent = itemName;
    subEl.textContent = itemCategory;
    if (modifyBtn) {
        modifyBtn.onclick = function() {
            closeCartInfoModal();
        };
    }
    listEl.innerHTML = '';

    try {
        const lines = JSON.parse(infoJson);
        lines.forEach(function(line) {
            if (!line || !line.label) return;
            const li = document.createElement('li');
            const strong = document.createElement('strong');
            strong.textContent = String(line.label) + ':';
            li.appendChild(strong);
            li.appendChild(document.createTextNode(' ' + String(line.value || '')));
            listEl.appendChild(li);
        });
    } catch (e) {
        const li = document.createElement('li');
        li.textContent = 'No additional details.';
        listEl.appendChild(li);
    }

    modal.style.display = 'flex';
}

function closeCartInfoModal() {
    const modal = document.getElementById('cartInfoModal');
    if (modal) modal.style.display = 'none';
}

// Handle remove button click
var removeItemId = '';

function confirmRemove(pid) {
    removeItemId = pid;
    document.getElementById('removeModal').style.display = 'flex';
}

function closeRemoveModal() {
    document.getElementById('removeModal').style.display = 'none';
    removeItemId = '';
}

async function handleRemoveItem() {
    if (!removeItemId) return;
    
    try {
        const res = await fetch('api_cart.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'remove', cart_key: removeItemId, csrf_token: PF_CSRF_TOKEN })
        });
        const data = await res.json();
        if (data.success) {
            if (window.updateCartBadge) updateCartBadge(data.cart_count);
            const row = document.querySelector(`.cart-row[data-id="${removeItemId}"]`);
            if (row) row.remove();
            closeRemoveModal();
            const remainingRows = document.querySelectorAll('.cart-row');
            if (remainingRows.length === 0) {
                window.location.reload();
            } else {
                recalculateTotal();
                checkSelectAllState();
            }
        } else {
            showCartToast(data.message || 'Failed to remove item.', true);
            closeRemoveModal();
        }
    } catch (err) {
        console.error(err);
        showCartToast('An error occurred.', true);
        closeRemoveModal();
    }
}

async function updateQty(pid, delta) {
    const span = document.getElementById(`qty-${pid}`);
    if (!span) return;

    let currentQty = parseInt(span.textContent);
    let newQty = currentQty + delta;
    if (newQty < 1) return;

    // Check overall cart total — only block increases
    if (delta > 0) {
        const cartTotal = getCartTotal();
        const newCartTotal = cartTotal - currentQty + newQty;
        if (newCartTotal > 99) {
            showCartToast('Your cart is full.', true);
            return;
        }
    }

    // Optimistic UI
    span.textContent = newQty;
    const row = document.querySelector(`.cart-row[data-id="${pid}"]`);
    const price = parseFloat(row.dataset.price);
    const totalCell = document.getElementById(`total-${pid}`);
    const lineTotalText = totalCell ? (totalCell.querySelector('.cart-line-total') || totalCell.querySelector('span')) : null;
    if (lineTotalText) {
        if (price > 0) {
            lineTotalText.textContent = PHP(price * newQty);
        }
    }

    const minusBtn = row.querySelector('.qty-btn:first-child');
    const plusBtn = row.querySelector('.qty-btn:last-child');
    minusBtn.disabled = (newQty <= 1);
    plusBtn.disabled = (getCartTotal() >= 99);

    recalculateTotal();
    updateAllPlusBtns();

    try {
        const res = await fetch('api_cart.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'update', cart_key: pid, quantity: newQty, csrf_token: PF_CSRF_TOKEN })
        });
        const data = await res.json();
        if (!data.success) {
            showCartToast(data.message || 'Failed to update quantity.', true);
            span.textContent = currentQty;
            if (lineTotalText && price > 0) lineTotalText.textContent = PHP(price * currentQty);
            minusBtn.disabled = (currentQty <= 1);
            plusBtn.disabled = false;
            recalculateTotal();
            updateAllPlusBtns();
        } else {
            if (window.updateCartBadge) updateCartBadge(data.cart_count);
        }
    } catch (err) {
        console.error(err);
        span.textContent = currentQty;
        if (lineTotalText && price > 0) lineTotalText.textContent = PHP(price * currentQty);
        minusBtn.disabled = (currentQty <= 1);
        plusBtn.disabled = false;
        recalculateTotal();
        updateAllPlusBtns();
        showCartToast('Failed to save quantity. Please try again.', true);
    }
}

function getCartTotal() {
    let total = 0;
    document.querySelectorAll('.qty-val').forEach(s => { total += parseInt(s.textContent) || 0; });
    return total;
}

function updateAllPlusBtns() {
    const full = getCartTotal() >= 99;
    document.querySelectorAll('.cart-row').forEach(row => {
        const plusBtn = row.querySelector('.qty-btn:last-child');
        if (plusBtn) plusBtn.disabled = full;
    });
}

function showCartToast(msg, isError) {
    let toast = document.getElementById('cart-toast');
    if (!toast) {
        toast = document.createElement('div');
        toast.id = 'cart-toast';
        document.body.appendChild(toast);
    }
    toast.textContent = msg;
    toast.style.cssText = `position:fixed;bottom:5rem;left:50%;transform:translateX(-50%);background:${isError ? 'rgba(239,68,68,0.92)' : 'rgba(0,0,0,0.85)'};color:white;padding:12px 24px;border-radius:4px;font-size:0.9rem;font-weight:500;z-index:10000;transition:opacity 0.3s;pointer-events:none;box-shadow:0 4px 12px rgba(0,0,0,0.2);`;
    toast.style.opacity = '1';
    clearTimeout(toast._timer);
    toast._timer = setTimeout(() => { toast.style.opacity = '0'; }, 2500);
}

async function toggleItem(pid, selected) {
    const row = document.querySelector(`.cart-row[data-id="${pid}"]`);
    if (selected) {
        row.style.opacity = '1';
        row.style.background = 'transparent';
    } else {
        row.style.opacity = '0.6';
        row.style.background = 'rgba(255,255,255,.035)';
    }
    
    checkSelectAllState();
    recalculateTotal();
    
    try {
        await fetch('api_cart.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                action: 'toggle_select',
                cart_key: pid,
                selected: selected,
                csrf_token: PF_CSRF_TOKEN
            })
        });
    } catch (err) { console.error(err); }
}

async function toggleAll(selected) {
    const checkboxes = document.querySelectorAll('.item-checkbox');
    checkboxes.forEach(cb => {
        cb.checked = selected;
        const pid = cb.closest('.cart-row').dataset.id;
        const row = cb.closest('.cart-row');
        if (selected) {
            row.style.opacity = '1';
            row.style.background = 'transparent';
        } else {
            row.style.opacity = '0.6';
            row.style.background = 'rgba(255,255,255,.035)';
        }
    });
    
    recalculateTotal();
    
    try {
        await fetch('api_cart.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                action: 'select_all',
                selected: selected,
                csrf_token: PF_CSRF_TOKEN
            })
        });
    } catch (err) { console.error(err); }
}

function checkSelectAllState() {
    const all = document.querySelectorAll('.item-checkbox');
    const checked = document.querySelectorAll('.item-checkbox:checked');
    document.getElementById('selectAll').checked = (all.length === checked.length);
}

function recalculateTotal() {
    let subtotal = 0;
    let hasProduct = false;
    let hasService = false;
    let selectedCount = 0;
    
    const rows = document.querySelectorAll('.cart-row');
    rows.forEach(row => {
        const checkbox = row.querySelector('.item-checkbox');
        if (checkbox.checked) {
            selectedCount += 1;
            const isCustom = row.dataset.custom === '1';
            const itemOrigin = (row.dataset.itemOrigin || '').trim();
            
            // Track if we have products or services
            if (itemOrigin === 'Product') {
                hasProduct = true;
            } else if (itemOrigin === 'Service') {
                hasService = true;
            }
            
            if (!isCustom) {
                const pid = row.dataset.id;
                const price = parseFloat(row.dataset.price);
                const qty = parseInt(document.getElementById(`qty-${pid}`).textContent);
                subtotal += price * qty;
            }
        }
    });
    
    document.getElementById('cart-total').textContent = PHP(subtotal);
    
    // Check if mixing products and services
    const hasMixedItems = hasProduct && hasService;
    const checkoutBtn = document.getElementById('checkout-btn');
    const mixedWarning = document.getElementById('mixed-items-warning');
    
    if (checkoutBtn) {
        if (hasMixedItems) {
            // Disable checkout if mixing products and services
            checkoutBtn.style.opacity = '0.5';
            checkoutBtn.style.pointerEvents = 'none';
            checkoutBtn.style.cursor = 'not-allowed';
            if (mixedWarning) mixedWarning.style.display = 'flex';
        } else if (selectedCount === 0) {
            // Disable if no items are selected
            checkoutBtn.style.opacity = '0.5';
            checkoutBtn.style.pointerEvents = 'none';
            checkoutBtn.style.cursor = 'not-allowed';
            if (mixedWarning) mixedWarning.style.display = 'none';
        } else if (subtotal <= 0 && !hasService) {
            // Disable only if the selection has no payable products and no services to inquire about
            checkoutBtn.style.opacity = '0.5';
            checkoutBtn.style.pointerEvents = 'none';
            checkoutBtn.style.cursor = 'not-allowed';
            if (mixedWarning) mixedWarning.style.display = 'none';
        } else {
            // Enable checkout
            checkoutBtn.style.opacity = '1';
            checkoutBtn.style.pointerEvents = 'auto';
            checkoutBtn.style.cursor = 'pointer';
            if (mixedWarning) mixedWarning.style.display = 'none';
        }
    }
}

function PHP(amount) {
    return '₱' + amount.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

function proceedToReview() {
    // Get all selected items
    const selectedRows = document.querySelectorAll('.cart-row .item-checkbox:checked');
    if (selectedRows.length === 0) {
        showCartToast('Please select at least one item to checkout.', true);
        return;
    }
    
    // Check if mixing products and services
    let hasProduct = false;
    let hasService = false;
    selectedRows.forEach(checkbox => {
        const row = checkbox.closest('.cart-row');
        const itemOrigin = (row.dataset.itemOrigin || '').trim();
        if (itemOrigin === 'Product') hasProduct = true;
        if (itemOrigin === 'Service') hasService = true;
    });
    
    if (hasProduct && hasService) {
        showCartToast('Cannot checkout products and services together. Please select only one type.', true);
        return;
    }
    
    // Collect all selected item keys
    const itemKeys = [];
    selectedRows.forEach(checkbox => {
        const row = checkbox.closest('.cart-row');
        itemKeys.push(row.dataset.id);
    });
    
    // Redirect to order review with all selected items (comma-separated)
    window.location.href = 'order_review.php?item=' + encodeURIComponent(itemKeys.join(','));
}

function handleCartRowClick(row, event) {
    const itemOrigin = row.getAttribute('data-item-origin');
    const productId = row.getAttribute('data-product-id');
    const serviceId = row.getAttribute('data-service-id');
    const cartKey = row.getAttribute('data-id');
    
    console.log('=== CART ROW CLICKED ===');
    console.log('Item Origin:', itemOrigin);
    console.log('Product ID:', productId);
    console.log('Service ID:', serviceId);
    console.log('Cart Key:', cartKey);
    
    if (itemOrigin === 'Service') {
        if (serviceId && serviceId !== '' && serviceId !== '0') {
            const url = 'order_service_dynamic.php?service_id=' + encodeURIComponent(serviceId) + '&edit_item=' + encodeURIComponent(cartKey);
            console.log('✓ Redirecting to:', url);
            window.location.href = url;
        } else {
            console.error('✗ Service ID is invalid:', serviceId);
            const match = cartKey.match(/^service_(\d+)_/);
            if (match && match[1]) {
                const extractedId = match[1];
                const url = 'order_service_dynamic.php?service_id=' + encodeURIComponent(extractedId) + '&edit_item=' + encodeURIComponent(cartKey);
                console.log('✓ Extracted service_id:', extractedId, '- Redirecting to:', url);
                window.location.href = url;
            } else {
                showToast('Cannot view this service item.');
            }
        }
    } else if (itemOrigin === 'Product') {
        if (productId && productId !== '' && productId !== '0') {
            const url = 'order_create.php?product_id=' + productId + '&edit_item=' + encodeURIComponent(cartKey);
            console.log('✓ Redirecting to product:', url);
            window.location.href = url;
        } else {
            console.warn('✗ Product has no valid ID');
        }
    } else {
        console.warn('✗ Unknown item origin:', itemOrigin);
    }
}

document.addEventListener('DOMContentLoaded', function() {
    updateAllPlusBtns();
    
    // Update cart badge on page load
    if (window.updateCartBadge) {
        const cartTotal = getCartTotal();
        updateCartBadge(cartTotal);
    }
    
    // Check for mixed items on page load
    recalculateTotal();
    
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') closeCartInfoModal();
    });
});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
