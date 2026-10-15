<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/json_endpoint.php';
printflow_json_endpoint_bootstrap();
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/order_archive.php';

header('Cache-Control: no-store, private');
require_role('Admin');
if (!is_admin()) printflow_json_response(['success' => false, 'error' => 'Admin authorization is required.'], 403);

function pf_pending_hide_json(array $body, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

function pf_pending_hide_dates(string $start, string $end): array
{
    $zone = new DateTimeZone('Asia/Manila');
    $from = DateTimeImmutable::createFromFormat('!Y-m-d', $start, $zone);
    $to = DateTimeImmutable::createFromFormat('!Y-m-d', $end, $zone);
    $errors = DateTimeImmutable::getLastErrors();
    if (!$from || !$to || ($errors && ($errors['warning_count'] || $errors['error_count']))
        || $from->format('Y-m-d') !== $start || $to->format('Y-m-d') !== $end || $from > $to) {
        throw new InvalidArgumentException('Enter a valid start and end date, with the start date no later than the end date.');
    }
    return [$from->format('Y-m-d 00:00:00'), $to->modify('+1 day')->format('Y-m-d 00:00:00')];
}

function pf_pending_hide_eligible_sql(): string
{
    return "o.order_date >= ? AND o.order_date < ?
        AND BINARY TRIM(COALESCE(o.status, '')) = 'Pending'
        AND BINARY TRIM(COALESCE(o.payment_status, '')) = 'Unpaid'
        AND COALESCE(o.is_archived, 0) = 0
        AND TRIM(COALESCE(o.payment_reference, '')) = ''
        AND TRIM(COALESCE(o.payment_proof_path, '')) = ''
        AND TRIM(COALESCE(o.payment_proof, '')) = ''
        AND o.payment_submitted_at IS NULL
        AND NOT EXISTS (SELECT 1 FROM payment_submissions ps WHERE ps.order_id=o.order_id
            OR ps.job_order_id IN (SELECT j.id FROM job_orders j WHERE j.order_id=o.order_id))
        AND NOT EXISTS (SELECT 1 FROM provider_payments pp WHERE pp.order_id=o.order_id
            OR (pp.subject_type='order' AND pp.subject_id=o.order_id)
            OR pp.job_order_id IN (SELECT j2.id FROM job_orders j2 WHERE j2.order_id=o.order_id))
        AND NOT EXISTS (SELECT 1 FROM job_orders jo WHERE jo.order_id=o.order_id
            AND COALESCE(UPPER(REPLACE(TRIM(jo.status),' ','_')),'') NOT IN ('PENDING','PENDING_APPROVAL'))
        AND NOT EXISTS (SELECT 1 FROM customizations cu WHERE cu.order_id=o.order_id
            AND UPPER(REPLACE(TRIM(COALESCE(cu.status,'')),' ','_')) NOT IN ('PENDING','PENDING_REVIEW','PENDING_APPROVAL'))
        AND NOT EXISTS (SELECT 1 FROM inventory_movements im WHERE UPPER(TRIM(im.reference_type))='ORDER'
            AND TRIM(im.reference_id)=CAST(o.order_id AS CHAR))
        AND NOT EXISTS (SELECT 1 FROM inventory_transactions it WHERE UPPER(TRIM(it.ref_type)) IN ('ORDER','JOB_ORDER')
            AND (it.ref_id=o.order_id OR (UPPER(TRIM(it.ref_type))='JOB_ORDER' AND it.ref_id IN
                (SELECT jo2.id FROM job_orders jo2 WHERE jo2.order_id=o.order_id))))
        AND NOT EXISTS (SELECT 1 FROM receipt_print_jobs rp WHERE rp.order_id=o.order_id)
        AND NOT EXISTS (SELECT 1 FROM printflow_order_archive_map am WHERE am.order_id=o.order_id)
        AND NOT EXISTS (SELECT 1 FROM historical_generator_hidden_orders hh WHERE hh.order_id=o.order_id)";
}

function pf_pending_hide_candidates(string $from, string $until, bool $lock = false): array
{
    $sql = "SELECT o.order_id, o.order_date, o.customer_id, o.branch_id, o.total_amount, o.status, o.payment_status,
                   CONCAT_WS(' ', NULLIF(c.first_name,''), NULLIF(c.last_name,'')) AS customer_name
            FROM orders o LEFT JOIN customers c ON c.customer_id=o.customer_id
            WHERE " . pf_pending_hide_eligible_sql() . " ORDER BY o.order_date DESC, o.order_id DESC" . ($lock ? ' FOR UPDATE' : '');
    // db_query() returns [] on SQL errors; a silent empty preview must never be mistaken for "no matches".
    $errorsBefore = function_exists('printflow_db_errors') ? count(printflow_db_errors()) : 0;
    $rows = db_query($sql, 'ss', [$from, $until]) ?: [];
    $errors = function_exists('printflow_db_errors') ? printflow_db_errors() : [];
    if (count($errors) > $errorsBefore) {
        $last = $errors[count($errors) - 1];
        throw new RuntimeException('Eligibility query failed: ' . (string)($last['error'] ?? 'database error') . ' (errno ' . (int)($last['errno'] ?? 0) . ')');
    }
    return $rows;
}

try {
    if (!printflow_order_archive_tables_ready()) pf_pending_hide_json(['success' => false, 'error' => 'Order archive storage is unavailable.'], 503);
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
        [$from, $until] = pf_pending_hide_dates((string)($_GET['start_date'] ?? '2026-09-01'), (string)($_GET['end_date'] ?? '2026-10-15'));
        $rows = pf_pending_hide_candidates($from, $until);
        $ids = array_map(static fn(array $row): int => (int)$row['order_id'], $rows);
        $token = bin2hex(random_bytes(24));
        $_SESSION['pf_pending_hide_preview'] = ['token' => $token, 'from' => $from, 'until' => $until, 'ids' => $ids, 'expires' => time() + 600];
        pf_pending_hide_json(['success' => true, 'start_date' => substr($from,0,10), 'end_date' => (new DateTimeImmutable($until, new DateTimeZone('Asia/Manila')))->modify('-1 day')->format('Y-m-d'), 'count' => count($ids), 'order_ids' => $ids, 'orders' => $rows, 'preview_token' => $token, 'csrf_token' => generate_csrf_token()]);
    }
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') pf_pending_hide_json(['success' => false, 'error' => 'Method not allowed.'], 405);
    $body = json_decode((string)file_get_contents('php://input'), true);
    if (!is_array($body) || !verify_csrf_token((string)($body['csrf_token'] ?? ''))) pf_pending_hide_json(['success' => false, 'error' => 'The request token is invalid or expired.'], 403);
    [$from, $until] = pf_pending_hide_dates((string)($body['start_date'] ?? ''), (string)($body['end_date'] ?? ''));
    $preview = $_SESSION['pf_pending_hide_preview'] ?? null;
    if (!is_array($preview) || (int)($preview['expires'] ?? 0) < time() || !hash_equals((string)($preview['token'] ?? ''), (string)($body['preview_token'] ?? ''))
        || $preview['from'] !== $from || $preview['until'] !== $until) pf_pending_hide_json(['success' => false, 'error' => 'The preview expired. Refresh the preview before applying the hide action.'], 409);

    global $conn;
    if (!$conn || !$conn->begin_transaction()) throw new RuntimeException('Could not start archive transaction.');
    $started = true;
    $rows = pf_pending_hide_candidates($from, $until, true);
    $ids = array_map(static fn(array $row): int => (int)$row['order_id'], $rows);
    if ($ids !== $preview['ids']) throw new RuntimeException('The eligible order set changed after preview. Refresh and review it again.');
    $actor = (int)get_user_id();
    if ($actor < 1 || get_user_type() !== 'Admin') throw new RuntimeException('Admin identity could not be verified.');
    $batch = 'pf_temp_pending_' . gmdate('Ymd_His') . '_' . bin2hex(random_bytes(5));
    $reason = 'Temporary Pending/Unpaid hide; Manila dates ' . substr($from,0,10) . ' through ' . (new DateTimeImmutable($until, new DateTimeZone('Asia/Manila')))->modify('-1 day')->format('Y-m-d');
    foreach ($ids as $id) {
        $res = db_execute("INSERT INTO printflow_order_archive_map (order_id,archive_batch_id,archived_by,archived_at,reason,status) VALUES (?,?,?,NOW(),?,'archived')", 'isis', [$id,$batch,$actor,$reason]);
        if ($res === false) throw new RuntimeException('Archive mapping write failed.');
        $details = json_encode(['reason'=>$reason], JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE) ?: '{}';
        if (db_execute("INSERT INTO printflow_order_archive_events (archive_batch_id,order_id,action,actor_user_id,event_at,details_json) VALUES (?,?,'archive',?,NOW(),?)", 'siis', [$batch,$id,$actor,$details]) === false) throw new RuntimeException('Archive audit event write failed.');
    }
    $manifest = json_encode($ids, JSON_UNESCAPED_SLASHES) ?: '[]';
    $notes = 'Only exact Pending/Unpaid orders without payment submissions, provider payments, active production, inventory movement, receipt, or prior archive rows were mapped. No transactional order or customer data was modified.';
    $counts = json_encode(['orders'=>count($ids)], JSON_UNESCAPED_SLASHES) ?: '{}';
    // Audit row stores the inclusive Manila range end (same convention as archived_orders_manifest.php).
    $rangeEndInclusive = (new DateTimeImmutable($until, new DateTimeZone('Asia/Manila')))->modify('-1 second')->format('Y-m-d H:i:s');
    if (db_execute("INSERT INTO printflow_order_archive_batch_events (archive_batch_id,actor_user_id,event_at,date_range_start,date_range_end,manifest_order_count,manifest_order_ids,protected_record_notes,operation_result,related_record_counts) VALUES (?,?,NOW(),?,?,?,?,?,'archived',?)", 'sississs', [$batch,$actor,$from,$rangeEndInclusive,count($ids),$manifest,$notes,$counts]) === false) throw new RuntimeException('Archive batch audit write failed.');
    if (!$conn->commit()) throw new RuntimeException('Archive transaction commit failed.');
    $started = false;
    unset($_SESSION['pf_pending_hide_preview']);
    pf_pending_hide_json(['success'=>true,'batch_id'=>$batch,'count'=>count($ids),'order_ids'=>$ids]);
} catch (InvalidArgumentException $e) {
    pf_pending_hide_json(['success'=>false,'error'=>$e->getMessage()],400);
} catch (Throwable $e) {
    if (!empty($started) && isset($conn) && $conn) $conn->rollback();
    $ref = bin2hex(random_bytes(6));
    error_log('[temporary pending archive]['.$ref.'] '.get_class($e).': '.$e->getMessage());
    pf_pending_hide_json(['success'=>false,'error'=>'The archive action failed. Reference: '.$ref],500);
}
