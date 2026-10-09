<?php
declare(strict_types=1);

/** Isolated CSV demo maintenance. No checkout, stock, or payment workflow calls. */
interface DemoSeedDeletionDatabase
{
    public function rows(string $sql, array $params = []): array;
    public function write(string $sql, array $params = []): int;
    public function schema(): array;
    public function begin(): void;
    public function commit(): void;
    public function rollback(): void;
    public function lockClause(): string;
}

final class DemoSeedMysqliDeletionDatabase implements DemoSeedDeletionDatabase
{
    public function __construct(private mysqli $connection) {}

    private function statement(string $sql, array $params): mysqli_stmt
    {
        $statement = $this->connection->prepare($sql);
        if (!$statement) throw new RuntimeException('Demo maintenance query could not be prepared: ' . $this->connection->error);
        if ($params !== []) {
            $types = implode('', array_map(static fn($value) => is_int($value) ? 'i' : 's', $params));
            $statement->bind_param($types, ...$params);
        }
        if (!$statement->execute()) {
            $error = $statement->error;
            $statement->close();
            throw new RuntimeException('Demo maintenance query failed: ' . $error);
        }
        return $statement;
    }

    public function rows(string $sql, array $params = []): array
    {
        $statement = $this->statement($sql, $params);
        if (method_exists($statement, 'get_result')) {
            $result = $statement->get_result();
            if (!$result) throw new RuntimeException('Could not read demo maintenance query result.');
            $rows = $result->fetch_all(MYSQLI_ASSOC);
        } else {
            $metadata = $statement->result_metadata();
            if (!$metadata) throw new RuntimeException('Could not inspect demo maintenance query result.');
            $row = $bindings = $rows = [];
            foreach ($metadata->fetch_fields() as $field) { $row[$field->name] = null; $bindings[] = &$row[$field->name]; }
            $statement->bind_result(...$bindings);
            while (($status = $statement->fetch()) === true) $rows[] = array_map(static fn($value) => $value, $row);
            if ($status === false) throw new RuntimeException('Could not fetch demo maintenance rows: ' . $statement->error);
        }
        $statement->close();
        return $rows;
    }

    public function write(string $sql, array $params = []): int
    {
        $statement = $this->statement($sql, $params);
        $affected = $statement->affected_rows;
        $statement->close();
        return $affected;
    }

    public function schema(): array
    {
        $tables = [];
        foreach ($this->rows('SELECT TABLE_NAME, ENGINE FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = ?', ['BASE TABLE']) as $row) {
            $tables[$row['TABLE_NAME']] = ['columns' => [], 'pk' => [], 'engine' => $row['ENGINE']];
        }
        foreach ($this->rows('SELECT TABLE_NAME, COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() ORDER BY ORDINAL_POSITION') as $row) {
            if (isset($tables[$row['TABLE_NAME']])) $tables[$row['TABLE_NAME']]['columns'][] = $row['COLUMN_NAME'];
        }
        $keys = $this->rows('SELECT TABLE_SCHEMA, TABLE_NAME, COLUMN_NAME, CONSTRAINT_NAME, REFERENCED_TABLE_SCHEMA, REFERENCED_TABLE_NAME, REFERENCED_COLUMN_NAME FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA = DATABASE() ORDER BY TABLE_NAME, CONSTRAINT_NAME, ORDINAL_POSITION');
        $foreignKeys = [];
        foreach ($keys as $key) {
            if ($key['CONSTRAINT_NAME'] === 'PRIMARY') $tables[$key['TABLE_NAME']]['pk'][] = $key['COLUMN_NAME'];
            if ($key['REFERENCED_TABLE_NAME'] !== null) $foreignKeys[] = [
                'table' => $key['TABLE_NAME'], 'column' => $key['COLUMN_NAME'],
                'parent' => $key['REFERENCED_TABLE_NAME'], 'parent_column' => $key['REFERENCED_COLUMN_NAME'],
                'constraint' => $key['CONSTRAINT_NAME'],
                'external' => $key['REFERENCED_TABLE_SCHEMA'] !== $key['TABLE_SCHEMA'],
            ];
        }
        if ($tables === []) throw new RuntimeException('Cannot inspect the actual database schema. Deletion is disabled.');
        return ['tables' => $tables, 'foreign_keys' => $foreignKeys];
    }

    public function begin(): void { if (!$this->connection->begin_transaction()) throw new RuntimeException('Could not begin demo deletion transaction.'); }
    public function commit(): void { if (!$this->connection->commit()) throw new RuntimeException('Could not commit demo deletion transaction.'); }
    public function rollback(): void { if (!$this->connection->rollback()) throw new RuntimeException('Could not roll back demo deletion transaction.'); }
    public function lockClause(): string { return ' FOR UPDATE'; }
}

final class DemoSeedDeletionTool
{
    public const CONFIRMATION = 'DELETE MEETING DATA';
    private array $schema = [];
    private array $candidates = [];
    private array $scope = [];
    private array $denied = [];
    private array $links = [];
    private array $diagnostics = [];
    private array $anchors = [];
    private array $readCache = [];
    private string $batchId = '';
    private bool $locking = false;

    public function __construct(private DemoSeedDeletionDatabase $db) {}

    private static function identifier(string $name): string
    {
        if (!preg_match('/^[A-Za-z0-9_]+$/D', $name)) throw new RuntimeException('Unsupported schema identifier.');
        return '`' . $name . '`';
    }

    private function has(string $table, string $column): bool
    {
        return in_array($column, $this->schema['tables'][$table]['columns'] ?? [], true);
    }

    private function pk(string $table): ?string
    {
        $keys = $this->schema['tables'][$table]['pk'] ?? [];
        return count($keys) === 1 ? $keys[0] : null;
    }

    private function externalRelationship(string $table, string $column): bool
    {
        foreach ($this->schema['foreign_keys'] as $link) if ($link['table'] === $table && $link['column'] === $column && !empty($link['external'])) return true;
        return false;
    }

    private function selected(string $table, string $column, array $values): array
    {
        $values = array_values(array_unique(array_filter($values, static fn($v) => $v !== null && $v !== '')));
        if ($values === [] || !$this->has($table, $column)) return [];
        sort($values, SORT_STRING);
        $cacheKey = $table . '.' . $column;
        $missing = array_values(array_filter($values, fn($value) => !array_key_exists((string)$value, $this->readCache[$cacheKey] ?? [])));
        foreach (array_chunk($missing, 400) as $chunk) {
            $sql = 'SELECT * FROM ' . self::identifier($table) . ' WHERE ' . self::identifier($column) . ' IN (' . implode(',', array_fill(0, count($chunk), '?')) . ')';
            if ($this->locking) $sql .= $this->db->lockClause();
            foreach ($chunk as $value) $this->readCache[$cacheKey][(string)$value] = [];
            foreach ($this->db->rows($sql, $chunk) as $row) {
                if ($column === 'batch_id' && count($chunk) === 1) $this->readCache[$cacheKey][(string)$chunk[0]][] = $row;
                else $this->readCache[$cacheKey][(string)$row[$column]][] = $row;
            }
        }
        $rows = [];
        foreach ($values as $value) foreach ($this->readCache[$cacheKey][(string)$value] ?? [] as $row) $rows[$this->key($table, $row)] = $row;
        if (count($rows) > 50000) throw new RuntimeException('Related row count exceeds the safe preview limit. Review this batch separately.');
        $rows = array_values($rows);
        usort($rows, fn($a, $b) => $this->key($table, $a) <=> $this->key($table, $b));
        return $rows;
    }

    private function key(string $table, array $row): string
    {
        $pk = $this->pk($table);
        return $pk !== null ? (string)$row[$pk] : hash('sha256', json_encode($row, JSON_THROW_ON_ERROR));
    }

    /** Exact JSON equality, never a LIKE match, establishes batch ownership. */
    private function marker(array $row): ?string
    {
        $markers = [];
        foreach (['batch_id', 'seed_batch_id', '_seed_batch_id'] as $column) {
            if (isset($row[$column]) && (string)$row[$column] !== '') $markers[] = (string)$row[$column];
        }
        foreach (['customization_data', 'customization_details', 'specifications', 'metadata', 'payload'] as $column) {
            $data = isset($row[$column]) && is_string($row[$column]) ? json_decode($row[$column], true) : null;
            if (is_array($data) && isset($data['_seed_batch_id'])) $markers[] = (string)$data['_seed_batch_id'];
        }
        $markers = array_values(array_unique($markers));
        if (count($markers) > 1) return '__conflicting_markers__';
        return $markers[0] ?? null;
    }

    private function remember(string $table, array $row, ?string $proof = null): void
    {
        $key = $this->key($table, $row);
        $this->candidates[$table][$key] = $row;
        if ($proof === null) return;
        $reason = null;
        if ($this->pk($table) === null) $reason = 'No single primary key; individual deletion cannot be verified.';
        if (!in_array(strtolower((string)($this->schema['tables'][$table]['engine'] ?? '')), ['innodb', 'sqlite'], true)) $reason = 'Table does not support this deletion transaction.';
        if (preg_match('/^(users|staff(_.*)?|branches|services|products|product_.*|service_catalog.*|service_form.*|catalog.*|materials|inv_items|inv_rolls|inventory_items|inventory_rolls|machines|payment_methods|discounts)$/i', $table)) $reason = 'Shared catalog, account, branch, or material record.';
        $marker = $this->marker($row);
        if ($marker !== null && $marker !== $this->batchId) $reason = 'Batch marker belongs to another batch or conflicts.';
        if (preg_match('/payment|transaction|ledger|stock_movement|inv_movement/i', $table) && !preg_match('/history$|events$|logs$/i', $table) && $marker !== $this->batchId) $reason = 'Payment or inventory ownership is not proven by an exact batch marker.';
        if (array_key_exists('deducted_at', $row) && $row['deducted_at'] !== null) $reason = 'Material was deducted; stock and ledger require separate review.';
        if ($reason !== null) {
            $this->denied[$table][$key] = $reason;
            unset($this->scope[$table][$key]);
            return;
        }
        if (!isset($this->denied[$table][$key])) $this->scope[$table][$key] = $proof;
    }

    private function deny(string $table, array $row, string $reason): void
    {
        $this->remember($table, $row);
        $key = $this->key($table, $row);
        $this->denied[$table][$key] = $reason;
        unset($this->scope[$table][$key]);
    }

    private function markedRows(string $table, string $column): array
    {
        if (!$this->has($table, $column)) return [];
        $sql = 'SELECT * FROM ' . self::identifier($table) . ' WHERE ' . self::identifier($column) . ' LIKE ?';
        if ($this->locking) $sql .= $this->db->lockClause();
        return array_values(array_filter($this->db->rows($sql, ['%' . $this->batchId . '%']), fn($row) => $this->marker($row) === $this->batchId));
    }

    private function isScoped(string $table, string $column, $value): bool
    {
        if ($value !== null && in_array((string)$value, array_map('strval', $this->anchors[$table][$column] ?? []), true)) return true;
        if ($this->pk($table) === $column) return $value !== null && isset($this->scope[$table][(string)$value]);
        foreach ($this->scope[$table] ?? [] as $key => $_proof) {
            if ((string)($this->candidates[$table][$key][$column] ?? '') === (string)$value) return true;
        }
        return false;
    }

    private function anchorMissing(string $table, string $column, array $values): void
    {
        $existing = array_map('strval', array_column($this->selected($table, $column, $values), $column));
        foreach (array_unique(array_filter($values)) as $value) {
            if (!in_array((string)$value, $existing, true)) $this->anchors[$table][$column][] = $value;
        }
    }

    private function expandDependencies(): void
    {
        $seen = [];
        for ($pass = 0; $pass < 100; $pass++) {
            $changed = false;
            foreach ($this->links as $link) {
                $values = $this->anchors[$link['parent']][$link['parent_column']] ?? [];
                foreach ($this->scope[$link['parent']] ?? [] as $key => $_proof) $values[] = $this->candidates[$link['parent']][$key][$link['parent_column']] ?? null;
                foreach ($this->selected($link['table'], $link['column'], $values) as $row) {
                    $seenKey = $link['table'] . '|' . $this->key($link['table'], $row) . '|' . $link['constraint'];
                    if (isset($seen[$seenKey])) continue;
                    $seen[$seenKey] = true;
                    if (!empty($link['composite'])) $this->deny($link['table'], $row, 'Composite foreign-key relationship needs manual verification.');
                    elseif (str_starts_with($link['constraint'], 'Unconstrained related ID')) $this->remember($link['table'], $row, $this->marker($row) === $this->batchId ? 'Exact batch marker and related ID.' : null);
                    else $this->remember($link['table'], $row, $link['constraint'] . ': ' . $link['table'] . '.' . $link['column'] . ' -> ' . $link['parent'] . '.' . $link['parent_column']);
                    $changed = true;
                }
            }
            if (!$changed) return;
        }
        throw new RuntimeException('Dependency inspection did not converge. Deletion is disabled.');
    }

    private function protectAncestors(): void
    {
        for ($pass = 0; $pass < 100; $pass++) {
            $changed = false;
            foreach ($this->links as $link) foreach ($this->scope[$link['parent']] ?? [] as $parentKey => $_proof) {
                $parentRow = $this->candidates[$link['parent']][$parentKey];
                foreach ($this->candidates[$link['table']] ?? [] as $childKey => $child) {
                    if (!isset($this->scope[$link['table']][$childKey]) && (string)($child[$link['column']] ?? '') === (string)($parentRow[$link['parent_column']] ?? '')) {
                        $this->deny($link['parent'], $parentRow, 'Protected dependent row in ' . $link['table'] . ' prevents deleting this parent.');
                        $changed = true;
                        break;
                    }
                }
            }
            if (!$changed) return;
        }
        throw new RuntimeException('Protected dependency inspection did not converge. No deletion is enabled.');
    }

    /** Inspect constraints plus only the application relationships written by this CSV importer. */
    private function buildLinks(): void
    {
        $this->links = array_values(array_filter($this->schema['foreign_keys'], static fn($link) => empty($link['external'])));
        $constraintSizes = [];
        foreach ($this->links as $link) {
            $group = $link['table'] . '|' . $link['constraint'];
            $constraintSizes[$group] = ($constraintSizes[$group] ?? 0) + 1;
        }
        foreach ($this->links as &$link) $link['composite'] = $constraintSizes[$link['table'] . '|' . $link['constraint']] > 1;
        unset($link);
        foreach ([
            ['orders', 'customer_id', 'customers', 'customer_id'],
            ['order_items', 'order_id', 'orders', 'order_id'],
            ['customizations', 'order_id', 'orders', 'order_id'],
            ['customizations', 'order_item_id', 'order_items', 'order_item_id'],
            ['customizations', 'customer_id', 'customers', 'customer_id'],
            ['job_orders', 'order_id', 'orders', 'order_id'],
            ['job_orders', 'order_item_id', 'order_items', 'order_item_id'],
            ['job_orders', 'customer_id', 'customers', 'customer_id'],
            ['order_status_history', 'order_id', 'orders', 'order_id'],
        ] as [$table, $column, $parent, $parentColumn]) {
            if ($this->has($table, $column) && $this->has($parent, $parentColumn) && !$this->externalRelationship($table, $column)) $this->links[] = [
                'table' => $table, 'column' => $column, 'parent' => $parent, 'parent_column' => $parentColumn,
                'constraint' => 'CSV importer application relationship',
            ];
        }
        $unique = [];
        foreach ($this->links as $link) {
            $key = implode('|', [$link['table'], $link['column'], $link['parent'], $link['parent_column']]);
            if (!isset($unique[$key])) $unique[$key] = $link;
        }
        $this->links = array_values($unique);
    }

    public function preview(string $batchId, bool $verifiedOnly = false, bool $locking = false): array
    {
        if ($batchId === '' || trim($batchId) !== $batchId) throw new RuntimeException('Select an exact registry batch ID.');
        $this->batchId = $batchId;
        $this->locking = $locking;
        $this->schema = $this->db->schema();
        $this->candidates = $this->scope = $this->denied = $this->diagnostics = [];
        $this->anchors = [];
        $this->readCache = [];
        foreach (['demo_seed_batches', 'demo_seed_rows', 'orders'] as $required) {
            if (!isset($this->schema['tables'][$required])) throw new RuntimeException('Required registry/schema table is missing: ' . $required);
        }
        foreach (['demo_seed_batches', 'demo_seed_rows'] as $table) {
            if (!in_array(strtolower((string)$this->schema['tables'][$table]['engine']), ['innodb', 'sqlite'], true)) throw new RuntimeException('Seed registry and batch tables must support transactions: ' . $table);
        }
        foreach (['demo_seed_batches' => 'id', 'demo_seed_rows' => 'id', 'orders' => 'order_id', 'order_items' => 'order_item_id', 'customizations' => 'customization_id', 'job_orders' => 'id', 'customers' => 'customer_id'] as $table => $pk) {
            if (isset($this->schema['tables'][$table]) && $this->pk($table) !== $pk) throw new RuntimeException('CSV registry primary-key relationship needs review: ' . $table . '.' . $pk);
        }
        $batchRows = $this->selected('demo_seed_batches', 'batch_id', [$batchId]);
        $batch = array_values(array_filter($batchRows, static fn($row) => (string)$row['batch_id'] === $batchId))[0] ?? null;
        if (!$batch) throw new RuntimeException('The exact selected batch ID was not found in the seed registry.');
        $registry = $this->selected('demo_seed_rows', 'batch_id', [$batchId]);
        foreach ($registry as $row) if ((string)$row['batch_id'] !== $batchId) throw new RuntimeException('Registry batch IDs differ in case or whitespace; review them before deletion.');
        $this->buildLinks();

        $markerRows = [];
        foreach (['order_items' => 'customization_data', 'customizations' => 'customization_details'] as $table => $column) {
            foreach ($this->markedRows($table, $column) as $row) {
                $markerRows[$table][$this->key($table, $row)] = $row;
                $this->remember($table, $row);
            }
        }
        $orderIds = array_filter(array_column($registry, 'order_id'));
        foreach ($markerRows as $table => $rows) foreach ($rows as $row) {
            if (!empty($row['order_id']) && !$this->externalRelationship($table, 'order_id')) $orderIds[] = $row['order_id'];
            elseif ($this->externalRelationship($table, 'order_id')) $this->diagnostics[] = $table . ' has a cross-database order relationship; a matching number does not prove a local order is demo data.';
        }
        $this->anchorMissing('orders', 'order_id', $orderIds);
        foreach (['order_items' => 'order_item_id', 'customizations' => 'customization_id', 'job_orders' => 'job_order_id'] as $table => $registryColumn) {
            $pk = $this->pk($table);
            if ($pk) $this->anchorMissing($table, $pk, array_filter(array_column($registry, $registryColumn)));
        }
        foreach ($this->selected('orders', 'order_id', $orderIds) as $row) $this->remember('orders', $row, 'Exact registry order_id or exact hidden batch marker linked by the CSV importer.');

        // A conflicting registry or hidden marker is never repaired by guessing ownership.
        $conflictingOrderIds = [];
        foreach ($this->selected('demo_seed_rows', 'order_id', $orderIds) as $row) {
            if ((string)$row['batch_id'] !== $batchId) {
                $conflictingOrderIds[(string)$row['order_id']] = true;
                $this->anchors['orders']['order_id'] = array_values(array_filter($this->anchors['orders']['order_id'] ?? [], static fn($id) => (string)$id !== (string)$row['order_id']));
                $this->diagnostics[] = 'Order ID ' . $row['order_id'] . ' is claimed by another batch; ownership is unverified.';
                foreach ($this->selected('orders', 'order_id', [$row['order_id']]) as $order) $this->deny('orders', $order, 'Order is also registered to another batch.');
            }
        }
        foreach (['order_items' => 'order_item_id', 'customizations' => 'customization_id', 'job_orders' => 'job_order_id'] as $table => $registryColumn) {
            $pk = $this->pk($table);
            if ($pk === null) continue;
            $rows = array_merge($this->selected($table, $pk, array_filter(array_column($registry, $registryColumn))), $this->selected($table, 'order_id', $orderIds), array_values($markerRows[$table] ?? []));
            $this->selected('demo_seed_rows', $registryColumn, array_column($rows, $pk));
            foreach ($rows as $row) {
                $registered = in_array((string)$row[$pk], array_map('strval', array_filter(array_column($registry, $registryColumn))), true);
                $this->remember($table, $row);
                $marker = $this->marker($row);
                if (isset($conflictingOrderIds[(string)($row['order_id'] ?? '')])) {
                    $this->deny($table, $row, 'Original order is claimed by multiple batches.');
                } elseif ($marker !== null && $marker !== $batchId) {
                    $this->deny($table, $row, 'Conflicting hidden batch marker.');
                    foreach ($this->selected('orders', 'order_id', [$row['order_id'] ?? null]) as $order) $this->deny('orders', $order, 'A related row has a conflicting batch marker.');
                } elseif (!$this->isScoped('orders', 'order_id', $row['order_id'] ?? null)) {
                    if (($registered || $marker === $batchId) && $this->selected('orders', 'order_id', [$row['order_id'] ?? null]) === []) {
                        $this->remember($table, $row, $registered ? 'Exact registry ID; original order is absent.' : 'Exact hidden batch marker; original order is absent.');
                        $this->diagnostics[] = $table . ' ID ' . $row[$pk] . ' has no existing order; independently proven orphan is eligible only after review.';
                    } else $this->deny($table, $row, 'Order relationship is missing, conflicting, or belongs outside the verified batch.');
                } elseif ($registered || $marker === $batchId) {
                    $this->remember($table, $row, $registered ? 'Exact registry ' . $registryColumn . ' and verified order relationship.' : 'Exact hidden _seed_batch_id and verified order relationship.');
                }
                foreach ($this->selected('demo_seed_rows', $registryColumn, [$row[$pk]]) as $claim) {
                    if ((string)$claim['batch_id'] !== $batchId) $this->deny($table, $row, 'Record is also registered to another batch.');
                }
            }
        }

        // Follow actual foreign keys outward from verified rows.
        $this->expandDependencies();

        // Report matching application ID columns without treating their names as ownership proof.
        foreach ($this->schema['tables'] as $table => $definition) {
            if (in_array($table, ['demo_seed_batches', 'demo_seed_rows', 'activity_logs'], true)) continue;
            foreach (['order_id' => ['orders', 'order_id'], 'order_item_id' => ['order_items', 'order_item_id'], 'customization_id' => ['customizations', 'customization_id'], 'job_order_id' => ['job_orders', 'id']] as $column => [$parent, $parentColumn]) {
                if ($table === $parent || !$this->has($table, $column)) continue;
                $covered = array_filter($this->links, static fn($link) => $link['table'] === $table && $link['column'] === $column && $link['parent'] === $parent);
                if ($covered !== []) continue;
                $values = $this->anchors[$parent][$parentColumn] ?? [];
                foreach ($this->scope[$parent] ?? [] as $key => $_proof) $values[] = $this->candidates[$parent][$key][$parentColumn] ?? null;
                foreach ($this->selected($table, $column, $values) as $row) {
                    $this->remember($table, $row, $this->marker($row) === $batchId ? 'Exact batch marker and related ID.' : null);
                }
                $this->links[] = ['table' => $table, 'column' => $column, 'parent' => $parent, 'parent_column' => $parentColumn, 'constraint' => 'Unconstrained related ID (protected unless independently proven)'];
            }
        }

        // notifications.data_id is polymorphic: a matching number is not batch proof.
        if ($this->has('notifications', 'data_id')) {
            $notificationIds = array_merge($orderIds, array_filter(array_column($registry, 'job_order_id')));
            foreach ($this->selected('notifications', 'data_id', $notificationIds) as $row) {
                $key = $this->key('notifications', $row);
                if (isset($this->scope['notifications'][$key])) continue;
                if ($this->marker($row) === $batchId) $this->remember('notifications', $row, 'Exact batch marker on notification.');
                else $this->deny('notifications', $row, 'Matching polymorphic data_id is not ownership proof; notification is retained.');
            }
        }
        $this->expandDependencies();

        // A child that points at two different orders must not be removed via either parent.
        foreach ($this->scope as $table => $records) foreach ($records as $key => $_proof) {
            $row = $this->candidates[$table][$key];
            foreach ($this->links as $link) {
                if ($link['table'] !== $table || !in_array($link['parent'], ['orders', 'order_items', 'customizations', 'job_orders'], true)) continue;
                $value = $row[$link['column']] ?? null;
                if ($value !== null && !$this->isScoped($link['parent'], $link['parent_column'], $value) && $this->selected($link['parent'], $link['parent_column'], [$value]) !== []) $this->deny($table, $row, 'A related order/item/customization/job is outside the verified scope.');
            }
        }

        // Protect parents even for CASCADE and SET NULL.
        $this->protectAncestors();
        // Keep registry/marker witnesses when a missing parent still has an unverified dependent.
        $blockedAnchors = [];
        foreach ($this->links as $link) foreach ($this->anchors[$link['parent']][$link['parent_column']] ?? [] as $value) {
            foreach ($this->candidates[$link['table']] ?? [] as $childKey => $child) {
                if (!isset($this->scope[$link['table']][$childKey]) && (string)($child[$link['column']] ?? '') === (string)$value) $blockedAnchors[$link['parent']][$link['parent_column']][(string)$value] = true;
            }
        }
        foreach ($markerRows as $table => $rows) foreach ($rows as $row) {
            foreach ($this->links as $link) {
                if ($link['table'] === $table && isset($blockedAnchors[$link['parent']][$link['parent_column']][(string)($row[$link['column']] ?? '')])) $this->deny($table, $row, 'Hidden marker retained as evidence for an unverified dependent of a missing parent.');
            }
        }
        $this->protectAncestors();

        // Only customers explicitly created by the importer are candidates for removal.
        $customerIds = array_filter(array_column($registry, 'customer_id'));
        $this->selected('demo_seed_rows', 'customer_id', $customerIds);
        foreach ($this->schema['tables'] as $table => $_definition) {
            if ($table !== 'customers' && $this->has($table, 'customer_id')) $this->selected($table, 'customer_id', $customerIds);
        }
        foreach ($this->selected('customers', 'customer_id', array_filter(array_column($registry, 'customer_id'))) as $customer) {
            $customerId = $customer['customer_id'];
            $claims = $this->selected('demo_seed_rows', 'customer_id', [$customerId]);
            $created = $claims !== [];
            foreach ($claims as $claim) if ((string)$claim['batch_id'] !== $batchId || (int)($claim['customer_created'] ?? 0) !== 1) $created = false;
            $references = $this->selected('orders', 'customer_id', [$customerId]);
            $outside = array_filter($references, fn($row) => !$this->isScoped('orders', 'order_id', $row['order_id']));
            foreach ($this->schema['foreign_keys'] as $link) {
                if ($link['parent'] !== 'customers' || $link['table'] === 'demo_seed_rows') continue;
                foreach ($this->selected($link['table'], $link['column'], [$customer[$link['parent_column']]]) as $row) {
                    if (!isset($this->scope[$link['table']][$this->key($link['table'], $row)])) $outside[] = $row;
                }
            }
            foreach ($this->schema['tables'] as $relatedTable => $_definition) {
                if ($relatedTable === 'demo_seed_rows' || $relatedTable === 'customers' || !$this->has($relatedTable, 'customer_id')) continue;
                foreach ($this->selected($relatedTable, 'customer_id', [$customerId]) as $row) {
                    if (!isset($this->scope[$relatedTable][$this->key($relatedTable, $row)])) {
                        $outside[] = $row;
                        $this->remember($relatedTable, $row);
                    }
                }
            }
            if ($created && $outside === []) $this->remember('customers', $customer, 'Registry customer_created=1; no non-target orders or foreign-key references.');
            else $this->deny('customers', $customer, !$created ? 'Existing/shared customer or missing customer_created ownership proof.' : 'Customer has a non-target order or other protected relationship.');
        }

        foreach ($registry as $row) {
            $protectedReference = isset($conflictingOrderIds[(string)($row['order_id'] ?? '')]);
            foreach (['order_id' => 'orders', 'order_item_id' => 'order_items', 'customization_id' => 'customizations', 'job_order_id' => 'job_orders'] as $column => $table) {
                $pk = $this->pk($table);
                if ($pk && isset($blockedAnchors[$table][$pk][(string)($row[$column] ?? '')])) $protectedReference = true;
                foreach ($pk ? $this->selected($table, $pk, [$row[$column] ?? null]) : [] as $linked) {
                    if (!isset($this->scope[$table][$this->key($table, $linked)])) $protectedReference = true;
                }
                if (empty($row[$column])) $this->diagnostics[] = 'Seed ' . $row['seed_row_key'] . ' has no ' . $column . '; only independently verified rows are eligible.';
                elseif ($pk && $this->selected($table, $pk, [$row[$column]]) === []) $this->diagnostics[] = 'Seed ' . $row['seed_row_key'] . ': ' . $table . ' ID ' . $row[$column] . ' is already missing.';
            }
            if ($protectedReference) $this->deny('demo_seed_rows', $row, 'Registry retained to trace protected target records.');
            else $this->remember('demo_seed_rows', $row, 'Exact batch registry entry; linked existing targets are verified.');
        }
        $this->expandDependencies();
        $this->protectAncestors();
        foreach ($registry as $row) {
            foreach (['order_id' => 'orders', 'order_item_id' => 'order_items', 'customization_id' => 'customizations', 'job_order_id' => 'job_orders'] as $column => $table) {
                $pk = $this->pk($table);
                foreach ($pk ? $this->selected($table, $pk, [$row[$column] ?? null]) : [] as $linked) {
                    if (!isset($this->scope[$table][$this->key($table, $linked)])) $this->deny('demo_seed_rows', $row, 'Registry retained to trace protected target records.');
                }
            }
        }
        $this->protectAncestors();
        $expected = (int)($batch['total_orders'] ?? count($registry));
        if ($expected !== count($registry)) $this->diagnostics[] = 'Import total_orders=' . $expected . ', current registry_rows=' . count($registry) . '. This is a diagnostic, not ownership proof.';

        $tables = $protected = $counts = $ids = [];
        foreach ($this->candidates as $table => $rows) {
            $pk = $this->pk($table);
            foreach ($rows as $key => $row) {
                $entry = ['id' => $pk ? $row[$pk] : null, 'identity' => array_intersect_key($row, array_flip($this->schema['tables'][$table]['pk']))];
                if (isset($this->scope[$table][$key])) {
                    $entry['proof'] = $this->scope[$table][$key];
                    $tables[$table][] = $entry;
                } else {
                    $entry['reason'] = $this->denied[$table][$key] ?? 'No registry ID, exact marker, or verified foreign-key relationship proves ownership.';
                    $protected[$table][] = $entry;
                }
            }
        }
        foreach ($tables as $table => $records) { $counts[$table] = count($records); $ids[$table] = array_column($records, 'id'); }
        foreach ($ids as &$tableIds) sort($tableIds);
        unset($tableIds);
        foreach ($tables as &$records) usort($records, static fn($a, $b) => (string)$a['id'] <=> (string)$b['id']);
        unset($records);
        foreach ($protected as &$records) usort($records, static fn($a, $b) => (string)$a['id'] <=> (string)$b['id']);
        unset($records);
        ksort($tables); ksort($protected); ksort($counts); ksort($ids);
        $protectedTargets = [];
        foreach (['orders' => 'order_id', 'order_items' => 'order_item_id', 'customizations' => 'customization_id', 'job_orders' => 'job_order_id', 'demo_seed_rows' => 'id'] as $table => $registryColumn) {
            $registeredIds = array_map('strval', array_filter(array_column($registry, $registryColumn)));
            foreach ($protected[$table] ?? [] as $record) {
                $row = $this->candidates[$table][(string)$record['id']] ?? [];
                $isTarget = $table === 'demo_seed_rows'
                    || ($table === 'orders' && in_array((string)$record['id'], array_map('strval', $orderIds), true))
                    || ($table !== 'orders' && (in_array((string)$record['id'], $registeredIds, true) || $this->marker($row) === $batchId || in_array((string)($row['order_id'] ?? ''), array_map('strval', $orderIds), true)));
                if ($isTarget) $protectedTargets[$table][] = $record;
            }
        }
        $needsReview = $this->diagnostics !== [] || $protectedTargets !== [];
        $order = $this->dependencyOrder($tables);
        $remainingRegistry = count($protected['demo_seed_rows'] ?? []);
        $plan = [
            'batch_id' => $batchId, 'registry_rows' => count($registry), 'counts' => $counts,
            'tables' => $tables, 'ids' => $ids, 'protected' => $protected,
            'registry_records' => array_map(static fn($row) => array_intersect_key($row, array_flip(['id', 'seed_row_key', 'order_id', 'order_item_id', 'customization_id', 'job_order_id', 'customer_id', 'customer_created'])), $registry),
            'diagnostics' => array_values(array_unique($this->diagnostics)),
            'foreign_keys' => $this->schema['foreign_keys'], 'delete_order' => $order,
            'schema' => array_intersect_key($this->schema['tables'], array_flip(array_unique(array_merge(array_keys($tables), array_keys($protected), ['demo_seed_batches'])))),
            'verified_only' => $verifiedOnly, 'requires_verified_only' => $needsReview,
            'safe_to_delete' => $tables !== [] && (!$needsReview || $verifiedOnly),
            'remaining_registry_rows' => $remainingRegistry,
            'confirmation_phrase' => self::CONFIRMATION,
            'inventory_rows' => array_sum(array_map('count', array_filter($tables, static fn($key) => (bool)preg_match('/inventory|^inv_|material|ink_usage|transaction|ledger/i', $key), ARRAY_FILTER_USE_KEY))) + array_sum(array_map('count', array_filter($protected, static fn($key) => (bool)preg_match('/inventory|^inv_|material|ink_usage|transaction|ledger/i', $key), ARRAY_FILTER_USE_KEY))),
            'inventory_transaction_rows' => array_sum(array_map('count', array_filter($tables, static fn($key) => (bool)preg_match('/transaction|ledger|movement/i', $key), ARRAY_FILTER_USE_KEY))) + array_sum(array_map('count', array_filter($protected, static fn($key) => (bool)preg_match('/transaction|ledger|movement/i', $key), ARRAY_FILTER_USE_KEY))),
            'no_records' => $tables === [] && $protectedTargets === [],
        ];
        $plan['fingerprint'] = hash('sha256', json_encode($plan, JSON_THROW_ON_ERROR));
        return $plan;
    }

    public function batches(): array
    {
        $schema = $this->db->schema();
        if (!isset($schema['tables']['demo_seed_batches'], $schema['tables']['demo_seed_rows'])) return [];
        return $this->db->rows('SELECT b.batch_id, b.status, b.total_orders, b.imported_at, (SELECT COUNT(*) FROM demo_seed_rows r WHERE r.batch_id = b.batch_id) AS registry_rows FROM demo_seed_batches b ORDER BY b.imported_at DESC, b.id DESC');
    }

    private function dependencyOrder(array $tables): array
    {
        $pending = array_fill_keys(array_keys($tables), []);
        foreach ($this->links as $link) {
            if (!isset($pending[$link['table']], $pending[$link['parent']])) continue;
            $pending[$link['parent']][$link['table']] = true;
        }
        // Keep ownership metadata until the end unless an actual FK requires it earlier.
        if (isset($pending['demo_seed_rows'])) {
            $registryMustGoFirst = false;
            foreach ($pending as $dependencies) if (isset($dependencies['demo_seed_rows'])) $registryMustGoFirst = true;
            if (!$registryMustGoFirst) foreach (array_keys($pending) as $table) if ($table !== 'demo_seed_rows') $pending['demo_seed_rows'][$table] = true;
        }
        $order = [];
        while ($pending !== []) {
            $leaves = array_keys(array_filter($pending, static fn($dependencies) => $dependencies === []));
            if ($leaves === []) throw new RuntimeException('A foreign-key dependency cycle needs review. No deletion is enabled.');
            sort($leaves);
            foreach ($leaves as $leaf) {
                $order[] = $leaf;
                unset($pending[$leaf]);
                foreach ($pending as &$dependencies) unset($dependencies[$leaf]);
                unset($dependencies);
            }
        }
        return $order;
    }

    public static function authorize(string $role, bool $validCsrf, string $phrase, bool $backupConfirmed): void
    {
        if ($role !== 'Admin') throw new RuntimeException('Admin authorization required.');
        if (!$validCsrf) throw new RuntimeException('Invalid CSRF token.');
        if ($phrase !== self::CONFIRMATION) throw new RuntimeException('Type the exact phrase: ' . self::CONFIRMATION);
        if (!$backupConfirmed) throw new RuntimeException('Confirm a database backup exists before final deletion.');
    }

    private function audit(int $adminId, array $details): void
    {
        foreach (['user_id', 'action', 'details', 'created_at'] as $column) if (!$this->has('activity_logs', $column)) throw new RuntimeException('Required deletion audit column is missing: activity_logs.' . $column);
        if (!in_array(strtolower((string)$this->schema['tables']['activity_logs']['engine']), ['innodb', 'sqlite'], true)) throw new RuntimeException('Deletion audit table must support transactions.');
        $count = $this->db->write('INSERT INTO activity_logs (user_id, action, details, created_at) VALUES (?, ?, ?, ?)', [$adminId, 'Demo CSV Batch Deletion', json_encode($details, JSON_THROW_ON_ERROR), $details['ended_at']]);
        if ($count !== 1) throw new RuntimeException('Deletion audit was not written.');
    }

    public function delete(string $batchId, int $adminId, string $expectedFingerprint, bool $verifiedOnly): array
    {
        if ($adminId <= 0 || $expectedFingerprint === '') throw new RuntimeException('An authenticated admin and an unexpired dry run are required.');
        $startedAt = date('Y-m-d H:i:s');
        $counts = [];
        $this->db->begin();
        try {
            $plan = $this->preview($batchId, $verifiedOnly, true);
            if (!$plan['no_records'] && !hash_equals($expectedFingerprint, $plan['fingerprint'])) throw new RuntimeException('The batch or schema changed after the dry run. Run Dry Run again.');
            if (!$plan['no_records'] && !$plan['safe_to_delete']) throw new RuntimeException('Review the protected preview and select verified records only before deleting.');
            foreach ($plan['delete_order'] as $table) {
                $pk = $this->pk($table);
                foreach (array_chunk($plan['ids'][$table], 400) as $ids) {
                    $affected = $this->db->write('DELETE FROM ' . self::identifier($table) . ' WHERE ' . self::identifier((string)$pk) . ' IN (' . implode(',', array_fill(0, count($ids), '?')) . ')', $ids);
                    if ($affected !== count($ids)) throw new RuntimeException('Deleted count mismatch in ' . $table . '; transaction rolled back.');
                    $counts[$table] = ($counts[$table] ?? 0) + $affected;
                }
            }
            $this->readCache = [];
            foreach ($plan['ids'] as $table => $ids) if ($this->selected($table, (string)$this->pk($table), $ids) !== []) throw new RuntimeException('Target demo records remain in ' . $table . '; transaction rolled back.');
            // Preserve protected metadata and never mark a partial deletion as fully rolled back.
            $remaining = $this->preview($batchId, true, true);
            if (!$plan['no_records'] && $remaining['tables'] !== []) throw new RuntimeException('New verified demo records remain; transaction rolled back. Run Dry Run again.');
            $partial = !$remaining['no_records'];
            $this->db->write('UPDATE demo_seed_batches SET status = ?, rolled_back_by = ?, rolled_back_at = ? WHERE batch_id = ?', [$partial ? 'partial' : 'rolled_back', $adminId, $partial ? null : date('Y-m-d H:i:s'), $batchId]);
            $result = [
                'batch_id' => $batchId, 'started_at' => $startedAt, 'ended_at' => date('Y-m-d H:i:s'),
                'deleted_counts' => $counts, 'protected_counts' => array_map('count', $plan['protected']),
                'remaining_registry_rows' => $remaining['registry_rows'], 'partial' => $partial,
                'no_records' => $plan['no_records'], 'verified_rows_remaining' => 0,
            ];
            $this->audit($adminId, $result + ['failure_reason' => null]);
            $this->db->commit();
            return $result;
        } catch (Throwable $error) {
            $this->db->rollback();
            try {
                $this->audit($adminId, ['batch_id' => $batchId, 'started_at' => $startedAt, 'ended_at' => date('Y-m-d H:i:s'), 'deleted_counts' => [], 'protected_counts' => isset($plan) ? array_map('count', $plan['protected']) : [], 'failure_reason' => $error->getMessage(), 'rolled_back' => true]);
            } catch (Throwable $auditError) {
                error_log('[demo deletion audit] ' . $auditError->getMessage());
                throw new RuntimeException($error->getMessage() . ' Failure audit could not be written; review server logs.', 0, $error);
            }
            throw $error;
        }
    }
}

function demo_seed_deletion_tool(): DemoSeedDeletionTool
{
    global $conn;
    if (!$conn instanceof mysqli) throw new RuntimeException('Database connection is unavailable. No deletion can run.');
    return new DemoSeedDeletionTool(new DemoSeedMysqliDeletionDatabase($conn));
}
