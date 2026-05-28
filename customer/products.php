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
        --shopee-orange: #0f3441;
        --shopee-bg: #ffffff;
        --shopee-card-bg: rgba(255, 255, 255, 0.78);
        --shopee-text: #173042;
        --shopee-muted: #688092;
        --shopee-border: rgba(126, 164, 184, 0.24);
        --shopee-glass-shadow: 0 22px 50px rgba(13, 45, 60, 0.12);
        --shopee-glass-shadow-hover: 0 28px 65px rgba(13, 45, 60, 0.2);
        --shopee-glow: linear-gradient(135deg, rgba(129, 212, 250, 0.3), rgba(255, 255, 255, 0.08) 45%, rgba(139, 226, 216, 0.18));
    }

    .shopee-grid {
        display: grid;
        grid-template-columns: repeat(4, minmax(0, 1fr));
        gap: 10px;
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
            border-radius: 16px;
        }

        /* Optimize card layout for mobile */
        .shopee-img {
            aspect-ratio: 1.15;
            width: 100%;
            height: auto;
            max-height: 210px;
            object-fit: cover;
            margin: 0;
            border-radius: 0;
        }

        .shopee-body {
            padding: 7px 8px 0;
            flex: 1;
            display: flex;
            flex-direction: column;
        }

        .shopee-name {
            font-size: 0.8rem;
            line-height: 1.02rem;
            min-height: 1.02rem;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
            margin-bottom: 4px;
        }

        .shopee-meta-row {
            gap: 6px;
            align-items: flex-start;
            flex-wrap: nowrap;
        }

        .shopee-price-row {
            margin-top: auto;
        }

        .shopee-price {
            font-size: 0.92rem;
        }

        .shopee-footer {
            padding: 6px 8px 8px;
            gap: 5px;
            flex-shrink: 0;
        }

        .shopee-btn {
            padding: 0.52rem 0.56rem;
            font-size: 0.62rem;
            min-height: 32px;
        }

        .rating-stars {
            font-size: 0.68rem;
            margin-bottom: 2px;
        }

        .rating-stars svg {
            width: 12px !important;
            height: 12px !important;
        }
    }

    .shopee-card {
        background: var(--shopee-card-bg);
        border: 1px solid var(--shopee-border);
        border-radius: 16px;
        transition: transform 0.28s ease, box-shadow 0.28s ease, border-color 0.28s ease;
        cursor: pointer;
        overflow: hidden;
        display: flex;
        flex-direction: column;
        height: 100%;
        min-height: 0;
        max-width: 100%;
        position: relative;
        box-shadow: var(--shopee-glass-shadow);
        backdrop-filter: blur(18px);
        -webkit-backdrop-filter: blur(18px);
    }

    .shopee-card::before {
        content: "";
        position: absolute;
        inset: 0;
        background: var(--shopee-glow);
        opacity: 0.85;
        pointer-events: none;
    }

    .shopee-card::after {
        content: "";
        position: absolute;
        inset: 1px;
        border-radius: 15px;
        border: 1px solid rgba(255, 255, 255, 0.45);
        pointer-events: none;
    }

    .shopee-card:hover {
        transform: translateY(-8px);
        box-shadow: var(--shopee-glass-shadow-hover);
        border-color: rgba(93, 158, 188, 0.42);
    }

    .shopee-card > * {
        position: relative;
        z-index: 1;
    }

    .shopee-img {
        width: 100%;
        min-width: 100%;
        display: block;
        margin: 0;
        aspect-ratio: 1.18;
        object-fit: cover;
        border-radius: 0;
        box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.45), 0 14px 30px rgba(16, 53, 71, 0.12);
        background: linear-gradient(180deg, rgba(240, 248, 252, 0.9), rgba(225, 236, 243, 0.9));
    }

    .shopee-body {
        padding: 6px 8px 0;
        flex-grow: 0;
        display: flex;
        flex-direction: column;
    }

    .shopee-meta-row {
        display: flex;
        justify-content: space-between;
        align-items: center;
        gap: 5px;
        margin-bottom: 3px;
        flex-wrap: nowrap;
    }

    .shopee-name {
        font-size: 0.78rem;
        line-height: 1rem;
        min-height: 1rem;
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
        color: var(--shopee-text);
        margin-bottom: 3px;
        font-weight: 700;
        letter-spacing: -0.02em;
    }

    .shopee-category {
        font-size: 0.58rem;
        color: #477089;
        display: inline-flex;
        align-items: center;
        width: fit-content;
        padding: 0.16rem 0.4rem;
        border-radius: 999px;
        background: rgba(255, 255, 255, 0.6);
        border: 1px solid rgba(126, 164, 184, 0.18);
        text-transform: uppercase;
        letter-spacing: 0.08em;
        font-weight: 700;
        white-space: nowrap;
    }

    .shopee-stock {
        font-size: 0.58rem;
        font-weight: 700;
        padding: 0.16rem 0.4rem;
        border-radius: 999px;
        background: rgba(255, 255, 255, 0.6);
        border: 1px solid rgba(126, 164, 184, 0.18);
        white-space: nowrap;
    }

    .shopee-price-row {
        margin-top: auto;
        margin-bottom: 0;
        display: flex;
        align-items: center;
        justify-content: space-between;
        padding-top: 0;
    }

    .shopee-price {
        color: #0f3441;
        font-weight: 800;
        font-size: 0.82rem;
        letter-spacing: -0.03em;
        white-space: nowrap;
    }

    .shopee-sold {
        margin-left: auto;
        font-size: 0.58rem;
        color: var(--shopee-muted);
        padding: 0.16rem 0.4rem;
        border-radius: 999px;
        background: rgba(255, 255, 255, 0.55);
        border: 1px solid rgba(126, 164, 184, 0.16);
        white-space: nowrap;
    }

    .shopee-footer {
        padding: 5px 8px 8px;
        border-top: 1px solid rgba(126, 164, 184, 0.16);
        display: flex;
        gap: 4px;
        margin-top: 4px;
    }

    .shopee-btn {
        flex: 1;
        padding: 0.5rem 0.54rem;
        border-radius: 12px;
        font-size: 0.6rem;
        font-weight: 700;
        text-align: center;
        text-transform: uppercase;
        transition: transform 0.22s ease, box-shadow 0.22s ease, background 0.22s ease, border-color 0.22s ease, opacity 0.22s ease;
        border: 1px solid transparent;
        display: flex;
        align-items: center;
        justify-content: center;
        text-decoration: none;
        letter-spacing: 0.05em;
        white-space: nowrap;
        line-height: 1;
    }

    .shopee-btn-cart {
        background: rgba(255, 255, 255, 0.68);
        color: var(--shopee-orange);
        border-color: rgba(126, 164, 184, 0.22);
        box-shadow: inset 0 1px 0 rgba(255,255,255,0.45);
    }

    .shopee-btn-buy {
        background: linear-gradient(135deg, #123746 0%, #0f4958 100%);
        color: #fff;
        box-shadow: 0 12px 24px rgba(15, 58, 73, 0.22);
    }

    .shopee-btn:hover {
        opacity: 1;
        transform: translateY(-2px);
    }

    .shopee-btn-cart:hover {
        box-shadow: 0 10px 22px rgba(15, 58, 73, 0.12);
        background: rgba(255, 255, 255, 0.85);
    }

    .shopee-btn-buy:hover {
        box-shadow: 0 16px 32px rgba(15, 58, 73, 0.28);
    }

    .rating-stars {
        color: #ffca11;
        font-size: 0.62rem;
        display: flex;
        align-items: center;
        gap: 2px;
        margin-bottom: 0;
        padding-top: 0;
        flex-wrap: nowrap;
    }

    .rating-text {
        font-size: 0.6rem;
        color: var(--shopee-muted);
        margin-left: 3px;
        font-weight: 600;
        white-space: nowrap;
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
                            <div class="shopee-meta-row">
                                <span class="shopee-category"><?php echo htmlspecialchars($product['category']); ?></span>
                                <span class="shopee-stock" style="color: <?php echo $stock > 10 ? '#059669' : ($stock > 0 ? '#f59e0b' : '#dc2626'); ?>"><?php echo $stock_display; ?> in stock</span>
                            </div>
                            <h3 class="shopee-name"><?php echo htmlspecialchars($product['name']); ?></h3>
                            
                            <div class="rating-stars">
                                <?php for($i=1; $i<=5; $i++): ?>
                                    <svg style="width: 14px; height: 14px;" fill="<?php echo ($i <= round($avg_rating)) ? '#ffca11' : '#e5e7eb'; ?>" viewBox="0 0 20 20">
                                        <path d="M9.049 2.927c.3-.921 1.603-.921 1.902 0l1.07 3.292a1 1 0 00.95.69h3.462c.969 0 1.371 1.24.588 1.81l-2.8 2.034a1 1 0 00-.364 1.118l1.07 3.292c.3.921-.755 1.688-1.54 1.118l-2.8-2.034a1 1 0 00-1.176 0l-2.8 2.034c-.784.57-1.838-.197-1.539-1.118l1.07-3.292a1 1 0 00-.364-1.118L2.98 8.72c-.783-.57-.38-1.81.588-1.81h3.461a1 1 0 00.951-.69l1.07-3.292z"></path>
                                    </svg>
                                <?php endfor; ?>
                                <span class="rating-text"><?php echo $review_count > 0 ? "($review_count)" : ''; ?></span>
                                <span class="shopee-sold"><?php echo $sold_display; ?> sold</span>
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
