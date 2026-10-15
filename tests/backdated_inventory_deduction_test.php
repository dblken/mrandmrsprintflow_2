<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/inventory_business_datetime.php';

$root = dirname(__DIR__);
$passed = 0;
$check = static function (bool $ok, string $label) use (&$passed): void {
    if (!$ok) {
        throw new RuntimeException('FAIL: ' . $label);
    }
    $passed++;
    echo 'PASS: ', $label, PHP_EOL;
};

$previousTz = date_default_timezone_get();
date_default_timezone_set('UTC');
$check(
    printflow_normalize_business_datetime('2026-09-07 07:10:00') === '2026-09-07 07:10:00',
    'backdated 2026-09-07 07:10:00 is preserved across a UTC process clock'
);
date_default_timezone_set('Asia/Manila');
$check(
    printflow_normalize_business_datetime('2026-09-07T07:10:00') === '2026-09-07 07:10:00',
    'Manila wall-clock datetime is not shifted'
);
$check(printflow_normalize_business_datetime('2026-09-07') === '2026-09-07 00:00:00', 'date-only values start at midnight');
$check(printflow_normalize_business_datetime('0000-00-00 00:00:00') === null, 'zero dates are rejected');
$check(printflow_normalize_business_datetime('2026-02-31 07:10:00') === null, 'impossible calendar values are rejected');
$check(
    printflow_ledger_datetime_range_bound('2026-09-07', false) === '2026-09-07 00:00:00'
        && printflow_ledger_datetime_range_bound('2026-09-07', true) === '2026-09-07 23:59:59',
    'a same-day ledger filter includes 07:10 instead of stopping at midnight'
);
$check(
    printflow_ledger_datetime_range_bound('2026-09-07 07:10:00', true) === '2026-09-07 07:10:00',
    'an exact datetime filter stays exact'
);
$boundary = printflow_normalize_business_datetime('2026-09-07 23:59:59');
$check($boundary === '2026-09-07 23:59:59', 'timezone boundary 23:59:59 stays on the selected day');
date_default_timezone_set($previousTz);

$change = (string)file_get_contents($root . '/includes/change_item_workflow.php');
$service = (string)file_get_contents($root . '/staff/api/service_order_api.php');
$rolls = (string)file_get_contents($root . '/includes/RollService.php');
$ledger = (string)file_get_contents($root . '/admin/inv_transactions_ledger.php');
$check(str_contains($change, '$businessTransactionAt'), 'change-item deductions receive the parent order datetime');
$check(str_contains($service, '$serviceBusinessAt'), 'service-material and ink deductions receive the service order datetime');
$check(
    str_contains($rolls, 'printflow_store_order_ledger_date($linkedOrderId)')
        && str_contains($rolls, '$transactionDate,'),
    'direct roll deductions write the linked order datetime'
);
$check(
    str_contains($ledger, 'printflow_ledger_datetime_range_bound((string)$start_date, false)')
        && str_contains($ledger, 'printflow_ledger_datetime_range_bound((string)$end_date, true)'),
    'ledger display uses the same datetime column with a full-day range'
);

$source = (string)file_get_contents($root . '/includes/JobOrderService.php');
$start = strpos($source, '    private static function processDeductions(');
$end = strpos($source, '    private static function syncLiveJobMaterialsIfNeeded(', $start);
$method = preg_replace(
    '/private static function processDeductions/',
    'public static function processDeductions',
    substr($source, $start, $end - $start),
    1
);

class BackdatedTestConnection {
    public bool $active = false;
    private array $snapshot = [];
    public function begin_transaction(): bool {
        $this->snapshot = [$GLOBALS['materials'], $GLOBALS['inks'], InventoryManager::$stock, InventoryManager::$ledger, InventoryManager::$rolls];
        return $this->active = true;
    }
    public function commit(): bool { $this->active = false; return true; }
    public function rollback(): bool {
        [$GLOBALS['materials'], $GLOBALS['inks'], InventoryManager::$stock, InventoryManager::$ledger, InventoryManager::$rolls] = $this->snapshot;
        $this->active = false;
        return true;
    }
}
class InventoryManager {
    public static float $stock = 10;
    public static array $ledger = [];
    public static array $rolls = ['remaining' => 30.0];
    public static bool $rollTracked = false;
    public static function getItem($id): array {
        return ['id' => $id, 'name' => 'Test material', 'track_by_roll' => self::$rollTracked, 'unit_of_measure' => 'ft'];
    }
    public static function getStockOnHand($id, $branch): float {
        return self::$rollTracked ? self::$rolls['remaining'] : self::$stock;
    }
    public static function issueStock($id, $qty, $uom, $ref, $refId, $notes, $ignore, $negative, $branch, $date): int {
        if ($qty > self::$stock) {
            throw new RuntimeException('Insufficient stock');
        }
        self::$stock -= $qty;
        self::$ledger[] = ['id' => $id, 'qty' => $qty, 'date' => $date, 'ref' => $ref, 'path' => 'issue'];
        return count(self::$ledger);
    }
}
class RollService {
    public static function deductFIFO($itemId, $length, $ref, $refId, $notes, $branch, $date = null): bool {
        if ($length > InventoryManager::$rolls['remaining']) {
            throw new RuntimeException('Insufficient roll');
        }
        InventoryManager::$rolls['remaining'] -= $length;
        InventoryManager::$ledger[] = ['id' => $itemId, 'qty' => $length, 'date' => $date, 'ref' => $ref, 'path' => 'roll'];
        return true;
    }
}
function printflow_db_in_transaction($conn): bool { return $conn->active; }
function printflow_db_transaction_depth_bump($conn): bool { return $conn->begin_transaction(); }
function printflow_db_transaction_depth_pop($conn, bool $commit = true): void {
    if ($commit) {
        $conn->commit();
    } else {
        $conn->rollback();
    }
}
function printflow_store_order_ledger_date($id): string { return $GLOBALS['order_date']; }
function printflow_get_job_inventory_reference($id): array { return ['label' => 'Test job']; }
function db_query($sql, $types = null, $params = []): array { return [['order_id' => 12149, 'id' => 4571]]; }
function db_execute($sql, $types, $params): bool {
    if (str_contains($sql, 'job_order_materials') && str_contains($sql, 'SET deducted_at = NOW()')) {
        foreach ($GLOBALS['materials'] as &$row) {
            if ((int)$row['id'] === (int)$params[0]) $row['deducted_at'] = '2026-10-13 10:12:57';
        }
    }
    if (str_contains($sql, 'job_order_ink_usage')) {
        foreach ($GLOBALS['inks'] as &$row) {
            if ((int)$row['id'] === (int)$params[0]) $row['deducted_at'] = '2026-10-13 10:12:57';
        }
    }
    return true;
}
eval('class BackdatedDeduction {
    private static function getJobBranchId($id) { return 2; }
    private static function assignmentIsMarkedDeducted($assignment) {
        $stamp = trim((string)($assignment["deducted_at"] ?? ""));
        return $stamp !== "" && $stamp !== "0000-00-00 00:00:00";
    }
    private static function findJobMaterialLedgerMovements($jobOrderId, $itemId, $branchId) { return []; }
    private static function assertMaterialAssignmentsDeductionIntegrity($jobOrderId, $branchId) {}
    private static function getScopedMaterials($id, $onlyUndeducted = true, $lock = true) {
        $rows = $GLOBALS["materials"];
        if ($onlyUndeducted) return array_values(array_filter($rows, fn($row) => $row["deducted_at"] === null));
        return $rows;
    }
    private static function getScopedInkUsage($id, $onlyUndeducted = true, $lock = true) {
        $rows = $GLOBALS["inks"];
        if ($onlyUndeducted) return array_values(array_filter($rows, fn($row) => $row["deducted_at"] === null));
        return $rows;
    }
    private static function convertInkMlToItemUom($ml, $item) { return (float)$ml; }
    private static function tableHasColumn($table, $column) { return true; }
    private static function rollMaterialSupersededByWiderAssignment($assignment, $materials, $itemsById) { return false; }
    private static function resolveRollMaterialLengthFt($assignment, $jobDimensionRow) {
        $computed = (float)($assignment["computed_required_length_ft"] ?? 0);
        if ($computed > 0) {
            return $computed;
        }
        return (float)($assignment["quantity"] ?? 0);
    }
    private static function decodeMaterialMetadata($raw) { return is_array($raw) ? $raw : []; }
    private static function markSupersededRollMaterialSkipped($id, array $meta) {}
' . $method . '}');

$conn = new BackdatedTestConnection();
$GLOBALS['order_date'] = '2026-09-07 07:10:00';
$GLOBALS['inks'] = [];
InventoryManager::$rollTracked = true;
InventoryManager::$rolls = ['remaining' => 30.0];
InventoryManager::$ledger = [];
$GLOBALS['materials'] = [[
    'id' => 607, 'item_id' => 16, 'quantity' => 3, 'computed_required_length_ft' => 18, 'uom' => 'ft', 'deducted_at' => null, 'metadata' => null,
]];
BackdatedDeduction::processDeductions(4571, ['materials' => true, 'inks' => false]);
$check(
    count(InventoryManager::$ledger) === 1
        && InventoryManager::$ledger[0]['date'] === '2026-09-07 07:10:00'
        && InventoryManager::$ledger[0]['path'] === 'roll'
        && InventoryManager::$rolls['remaining'] === 12.0,
    'backdated roll deduction records 2026-09-07 07:10:00 and the remaining length'
);
BackdatedDeduction::processDeductions(4571, ['materials' => true, 'inks' => false]);
$check(count(InventoryManager::$ledger) === 1 && InventoryManager::$rolls['remaining'] === 12.0, 'repeating completion does not deduct the roll again');

InventoryManager::$rollTracked = false;
InventoryManager::$stock = 10;
InventoryManager::$ledger = [];
$GLOBALS['materials'] = [['id' => 1, 'item_id' => 20, 'quantity' => 2, 'uom' => 'Pcs', 'deducted_at' => null, 'metadata' => null]];
$GLOBALS['inks'] = [['id' => 3, 'item_id' => 8, 'quantity_used' => 1, 'ink_color' => 'Cyan', 'deducted_at' => null]];
$GLOBALS['order_date'] = date('Y-m-d H:i:s');
BackdatedDeduction::processDeductions(4571, ['materials' => true, 'inks' => true]);
$check(
    count(InventoryManager::$ledger) === 2
        && InventoryManager::$ledger[0]['date'] === $GLOBALS['order_date']
        && InventoryManager::$ledger[1]['date'] === $GLOBALS['order_date']
        && InventoryManager::$stock === 7.0,
    'current-date material and ink deductions keep the order datetime'
);

InventoryManager::$stock = 1;
InventoryManager::$ledger = [];
$GLOBALS['materials'] = [['id' => 2, 'item_id' => 20, 'quantity' => 5, 'uom' => 'Pcs', 'deducted_at' => null, 'metadata' => null]];
$GLOBALS['inks'] = [];
$failed = false;
try {
    BackdatedDeduction::processDeductions(4571, ['materials' => true, 'inks' => false]);
} catch (RuntimeException $e) {
    $failed = true;
}
$check($failed && InventoryManager::$stock === 1.0 && InventoryManager::$ledger === [] && $GLOBALS['materials'][0]['deducted_at'] === null, 'insufficient stock rolls the ledger and deduction marker back');

echo "Backdated inventory deduction: {$passed} assertions passed.\n";
