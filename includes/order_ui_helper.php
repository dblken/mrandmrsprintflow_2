<?php
/**
 * Shared Helper for Order Item UI
 * PrintFlow - Neubrutalism Design System
 */

if (!function_exists('pf_order_ui_value_to_text')) {
    function pf_order_ui_value_to_text($value): string {
        if (is_array($value)) {
            $parts = [];
            $is_list = array_keys($value) === range(0, count($value) - 1);

            foreach ($value as $key => $inner_value) {
                if ($inner_value === '' || $inner_value === null) {
                    continue;
                }

                $text = pf_order_ui_value_to_text($inner_value);
                if ($text === '') {
                    continue;
                }

                $parts[] = $is_list
                    ? $text
                    : ucwords(str_replace(['_', '-'], ' ', (string)$key)) . ': ' . $text;
            }

            return implode(', ', $parts);
        }

        if (is_bool($value)) {
            return $value ? 'Yes' : 'No';
        }

        if ($value === null) {
            return '';
        }

        return (string)$value;
    }
}

if (!function_exists('pf_order_ui_resolve_special_instructions_text')) {
    /**
     * Long-form text shown under “Notes” on order review — merges legacy note keys plus any *description* fields
     * that are excluded from the specification tiles (same idea as customer orders modal).
     */
    function pf_order_ui_resolve_special_instructions_text(array $custom, array $item = []): string {
        $notes = $custom['notes']
            ?? $custom['order_notes']
            ?? $custom['job_notes']
            ?? $custom['special_instructions']
            ?? $custom['additional_notes']
            ?? $custom['other_instructions']
            ?? ($item['notes'] ?? ($item['order_notes'] ?? ($item['job_notes'] ?? ($item['design_notes'] ?? null))));
        if ($notes === null || $notes === '') {
            $notes = $custom['design_description']
                ?? $custom['tshirt_design_description']
                ?? $custom['tarp_design_description']
                ?? $custom['design_notes']
                ?? null;
        }
        $parts = [];
        if ($notes !== null && $notes !== '') {
            $t = trim(pf_order_ui_value_to_text($notes));
            if ($t !== '') {
                $parts[] = $t;
            }
        }
        foreach ($custom as $ck => $cv) {
            if (!is_string($ck) || $ck === '') {
                continue;
            }
            if ($cv === null || $cv === '') {
                continue;
            }
            if (stripos($ck, 'description') === false) {
                continue;
            }
            if (in_array($ck, ['design_description', 'tshirt_design_description', 'tarp_design_description'], true)) {
                continue;
            }
            $t = trim(pf_order_ui_value_to_text($cv));
            if ($t === '') {
                continue;
            }
            $parts[] = trim(str_replace(['_', '-'], ' ', $ck)) . ': ' . $t;
        }

        return implode("\n\n", $parts);
    }
}

if (!function_exists('pf_order_ui_should_skip_spec_key')) {
    /**
     * Keys/labels that belong in the header, notes block, or design preview — not spec tiles.
     */
    function pf_order_ui_should_skip_spec_key(string $key): bool
    {
        $key = trim($key);
        if ($key === '') {
            return true;
        }

        static $skipExact = [
            'design_upload', 'reference_upload', 'notes', 'order_notes', 'job_notes',
            'special_instructions', 'additional_notes', 'other_instructions',
            'design_notes', 'Branch_ID', 'service_type', 'product_type', 'unit',
            'install_province', 'install_city', 'install_barangay', 'install_street',
            'source_page', 'source', 'service_id', 'quantity', 'Quantity', 'Notes', 'Note',
            'design_upload_data', 'upload_design_data', 'design_data',
            'reference_upload_data', 'upload_reference_data', 'reference_data',
            'design_upload_path', 'design_file', 'reference_file', 'layout_file',
            'design_upload_name', 'design_upload_mime', 'reference_upload_name', 'reference_upload_mime',
        ];
        if (in_array($key, $skipExact, true)) {
            return true;
        }

        $normalized = strtolower(preg_replace('/[^a-z0-9]/', '', $key));
        if (in_array($normalized, [
            'quantity', 'qty', 'notes', 'note', 'ordernotes', 'jobnotes', 'specialinstructions',
            'otherinstructions', 'additionalnotes', 'designnotes', 'sourcepage', 'serviceid',
            'source', 'uploaddesign', 'designupload', 'designfile', 'designuploadpath',
            'designuploaddata', 'uploaddesigndata', 'designdata', 'designuploadname',
            'designuploadmime', 'referenceuploadname', 'referenceuploadmime',
            'referenceupload', 'referencefile', 'referenceuploaddata', 'uploadreferencedata',
            'referencedata',
        ], true)) {
            return true;
        }

        if (preg_match('/upload\s*design/iu', $key)) {
            return true;
        }
        if (preg_match('/reference\s*(attachment|image|upload)/iu', $key)) {
            return true;
        }
        if (preg_match('/^(notes?|order\s*notes?|job\s*notes?|special\s*instructions?|other\s*instructions?|additional\s*notes?|design\s*notes?|source\s*page|service\s*id)$/iu', $key)) {
            return true;
        }

        return false;
    }
}

if (!function_exists('pf_order_ui_normalize_review_customization')) {
    /**
     * Order review / cart item cards: remove duplicate needed-date tiles and redundant
     * quantity / notes / upload filename rows (shown elsewhere on the card).
     */
    function pf_order_ui_normalize_review_customization(array $custom, array $item, bool $is_cart_item): array {
        $needed_val = null;
        foreach ($custom as $ck => $cv) {
            if ($cv === '' || $cv === null) {
                continue;
            }
            $nk = strtolower(preg_replace('/[^a-z0-9]/', '', (string)$ck));
            if (in_array($nk, ['neededdate', 'dateneeded', 'orderneededdate'], true)) {
                $needed_val = $cv;
                break;
            }
        }

        $out = [];
        $needed_written = false;
        foreach ($custom as $ck => $cv) {
            if ($cv === '' || $cv === null) {
                continue;
            }
            if (pf_order_ui_should_skip_spec_key((string)$ck)) {
                continue;
            }
            $nk = strtolower(preg_replace('/[^a-z0-9]/', '', (string)$ck));
            if (in_array($nk, ['neededdate', 'dateneeded', 'orderneededdate'], true)) {
                if ($needed_written) {
                    continue;
                }
                $needed_written = true;
                $out['needed_date'] = $needed_val ?? $cv;
                continue;
            }
            $out[$ck] = $cv;
        }

        if (!$needed_written && $needed_val !== null) {
            $out['needed_date'] = $needed_val;
        }

        return $out;
    }
}

if (!function_exists('pf_order_ui_cart_has_design_upload')) {
    function pf_order_ui_cart_has_design_upload(array $item): bool
    {
        if (!empty($item['design_tmp_path']) && is_file((string)$item['design_tmp_path'])) {
            return true;
        }
        if (!empty($item['design_name']) || !empty($item['design_mime'])) {
            return true;
        }
        if (!empty($item['uploaded_files']) && is_array($item['uploaded_files'])) {
            foreach ($item['uploaded_files'] as $upload) {
                $field = strtolower(trim((string)($upload['field'] ?? '')));
                if ($field === '' || str_contains($field, 'design')) {
                    return true;
                }
            }
        }

        $custom = is_array($item['customization'] ?? null)
            ? $item['customization']
            : (is_string($item['customization'] ?? null) ? json_decode((string)$item['customization'], true) : []);
        if (!is_array($custom)) {
            return false;
        }

        foreach ($custom as $key => $value) {
            if (!is_scalar($value) || trim((string)$value) === '') {
                continue;
            }
            $nk = strtolower(preg_replace('/[^a-z0-9]/', '', (string)$key));
            if (in_array($nk, ['designupload', 'desingupload', 'designfile', 'uploaddesign'], true)) {
                return true;
            }
            if (str_contains($nk, 'design') && (str_contains($nk, 'upload') || str_contains($nk, 'file'))) {
                return true;
            }
        }

        return false;
    }
}

if (!function_exists('pf_order_ui_temp_preview_url')) {
    function pf_order_ui_temp_preview_url(array $item, string $field): ?string {
        $cart_key = (string)($item['_cart_key'] ?? '');
        if ($cart_key === '') {
            return null;
        }

        $path_key = $field === 'reference' ? 'reference_tmp_path' : 'design_tmp_path';
        $mime_key = $field === 'reference' ? 'reference_mime' : 'design_mime';

        if (empty($item[$path_key]) || !is_file((string)$item[$path_key])) {
            return null;
        }

        $mime = (string)($item[$mime_key] ?? '');
        // Be permissive with MIME types for temp previews, but prioritize images
        if ($mime !== '' && stripos($mime, 'image/') !== 0 && stripos($mime, 'application/octet-stream') === false) {
            // Allow if it's likely a file we can serve
        }

        $base = defined('BASE_URL') ? BASE_URL : (function_exists('pf_app_base_path') ? pf_app_base_path() : '');
        return rtrim($base, '/') . '/customer/temp_upload_preview.php?item=' . rawurlencode($cart_key) . '&field=' . rawurlencode($field);
    }
}

if (!function_exists('pf_order_ui_resolve_customer_upload_url')) {
    function pf_order_ui_resolve_customer_upload_url(array $item, bool $is_cart_item): ?string
    {
        $base_url = defined('BASE_URL') ? BASE_URL : (function_exists('pf_app_base_path') ? pf_app_base_path() : '');

        if ($is_cart_item) {
            $tmp = pf_order_ui_temp_preview_url($item, 'design');
            if ($tmp) {
                return $tmp;
            }

            $custom = is_array($item['customization'] ?? null)
                ? $item['customization']
                : (is_string($item['customization'] ?? null) ? json_decode((string)$item['customization'], true) : []);
            if (is_array($custom)) {
                foreach (['design_upload_data', 'upload_design_data', 'design_upload', 'design_data'] as $key) {
                    $val = trim((string)($custom[$key] ?? ''));
                    if ($val !== '' && str_starts_with($val, 'data:')) {
                        return $val;
                    }
                }
            }

            return null;
        }

        if (function_exists('getOrderDesignImage') && (int)($item['order_item_id'] ?? 0) > 0) {
            $resolved = getOrderDesignImage($item, ['heal' => true]);
            if (!empty($resolved['exists'])) {
                return $resolved['direct_url'] ?? $resolved['serve_url'] ?? $resolved['url'];
            }
        }

        $has_design = !empty($item['design_image']) || !empty($item['design_file']);
        if ($has_design && (int)($item['order_item_id'] ?? 0) > 0) {
            return rtrim($base_url, '/') . '/public/serve_design.php?type=order_item&id=' . (int)$item['order_item_id'];
        }

        return null;
    }
}

if (!function_exists('pf_order_ui_resolve_catalog_image_url')) {
    function pf_order_ui_resolve_catalog_image_url(array $item, bool $is_cart_item, string $fallbackName = ''): ?string
    {
        if (pf_order_ui_is_service_item($item, $is_cart_item)) {
            foreach (['service_image', 'catalog_service_image'] as $serviceImageField) {
                if (!empty($item[$serviceImageField])) {
                    $url = pf_order_ui_asset_url($item[$serviceImageField]);
                    if ($url) {
                        return $url;
                    }
                }
            }
        }

        if (pf_order_ui_is_service_item($item, $is_cart_item)
            && function_exists('printflow_resolve_order_service_catalog_image_url')) {
            $serviceUrl = printflow_resolve_order_service_catalog_image_url($item, $fallbackName);
            if (!empty($serviceUrl)) {
                return $serviceUrl;
            }
        }

        if (!empty($item['product_image'])) {
            $url = pf_order_ui_asset_url($item['product_image']);
            if ($url) {
                return $url;
            }
        }

        return null;
    }
}

if (!function_exists('pf_order_ui_resolve_item_design_urls')) {
    /**
     * @return array{design_url:?string,upload_url:?string,catalog_url:?string,ref_url:?string}
     */
    function pf_order_ui_resolve_item_design_urls(array $item, bool $is_cart_item, string $fallbackName = ''): array
    {
        $base_url = defined('BASE_URL') ? BASE_URL : (function_exists('pf_app_base_path') ? pf_app_base_path() : '');
        $upload_url = pf_order_ui_resolve_customer_upload_url($item, $is_cart_item);
        $catalog_url = pf_order_ui_resolve_catalog_image_url($item, $is_cart_item, $fallbackName);
        $ref_url = null;

        if ($is_cart_item) {
            $ref_url = pf_order_ui_temp_preview_url($item, 'reference');
        } elseif (!empty($item['reference_image_file']) && (int)($item['order_item_id'] ?? 0) > 0) {
            $ref_url = rtrim($base_url, '/') . '/public/serve_design.php?type=order_item&id=' . (int)$item['order_item_id'] . '&field=reference';
        }

        return [
            'upload_url'  => $upload_url,
            'catalog_url' => $catalog_url,
            'design_url'  => $upload_url ?: $catalog_url,
            'ref_url'     => $ref_url,
        ];
    }
}

if (!function_exists('pf_order_ui_escape')) {
    function pf_order_ui_escape($value): string {
        return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('pf_order_ui_asset_url')) {
    function pf_order_ui_asset_url($path): ?string {
        $path = trim((string)$path);
        if ($path === '') {
            return null;
        }

        $base = defined('BASE_URL') ? rtrim((string)BASE_URL, '/') : (function_exists('pf_app_base_path') ? rtrim((string)pf_app_base_path(), '/') : '');
        $path = str_replace('\\', '/', $path);

        // Keep absolute URLs intact (CDN / full host) so thumbnails actually load in the browser.
        if (preg_match('#^https?://#i', $path)) {
            return $path;
        }

        if (preg_match('#^[A-Za-z]:/#', $path)) {
            $path = preg_replace('#^[A-Za-z]:#', '', $path);
        }

        foreach (['/public/', '/uploads/'] as $marker) {
            $pos = strpos($path, $marker);
            if ($pos !== false) {
                $path = substr($path, $pos);
                break;
            }
        }

        if ($base === '' && strpos($path, '/printflow/') === 0) {
            $path = substr($path, strlen('/printflow'));
        }

        if ($base !== '' && strpos($path, $base . '/') === 0) {
            return $path;
        }

        if ($path !== '' && $path[0] !== '/') {
            if (strpos($path, 'uploads/') === 0 || strpos($path, 'public/') === 0) {
                $path = '/' . $path;
            } else {
                $filename = basename($path);
                $public_product = __DIR__ . '/../public/images/products/' . $filename;
                $uploaded_product = __DIR__ . '/../uploads/products/' . $filename;
                $path = is_file($public_product)
                    ? '/public/images/products/' . $filename
                    : '/uploads/products/' . $filename;
                if (!is_file($public_product) && !is_file($uploaded_product) && preg_match('/^product_\d+$/', $filename)) {
                    foreach (['jpg', 'png', 'jpeg', 'webp'] as $ext) {
                        if (is_file(__DIR__ . '/../public/images/products/' . $filename . '.' . $ext)) {
                            $path = '/public/images/products/' . $filename . '.' . $ext;
                            break;
                        }
                    }
                }
            }
        }

        return ($base !== '' ? $base : '') . $path;
    }
}

if (!function_exists('pf_order_ui_is_product_item')) {
    function pf_order_ui_is_product_item(array $item, bool $is_cart_item = false): bool {
        if (!$is_cart_item) {
            $custom = printflow_decode_modal_customization_payload($item['customization_data'] ?? []);
            return empty($custom['service_type']);
        }

        $custom = $item['customization'] ?? [];
        if (is_string($custom)) {
            $custom = printflow_decode_modal_customization_payload($custom);
        }

        $source_page = strtolower(trim((string)($item['source_page'] ?? '')));
        $item_type = strtolower(trim((string)($item['type'] ?? '')));
        $cart_key = strtolower(trim((string)($item['_cart_key'] ?? '')));
        $product_id = (int)($item['product_id'] ?? 0);
        $service_id = (int)($item['service_id'] ?? 0);

        if ($source_page === 'services' || $item_type === 'service' || strpos($cart_key, 'service_') === 0 || $service_id > 0 || !empty($custom['service_type'])) {
            return false;
        }

        if ($source_page === 'products' || $source_page === 'dynamic_form' || $item_type === 'product' || strpos($cart_key, 'product_') === 0) {
            return true;
        }

        return $product_id > 0;
    }
}

if (!function_exists('pf_order_ui_is_service_item')) {
    function pf_order_ui_is_service_item(array $item, bool $is_cart_item = false): bool {
        return !pf_order_ui_is_product_item($item, $is_cart_item);
    }
}

if (!function_exists('pf_order_ui_catalog_unit_price')) {
    function pf_order_ui_catalog_unit_price(array $item): ?float {
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

        $product = db_query("SELECT price FROM products WHERE product_id = ? LIMIT 1", 'i', [$product_id]);
        return $cache[$cache_key] = (!empty($product) ? (float)$product[0]['price'] : null);
    }
}

if (!function_exists('pf_order_ui_item_unit_price')) {
    function pf_order_ui_item_unit_price(array $item, bool $is_cart_item = false): float {
        $raw_price = $is_cart_item
            ? (float)($item['price'] ?? $item['unit_price'] ?? $item['estimated_price'] ?? 0)
            : (float)($item['unit_price'] ?? $item['price'] ?? 0);

        if (!$is_cart_item || !pf_order_ui_is_product_item($item, true)) {
            return $raw_price;
        }

        $catalog_unit_price = pf_order_ui_catalog_unit_price($item);
        if ($catalog_unit_price === null || $catalog_unit_price <= 0) {
            return $raw_price;
        }

        $quantity = max(1, (int)($item['quantity'] ?? 1));
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
}

if (!function_exists('pf_order_ui_item_estimated_total')) {
    function pf_order_ui_item_estimated_total(array $item, bool $is_cart_item = false): float {
        $quantity = max(1, (int)($item['quantity'] ?? 1));
        $estimated = (float)($item['estimated_price'] ?? 0);
        if ($estimated > 0) {
            return $estimated;
        }
        return 0.0;
    }
}

/**
 * Renders a single order item card in the Neubrutalism style.
 * Supports both cart items (session) and database items (order_items table).
 *
 * @param array $item The item data
 * @param bool $is_cart_item Whether this is from the session cart
 */
function render_order_item_neubrutalism($item, $is_cart_item = false, $show_price = true) {
    // 1. Data Normalization
    $custom = $is_cart_item
        ? printflow_decode_modal_customization_payload($item['customization'] ?? [])
        : printflow_decode_modal_customization_payload($item['customization_data'] ?? '');
    $custom = pf_order_ui_normalize_review_customization($custom, $item, $is_cart_item);
    $name = printflow_resolve_order_item_name($item['name'] ?? ($item['product_name'] ?? null), $custom, 'Order Item');
    $category = $item['category'] ?? 'General';
    $is_service_item = pf_order_ui_is_service_item($item, $is_cart_item);
    $unit_price = pf_order_ui_item_unit_price($item, $is_cart_item);
    $quantity = max(1, (int)($item['quantity'] ?? 1));
    $subtotal = $unit_price * $quantity;
    $estimated_total = $is_service_item ? pf_order_ui_item_estimated_total($item, $is_cart_item) : $subtotal;
    $estimated_total_display = $estimated_total > 0 ? format_currency($estimated_total) : 'To Be Discussed';
    $media = pf_order_ui_resolve_item_design_urls($item, $is_cart_item, $name);
    $upload_url = $media['upload_url'] ?? null;
    $catalog_url = $media['catalog_url'] ?? null;
    $header_image_url = $is_service_item ? $catalog_url : ($catalog_url ?: $upload_url);
    $ref_url = $media['ref_url'];

    // Field Map for Labels
    $field_map = [
        'size' => 'Size',
        'color' => 'Color',
        'shirt_color' => 'Color',
        'print_placement' => 'Placement',
        'design_type' => 'Design Type',
        'template' => 'Template',
        'width' => 'Width (ft)',
        'height' => 'Height (ft)',
        'finish' => 'Finish',
        'with_eyelets' => 'Eyelets',
        'shape' => 'Shape',
        'waterproof' => 'Waterproof',
        'Sintra_Type' => 'Sintraboard Type',
        'laminate_option' => 'Lamination Option',
        'lamination' => 'Lamination',
        'tshirt_provider' => 'T-Shirt Provider',
        'shirt_source' => 'Shirt Source',
        'Stand_Type' => 'Stand Type',
        'Cut_Type' => 'Cut Type',
        'Thickness' => 'Thickness',
        'Lamination' => 'Lamination Type',
        'needed_date' => 'Needed Date',
        'installation_fee' => 'Installation Fee',
    ];
    
    ?>
    <div style="border: 2px solid #000; background: #fff; margin-bottom: 2rem; overflow: hidden; box-shadow: 8px 8px 0px rgba(0,0,0,1);">
        <!-- Top Section: Core Info -->
        <div style="padding: 1.5rem; border-bottom: 2px solid #000; display: flex; gap: 1.5rem; align-items: flex-start;">
            <div style="width: 120px; height: 120px; border: 2px solid #000; border-radius: 8px; overflow: hidden; background: #f3f4f6; display: flex; align-items: center; justify-content: center; flex-shrink: 0;">
                <?php if ($header_image_url): ?>
                    <img src="<?php echo htmlspecialchars($header_image_url); ?>" style="width: 100%; height: 100%; object-fit: cover;">
                <?php else: ?>
                    <span style="font-size: 2.5rem; color: #9ca3af; text-align: center;">Item</span>
                <?php endif; ?>
            </div>
            
            <div style="flex: 1; min-width: 0;">
                <div style="font-size: 1.5rem; font-weight: 900; margin-bottom: 0.25rem; word-wrap: break-word;"><?php echo pf_order_ui_escape($name); ?></div>
                <div style="font-size: 0.75rem; font-weight: 800; color: #6b7280; text-transform: uppercase; letter-spacing: 0.1em; margin-bottom: 1rem; word-wrap: break-word;">
                    <?php echo pf_order_ui_escape($category); ?>
                </div>
                
                <div style="display: flex; flex-wrap: wrap; gap: 1rem;">
                    <?php if ($show_price && !$is_service_item): ?>
                    <div style="min-width: 120px;">
                        <div style="font-size: 0.95rem; font-weight: 800;">Price: <?php echo format_currency($unit_price); ?></div>
                    </div>
                    <?php endif; ?>
                    <div style="min-width: 80px;">
                        <div style="font-size: 0.95rem; font-weight: 800;">Qty: <?php echo $quantity; ?></div>
                    </div>
                    <?php if ($show_price): ?>
                    <div style="min-width: 150px;">
                        <div style="font-size: 0.95rem; font-weight: 800;"><?php echo $is_service_item ? 'Estimated Price' : 'Subtotal'; ?>: <?php echo $is_service_item ? pf_order_ui_escape($estimated_total_display) : format_currency($subtotal); ?></div>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Middle Section: Customization -->
        <div style="padding: 1.5rem; background: #fcfcfc;">
            <div style="font-size: 0.75rem; font-weight: 900; text-transform: uppercase; letter-spacing: 0.05em; margin-bottom: 1rem; color: #000; display: flex; align-items: center; gap: 6px;">
                <span style="width: 8px; height: 8px; background: #000; border-radius: 50%;"></span>
                Specifications
            </div>
            
            <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(140px, 1fr)); gap: 1rem;">
                <?php 
                $has_specs = false;
                foreach ($custom as $ck => $cv): 
                    if (empty($cv) || pf_order_ui_should_skip_spec_key((string)$ck) || stripos((string)$ck, 'description') !== false) continue;
                    $has_specs = true;
                    $label = $field_map[$ck] ?? ucwords(str_replace(['_', '-'], ' ', (string)$ck));
                    $display_val = ($ck === 'tshirt_provider' && $cv === 'shop') ? 'Shop will provide' : (($ck === 'tshirt_provider' && $cv === 'customer') ? 'Customer will provide' : (($ck === 'installation_fee' && is_numeric($cv)) ? format_currency((float)$cv) : pf_order_ui_value_to_text($cv)));
                ?>
                    <div style="border: 1px solid #000; padding: 0.75rem; border-radius: 6px; background: #fff; min-width: 0;">
                        <div style="font-size: 0.6rem; font-weight: 800; color: #6b7280; text-transform: uppercase; margin-bottom: 2px;"><?php echo pf_order_ui_escape($label); ?></div>
                        <div style="font-size: 0.9rem; font-weight: 800; color: #000; overflow-wrap: break-word; word-break: break-word;"><?php echo pf_order_ui_escape($display_val); ?></div>
                    </div>
                <?php endforeach; ?>
                
                <?php if (!$has_specs): ?>
                    <div style="font-size: 0.8rem; color: #9ca3af; font-style: italic;">No specific customizations.</div>
                <?php endif; ?>
            </div>

            <!-- Notes -->
            <?php
            $notesCombined = pf_order_ui_resolve_special_instructions_text($custom, $item);
            if ($notesCombined !== ''):
            ?>
                <div style="margin-top: 1rem; padding: 1rem; background: #fffbeb; border: 1px solid #000; border-radius: 8px; min-width: 0;">
                    <div style="font-size: 0.7rem; font-weight: 800; text-transform: uppercase; color: #92400e; margin-bottom: 4px;">Notes</div>
                    <div style="font-size: 0.9rem; font-weight: 700; color: #b45309; line-height: 1.4; overflow-wrap: break-word; word-break: break-word; white-space: pre-wrap;"><?php echo nl2br(pf_order_ui_escape($notesCombined)); ?></div>
                </div>
            <?php endif; ?>
        </div>

        <!-- Reference -->
        <?php if ($ref_url): ?>
            <div style="padding: 1.25rem; background: #fff; border-top: 1px solid #000; border-style: dashed;">
                <div style="font-size: 0.75rem; font-weight: 900; text-transform: uppercase; margin-bottom: 0.75rem;">Reference Image</div>
                <div style="display: inline-block; padding: 6px; border: 2px solid #000; border-radius: 8px; background: white; box-shadow: 4px 4px 0px rgba(0,0,0,0.1);">
                    <img src="<?php echo htmlspecialchars($ref_url); ?>" style="max-width: 140px; border-radius: 4px; display: block;">
                </div>
            </div>
        <?php endif; ?>
    </div>
    <?php
}

/**
 * Renders a single order item card in a clean, modern style.
 *
 * @param array $item The item data
 * @param bool $is_cart_item Whether this is from the session cart
 * @param bool $show_quantity Whether to show quantity in header
 */
function render_order_item_clean($item, $is_cart_item = false, $show_price = true, $show_quantity = true) {
    // 1. Data Normalization
    $rawCustom = $is_cart_item
        ? printflow_decode_modal_customization_payload($item['customization'] ?? [])
        : printflow_decode_modal_customization_payload($item['customization_data'] ?? '');
    $custom = pf_order_ui_normalize_review_customization($rawCustom, $item, $is_cart_item);
    $notesCombined = pf_order_ui_resolve_special_instructions_text($rawCustom, $item);
    $name = printflow_resolve_order_item_name($item['name'] ?? ($item['product_name'] ?? 'Order Item'), $custom, 'Order Item');
    
    $category = $item['category'] ?? 'General';
    $is_service_item = pf_order_ui_is_service_item($item, $is_cart_item);
    $unit_price = pf_order_ui_item_unit_price($item, $is_cart_item);
    $quantity = max(1, (int)($item['quantity'] ?? 1));
    $subtotal = $unit_price * $quantity;
    $estimated_total = $is_service_item ? pf_order_ui_item_estimated_total($item, $is_cart_item) : $subtotal;
    $estimated_total_display = $estimated_total > 0 ? format_currency($estimated_total) : 'To Be Discussed';
    $media = pf_order_ui_resolve_item_design_urls($item, $is_cart_item, $name);
    $upload_url = $media['upload_url'] ?? null;
    $catalog_url = $media['catalog_url'] ?? null;
    $header_image_url = $is_service_item ? $catalog_url : ($catalog_url ?: $upload_url);
    $ref_url = $media['ref_url'];

    $field_map = [
        'size' => 'Size',
        'color' => 'Color',
        'shirt_color' => 'Color',
        'print_placement' => 'Placement',
        'design_type' => 'Design Type',
        'template' => 'Template',
        'width' => 'Width (ft)',
        'height' => 'Height (ft)',
        'finish' => 'Finish',
        'with_eyelets' => 'Eyelets',
        'shape' => 'Shape',
        'waterproof' => 'Waterproof',
        'Sintra_Type' => 'Sintraboard Type',
        'laminate_option' => 'Lamination Option',
        'lamination' => 'Lamination',
        'tshirt_provider' => 'T-Shirt Provider',
        'shirt_source' => 'Shirt Source',
        'Stand_Type' => 'Stand Type',
        'Cut_Type' => 'Cut Type',
        'Thickness' => 'Thickness',
        'Lamination' => 'Lamination Type',
        'needed_date' => 'Needed Date',
        'installation_fee' => 'Installation Fee',
    ];
    ?>
    <div style="background: #0a2530; padding: 0; overflow: hidden; border: 1px solid rgba(83, 197, 224, 0.24); border-radius: 16px; margin-bottom: 1.5rem; box-shadow: 0 10px 25px rgba(0,0,0,0.3); width: 100%; max-width: 100%; box-sizing: border-box;">
        <!-- Core Info -->
        <div class="order-item-header" style="padding: 1.25rem; display: flex; gap: 1.25rem; align-items: flex-start; border-bottom: 1px solid rgba(83, 197, 224, 0.15); background: rgba(255,255,255,0.02);">
            <div class="order-item-image" style="width: 130px; height: 130px; border-radius: 12px; overflow: hidden; background: rgba(0,0,0,0.35); border: 1px solid rgba(83, 197, 224, 0.2); display: flex; align-items: center; justify-content: center; flex-shrink: 0; box-shadow: inset 0 2px 10px rgba(0,0,0,0.2);">
                <?php if ($header_image_url): ?>
                    <img src="<?php echo htmlspecialchars($header_image_url); ?>" style="width: 100%; height: 100%; object-fit: cover; transition: transform 0.3s ease-in-out;" onmouseover="this.style.transform='scale(1.08)'" onmouseout="this.style.transform='scale(1)'">
                <?php else: ?>
                    <span style="font-size: 2.2rem; color: rgba(255,255,255,0.15);">Item</span>
                <?php endif; ?>
            </div>
            
            <div class="order-item-content" style="flex: 1; min-width: 0; display: flex; flex-direction: column;">
                <h3 style="font-size: 0.95rem; line-height: 1.3rem; font-weight: 600; color: #ffffff !important; margin: 0 0 0.3rem 0; word-wrap: break-word;"><?php echo pf_order_ui_escape($name); ?></h3>
                <div class="order-item-category-badge" style="display: inline-flex; font-size: 0.72rem; font-weight: 700; color: #53c5e0; text-transform: uppercase; letter-spacing: 0.08em; padding: 3px 10px; border-radius: 20px; background: rgba(83, 197, 224, 0.12); border: 1px solid rgba(83, 197, 224, 0.18); margin-bottom: 1.25rem; align-self: flex-start;">
                    <?php echo pf_order_ui_escape($category); ?>
                </div>
                
                <div class="order-item-details" style="display: flex; justify-content: space-between; gap: 0.75rem; flex-wrap: wrap; margin-top: auto;">
                    <?php if ($show_quantity): ?>
                    <div class="review-detail-row" style="flex: 1; min-width: 80px;">
                        <div class="review-detail-label" style="font-size: 0.68rem; color: #9fc4d4; font-weight: 700; text-transform: uppercase; margin-bottom: 2px;">Quantity</div>
                        <div class="review-detail-value" style="font-size: 1rem; color: #eaf6fb; font-weight: 700;"><?php echo $quantity; ?></div>
                    </div>
                    <?php endif; ?>
                    <?php if ($show_price): ?>
                    <?php if (!$is_service_item): ?>
                    <div class="review-detail-row" style="flex: 1; min-width: 100px;">
                        <div class="review-detail-label" style="font-size: 0.68rem; color: #9fc4d4; font-weight: 700; text-transform: uppercase; margin-bottom: 2px;">Unit Price</div>
                        <div class="review-detail-value" style="font-size: 1rem; color: #eaf6fb; font-weight: 700;"><?php echo format_currency($unit_price); ?></div>
                    </div>
                    <?php endif; ?>
                    <div class="review-total-row" style="flex: 1; min-width: 100px;">
                        <div class="review-total-label" style="font-size: 0.68rem; color: #53c5e0; font-weight: 700; text-transform: uppercase; margin-bottom: 2px;"><?php echo $is_service_item ? 'Estimated Price' : 'Total'; ?></div>
                        <div class="review-total-value" style="font-size: 1rem; color: #53c5e0; font-weight: 800;"><?php echo $is_service_item ? pf_order_ui_escape($estimated_total_display) : format_currency($subtotal); ?></div>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Specifications -->
        <div class="order-item-specs" style="padding: 1.25rem; background: transparent;">
            <h4 style="font-size: 0.85rem; font-weight: 800; color: #eaf6fb; margin-bottom: 1rem; display: flex; align-items: center; gap: 8px; border-bottom: 1px solid rgba(83, 197, 224, 0.12); padding-bottom: 0.5rem;">
                <svg style="width: 16px; height: 16px; color: #53c5e0;" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                Order Specifications
            </h4>
            
            <div class="review-spec-grid order-item-spec-grid" style="display: grid; grid-template-columns: repeat(auto-fill, minmax(140px, 1fr)); gap: 0.75rem; width: 100%;">
                <?php 
                $has_specs = false;
                foreach ($custom as $ck => $cv): 
                    if (empty($cv) || pf_order_ui_should_skip_spec_key((string)$ck) || stripos((string)$ck, 'description') !== false) continue;
                    $has_specs = true;
                    $label = $field_map[$ck] ?? ucwords(str_replace(['_', '-'], ' ', (string)$ck));
                    $display_val = ($ck === 'tshirt_provider' && $cv === 'shop') ? 'Shop will provide' : (($ck === 'tshirt_provider' && $cv === 'customer') ? 'Customer will provide' : (($ck === 'installation_fee' && is_numeric($cv)) ? format_currency((float)$cv) : pf_order_ui_value_to_text($cv)));
                ?>
                    <div class="review-spec-tile order-item-spec-tile" style="background: rgba(255, 255, 255, 0.04); border: 1px solid rgba(83, 197, 224, 0.18); padding: 0.75rem 0.85rem; border-radius: 10px; transition: border-color 0.2s; display: flex; flex-direction: column; min-width: 0; height: auto;">
                        <div class="pf-spec-label" style="font-size: 0.65rem; color: #9fc4d4; font-weight: 700; text-transform: uppercase; margin-bottom: 4px; letter-spacing: 0.02em; line-height: 1.2;"><?php echo pf_order_ui_escape($label); ?></div>
                        <div class="pf-spec-value" style="font-size: 0.95rem; font-weight: 700; color: #eaf6fb; overflow-wrap: break-word; word-break: break-word; line-height: 1.3;"><?php echo pf_order_ui_escape($display_val); ?></div>
                    </div>
                <?php endforeach; ?>

                <?php if ($upload_url): ?>
                    <div class="review-spec-tile order-item-spec-tile order-item-upload-design" style="grid-column: 1 / -1; background: rgba(255, 255, 255, 0.04); border: 1px solid rgba(83, 197, 224, 0.18); padding: 0.85rem; border-radius: 10px;">
                        <div class="pf-spec-label" style="font-size: 0.65rem; color: #9fc4d4; font-weight: 700; text-transform: uppercase; margin-bottom: 8px; letter-spacing: 0.02em;">Uploaded Design</div>
                        <img src="<?php echo htmlspecialchars($upload_url); ?>" alt="Uploaded design preview" class="review-order-item" style="width: 100%; max-width: 320px; max-height: 280px; object-fit: contain; border-radius: 8px; border: 1px solid rgba(83, 197, 224, 0.22); background: rgba(0,0,0,0.25); display: block; cursor: zoom-in;">
                    </div>
                    <?php $has_specs = true; ?>
                <?php endif; ?>
                
                <?php if (!$has_specs): ?>
                    <p style="font-size: 0.9rem; color: #9fc4d4; font-style: italic; white-space: nowrap;">No specific customizations.</p>
                <?php endif; ?>
            </div>

            <!-- Notes -->
            <?php if ($notesCombined !== ''): ?>
                <div style="margin-top: 1.5rem; padding: 1.25rem; background: rgba(83, 197, 224, 0.08); border: 1px solid rgba(83, 197, 224, 0.22); border-left: 4px solid #53c5e0; border-radius: 12px;">
                    <div style="font-size: 0.75rem; font-weight: 800; color: #53c5e0; text-transform: uppercase; margin-bottom: 8px; display: flex; align-items: center; gap: 8px;">
                        Special Instructions & Notes
                    </div>
                    <div style="font-size: 0.95rem; color: #eaf6fb; line-height: 1.6; font-weight: 600; overflow-wrap: break-word; word-break: break-word; white-space: pre-wrap; transition: color 0.2s;"><?php echo nl2br(pf_order_ui_escape($notesCombined)); ?></div>
                </div>
            <?php endif; ?>
        </div>

        <!-- Reference -->
        <?php if ($ref_url): ?>
            <div style="padding: 1.25rem; border-top: 1px solid rgba(83, 197, 224, 0.15); background: rgba(0,0,0,0.12);">
                <div style="font-size: 0.85rem; font-weight: 800; color: #eaf6fb; margin-bottom: 1rem; display: flex; align-items: center; gap: 8px;">
                    Reference Attachment
                </div>
                <div style="width: 140px; border-radius: 10px; overflow: hidden; border: 1px solid rgba(83, 197, 224, 0.24); padding: 5px; background: rgba(0,0,0,0.2);">
                    <img src="<?php echo htmlspecialchars($ref_url); ?>" style="width: 100%; height: auto; display: block; border-radius: 6px;">
                </div>
            </div>
        <?php endif; ?>
    </div>
    <?php
}

