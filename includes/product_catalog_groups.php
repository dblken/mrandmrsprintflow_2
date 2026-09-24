<?php
/**
 * Customer catalog product groups (display-only; real orders use member product_id).
 */

require_once __DIR__ . '/db.php';

/** Max length for product_catalog_groups.description (admin + server validation). */
const PRINTFLOW_CATALOG_GROUP_DESCRIPTION_MAX = 1000;

function printflow_ensure_product_catalog_groups_schema(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    global $conn;
    if (printflow_db_in_transaction($conn)) {
        $done = true;
        return;
    }

    db_execute(
        "CREATE TABLE IF NOT EXISTS product_catalog_groups (
            group_id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(100) NOT NULL,
            cover_image VARCHAR(255) NULL,
            status ENUM('Activated','Deactivated') NOT NULL DEFAULT 'Activated',
            sort_order INT NOT NULL DEFAULT 0,
            created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            KEY idx_catalog_group_status (status, sort_order, name)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    db_execute(
        "CREATE TABLE IF NOT EXISTS product_catalog_group_members (
            member_id INT AUTO_INCREMENT PRIMARY KEY,
            group_id INT NOT NULL,
            product_id INT NOT NULL,
            sort_order INT NOT NULL DEFAULT 0,
            created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_catalog_member_product (product_id),
            UNIQUE KEY uq_catalog_group_product (group_id, product_id),
            KEY idx_catalog_group_sort (group_id, sort_order, member_id),
            CONSTRAINT fk_catalog_member_group FOREIGN KEY (group_id) REFERENCES product_catalog_groups (group_id) ON DELETE CASCADE,
            CONSTRAINT fk_catalog_member_product FOREIGN KEY (product_id) REFERENCES products (product_id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $groupCols = array_flip(array_column(db_query('SHOW COLUMNS FROM product_catalog_groups') ?: [], 'Field'));
    if (!isset($groupCols['description'])) {
        db_execute(
            "ALTER TABLE product_catalog_groups
             ADD COLUMN description TEXT NULL AFTER cover_image"
        );
    }

    $done = true;
}

function printflow_catalog_group_list_all(bool $includeInactive = false): array
{
    printflow_ensure_product_catalog_groups_schema();
    $sql = "SELECT g.*,
            (SELECT COUNT(*) FROM product_catalog_group_members m WHERE m.group_id = g.group_id) AS member_count
            FROM product_catalog_groups g";
    if (!$includeInactive) {
        $sql .= " WHERE g.status = 'Activated'";
    }
    $sql .= " ORDER BY g.sort_order ASC, g.name ASC";
    return db_query($sql) ?: [];
}

function printflow_catalog_group_get(int $groupId, bool $requireActive = false): ?array
{
    printflow_ensure_product_catalog_groups_schema();
    if ($groupId <= 0) {
        return null;
    }
    $rows = db_query(
        "SELECT * FROM product_catalog_groups WHERE group_id = ? LIMIT 1",
        'i',
        [$groupId]
    );
    if (empty($rows)) {
        return null;
    }
    $group = $rows[0];
    if ($requireActive && ($group['status'] ?? '') !== 'Activated') {
        return null;
    }
    return $group;
}

function printflow_catalog_group_members(int $groupId, bool $activeProductsOnly = true): array
{
    printflow_ensure_product_catalog_groups_schema();
    if ($groupId <= 0) {
        return [];
    }
    $sql = "SELECT p.*, m.sort_order AS member_sort_order, m.member_id
            FROM product_catalog_group_members m
            INNER JOIN products p ON p.product_id = m.product_id
            WHERE m.group_id = ?";
    if ($activeProductsOnly) {
        $sql .= " AND p.status = 'Activated'";
    }
    $sql .= " ORDER BY m.sort_order ASC, p.name ASC";
    return db_query($sql, 'i', [$groupId]) ?: [];
}

function printflow_catalog_group_for_product(int $productId): ?array
{
    printflow_ensure_product_catalog_groups_schema();
    if ($productId <= 0) {
        return null;
    }
    $rows = db_query(
        "SELECT g.group_id, g.name, g.cover_image, g.status
         FROM product_catalog_group_members m
         INNER JOIN product_catalog_groups g ON g.group_id = m.group_id
         WHERE m.product_id = ?
         LIMIT 1",
        'i',
        [$productId]
    );
    return !empty($rows) ? $rows[0] : null;
}

function printflow_catalog_product_is_grouped(int $productId): bool
{
    return printflow_catalog_group_for_product($productId) !== null;
}

function printflow_catalog_validate_member_in_group(int $groupId, int $productId): bool
{
    if ($groupId <= 0 || $productId <= 0) {
        return false;
    }
    $rows = db_query(
        "SELECT 1 FROM product_catalog_group_members m
         INNER JOIN products p ON p.product_id = m.product_id
         INNER JOIN product_catalog_groups g ON g.group_id = m.group_id
         WHERE m.group_id = ? AND m.product_id = ? AND g.status = 'Activated' AND p.status = 'Activated'
         LIMIT 1",
        'ii',
        [$groupId, $productId]
    );
    return !empty($rows);
}

/**
 * @return array{ok:bool,value:?string,message?:string}
 */
function printflow_catalog_group_normalize_description(?string $raw): array
{
    $text = str_replace(["\r\n", "\r"], "\n", trim((string) $raw));
    if ($text === '') {
        return ['ok' => true, 'value' => null];
    }
    $length = function_exists('mb_strlen') ? mb_strlen($text, 'UTF-8') : strlen($text);
    if ($length > PRINTFLOW_CATALOG_GROUP_DESCRIPTION_MAX) {
        return [
            'ok' => false,
            'message' => 'Description must be ' . PRINTFLOW_CATALOG_GROUP_DESCRIPTION_MAX . ' characters or fewer.',
        ];
    }
    return ['ok' => true, 'value' => $text];
}

function printflow_catalog_group_create(string $name, ?string $coverImage = null, int $sortOrder = 0, ?string $description = null): array
{
    printflow_ensure_product_catalog_groups_schema();
    $name = preg_replace('/\s+/', ' ', trim($name));
    if ($name === '' || strlen($name) > 100) {
        return ['success' => false, 'message' => 'Group name is required (max 100 characters).'];
    }
    $id = db_execute(
        "INSERT INTO product_catalog_groups (name, cover_image, description, status, sort_order, created_at, updated_at)
         VALUES (?, ?, ?, 'Activated', ?, NOW(), NOW())",
        'sssi',
        [$name, $coverImage, $description, $sortOrder]
    );
    if (!$id) {
        return ['success' => false, 'message' => 'Could not create product group.'];
    }
    return ['success' => true, 'group_id' => (int) (is_numeric($id) ? $id : 0)];
}

function printflow_catalog_group_update(int $groupId, string $name, ?string $coverImage, string $status, int $sortOrder, ?string $description = null): array
{
    printflow_ensure_product_catalog_groups_schema();
    if ($groupId <= 0) {
        return ['success' => false, 'message' => 'Invalid group.'];
    }
    $name = preg_replace('/\s+/', ' ', trim($name));
    if ($name === '') {
        return ['success' => false, 'message' => 'Group name is required.'];
    }
    if (!in_array($status, ['Activated', 'Deactivated'], true)) {
        $status = 'Activated';
    }
    $ok = db_execute(
        "UPDATE product_catalog_groups SET name = ?, cover_image = ?, description = ?, status = ?, sort_order = ?, updated_at = NOW() WHERE group_id = ?",
        'ssssii',
        [$name, $coverImage, $description, $status, $sortOrder, $groupId]
    );
    return $ok ? ['success' => true] : ['success' => false, 'message' => 'Could not update product group.'];
}

function printflow_catalog_group_delete(int $groupId): array
{
    printflow_ensure_product_catalog_groups_schema();
    if ($groupId <= 0) {
        return ['success' => false, 'message' => 'Invalid group.'];
    }
    $ok = db_execute("DELETE FROM product_catalog_groups WHERE group_id = ?", 'i', [$groupId]);
    return $ok ? ['success' => true] : ['success' => false, 'message' => 'Could not delete product group.'];
}

function printflow_catalog_group_add_member(int $groupId, int $productId, int $sortOrder = 0): array
{
    printflow_ensure_product_catalog_groups_schema();
    if ($groupId <= 0 || $productId <= 0) {
        return ['success' => false, 'message' => 'Invalid group or product.'];
    }
    $product = db_query("SELECT product_id, status FROM products WHERE product_id = ? LIMIT 1", 'i', [$productId]);
    if (empty($product)) {
        return ['success' => false, 'message' => 'Product not found.'];
    }
    $existing = db_query(
        "SELECT group_id FROM product_catalog_group_members WHERE product_id = ? LIMIT 1",
        'i',
        [$productId]
    );
    if (!empty($existing) && (int) $existing[0]['group_id'] !== $groupId) {
        return ['success' => false, 'message' => 'This product is already assigned to another group. Remove it there first.'];
    }
    if (!empty($existing)) {
        return ['success' => true, 'message' => 'Product is already in this group.'];
    }
    $ok = db_execute(
        "INSERT INTO product_catalog_group_members (group_id, product_id, sort_order) VALUES (?, ?, ?)",
        'iii',
        [$groupId, $productId, $sortOrder]
    );
    return $ok ? ['success' => true] : ['success' => false, 'message' => 'Could not add product to group.'];
}

function printflow_catalog_group_remove_member(int $groupId, int $productId): array
{
    printflow_ensure_product_catalog_groups_schema();
    $ok = db_execute(
        "DELETE FROM product_catalog_group_members WHERE group_id = ? AND product_id = ?",
        'ii',
        [$groupId, $productId]
    );
    return $ok ? ['success' => true] : ['success' => false, 'message' => 'Could not remove product from group.'];
}

function printflow_catalog_group_set_product(int $productId, ?int $groupId): array
{
    printflow_ensure_product_catalog_groups_schema();
    if ($productId <= 0) {
        return ['success' => false, 'message' => 'Invalid product.'];
    }
    db_execute("DELETE FROM product_catalog_group_members WHERE product_id = ?", 'i', [$productId]);
    if ($groupId === null || $groupId <= 0) {
        return ['success' => true];
    }
    return printflow_catalog_group_add_member($groupId, $productId, 0);
}

/**
 * Summaries for activated groups with at least one activated member.
 */
function printflow_catalog_active_group_summaries(?string $categoryFilter = null): array
{
    printflow_ensure_product_catalog_groups_schema();
    $params = [];
    $types = '';
    $categorySql = '';
    if ($categoryFilter !== null && $categoryFilter !== '') {
        $categorySql = ' AND p.category = ? ';
        $params[] = $categoryFilter;
        $types .= 's';
    }
    $rows = db_query(
        "SELECT g.group_id, g.name, g.cover_image, g.sort_order,
                COUNT(p.product_id) AS option_count,
                MIN(p.price) AS min_price,
                MAX(p.price) AS max_price
         FROM product_catalog_groups g
         INNER JOIN product_catalog_group_members m ON m.group_id = g.group_id
         INNER JOIN products p ON p.product_id = m.product_id AND p.status = 'Activated' {$categorySql}
         WHERE g.status = 'Activated'
         GROUP BY g.group_id, g.name, g.cover_image, g.sort_order
         HAVING option_count > 0
         ORDER BY g.sort_order ASC, g.name ASC",
        $types !== '' ? $types : null,
        $params ?: null
    ) ?: [];
    return $rows;
}

/**
 * Customer catalog page: mixed group cards + standalone products, sorted by display name.
 *
 * @return array{entries: array<int, array>, total: int}
 */
function printflow_customer_catalog_entries(?string $categoryFilter, int $offset, int $limit): array
{
    printflow_ensure_product_catalog_groups_schema();

    $groups = printflow_catalog_active_group_summaries($categoryFilter);
    $groupEntries = [];
    foreach ($groups as $g) {
        $groupEntries[] = [
            'type' => 'group',
            'sort_name' => (string) ($g['name'] ?? ''),
            'group_id' => (int) $g['group_id'],
            'name' => (string) $g['name'],
            'cover_image' => $g['cover_image'] ?? null,
            'option_count' => (int) $g['option_count'],
            'min_price' => (float) ($g['min_price'] ?? 0),
            'max_price' => (float) ($g['max_price'] ?? 0),
        ];
    }

    $sql = "SELECT p.* FROM products p
            WHERE p.status = 'Activated'
            AND p.product_id NOT IN (SELECT product_id FROM product_catalog_group_members)";
    $params = [];
    $types = '';
    if ($categoryFilter !== null && $categoryFilter !== '') {
        $sql .= " AND p.category = ?";
        $params[] = $categoryFilter;
        $types .= 's';
    }
    $standalone = db_query($sql, $types !== '' ? $types : null, $params ?: null) ?: [];

    $productEntries = [];
    foreach ($standalone as $p) {
        $productEntries[] = [
            'type' => 'product',
            'sort_name' => (string) ($p['name'] ?? ''),
            'product' => $p,
        ];
    }

    $all = array_merge($groupEntries, $productEntries);
    usort($all, static function ($a, $b) {
        return strcasecmp($a['sort_name'] ?? '', $b['sort_name'] ?? '');
    });

    $total = count($all);
    $slice = array_slice($all, max(0, $offset), max(1, $limit));
    return ['entries' => $slice, 'total' => $total];
}

function printflow_catalog_group_cover_url(array $group, string $basePath, string $defaultImg): string
{
    $raw = trim((string) ($group['cover_image'] ?? ''));
    if ($raw !== '' && function_exists('pf_normalize_service_image_path')) {
        return pf_normalize_service_image_path($raw, $basePath, $defaultImg);
    }
    return $defaultImg;
}

function printflow_catalog_group_fallback_cover_from_members(int $groupId, string $basePath, string $defaultImg): string
{
    $members = printflow_catalog_group_members($groupId, true);
    if (empty($members)) {
        return $defaultImg;
    }
    $first = $members[0];
    $raw = trim((string) ($first['photo_path'] ?? $first['product_image'] ?? ''));
    if ($raw !== '' && function_exists('pf_normalize_service_image_path')) {
        return pf_normalize_service_image_path($raw, $basePath, $defaultImg);
    }
    return $defaultImg;
}
