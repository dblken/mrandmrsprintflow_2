<?php
/**
 * Checkout Page
 * PrintFlow - Printing Shop PWA
 */

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/JobOrderService.php';
require_once __DIR__ . '/../includes/product_option_stock.php';

require_role('Customer');
require_once __DIR__ . '/../includes/require_customer_profile_complete.php';
require_once __DIR__ . '/../includes/require_id_verified.php';

function checkout_item_is_service(array $item): bool {
    $custom = $item['customization'] ?? [];
    if (is_string($custom)) {
        $custom = function_exists('printflow_decode_modal_customization_payload')
            ? printflow_decode_modal_customization_payload($custom)
            : (json_decode($custom, true) ?: []);
    }

    $source_page = strtolower(trim((string)($item['source_page'] ?? '')));
    $item_type = strtolower(trim((string)($item['type'] ?? '')));
    $cart_key = strtolower(trim((string)($item['_cart_key'] ?? '')));
    $product_id = (int)($item['product_id'] ?? 0);

    if ($source_page === 'products' || $source_page === 'dynamic_form' || $item_type === 'product' || strpos($cart_key, 'product_') === 0) {
        return false;
    }

    if ($source_page === 'services' || $item_type === 'service') {
        return true;
    }

    if (!empty($custom['service_type'])) {
        return true;
    }

    return $product_id <= 0;
}

function checkout_item_customization(array $item): array {
    $custom = $item['customization'] ?? [];
    if (is_string($custom)) {
        if (function_exists('printflow_decode_modal_customization_payload')) {
            return printflow_decode_modal_customization_payload($custom);
        }
        $decoded = json_decode($custom, true);
        return is_array($decoded) ? $decoded : [];
    }
    if (!is_array($custom)) {
        return [];
    }
    return function_exists('printflow_normalize_customization_for_modal')
        ? printflow_normalize_customization_for_modal($custom)
        : $custom;
}

$cart_items = $_SESSION['cart'] ?? [];

if (empty($cart_items)) {
    redirect('cart.php');
}

$total = 0;
foreach ($cart_items as $item) {
    $total += $item['price'] * $item['quantity'];
}

$customer_id = get_user_id();
$customer = db_query("SELECT * FROM customers WHERE customer_id = ?", 'i', [$customer_id])[0];

$customer_type = $customer['customer_type'] ?? 'new';

// Handle Order Submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['place_order'])) {
    global $conn; // needed for send_long_data BLOB insertion
    
    if (false) {
        $error = "🚫 Your account is restricted from placing new orders.";
    } elseif (verify_csrf_token($_POST['csrf_token'] ?? '')) {
        // Pricing and payment are determined AFTER staff review.
        // The checkout page does not collect payment choice from the customer initially.
        // Staff will set the price and move to 'To Pay' status when ready.
        $downpayment_amount = 0;
        $payment_type = 'tbd'; 
        $payment_status = 'Unpaid';

        // Start Transaction (if supported, otherwise manual checks)
        // 1. Create Order
        // Extract branch_id from the first item in the cart or from the POST selector
        $branch_id = (int)($_POST['order_branch_id'] ?? 1);
        
        if (!empty($cart_items)) {
            foreach ($cart_items as $item) {
                if (!empty($item['branch_id'])) {
                    $branch_id = (int)$item['branch_id'];
                    break;
                }
                if (isset($item['customization']['Branch_ID'])) {
                    $branch_id = (int)$item['customization']['Branch_ID'];
                    break;
                }

            }
        }

        foreach ($cart_items as $cartItem) {
            if (checkout_item_is_service($cartItem)) {
                continue;
            }
            $productId = (int)($cartItem['product_id'] ?? 0);
            if ($productId <= 0) {
                continue;
            }
            $stockCheck = printflow_product_option_stock_validate(
                $productId,
                $branch_id,
                (array)($cartItem['customization'] ?? []),
                (int)($cartItem['quantity'] ?? 1)
            );
            if (!empty($stockCheck['uses_option_stock']) && empty($stockCheck['ok'])) {
                $error = (string)($stockCheck['message'] ?? 'Selected variant is out of stock.');
                break;
            }
        }

        if (!empty($error)) {
            // validation failed, render page with error
        } else {

        $notes = $_POST['notes'] ?? null;

        // Server-side idempotency guard to prevent duplicate orders
        // when the customer double-clicks submit or retries quickly.
        $guard_items = [];
        foreach ($cart_items as $item) {
            $custom = $item['customization'] ?? [];
            if (is_array($custom)) {
                ksort($custom);
            }
            $guard_items[] = [
                'product_id' => (int)($item['product_id'] ?? 0),
                'quantity' => (int)($item['quantity'] ?? 0),
                'price' => (float)($item['price'] ?? 0),
                'source_page' => (string)($item['source_page'] ?? ''),
                'customization' => $custom,
            ];
        }
        usort($guard_items, static function (array $a, array $b): int {
            return [$a['product_id'], $a['quantity'], $a['price']] <=> [$b['product_id'], $b['quantity'], $b['price']];
        });
        $guard_payload = [
            'customer_id' => (int)$customer_id,
            'branch_id' => (int)$branch_id,
            'notes' => trim((string)$notes),
            'items' => $guard_items,
        ];
        $guard_fingerprint = hash('sha256', json_encode($guard_payload));
        $guard_now = time();
        $guard_window_secs = 30;
        $last_guard = $_SESSION['checkout_submit_guard'] ?? null;
        if (
            is_array($last_guard)
            && ($last_guard['fingerprint'] ?? '') === $guard_fingerprint
            && ($guard_now - (int)($last_guard['ts'] ?? 0)) <= $guard_window_secs
        ) {
            if (!empty($last_guard['order_id'])) {
                $_SESSION['success'] = "Order #{$last_guard['order_id']} was already placed successfully.";
                redirect("order_details.php?id=" . (int)$last_guard['order_id']);
            }
            $error = "Order submission is already in progress. Please wait a moment.";
            $skip_order_insert = true;
        } else {
            $_SESSION['checkout_submit_guard'] = [
                'fingerprint' => $guard_fingerprint,
                'ts' => $guard_now,
                'order_id' => null,
            ];
            $skip_order_insert = false;
        }
        
        // Determine order type based on cart items; resolve reference_id for pure-service carts (legacy pages omit product_id).
        $order_type = 'product';
        foreach ($cart_items as $item) {
            if (checkout_item_is_service($item)) {
                $order_type = 'custom';
                break;
            }
        }

        $reference_id = null;
        foreach ($cart_items as $item) {
            if (!empty($item['product_id'])) {
                $reference_id = (int)$item['product_id'];
                break;
            }
        }
        if (($reference_id === null || $reference_id <= 0) && $order_type === 'custom') {
            foreach ($cart_items as $item) {
                if (!checkout_item_is_service($item)) {
                    continue;
                }
                $rid = printflow_resolve_service_catalog_service_id_from_cart_line($item);
                if ($rid > 0) {
                    $reference_id = $rid;
                    break;
                }
            }
        }
        
        $order_sql = "INSERT INTO orders (customer_id, branch_id, reference_id, order_date, total_amount, downpayment_amount, status, payment_status, payment_type, notes, order_type) 
                      VALUES (?, ?, ?, NOW(), ?, ?, ?, ?, ?, ?, ?)";
        
        $status = 'Pending';
        $order_id = $skip_order_insert
            ? 0
            : db_execute($order_sql, 'iiiddssssss', [$customer_id, $branch_id, $reference_id, $total, $downpayment_amount, $status, $payment_status, $payment_type, $notes, $order_type]);
        
        if ($order_id) {
            $_SESSION['checkout_submit_guard']['order_id'] = (int)$order_id;
            // 2. Insert Order Items (design stored as LONGBLOB, never on disk)
            $inserted_order_item_ids = [];
            foreach ($cart_items as $pid => $item) {
                // Determine service_type for better display in history/notifications
                $custom = checkout_item_customization($item);
                if (!checkout_item_is_service($item)) {
                    $sp = trim((string)($item['source_page'] ?? ''));
                    if ($sp !== '' && empty($custom['source_page'])) {
                        $custom['source_page'] = $sp;
                    }
                }
                if (checkout_item_is_service($item)) {
                    if (empty($custom['service_type']) && !empty($item['name'])) {
                        $custom['service_type'] = $item['name'];
                    }
                    if (empty($custom['source_page'])) {
                        $custom['source_page'] = trim((string)($item['source_page'] ?? 'services')) ?: 'services';
                    }
                } elseif (!checkout_item_is_service($item) && empty($custom['product_type']) && !empty($item['name'])) {
                    $custom['product_type'] = $item['name'];
                }

                $custom = printflow_merge_dynamic_form_data_into_customization($custom, $item);

                if (checkout_item_is_service($item)) {
                    $tmpLine = $item;
                    $tmpLine['customization'] = $custom;
                    $resolvedSid = printflow_resolve_service_catalog_service_id_from_cart_line($tmpLine);
                    if ($resolvedSid > 0 && (int)($custom['service_id'] ?? 0) <= 0) {
                        $custom['service_id'] = $resolvedSid;
                    }
                    $custom = printflow_apply_service_field_config_display_labels(
                        $custom,
                        (int)($custom['service_id'] ?? 0),
                        [
                            'quantity' => (int)($item['quantity'] ?? 1),
                            'design_name' => trim((string)($item['design_name'] ?? '')),
                            'reference_name' => trim((string)($item['reference_name'] ?? '')),
                            'dimension_unit' => trim((string)($custom['unit'] ?? '')),
                        ]
                    );
                }
                $custom = printflow_normalize_customization_for_modal($custom);
                if (checkout_item_is_service($item) && (int)($custom['service_id'] ?? 0) <= 0) {
                    $resolvedSid = printflow_resolve_service_catalog_service_id_from_cart_line($item);
                    if ($resolvedSid > 0) {
                        $custom['service_id'] = $resolvedSid;
                    }
                }
                if (trim((string)($custom['source_page'] ?? '')) === '') {
                    $custom['source_page'] = trim((string)($item['source_page'] ?? 'services')) ?: 'services';
                }

                $custom_data    = printflow_encode_customization_payload($custom);
                $cart_items[$pid]['customization'] = $custom;
                $design_binary  = null;
                $design_mime    = $item['design_mime']   ?? null;
                $design_name    = $item['design_name']   ?? null;
                $design_file_path = null;
                $reference_file_path = null;

                $upload_dir = __DIR__ . '/../uploads/orders';
                if (!is_dir($upload_dir)) {
                    mkdir($upload_dir, 0755, true);
                }

                // Read binary from temp file (session only stores path, not raw bytes)
                if (!empty($item['design_tmp_path']) && file_exists($item['design_tmp_path'])) {
                    $design_binary = file_get_contents($item['design_tmp_path']);
                    $ext = strtolower(pathinfo((string)$design_name, PATHINFO_EXTENSION));
                    if ($ext === '') {
                        $ext = 'bin';
                    }
                    $new_name = uniqid('design_') . '_' . time() . '.' . $ext;
                    if (copy($item['design_tmp_path'], $upload_dir . '/' . $new_name)) {
                        $design_file_path = '/printflow/uploads/orders/' . $new_name;
                    }
                }

                if (!empty($item['reference_tmp_path']) && file_exists($item['reference_tmp_path'])) {
                    $reference_name = $item['reference_name'] ?? 'reference';
                    $ref_ext = strtolower(pathinfo((string)$reference_name, PATHINFO_EXTENSION));
                    if ($ref_ext === '') {
                        $ref_ext = 'bin';
                    }
                    $new_reference_name = uniqid('ref_') . '_' . time() . '.' . $ref_ext;
                    if (copy($item['reference_tmp_path'], $upload_dir . '/' . $new_reference_name)) {
                        $reference_file_path = '/printflow/uploads/orders/' . $new_reference_name;
                    }
                }

                // Ensure unit_price is per item, not total
                $unit_price = (float)$item['price'];
                $quantity_val = (int)$item['quantity'];
                
                // Safety check: if unit_price is suspiciously high (> 500) and quantity > 1,
                // divide by quantity to get the correct unit price
                if ($quantity_val > 1 && $unit_price > 500) {
                    // Likely a total price, convert to unit price
                    $unit_price = round($unit_price / $quantity_val, 2);
                }
                
                $order_item_id = 0;
                if ($design_binary) {
                    // INSERT with BLOB using send_long_data
                    $item_stmt = $conn->prepare(
                        "INSERT INTO order_items (order_id, product_id, quantity, unit_price, customization_data, design_image, design_image_mime, design_image_name, design_file, reference_image_file)
                         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
                    );
                    if ($item_stmt) {
                        $null = NULL;
                        $item_stmt->bind_param('iiidsbssss',
                            $order_id,
                            $item['product_id'],
                            $item['quantity'],
                            $unit_price,
                            $custom_data,
                            $null,          // placeholder for BLOB
                            $design_mime,
                            $design_name,
                            $design_file_path,
                            $reference_file_path
                        );
                        $item_stmt->send_long_data(5, $design_binary);
                        $item_stmt->execute();
                        $order_item_id = (int)$conn->insert_id;
                        $inserted_order_item_ids[$pid] = $order_item_id;
                        $item_stmt->close();
                    }
                } else {
                    // No design uploaded — insert without BLOB
                    $order_item_id = (int)db_execute(
                        "INSERT INTO order_items (order_id, product_id, quantity, unit_price, customization_data, design_file, reference_image_file)
                         VALUES (?, ?, ?, ?, ?, ?, ?)",
                        'iiidsss',
                        [$order_id, $item['product_id'], $item['quantity'], $unit_price, $custom_data, $design_file_path, $reference_file_path]
                    );
                    $inserted_order_item_ids[$pid] = $order_item_id;
                }

                if (checkout_item_is_service($item) && $order_item_id > 0) {
                    db_execute(
                        "INSERT INTO customizations (order_id, order_item_id, customer_id, service_type, customization_details, status, created_at, updated_at)
                         VALUES (?, ?, ?, ?, ?, 'Pending Review', NOW(), NOW())",
                        'iiiss',
                        [
                            $order_id,
                            $order_item_id,
                            $customer_id,
                            (string)($custom['service_type'] ?? ($item['name'] ?? 'Service')),
                            $custom_data
                        ]
                    );
                }
            }
            
            // 3. Clean up temp design files and clear Cart
            foreach ($cart_items as $ci) {
                if (!empty($ci['design_tmp_path']) && file_exists($ci['design_tmp_path'])) {
                    @unlink($ci['design_tmp_path']);
                }
            }
            
            // 4. Auto-create Job Orders for Production Workflow
            foreach ($cart_items as $pid => $item) {
                if (!checkout_item_is_service($item)) {
                    continue;
                }

                // Determine service type accurately for ENUM matching
                $service_type = 'Tarpaulin Printing'; // Default
                $cat_lower = strtolower(($item['category'] ?? '') . ' ' . ($item['name'] ?? ''));

                if (strpos($cat_lower, 'tarpaulin') !== false) {
                    $service_type = 'Tarpaulin Printing';
                } elseif (strpos($cat_lower, 't-shirt') !== false || strpos($cat_lower, 'shirt') !== false) {
                    $service_type = 'T-shirt Printing';
                } elseif (strpos($cat_lower, 'reflectorized') !== false) {
                    $service_type = 'Reflectorized (Subdivision Stickers/Signages)';
                } elseif (strpos($cat_lower, 'transparent') !== false) {
                    $service_type = 'Transparent Stickers';
                } elseif (strpos($cat_lower, 'glass') !== false || strpos($cat_lower, 'wall') !== false || strpos($cat_lower, 'frosted') !== false) {
                    $service_type = 'Glass Stickers / Wall / Frosted Stickers';
                } elseif (strpos($cat_lower, 'sintraboard') !== false && (strpos($cat_lower, 'standee') !== false || strpos($cat_lower, 'stand') !== false)) {
                    $service_type = 'Sintraboard Standees';
                } elseif (strpos($cat_lower, 'sintraboard') !== false) {
                    $service_type = 'Stickers on Sintraboard';
                } elseif (strpos($cat_lower, 'sticker') !== false || strpos($cat_lower, 'decal') !== false) {
                    $service_type = 'Decals/Stickers (Print/Cut)';
                } elseif (strpos($cat_lower, 'souvenir') !== false) {
                    $service_type = 'Souvenirs';
                } elseif (strpos($cat_lower, 'layout') !== false) {
                    $service_type = 'Layouts';
                }
                
                // Parse dimensions from customization data
                $custom = $item['customization'] ?? [];
                $dimensions = $custom['dimensions'] ?? $custom['Size'] ?? '';
                $width_ft = 0; $height_ft = 0;
                if ($dimensions && (strpos($dimensions, 'x') !== false || strpos($dimensions, '×') !== false)) {
                    $d_parts = preg_split('/[x×]/', strtolower($dimensions));
                    $width_ft  = (float)(trim($d_parts[0] ?? 0));
                    $height_ft = (float)(trim($d_parts[1] ?? 0));
                }
                
                $job_title = get_service_name_from_customization($custom, $item['name'] ?? $service_type);
                $job_qty   = (int)($item['quantity'] ?? 1);
                $oi_id     = $inserted_order_item_ids[$pid] ?? null;

                // Use JobOrderService for robust creation
                try {
                    JobOrderService::createOrder([
                        'order_id'        => $order_id,
                        'customer_id'     => $customer_id,
                        'job_title'       => $job_title,
                        'service_type'    => $service_type,
                        'width_ft'        => $width_ft,
                        'height_ft'       => $height_ft,
                        'quantity'        => $job_qty,
                        'total_sqft'      => $width_ft * $height_ft * $job_qty,
                        'price_per_sqft'  => null,
                        'price_per_piece' => null,
                        'estimated_total' => $item['price'] * $job_qty,
                        'notes'           => $notes,
                        'due_date'        => null,
                        'priority'        => 'NORMAL',
                        'artwork_path'    => null,
                        'created_by'      => null,
                    ]);
                } catch (Exception $e) {
                    error_log("Failed to create job order for item in Order #$order_id: " . $e->getMessage());
                }
            }

            if (!isset($_SESSION['pending_payment_cart_restore']) || !is_array($_SESSION['pending_payment_cart_restore'])) {
                $_SESSION['pending_payment_cart_restore'] = [];
            }
            $_SESSION['pending_payment_cart_restore'][(string)$order_id] = [
                'items' => $cart_items,
                'created_at' => time(),
            ];
            
            unset($_SESSION['cart']);
            sync_cart_to_db($customer_id);
            
            // 5. Notification
            $first_item_custom = !empty($inserted_order_item_ids) ? db_query("SELECT customization_data FROM order_items WHERE order_id = ? LIMIT 1", 'i', [$order_id]) : [];
            $product_name = 'Product Order';
            if (!empty($first_item_custom)) {
                $custom_data = json_decode($first_item_custom[0]['customization_data'] ?? '[]', true);
                $product_name = get_service_name_from_customization($custom_data, 'Product Order');
            }
            create_notification($customer_id, 'Customer', "Order for {$product_name} placed successfully!", 'Order', true, false, $order_id);
            notify_staff_new_order((int)$order_id, (string)($customer['first_name'] ?? 'Customer'), (int)$customer_id);
            
            $_SESSION['success'] = "Your order for {$product_name} has been placed successfully! Our team will review it shortly. You can track the status here.";
            
            // Redirect to the new order's details page
            redirect("order_details.php?id=$order_id");
        } else {
            if (!$skip_order_insert) {
                unset($_SESSION['checkout_submit_guard']);
            }
            $error = "Failed to place order. Please try again.";
        }
        }
    } else {
        $error = "Invalid request.";
    }
}

$page_title = 'Checkout - PrintFlow';
$use_customer_css = true;
require_once __DIR__ . '/../includes/header.php';
?>

<style>
    .order-container { max-width: 650px; margin: 0 auto; }
    .compact-card { padding: 1.25rem !important; }
</style>

<div class="min-h-screen py-8">
    <div class="container mx-auto px-4 order-container">
        <h1 class="ct-page-title" style="text-align: center; margin-bottom: 2rem;">Checkout</h1>

        <form method="POST">
            <?php echo csrf_field(); ?>
            
            <div style="display:flex; flex-direction:column; gap:1.25rem;">
                <?php if (isset($error)): ?>
                    <div class="alert-error"><?php echo $error; ?></div>
                <?php endif; ?>

                <!-- 1. Order Summary -->
                <div class="card compact-card">
                    <h2 style="font-size:1rem; font-weight:700; margin-bottom:1rem; color:#111827; border-bottom:1px solid #f3f4f6; padding-bottom:0.5rem; display:flex; align-items:center; gap:8px;">
                        <span>🛒</span> Order Summary
                    </h2>
                    <div style="margin-bottom:1rem; display:flex; flex-direction:column; gap:0.75rem;">
                        <?php foreach ($cart_items as $item):
                            $item_total     = $item['price'] * $item['quantity'];
                            $custom         = $item['customization'] ?? [];
                            $design_preview = null;
                            if (!empty($item['design_tmp_path']) && file_exists($item['design_tmp_path']) && !empty($item['design_mime'])) {
                                $bin = @file_get_contents($item['design_tmp_path']);
                                if ($bin) $design_preview = 'data:' . $item['design_mime'] . ';base64,' . base64_encode($bin);
                            }
                        ?>
                            <div style="border:1px solid #f1f5f9; border-radius:10px; padding:0.75rem; display:flex; gap:0.75rem; align-items:flex-start; background:#fff;">
                                <div style="flex-shrink:0; width:50px; height:50px; border-radius:8px; overflow:hidden; background:#f9fafb; border:1px solid #e5e7eb; display:flex; align-items:center; justify-content:center;">
                                    <?php if ($design_preview): ?>
                                        <img src="<?php echo $design_preview; ?>" alt="" style="width:100%; height:100%; object-fit:cover;">
                                    <?php else: ?>
                                        <span style="font-size:1.5rem;">📦</span>
                                    <?php endif; ?>
                                </div>
                                <div style="flex:1; min-width:0;">
                                    <div style="font-weight:700; font-size:0.85rem; color:#1f2937; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;"><?php echo htmlspecialchars($item['name']); ?></div>
                                    <div style="font-size:0.7rem; color:#6b7280; margin-top:2px;">Qty: <?php echo (int)$item['quantity']; ?></div>
                                </div>
                                <!-- Price column hidden -->
                            </div>
                        <?php endforeach; ?>
                    </div>
                    
                    <div style="border-top:1px solid #f3f4f6; padding-top:1rem; display:flex; flex-direction:column; gap:0.5rem;">
                        <!-- Pricing Notice (replaces subtotal/total) -->
                        <div style="margin-top:0.5rem; background:linear-gradient(135deg,#f0f9ff,#e0f2fe); border:1px solid #bae6fd; border-left:4px solid #0ea5e9; border-radius:10px; padding:14px 16px; display:flex; gap:12px; align-items:flex-start;">
                            <span style="font-size:1.25rem; flex-shrink:0;">ℹ️</span>
                            <div>
                                <div style="font-size:0.82rem; font-weight:700; color:#0c4a6e; margin-bottom:3px;">Price will be confirmed by the shop</div>
                                <div style="font-size:0.75rem; color:#0369a1; line-height:1.5;">Your order will be reviewed and priced by our team. Payment options will be available once your order reaches the <strong>To Pay</strong> stage.</div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- 2. Contact Information -->
                <div class="card compact-card">
                    <h2 style="font-size:1rem; font-weight:700; margin-bottom:1rem; border-bottom:1px solid #f3f4f6; padding-bottom:0.5rem; display:flex; align-items:center; gap:8px;">
                        <span>👤</span> Contact Information
                    </h2>
                    <div style="display:grid; grid-template-columns:1fr 1fr; gap:0.75rem;">
                        <div>
                            <label style="display:block; font-size:0.7rem; font-weight:700; color:#6b7280; text-transform:uppercase; margin-bottom:4px;">Full Name</label>
                            <input type="text" class="input-field" value="<?php echo htmlspecialchars($customer['first_name'] . ' ' . $customer['last_name']); ?>" disabled style="background:#f9fafb; font-size:0.85rem; padding:8px 12px;">
                        </div>
                        <div>
                            <label style="display:block; font-size:0.7rem; font-weight:700; color:#6b7280; text-transform:uppercase; margin-bottom:4px;">Email Address</label>
                            <input type="text" class="input-field" value="<?php echo htmlspecialchars($customer['email']); ?>" disabled style="background:#f9fafb; font-size:0.85rem; padding:8px 12px;">
                        </div>
                        <div style="grid-column:span 2;">
                            <label style="display:block; font-size:0.7rem; font-weight:700; color:#6b7280; text-transform:uppercase; margin-bottom:4px;">Phone Number</label>
                            <input type="text" class="input-field" value="<?php echo htmlspecialchars($customer['contact_number']); ?>" disabled style="background:#f9fafb; font-size:0.85rem; padding:8px 12px;">
                        </div>
                    </div>
                </div>

                <!-- 3. Branch Selection -->
                <div class="card compact-card">
                    <h2 style="font-size:1rem; font-weight:700; margin-bottom:1rem; border-bottom:1px solid #f3f4f6; padding-bottom:0.5rem; display:flex; align-items:center; gap:8px;">
                        <span>📍</span> Select Branch
                    </h2>
                    <?php 
                    $branches = db_query("SELECT id, branch_name FROM branches WHERE status = 'Active'"); 
                    $preset_branch = 1;
                    if (!empty($cart_items)) {
                        foreach($cart_items as $ci) {
                            if (!empty($ci['branch_id'])) { $preset_branch = $ci['branch_id']; break; }
                        }
                    }
                    ?>
                    <select name="order_branch_id" class="input-field" style="font-size:0.85rem; padding:8px 12px;" required>
                        <?php foreach($branches as $b): ?>
                            <option value="<?php echo $b['id']; ?>" <?php echo ($b['id'] == $preset_branch) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($b['branch_name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <!-- 4. Payment Policy -->
                <div class="card compact-card">
                    <h2 style="font-size:1rem; font-weight:700; margin-bottom:1rem; color:#111827; border-bottom:1px solid #f3f4f6; padding-bottom:0.5rem; display:flex; align-items:center; gap:8px;">
                        <span>💳</span> Payment Policy
                    </h2>
                    
                    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 0.75rem;">
                        <label style="display: flex; flex-direction: column; gap: 4px; padding: 10px; border: 1px solid #e5e7eb; border-radius: 10px; cursor: pointer; transition: all 0.2s;" onmouseover="this.style.background='#f9fafb'" onmouseout="this.style.background='white'">
                            <div style="display:flex; align-items:center; gap:8px;">
                                <input type="radio" name="payment_choice" value="full" style="width: 16px; height: 16px;">
                                <span style="font-weight: 700; font-size: 0.85rem; color: #1f2937;">Full (100%)</span>
                            </div>
                            <span style="font-size: 0.7rem; color: #6b7280; padding-left:24px;">Pay <?php echo format_currency($total); ?></span>
                        </label>

                        <label style="display: flex; flex-direction: column; gap: 4px; padding: 10px; border: 2px solid #4F46E5; background: #f5f3ff; border-radius: 10px; cursor: pointer;">
                            <div style="display:flex; align-items:center; gap:8px;">
                                <input type="radio" name="payment_choice" value="half" checked style="width: 16px; height: 16px;">
                                <span style="font-weight: 700; font-size: 0.85rem; color: #4F46E5;">Half (50%)</span>
                            </div>
                            <span style="font-size: 0.7rem; color: #6b7280; padding-left:24px;">Pay <?php echo format_currency($total * 0.5); ?></span>
                        </label>
                    </div>
                </div>

                <!-- 5. Order Notes -->
                <div class="card compact-card">
                    <h2 style="font-size:1rem; font-weight:700; margin-bottom:0.75rem; border-bottom:1px solid #f3f4f6; padding-bottom:0.5rem; display:flex; align-items:center; gap:8px;">
                        <span>📝</span> Order Notes
                    </h2>
                    <textarea name="notes" class="input-field" style="width:100%; min-height:80px; resize:vertical; font-size:0.85rem; padding:10px;" placeholder="Add special instructions for your entire order..."></textarea>
                </div>

                <!-- 6. Final Actions -->
                <div style="margin-top:0.5rem; text-align:center;">
                    <button type="submit" name="place_order" class="btn-primary" style="width:100%; padding:14px; font-weight:700; font-size:1.1rem; border-radius:12px; box-shadow:0 4px 6px -1px rgba(79, 70, 229, 0.2);">Place Order</button>
                    <a href="cart.php" style="display:inline-block; margin-top:1.25rem; font-size:0.875rem; color:#6b7280; text-decoration:none; font-weight:600; padding:8px 16px; transition:all 0.2s;" onmouseover="this.style.color='#ef4444'" onmouseout="this.style.color='#6b7280'">Returns to Cart</a>
                </div>
            </div>
        </form>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
