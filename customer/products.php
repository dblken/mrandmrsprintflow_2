<?php
/**
 * Customer Products Page (Fixed Products Only)
 * PrintFlow - Printing Shop PWA
 */

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

require_role('Customer');

function pf_product_media_is_video($path) {
    $path = strtolower(trim((string)$path));
    return (bool)preg_match('/\.(mp4|webm|mov|m4v)(?:[\?#].*)?$/', $path);
}

// Reviews table columns vary across deployments
$review_cols = array_flip(array_column(db_query("SHOW COLUMNS FROM reviews") ?: [], 'Field'));
$review_service_expr = isset($review_cols['service_type']) ? 'r.service_type' : "''";
$review_order_expr = isset($review_cols['order_id']) ? 'r.order_id' : 'NULL';
$review_ref_expr = isset($review_cols['reference_id']) ? 'r.reference_id' : 'NULL';
$review_type_expr = isset($review_cols['review_type']) ? 'r.review_type' : "''";
$review_match_parts = [];
if (isset($review_cols['reference_id']) && isset($review_cols['review_type'])) {
    $review_match_parts[] = "({$review_type_expr} = 'product' AND {$review_ref_expr} = p.product_id)";
}
if (isset($review_cols['order_id'])) {
    $review_match_parts[] = "EXISTS (
        SELECT 1
        FROM order_items oi_rating
        WHERE oi_rating.order_id = {$review_order_expr}
          AND oi_rating.product_id = p.product_id
    )";
}
$review_match_parts[] = "{$review_service_expr} COLLATE utf8mb4_unicode_ci = p.name COLLATE utf8mb4_unicode_ci";
$review_match_sql = '(' . implode(' OR ', $review_match_parts) . ')';

$avg_rating_sql = "(SELECT AVG(rating) FROM reviews r WHERE {$review_match_sql}) as avg_rating";
$review_count_sql = "(SELECT COUNT(*) FROM reviews r WHERE {$review_match_sql}) as review_count";

// Get filter parameters
$category = $_GET['category'] ?? '';

// Build query — restore activated catalog items
$sql = "SELECT p.*, 
    (SELECT COUNT(*) FROM product_variants pv WHERE pv.product_id = p.product_id AND pv.status = 'Active') as variant_count,
    (SELECT COALESCE(SUM(oi.quantity),0) FROM order_items oi JOIN orders o ON oi.order_id = o.order_id WHERE oi.product_id = p.product_id AND o.status != 'Cancelled' AND (
        LOWER(TRIM(COALESCE(o.order_type, ''))) != 'custom'
        OR oi.customization_data LIKE '%\"config_id\"%'
        OR oi.customization_data LIKE '%\"form_type\":\"dynamic\"%'
        OR oi.customization_data LIKE '%\"form_type\": \"dynamic\"%'
        OR oi.customization_data LIKE '%\"source_page\":\"products\"%'
        OR oi.customization_data LIKE '%\"source_page\":\"product\"%'
        OR oi.customization_data LIKE '%\"source_page\":\"dynamic_form\"%'
        OR oi.customization_data LIKE '%\"source_page\": \"products\"%'
        OR oi.customization_data LIKE '%\"source_page\": \"product\"%'
        OR oi.customization_data LIKE '%\"source_page\": \"dynamic_form\"%'
    )) as sold_count,
    {$avg_rating_sql},
    {$review_count_sql}
    FROM products p 
    WHERE p.status = 'Activated'";
$params = [];
$types = '';

if (!empty($category)) {
    $sql .= " AND category = ?";
    $params[] = $category;
    $types .= 's';
}

// Pagination settings
$items_per_page = 12;
$current_page = max(1, (int)($_GET['page'] ?? 1));
$offset = ($current_page - 1) * $items_per_page;

// Count total items for pagination
$count_sql = "SELECT COUNT(*) as total FROM products WHERE status = 'Activated'";
$count_params = [];
$count_types = '';

if (!empty($category)) {
    $count_sql .= " AND category = ?";
    $count_params[] = $category;
    $count_types .= 's';
}

$total_result = db_query($count_sql, $count_types, $count_params);
$total_items = $total_result[0]['total'] ?? 0;
$total_pages = ceil($total_items / $items_per_page);

$sql .= " ORDER BY name ASC LIMIT ? OFFSET ?";
$params[] = $items_per_page;
$params[] = $offset;
$types .= 'ii';

$products = db_query($sql, $types, $params);

$page_title = 'Products - PrintFlow';
$use_customer_css = true;
require_once __DIR__ . '/../includes/header.php';
?>

<style>
    :root {
        --shopee-orange: #0a2530;
        --shopee-bg: #ffffff;
        --shopee-card-bg: #ffffff;
        --shopee-text: #212121;
        --shopee-muted: #757575;
        --shopee-border: rgba(0,0,0,0.09);
    }

    .shopee-grid {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(240px, 1fr));
        gap: 20px;
    }

    /* Tablet: 2 cards per row */
    @media (max-width: 1023px) and (min-width: 641px) {
        .shopee-grid {
            grid-template-columns: repeat(2, 1fr);
            gap: 16px;
        }
    }

    /* Mobile: 1 card per row (full width) */
    @media (max-width: 640px) {
        .shopee-grid {
            grid-template-columns: 1fr;
            gap: 16px;
        }

        .shopee-card {
            width: 100%;
            max-width: 100%;
            margin: 0;
            min-height: auto;
        }

        /* Optimize card layout for mobile */
        .shopee-img {
            aspect-ratio: 1.5;
            width: 100%;
            height: auto;
            max-height: 280px;
            object-fit: cover;
        }

        .shopee-body {
            padding: 14px;
            flex: 1;
            display: flex;
            flex-direction: column;
        }

        .shopee-name {
            font-size: 1.05rem;
            line-height: 1.4rem;
            height: 2.8rem;
            overflow: hidden;
            text-overflow: ellipsis;
            display: -webkit-box;
            -webkit-line-clamp: 2;
            -webkit-box-orient: vertical;
            margin-bottom: 8px;
        }

        .shopee-category {
            font-size: 0.8rem;
        }

        .shopee-price-row {
            margin-top: auto;
        }

        .shopee-price {
            font-size: 1.25rem;
        }

        .shopee-footer {
            padding: 10px 14px;
            gap: 10px;
            flex-shrink: 0;
        }

        .shopee-btn {
            padding: 10px 0;
            font-size: 0.85rem;
            min-height: 42px;
        }

        .rating-stars {
            font-size: 0.85rem;
            margin-bottom: 4px;
        }

        .rating-stars svg {
            width: 15px !important;
            height: 15px !important;
        }
    }

    .shopee-card {
        background: var(--shopee-card-bg);
        border: 1px solid var(--shopee-border);
        border-radius: 4px;
        transition: transform 0.2s, box-shadow 0.2s;
        cursor: pointer;
        overflow: hidden;
        display: flex;
        flex-direction: column;
        height: 100%;
    }

    .shopee-card:hover {
        transform: translateY(-2px);
        box-shadow: 0 4px 12px rgba(0,0,0,0.1);
        border-color: var(--shopee-orange);
    }

    .shopee-img {
        width: 100%;
        aspect-ratio: 1.2;
        object-fit: cover;
    }

    .shopee-body {
        padding: 10px 10px 0px 10px;
        flex-grow: 1;
        display: flex;
        flex-direction: column;
    }

    .shopee-name {
        font-size: 0.95rem;
        line-height: 1.3rem;
        height: 2.6rem;
        overflow: hidden;
        text-overflow: ellipsis;
        display: -webkit-box;
        -webkit-line-clamp: 2;
        -webkit-box-orient: vertical;
        color: var(--shopee-text);
        margin-bottom: 6px;
    }

    .shopee-category {
        font-size: 0.75rem;
        color: var(--shopee-muted);
        margin-bottom: 4px;
    }

    .shopee-price-row {
        margin-top: auto;
        margin-bottom: 0;
        display: flex;
        align-items: center;
        justify-content: space-between;
    }

    .shopee-price {
        color: var(--shopee-orange);
        font-weight: 600;
        font-size: 1.1rem;
    }

    .shopee-sold {
        font-size: 0.75rem;
        color: var(--shopee-muted);
    }

    .shopee-footer {
        padding: 8px 10px;
        border-top: 1px solid var(--shopee-border);
        display: flex;
        gap: 8px;
    }

    .shopee-btn {
        flex: 1;
        padding: 7px 0;
        border-radius: 3px;
        font-size: 0.8rem;
        font-weight: 600;
        text-align: center;
        text-transform: uppercase;
        transition: all 0.2s;
        border: 1px solid var(--shopee-orange);
        display: flex;
        align-items: center;
        justify-content: center;
        text-decoration: none;
    }

    .shopee-btn-cart {
        background: rgba(10, 37, 48, 0.08);
        color: var(--shopee-orange);
    }

    .shopee-btn-buy {
        background: var(--shopee-orange);
        color: #fff;
    }

    .shopee-btn:hover {
        opacity: 0.9;
    }

    .rating-stars {
        color: #ffca11;
        font-size: 0.8rem;
        display: flex;
        align-items: center;
        gap: 2px;
        margin-bottom: 2px;
    }

    .rating-text {
        font-size: 0.75rem;
        color: var(--shopee-muted);
        margin-left: 5px;
    }
</style>

<div class="min-h-screen py-8">
    <div class="container mx-auto px-4" style="max-width:1100px;">
        <div class="flex flex-col md:flex-row justify-between items-start md:items-center mb-6 gap-4">
            <h1 class="text-2xl font-bold text-gray-800">Available Products</h1>
        </div>

        <!-- Products Grid -->
        <?php if (empty($products)): ?>
            <div class="bg-white rounded-lg p-12 text-center shadow-sm">
                <div class="text-6xl mb-4">📦</div>
                <p class="text-gray-500 text-lg">No products found.</p>
                <a href="products.php" class="text-shopee-orange mt-4 inline-block hover:underline font-semibold">Browse all products</a>
            </div>
        <?php else: ?>
            <div class="shopee-grid">
                <?php foreach ($products as $product): 
                    $display_img = $product['photo_path'] ?: $product['product_image'] ?: "/printflow/public/assets/images/services/default.png";
                    if ($display_img[0] !== '/' && strpos($display_img, 'http') === false) $display_img = '/' . $display_img;
                    $is_video_media = pf_product_media_is_video($display_img);
                    
                    $sold_count = (int)$product['sold_count'];
                    $avg_rating = (float)$product['avg_rating'];
                    $review_count = (int)$product['review_count'];
                    $stock = (int)$product['stock_quantity'];
                    $sold_display = $sold_count >= 1000 ? number_format($sold_count / 1000, 1) . 'k' : $sold_count;
                    $stock_display = $stock >= 1000 ? number_format($stock / 1000, 1) . 'k' : $stock;
                ?>
                    <div class="shopee-card" onclick="window.location.href='order_create.php?product_id=<?php echo $product['product_id']; ?>'">
                        <?php if ($is_video_media): ?>
                            <video
                                src="<?php echo htmlspecialchars($display_img); ?>#t=1"
                                class="shopee-img"
                                muted
                                playsinline
                                preload="metadata"
                                onloadedmetadata="try{this.currentTime=1;}catch(e){}"
                                onseeked="this.style.opacity='1';"
                                style="background:#f8fafc;opacity:0;"
                            ></video>
                        <?php else: ?>
                            <img src="<?php echo htmlspecialchars($display_img); ?>" alt="<?php echo htmlspecialchars($product['name']); ?>" class="shopee-img">
                        <?php endif; ?>
                        <div class="shopee-body">
                            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 4px;">
                                <span class="shopee-category"><?php echo htmlspecialchars($product['category']); ?></span>
                                <span style="font-size: 0.75rem; font-weight: 600; color: <?php echo $stock > 10 ? '#059669' : ($stock > 0 ? '#f59e0b' : '#dc2626'); ?>"><?php echo $stock_display; ?> in stock</span>
                            </div>
                            <h3 class="shopee-name"><?php echo htmlspecialchars($product['name']); ?></h3>
                            
                            <div class="rating-stars">
                                <?php for($i=1; $i<=5; $i++): ?>
                                    <svg style="width: 14px; height: 14px;" fill="<?php echo ($i <= round($avg_rating)) ? '#ffca11' : '#e5e7eb'; ?>" viewBox="0 0 20 20">
                                        <path d="M9.049 2.927c.3-.921 1.603-.921 1.902 0l1.07 3.292a1 1 0 00.95.69h3.462c.969 0 1.371 1.24.588 1.81l-2.8 2.034a1 1 0 00-.364 1.118l1.07 3.292c.3.921-.755 1.688-1.54 1.118l-2.8-2.034a1 1 0 00-1.176 0l-2.8 2.034c-.784.57-1.838-.197-1.539-1.118l1.07-3.292a1 1 0 00-.364-1.118L2.98 8.72c-.783-.57-.38-1.81.588-1.81h3.461a1 1 0 00.951-.69l1.07-3.292z"></path>
                                    </svg>
                                <?php endfor; ?>
                                <span class="rating-text"><?php echo $review_count > 0 ? "($review_count)" : ''; ?></span>
                                <span style="margin-left: auto; font-size: 0.75rem; color: var(--shopee-muted);"><?php echo $sold_display; ?> sold</span>
                            </div>

                            <div class="shopee-price-row">
                                <span class="shopee-price"><?php echo format_currency($product['price']); ?></span>
                            </div>
                        </div>
                        <div class="shopee-footer" onclick="event.stopPropagation()">
                            <button onclick="addToCartDirect(<?php echo $product['product_id']; ?>)" class="shopee-btn shopee-btn-cart" title="Add to Cart">
                                <svg style="width: 1.25rem; height: 1.25rem;" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 3h2l.4 2M7 13h10l4-8H5.4M7 13L5.4 5M7 13l-2.293 2.293c-.63.63-.184 1.707.707 1.707H17m0 0a2 2 0 100 4 2 2 0 000-4zm-8 2a2 2 0 11-4 0 2 2 0 014 0z"></path></svg>
                            </button>
                            <a href="order_create.php?product_id=<?php echo $product['product_id']; ?>&buy_now=1" class="shopee-btn shopee-btn-buy">Order Now</a>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>

            <!-- Pagination -->
            <div class="mt-12 flex justify-center">
                <?php echo get_pagination_links($current_page, $total_pages, ['category' => $category]); ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<script>
var PF_CSRF_TOKEN = '<?php echo generate_csrf_token(); ?>';

async function addToCartDirect(productId) {
    try {
        const response = await fetch('api_cart.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                action: 'add',
                product_id: productId,
                quantity: 1,
                csrf_token: PF_CSRF_TOKEN
            })
        });

        const data = await response.json();

        if (data.success) {
            if (window.updateCartBadge) updateCartBadge(data.cart_count);
            showToast('Added to cart!');
        } else {
            showToast(data.message || 'Failed to add to cart.', true);
        }
    } catch (err) {
        console.error('Cart Error:', err);
        alert('An error occurred. Please try again.');
    }
}

function showToast(msg, isError) {
    let toast = document.getElementById('shopee-toast');
    if (!toast) {
        toast = document.createElement('div');
        toast.id = 'shopee-toast';
        document.body.appendChild(toast);
    }
    
    toast.textContent = msg;
    toast.style.cssText = `
        position: fixed;
        bottom: 5rem;
        left: 50%;
        transform: translateX(-50%);
        background: ${isError ? 'rgba(239,68,68,0.92)' : 'rgba(0,0,0,0.85)'};
        color: white;
        padding: 12px 24px;
        border-radius: 0;
        font-size: 0.9rem;
        font-weight: 500;
        z-index: 10000;
        transition: opacity 0.3s;
        pointer-events: none;
        box-shadow: 0 4px 12px rgba(0,0,0,0.2);
    `;
    
    toast.style.opacity = '1';
    setTimeout(() => {
        toast.style.opacity = '0';
    }, 2500);
}
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
