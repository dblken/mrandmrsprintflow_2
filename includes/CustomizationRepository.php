<?php
/**
 * CustomizationRepository (V2)
 * --------------------------------------------------------------------------
 * Pure data-access layer for the Staff Customizations V2 module.
 *
 * Design principles:
 *  - order_items is ALWAYS the source of truth (never rely only on job_orders).
 *  - Reuse the existing tables: orders, order_items, job_orders, services,
 *    products, customizations. No new/duplicate tables are created.
 *  - Schema-aware: optional columns are detected at runtime so the same code
 *    works across local + production schemas.
 *
 * This class performs NO presentation logic and NO JSON decoding of
 * customization payloads — that belongs to CustomizationService.
 */

require_once __DIR__ . '/db.php';

class CustomizationRepository
{
    /** @var array<string,bool> */
    private static array $columnCache = [];

    /**
     * Schema-safe column check (cached).
     */
    public function hasColumn(string $table, string $column): bool
    {
        $key = $table . '.' . $column;
        if (array_key_exists($key, self::$columnCache)) {
            return self::$columnCache[$key];
        }

        if (function_exists('db_table_has_column')) {
            return self::$columnCache[$key] = db_table_has_column($table, $column);
        }

        $t = preg_replace('/[^a-zA-Z0-9_]/', '', $table);
        $c = preg_replace('/[^a-zA-Z0-9_]/', '', $column);
        $rows = db_query("SHOW COLUMNS FROM `{$t}` LIKE '{$c}'");
        return self::$columnCache[$key] = !empty($rows);
    }

    /**
     * List "customization" orders (online services + POS service/custom orders).
     *
     * We intentionally drive this off the orders table filtered to custom
     * orders, but every order is guaranteed to be resolvable down to its
     * order_items rows. Hidden POS drafts (pos_draft) are excluded.
     *
     * @return array<int,array<string,mixed>>
     */
    public function listOrders(?int $branchId = null, ?string $sourceFilter = null, int $limit = 200): array
    {
        $hasOrderSource = $this->hasColumn('orders', 'order_source');
        $hasOrderType   = $this->hasColumn('orders', 'order_type');
        $hasEstimated   = $this->hasColumn('orders', 'estimated_price');
        $hasDesignStat  = $this->hasColumn('orders', 'design_status');

        $select = [
            'o.order_id',
            'o.customer_id',
            'o.order_date',
            'o.status',
            'o.payment_status',
            'o.total_amount',
            'o.branch_id',
            'o.notes',
            ($hasOrderType ? 'o.order_type' : "'custom' AS order_type"),
            ($hasOrderSource ? 'o.order_source' : "'customer' AS order_source"),
            ($hasEstimated ? 'o.estimated_price' : 'NULL AS estimated_price'),
            ($hasDesignStat ? 'o.design_status' : 'NULL AS design_status'),
            'c.first_name',
            'c.last_name',
            'c.customer_type',
            "COALESCE(NULLIF(TRIM(c.contact_number), ''), NULLIF(TRIM(c.email), '')) AS customer_contact",
            'c.profile_picture AS customer_profile_picture',
            'b.branch_name',
        ];

        $where = [];
        $types = '';
        $params = [];

        if ($hasOrderType) {
            $where[] = "o.order_type = 'custom'";
        }
        // Never surface hidden POS draft orders (work-in-progress carts).
        if ($hasOrderSource) {
            $where[] = "LOWER(TRIM(COALESCE(o.order_source, ''))) <> 'pos_draft'";
        }
        $where[] = "LOWER(TRIM(COALESCE(o.status, ''))) <> 'draft'";
        // Only include orders that actually have at least one order item.
        $where[] = "EXISTS (SELECT 1 FROM order_items oi WHERE oi.order_id = o.order_id)";

        if ($branchId !== null) {
            $where[] = 'o.branch_id = ?';
            $types .= 'i';
            $params[] = $branchId;
        }

        $sql = 'SELECT ' . implode(', ', $select) . '
                FROM orders o
                LEFT JOIN customers c ON c.customer_id = o.customer_id
                LEFT JOIN branches b ON b.id = o.branch_id
                WHERE ' . implode(' AND ', $where) . '
                ORDER BY o.order_date DESC, o.order_id DESC
                LIMIT ' . max(1, $limit);

        $rows = db_query($sql, $types ?: '', $params ?: []) ?: [];

        if ($sourceFilter !== null && $sourceFilter !== '' && $sourceFilter !== 'all') {
            $rows = array_values(array_filter($rows, function (array $row) use ($sourceFilter) {
                $isPos = $this->rowIsPos($row);
                return $sourceFilter === 'pos' ? $isPos : !$isPos;
            }));
        }

        return $rows;
    }

    /**
     * Fetch a single order with customer + branch context.
     *
     * @return array<string,mixed>|null
     */
    public function getOrder(int $orderId): ?array
    {
        if ($orderId <= 0) {
            return null;
        }

        // Schema-safe: pull each table separately with wildcard selects so we
        // never reference an optional column (address/profile_picture/etc.)
        // that may not exist on a given environment's schema.
        $rows = db_query('SELECT * FROM orders WHERE order_id = ? LIMIT 1', 'i', [$orderId]);
        if (empty($rows)) {
            return null;
        }
        $order = $rows[0];

        // Normalise optional order columns so callers can read them safely.
        foreach (['order_type', 'order_source', 'estimated_price', 'design_status', 'reference_id', 'revision_reason', 'rejection_reason'] as $optional) {
            if (!array_key_exists($optional, $order)) {
                $order[$optional] = null;
            }
        }

        // Customer (optional columns resolved defensively).
        $customerId = (int)($order['customer_id'] ?? 0);
        $cust = [];
        if ($customerId > 0) {
            $cRows = db_query('SELECT * FROM customers WHERE customer_id = ? LIMIT 1', 'i', [$customerId]);
            $cust = $cRows[0] ?? [];
        }
        $order['first_name']                = $cust['first_name'] ?? '';
        $order['last_name']                 = $cust['last_name'] ?? '';
        $order['customer_email']            = $cust['email'] ?? '';
        $order['customer_contact']          = $cust['contact_number'] ?? '';
        $order['customer_type']             = $cust['customer_type'] ?? '';
        $order['customer_profile_picture']  = $cust['profile_picture'] ?? '';
        $order['customer_address']          = $cust['address'] ?? '';
        $order['customer_street']           = $cust['street'] ?? '';
        $order['customer_barangay']         = $cust['barangay'] ?? '';
        $order['customer_city']             = $cust['city'] ?? '';
        $order['customer_province']         = $cust['province'] ?? '';

        // Branch name (optional).
        $order['branch_name'] = '';
        $branchId = (int)($order['branch_id'] ?? 0);
        if ($branchId > 0) {
            $bRows = db_query('SELECT branch_name FROM branches WHERE id = ? LIMIT 1', 'i', [$branchId]);
            $order['branch_name'] = (string)($bRows[0]['branch_name'] ?? '');
        }

        return $order;
    }

    /**
     * Fetch all order_items rows for an order (raw). Heavy BLOB column is
     * replaced with a byte-length flag so we never pull large binaries here.
     *
     * @return array<int,array<string,mixed>>
     */
    public function getOrderItems(int $orderId): array
    {
        if ($orderId <= 0) {
            return [];
        }

        return db_query(
            'SELECT ' . $this->orderItemSelect() . '
             FROM order_items oi
             WHERE oi.order_id = ?
             ORDER BY oi.order_item_id ASC',
            'i',
            [$orderId]
        ) ?: [];
    }

    /**
     * Build a schema-safe SELECT list for order_items. Optional columns that
     * may not exist on a given schema are emitted as typed NULL/0 aliases so
     * downstream code can always read them by key.
     */
    private function orderItemSelect(): string
    {
        $cols = [
            'oi.order_item_id',
            'oi.order_id',
            'oi.product_id',
            'oi.quantity',
            'oi.unit_price',
            'oi.sku',
        ];

        $cols[] = $this->hasColumn('order_items', 'variant_id')
            ? 'oi.variant_id' : 'NULL AS variant_id';
        $cols[] = $this->hasColumn('order_items', 'customization_data')
            ? 'oi.customization_data' : 'NULL AS customization_data';
        $cols[] = $this->hasColumn('order_items', 'design_image')
            ? 'IFNULL(LENGTH(oi.design_image), 0) AS design_image_bytes' : '0 AS design_image_bytes';
        $cols[] = $this->hasColumn('order_items', 'design_image_mime')
            ? 'oi.design_image_mime' : 'NULL AS design_image_mime';
        $cols[] = $this->hasColumn('order_items', 'design_image_name')
            ? 'oi.design_image_name' : 'NULL AS design_image_name';
        $cols[] = $this->hasColumn('order_items', 'design_file')
            ? 'oi.design_file' : 'NULL AS design_file';
        $cols[] = $this->hasColumn('order_items', 'reference_image_file')
            ? 'oi.reference_image_file' : 'NULL AS reference_image_file';

        return implode(', ', $cols);
    }

    /**
     * Fetch one order_item by id (raw, BLOB-safe).
     *
     * @return array<string,mixed>|null
     */
    public function getOrderItem(int $orderItemId): ?array
    {
        if ($orderItemId <= 0) {
            return null;
        }

        $rows = db_query(
            'SELECT ' . $this->orderItemSelect() . '
             FROM order_items oi
             WHERE oi.order_item_id = ?
             LIMIT 1',
            'i',
            [$orderItemId]
        );

        return $rows[0] ?? null;
    }

    /**
     * Customizations table rows for an order (fallback spec/detail source &
     * also used to resolve customization_id for staff actions).
     *
     * @return array<int,array<string,mixed>>
     */
    public function getCustomizations(int $orderId): array
    {
        if ($orderId <= 0) {
            return [];
        }

        return db_query(
            "SELECT customization_id, order_id, order_item_id, customer_id,
                    service_type, customization_details, status
             FROM customizations
             WHERE order_id = ?
             ORDER BY customization_id ASC",
            'i',
            [$orderId]
        ) ?: [];
    }

    /**
     * Job order artwork paths for an order (used to detect a design exists).
     *
     * @return array<int,string>
     */
    public function getJobArtworkPaths(int $orderId): array
    {
        if ($orderId <= 0) {
            return [];
        }

        $rows = db_query(
            "SELECT artwork_path
             FROM job_orders
             WHERE order_id = ?
               AND TRIM(COALESCE(artwork_path, '')) <> ''",
            'i',
            [$orderId]
        ) ?: [];

        return array_values(array_filter(array_map(
            static fn(array $r): string => trim((string)($r['artwork_path'] ?? '')),
            $rows
        )));
    }

    /**
     * Linked, active job_orders ids for an order.
     *
     * @return array<int,int>
     */
    public function getActiveJobIds(int $orderId): array
    {
        if ($orderId <= 0) {
            return [];
        }

        $rows = db_query(
            "SELECT id FROM job_orders
             WHERE order_id = ?
               AND status NOT IN ('COMPLETED', 'CANCELLED')
             ORDER BY id ASC",
            'i',
            [$orderId]
        ) ?: [];

        return array_values(array_filter(array_map(
            static fn(array $r): int => (int)($r['id'] ?? 0),
            $rows
        )));
    }

    /**
     * @return array<string,mixed>|null
     */
    public function getServiceById(int $serviceId): ?array
    {
        static $cache = [];
        if ($serviceId <= 0) {
            return null;
        }
        if (array_key_exists($serviceId, $cache)) {
            return $cache[$serviceId];
        }

        $rows = db_query(
            'SELECT service_id, name, category, display_image, hero_image
             FROM services WHERE service_id = ? LIMIT 1',
            'i',
            [$serviceId]
        );

        return $cache[$serviceId] = ($rows[0] ?? null);
    }

    /**
     * @return array<string,mixed>|null
     */
    public function getProductById(int $productId): ?array
    {
        static $cache = [];
        if ($productId <= 0) {
            return null;
        }
        if (array_key_exists($productId, $cache)) {
            return $cache[$productId];
        }

        $rows = db_query(
            'SELECT product_id, name, category, sku, price, photo_path, product_image
             FROM products WHERE product_id = ? LIMIT 1',
            'i',
            [$productId]
        );

        return $cache[$productId] = ($rows[0] ?? null);
    }

    /**
     * Determine whether an order row originated from the POS / walk-in flow.
     *
     * @param array<string,mixed> $row
     */
    public function rowIsPos(array $row): bool
    {
        $source = strtolower(trim((string)($row['order_source'] ?? '')));
        if (in_array($source, ['pos', 'walk-in', 'pos_merged', 'pos_draft'], true)) {
            return true;
        }

        // Fallback: look for the POS marker stored inside customization_details.
        $orderId = (int)($row['order_id'] ?? 0);
        if ($orderId <= 0) {
            return false;
        }
        $rows = db_query(
            "SELECT 1 FROM customizations
             WHERE order_id = ?
               AND customization_details LIKE '%\"source\":\"POS\"%'
             LIMIT 1",
            'i',
            [$orderId]
        ) ?: [];

        return !empty($rows);
    }

    /**
     * Persist a status string on the customizations rows of an order.
     */
    public function updateCustomizationStatus(int $orderId, string $status, ?string $reason = null): void
    {
        if ($orderId <= 0 || $status === '') {
            return;
        }

        db_execute(
            'UPDATE customizations SET status = ?, updated_at = NOW() WHERE order_id = ?',
            'si',
            [$status, $orderId]
        );

        if ($reason !== null && $reason !== '' && $this->hasColumn('customizations', 'rejection_reason')) {
            db_execute(
                'UPDATE customizations SET rejection_reason = ? WHERE order_id = ?',
                'si',
                [$reason, $orderId]
            );
        }
    }

    /**
     * Persist a status string (and optional revision metadata) on the order.
     */
    public function updateOrderStatus(int $orderId, string $status, ?string $designStatus = null, ?string $reason = null): void
    {
        if ($orderId <= 0 || $status === '') {
            return;
        }

        db_execute('UPDATE orders SET status = ? WHERE order_id = ?', 'si', [$status, $orderId]);

        if ($designStatus !== null && $this->hasColumn('orders', 'design_status')) {
            db_execute('UPDATE orders SET design_status = ? WHERE order_id = ?', 'si', [$designStatus, $orderId]);
        }

        if ($reason !== null && $reason !== '') {
            if ($this->hasColumn('orders', 'rejection_reason')) {
                db_execute('UPDATE orders SET rejection_reason = ? WHERE order_id = ?', 'si', [$reason, $orderId]);
            }
            if ($this->hasColumn('orders', 'revision_reason')) {
                db_execute('UPDATE orders SET revision_reason = ? WHERE order_id = ?', 'si', [$reason, $orderId]);
            }
        }
    }
}
