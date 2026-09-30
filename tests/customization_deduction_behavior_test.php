<?php
declare(strict_types=1);

// Execute the real deduction method with an in-memory DB boundary. This is
// a behavior test, not production stock/ledger evidence.
$source = (string)file_get_contents(__DIR__ . '/../includes/JobOrderService.php');
$start = strpos($source, '    private static function processDeductions(');
$end = strpos($source, '    private static function syncLiveJobMaterialsIfNeeded(', $start);
$method = substr($source, $start, $end - $start);
$method = preg_replace('/private static function processDeductions/', 'public static function processDeductions', $method, 1);

class TestConnection {
    public bool $active = false;
    private array $snapshot = [];
    public function begin_transaction(): bool {
        $this->snapshot = [$GLOBALS['materials'], InventoryManager::$stock, InventoryManager::$ledger];
        return $this->active = true;
    }
    public function commit(): bool { $this->active = false; return true; }
    public function rollback(): bool {
        [$GLOBALS['materials'], InventoryManager::$stock, InventoryManager::$ledger] = $this->snapshot;
        $this->active = false;
        return true;
    }
}
class InventoryManager {
    public static float $stock = 10;
    public static array $ledger = [];
    public static function getItem($id): array { return ['id' => $id, 'name' => 'Test material', 'track_by_roll' => false]; }
    public static function getStockOnHand($id, $branch): float { return self::$stock; }
    public static function issueStock($id, $qty, $uom, $ref, $refId, $notes, $ignore, $negative, $branch, $date): int {
        if ($qty > self::$stock) throw new RuntimeException('Insufficient stock');
        self::$stock -= $qty;
        self::$ledger[] = compact('id', 'qty', 'branch', 'date', 'ref', 'refId');
        return count(self::$ledger);
    }
}
function printflow_db_in_transaction($conn): bool { return $conn->active; }
function printflow_store_order_ledger_date($id): string { return '2026-09-07 14:30:00'; }
function printflow_get_job_inventory_reference($id): array { return ['label' => 'Test job']; }
function db_query($sql, $types = null, $params = []): array { return [['order_id' => 11510, 'id' => 9]]; }
function db_execute($sql, $types, $params): bool {
    if (str_contains($sql, 'SET deducted_at = NOW()')) {
        foreach ($GLOBALS['materials'] as &$row) {
            if ($row['id'] === $params[0]) $row['deducted_at'] = '2026-09-30 13:00:00';
        }
    }
    return true;
}
eval('class DeductionUnderTest {
    private static function getJobBranchId($id) { return 2; }
    private static function assignmentIsMarkedDeducted($assignment) {
        $stamp = trim((string)($assignment["deducted_at"] ?? ""));
        return $stamp !== "" && $stamp !== "0000-00-00 00:00:00";
    }
    private static function findJobMaterialLedgerMovements($jobOrderId, $itemId, $branchId) {
        foreach (InventoryManager::$ledger as $row) {
            if ((int)$row["refId"] === (int)$jobOrderId && (int)$row["id"] === 42) {
                return [["id" => 1, "transaction_date" => $row["date"]]];
            }
        }
        return [];
    }
    private static function assertMaterialAssignmentsDeductionIntegrity($jobOrderId, $branchId) {
        foreach ($GLOBALS["materials"] as $assignment) {
            if (!self::assignmentIsMarkedDeducted($assignment)) {
                continue;
            }
            if (self::findJobMaterialLedgerMovements($jobOrderId, (int)$assignment["item_id"], $branchId) === []) {
                throw new RuntimeException("inconsistent deducted_at without ledger");
            }
        }
    }
    private static function getScopedMaterials($id, $onlyUndeducted = true, $lock = true) {
        $rows = $GLOBALS["materials"];
        if ($onlyUndeducted) {
            return array_values(array_filter($rows, fn($row) => $row["deducted_at"] === null));
        }
        return $rows;
    }
' . $method . '}');

function fixture(array $quantities): void {
    InventoryManager::$stock = 10;
    InventoryManager::$ledger = [];
    $GLOBALS['materials'] = [];
    foreach ($quantities as $index => $qty) {
        $GLOBALS['materials'][] = ['id' => $index + 1, 'item_id' => 42, 'quantity' => $qty, 'uom' => 'Pcs', 'deducted_at' => null];
    }
}
function check(bool $ok, string $label): void {
    if (!$ok) throw new RuntimeException($label);
    echo 'PASS: ', $label, PHP_EOL;
}
$conn = new TestConnection();
fixture([2]);
DeductionUnderTest::processDeductions(9, ['materials' => true, 'inks' => false]);
check(InventoryManager::$stock === 8.0 && count(InventoryManager::$ledger) === 1, 'stock 10 -> 8 and one ledger entry');
check(InventoryManager::$ledger[0]['date'] === '2026-09-07 14:30:00' && InventoryManager::$ledger[0]['branch'] === 2, 'parent datetime and branch reach inventory writer');
check($materials[0]['deducted_at'] === '2026-09-30 13:00:00', 'processing timestamp remains separate');
DeductionUnderTest::processDeductions(9, ['materials' => true, 'inks' => false]);
check(InventoryManager::$stock === 8.0 && count(InventoryManager::$ledger) === 1, 'repeat deduction makes no second movement');
foreach ([[2, 20], [0]] as $quantities) {
    fixture($quantities);
    $failed = false;
    try { DeductionUnderTest::processDeductions(9, ['materials' => true, 'inks' => false]); }
    catch (RuntimeException $e) { $failed = true; }
    check($failed && InventoryManager::$stock === 10.0 && InventoryManager::$ledger === [] && $materials[0]['deducted_at'] === null, 'invalid/insufficient quantity rolls back stock, ledger and deduction marker');
}
fixture([2]);
$materials[0]['deducted_at'] = '2026-09-30 12:00:00';
$failed = false;
try { DeductionUnderTest::processDeductions(9, ['materials' => true, 'inks' => false]); }
catch (RuntimeException $e) { $failed = true; }
check($failed && InventoryManager::$stock === 10.0 && InventoryManager::$ledger === [], 'inconsistent deducted_at without ledger blocks completion without double deduct');
