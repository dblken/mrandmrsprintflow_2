<?php
declare(strict_types=1);
require_once __DIR__ . '/demo_seed_deletion.php';

/** Enforce SELECT-only access even if a future planner accidentally requests a write. */
final class DemoSeedReadOnlyDatabase implements DemoSeedDeletionDatabase
{
    public function __construct(private DemoSeedDeletionDatabase $db) {}
    public function rows(string $sql, array $params = []): array
    {
        if (!preg_match('/^SELECT\b/i', ltrim($sql)) || preg_match('/\bFOR\s+UPDATE\b|\bINTO\s+(OUTFILE|DUMPFILE)\b/i', $sql)) throw new RuntimeException('Audit permits SELECT queries only.');
        return $this->db->rows($sql, $params);
    }
    public function schema(): array { return $this->db->schema(); }
    public function write(string $sql, array $params = []): int { throw new RuntimeException('Audit cannot write.'); }
    public function begin(): void { throw new RuntimeException('Audit cannot start deletion transactions.'); }
    public function commit(): void { throw new RuntimeException('Audit cannot commit.'); }
    public function rollback(): void { throw new RuntimeException('Audit cannot roll back.'); }
    public function lockClause(): string { throw new RuntimeException('Audit cannot lock for deletion.'); }
}

final class DemoSeedReadOnlyAudit
{
    private DemoSeedReadOnlyDatabase $db;
    public function __construct(DemoSeedDeletionDatabase $db) { $this->db = new DemoSeedReadOnlyDatabase($db); }
    private static function identifier(string $name): string
    {
        if (!preg_match('/^[A-Za-z0-9_]+$/D', $name)) throw new RuntimeException('Unsupported schema identifier.');
        return '`' . $name . '`';
    }
    private static function markers(array $row): array
    {
        $values = [];
        foreach (['batch_id', 'seed_batch_id', '_seed_batch_id'] as $column) if (isset($row[$column])) $values[] = (string)$row[$column];
        foreach (['customization_data', 'customization_details', 'specifications', 'metadata', 'payload'] as $column) {
            $json = isset($row[$column]) && is_string($row[$column]) ? json_decode($row[$column], true) : null;
            if (is_array($json) && isset($json['_seed_batch_id'])) $values[] = (string)$json['_seed_batch_id'];
        }
        return array_values(array_unique(array_filter($values, static fn($value) => $value !== '')));
    }
    public function report(): array
    {
        $schema = $this->db->schema();
        $batches = isset($schema['tables']['demo_seed_batches']) ? $this->db->rows('SELECT * FROM demo_seed_batches ORDER BY imported_at DESC, id DESC') : [];
        $reports = [];
        foreach ($batches as $batch) {
            $batchId = (string)$batch['batch_id'];
            $counts = $records = $errors = [];
            foreach ($schema['tables'] as $table => $metadata) {
                if ($table === 'demo_seed_batches' || $table === 'activity_logs') continue;
                $counts[$table] = ['verified' => 0, 'protected_or_unverified' => 0, 'related_total' => 0, 'schema_present' => true];
            }
            $plan = null;
            try {
                $plan = (new DemoSeedDeletionTool($this->db))->preview($batchId);
                foreach (['tables' => 'verified', 'protected' => 'protected_or_unverified'] as $group => $category) {
                    foreach ($plan[$group] as $table => $rows) foreach ($rows as $row) {
                        $key = json_encode($row['identity'], JSON_THROW_ON_ERROR);
                        $records[$table][$key] = ['identity' => $row['identity'], 'classification' => $category, 'evidence' => $row['proof'] ?? $row['reason'] ?? ''];
                    }
                }
            } catch (Throwable $error) { $errors[] = $error->getMessage(); }
            // Find witnesses outside the two importer root tables, including orphan financial rows.
            foreach ($schema['tables'] as $table => $metadata) {
                if ($table === 'demo_seed_batches' || $table === 'activity_logs') continue;
                $columns = array_intersect($metadata['columns'], ['batch_id', 'seed_batch_id', '_seed_batch_id', 'customization_data', 'customization_details', 'specifications', 'metadata', 'payload']);
                foreach ($columns as $column) {
                    try {
                        $rows = $this->db->rows('SELECT * FROM ' . self::identifier($table) . ' WHERE ' . self::identifier($column) . ' LIKE ?', ['%' . $batchId . '%']);
                        foreach ($rows as $row) {
                            $markers = self::markers($row);
                            if (!in_array($batchId, $markers, true)) continue;
                            $identity = array_intersect_key($row, array_flip($metadata['pk']));
                            if ($identity === []) $identity = ['row_hash' => hash('sha256', json_encode($row, JSON_THROW_ON_ERROR))];
                            $key = json_encode($identity, JSON_THROW_ON_ERROR);
                            if (!isset($records[$table][$key])) $records[$table][$key] = ['identity' => $identity, 'classification' => 'protected_or_unverified', 'evidence' => count($markers) > 1 ? 'Conflicting exact batch markers. Unverified — Not Deleted.' : 'Exact batch marker found outside the deletion plan. Unverified — Not Deleted; relationships require review.'];
                        }
                    } catch (Throwable $error) { $errors[] = $table . '.' . $column . ': ' . $error->getMessage(); }
                }
            }
            foreach ($records as $table => $rows) foreach ($rows as $row) {
                $counts[$table][$row['classification']]++;
                $counts[$table]['related_total']++;
            }
            foreach (['demo_seed_rows', 'orders', 'order_items', 'customers', 'customizations', 'job_orders', 'payments', 'notifications', 'order_status_history', 'job_order_materials', 'inventory_transactions'] as $table) {
                if (!isset($counts[$table])) $counts[$table] = ['verified' => 0, 'protected_or_unverified' => 0, 'related_total' => 0, 'schema_present' => false];
            }
            ksort($counts);
            $remaining = array_sum(array_column($counts, 'related_total'));
            $reports[] = [
                'batch_id' => $batchId, 'database_batch_metadata' => array_intersect_key($batch, array_flip(['id', 'status', 'imported_at', 'total_orders', 'rolled_back_at'])),
                'registry_count' => $plan['registry_rows'] ?? null, 'per_table' => $counts,
                'remaining_related_records' => array_map('array_values', $records), 'registry_links' => $plan['registry_records'] ?? [],
                'deletion_status' => $errors ? 'Cannot Safely Verify' : ($remaining ? (in_array($batch['status'] ?? '', ['partial', 'rolled_back'], true) ? 'Partially Deleted' : 'Cannot Safely Verify') : 'Not Found'),
                'status_basis' => $remaining ? 'Exact registry/marker-linked records remain. Staff visibility and original totals need comparison before distinguishing Hidden Only from Partial.' : 'No discoverable related rows. This is not proof of complete deletion when original ID witnesses have been removed.',
                'safe_to_remove_dropdown_entry' => false,
                'diagnostics' => array_merge($plan['diagnostics'] ?? [], $errors),
                'coverage_limits' => ['Unmarked orphan rows cannot be attributed after registry and parent witnesses are removed; compare original CSV, pre-delete backup or retained exact-ID receipts.', 'Additional markers outside importer roots are reported for review; their unmarked descendants require a separate exact-ID relationship audit.', 'Shared/protected rows are included separately; related_total is not a deletable count or a global table count.', 'SELECTs are not a consistent snapshot during concurrent writes. Run against a read-only export or quiet maintenance window.', 'Staff visibility and normal workflow regression checks have not been exercised by this command.'],
            ];
        }
        return ['read_only' => true, 'generated_at' => gmdate('c'), 'batches_found' => count($batches), 'batches' => $reports];
    }
}
