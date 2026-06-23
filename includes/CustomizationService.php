<?php
/**
 * CustomizationService (V2)
 * --------------------------------------------------------------------------
 * Business + presentation-model layer for the Staff Customizations V2 module.
 *
 * The golden rule of this module:
 *   "Display EXACTLY what the customer submitted."
 *
 * To guarantee that, this service reuses the SAME canonical helpers that the
 * customer review page (customer/order_review.php -> render_order_item_clean)
 * uses to decode and normalise customization payloads:
 *
 *   - printflow_decode_modal_customization_payload()  (JSON decode + normalise)
 *   - pf_order_ui_normalize_review_customization()     (de-dupe needed date etc.)
 *   - printflow_resolve_order_item_name()              (real service/product name)
 *   - pf_order_ui_resolve_special_instructions_text()  (notes)
 *
 * Specifications are rendered with a UNIVERSAL parser: we decode the JSON and
 * loop EVERY key/value pair dynamically. Nothing is hard-coded or whitelisted,
 * so every current and future service works automatically.
 */

require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/order_ui_helper.php';
require_once __DIR__ . '/CustomizationRepository.php';

class CustomizationService
{
    private CustomizationRepository $repo;

    /**
     * Friendly labels for known internal keys. This is ONLY cosmetic — any key
     * not present here is still rendered (humanised from snake_case). Mirrors
     * the map used by render_order_item_clean() so labels match the customer view.
     *
     * @var array<string,string>
     */
    private const FIELD_LABELS = [
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
        'layout' => 'Layout',
        'paper_type' => 'Paper Type',
        'print_type' => 'Print Type',
        'laminate' => 'Laminate',
        'dimensions' => 'Dimensions',
        'orientation' => 'Orientation',
        'binding' => 'Binding',
        'copies' => 'Copies',
        'material' => 'Material',
        'material_type' => 'Material',
    ];

    /**
     * Keys that must NOT be rendered as a specification tile (handled elsewhere
     * or purely internal). Mirrors render_order_item_clean()'s skip list plus
     * the internal bookkeeping keys produced by the cart/checkout pipeline.
     *
     * @var array<int,string>
     */
    private const SKIP_KEYS = [
        'design_upload', 'reference_upload', 'notes', 'additional_notes',
        'other_instructions', 'design_notes', 'Branch_ID', 'service_type',
        'product_type', 'unit', 'install_province', 'install_city',
        'install_barangay', 'install_street',
        // internal / non-spec keys
        'service_id', 'source', 'source_page', '_uploaded_files', '_cart_key',
        'design_upload_data', 'design_upload_name', 'design_upload_mime',
        'design_upload_path', 'reference_upload_data', 'reference_upload_name',
        'reference_upload_mime', 'reference_upload_path', 'design_data',
        'reference_data', 'layout_file', 'design_file', 'reference_file',
        'upload_design_data', 'upload_reference_data', 'upload_design_path',
        'upload_reference_path',
    ];

    public function __construct(?CustomizationRepository $repo = null)
    {
        $this->repo = $repo ?? new CustomizationRepository();
    }

    public function repository(): CustomizationRepository
    {
        return $this->repo;
    }

    /**
     * Map any raw order/customization status string into one of the staff
     * workflow buckets used by the V2 list tabs & KPI cards.
     *
     * Buckets: INQUIRY | PAYMENT | PRODUCTION | TO_PICKUP | COMPLETED | CANCELLED
     */
    public static function statusBucket(string $status): string
    {
        $s = strtoupper(trim(preg_replace('/[\s\-\/]+/', '_', $status)));

        $map = [
            'CANCELLED' => 'CANCELLED', 'REJECTED' => 'CANCELLED',
            'COMPLETED' => 'COMPLETED', 'DONE' => 'COMPLETED',
            'READY_FOR_PICKUP' => 'TO_PICKUP', 'TO_RECEIVE' => 'TO_PICKUP',
            'READY_TO_COLLECT' => 'TO_PICKUP', 'TO_PICKUP' => 'TO_PICKUP',
            'PROCESSING' => 'PRODUCTION', 'IN_PRODUCTION' => 'PRODUCTION', 'PRINTING' => 'PRODUCTION',
            'TO_PAY' => 'PAYMENT', 'PENDING_VERIFICATION' => 'PAYMENT',
            'VERIFY_PAY' => 'PAYMENT', 'DOWNPAYMENT_SUBMITTED' => 'PAYMENT', 'PAYMENT' => 'PAYMENT',
        ];

        // Everything else (Pending, Pending Review, Approved, For Revision,
        // Draft, Inquiry, …) is part of the Inquiry & Design stage.
        return $map[$s] ?? 'INQUIRY';
    }

    /**
     * Human label for a bucket key.
     */
    public static function bucketLabel(string $bucket): string
    {
        return [
            'INQUIRY'    => 'Inquiry & Design',
            'PAYMENT'    => 'Payment',
            'PRODUCTION' => 'Production',
            'TO_PICKUP'  => 'To Pickup',
            'COMPLETED'  => 'Completed',
            'CANCELLED'  => 'Cancelled',
        ][$bucket] ?? $bucket;
    }

    private function baseUrl(): string
    {
        if (defined('BASE_URL')) {
            return rtrim((string)BASE_URL, '/');
        }
        if (function_exists('pf_app_base_path')) {
            return rtrim((string)pf_app_base_path(), '/');
        }
        return '';
    }

    // ----------------------------------------------------------------------
    // LIST
    // ----------------------------------------------------------------------

    /**
     * Build lightweight list rows for the staff grid.
     *
     * @return array<int,array<string,mixed>>
     */
    public function listOrderSummaries(?int $branchId = null, ?string $sourceFilter = null, int $limit = 200): array
    {
        $orders = $this->repo->listOrders($branchId, $sourceFilter, $limit);
        $out = [];

        foreach ($orders as $order) {
            $orderId = (int)($order['order_id'] ?? 0);
            if ($orderId <= 0) {
                continue;
            }

            $items = $this->repo->getOrderItems($orderId);
            if (empty($items)) {
                continue;
            }

            // Resolve a representative (first) item view for the card.
            $firstView = $this->buildItemView($items[0], $order);
            $itemCount = count($items);

            $customerName = trim((string)($order['first_name'] ?? '') . ' ' . (string)($order['last_name'] ?? ''));
            if ($customerName === '') {
                $customerName = 'Customer';
            }

            $status = (string)($order['status'] ?? '');
            $orderCode = function_exists('printflow_get_order_inventory_reference')
                ? (string)(printflow_get_order_inventory_reference($orderId)['code'] ?? '')
                : '';
            if ($orderCode === '') {
                $orderCode = 'ORD-' . $orderId;
            }

            $out[] = [
                'order_id'       => $orderId,
                'order_code'     => $orderCode,
                'title'          => $firstView['name'],
                'service_name'   => $firstView['name'],
                'category'       => $firstView['category'],
                'item_count'     => $itemCount,
                'extra_items'    => max(0, $itemCount - 1),
                'customer_name'  => $customerName,
                'customer_contact' => (string)($order['customer_contact'] ?? ''),
                'customer_type'  => (string)($order['customer_type'] ?? ''),
                'branch_name'    => (string)($order['branch_name'] ?? ''),
                'order_date'     => (string)($order['order_date'] ?? ''),
                'status'         => $status,
                'status_bucket'  => self::statusBucket($status),
                'payment_status' => (string)($order['payment_status'] ?? ''),
                'is_pos'         => $this->repo->rowIsPos($order),
                'source_label'   => $this->repo->rowIsPos($order) ? 'POS / Walk-in' : 'Online',
                'thumb_url'      => $firstView['design_url'] ?: $firstView['product_image_url'],
                'quantity'       => $firstView['quantity'],
            ];
        }

        return $out;
    }

    // ----------------------------------------------------------------------
    // DETAIL
    // ----------------------------------------------------------------------

    /**
     * Full structured detail for one order: customer, order meta, and every
     * item with its complete (universally parsed) specification set.
     *
     * @return array<string,mixed>|null
     */
    public function getOrderDetail(int $orderId): ?array
    {
        $order = $this->repo->getOrder($orderId);
        if ($order === null) {
            return null;
        }

        $items = $this->repo->getOrderItems($orderId);
        $itemViews = [];
        foreach ($items as $item) {
            $itemViews[] = $this->buildItemView($item, $order);
        }

        // Fallback: if there are genuinely no order_items (legacy), surface
        // whatever the customizations table holds so nothing is lost.
        if (empty($itemViews)) {
            foreach ($this->repo->getCustomizations($orderId) as $cust) {
                $pseudoItem = [
                    'order_item_id'     => (int)($cust['order_item_id'] ?? 0),
                    'order_id'          => $orderId,
                    'product_id'        => 0,
                    'quantity'          => 1,
                    'unit_price'        => 0,
                    'customization_data' => (string)($cust['customization_details'] ?? ''),
                    'design_image_bytes' => 0,
                ];
                if (!empty($cust['service_type'])) {
                    $decoded = customer_orders_decode_customization_payload((string)($cust['customization_details'] ?? ''));
                    if (empty($decoded['service_type'])) {
                        $pseudoItem['customization_data'] = json_encode(array_merge(
                            is_array($decoded) ? $decoded : [],
                            ['service_type' => $cust['service_type']]
                        ));
                    }
                }
                $itemViews[] = $this->buildItemView($pseudoItem, $order);
            }
        }

        $address = $this->composeAddress($order);
        $customerName = trim((string)($order['first_name'] ?? '') . ' ' . (string)($order['last_name'] ?? ''));
        if ($customerName === '') {
            $customerName = 'Customer';
        }

        // A "needed date" pulled from the items so it can headline the order.
        $neededDate = '';
        foreach ($itemViews as $view) {
            if ($view['needed_date'] !== '') {
                $neededDate = $view['needed_date'];
                break;
            }
        }

        return [
            'order_id'       => $orderId,
            'status'         => (string)($order['status'] ?? ''),
            'design_status'  => (string)($order['design_status'] ?? ''),
            'payment_status' => (string)($order['payment_status'] ?? ''),
            'order_date'     => (string)($order['order_date'] ?? ''),
            'total_amount'   => (float)($order['total_amount'] ?? 0),
            'estimated_price' => isset($order['estimated_price']) ? (float)$order['estimated_price'] : 0.0,
            'order_notes'    => trim((string)($order['notes'] ?? '')),
            'revision_reason' => trim((string)($order['revision_reason'] ?? $order['rejection_reason'] ?? '')),
            'is_pos'         => $this->repo->rowIsPos($order),
            'source_label'   => $this->repo->rowIsPos($order) ? 'POS / Walk-in' : 'Online',
            'needed_date'    => $neededDate,
            'customer' => [
                'name'    => $customerName,
                'email'   => (string)($order['customer_email'] ?? ''),
                'contact' => (string)($order['customer_contact'] ?? ''),
                'type'    => (string)($order['customer_type'] ?? ''),
                'address' => $address,
                'avatar'  => $this->resolveAvatar((string)($order['customer_profile_picture'] ?? '')),
            ],
            'branch' => [
                'id'   => (int)($order['branch_id'] ?? 0),
                'name' => (string)($order['branch_name'] ?? ''),
            ],
            'items' => $itemViews,
        ];
    }

    /**
     * THE UNIVERSAL PARSER + RESOLVER for a single order item.
     *
     * @param array<string,mixed> $item  raw order_items row
     * @param array<string,mixed> $order raw order row (context)
     * @return array<string,mixed>
     */
    public function buildItemView(array $item, array $order): array
    {
        // --- 1. Decode payload exactly like the customer review page does. ----
        $rawDecoded = customer_orders_decode_customization_payload($item['customization_data'] ?? '');
        $custom = printflow_decode_modal_customization_payload($item['customization_data'] ?? '');
        $custom = pf_order_ui_normalize_review_customization($custom, $item, false);

        // --- 2. Resolve whether this is a service or a fixed product. --------
        $serviceId = (int)($custom['service_id'] ?? $rawDecoded['service_id'] ?? 0);
        $hasServiceType = trim((string)($custom['service_type'] ?? '')) !== '';
        $isService = $serviceId > 0 || $hasServiceType;

        $product = $this->repo->getProductById((int)($item['product_id'] ?? 0));
        $productName = trim((string)($product['name'] ?? ''));
        $productCategory = trim((string)($product['category'] ?? ''));

        // --- 3. Resolve the REAL title (never "Order Item" if anything else). -
        $name = $this->resolveItemName($custom, $serviceId, $isService, $productName);

        // --- 4. Resolve category. -------------------------------------------
        $category = $this->resolveCategory($custom, $serviceId, $isService, $productCategory);

        // --- 5. UNIVERSAL spec extraction: loop EVERY key/value dynamically. -
        $specs = $this->extractSpecifications($custom);

        // --- 6. Notes (long-form), via the same helper the customer sees. ----
        $notes = pf_order_ui_resolve_special_instructions_text($custom, $item);

        // --- 7. Needed date convenience field. ------------------------------
        $neededDate = '';
        foreach ($custom as $k => $v) {
            $nk = strtolower(preg_replace('/[^a-z0-9]/', '', (string)$k));
            if (in_array($nk, ['neededdate', 'dateneeded', 'orderneededdate'], true) && trim((string)$v) !== '') {
                $neededDate = trim(pf_order_ui_value_to_text($v));
                break;
            }
        }

        // --- 8. Image resolution (design / reference / product). ------------
        $orderItemId = (int)($item['order_item_id'] ?? 0);
        $images = $this->resolveImages($item, $rawDecoded, $order, $isService, $product, $name);

        return [
            'order_item_id'     => $orderItemId,
            'name'              => $name,
            'category'          => $category,
            'is_service'        => $isService,
            'quantity'          => max(1, (int)($item['quantity'] ?? 1)),
            'unit_price'        => (float)($item['unit_price'] ?? 0),
            'specs'             => $specs,
            'notes'             => $notes,
            'needed_date'       => $neededDate,
            'design_url'        => $images['design_url'],
            'reference_url'     => $images['reference_url'],
            'product_image_url' => $images['product_image_url'],
            'has_design'        => $images['has_design'],
            'has_reference'     => $images['has_reference'],
        ];
    }

    // ----------------------------------------------------------------------
    // Resolution helpers
    // ----------------------------------------------------------------------

    /**
     * @param array<string,mixed> $custom
     */
    private function resolveItemName(array $custom, int $serviceId, bool $isService, string $productName): string
    {
        // Most reliable: services table by id.
        if ($serviceId > 0) {
            $svc = $this->repo->getServiceById($serviceId);
            $svcName = trim((string)($svc['name'] ?? ''));
            if ($svcName !== '') {
                return $svcName;
            }
        }

        if ($isService) {
            // Use the canonical resolver seeded with the stored service_type.
            $raw = trim((string)($custom['service_type'] ?? ''));
            $resolved = printflow_resolve_order_item_name($raw !== '' ? $raw : $productName, $custom, 'Service');
            $resolved = trim((string)$resolved);
            if ($resolved !== '' && !customer_orders_is_generic_item_name($resolved)) {
                return $resolved;
            }
            if ($raw !== '' && !customer_orders_is_generic_item_name($raw)) {
                return $raw;
            }
        }

        // Fixed product: prefer the real catalog name.
        if ($productName !== '' && !customer_orders_is_generic_item_name($productName)) {
            return $productName;
        }

        // Last-chance canonical resolution from the payload itself.
        $resolved = trim((string)printflow_resolve_order_item_name($productName, $custom, 'Order Item'));
        if ($resolved !== '' && !customer_orders_is_generic_item_name($resolved)) {
            return $resolved;
        }

        // Absolute fallback (requirement: only when nothing else exists).
        return 'Order Item';
    }

    /**
     * @param array<string,mixed> $custom
     */
    private function resolveCategory(array $custom, int $serviceId, bool $isService, string $productCategory): string
    {
        if ($serviceId > 0) {
            $svc = $this->repo->getServiceById($serviceId);
            $cat = trim((string)($svc['category'] ?? ''));
            if ($cat !== '') {
                return $cat;
            }
        }
        if ($productCategory !== '') {
            return $productCategory;
        }
        return $isService ? 'Service' : 'Product';
    }

    /**
     * UNIVERSAL PARSER.
     * Decode is already done; here we loop EVERY remaining key/value pair and
     * turn it into a {label, value} tile. No whitelist — only the structural
     * skip list (notes/uploads/internal bookkeeping) is excluded.
     *
     * @param array<string,mixed> $custom
     * @return array<int,array{label:string,value:string}>
     */
    private function extractSpecifications(array $custom): array
    {
        $specs = [];
        foreach ($custom as $key => $value) {
            if (!is_string($key) || $key === '') {
                continue;
            }
            if ($value === '' || $value === null) {
                continue;
            }
            if (in_array($key, self::SKIP_KEYS, true)) {
                continue;
            }
            // *description* fields are folded into Notes (matches customer view).
            if (stripos($key, 'description') !== false) {
                continue;
            }

            $text = trim(pf_order_ui_value_to_text($value));
            if ($text === '') {
                continue;
            }

            $label = self::FIELD_LABELS[$key] ?? ucwords(str_replace(['_', '-'], ' ', $key));

            // Cosmetic value transforms identical to render_order_item_clean().
            if ($key === 'tshirt_provider' && $value === 'shop') {
                $text = 'Shop will provide';
            } elseif ($key === 'tshirt_provider' && $value === 'customer') {
                $text = 'Customer will provide';
            } elseif ($key === 'installation_fee' && is_numeric($value)) {
                $text = function_exists('format_currency') ? format_currency((float)$value) : (string)$value;
            }

            $specs[] = ['label' => $label, 'value' => $text];
        }

        return $specs;
    }

    /**
     * Resolve design / reference / product image URLs.
     *
     * Design + reference are served through the existing, battle-tested
     * serve_design.php endpoint which already falls back across BLOB, file,
     * customization payload (data URLs / paths) and job_orders artwork.
     *
     * @param array<string,mixed> $item
     * @param array<string,mixed> $rawDecoded
     * @param array<string,mixed> $order
     * @param array<string,mixed>|null $product
     * @return array{design_url:?string,reference_url:?string,product_image_url:?string,has_design:bool,has_reference:bool}
     */
    private function resolveImages(array $item, array $rawDecoded, array $order, bool $isService, ?array $product, string $name): array
    {
        $base = $this->baseUrl();
        $orderItemId = (int)($item['order_item_id'] ?? 0);
        $orderId = (int)($item['order_id'] ?? $order['order_id'] ?? 0);

        // ---- Detect a real design exists (so we never show empty boxes). ----
        $hasDesign = (int)($item['design_image_bytes'] ?? 0) > 0
            || trim((string)($item['design_file'] ?? '')) !== ''
            || trim((string)($item['design_image_name'] ?? '')) !== ''
            || $this->payloadHasMedia($rawDecoded, 'design');

        if (!$hasDesign && $orderId > 0) {
            // POS / legacy: design may live in customizations payload or job artwork.
            if (!empty($this->repo->getJobArtworkPaths($orderId))) {
                $hasDesign = true;
            } else {
                foreach ($this->repo->getCustomizations($orderId) as $cust) {
                    $payload = customer_orders_decode_customization_payload((string)($cust['customization_details'] ?? ''));
                    if ($this->payloadHasMedia($payload, 'design')) {
                        $hasDesign = true;
                        break;
                    }
                }
            }
        }

        // ---- Detect a reference image. -------------------------------------
        $hasReference = trim((string)($item['reference_image_file'] ?? '')) !== ''
            || $this->payloadHasMedia($rawDecoded, 'reference');

        if (!$hasReference && $orderId > 0) {
            foreach ($this->repo->getCustomizations($orderId) as $cust) {
                $payload = customer_orders_decode_customization_payload((string)($cust['customization_details'] ?? ''));
                if ($this->payloadHasMedia($payload, 'reference')) {
                    $hasReference = true;
                    break;
                }
            }
        }

        $designUrl = ($hasDesign && $orderItemId > 0)
            ? $base . '/public/serve_design.php?type=order_item&id=' . $orderItemId
            : null;

        $referenceUrl = ($hasReference && $orderItemId > 0)
            ? $base . '/public/serve_design.php?type=order_item&id=' . $orderItemId . '&field=reference'
            : null;

        // ---- Product / service catalog image. ------------------------------
        $productImageUrl = null;
        if (!$isService && $product !== null) {
            $img = pf_order_ui_asset_url((string)($product['photo_path'] ?? ''))
                ?: pf_order_ui_asset_url((string)($product['product_image'] ?? ''));
            $productImageUrl = $img ?: null;
        } else {
            // Service: prefer catalog image, fall back to service-type image map.
            $serviceId = (int)($rawDecoded['service_id'] ?? 0);
            if ($serviceId > 0) {
                $svc = $this->repo->getServiceById($serviceId);
                $display = trim((string)($svc['display_image'] ?? ''));
                $hero = trim((string)($svc['hero_image'] ?? ''));
                $first = $display !== '' ? trim(explode(',', $display)[0]) : $hero;
                $productImageUrl = pf_order_ui_asset_url($first) ?: null;
            }
            if ($productImageUrl === null && function_exists('get_service_image_url')) {
                $productImageUrl = get_service_image_url($name) ?: null;
            }
        }

        return [
            'design_url'        => $designUrl,
            'reference_url'     => $referenceUrl,
            'product_image_url' => $productImageUrl,
            'has_design'        => $hasDesign,
            'has_reference'     => $hasReference,
        ];
    }

    /**
     * Detect whether a decoded payload carries inline/file media for a field.
     *
     * @param array<string,mixed> $payload
     */
    private function payloadHasMedia(array $payload, string $field): bool
    {
        $keys = $field === 'reference'
            ? ['reference_upload', 'reference_upload_data', 'reference_upload_path', 'reference_file', 'reference_data', 'upload_reference_data', 'upload_reference_path']
            : ['design_upload', 'design_upload_data', 'design_upload_path', 'design_file', 'design_data', 'upload_design_data', 'upload_design_path', 'layout_file'];

        foreach ($keys as $k) {
            if (isset($payload[$k]) && is_scalar($payload[$k]) && trim((string)$payload[$k]) !== '') {
                return true;
            }
        }
        return false;
    }

    /**
     * @param array<string,mixed> $order
     */
    private function composeAddress(array $order): string
    {
        $parts = [
            trim((string)($order['customer_address'] ?? '')),
            trim((string)($order['customer_street'] ?? '')),
            trim((string)($order['customer_barangay'] ?? '')),
            trim((string)($order['customer_city'] ?? '')),
            trim((string)($order['customer_province'] ?? '')),
        ];
        $parts = array_values(array_filter($parts, static fn($p) => $p !== ''));
        return !empty($parts) ? implode(', ', $parts) : '';
    }

    private function resolveAvatar(string $path): string
    {
        $path = trim($path);
        if ($path === '') {
            return '';
        }
        return (string)(pf_order_ui_asset_url($path) ?? '');
    }

    // ----------------------------------------------------------------------
    // ACTIONS (Approve / Request Revision / Close)
    // ----------------------------------------------------------------------

    /**
     * Approve the customization order (and sync linked job + chat).
     *
     * @return array{success:bool,message:string}
     */
    public function approve(int $orderId): array
    {
        $order = $this->repo->getOrder($orderId);
        if ($order === null) {
            return ['success' => false, 'message' => 'Order not found.'];
        }

        $this->repo->updateCustomizationStatus($orderId, 'Approved');
        $this->repo->updateOrderStatus($orderId, 'Approved');
        $this->syncJobs($orderId, 'APPROVED');
        $this->sendChat($orderId, 'approved');

        return ['success' => true, 'message' => 'Order approved.'];
    }

    /**
     * Request a revision from the customer.
     *
     * @return array{success:bool,message:string}
     */
    public function requestRevision(int $orderId, string $reason): array
    {
        $reason = trim($reason);
        if ($reason === '') {
            return ['success' => false, 'message' => 'A revision reason is required.'];
        }

        $order = $this->repo->getOrder($orderId);
        if ($order === null) {
            return ['success' => false, 'message' => 'Order not found.'];
        }

        $this->repo->updateCustomizationStatus($orderId, 'For Revision', $reason);
        $this->repo->updateOrderStatus($orderId, 'For Revision', 'Revision Requested', $reason);
        $this->sendChat($orderId, 'for_revision', ['reason' => $reason]);

        return ['success' => true, 'message' => 'Revision requested.'];
    }

    /**
     * Close (complete) the customization order.
     *
     * @return array{success:bool,message:string}
     */
    public function close(int $orderId): array
    {
        $order = $this->repo->getOrder($orderId);
        if ($order === null) {
            return ['success' => false, 'message' => 'Order not found.'];
        }

        $this->repo->updateCustomizationStatus($orderId, 'Completed');
        $this->repo->updateOrderStatus($orderId, 'Completed');
        $this->syncJobs($orderId, 'COMPLETED');
        $this->sendChat($orderId, 'completed');

        return ['success' => true, 'message' => 'Order closed.'];
    }

    private function syncJobs(int $orderId, string $jobStatus): void
    {
        if (!class_exists('JobOrderService')) {
            require_once __DIR__ . '/JobOrderService.php';
        }
        if (!class_exists('JobOrderService')) {
            return;
        }

        try {
            JobOrderService::ensureJobsForStoreOrder($orderId);
        } catch (Throwable $e) {
            error_log('CustomizationService::syncJobs ensure failed for #' . $orderId . ': ' . $e->getMessage());
        }

        foreach ($this->repo->getActiveJobIds($orderId) as $jobId) {
            try {
                JobOrderService::updateStatus($jobId, $jobStatus);
            } catch (Throwable $e) {
                error_log('CustomizationService::syncJobs status failed for job #' . $jobId . ': ' . $e->getMessage());
            }
        }
    }

    /**
     * @param array<string,mixed> $meta
     */
    private function sendChat(int $orderId, string $step, array $meta = []): void
    {
        if (!function_exists('printflow_send_order_update')) {
            $chatHelper = __DIR__ . '/order_chat_system.php';
            if (is_file($chatHelper)) {
                require_once $chatHelper;
            }
        }
        if (!function_exists('printflow_send_order_update')) {
            return;
        }
        try {
            printflow_send_order_update($orderId, $step, 'view_status', '', '', $meta);
        } catch (Throwable $e) {
            error_log('CustomizationService::sendChat failed for #' . $orderId . ': ' . $e->getMessage());
        }
    }
}
