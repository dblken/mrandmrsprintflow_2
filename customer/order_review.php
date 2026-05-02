<?php
/**
 * Order Review & Confirm Page
 * PrintFlow — Shown when customer clicks "Buy Now"
 * Displays full order summary with design image preview,
 * customization details, price, and Cancel / Confirm buttons.
 */

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/order_ui_helper.php';
require_once __DIR__ . '/../includes/JobOrderService.php';

require_role('Customer');
require_once __DIR__ . '/../includes/require_customer_profile_complete.php';
require_once __DIR__ . '/../includes/require_id_verified.php';

function review_resolve_catalog_image(?string $path): ?string {
    $path = trim((string)$path);
    if ($path === '') {
        return null;
    }

    $resolved = pf_order_ui_asset_url($path);
    return $resolved !== '' ? $resolved : null;
}

function review_enrich_cart_item(array $item): array {
    $custom = review_item_customization($item);
    $is_product = review_item_is_product($item);
    $product_id = (int)($item['product_id'] ?? 0);

    if ($is_product && $product_id > 0) {
        $product_rows = db_query(
            "SELECT name, category, photo_path, product_image, product_type FROM products WHERE product_id = ? LIMIT 1",
            'i',
            [$product_id]
        );
        if (!empty($product_rows)) {
            $product = $product_rows[0];
            if (empty($item['name']) && !empty($product['name'])) {
                $item['name'] = $product['name'];
            }
            if (empty($item['category']) && !empty($product['category'])) {
                $item['category'] = $product['category'];
            }
            $item['catalog_product_type'] = $product['product_type'] ?? 'fixed';
            $catalog_image = review_resolve_catalog_image($product['photo_path'] ?? '')
                ?: review_resolve_catalog_image($product['product_image'] ?? '');
            if (!empty($catalog_image)) {
                $item['product_image'] = $catalog_image;
            }
        }
    } else {
        $service_name = '';
        if (!empty($item['service_id'])) {
            $service_rows = db_query(
                "SELECT name, category, display_image, hero_image FROM services WHERE service_id = ? LIMIT 1",
                'i',
                [(int)$item['service_id']]
            );
            if (!empty($service_rows)) {
                $service = $service_rows[0];
                $service_name = trim((string)($service['name'] ?? ''));
                if ($service_name !== '') {
                    $item['name'] = $service_name;
                    $custom['service_type'] = $service_name;
                    $item['customization'] = $custom;
                    if (empty($item['category']) && !empty($service['category'])) {
                        $item['category'] = $service['category'];
                    }
                }
                $display_image = trim((string)($service['display_image'] ?? ''));
                $hero_image = trim((string)($service['hero_image'] ?? ''));
                $first_image = $display_image !== '' ? trim(explode(',', $display_image)[0]) : $hero_image;
                $catalog_image = review_resolve_catalog_image($first_image);
                if (!empty($catalog_image)) {
                    $item['product_image'] = $catalog_image;
                }
            }
        }

        if ($service_name === '') {
            $resolved_name = printflow_resolve_order_item_name($item['name'] ?? 'Order Item', $custom, 'Order Item');
            if ($resolved_name !== '') {
                $item['name'] = $resolved_name;
            }
        }
    }

    return $item;
}

function review_render_item_summary(array $item): void {
    $name = trim((string)($item['name'] ?? 'Order Item'));
    $category = trim((string)($item['category'] ?? 'Service'));
    $quantity = review_item_quantity($item);
    $is_service_item = review_item_is_service($item);
    $unit_price = review_item_unit_price($item);
    $subtotal = $unit_price * $quantity;
    $estimated_total = review_item_estimated_total($item);
    $estimated_total_display = $estimated_total > 0 ? format_currency($estimated_total) : 'To Be Discussed';
    ?>
    <div style="background:#0a2530;border:1px solid rgba(83,197,224,0.24);border-radius:16px;padding:1.25rem;margin-bottom:1.5rem;color:#eaf6fb;">
        <h3 style="font-size:0.95rem;font-weight:700;margin:0 0 0.35rem;"><?php echo htmlspecialchars($name, ENT_QUOTES, 'UTF-8'); ?></h3>
        <div style="font-size:0.72rem;font-weight:700;color:#53c5e0;text-transform:uppercase;margin-bottom:1rem;"><?php echo htmlspecialchars($category, ENT_QUOTES, 'UTF-8'); ?></div>
        <div style="display:flex;gap:1rem;flex-wrap:wrap;">
            <div><span style="color:#9fc4d4;font-size:0.68rem;text-transform:uppercase;font-weight:700;">Quantity</span><br><strong><?php echo $quantity; ?></strong></div>
            <?php if (!$is_service_item): ?>
            <div><span style="color:#9fc4d4;font-size:0.68rem;text-transform:uppercase;font-weight:700;">Unit Price</span><br><strong><?php echo format_currency($unit_price); ?></strong></div>
            <div><span style="color:#53c5e0;font-size:0.68rem;text-transform:uppercase;font-weight:700;">Total</span><br><strong style="color:#53c5e0;"><?php echo format_currency($subtotal); ?></strong></div>
            <?php else: ?>
            <div><span style="color:#53c5e0;font-size:0.68rem;text-transform:uppercase;font-weight:700;">Estimated Price</span><br><strong style="color:#53c5e0;"><?php echo htmlspecialchars($estimated_total_display, ENT_QUOTES, 'UTF-8'); ?></strong></div>
            <?php endif; ?>
        </div>
    </div>
    <?php
}

function review_item_is_product(array $item): bool {
    $custom = review_item_customization($item);
    $source_page = strtolower(trim((string)($item['source_page'] ?? '')));
    $item_type = strtolower(trim((string)($item['type'] ?? '')));
    $catalog_product_type = strtolower(trim((string)($item['catalog_product_type'] ?? '')));
    $cart_key = strtolower(trim((string)($item['_cart_key'] ?? '')));
    $product_id = (int)($item['product_id'] ?? 0);
    $service_id = (int)($item['service_id'] ?? 0);

    if ($source_page === 'products' || $source_page === 'dynamic_form' || $item_type === 'product' || strpos($cart_key, 'product_') === 0) {
        return true;
    }

    if ($source_page === 'services' || $item_type === 'service' || strpos($cart_key, 'service_') === 0 || $service_id > 0 || !empty($custom['service_type'])) {
        return false;
    }

    if (in_array($catalog_product_type, ['fixed', 'fixed product', 'product'], true)) {
        return true;
    }
    if ($catalog_product_type === 'custom') {
        return false;
    }

    return $product_id > 0;
}

function review_item_is_service(array $item): bool {
    return !review_item_is_product($item);
}

function review_catalog_unit_price(array $item): ?float {
    static $cache = [];

    $product_id = (int)($item['product_id'] ?? 0);
    if ($product_id <= 0) {
        return null;
    }

    $variant_id = isset($item['variant_id']) ? (int)$item['variant_id'] : 0;
    $cache_key = $product_id . ':' . $variant_id;
    if (array_key_exists($cache_key, $cache)) {
        return $cache[$cache_key];
    }

    if ($variant_id > 0) {
        $variant = db_query(
            "SELECT price FROM product_variants WHERE variant_id = ? AND product_id = ? LIMIT 1",
            'ii',
            [$variant_id, $product_id]
        );
        if (!empty($variant)) {
            return $cache[$cache_key] = (float)$variant[0]['price'];
        }
    }

    $product = db_query(
        "SELECT price FROM products WHERE product_id = ? LIMIT 1",
        'i',
        [$product_id]
    );

    return $cache[$cache_key] = (!empty($product) ? (float)$product[0]['price'] : null);
}

function review_item_unit_price(array $item): float {
    $raw_price = (float)($item['price'] ?? $item['unit_price'] ?? $item['estimated_price'] ?? 0);
    if (!review_item_is_product($item)) {
        return $raw_price;
    }

    $catalog_unit_price = review_catalog_unit_price($item);
    if ($catalog_unit_price === null || $catalog_unit_price <= 0) {
        return $raw_price;
    }

    $quantity = review_item_quantity($item);
    if ($raw_price <= 0) {
        return $catalog_unit_price;
    }

    if (abs($raw_price - $catalog_unit_price) < 0.01) {
        return $catalog_unit_price;
    }

    if ($quantity > 1 && abs($raw_price - ($catalog_unit_price * $quantity)) < 0.01) {
        return $catalog_unit_price;
    }

    return $raw_price;
}

function review_item_quantity(array $item): int {
    return max(1, (int)($item['quantity'] ?? 1));
}

function review_item_estimated_total(array $item): float {
    $estimated = (float)($item['estimated_price'] ?? 0);
    if ($estimated > 0) {
        return $estimated;
    }
    return 0.0;
}

function review_item_customization(array $item): array {
    $custom = $item['customization'] ?? [];
    if (is_string($custom)) {
        $decoded = json_decode($custom, true);
        return is_array($decoded) ? $decoded : [];
    }

    return is_array($custom) ? $custom : [];
}

// ── Accept the "buy_now" item key(s) from session ──────────────────
$item_key = $_REQUEST['item'] ?? '';
$cart     = $_SESSION['cart'] ?? [];

// Support multiple items separated by comma
$item_keys = array_filter(array_map('trim', explode(',', $item_key)));
if (empty($item_keys)) {
    redirect('products.php');
}

// Collect all valid items
$items_to_review = [];
foreach ($item_keys as $key) {
    if (isset($cart[$key]) && is_array($cart[$key])) {
        $items_to_review[$key] = $cart[$key];
        $items_to_review[$key]['_cart_key'] = $key;
        $items_to_review[$key] = review_enrich_cart_item($items_to_review[$key]);
    }
}

if (empty($items_to_review)) {
    redirect('products.php');
}

$review_has_product = false;
$review_has_service = false;
foreach ($items_to_review as $item) {
    if (review_item_is_product($item)) {
        $review_has_product = true;
    } else {
        $review_has_service = true;
    }
}

if ($review_has_product && $review_has_service) {
    $_SESSION['error'] = 'Products and services must be checked out separately.';
    redirect('cart.php');
}

$customer_id = get_user_id();
$customer    = db_query("SELECT * FROM customers WHERE customer_id = ?", 'i', [$customer_id])[0] ?? [];
$customer_type = $customer['customer_type'] ?? 'new';
$address_parts = [
    trim((string)($customer['address'] ?? '')),
    trim((string)($customer['street'] ?? '')),
    trim((string)($customer['barangay'] ?? '')),
    trim((string)($customer['city'] ?? '')),
    trim((string)($customer['province'] ?? '')),
];
$address_parts = array_values(array_filter($address_parts, fn($p) => $p !== ''));
$customer_address = !empty($address_parts) ? implode(', ', $address_parts) : '—';

// Fetch active branches for selection
$branches = db_query("SELECT id, branch_name FROM branches WHERE status = 'Active'") ?: [];

// ── Determine if branch selection is needed ──────────────────────
$is_multiple_checkout = count($items_to_review) > 1;
$needs_branch_selection = $is_multiple_checkout; // Forced for multiple
if (!$needs_branch_selection) {
    foreach ($items_to_review as $item) {
        if (empty($item['branch_id'])) {
            $needs_branch_selection = true;
            break;
        }
    }
}

// Get branch_id from items if already selected
$selected_branch_id_from_item = null;
if (!$needs_branch_selection) {
    foreach ($items_to_review as $item) {
        if (!empty($item['branch_id'])) {
            $selected_branch_id_from_item = $item['branch_id'];
            break;
        }
    }
}

// Handle Place Order FIRST (to allow clearing cart without trigger redirect)
$order_error = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['confirm_order'])) {
    error_log('=== ORDER REVIEW POST RECEIVED ===');
    error_log('POST data: ' . print_r($_POST, true));
    error_log('Current URL: ' . $_SERVER['REQUEST_URI']);
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $order_error = 'Invalid request. Please try again.';
    } else {
        // Validate branch selection
        $selected_branch_id = (int)($_POST['branch_id'] ?? 0);
        
        // STRICT REQUIREMENT: If the field was shown ($needs_branch_selection), it MUST be in POST.
        // ONLY fallback if selection was NOT required (single item with existing branch).
        if ($selected_branch_id < 1 && !$needs_branch_selection) {
            foreach ($items_to_review as $item) {
                if (!empty($item['branch_id'])) {
                    $selected_branch_id = (int)$item['branch_id'];
                    break;
                }
            }
        }
        
        if ($selected_branch_id < 1 && $needs_branch_selection) {
            $order_error = 'Please select a branch for pickup.';
        } else {
            // 1. Calculate totals and determine order properties
            $grand_total = 0;
            $order_type = 'product';
            $reference_id = null;
            $all_notes = [];
            
            foreach ($items_to_review as $item) {
                $custom = review_item_customization($item);
                $subtotal = review_item_is_service($item)
                    ? review_item_estimated_total($item)
                    : (review_item_unit_price($item) * review_item_quantity($item));
                $grand_total += $subtotal;
                
                if ($reference_id === null && !empty($item['product_id'])) {
                    $reference_id = $item['product_id'];
                }
                
                if (review_item_is_service($item)) {
                    $order_type = 'custom';
                }

                if (!empty($custom)) {
                    $note = $custom['notes'] ?? $custom['additional_notes'] ?? null;
                    if ($note) $all_notes[] = function_exists('pf_order_ui_value_to_text') ? pf_order_ui_value_to_text($note) : (string)$note;
                }
            }
            
            $notes_summary = !empty($all_notes) ? implode('; ', $all_notes) : null;

            if (false) {
                $order_error = "🚫 Your account is restricted from placing new orders.";
            } else {
                global $conn;
                $downpayment_amount = 0;
                $payment_type = 'full_payment';
                $payment_status = 'Unpaid';
                // For service/custom orders, set status to 'Pending' so staff can set price (Step 1)
                // For product orders, set status to 'To Pay' so customer can pay immediately
                $order_status = ($order_type === 'custom') ? 'Pending' : 'To Pay';
                $branch_id = $selected_branch_id;
                
                // For service/custom orders, save estimated_price and set total_amount to estimated total
                $estimated_price = ($order_type === 'custom') ? $grand_total : null;
                $order_total_amount = $grand_total;

                // 2. Create Single Order with a schema-safe insert
                $orders_columns = db_query("SHOW COLUMNS FROM orders") ?: [];
                $orders_field_map = [];
                foreach ($orders_columns as $col) {
                    if (!empty($col['Field'])) {
                        $orders_field_map[$col['Field']] = true;
                    }
                }

                $required_fields = ['customer_id', 'total_amount'];
                $missing_required = array_filter($required_fields, fn($f) => empty($orders_field_map[$f]));
                if (!empty($missing_required)) {
                    $order_error = 'Order table is missing required columns. Please contact support.';
                } else {
                    $insert_fields = [];
                    $insert_values = [];
                    $types = '';
                    $params = [];

                    $add_param = function ($field, $type, $value) use (&$insert_fields, &$insert_values, &$types, &$params) {
                        $insert_fields[] = $field;
                        $insert_values[] = '?';
                        $types .= $type;
                        $params[] = $value;
                    };

                    if (!empty($orders_field_map['customer_id'])) {
                        $add_param('customer_id', 'i', $customer_id);
                    }
                    if (!empty($orders_field_map['branch_id'])) {
                        $add_param('branch_id', 'i', $branch_id);
                    }
                    if (!empty($orders_field_map['reference_id'])) {
                        $add_param('reference_id', 'i', $reference_id);
                    }
                    if (!empty($orders_field_map['order_date'])) {
                        $insert_fields[] = 'order_date';
                        $insert_values[] = 'NOW()';
                    }
                    if (!empty($orders_field_map['total_amount'])) {
                        $add_param('total_amount', 'd', $order_total_amount);
                    }
                    if (!empty($orders_field_map['estimated_price'])) {
                        $add_param('estimated_price', 'd', $estimated_price);
                    }
                    if (!empty($orders_field_map['downpayment_amount'])) {
                        $add_param('downpayment_amount', 'd', $downpayment_amount);
                    }
                    if (!empty($orders_field_map['status'])) {
                        $add_param('status', 's', $order_status);
                    }
                    if (!empty($orders_field_map['payment_status'])) {
                        $add_param('payment_status', 's', $payment_status);
                    }
                    if (!empty($orders_field_map['payment_type'])) {
                        $add_param('payment_type', 's', $payment_type);
                    }
                    if (!empty($orders_field_map['notes'])) {
                        $add_param('notes', 's', $notes_summary);
                    }
                    if (!empty($orders_field_map['order_type'])) {
                        $add_param('order_type', 's', $order_type);
                    }
                    if (!empty($orders_field_map['order_source'])) {
                        $add_param('order_source', 's', 'customer');
                    }

                    $order_sql = "INSERT INTO orders (" . implode(', ', $insert_fields) . ") VALUES (" . implode(', ', $insert_values) . ")";
                    $order_id = $types !== '' ? db_execute($order_sql, $types, $params) : db_execute($order_sql);
                }

                if (!empty($order_id)) {
                    error_log('Order created successfully with ID: ' . $order_id);
                    error_log('Order type: ' . $order_type);
                    error_log('Branch ID: ' . $branch_id);
                    
                    // 3. Process each item and insert into order_items
                    foreach ($items_to_review as $key => $item) {
                        $custom = review_item_customization($item);
                        if (review_item_is_product($item)) {
                            $sp = trim((string)($item['source_page'] ?? ''));
                            if ($sp !== '' && empty($custom['source_page'])) {
                                $custom['source_page'] = $sp;
                            }
                        }
                        if (review_item_is_service($item)) {
                            if (empty($custom['service_type']) && !empty($item['name'])) {
                                $custom['service_type'] = $item['name'];
                            }
                            if (!empty($item['service_id'])) {
                                $custom['service_id'] = (int)$item['service_id'];
                            }
                        } elseif (review_item_is_product($item) && empty($custom['product_type']) && !empty($item['name'])) {
                            $custom['product_type'] = $item['name'];
                        }
                        $custom = printflow_merge_dynamic_form_data_into_customization($custom, $item);
                        $custom_data   = json_encode($custom);
                        $design_binary = null;
                        $design_mime   = $item['design_mime']   ?? null;
                        $design_name   = $item['design_name']   ?? null;
                        
                        $upload_dir = __DIR__ . '/../uploads/orders';
                        if (!is_dir($upload_dir)) mkdir($upload_dir, 0755, true);

                        $design_file_path = null;
                        $reference_file_path = null;

                        if (!empty($item['design_tmp_path']) && file_exists($item['design_tmp_path'])) {
                            $design_binary = file_get_contents($item['design_tmp_path']);
                            $ext = strtolower(pathinfo($design_name, PATHINFO_EXTENSION));
                            $new_name = uniqid('design_') . '_' . time() . '.' . $ext;
                            if (copy($item['design_tmp_path'], $upload_dir . '/' . $new_name)) {
                                $design_file_path = '/printflow/uploads/orders/' . $new_name;
                            }
                        }

                        if (!empty($item['reference_tmp_path']) && file_exists($item['reference_tmp_path'])) {
                            $ref_name = $item['reference_name'] ?? 'reference.jpg';
                            $ext = strtolower(pathinfo($ref_name, PATHINFO_EXTENSION));
                            $new_name = uniqid('ref_') . '_' . time() . '.' . $ext;
                            if (copy($item['reference_tmp_path'], $upload_dir . '/' . $new_name)) {
                                $reference_file_path = '/printflow/uploads/orders/' . $new_name;
                            }
                        }

                        $product_id = !empty($item['product_id']) ? (int)$item['product_id'] : null;
                        $service_type = $custom['service_type'] ?? ($item['category'] ?? ($item['name'] ?? ''));
                        
                        // Guard FK: ensure product_id exists
                        $product_exists = false;
                        if ($product_id !== null && $product_id > 0) {
                            $chk = db_query("SELECT product_id FROM products WHERE product_id = ? LIMIT 1", 'i', [$product_id]);
                            $product_exists = !empty($chk);
                        }
                        if (!$product_exists) {
                            $product_id = 3; // Fallback
                        }

                        // FIXED: Save estimated price to order_items so it displays correctly
                        // For service/custom orders, save estimated unit_price (staff can update later)
                        // For product orders, use the price as-is (it's already per-item unit price)
                        $unit_price = review_item_unit_price($item);
                        $quantity_val = review_item_quantity($item);

                        if ($design_binary) {
                            $stmt = $conn->prepare(
                                "INSERT INTO order_items (order_id, product_id, quantity, unit_price, customization_data, 
                                                        design_image, design_image_mime, design_image_name, design_file, reference_image_file)
                                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
                            );
                            if ($stmt) {
                                $null = NULL;
                                $stmt->bind_param('iiidssssss', $order_id, $product_id, $quantity_val, $unit_price, $custom_data, $null, $design_mime, $design_name, $design_file_path, $reference_file_path);
                                $stmt->send_long_data(5, $design_binary);
                                $stmt->execute();
                                $stmt->close();
                            }
                        } else {
                            db_execute(
                                "INSERT INTO order_items (order_id, product_id, quantity, unit_price, customization_data, design_file, reference_image_file) 
                                 VALUES (?, ?, ?, ?, ?, ?, ?)",
                                'iiidsss',
                                [$order_id, $product_id, $quantity_val, $unit_price, $custom_data, $design_file_path, $reference_file_path]
                            );
                        }

                        if (!empty($item['design_tmp_path']) && file_exists($item['design_tmp_path'])) @unlink($item['design_tmp_path']);
                        if (!empty($item['reference_tmp_path']) && file_exists($item['reference_tmp_path'])) @unlink($item['reference_tmp_path']);
                    }

                    $item_keys_to_clear = array_keys($items_to_review);
                    if (!isset($_SESSION['pending_payment_cart_restore']) || !is_array($_SESSION['pending_payment_cart_restore'])) {
                        $_SESSION['pending_payment_cart_restore'] = [];
                    }
                    $_SESSION['pending_payment_cart_restore'][(string)$order_id] = [
                        'items' => $items_to_review,
                        'created_at' => time(),
                    ];

                    // 4. Clear cart items and redirect
                    foreach ($item_keys_to_clear as $key) {
                        if (isset($_SESSION['cart'][$key])) {
                            unset($_SESSION['cart'][$key]);
                        }
                    }
                    
                    $_SESSION['last_order_item_key'] = implode(',', $item_keys_to_clear);
                    sync_cart_to_db($customer_id);
                    
                    // Notify shop users immediately only for custom/service orders.
                    // Product orders still need payment submission first.
                    $customer_name = trim(($customer['first_name'] ?? '') . ' ' . ($customer['last_name'] ?? ''));
                    if (empty($customer_name)) $customer_name = 'Customer';
                    if ($order_type === 'custom') {
                        // Auto-create job_orders rows for each service item so that the
                        // notification deep-link (customizations.php?order_id=X&job_type=ORDER)
                        // can resolve to a JOB and display the order in customizations.php.
                        // Without this, orders placed via order_review.php had no job_orders row
                        // and the deep-link incorrectly fell back to orders.php.
                        if (class_exists('JobOrderService')) {
                            foreach ($items_to_review as $rev_item) {
                                if (!review_item_is_service($rev_item)) {
                                    continue;
                                }
                                $rev_custom = review_item_customization($rev_item);
                                $rev_job_title = get_service_name_from_customization($rev_custom, $rev_item['name'] ?? 'Service Order');
                                $rev_cat_lower = strtolower(($rev_item['category'] ?? '') . ' ' . ($rev_item['name'] ?? ''));
                                $rev_service_type = 'Tarpaulin Printing';
                                if (strpos($rev_cat_lower, 'tarpaulin') !== false)           $rev_service_type = 'Tarpaulin Printing';
                                elseif (strpos($rev_cat_lower, 't-shirt') !== false || strpos($rev_cat_lower, 'shirt') !== false) $rev_service_type = 'T-shirt Printing';
                                elseif (strpos($rev_cat_lower, 'sticker') !== false || strpos($rev_cat_lower, 'decal') !== false)  $rev_service_type = 'Decals/Stickers (Print/Cut)';
                                elseif (strpos($rev_cat_lower, 'sintraboard') !== false)      $rev_service_type = 'Stickers on Sintraboard';
                                elseif (strpos($rev_cat_lower, 'glass') !== false || strpos($rev_cat_lower, 'frosted') !== false)   $rev_service_type = 'Glass Stickers / Wall / Frosted Stickers';
                                elseif (strpos($rev_cat_lower, 'transparent') !== false)      $rev_service_type = 'Transparent Stickers';
                                elseif (strpos($rev_cat_lower, 'reflectorized') !== false)    $rev_service_type = 'Reflectorized (Subdivision Stickers/Signages)';
                                elseif (strpos($rev_cat_lower, 'souvenir') !== false)         $rev_service_type = 'Souvenirs';
                                elseif (strpos($rev_cat_lower, 'layout') !== false)           $rev_service_type = 'Layouts';
                                $rev_dim = $rev_custom['dimensions'] ?? $rev_custom['Size'] ?? '';
                                $rev_w = 0; $rev_h = 0;
                                if ($rev_dim && (strpos($rev_dim, 'x') !== false || strpos($rev_dim, '×') !== false)) {
                                    $dp = preg_split('/[x×]/', strtolower($rev_dim));
                                    $rev_w = (float)(trim($dp[0] ?? 0));
                                    $rev_h = (float)(trim($dp[1] ?? 0));
                                }
                                try {
                                    JobOrderService::createOrder([
                                        'order_id'        => $order_id,
                                        'customer_id'     => $customer_id,
                                        'job_title'       => $rev_job_title,
                                        'service_type'    => $rev_service_type,
                                        'width_ft'        => $rev_w,
                                        'height_ft'       => $rev_h,
                                        'quantity'        => review_item_quantity($rev_item),
                                        'total_sqft'      => $rev_w * $rev_h * review_item_quantity($rev_item),
                                        'price_per_sqft'  => null,
                                        'price_per_piece' => null,
                                        'estimated_total' => review_item_estimated_total($rev_item),
                                        'notes'           => $notes_summary,
                                        'due_date'        => null,
                                        'priority'        => 'NORMAL',
                                        'artwork_path'    => null,
                                        'created_by'      => null,
                                    ]);
                                } catch (Exception $e) {
                                    error_log("order_review: Failed to create job order for Order #$order_id: " . $e->getMessage());
                                }
                            }
                        }
                        notify_staff_new_order($order_id, $customer_name, $customer_id);
                        printflow_send_order_update($order_id, 'inquiry');
                    }
                    
                    // Log activity (skip for customers as log_activity only works for staff users)
                    // log_activity($customer_id, 'Order Placed', "Customer placed order #$order_id");
                    
                    // For service orders (custom), redirect to orders page instead of payment
                    if ($order_type === 'custom') {
                        error_log('Service order placed, redirecting to orders page: ' . $order_id);
                        $_SESSION['order_success'] = "Order #$order_id placed successfully! Our team will review and price your order shortly.";
                        header("Location: orders.php");
                        exit();
                    } else {
                        error_log('Redirecting to payment page for order: ' . $order_id);
                        header("Location: payment.php?order_id=$order_id");
                        exit();
                    }
                } else {
                    $order_error = 'Failed to place order. Please try again.';
                }
            }
        }
    }
}

// Calculate total for all items
$grand_total = 0;
foreach ($items_to_review as $key => $item) {
    $grand_total += review_item_is_service($item)
        ? review_item_estimated_total($item)
        : (review_item_unit_price($item) * review_item_quantity($item));
}

// Determine if any item has customization
$is_product_order = true;
foreach ($items_to_review as $item) {
    if (review_item_is_service($item)) {
        $is_product_order = false;
        break;
    }
}

$page_title      = 'Review Your Order — PrintFlow';
$use_customer_css = true;
require_once __DIR__ . '/../includes/header.php';
?>

<style>
    .order-container { max-width: 650px; margin: 0 auto; }
    .compact-section { margin-bottom: 1.25rem; }
    .compact-card { padding: 1.25rem !important; }
    .review-title { text-align: center; margin-bottom: 2rem; color: #1f2937 !important; }
    .review-card {
        background: rgba(0,49,61,0.85) !important;
        border: 1px solid rgba(83,197,224,0.2) !important;
        border-radius: 12px !important;
        backdrop-filter: blur(8px);
    }
    .review-heading {
        color: #111827 !important;
        border-bottom-color: #e5e7eb !important;
    }
    .review-info-note {
        margin-top: 1rem;
        background: #f0f9ff !important;
        border: 1px solid #bae6fd !important;
        border-left: 4px solid #0ea5e9 !important;
        border-radius: 10px;
        padding: 14px 16px;
        display: flex;
        gap: 12px;
        align-items: flex-start;
    }
    .review-info-note-title { font-size: 0.82rem; font-weight: 700; color: #0c4a6e !important; margin-bottom: 3px; }
    .review-info-note-text { font-size: 0.75rem; color: #075985 !important; line-height: 1.5; }
    .review-contact-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; }
    .review-contact-full { grid-column: span 2; }
    .review-input-label {
        display: block;
        font-size: 0.65rem;
        font-weight: 600;
        color: #6b7280 !important;
        text-transform: uppercase;
        margin-bottom: 2px;
    }
    .review-input-disabled {
        background: #ffffff !important;
        border: 1px solid #d1d5db !important;
        color: #374151 !important;
        font-weight: 600;
        font-size: 0.85rem;
        font-family: inherit !important;
        padding: 8px 12px;
        opacity: 1 !important;
        -webkit-text-fill-color: #374151;
    }
    .review-input-disabled-textarea {
        min-height: 44px;
        resize: none;
        white-space: pre-wrap;
        overflow-wrap: anywhere;
        word-break: break-word;
        line-height: 1.45;
        font-size: 0.85rem !important;
        font-weight: 600 !important;
        font-family: inherit !important;
        color: #374151 !important;
    }
    .review-policy-card {
        margin-bottom: 1.5rem;
    }
    .review-policy-title { font-size: 0.82rem; font-weight: 700; color: #0c4a6e !important; margin-bottom: 3px; }
    .review-policy-text { font-size: 0.75rem; color: #075985 !important; line-height: 1.5; margin: 0; }
    .order-review-page-header {
        display: flex;
        align-items: center;
        justify-content: space-between;
        position: relative;
        margin-bottom: 2rem;
        gap: 1rem;
    }
    .order-review-back-link {
        text-decoration: none;
        display: inline-flex;
        align-items: center;
        gap: 4px;
        color: #374151;
        font-weight: 600;
        transition: color 0.2s;
        flex-shrink: 0;
    }
    .order-review-page-title {
        margin: 0;
        position: absolute;
        left: 50%;
        transform: translateX(-50%);
    }
    .review-total-banner {
        background: #f0f9ff;
        border: 1px solid #bae6fd;
        border-radius: 10px;
        padding: 1rem;
        margin-bottom: 1.5rem;
    }
    .review-total-banner-row {
        display: flex;
        justify-content: space-between;
        align-items: center;
        gap: 1rem;
    }
    .review-actions-bar {
        display: flex;
        justify-content: flex-end;
        gap: 1rem;
        padding-top: 1rem;
        border-top: 1px solid #e5e7eb;
    }
    .review-buy-btn {
        width: auto;
        font-weight: 700;
        font-size: .9rem;
        border-radius: 10px;
        border: none;
        background: linear-gradient(135deg, #53C5E0, #32a1c4) !important;
        color: #ffffff !important;
        text-transform: uppercase;
        letter-spacing: .02em;
        cursor: pointer;
        box-shadow: 0 10px 22px rgba(50,161,196,0.3);
        transition: all .2s;
    }
    .review-cancel-btn {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        width: auto;
        border: 1px solid rgba(83,197,224,.28);
        border-radius: 10px;
        font-size: .9rem;
        color: #d9e6ef;
        text-decoration: none;
        font-weight: 700;
        padding: 0 1.15rem;
        transition: all 0.2s;
        background: rgba(255,255,255,.06);
    }
    .review-cancel-btn:hover {
        background: rgba(83,197,224,.12);
        border-color: rgba(83,197,224,.52);
        color: #fff;
    }
    .review-actions-row {
        display: flex;
        justify-content: flex-end;
        align-items: center;
        gap: .75rem;
        margin-top: 1.1rem;
        flex-wrap: wrap;
    }
    .review-buy-btn,
    .review-cancel-btn {
        height: 46px;
        min-width: 150px;
        padding: 0 1.15rem;
        width: auto;
    }
    .review-image-clickable {
        cursor: zoom-in;
    }

    /* Match order_tshirt action buttons */
    .tshirt-btn {
        height: 46px;
        min-width: 150px;
        padding: 0 1.15rem;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        border-radius: 10px;
        text-decoration: none;
        font-size: .9rem;
        font-weight: 700;
        transition: all .2s;
    }
    .tshirt-btn-secondary {
        background: rgba(83,197,224,0.08) !important;
        border: 1px solid rgba(83,197,224,0.3) !important;
        color: #e0f2fe !important;
    }
    .tshirt-btn-secondary:hover {
        background: rgba(83,197,224,0.15) !important;
        border-color: rgba(83,197,224,0.5) !important;
        color: #ffffff !important;
    }
    .tshirt-btn-primary {
        border: none;
        background: #0a2530 !important;
        color: #fff !important;
        text-transform: uppercase;
        letter-spacing: .02em;
        cursor: pointer;
        box-shadow: 0 4px 12px rgba(10, 37, 48, 0.3);
    }
    .tshirt-btn:active {
        transform: translateY(1px) scale(0.99);
    }

    .image-preview-modal {
        position: fixed;
        inset: 0;
        background: rgba(2, 12, 18, 0.78);
        backdrop-filter: blur(4px);
        display: none;
        align-items: center;
        justify-content: center;
        z-index: 10000;
        padding: 1rem;
    }
    .image-preview-modal.active {
        display: flex;
    }
    .image-preview-modal img {
        max-width: min(96vw, 1100px);
        max-height: 92vh;
        border-radius: 12px;
        border: 1px solid rgba(83, 197, 224, 0.35);
        box-shadow: 0 20px 50px rgba(0, 0, 0, 0.55);
        background: #0a2530;
    }

    /* 
     * Target the image clickable state 
     */
    .review-order-item img {
        cursor: pointer;
        transition: transform 0.2s;
    }
    .review-order-item img:hover {
        transform: scale(1.02);
    }

    /* Show More/Less Button */
    .show-more-btn {
        width: 100%;
        padding: 0.75rem;
        background: rgba(83, 197, 224, 0.08);
        border: 1px dashed rgba(83, 197, 224, 0.3);
        border-radius: 10px;
        color: #0369a1;
        font-weight: 600;
        font-size: 0.9rem;
        cursor: pointer;
        transition: all 0.2s;
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 0.5rem;
        margin-bottom: 1.5rem;
    }
    .show-more-btn:hover {
        background: rgba(83, 197, 224, 0.15);
        border-color: rgba(83, 197, 224, 0.5);
    }
    .show-more-btn svg {
        transition: transform 0.3s;
    }
    .show-more-btn.expanded svg {
        transform: rotate(180deg);
    }
    .items-hidden {
        display: none;
    }

    /* Success Modal Styles */
    .success-modal-overlay {
        position: fixed; inset: 0; background: rgba(15, 23, 42, 0.6);
        backdrop-filter: none; z-index: 9999;
        display: flex; align-items: center; justify-content: center;
        opacity: 0; pointer-events: none; transition: opacity 0.4s ease;
    }
    .success-modal-overlay.active { opacity: 1; pointer-events: auto; }
    
    .success-modal-card {
        background: linear-gradient(160deg, #0f3340, #0a2530); width: 90%; max-width: 400px; padding: 40px 30px;
        border-radius: 24px; text-align: center;
        transform: scale(0.9); transition: transform 0.4s cubic-bezier(0.34, 1.56, 0.64, 1);
        box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.45);
        border: 1px solid rgba(83, 197, 224, 0.28);
    }
    .success-modal-overlay.active .success-modal-card { transform: scale(1); }

    .success-icon-wrap {
        width: 80px; height: 80px; background: rgba(83, 197, 224, 0.2); border-radius: 50%;
        display: flex; align-items: center; justify-content: center;
        margin: 0 auto 24px; color: #53c5e0;
    }
    .success-checkmark { font-size: 3rem; animation: checkmarkScale 0.5s ease 0.2s both; }
    @keyframes checkmarkScale { 
        0% { transform: scale(0); opacity: 0; }
        60% { transform: scale(1.2); }
        100% { transform: scale(1); opacity: 1; }
    }

    .success-title { font-size: 1.25rem; font-weight: 800; color: #e8f6fb; margin-bottom: 8px; }
    .success-msg { font-size: 0.95rem; color: #bfdce8; line-height: 1.5; margin-bottom: 24px; }
    
    .loading-bar-wrap { width: 100%; height: 6px; background: rgba(255, 255, 255, 0.14); border-radius: 10px; overflow: hidden; margin-bottom: 8px; }
    .loading-bar-fill { width: 0%; height: 100%; background: #53c5e0; transition: width 3s linear; }
    .redirect-msg { font-size: 0.75rem; color: #9ec3d3; font-weight: 600; text-transform: uppercase; letter-spacing: 0.05em; }
    @media (max-width: 640px) {
        .review-contact-grid { grid-template-columns: 1fr !important; gap: 0.75rem !important; }
        
        .review-actions-row {
            flex-direction: column;
            align-items: stretch;
            gap: 0.75rem;
        }
        .review-buy-btn, .review-cancel-btn, .tshirt-btn { width: 100% !important; }
        
        /* Main Item Container */
        .review-order-item {
            display: flex !important;
            flex-direction: column !important;
            max-width: 100% !important;
            padding: 0 !important;
            overflow: visible !important;
            margin-bottom: 2rem !important;
            position: relative !important;
        }

        /* Top Header Section (Image + Name + Qty) */
        .review-order-item .order-item-header {
            display: flex !important;
            flex-direction: column !important;
            width: 100% !important;
            padding: 1.5rem 1rem !important;
            gap: 1.25rem !important;
            border-bottom: 1px solid rgba(83, 197, 224, 0.12) !important;
            height: auto !important;
            min-height: auto !important;
            position: relative !important;
            box-sizing: border-box !important;
        }
        
        .review-order-item .order-item-image {
            width: min(100%, 260px) !important;
            height: auto !important;
            aspect-ratio: 1 / 1 !important;
            margin: 0 auto !important;
            display: block !important;
            border-radius: 16px !important;
            flex-shrink: 0 !important;
        }
        
        .review-order-item .order-item-content {
            width: 100% !important;
            display: flex !important;
            flex-direction: column !important;
            align-items: center !important;
            text-align: center !important;
            padding: 0 !important;
            flex: none !important;
        }
        
        .review-order-item .order-item-content h3 {
            font-size: 1.1rem !important;
            line-height: 1.4 !important;
            margin: 0 0 0.75rem 0 !important;
            text-align: center !important;
            white-space: normal !important;
            word-break: break-word !important;
            width: 100% !important;
        }

        .review-order-item .order-item-details {
            width: 100% !important;
            display: flex !important;
            flex-direction: column !important;
            gap: 0.75rem !important;
            margin-top: 1rem !important;
            position: relative !important;
        }

        .review-order-item .review-detail-row,
        .review-order-item .review-total-row {
            display: flex !important;
            justify-content: space-between !important;
            align-items: center !important;
            padding: 0.85rem 1rem !important;
            border: 1px solid rgba(83, 197, 224, 0.18) !important;
            border-radius: 12px !important;
            background: rgba(255, 255, 255, 0.04) !important;
            width: 100% !important;
            box-sizing: border-box !important;
            min-width: 0 !important;
        }

        /* Bottom Specifications Section */
        .review-order-item .order-item-specs {
            width: 100% !important;
            padding: 1.25rem 1rem !important;
            display: flex !important;
            flex-direction: column !important;
            gap: 1rem !important;
            height: auto !important;
            min-height: auto !important;
            position: relative !important;
        }

        .review-order-item .order-item-spec-grid {
            display: flex !important;
            flex-direction: column !important;
            gap: 0.75rem !important;
            width: 100% !important;
            grid-template-columns: none !important;
        }

        .review-order-item .order-item-spec-tile {
            display: flex !important;
            flex-direction: row !important;
            flex-wrap: wrap !important;
            justify-content: space-between !important;
            align-items: center !important;
            padding: 0.8rem 0.9rem !important;
            background: rgba(255, 255, 255, 0.03) !important;
            border: 1px solid rgba(83, 197, 224, 0.18) !important;
            border-radius: 12px !important;
            width: 100% !important;
            box-sizing: border-box !important;
            height: auto !important;
            min-height: auto !important;
        }

        .review-order-item .order-item-spec-tile > div:first-child {
            font-size: 0.76rem !important;
            color: #9fc4d4 !important;
            font-weight: 700 !important;
            margin-bottom: 0 !important;
        }

        .review-order-item .order-item-spec-tile > div:last-child {
            font-size: 0.95rem !important;
            font-weight: 700 !important;
            color: #eaf6fb !important;
            text-align: right !important;
            word-break: break-word !important;
        }

        .review-order-item .review-total-value {
            font-size: 1.1rem !important;
            font-weight: 800 !important;
            color: #53c5e0 !important;
        }

        .review-total-banner-row { flex-direction: column !important; align-items: flex-start !important; gap: 0.35rem !important; }
        .review-actions-bar { flex-direction: column-reverse !important; gap: 0.75rem !important; }
        
        /* Form Section */
        .review-input-disabled, .review-input-disabled-textarea { font-size: 0.85rem !important; padding: 0.65rem 0.75rem !important; }
        .compact-card { padding: 1rem !important; }
        .review-heading { font-size: 0.95rem !important; margin-bottom: 0.75rem !important; }
    }
    @media (max-width: 480px) {
        .review-order-item .order-item-image { width: 100% !important; }
        .order-review-page-title { font-size: 1.15rem !important; }
    }
</style>

<!-- Success Modal -->
<?php if (false): // Disabled - redirect directly instead ?>
    <div class="success-modal-card">
        <div class="success-icon-wrap">
            <span class="success-checkmark">✓</span>
        </div>
        <h2 class="success-title">Order Placed Successfully!</h2>
        <p class="success-msg">Your order <strong>#<?php echo $order_placed_id ?? ''; ?></strong> has been sent to our team for review. You'll receive a notification shortly.</p>
        
        <div class="loading-bar-wrap">
            <div id="loadingBar" class="loading-bar-fill"></div>
        </div>
        <p class="redirect-msg">Redirecting to payment...</p>
    </div>
</div>
<?php endif; ?>
<div id="imagePreviewModal" class="image-preview-modal" aria-hidden="true">
    <img id="imagePreviewModalImg" src="" alt="Design preview">
</div>

<script>
    function toggleItems(btn) {
        const hiddenItems = document.querySelectorAll('.items-hidden');
        const isExpanded = btn.classList.contains('expanded');
        const textSpan = btn.querySelector('.show-more-text');
        if (isExpanded) {
            hiddenItems.forEach(item => item.style.display = 'none');
            btn.classList.remove('expanded');
            textSpan.textContent = 'Show ' + hiddenItems.length + ' More Item' + (hiddenItems.length > 1 ? 's' : '');
        } else {
            hiddenItems.forEach(item => item.style.display = 'block');
            btn.classList.add('expanded');
            textSpan.textContent = 'Show Less';
        }
    }
</script>

<div class="min-h-screen py-8">
    <?php if (!isset($order_placed_id)): ?>
    <div class="container mx-auto px-4 order-container">
        <div class="order-review-page-header">
            <a href="cart.php" class="order-review-back-link" onmouseover="this.style.color='#111827'" onmouseout="this.style.color='#374151'">
                <svg width="20" height="20" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"/></svg>
                Back
            </a>
            <h1 class="text-2xl font-bold text-gray-800 order-review-page-title">Review Your Order</h1>
        </div>

        <form method="POST" action="order_review.php?item=<?php echo urlencode($item_key); ?>" novalidate data-pf-skip-guard>
            <input type="hidden" name="item" value="<?php echo htmlspecialchars($item_key); ?>">
            <?php echo csrf_field(); ?>
            
            <?php if ($order_error): ?>
                <div class="alert-error" style="margin-bottom: 1.25rem;"><?php echo htmlspecialchars($order_error); ?></div>
            <?php endif; ?>

            <!-- Single Consolidated Card -->
            <div class="card compact-card review-card">
                <!-- 1. Order Summary -->
                <h2 class="review-heading" style="font-size:1rem; font-weight:700; margin-bottom:1rem; display:flex; align-items:center; gap:8px;">
                    Order Summary (<?php echo count($items_to_review); ?> item<?php echo count($items_to_review) > 1 ? 's' : ''; ?>)
                </h2>
                <?php 
                $item_index = 0;
                foreach ($items_to_review as $key => $item): 
                    $item_index++;
                    $is_hidden = ($item_index > 3);
                ?>
                <div class="review-order-item <?php echo $is_hidden ? 'items-hidden' : ''; ?>" style="margin-bottom: 1.5rem; padding-bottom: 1.5rem; <?php echo $key !== array_key_last($items_to_review) ? 'border-bottom: 1px solid #e5e7eb;' : ''; ?>">
                    <?php
                    try {
                        render_order_item_clean($item, true, true, true);
                    } catch (Throwable $e) {
                        error_log('Order review item render failed for ' . $key . ': ' . $e->getMessage());
                        review_render_item_summary($item);
                    }
                    ?>
                </div>
                <?php endforeach; ?>
                
                <?php if (count($items_to_review) > 3): ?>
                <button type="button" class="show-more-btn" onclick="toggleItems(this)">
                    <span class="show-more-text">Show <?php echo count($items_to_review) - 3; ?> More Item<?php echo (count($items_to_review) - 3) > 1 ? 's' : ''; ?></span>
                    <svg width="20" height="20" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/>
                    </svg>
                </button>
                <?php endif; ?>
                
                <!-- Grand Total -->
                <?php if (count($items_to_review) > 1): ?>
                <div class="review-total-banner">
                    <div class="review-total-banner-row">
                        <span style="font-size: 1rem; font-weight: 700; color: #0c4a6e;">Total Amount:</span>
                        <span style="font-size: 1.25rem; font-weight: 800; color: #0369a1;">₱ <?php echo number_format($grand_total, 2); ?></span>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Pricing Notice -->
                <div class="review-info-note" style="margin-bottom: 2rem;">
                    <span style="font-size:1.25rem; flex-shrink:0;">ℹ️</span>
                    <div>
                        <div class="review-info-note-title">Order Review Process</div>
                        <div class="review-info-note-text">Your order will be reviewed by our team. You'll receive a notification when it's ready for payment or pickup.</div>
                    </div>
                </div>

                <?php if ($needs_branch_selection): ?>
                <!-- 2. Branch Selection -->
                <div style="margin-top: 1.5rem; padding-top: 1.25rem; border-top: 1px solid #e5e7eb; margin-bottom: 1.5rem;">
                    <label class="review-input-label" style="margin-bottom: 0.5rem; display: block;">Pickup Branch *</label>
                    <select name="branch_id" id="branch_id" class="input-field" required style="background: #ffffff; border: 1px solid #d1d5db; color: #374151; font-weight: 500; font-size: 0.9rem; padding: 0.75rem; border-radius: 8px; cursor: pointer; transition: all 0.2s; width: 100%; display: block;">
                        <option value="">-- Select Branch --</option>
                        <?php foreach ($branches as $b): ?>
                            <option value="<?php echo $b['id']; ?>"><?php echo htmlspecialchars($b['branch_name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <div id="branch-error" style="display: <?php echo ($order_error === 'Please select a branch for pickup.') ? 'flex' : 'none'; ?>; align-items: center; gap: 6px; color: #ef4444; font-size: 0.875rem; margin-top: 0.5rem; font-weight: 600;">
                        <svg width="16" height="16" fill="currentColor" viewBox="0 0 20 20" style="flex-shrink: 0;"><path fill-rule="evenodd" d="M8.257 3.099c.765-1.36 2.722-1.36 3.486 0l5.58 9.92c.75 1.334-.213 2.98-1.742 2.98H4.42c-1.53 0-2.493-1.646-1.743-2.98l5.58-9.92zM11 13a1 1 0 11-2 0 1 1 0 012 0zm-1-8a1 1 0 00-1 1v3a1 1 0 002 0V6a1 1 0 00-1-1z" clip-rule="evenodd"></path></svg>
                        Please select a branch for pickup.
                    </div>
                </div>
                <?php endif; ?>

                <!-- 3. Contact Information -->
                <h2 class="review-heading" style="font-size:1rem; font-weight:700; margin-bottom:1rem; padding-bottom:0.5rem; padding-top:1rem; border-top: 1px solid #e5e7eb; display:flex; align-items:center; gap:8px;">
                    Contact Information
                </h2>
                <div class="review-contact-grid" style="margin-bottom: 1.5rem;">
                    <div>
                        <label class="review-input-label">First Name</label>
                        <input type="text" class="input-field review-input-disabled" value="<?php echo htmlspecialchars($customer['first_name'] ?? ''); ?>" disabled>
                    </div>
                    <div>
                        <label class="review-input-label">Last Name</label>
                        <input type="text" class="input-field review-input-disabled" value="<?php echo htmlspecialchars($customer['last_name'] ?? ''); ?>" disabled>
                    </div>
                    <div>
                        <label class="review-input-label">Email Address</label>
                        <input type="text" class="input-field review-input-disabled" value="<?php echo htmlspecialchars($customer['email']); ?>" disabled>
                    </div>
                    <div>
                        <label class="review-input-label">Phone Number</label>
                        <input type="text" class="input-field review-input-disabled" value="<?php echo htmlspecialchars($customer['contact_number'] ?? '—'); ?>" disabled>
                    </div>
                    <div class="review-contact-full">
                        <label class="review-input-label">Address</label>
                        <textarea class="input-field review-input-disabled review-input-disabled-textarea" rows="2" disabled><?php echo htmlspecialchars($customer_address); ?></textarea>
                    </div>
                </div>

                <?php if (!$is_product_order): ?>
                <!-- 3. Payment Policy Notice -->
                <div class="review-info-note review-policy-card">
                    <svg style="width:20px;height:20px;color:#0ea5e9;flex-shrink:0;margin-top:1px;" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1"/>
                    </svg>
                    <div>
                        <div class="review-policy-title">Payment Policy</div>
                        <div class="review-policy-text">The payment option (100% Full Payment) will become available once staff reviews your order and sets the price. You will receive a notification when your order is ready for payment.</div>
                    </div>
                </div>
                <?php endif; ?>

                <!-- 4. Final Actions -->
                <div class="review-actions-bar">
                    <a href="cart.php" 
                       class="shopee-btn-outline" style="width: 150px; text-align: center; padding: 0.75rem; text-decoration: none; white-space: nowrap;">
                        Back to Cart
                    </a>
                    
                    <button type="submit" name="confirm_order" value="1" class="shopee-btn-primary" style="width: 150px; white-space: nowrap;"><?php echo $is_product_order ? 'Pay Now' : 'Inquire Now'; ?></button>
                </div>
            </div>
        </form>
    </div>
    <?php endif; ?>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const form = document.querySelector('form');
    const branchSelect = document.getElementById('branch_id');
    const branchError = document.getElementById('branch-error');

    if (form && branchSelect) {
        form.addEventListener('submit', function(e) {
            if (!branchSelect.value || branchSelect.value === '') {
                e.preventDefault();
                branchError.style.display = 'flex';
                branchSelect.style.borderColor = '#ef4444';
                branchSelect.style.boxShadow = '0 0 0 4px rgba(239, 68, 68, 0.1)';
                branchSelect.scrollIntoView({ behavior: 'smooth', block: 'center' });
            }
        });

        branchSelect.addEventListener('change', function() {
            if (this.value) {
                branchError.style.display = 'none';
                this.style.borderColor = '#d1d5db';
                this.style.boxShadow = 'none';
            }
        });
    }
});
</script>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
