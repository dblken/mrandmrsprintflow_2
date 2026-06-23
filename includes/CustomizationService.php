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

    /** @var array<int,array<string,mixed>|null> */
    private static array $storePayloadCache = [];

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

            $items = $this->resolveRawItems($orderId);
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

    /**
     * Resolve "raw" item rows for an order through a robust fallback chain so a
     * customization is NEVER lost regardless of how it was persisted:
     *   1. real order_items rows (the normal path)
     *   2. customizations table rows (online/POS service inquiries)
     *   3. job_orders payload (orders that only created an order + job_order,
     *      with no order_items/customizations at all)
     *
     * @return array<int,array<string,mixed>>
     */
    private function resolveRawItems(int $orderId): array
    {
        $items = $this->repo->getOrderItems($orderId);
        if ($this->itemsHaveMeaningfulCustomization($items)) {
            return $items;
        }

        $items = $this->pseudoItemsFromCustomizations($orderId);
        if (!empty($items)) {
            return $items;
        }

        return $this->pseudoItemsFromJobPayload($orderId);
    }

    /**
     * True when at least one order_items row carries real customization JSON.
     *
     * @param array<int,array<string,mixed>> $items
     */
    private function itemsHaveMeaningfulCustomization(array $items): bool
    {
        if ($items === []) {
            return false;
        }

        foreach ($items as $item) {
            $raw = trim((string)($item['customization_data'] ?? ''));
            $specRaw = trim((string)($item['specifications'] ?? ''));
            foreach ([$raw, $specRaw] as $candidate) {
                if ($candidate === '' || in_array($candidate, ['[]', '{}', 'null'], true)) {
                    continue;
                }
                $decoded = customer_orders_decode_customization_payload($candidate);
                if ($decoded !== []) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Build pseudo order_item rows from the existing, battle-tested store-order
     * payload builder (the SAME engine the old staff page and the customer
     * modal use). This guarantees V2 shows exactly what the old page shows for
     * orders that only have a job_orders row (no order_items/customizations).
     *
     * @return array<int,array<string,mixed>>
     */
    private function pseudoItemsFromJobPayload(int $orderId): array
    {
        if (!class_exists('JobOrderService')) {
            $path = __DIR__ . '/JobOrderService.php';
            if (is_file($path)) {
                require_once $path;
            }
        }
        if (!class_exists('JobOrderService') || !method_exists('JobOrderService', 'getStoreOrderItemsPayload')) {
            return [];
        }

        try {
            // detailMode=true synthesizes line(s) from order + job_orders even
            // when order_items is empty (mirrors the old customizations page).
            $payload = JobOrderService::getStoreOrderItemsPayload($orderId, false, true);
        } catch (\Throwable $e) {
            error_log('CustomizationService::pseudoItemsFromJobPayload failed: ' . $e->getMessage());
            return [];
        }

        $payloadItems = is_array($payload['items'] ?? null) ? $payload['items'] : [];
        $rootCustom = is_array($payload['customization_details'] ?? null) ? $payload['customization_details'] : [];
        $payloadServiceType = trim((string)($payload['service_type'] ?? ''));
        $out = [];
        foreach ($payloadItems as $it) {
            $custom = is_array($it['customization'] ?? null) ? $it['customization'] : [];
            if ($custom === [] && is_array($it['specifications'] ?? null)) {
                $custom = $it['specifications'];
            }
            if ($rootCustom !== []) {
                $custom = function_exists('printflow_overlay_nonempty_assoc')
                    ? printflow_overlay_nonempty_assoc($rootCustom, $custom)
                    : array_merge($rootCustom, $custom);
            }

            $productName = trim((string)($it['product_name'] ?? ''));
            if ($productName !== '' && $this->isGenericServiceLabel($productName)) {
                $productName = '';
            }

            $designOpenUrl = trim((string)($it['design_open_url'] ?? ($it['design_url'] ?? '')));
            $referenceOpenUrl = trim((string)($it['reference_open_url'] ?? ($it['reference_url'] ?? '')));

            $out[] = [
                'order_item_id'           => (int)($it['order_item_id'] ?? 0),
                'order_id'                => $orderId,
                'product_id'              => (int)($it['product_id'] ?? 0),
                'quantity'                => max(1, (int)($it['quantity'] ?? 1)),
                'unit_price'              => (float)($it['unit_price'] ?? 0),
                'customization_data'      => json_encode($custom),
                'design_image_bytes'      => 0,
                'pf_from_job_payload'     => true,
                'pf_product_name'         => $productName,
                'pf_payload_service_type' => $payloadServiceType,
                'pf_category'             => trim((string)($it['category'] ?? '')),
                'pf_customization'        => $custom,
                'pf_design_open_url'      => $designOpenUrl !== '' ? $designOpenUrl : null,
                'pf_reference_open_url'   => $referenceOpenUrl !== '' ? $referenceOpenUrl : null,
                'pf_design_name'          => trim((string)($it['design_name'] ?? ($it['design_image_name'] ?? ''))),
            ];
        }

        return $out;
    }

    /**
     * Build pseudo order_item rows from the customizations table for orders
     * that have no real order_items (legacy / online service inquiries).
     *
     * @return array<int,array<string,mixed>>
     */
    private function pseudoItemsFromCustomizations(int $orderId): array
    {
        $pseudo = [];
        foreach ($this->repo->getCustomizations($orderId) as $cust) {
            $payload = (string)($cust['customization_details'] ?? '');
            if (!empty($cust['service_type'])) {
                $decoded = customer_orders_decode_customization_payload($payload);
                if (empty($decoded['service_type'])) {
                    $payload = json_encode(array_merge(
                        is_array($decoded) ? $decoded : [],
                        ['service_type' => $cust['service_type']]
                    ));
                }
            }

            $pseudo[] = [
                'order_item_id'      => (int)($cust['order_item_id'] ?? 0),
                'order_id'           => $orderId,
                'product_id'         => 0,
                'quantity'           => 1,
                'unit_price'         => 0,
                'customization_data' => $payload,
                'design_image_bytes' => 0,
            ];
        }

        return $pseudo;
    }

    /**
     * Load (and cache) the canonical store-order payload for an order.
     *
     * @return array<string,mixed>|null
     */
    private function loadStorePayload(int $orderId): ?array
    {
        if ($orderId <= 0) {
            return null;
        }
        if (array_key_exists($orderId, self::$storePayloadCache)) {
            return self::$storePayloadCache[$orderId];
        }

        if (!class_exists('JobOrderService')) {
            $path = __DIR__ . '/JobOrderService.php';
            if (is_file($path)) {
                require_once $path;
            }
        }
        if (!class_exists('JobOrderService') || !method_exists('JobOrderService', 'getStoreOrderItemsPayload')) {
            self::$storePayloadCache[$orderId] = null;
            return null;
        }

        try {
            $payload = JobOrderService::getStoreOrderItemsPayload($orderId, false, true);
            self::$storePayloadCache[$orderId] = is_array($payload) ? $payload : null;
        } catch (\Throwable $e) {
            error_log('CustomizationService::loadStorePayload failed: ' . $e->getMessage());
            self::$storePayloadCache[$orderId] = null;
        }

        return self::$storePayloadCache[$orderId];
    }

    /**
     * Find the matching payload line for a raw order_items row.
     *
     * @param array<string,mixed> $item
     * @return array<string,mixed>|null
     */
    private function resolveStorePayloadLine(int $orderId, array $item): ?array
    {
        $payload = $this->loadStorePayload($orderId);
        if ($payload === null) {
            return null;
        }

        $lines = is_array($payload['items'] ?? null) ? $payload['items'] : [];
        if ($lines === []) {
            return null;
        }

        $targetId = (int)($item['order_item_id'] ?? 0);
        if ($targetId > 0) {
            foreach ($lines as $line) {
                if ((int)($line['order_item_id'] ?? 0) === $targetId) {
                    return $line;
                }
            }
        }

        return $lines[0];
    }

    /**
     * Merge a JobOrderService payload line into a pseudo-item for rendering.
     *
     * @param array<string,mixed> $item
     * @param array<string,mixed> $line
     * @return array<string,mixed>
     */
    private function mergeItemWithPayloadLine(array $item, array $line, int $orderId): array
    {
        $payload = $this->loadStorePayload($orderId) ?? [];
        $rootCustom = is_array($payload['customization_details'] ?? null) ? $payload['customization_details'] : [];

        $custom = is_array($line['customization'] ?? null) ? $line['customization'] : [];
        if ($custom === [] && is_array($line['specifications'] ?? null)) {
            $custom = $line['specifications'];
        }
        if ($rootCustom !== []) {
            $custom = function_exists('printflow_overlay_nonempty_assoc')
                ? printflow_overlay_nonempty_assoc($rootCustom, $custom)
                : array_merge($rootCustom, $custom);
        }

        $productName = trim((string)($line['product_name'] ?? ''));
        if ($productName !== '' && $this->isGenericServiceLabel($productName)) {
            $productName = '';
        }

        $designOpenUrl = trim((string)($line['design_open_url'] ?? ($line['design_url'] ?? '')));
        $referenceOpenUrl = trim((string)($line['reference_open_url'] ?? ($line['reference_url'] ?? '')));

        $referenceFile = trim((string)($item['reference_image_file'] ?? ''));
        if ($referenceFile === '') {
            $referenceFile = trim((string)($line['debug_reference_image_file'] ?? ''));
        }

        return array_merge($item, [
            'order_item_id'           => (int)($line['order_item_id'] ?? $item['order_item_id'] ?? 0),
            'order_id'                => $orderId,
            'quantity'                => max(1, (int)($line['quantity'] ?? $item['quantity'] ?? 1)),
            'unit_price'              => (float)($line['unit_price'] ?? $item['unit_price'] ?? 0),
            'product_name'            => trim((string)($line['product_name'] ?? ($item['product_name'] ?? ''))),
            'pf_from_job_payload'     => true,
            'pf_product_name'         => $productName !== '' ? $productName : trim((string)($line['product_name'] ?? '')),
            'pf_payload_service_type' => trim((string)($payload['service_type'] ?? '')),
            'pf_category'             => trim((string)($line['category'] ?? '')),
            'pf_customization'        => $custom,
            'pf_design_open_url'      => $designOpenUrl !== '' ? $designOpenUrl : null,
            'pf_reference_open_url'   => $referenceOpenUrl !== '' ? $referenceOpenUrl : null,
            'pf_design_name'          => trim((string)($line['design_name'] ?? ($line['design_image_name'] ?? ''))),
            'design_file'             => trim((string)($line['design_file'] ?? ($item['design_file'] ?? ''))),
            'design_image_name'       => trim((string)($item['design_image_name'] ?? ''))
                ?: trim((string)($line['design_image_name'] ?? ($line['design_name'] ?? ''))),
            'design_image_mime'       => trim((string)($item['design_image_mime'] ?? ''))
                ?: trim((string)($line['design_image_mime'] ?? '')),
            'reference_image_file'    => $referenceFile,
            'design_image_bytes'      => max(
                (int)($item['design_image_bytes'] ?? 0),
                (int)($line['pf_design_image_bytes'] ?? 0)
            ),
        ]);
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

        $items = $this->resolveRawItems($orderId);
        if ($items === [] && function_exists('printflow_repair_order_missing_line_items')) {
            printflow_repair_order_missing_line_items($orderId);
            $items = $this->resolveRawItems($orderId);
        }
        $itemViews = [];
        foreach ($items as $item) {
            $itemViews[] = $this->buildItemView($item, $order);
        }
        $this->consolidateItemNotes($order, $itemViews);

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
            'order_notes'    => '',
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
        $orderId = (int)($order['order_id'] ?? $item['order_id'] ?? 0);
        if ($orderId > 0 && empty($order['reference_id'])) {
            $fullOrder = $this->repo->getOrder($orderId);
            if ($fullOrder !== null) {
                $order = array_merge($order, $fullOrder);
            }
        }

        // Primary path: JobOrderService store payload (same engine as customer modal + old staff page).
        $payloadLine = $this->resolveStorePayloadLine($orderId, $item);
        if ($payloadLine !== null) {
            $mergedItem = $this->mergeItemWithPayloadLine($item, $payloadLine, $orderId);
            $payloadCustom = is_array($mergedItem['pf_customization'] ?? null) ? $mergedItem['pf_customization'] : [];
            if ($payloadCustom !== []) {
                return $this->buildItemViewFromStorePayload($mergedItem, $order);
            }
        }

        $custom = $this->resolveCanonicalCustomization($item, $order);

        return $this->assembleItemViewFromCustom($item, $order, $custom);
    }

    /**
     * One notes block per order: promote order-level notes to the first item
     * when line items have none; never duplicate order + item notes in the UI.
     *
     * @param array<string,mixed> $order
     * @param array<int,array<string,mixed>> $itemViews
     */
    private function consolidateItemNotes(array $order, array &$itemViews): void
    {
        if ($itemViews === []) {
            return;
        }

        $orderNotes = trim((string)($order['notes'] ?? ''));
        $hasItemNotes = false;
        foreach ($itemViews as $view) {
            if (trim((string)($view['notes'] ?? '')) !== '') {
                $hasItemNotes = true;
                break;
            }
        }

        if (!$hasItemNotes && $orderNotes !== '') {
            $itemViews[0]['notes'] = $orderNotes;
        }
    }

    /**
     * Merge every known customization source into one array — identical to what
     * the customer order review page ultimately renders.
     *
     * @param array<string,mixed> $item
     * @param array<string,mixed> $order
     * @return array<string,mixed>
     */
    private function resolveCanonicalCustomization(array $item, array $order): array
    {
        $orderId = (int)($order['order_id'] ?? $item['order_id'] ?? 0);
        $custom = $this->decodeRawItemCustomization($item);

        // Store payload (old staff page engine).
        $payloadLine = $this->resolveStorePayloadLine($orderId, $item);
        if ($payloadLine !== null) {
            $payloadCustom = is_array($payloadLine['customization'] ?? null) ? $payloadLine['customization'] : [];
            if ($payloadCustom === [] && is_array($payloadLine['specifications'] ?? null)) {
                $payloadCustom = $payloadLine['specifications'];
            }
            if ($payloadCustom !== []) {
                $custom = function_exists('printflow_overlay_nonempty_assoc')
                    ? printflow_overlay_nonempty_assoc($custom, $payloadCustom)
                    : array_merge($custom, $payloadCustom);
            }
        }

        $payload = $this->loadStorePayload($orderId);
        if (is_array($payload['customization_details'] ?? null) && $payload['customization_details'] !== []) {
            $custom = function_exists('printflow_overlay_nonempty_assoc')
                ? printflow_overlay_nonempty_assoc($custom, $payload['customization_details'])
                : array_merge($custom, $payload['customization_details']);
        }

        // customizations table rows.
        foreach ($this->repo->getCustomizations($orderId) as $custRow) {
            $details = printflow_decode_modal_customization_payload((string)($custRow['customization_details'] ?? ''));
            if ($details !== []) {
                $custom = function_exists('printflow_overlay_nonempty_assoc')
                    ? printflow_overlay_nonempty_assoc($custom, $details)
                    : array_merge($custom, $details);
            }
            if (!empty($custRow['service_type']) && trim((string)($custom['service_type'] ?? '')) === '') {
                $custom['service_type'] = $custRow['service_type'];
            }
        }

        // job_orders row (notes, dimensions, service_type).
        if ($orderId > 0) {
            $jobRows = db_query(
                'SELECT job_title, service_type, width_ft, height_ft, notes, total_sqft, artwork_path
                 FROM job_orders WHERE order_id = ? ORDER BY id ASC LIMIT 1',
                'i',
                [$orderId]
            ) ?: [];
            if (!empty($jobRows[0]) && function_exists('customer_orders_merge_job_order_row_into_customization')) {
                $custom = customer_orders_merge_job_order_row_into_customization($custom, $jobRows[0]);
            }
        }

        // Order-level enrichment (branch name, service catalog, etc.).
        if (function_exists('customer_orders_enrich_line_customization')) {
            $custom = customer_orders_enrich_line_customization($custom, $order);
        }

        $custom = pf_order_ui_normalize_review_customization($custom, $item, false);

        $serviceId = (int)($custom['service_id'] ?? 0);
        if ($serviceId <= 0 && function_exists('printflow_resolve_service_catalog_service_id_for_order_line')) {
            $serviceId = printflow_resolve_service_catalog_service_id_for_order_line($custom, $order, $item);
            if ($serviceId > 0) {
                $custom['service_id'] = $serviceId;
            }
        }
        if ($serviceId <= 0) {
            $serviceId = (int)($order['reference_id'] ?? 0);
        }
        if ($serviceId > 0 && function_exists('printflow_apply_service_field_config_display_labels')) {
            $custom = printflow_apply_service_field_config_display_labels($custom, $serviceId, [
                'branch_name'    => (string)($order['branch_name'] ?? ''),
                'quantity'       => max(1, (int)($item['quantity'] ?? 1)),
                'design_name'    => trim((string)($item['design_image_name'] ?? '')),
                'reference_name' => basename((string)($item['reference_image_file'] ?? '')),
                'dimension_unit' => trim((string)($custom['unit'] ?? '')),
            ]);
        }

        // Flatten to the same human-readable labels the customer modal uses.
        $qty = max(1, (int)($item['quantity'] ?? 1));
        $display = function_exists('printflow_flatten_customization_for_customer_order_modal')
            ? printflow_flatten_customization_for_customer_order_modal($custom, $qty, true)
            : [];
        if ($display === [] && function_exists('printflow_modal_customization_fallback_flatten_for_staff')) {
            $display = printflow_modal_customization_fallback_flatten_for_staff($custom, $qty);
        }
        if ($display === [] && class_exists('JobOrderService') && method_exists('JobOrderService', 'buildStaffCustomizationPayload')) {
            if (!class_exists('JobOrderService')) {
                $path = __DIR__ . '/JobOrderService.php';
                if (is_file($path)) {
                    require_once $path;
                }
            }
            $display = JobOrderService::buildStaffCustomizationPayload($custom, $qty);
        }
        foreach ($display as $label => $value) {
            if ($value !== '' && $value !== null) {
                $custom[(string)$label] = $value;
            }
        }

        return $custom;
    }

    /**
     * @param array<string,mixed> $item
     * @return array<string,mixed>
     */
    private function decodeRawItemCustomization(array $item): array
    {
        $rawDecoded = customer_orders_decode_customization_payload($item['customization_data'] ?? '');
        $savedSpecs = customer_orders_decode_customization_payload($item['specifications'] ?? '');
        if ($savedSpecs !== [] && function_exists('printflow_overlay_nonempty_assoc')) {
            $rawDecoded = printflow_overlay_nonempty_assoc($rawDecoded, $savedSpecs);
        } elseif ($savedSpecs !== []) {
            $rawDecoded = array_merge($rawDecoded, $savedSpecs);
        }

        if ($rawDecoded === []) {
            return [];
        }

        return printflow_decode_modal_customization_payload(json_encode($rawDecoded));
    }

    /**
     * @param array<string,mixed> $item
     * @param array<string,mixed> $order
     * @param array<string,mixed> $custom
     * @return array<string,mixed>
     */
    private function assembleItemViewFromCustom(array $item, array $order, array $custom): array
    {
        $orderId = (int)($order['order_id'] ?? $item['order_id'] ?? 0);
        $serviceId = (int)($custom['service_id'] ?? $order['reference_id'] ?? 0);
        $hasServiceType = trim((string)($custom['service_type'] ?? '')) !== '';
        $isService = $serviceId > 0 || $hasServiceType || strtolower(trim((string)($order['order_type'] ?? ''))) === 'custom';

        $product = $this->repo->getProductById((int)($item['product_id'] ?? 0));
        $productName = trim((string)($product['name'] ?? ''));
        $productCategory = trim((string)($product['category'] ?? ''));

        $name = $this->resolveItemName($custom, $serviceId, $isService, $productName);
        $payloadLine = $this->resolveStorePayloadLine($orderId, $item);
        if ($payloadLine !== null) {
            $payloadName = trim((string)($payloadLine['product_name'] ?? ''));
            if ($payloadName !== '' && !$this->isGenericServiceLabel($payloadName)) {
                $name = $payloadName;
            }
        }
        if ($this->isGenericServiceLabel($name)) {
            $name = $this->resolveStorePayloadItemName($item, $order, $custom);
        }

        $category = $this->resolveCategory($custom, $serviceId, $isService, $productCategory);
        $specs = $this->extractSpecificationsLikeCustomerReview($custom);
        if ($specs === []) {
            $specs = $this->extractSpecificationsFromDisplayCustom($custom);
        }
        if ($specs === []) {
            $specs = $this->extractSpecifications($custom);
        }

        $notes = pf_order_ui_resolve_special_instructions_text($custom, $item);
        if ($notes === '') {
            $notes = $this->resolveStorePayloadNotes($custom, $item, $order);
        }

        $neededDate = $this->extractNeededDateFromCustom($custom);
        $images = $this->resolveStaffDesignImages($item, $order, $custom, $isService);

        return [
            'order_item_id'     => (int)($item['order_item_id'] ?? 0),
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

    /**
     * Spec tiles using the exact skip/render rules from render_order_item_clean().
     *
     * @param array<string,mixed> $custom
     * @return array<int,array{label:string,value:string}>
     */
    private function extractSpecificationsLikeCustomerReview(array $custom): array
    {
        $skip = [
            'design_upload', 'reference_upload', 'notes', 'additional_notes',
            'other_instructions', 'design_notes', 'job_notes', 'Job Notes',
            'Branch_ID', 'service_type', 'product_type', 'unit',
            'install_province', 'install_city', 'install_barangay', 'install_street',
        ];
        $specs = [];

        foreach ($custom as $ck => $cv) {
            if ($cv === '' || $cv === null) {
                continue;
            }
            if (!is_string($ck) && !is_int($ck)) {
                continue;
            }
            $ck = (string)$ck;
            if (in_array($ck, $skip, true) || stripos($ck, 'description') !== false) {
                continue;
            }
            $ckNorm = strtolower(preg_replace('/[^a-z0-9]/', '', $ck));
            if (in_array($ckNorm, ['jobnotes', 'specialinstructions', 'otherinstructions', 'additionalnotes'], true)) {
                continue;
            }

            $text = trim(pf_order_ui_value_to_text($cv));
            if ($text === '') {
                continue;
            }

            if ($ck === 'tshirt_provider' && $cv === 'shop') {
                $text = 'Shop will provide';
            } elseif ($ck === 'tshirt_provider' && $cv === 'customer') {
                $text = 'Customer will provide';
            } elseif ($ck === 'installation_fee' && is_numeric($cv) && function_exists('format_currency')) {
                $text = format_currency((float)$cv);
            }

            $label = self::FIELD_LABELS[$ck] ?? ucwords(str_replace(['_', '-'], ' ', $ck));
            $specs[] = ['label' => $label, 'value' => $text];
        }

        return $specs;
    }

    /**
     * Staff detail images: always prefer the customer's uploaded design, never
     * the catalog/sample service photo.
     *
     * @param array<string,mixed> $item
     * @param array<string,mixed> $order
     * @param array<string,mixed> $custom
     * @return array{design_url:?string,reference_url:?string,product_image_url:?string,has_design:bool,has_reference:bool}
     */
    private function resolveStaffDesignImages(array $item, array $order, array $custom, bool $isService): array
    {
        $orderId = (int)($order['order_id'] ?? $item['order_id'] ?? 0);
        $orderItemId = (int)($item['order_item_id'] ?? 0);
        $base = $this->baseUrl();

        $payloadLine = $this->resolveStorePayloadLine($orderId, $item);
        $designUrl = trim((string)($item['pf_design_open_url'] ?? ''));
        if ($designUrl === '' && $payloadLine !== null) {
            $designUrl = trim((string)($payloadLine['design_open_url'] ?? ($payloadLine['design_url'] ?? '')));
        }

        $hasStoredDesign = (int)($item['design_image_bytes'] ?? 0) > 0
            || trim((string)($item['design_file'] ?? '')) !== '';
        $hasUploadInSpecs = $this->customHasUploadDesign($custom);

        if ($designUrl === '' && $hasStoredDesign && $orderItemId > 0) {
            $designUrl = $base . '/public/serve_design.php?type=order_item&id=' . $orderItemId;
        }
        if ($designUrl === '' && $hasUploadInSpecs) {
            $designUrl = (string)($this->resolveDesignUrlFromCustom($custom, $orderId) ?? '');
            if ($designUrl === '' && $orderItemId > 0) {
                $designUrl = $base . '/public/serve_design.php?type=order_item&id=' . $orderItemId;
            }
        }
        if ($designUrl === '' && $orderId > 0) {
            $fallbackId = $this->findFallbackDesignOrderItemId($orderId);
            if ($fallbackId > 0) {
                $designUrl = $base . '/public/serve_design.php?type=order_item&id=' . $fallbackId;
            }
        }
        if ($designUrl === '' && $orderId > 0) {
            foreach ($this->repo->getJobArtworkPaths($orderId) as $path) {
                $resolved = $this->resolveMediaPathToUrl($path);
                if ($resolved !== null) {
                    $designUrl = $resolved;
                    break;
                }
            }
        }

        $hasDesign = ($designUrl !== '') || $hasStoredDesign || $hasUploadInSpecs;

        $hasReference = trim((string)($item['reference_image_file'] ?? '')) !== ''
            || $this->payloadHasMedia($custom, 'reference');
        $referenceUrl = null;
        if ($hasReference) {
            $refItemId = $orderItemId > 0 ? $orderItemId : $this->findFallbackReferenceOrderItemId($orderId);
            if ($refItemId > 0) {
                $referenceUrl = $base . '/public/serve_design.php?type=order_item&id=' . $refItemId . '&field=reference';
            } elseif (trim((string)($item['pf_reference_open_url'] ?? '')) !== '') {
                $referenceUrl = trim((string)$item['pf_reference_open_url']);
            }
        }

        // Never show catalog/sample images in staff V2 — staff need the upload.
        $productImageUrl = null;
        if (!$isService && !$hasDesign && $orderItemId <= 0) {
            $product = $this->repo->getProductById((int)($item['product_id'] ?? 0));
            if ($product !== null) {
                $img = pf_order_ui_asset_url((string)($product['photo_path'] ?? ''))
                    ?: pf_order_ui_asset_url((string)($product['product_image'] ?? ''));
                $productImageUrl = $img ?: null;
            }
        }

        return [
            'design_url'        => ($hasDesign && $designUrl !== '') ? $designUrl : null,
            'reference_url'     => $referenceUrl,
            'product_image_url' => $productImageUrl,
            'has_design'        => $hasDesign && $designUrl !== '',
            'has_reference'     => $hasReference && $referenceUrl !== null,
        ];
    }

    /**
     * Build an item view from the proven JobOrderService store payload (same
     * shape the old staff page + customer modal use). Avoids re-decoding that
     * would drop flattened display labels like "Print Type", "Upload Design".
     *
     * @param array<string,mixed> $item
     * @param array<string,mixed> $order
     * @return array<string,mixed>
     */
    private function buildItemViewFromStorePayload(array $item, array $order): array
    {
        $custom = is_array($item['pf_customization'] ?? null) ? $item['pf_customization'] : [];
        if ($custom === []) {
            $custom = customer_orders_decode_customization_payload($item['customization_data'] ?? '');
        }

        $orderId = (int)($item['order_id'] ?? $order['order_id'] ?? 0);
        $name = $this->resolveStorePayloadItemName($item, $order, $custom);
        $category = trim((string)($item['pf_category'] ?? ''));
        if ($category === '' || $this->isGenericServiceLabel($category)) {
            $serviceId = (int)($custom['service_id'] ?? ($order['reference_id'] ?? 0));
            $category = $this->resolveCategory($custom, $serviceId, true, '');
        }

        $specs = $this->extractSpecificationsFromDisplayCustom($custom);
        if ($specs === []) {
            $rawDecoded = customer_orders_decode_customization_payload($item['customization_data'] ?? '');
            $savedSpecs = customer_orders_decode_customization_payload($item['specifications'] ?? '');
            if ($savedSpecs !== [] && function_exists('printflow_overlay_nonempty_assoc')) {
                $rawDecoded = printflow_overlay_nonempty_assoc($rawDecoded, $savedSpecs);
            }
            $rawCustom = printflow_decode_modal_customization_payload(
                $rawDecoded !== [] ? json_encode($rawDecoded) : ($item['customization_data'] ?? '')
            );
            $specs = $this->extractSpecifications($rawCustom);
        }
        $notes = $this->resolveStorePayloadNotes($custom, $item, $order);
        $neededDate = $this->extractNeededDateFromCustom($custom);
        $images = $this->resolveStorePayloadImages($item, $custom, $order, $name);

        return [
            'order_item_id'     => (int)($item['order_item_id'] ?? 0),
            'name'              => $name,
            'category'          => $category !== '' ? $category : 'Service',
            'is_service'        => true,
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

    /**
     * @param array<string,mixed> $item
     * @param array<string,mixed> $order
     * @param array<string,mixed> $custom
     */
    private function resolveStorePayloadItemName(array $item, array $order, array $custom): string
    {
        $candidates = [
            trim((string)($item['pf_product_name'] ?? '')),
            trim((string)($item['pf_payload_service_type'] ?? '')),
            trim((string)($custom['service_type'] ?? '')),
        ];

        if (function_exists('get_service_name_from_customization')) {
            $fromCustom = trim((string)get_service_name_from_customization($custom, ''));
            if ($fromCustom !== '') {
                $candidates[] = $fromCustom;
            }
        }

        $ref = (int)($order['reference_id'] ?? ($custom['service_id'] ?? 0));
        if ($ref > 0) {
            $svc = $this->repo->getServiceById($ref);
            $candidates[] = trim((string)($svc['name'] ?? ''));
        }

        if ($orderId = (int)($order['order_id'] ?? 0)) {
            $jobRows = db_query(
                'SELECT job_title, service_type FROM job_orders WHERE order_id = ? ORDER BY id ASC LIMIT 1',
                'i',
                [$orderId]
            ) ?: [];
            if (!empty($jobRows[0])) {
                $candidates[] = trim((string)($jobRows[0]['service_type'] ?? ''));
                $candidates[] = trim((string)($jobRows[0]['job_title'] ?? ''));
            }
        }

        foreach ($candidates as $candidate) {
            if ($candidate !== '' && !$this->isGenericServiceLabel($candidate)) {
                return $candidate;
            }
        }

        return 'Order Item';
    }

    /**
     * Extract spec tiles from the flattened customer/staff display payload.
     *
     * @param array<string,mixed> $custom
     * @return array<int,array{label:string,value:string}>
     */
    private function extractSpecificationsFromDisplayCustom(array $custom): array
    {
        $specs = [];
        $skipNormalized = [
            'quantity', 'qty', 'jobnotes', 'notes', 'specialinstructions',
            'otherinstructions', 'additionalnotes', 'designnotes',
            'servicetype', 'producttype',
        ];

        foreach ($custom as $key => $value) {
            if (!is_string($key) && !is_int($key)) {
                continue;
            }
            $key = (string)$key;
            if ($key === '' || $value === '' || $value === null) {
                continue;
            }

            $normalized = strtolower(preg_replace('/[^a-z0-9]/', '', $key));
            if (in_array($normalized, $skipNormalized, true)) {
                continue;
            }
            if (in_array($key, self::SKIP_KEYS, true)) {
                continue;
            }

            $text = trim(pf_order_ui_value_to_text($value));
            if ($text === '') {
                continue;
            }

            $label = self::FIELD_LABELS[$key] ?? $key;
            if ($label === $key) {
                $label = ucwords(str_replace(['_', '-'], ' ', $key));
            }

            $specs[] = ['label' => $label, 'value' => $text];
        }

        return $specs;
    }

    /**
     * @param array<string,mixed> $custom
     * @param array<string,mixed> $item
     * @param array<string,mixed> $order
     */
    private function resolveStorePayloadNotes(array $custom, array $item, array $order): string
    {
        $notes = pf_order_ui_resolve_special_instructions_text($custom, $item);
        if ($notes !== '') {
            return $notes;
        }

        foreach (['Job Notes', 'job_notes', 'notes', 'Notes', 'Special Instructions'] as $key) {
            if (!empty($custom[$key]) && is_scalar($custom[$key])) {
                $text = trim((string)$custom[$key]);
                if ($text !== '') {
                    return $text;
                }
            }
        }

        return '';
    }

    /**
     * First order_items row on this order that has a stored design file/BLOB.
     */
    private function findFallbackDesignOrderItemId(int $orderId): int
    {
        if ($orderId <= 0 || !$this->repo->hasColumn('order_items', 'design_image')) {
            return 0;
        }

        $rows = db_query(
            "SELECT order_item_id FROM order_items
             WHERE order_id = ?
               AND (design_image IS NOT NULL OR (design_file IS NOT NULL AND TRIM(COALESCE(design_file, '')) != ''))
             ORDER BY order_item_id ASC LIMIT 1",
            'i',
            [$orderId]
        ) ?: [];

        return !empty($rows[0]) ? (int)($rows[0]['order_item_id'] ?? 0) : 0;
    }

    /**
     * First order_items row on this order that has a reference attachment.
     */
    private function findFallbackReferenceOrderItemId(int $orderId): int
    {
        if ($orderId <= 0 || !$this->repo->hasColumn('order_items', 'reference_image_file')) {
            return 0;
        }

        $rows = db_query(
            "SELECT order_item_id FROM order_items
             WHERE order_id = ?
               AND reference_image_file IS NOT NULL
               AND TRIM(COALESCE(reference_image_file, '')) != ''
             ORDER BY order_item_id ASC LIMIT 1",
            'i',
            [$orderId]
        ) ?: [];

        return !empty($rows[0]) ? (int)($rows[0]['order_item_id'] ?? 0) : 0;
    }

    /**
     * @param array<string,mixed> $custom
     */
    private function extractNeededDateFromCustom(array $custom): string
    {
        foreach ($custom as $k => $v) {
            $nk = strtolower(preg_replace('/[^a-z0-9]/', '', (string)$k));
            if (in_array($nk, ['neededdate', 'dateneeded', 'orderneededdate'], true) && trim((string)$v) !== '') {
                return trim(pf_order_ui_value_to_text($v));
            }
        }

        return '';
    }

    /**
     * @param array<string,mixed> $item
     * @param array<string,mixed> $custom
     * @param array<string,mixed> $order
     * @return array{design_url:?string,reference_url:?string,product_image_url:?string,has_design:bool,has_reference:bool}
     */
    private function resolveStorePayloadImages(array $item, array $custom, array $order, string $name): array
    {
        $orderId = (int)($item['order_id'] ?? $order['order_id'] ?? 0);
        $orderItemId = (int)($item['order_item_id'] ?? 0);
        $base = $this->baseUrl();

        $hasStoredDesign = (int)($item['design_image_bytes'] ?? 0) > 0
            || trim((string)($item['design_file'] ?? '')) !== '';
        $hasUploadInSpecs = $this->customHasUploadDesign($custom);
        $hasDesign = $hasStoredDesign
            || $hasUploadInSpecs
            || trim((string)($item['pf_design_name'] ?? '')) !== '';

        $designUrl = trim((string)($item['pf_design_open_url'] ?? ''));
        if ($designUrl === '' && $hasStoredDesign && $orderItemId > 0) {
            $designUrl = $base . '/public/serve_design.php?type=order_item&id=' . $orderItemId;
        }
        if ($designUrl === '' && $orderId > 0) {
            $fallbackId = $this->findFallbackDesignOrderItemId($orderId);
            if ($fallbackId > 0) {
                $designUrl = $base . '/public/serve_design.php?type=order_item&id=' . $fallbackId;
                $hasDesign = true;
            }
        }
        if ($designUrl === '' && $hasUploadInSpecs) {
            $designUrl = (string)($this->resolveDesignUrlFromCustom($custom, $orderId) ?? '');
            if ($designUrl === '' && $orderItemId > 0) {
                $designUrl = $base . '/public/serve_design.php?type=order_item&id=' . $orderItemId;
            }
        }
        if ($designUrl === '' && $orderId > 0) {
            foreach ($this->repo->getJobArtworkPaths($orderId) as $path) {
                $resolved = $this->resolveMediaPathToUrl($path);
                if ($resolved !== null) {
                    $designUrl = $resolved;
                    $hasDesign = true;
                    break;
                }
            }
        }

        $referenceUrl = trim((string)($item['pf_reference_open_url'] ?? ''));
        $hasReference = $referenceUrl !== ''
            || trim((string)($item['reference_image_file'] ?? '')) !== ''
            || $this->payloadHasMedia($custom, 'reference');
        if ($referenceUrl === '' && $hasReference) {
            $refItemId = $orderItemId > 0 ? $orderItemId : $this->findFallbackReferenceOrderItemId($orderId);
            if ($refItemId > 0) {
                $referenceUrl = $base . '/public/serve_design.php?type=order_item&id=' . $refItemId . '&field=reference';
            }
        }

        return [
            'design_url'        => ($hasDesign && $designUrl !== '') ? $designUrl : null,
            'reference_url'     => ($hasReference && $referenceUrl !== '') ? $referenceUrl : null,
            'product_image_url' => null,
            'has_design'        => $hasDesign && $designUrl !== '',
            'has_reference'     => $hasReference && $referenceUrl !== '',
        ];
    }

    /**
     * @param array<string,mixed> $custom
     */
    private function customHasUploadDesign(array $custom): bool
    {
        foreach ($custom as $k => $v) {
            if (!is_scalar($v) || trim((string)$v) === '') {
                continue;
            }
            $nk = strtolower(preg_replace('/[^a-z0-9]/', '', (string)$k));
            if ($this->isUploadDesignKey($nk)) {
                return true;
            }
        }

        return $this->payloadHasMedia($custom, 'design');
    }

    private function isUploadDesignKey(string $normalizedKey): bool
    {
        if (in_array($normalizedKey, ['designupload', 'desingupload', 'designfile', 'uploaddesign'], true)) {
            return true;
        }

        $hasUpload = strpos($normalizedKey, 'upload') !== false || strpos($normalizedKey, 'desing') !== false;
        $hasDesign = strpos($normalizedKey, 'design') !== false || strpos($normalizedKey, 'desing') !== false;

        return $hasUpload && $hasDesign;
    }

    /**
     * @param array<string,mixed> $custom
     */
    private function resolveDesignUrlFromCustom(array $custom, int $orderId): ?string
    {
        foreach ($custom as $k => $v) {
            if (!is_scalar($v) || trim((string)$v) === '') {
                continue;
            }
            $nk = strtolower(preg_replace('/[^a-z0-9]/', '', (string)$k));
            if ($this->isUploadDesignKey($nk)) {
                $url = $this->resolveMediaPathToUrl(trim((string)$v));
                if ($url !== null) {
                    return $url;
                }
            }
        }

        return null;
    }

    private function resolveMediaPathToUrl(string $path): ?string
    {
        $path = trim($path);
        if ($path === '') {
            return null;
        }

        if (function_exists('pf_order_ui_asset_url')) {
            $url = pf_order_ui_asset_url($path);
            if ($url !== null && $url !== '') {
                return $url;
            }
        }

        if (preg_match('#^https?://#i', $path)) {
            return $path;
        }

        $normalized = str_replace('\\', '/', $path);
        $base = $this->baseUrl();
        if (strpos($normalized, '/uploads/') !== false) {
            $normalized = substr($normalized, strpos($normalized, '/uploads/'));
        } elseif ($normalized !== '' && $normalized[0] !== '/') {
            $normalized = '/uploads/orders/' . ltrim($normalized, '/');
        }

        return $base . $normalized;
    }

    private function isGenericServiceLabel(string $name): bool
    {
        $normalized = strtolower(trim($name));
        if ($normalized === '' || $normalized === 'service') {
            return true;
        }

        return function_exists('customer_orders_is_generic_item_name')
            && customer_orders_is_generic_item_name($name);
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
            if ($resolved !== '' && !$this->isGenericServiceLabel($resolved)) {
                return $resolved;
            }
            if ($raw !== '' && !$this->isGenericServiceLabel($raw)) {
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

        // Pre-resolved URLs from the job-order payload fallback take priority —
        // they already point at the correct asset even when order_item_id is 0.
        $preDesignUrl = trim((string)($item['pf_design_open_url'] ?? ($item['pf_design_url'] ?? '')));
        $preReferenceUrl = trim((string)($item['pf_reference_open_url'] ?? ($item['pf_reference_url'] ?? '')));

        $designUrl = $preDesignUrl !== ''
            ? $preDesignUrl
            : (($hasDesign && $orderItemId > 0)
                ? $base . '/public/serve_design.php?type=order_item&id=' . $orderItemId
                : null);
        if ($preDesignUrl !== '') {
            $hasDesign = true;
        }

        $referenceUrl = $preReferenceUrl !== ''
            ? $preReferenceUrl
            : (($hasReference && $orderItemId > 0)
                ? $base . '/public/serve_design.php?type=order_item&id=' . $orderItemId . '&field=reference'
                : null);
        if ($preReferenceUrl !== '') {
            $hasReference = true;
        }

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

        if ($hasDesign) {
            $productImageUrl = null;
        } elseif ($isService && $productImageUrl !== null && $this->isGenericServiceLabel($name)) {
            $productImageUrl = null;
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
