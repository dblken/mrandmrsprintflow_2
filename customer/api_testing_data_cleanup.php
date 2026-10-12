<?php
declare(strict_types=1);

/** Temporary, Jenny-only data cleanup. This endpoint never removes uploaded files. */
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

const PF_JENNY_CLEANUP_CUSTOMER_ID = 25;
const PF_JENNY_CLEANUP_DELETE_ORDER = ['push_notification_queue','notifications','message_reactions','review_helpful','customer_cart','order_revision_requests','order_item_revisions','job_order_materials','job_orders','order_messages','customizations','order_items','orders'];
const PF_JENNY_CLEANUP_DIAGNOSTIC_VERSION = 'jenny-cleanup-runtime-20261012-7';

/** Runtime-only diagnostics; never includes connection credentials. */
function pf_jenny_runtime_diagnostics(): array
{
    global $conn;
    $databaseName = null;
    $connectionHost = null;
    if ($conn instanceof mysqli) {
        $connectionHost = (string)$conn->host_info;
        try {
            $result = $conn->query('SELECT DATABASE() AS database_name');
            if ($result instanceof mysqli_result) {
                $row = $result->fetch_assoc();
                $databaseName = (string)($row['database_name'] ?? '');
                $result->free();
            }
        } catch (Throwable $ignored) {
            $databaseName = null;
        }
    }
    $opcacheStatus = function_exists('opcache_get_status') ? @opcache_get_status(false) : false;
    return [
        'database_name' => $databaseName,
        'database_host' => $connectionHost,
        'opcache_enabled' => (bool)ini_get('opcache.enable'),
        'endpoint_cached' => function_exists('opcache_is_script_cached') ? @opcache_is_script_cached(__FILE__) : null,
        'opcache_status_available' => is_array($opcacheStatus),
    ];
}

/** Temporary diagnostics deliberately exclude SQL text, values, exception messages, and customer data. */
function pf_jenny_log_stage(string $stage, string $table = 'unknown', string $phase = 'request', string $errorType = 'none'): void
{
    error_log('jenny_cleanup_debug ' . json_encode([
        'stage' => preg_replace('/[^a-z0-9_.-]/i', '', $stage),
        'customer_id' => PF_JENNY_CLEANUP_CUSTOMER_ID,
        'table' => preg_match('/^[A-Za-z0-9_]+$/D', $table) ? $table : 'unknown',
        'query_phase' => preg_replace('/[^a-z0-9_.-]/i', '', $phase),
        'error_type' => preg_replace('/[^a-z0-9_.-]/i', '', $errorType),
    ], JSON_UNESCAPED_SLASHES));
}

function pf_jenny_json(int $status, array $payload): never
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function pf_jenny_ranges(array $spec): array
{
    $ids = [];
    foreach ($spec as $entry) {
        if (is_int($entry)) $ids[] = $entry;
        elseif (is_array($entry) && count($entry) === 2 && (int)$entry[0] <= (int)$entry[1]) {
            for ($id = (int)$entry[0]; $id <= (int)$entry[1]; $id++) $ids[] = $id;
        }
    }
    return array_values(array_unique($ids));
}

function pf_jenny_audited_order_id_spec(): array
{
    return [[11034,11035],11037,11042,11045,11054,[11087,11102],[11104,11108],11115,[11124,11138],11145,11149,11151,11153,[11156,11161],11175,11189,11239,11241,11284,11302,11309,11327];
}

/** Execute one diagnostic COUNT query without exposing SQL or row contents. */
function pf_jenny_diagnostic_count(string $step, string $table, string $sql, string $types = '', array $params = []): array
{
    global $conn;
    $entry = ['query_step'=>$step,'table'=>$table,'row_count'=>null,'database_error_code'=>null,'error_type'=>'none','sanitized_error_message'=>''];
    $statement = null;
    try {
        if (!($conn instanceof mysqli)) throw new RuntimeException('Database connection is unavailable.');
        $statement = $conn->prepare($sql);
        if (!$statement) {
            $entry['database_error_code'] = (int)$conn->errno;
            $entry['error_type'] = 'mysqli_prepare_error';
            $entry['sanitized_error_message'] = pf_jenny_sanitized_db_message((int)$conn->errno);
            return $entry;
        }
        if ($types !== '') {
            $arguments = [$types];
            foreach ($params as &$value) $arguments[] = &$value;
            unset($value);
            call_user_func_array([$statement,'bind_param'],$arguments);
        }
        if (!$statement->execute()) {
            $errno=(int)$statement->errno;
            $entry['database_error_code']=$errno;
            $entry['error_type']='mysqli_execute_error';
            $entry['sanitized_error_message']=pf_jenny_sanitized_db_message($errno);
            return $entry;
        }
        $rowCount=0;
        if (!$statement->bind_result($rowCount) || !$statement->fetch()) {
            $errno=(int)$statement->errno;
            $entry['database_error_code']=$errno;
            $entry['error_type']='mysqli_fetch_error';
            $entry['sanitized_error_message']=pf_jenny_sanitized_db_message($errno);
            return $entry;
        }
        $entry['row_count']=(int)$rowCount;
    } catch (Throwable $e) {
        $errno=$statement instanceof mysqli_stmt ? (int)$statement->errno : (int)($conn instanceof mysqli ? $conn->errno : 0);
        $entry['database_error_code']=$errno;
        $entry['error_type']=(new ReflectionClass($e))->getShortName();
        $entry['sanitized_error_message']=pf_jenny_sanitized_db_message($errno);
    } finally {
        if ($statement instanceof mysqli_stmt) $statement->close();
    }
    return $entry;
}

/** Mirror the application's buffered SELECT result path while returning only the row count. */
function pf_jenny_diagnostic_select_rows(string $step, string $table, string $sql, string $types, array $params): array
{
    global $conn;
    $entry=['query_step'=>$step,'table'=>$table,'row_count'=>null,'database_error_code'=>null,'error_type'=>'none','sanitized_error_message'=>''];
    $statement=null;
    try {
        if(!($conn instanceof mysqli))throw new RuntimeException('Database connection is unavailable.');
        $statement=$conn->prepare($sql);
        if(!$statement){$errno=(int)$conn->errno;$entry['database_error_code']=$errno;$entry['error_type']='mysqli_prepare_error';$entry['sanitized_error_message']=pf_jenny_sanitized_db_message($errno);return $entry;}
        $arguments=[$types];foreach($params as &$value)$arguments[]=&$value;unset($value);
        call_user_func_array([$statement,'bind_param'],$arguments);
        if(!$statement->execute()){$errno=(int)$statement->errno;$entry['database_error_code']=$errno;$entry['error_type']='mysqli_execute_error';$entry['sanitized_error_message']=pf_jenny_sanitized_db_message($errno);return $entry;}
        $result=$statement->get_result();
        if(!($result instanceof mysqli_result)){$errno=(int)$statement->errno;$entry['database_error_code']=$errno;$entry['error_type']='mysqli_get_result_error';$entry['sanitized_error_message']=pf_jenny_sanitized_db_message($errno);return $entry;}
        $count=0;while($result->fetch_assoc()!==null)$count++;
        $result->free();$entry['row_count']=$count;
    }catch(Throwable $e){$errno=$statement instanceof mysqli_stmt?(int)$statement->errno:(int)($conn instanceof mysqli?$conn->errno:0);$entry['database_error_code']=$errno;$entry['error_type']=(new ReflectionClass($e))->getShortName();$entry['sanitized_error_message']=pf_jenny_sanitized_db_message($errno);}
    finally{if($statement instanceof mysqli_stmt)$statement->close();}
    return $entry;
}

function pf_jenny_sanitized_db_message(int $errno): string
{
    return match ($errno) {
        1054 => 'Schema column is unavailable.',
        1064 => 'Database rejected the read-only query syntax.',
        1146 => 'Required table is unavailable.',
        1205 => 'Database lock wait timed out.',
        1213 => 'Database reported a deadlock.',
        2002,2003,2006,2013 => 'Database connection failed or was interrupted.',
        default => $errno === 0 ? 'Read-only diagnostic step failed before a database error code was available.' : 'Read-only database query failed.',
    };
}

function pf_jenny_orders_diagnostic(): array
{
    $orderIds=pf_jenny_ranges(pf_jenny_audited_order_id_spec());
    $batchSize=25;
    $batches=array_chunk($orderIds,$batchSize);
    $steps=[];
    $batchQuery=static function(string $name,string $table,string $where,string $types,array $tail=[]) use ($batches,&$steps): void {
        foreach($batches as $index=>$ids){
            $marks=implode(',',array_fill(0,count($ids),'?'));
            $steps[]=pf_jenny_diagnostic_count($name.'_batch_'.($index+1),$table,'SELECT COUNT(*) AS row_count FROM `'.$table.'` WHERE order_id IN ('.$marks.')'.$where,str_repeat('i',count($ids)).$types,array_merge($ids,$tail));
        }
    };
    foreach($batches as $index=>$ids){
        $marks=implode(',',array_fill(0,count($ids),'?'));
        $steps[]=pf_jenny_diagnostic_select_rows('order_select_star_result_fetch_batch_'.($index+1),'orders','SELECT * FROM orders WHERE order_id IN ('.$marks.') ORDER BY order_id',str_repeat('i',count($ids)),$ids);
        $steps[]=pf_jenny_diagnostic_select_rows('customer_owned_order_select_star_result_fetch_batch_'.($index+1),'orders','SELECT * FROM orders WHERE order_id IN ('.$marks.') AND customer_id = 25 ORDER BY order_id',str_repeat('i',count($ids)),$ids);
        $steps[]=pf_jenny_diagnostic_count('order_id_selection_batch_'.($index+1),'orders','SELECT COUNT(*) AS row_count FROM orders WHERE order_id IN ('.$marks.')',str_repeat('i',count($ids)),$ids);
        $steps[]=pf_jenny_diagnostic_count('customer_ownership_validation_batch_'.($index+1),'orders','SELECT COUNT(*) AS row_count FROM orders WHERE order_id IN ('.$marks.') AND customer_id = 25',str_repeat('i',count($ids)),$ids);
        $steps[]=pf_jenny_diagnostic_count('protected_order_classification_batch_'.($index+1),'orders','SELECT COUNT(*) AS row_count FROM orders WHERE order_id IN ('.$marks.') AND (COALESCE(payment_status,?) <> ? OR COALESCE(downpayment_amount,0) <> 0 OR COALESCE(payment_proof_path,?) <> ? OR COALESCE(payment_proof,?) <> ?)',str_repeat('i',count($ids)).'ssssss',array_merge($ids,['','Unpaid','','','','']));
        $steps[]=pf_jenny_diagnostic_count('safe_order_classification_batch_'.($index+1),'orders','SELECT COUNT(*) AS row_count FROM orders WHERE order_id IN ('.$marks.') AND customer_id = 25 AND COALESCE(payment_status,?) = ? AND COALESCE(downpayment_amount,0) = 0 AND COALESCE(payment_proof_path,?) = ? AND COALESCE(payment_proof,?) = ?',str_repeat('i',count($ids)).'ssssss',array_merge($ids,['','Unpaid','','','','']));
        foreach(['order_items'=>'order_id','order_messages'=>'order_id','customizations'=>'order_id','job_orders'=>'order_id'] as $child=>$fk){
            $extra=$child==='job_orders'?' AND customer_id = 25':'';
            $steps[]=pf_jenny_diagnostic_count('child_record_lookup_'.$child.'_batch_'.($index+1),$child,'SELECT COUNT(*) AS row_count FROM `'.$child.'` WHERE `'.$fk.'` IN ('.$marks.')'.$extra,str_repeat('i',count($ids)),$ids);
        }
        $steps[]=pf_jenny_diagnostic_count('protected_inventory_ledger_job_reference_batch_'.($index+1),'inventory_transactions','SELECT COUNT(*) AS row_count FROM inventory_transactions WHERE ref_type = ? AND ref_id IN (SELECT id FROM job_orders WHERE customer_id = 25 AND order_id IN ('.$marks.'))','s'.str_repeat('i',count($ids)),array_merge(['JOB_ORDER'],$ids));
    }
    // Exercise each protected order-reference table separately, only when its relationship column exists.
    foreach(['payment_submissions','provider_payments','inventory_transactions','printflow_order_archive_map','maintenance_synthetic_demo_order_rows','order_status_history','receipt_print_jobs','provider_payment_status_history','provider_webhook_events'] as $table){
        $schema=pf_jenny_diagnostic_count('protected_reference_schema_'.$table,$table,'SELECT COUNT(*) AS row_count FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?','ss',[$table,'order_id']);
        $steps[]=$schema;
        if($schema['row_count']===1) $batchQuery('protected_reference_lookup_'.$table,$table,'','',[]);
    }
    $steps[]=pf_jenny_diagnostic_count('customer_order_count_verification','orders','SELECT COUNT(*) AS row_count FROM orders WHERE customer_id = 25');
    return $steps;
}

function pf_jenny_ids(string $table, string $pk, array $ids, string $extra = '', string $types = '', array $params = [], bool $lock = false): array
{
    global $conn;
    if (!$ids) return [];
    foreach ([$table, $pk] as $name) if (!preg_match('/^[A-Za-z0-9_]+$/D', $name)) throw new RuntimeException('Invalid cleanup schema identifier.');
    $marks = implode(',', array_fill(0, count($ids), '?'));
    $sql = "SELECT * FROM `{$table}` WHERE `{$pk}` IN ({$marks})" . $extra . ' ORDER BY `' . $pk . '`' . ($lock ? ' FOR UPDATE' : '');
    $bindTypes = str_repeat('i', count($ids)) . $types;
    return pf_jenny_query($sql, $bindTypes, array_merge($ids, $params));
}

function pf_jenny_in_sql(string $column, array $ids): array
{
    if (!$ids) return ['1=0', '', []];
    if (!preg_match('/^[A-Za-z0-9_]+$/D', $column)) throw new RuntimeException('Invalid cleanup relationship column.');
    return ['`' . $column . '` IN (' . implode(',', array_fill(0, count($ids), '?')) . ')', str_repeat('i', count($ids)), $ids];
}

function pf_jenny_query(string $sql, string $types = '', array $params = []): array
{
    $before = count($GLOBALS['printflow_db_errors'] ?? []);
    preg_match('/\b(?:FROM|JOIN|UPDATE|INTO)\s+`?([A-Za-z0-9_]+)`?/i', $sql, $tableMatch);
    $table = $tableMatch[1] ?? 'unknown';
    $GLOBALS['pf_jenny_cleanup_context'] = ['table' => $table, 'phase' => 'execute'];
    try {
        $rows = db_query($sql, $types, $params);
    } catch (Throwable $e) {
        $GLOBALS['pf_jenny_cleanup_context'] = [
            'table' => $table,
            'phase' => 'db_query_exception',
            'exception_type' => (new ReflectionClass($e))->getShortName(),
            'exception_code' => (int)$e->getCode(),
            'db_sqlstate' => $e instanceof mysqli_sql_exception
                ? preg_replace('/[^A-Z0-9]/i', '', (string)$e->getSqlState())
                : '',
        ];
        pf_jenny_log_stage($GLOBALS['pf_jenny_cleanup_request_stage'] ?? 'query', $table, 'db_query_exception', (new ReflectionClass($e))->getShortName());
        throw new RuntimeException('A database query raised an exception; cleanup is disabled.', 0, $e);
    }
    if (!is_array($rows) || count($GLOBALS['printflow_db_errors'] ?? []) > $before) {
        $errors = $GLOBALS['printflow_db_errors'] ?? [];
        $latest = $errors ? end($errors) : [];
        $GLOBALS['pf_jenny_cleanup_context'] = [
            'table' => $table,
            'phase' => 'database_query_failure',
            'db_error_stage' => preg_replace('/[^a-z0-9_.-]/i', '', (string)($latest['stage'] ?? 'unknown')),
            'db_errno' => (int)($latest['errno'] ?? 0),
            'db_sqlstate' => preg_replace('/[^A-Z0-9]/i', '', (string)($latest['sqlstate'] ?? '')),
        ];
        pf_jenny_log_stage($GLOBALS['pf_jenny_cleanup_request_stage'] ?? 'query', $table, (string)($latest['stage'] ?? 'execute'), 'database_query_failure');
        throw new RuntimeException('A database query failed; cleanup is disabled.');
    }
    return $rows;
}

function pf_jenny_count(string $sql): int
{
    $rows = pf_jenny_query($sql);
    if (count($rows) !== 1 || !array_key_exists('row_count', $rows[0])) throw new RuntimeException('Could not verify protected-record counts.');
    return (int)$rows[0]['row_count'];
}

function pf_jenny_child_ids(string $table, string $pk, string $fk, array $parentIds, bool $lock = false): array
{
    if (!$parentIds) return [];
    foreach ([$table,$pk,$fk] as $name) if (!preg_match('/^[A-Za-z0-9_]+$/D',$name)) throw new RuntimeException('Invalid child relationship.');
    $rows=pf_jenny_query('SELECT `' . $pk . '` FROM `' . $table . '` WHERE `' . $fk . '` IN (' . implode(',',array_fill(0,count($parentIds),'?')) . ') ORDER BY `' . $pk . '`' . ($lock?' FOR UPDATE':''),str_repeat('i',count($parentIds)),$parentIds);
    return array_map('intval',array_column($rows,$pk));
}

function pf_jenny_other_customer_snapshot(bool $lock = false): array
{
    $rows=pf_jenny_query('SELECT customer_id FROM customers WHERE customer_id <> 25 ORDER BY customer_id LIMIT 1' . ($lock?' FOR UPDATE':''));
    if (!$rows) throw new RuntimeException('Could not select an independent customer verification record.');
    $id=(int)$rows[0]['customer_id'];
    $suffix=$lock?' FOR UPDATE':'';
    $snapshot=['customer_id'=>$id];
    foreach ([
        'orders'=>['SELECT order_id FROM orders WHERE customer_id = ? ORDER BY order_id','i',[$id]],
        'cart'=>['SELECT product_id,variant_id,quantity FROM customer_cart WHERE customer_id = ? ORDER BY product_id,variant_id','i',[$id]],
        'notifications'=>['SELECT notification_id FROM notifications WHERE customer_id = ? ORDER BY notification_id','i',[$id]],
        'provider_payments'=>['SELECT id FROM provider_payments WHERE customer_id = ? ORDER BY id','i',[$id]],
        'payment_submissions'=>['SELECT id FROM payment_submissions WHERE customer_id = ? ORDER BY id','i',[$id]],
    ] as $name=>[$sql,$types,$params]) $snapshot[$name]=pf_jenny_query($sql.$suffix,$types,$params);
    return $snapshot;
}

function pf_jenny_plan(bool $lock = false): array
{
    // These are the exact IDs from the completed read-only ownership audit.
    $spec = [
        'orders' => pf_jenny_audited_order_id_spec(),
        'order_items' => [12566,12577,12580,12593,[12638,12639],12647,[12665,12666],[12675,12677],12684,12688,12690,12692,[12695,12700],12714,12728,12780,12782,12829,12847,12854,12874],
        'order_messages' => [[1276,1279],1284,1304,[1335,1355],1357,[1389,1395],[1398,1405],1407,[1410,1421],[1424,1425],1433,[1466,1467],[1535,1536],[1539,1541],1594,1602,[1608,1619],1621,[1644,1645],1652],
        'customizations' => [[2126,2128],[2136,2140],2147,2151,2153,2155,[2158,2163],2177,2191,2228,2230,2253,2256],
        'job_orders' => [3649,3651,3661,3664,3675,[3699,3719],3726,[3733,3739],[3741,3748],[3752,3761],3768,3782,3822,3824,3847,3851],
        'job_order_materials' => [458,488],
        'order_item_revisions' => [1,2],
        'order_revision_requests' => [1,2],
        'message_reactions' => [36,37,38,39],
        'review_helpful' => [18,27,29,33,[39,41],[43,45],[49,52]],
    ];
    $ids = [];
    foreach ($spec as $table => $ranges) $ids[$table] = pf_jenny_ranges($ranges);

    $auditedOrderIds = $ids['orders'];
    $orders = [];
    foreach (array_chunk($auditedOrderIds, 25) as $orderBatch) {
        $orders = array_merge($orders, pf_jenny_ids('orders', 'order_id', $orderBatch, ' AND customer_id = 25', '', [], $lock));
    }
    $actualOrderIds = array_map(static fn($r) => (int)$r['order_id'], $orders);
    $GLOBALS['pf_jenny_cleanup_context'] = ['table' => 'orders', 'phase' => 'ownership_validation'];
    if ($actualOrderIds !== $auditedOrderIds) {
        $GLOBALS['pf_jenny_cleanup_context']['expected_count'] = count($auditedOrderIds);
        $GLOBALS['pf_jenny_cleanup_context']['actual_count'] = count($actualOrderIds);
        $GLOBALS['pf_jenny_cleanup_context']['mismatch_count'] = count(array_diff($auditedOrderIds, $actualOrderIds)) + count(array_diff($actualOrderIds, $auditedOrderIds));
        throw new RuntimeException('The audited safe order set changed or ownership no longer matches. Refresh preview.');
    }
    $protectedOrderIds=[];
    foreach(array_chunk($auditedOrderIds,25) as $orderBatch){
        $marks=implode(',',array_fill(0,count($orderBatch),'?'));
        $protectedRows=pf_jenny_query('SELECT order_id FROM orders WHERE order_id IN ('.$marks.') AND (COALESCE(payment_status,?) <> ? OR COALESCE(downpayment_amount,0) <> 0 OR COALESCE(payment_proof_path,?) <> ? OR COALESCE(payment_proof,?) <> ?) ORDER BY order_id',str_repeat('i',count($orderBatch)).'ssssss',array_merge($orderBatch,['','Unpaid','','','','']));
        $protectedOrderIds=array_merge($protectedOrderIds,array_map('intval',array_column($protectedRows,'order_id')));
    }
    $ids['protected_order_ids']=$protectedOrderIds;
    $ids['orders']=array_values(array_diff($auditedOrderIds,$protectedOrderIds));
    $orderIds = $ids['orders'];
    $itemsAll = pf_jenny_ids('order_items', 'order_item_id', $ids['order_items'], ' AND order_id IN (' . implode(',', array_fill(0,count($auditedOrderIds),'?')) . ')', str_repeat('i',count($auditedOrderIds)), $auditedOrderIds, $lock);
    if (array_map(static fn($r)=>(int)$r['order_item_id'],$itemsAll) !== $ids['order_items']) throw new RuntimeException('The audited order-item set or parent relationship changed. Refresh preview.');
    if (pf_jenny_child_ids('order_items','order_item_id','order_id',$auditedOrderIds,$lock) !== $ids['order_items']) throw new RuntimeException('An unclassified item is linked to an audited order.');
    $safeOrderLookup=array_fill_keys($orderIds,true);
    $items=array_values(array_filter($itemsAll,static fn($r)=>isset($safeOrderLookup[(int)$r['order_id']])));
    $ids['order_items']=array_map(static fn($r)=>(int)$r['order_item_id'],$items);
    foreach ([['order_messages','message_id'],['customizations','customization_id']] as [$table,$pk]) {
        $allRows = pf_jenny_ids($table,$pk,$ids[$table],' AND order_id IN (' . implode(',',array_fill(0,count($auditedOrderIds),'?')) . ')',str_repeat('i',count($auditedOrderIds)),$auditedOrderIds,$lock);
        if (array_map(static fn($r)=>(int)$r[$pk],$allRows) !== $ids[$table]) throw new RuntimeException('The audited ' . $table . ' set or order relationship changed. Refresh preview.');
        if (pf_jenny_child_ids($table,$pk,'order_id',$auditedOrderIds,$lock) !== $ids[$table]) throw new RuntimeException('An unclassified child is linked to an audited order.');
        $rows[$table]=array_values(array_filter($allRows,static fn($r)=>isset($safeOrderLookup[(int)$r['order_id']])));
        $ids[$table]=array_map(static fn($r)=>(int)$r[$pk],$rows[$table]);
    }
    $jobsAll = pf_jenny_ids('job_orders','id',$ids['job_orders'],' AND customer_id = 25 AND order_id IN (' . implode(',',array_fill(0,count($auditedOrderIds),'?')) . ')',str_repeat('i',count($auditedOrderIds)),$auditedOrderIds,$lock);
    if (array_map(static fn($r)=>(int)$r['id'],$jobsAll) !== $ids['job_orders']) throw new RuntimeException('The audited job-order set or ownership changed. Refresh preview.');
    if (pf_jenny_child_ids('job_orders','id','order_id',$auditedOrderIds,$lock) !== $ids['job_orders']) throw new RuntimeException('An unclassified job order is linked to an audited order.');
    $jobs=array_values(array_filter($jobsAll,static fn($r)=>isset($safeOrderLookup[(int)$r['order_id']])));
    $ids['job_orders']=array_map(static fn($r)=>(int)$r['id'],$jobs);
    $jobIds = $ids['job_orders'];
    if (pf_jenny_query('SELECT 1 FROM job_orders WHERE id IN (' . implode(',',array_fill(0,count($jobIds),'?')) . ') AND (COALESCE(payment_status,?) <> ? OR COALESCE(amount_paid,0) <> 0 OR COALESCE(payment_proof_path,?) <> ? OR COALESCE(payment_reference,?) <> ? OR COALESCE(payment_submitted_amount,0) <> 0 OR COALESCE(payment_proof_status,?) <> ? OR payment_verified_at IS NOT NULL) LIMIT 1' . ($lock?' FOR UPDATE':''),str_repeat('i',count($jobIds)).'ssssssss',array_merge($jobIds,['','UNPAID','','','','','','NONE']))) throw new RuntimeException('An audited job order contains payment state or proof.');
    $allJobIds=array_map(static fn($r)=>(int)$r['id'],$jobsAll);
    $safeJobLookup=array_fill_keys($jobIds,true);
    $materialsAll = pf_jenny_ids('job_order_materials','id',$ids['job_order_materials'],' AND job_order_id IN (' . implode(',',array_fill(0,count($allJobIds),'?')) . ') AND deducted_at IS NULL',str_repeat('i',count($allJobIds)),$allJobIds,$lock);
    if (array_map(static fn($r)=>(int)$r['id'],$materialsAll) !== $ids['job_order_materials']) throw new RuntimeException('The audited undeducted material set changed. Refresh preview.');
    $materials=array_values(array_filter($materialsAll,static fn($r)=>isset($safeJobLookup[(int)$r['job_order_id']])));
    $ids['job_order_materials']=array_map(static fn($r)=>(int)$r['id'],$materials);
    $auditedRevisionIds=$ids['order_item_revisions'];
    $auditedRevisionRequestIds=$ids['order_revision_requests'];
    $revisionsAll = pf_jenny_ids('order_item_revisions','revision_id',$auditedRevisionIds,' AND order_id IN (' . implode(',',array_fill(0,count($auditedOrderIds),'?')) . ')',str_repeat('i',count($auditedOrderIds)),$auditedOrderIds,$lock);
    $revisionRequestsAll = pf_jenny_ids('order_revision_requests','revision_request_id',$auditedRevisionRequestIds,' AND customer_id = 25 AND order_id IN (' . implode(',',array_fill(0,count($auditedOrderIds),'?')) . ')',str_repeat('i',count($auditedOrderIds)),$auditedOrderIds,$lock);
    $revisions=array_values(array_filter($revisionsAll,static fn($r)=>isset($safeOrderLookup[(int)$r['order_id']])));
    $revisionRequests=array_values(array_filter($revisionRequestsAll,static fn($r)=>isset($safeOrderLookup[(int)$r['order_id']])));
    $ids['order_item_revisions']=array_map(static fn($r)=>(int)$r['revision_id'],$revisions);
    $ids['order_revision_requests']=array_map(static fn($r)=>(int)$r['revision_request_id'],$revisionRequests);
    if (array_map(static fn($r)=>(int)$r['revision_id'],$revisionsAll) !== $auditedRevisionIds) throw new RuntimeException('The audited revision set changed. Refresh preview.');
    if (array_map(static fn($r)=>(int)$r['revision_request_id'],$revisionRequestsAll) !== $auditedRevisionRequestIds) throw new RuntimeException('The audited revision-request set changed. Refresh preview.');
    // Chatbot data is excluded from cleanup. Read its IDs for the protected preview only.
    $chatbotConversationRows = pf_jenny_query('SELECT id FROM chatbot_conversations WHERE customer_id = 25 ORDER BY id');
    $chatbotConversationIds = array_map('intval',array_column($chatbotConversationRows,'id'));
    $chatbotMessageRows = $chatbotConversationIds
        ? pf_jenny_query('SELECT m.id FROM chatbot_messages m INNER JOIN chatbot_conversations c ON c.id = m.conversation_id WHERE c.customer_id = 25 ORDER BY m.id')
        : [];
    $chatbotExcluded = [
        'chatbot_conversations' => $chatbotConversationIds,
        'chatbot_messages' => array_map('intval',array_column($chatbotMessageRows,'id')),
    ];

    $ownedNotifications = pf_jenny_query('SELECT notification_id FROM notifications n WHERE customer_id = 25 AND (user_id IS NULL OR user_id = 25) AND NOT EXISTS (SELECT 1 FROM notification_logs nl WHERE nl.notification_id = n.notification_id) ORDER BY notification_id' . ($lock?' FOR UPDATE':''));
    $ids['notifications'] = array_map('intval', array_column($ownedNotifications, 'notification_id'));
    $notifications = pf_jenny_ids('notifications','notification_id',$ids['notifications'],' AND customer_id = 25 AND (user_id IS NULL OR user_id = 25)','',[],$lock);
    if (count($notifications) !== count($ids['notifications'])) throw new RuntimeException('A notification has conflicting owner references. Refresh preview.');
    $notificationIds = $ids['notifications'];
    $queueRows = [];
    if ($notificationIds) {
        $queueRows = pf_jenny_query('SELECT id FROM push_notification_queue WHERE notification_id IN (' . implode(',',array_fill(0,count($notificationIds),'?')) . ') AND user_id = 25 AND user_type = ? ORDER BY id' . ($lock?' FOR UPDATE':''),str_repeat('i',count($notificationIds)).'s',array_merge($notificationIds,['Customer']));
    }
    $ids['push_notification_queue'] = array_map('intval',array_column($queueRows,'id'));
    if ($notificationIds) {
        $allQueue=pf_jenny_query('SELECT id,user_id,user_type FROM push_notification_queue WHERE notification_id IN (' . implode(',',array_fill(0,count($notificationIds),'?')) . ') ORDER BY id' . ($lock?' FOR UPDATE':''),str_repeat('i',count($notificationIds)),$notificationIds);
        if (count($allQueue)!==count($queueRows)) throw new RuntimeException('A protected push queue row is linked to a customer notification.');
    }
    $ownedCart = pf_jenny_query('SELECT product_id FROM customer_cart WHERE customer_id = 25 AND variant_id = 0 AND quantity = 1 AND product_id IN (46,47,49,58,64) ORDER BY product_id' . ($lock?' FOR UPDATE':''));
    $ids['customer_cart'] = array_map('intval',array_column($ownedCart,'product_id'));
    if ($ids['customer_cart'] !== [46,47,49,58,64]) throw new RuntimeException('The audited cart set changed. Refresh preview.');
    $reactionIdsAll = pf_jenny_ranges($spec['message_reactions']);
    $reactionsAll = pf_jenny_ids('message_reactions','reaction_id',$reactionIdsAll,' AND sender = ? AND sender_id = 25','s',['Customer'],$lock);
    if (array_map(static fn($r)=>(int)$r['reaction_id'],$reactionsAll) !== $reactionIdsAll) throw new RuntimeException('The audited customer reactions changed. Refresh preview.');
    $safeMessageLookup=array_fill_keys($ids['order_messages'],true);
    $reactions=array_values(array_filter($reactionsAll,static fn($r)=>isset($safeMessageLookup[(int)$r['message_id']])));
    $ids['message_reactions']=array_map(static fn($r)=>(int)$r['reaction_id'],$reactions);
    $helpful = pf_jenny_ids('review_helpful','id',$ids['review_helpful'],' AND customer_id = 25 AND user_id = 25 AND user_type = ?','s',['Customer'],$lock);
    if (count($helpful)!==14) throw new RuntimeException('The audited helpful-vote ownership changed. Refresh preview.');

    // Recheck protected financial, inventory, archive, synthetic, and receipt links before each preview/deletion.
    $protectedChecks = [
        'payment_submissions' => ['order_id','orderIds'], 'provider_payments' => ['order_id','orderIds'],
        'inventory_transactions' => ['order_id','orderIds'], 'printflow_order_archive_map' => ['order_id','orderIds'],
        'maintenance_synthetic_demo_order_rows' => ['order_id','orderIds'], 'order_status_history' => ['order_id','orderIds'],
        'receipt_print_jobs' => ['order_id','orderIds'],
    ];
    $jobTableChecks = [
        'payment_submissions' => 'job_order_id', 'provider_payments' => 'job_order_id',
        'inventory_transactions' => 'job_order_id', 'job_order_ink_usage' => 'job_order_id',
        'receipt_print_jobs' => 'job_order_id', 'change_item_requests' => 'job_order_id',
    ];
    foreach ($jobTableChecks as $table => $column) {
        if (!db_table_has_column($table,$column)) continue;
        $marks=implode(',',array_fill(0,count($jobIds),'?'));
        if (pf_jenny_query("SELECT 1 FROM `{$table}` WHERE `{$column}` IN ({$marks}) LIMIT 1".($lock?' FOR UPDATE':''),str_repeat('i',count($jobIds)),$jobIds)) throw new RuntimeException('An audited job order now has a protected ' . $table . ' reference. Refresh preview.');
    }
    foreach ($protectedChecks as $table => [$column,$unused]) {
        if (!db_table_has_column($table,$column)) continue;
        $marks=implode(',',array_fill(0,count($orderIds),'?'));
        if (pf_jenny_query("SELECT 1 FROM `{$table}` WHERE `{$column}` IN ({$marks}) LIMIT 1".($lock?' FOR UPDATE':''),str_repeat('i',count($orderIds)),$orderIds)) throw new RuntimeException('An audited order now has a protected ' . $table . ' reference. Refresh preview.');
    }
    foreach (['provider_payment_status_history','provider_webhook_events'] as $table) {
        if (db_table_has_column($table,'order_id') && pf_jenny_query("SELECT 1 FROM `{$table}` WHERE order_id IN (".implode(',',array_fill(0,count($orderIds),'?')).') LIMIT 1'.($lock?' FOR UPDATE':''),str_repeat('i',count($orderIds)),$orderIds)) throw new RuntimeException('Protected payment history exists.');
    }
    if (pf_jenny_query('SELECT 1 FROM orders WHERE order_id IN (' . implode(',',array_fill(0,count($orderIds),'?')) . ') AND (COALESCE(payment_status,?) <> ? OR COALESCE(downpayment_amount,0) <> 0 OR COALESCE(payment_proof_path,?) <> ? OR COALESCE(payment_proof,?) <> ?) LIMIT 1' . ($lock?' FOR UPDATE':''),str_repeat('i',count($orderIds)).'ssssss',array_merge($orderIds,['','Unpaid','','','','']))) throw new RuntimeException('An audited order contains payment state or proof.');

    $safeMessageIds = $ids['order_messages'];
    if ($safeMessageIds && pf_jenny_query('SELECT 1 FROM message_reactions WHERE message_id IN (' . implode(',',array_fill(0,count($safeMessageIds),'?')) . ') AND (sender <> ? OR sender_id <> 25 OR reaction_id NOT IN (' . implode(',',array_fill(0,count($ids['message_reactions']),'?')) . ')) LIMIT 1' . ($lock?' FOR UPDATE':''),str_repeat('i',count($safeMessageIds)).'s'.str_repeat('i',count($ids['message_reactions'])),array_merge($safeMessageIds,['Customer'],$ids['message_reactions']))) throw new RuntimeException('A protected reaction is linked to an audited order message.');
    $allSafeJobMaterials = pf_jenny_query('SELECT id FROM job_order_materials WHERE job_order_id IN (' . implode(',',array_fill(0,count($jobIds),'?')) . ') ORDER BY id' . ($lock?' FOR UPDATE':''),str_repeat('i',count($jobIds)),$jobIds);
    if (array_map(static fn($r)=>(int)$r['id'],$allSafeJobMaterials) !== $ids['job_order_materials']) throw new RuntimeException('An unclassified material row is linked to a safe job order.');

    $parentIds=['orders'=>$orderIds,'order_items'=>$ids['order_items'],'customizations'=>$ids['customizations'],'job_orders'=>$jobIds,'order_messages'=>$ids['order_messages']];
    $childPk=['orders'=>'order_id','order_items'=>'order_item_id','customizations'=>'customization_id','job_orders'=>'id','order_messages'=>'message_id','order_revision_requests'=>'revision_request_id','order_item_revisions'=>'revision_id','job_order_materials'=>'id','message_reactions'=>'reaction_id'];
    $foreignKeys=pf_jenny_query("SELECT TABLE_NAME,COLUMN_NAME,REFERENCED_TABLE_NAME FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE WHERE CONSTRAINT_SCHEMA=DATABASE() AND REFERENCED_TABLE_SCHEMA=DATABASE() AND REFERENCED_TABLE_NAME IN ('orders','order_items','customizations','job_orders','order_messages')");
    foreach($foreignKeys as $fk){
        $child=(string)$fk['TABLE_NAME']; $parent=(string)$fk['REFERENCED_TABLE_NAME']; $column=(string)$fk['COLUMN_NAME']; $pids=$parentIds[$parent]??[];
        if(!$pids)continue;
        foreach([$child,$column] as $identifier)if(!preg_match('/^[A-Za-z0-9_]+$/D',$identifier))throw new RuntimeException('Unsupported foreign-key schema.');
        if(!isset($childPk[$child])){
            $unknown=pf_jenny_query('SELECT 1 FROM `'.$child.'` WHERE `'.$column.'` IN ('.implode(',',array_fill(0,count($pids),'?')).') LIMIT 1'.($lock?' FOR UPDATE':''),str_repeat('i',count($pids)),$pids);
            if($unknown)throw new RuntimeException('An unclassified child table references a safe record: '.$child.'.');
            continue;
        }
        $matching=pf_jenny_query('SELECT `'.$childPk[$child].'` FROM `'.$child.'` WHERE `'.$column.'` IN ('.implode(',',array_fill(0,count($pids),'?')).') ORDER BY `'.$childPk[$child].'`'.($lock?' FOR UPDATE':''),str_repeat('i',count($pids)),$pids);
        if(!$matching)continue;
        $known=array_map('strval',$ids[$child]??[]);
        foreach($matching as $linked)if(!in_array((string)$linked[$childPk[$child]],$known,true))throw new RuntimeException('An unclassified foreign-key child references a safe record: '.$child.'.');
    }

    // These relationship-bearing tables were empty in the audit. Fail closed if a row appeared.
    foreach (['order_designs'=>['order_id',$orderIds], 'order_tarp_details'=>['order_item_id',$ids['order_items']], 'change_item_requests'=>['order_id',$orderIds], 'receipt_print_jobs'=>['order_id',$orderIds]] as $table=>[$column,$parentIds]) {
        if (db_table_has_column($table,$column) && $parentIds && pf_jenny_query("SELECT 1 FROM `{$table}` WHERE `{$column}` IN (".implode(',',array_fill(0,count($parentIds),'?')).') LIMIT 1'.($lock?' FOR UPDATE':''),str_repeat('i',count($parentIds)),$parentIds)) throw new RuntimeException('An unclassified child appeared in ' . $table . '. Refresh and review before deletion.');
    }
    $safeOrderSql=implode(',',$ids['orders']);
    $protectedJobSubquery="SELECT id FROM job_orders WHERE customer_id = 25 AND order_id IN (SELECT order_id FROM orders WHERE customer_id = 25 AND order_id NOT IN ({$safeOrderSql}))";
    $ids['protected_counts'] = [
        'orders' => pf_jenny_count('SELECT COUNT(*) AS row_count FROM orders WHERE customer_id = 25') - count($ids['orders']),
        'order_items' => pf_jenny_count("SELECT COUNT(*) AS row_count FROM order_items WHERE order_id IN (SELECT order_id FROM orders WHERE customer_id = 25 AND order_id NOT IN ({$safeOrderSql}))"),
        'order_messages' => pf_jenny_count("SELECT COUNT(*) AS row_count FROM order_messages WHERE order_id IN (SELECT order_id FROM orders WHERE customer_id = 25 AND order_id NOT IN ({$safeOrderSql}))"),
        'customizations' => pf_jenny_count("SELECT COUNT(*) AS row_count FROM customizations WHERE order_id IN (SELECT order_id FROM orders WHERE customer_id = 25 AND order_id NOT IN ({$safeOrderSql}))"),
        'job_orders' => pf_jenny_count("SELECT COUNT(*) AS row_count FROM job_orders WHERE customer_id = 25 AND order_id IN (SELECT order_id FROM orders WHERE customer_id = 25 AND order_id NOT IN ({$safeOrderSql}))"),
        'job_order_materials' => pf_jenny_count("SELECT COUNT(*) AS row_count FROM job_order_materials WHERE job_order_id IN ({$protectedJobSubquery})"),
        'order_item_revisions' => pf_jenny_count("SELECT COUNT(*) AS row_count FROM order_item_revisions WHERE order_id IN (SELECT order_id FROM orders WHERE customer_id = 25 AND order_id NOT IN ({$safeOrderSql}))"),
        'order_revision_requests' => pf_jenny_count("SELECT COUNT(*) AS row_count FROM order_revision_requests WHERE customer_id = 25 AND order_id IN (SELECT order_id FROM orders WHERE customer_id = 25 AND order_id NOT IN ({$safeOrderSql}))"),
        'change_item_requests' => pf_jenny_count("SELECT COUNT(*) AS row_count FROM change_item_requests WHERE customer_id = 25 AND order_id IN (SELECT order_id FROM orders WHERE customer_id = 25 AND order_id NOT IN ({$safeOrderSql}))"),
        'change_item_evidence' => pf_jenny_count('SELECT COUNT(*) AS row_count FROM change_item_evidence e JOIN change_item_requests c ON c.change_item_id = e.change_item_id WHERE c.customer_id = 25 AND c.order_id IN (SELECT order_id FROM orders WHERE customer_id = 25)'),
        'payment_submissions' => pf_jenny_count('SELECT COUNT(*) AS row_count FROM payment_submissions WHERE customer_id = 25 OR order_id IN (SELECT order_id FROM orders WHERE customer_id = 25) OR job_order_id IN (SELECT id FROM job_orders WHERE customer_id = 25)'),
        'provider_payments' => pf_jenny_count('SELECT COUNT(*) AS row_count FROM provider_payments WHERE customer_id = 25 OR order_id IN (SELECT order_id FROM orders WHERE customer_id = 25) OR job_order_id IN (SELECT id FROM job_orders WHERE customer_id = 25)'),
        'provider_payment_status_history' => pf_jenny_count('SELECT COUNT(*) AS row_count FROM provider_payment_status_history h JOIN provider_payments p ON p.id = h.provider_payment_id WHERE p.customer_id = 25 OR p.order_id IN (SELECT order_id FROM orders WHERE customer_id = 25) OR p.job_order_id IN (SELECT id FROM job_orders WHERE customer_id = 25)'),
        'provider_webhook_events' => pf_jenny_count('SELECT COUNT(*) AS row_count FROM provider_webhook_events h JOIN provider_payments p ON p.id = h.provider_payment_id WHERE p.customer_id = 25 OR p.order_id IN (SELECT order_id FROM orders WHERE customer_id = 25) OR p.job_order_id IN (SELECT id FROM job_orders WHERE customer_id = 25)'),
        'inventory_transactions' => pf_jenny_count("SELECT COUNT(*) AS row_count FROM inventory_transactions WHERE ref_type = 'JOB_ORDER' AND ref_id IN (SELECT id FROM job_orders WHERE customer_id = 25)"),
        'job_order_ink_usage' => pf_jenny_count('SELECT COUNT(*) AS row_count FROM job_order_ink_usage WHERE job_order_id IN (SELECT id FROM job_orders WHERE customer_id = 25)'),
        'archived_orders' => pf_jenny_count('SELECT COUNT(*) AS row_count FROM printflow_order_archive_map WHERE order_id IN (SELECT order_id FROM orders WHERE customer_id = 25)'),
        'order_status_history' => pf_jenny_count('SELECT COUNT(*) AS row_count FROM order_status_history WHERE order_id IN (SELECT order_id FROM orders WHERE customer_id = 25)'),
        'receipt_print_jobs' => pf_jenny_count('SELECT COUNT(*) AS row_count FROM receipt_print_jobs WHERE order_id IN (SELECT order_id FROM orders WHERE customer_id = 25)'),
        'receipt_print_job_events' => pf_jenny_count('SELECT COUNT(*) AS row_count FROM receipt_print_job_events WHERE job_id IN (SELECT id FROM receipt_print_jobs WHERE order_id IN (SELECT order_id FROM orders WHERE customer_id = 25))'),
        'notifications' => pf_jenny_count('SELECT COUNT(*) AS row_count FROM notifications WHERE customer_id = 25') - count($ids['notifications']),
        'notification_logs' => pf_jenny_count('SELECT COUNT(*) AS row_count FROM notification_logs WHERE notification_id IN (SELECT notification_id FROM notifications WHERE customer_id = 25)'),
        'customer_cart_other_rows' => pf_jenny_count('SELECT COUNT(*) AS row_count FROM customer_cart WHERE customer_id = 25') - count($ids['customer_cart']),
        'chatbot_conversations_excluded_by_scope' => count($chatbotExcluded['chatbot_conversations']),
        'chatbot_messages_excluded_by_scope' => count($chatbotExcluded['chatbot_messages']),
        'message_reactions_other' => pf_jenny_count("SELECT COUNT(*) AS row_count FROM message_reactions WHERE sender = 'Customer' AND sender_id = 25") - count($ids['message_reactions']),
        'review_helpful_other' => pf_jenny_count("SELECT COUNT(*) AS row_count FROM review_helpful WHERE customer_id = 25 AND user_id = 25 AND user_type = 'Customer'") - count($ids['review_helpful']),
        'synthetic_registry_rows' => pf_jenny_count('SELECT COUNT(*) AS row_count FROM maintenance_synthetic_demo_order_rows WHERE order_id IN (SELECT order_id FROM orders WHERE customer_id = 25)'),
        'maintenance_backup_rows' => pf_jenny_count('SELECT COUNT(*) AS row_count FROM maintenance_customer_25_order_delete_backup WHERE customer_id = 25'),
        'review_audit_rows' => pf_jenny_count('SELECT COUNT(*) AS row_count FROM review_cleanup_audit WHERE customer_id = 25'),
    ];
    $ids['other_customer_snapshot']=pf_jenny_other_customer_snapshot($lock);
    return ['ids'=>$ids,'protected_excluded_by_scope'=>$chatbotExcluded,'rows'=>['orders'=>$orders,'order_items'=>$items,'order_messages'=>$rows['order_messages'],'customizations'=>$rows['customizations'],'job_orders'=>$jobs,'job_order_materials'=>$materials,'order_item_revisions'=>$revisions,'order_revision_requests'=>$revisionRequests,'notifications'=>$notifications,'push_notification_queue'=>$queueRows,'message_reactions'=>$reactions,'review_helpful'=>$helpful]];
}

function pf_jenny_fingerprint(array $plan): string
{
    $ids=$plan['ids']; ksort($ids);
    return hash('sha256',json_encode($ids,JSON_THROW_ON_ERROR));
}

function pf_jenny_audit(string $status, array $details): void
{
    global $conn;
    $json=json_encode($details,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
    $statement=$conn->prepare('INSERT INTO customer_testing_data_cleanup_audit (customer_id, actor_customer_id, status, details, created_at) VALUES (25,25,?,?,NOW())');
    if (!$statement) throw new RuntimeException('Cleanup audit table is unavailable. Apply its migration before enabling this feature.');
    $statement->bind_param('ss',$status,$json);
    if (!$statement->execute()) { $statement->close(); throw new RuntimeException('Could not write the cleanup audit record.'); }
    $statement->close();
}

/**
 * Build a non-deletable preview after the exact approved cleanup set was already
 * committed. The committed audit is required; missing rows alone never grant this
 * state. All approved IDs are checked again against the live database.
 */
function pf_jenny_completed_cleanup_preview(): ?array
{
    $auditRows=pf_jenny_query("SELECT details FROM customer_testing_data_cleanup_audit WHERE customer_id = 25 AND actor_customer_id = 25 AND status = 'deleted' ORDER BY audit_id DESC LIMIT 1");
    if (!$auditRows) return null;
    $audit=json_decode((string)($auditRows[0]['details']??''),true);
    if (!is_array($audit) || !is_array($audit['deleted']??null) || !is_array($audit['remaining']??null)) return null;
    if (($audit['failed']??null)!==[] || !is_array($audit['protected']??null)) return null;

    $expectedDeleted=[
        'push_notification_queue'=>141,'notifications'=>141,'message_reactions'=>3,'review_helpful'=>14,
        'customer_cart'=>5,'order_revision_requests'=>2,'order_item_revisions'=>2,'job_order_materials'=>2,
        'job_orders'=>58,'order_messages'=>84,'customizations'=>24,'order_items'=>28,'orders'=>59,
    ];
    foreach($expectedDeleted as $table=>$count){
        if((int)($audit['deleted'][$table]??-1)!==$count || (int)($audit['remaining'][$table]??-1)!==0) return null;
    }

    $spec=[
        'order_items'=>['order_item_id',[12566,12577,12580,12593,[12638,12639],12647,[12665,12666],[12675,12677],12684,12688,12690,12692,[12695,12700],12714,12728,12780,12782,12829,12847,12854,12874]],
        'order_messages'=>['message_id',[[1276,1279],1284,1304,[1335,1355],1357,[1389,1395],[1398,1405],1407,[1410,1421],[1424,1425],1433,[1466,1467],[1535,1536],[1539,1541],1594,1602,[1608,1619],1621,[1644,1645],1652]],
        'customizations'=>['customization_id',[[2126,2128],[2136,2140],2147,2151,2153,2155,[2158,2163],2177,2191,2228,2230,2253,2256]],
        'job_orders'=>['id',[3649,3651,3661,3664,3675,[3699,3719],3726,[3733,3739],[3741,3748],[3752,3761],3768,3782,3822,3824,3847,3851]],
        'job_order_materials'=>['id',[458,488]],
        'order_item_revisions'=>['revision_id',[1,2]],
        'order_revision_requests'=>['revision_request_id',[1,2]],
        // The audit deleted three safe reactions. Candidate 39 belongs to a
        // customer message outside the safe audited message set and is retained.
        'message_reactions'=>['reaction_id',[36,37,38]],
        'review_helpful'=>['id',[18,27,29,33,[39,41],[43,45],[49,52]]],
    ];
    foreach($spec as $table=>[$pk,$ranges]){
        $ids=pf_jenny_ranges($ranges);
        foreach(array_chunk($ids,25) as $batch){
            $marks=implode(',',array_fill(0,count($batch),'?'));
            if(pf_jenny_query("SELECT `{$pk}` FROM `{$table}` WHERE `{$pk}` IN ({$marks}) LIMIT 1",str_repeat('i',count($batch)),$batch)) return null;
        }
    }
    $cart=pf_jenny_query('SELECT product_id FROM customer_cart WHERE customer_id = 25 AND product_id IN (46,47,49,58,64) AND variant_id = 0 AND quantity = 1');
    if($cart) return null;

    $auditedOrderIds=pf_jenny_ranges(pf_jenny_audited_order_id_spec());
    $currentOrders=[];
    foreach(array_chunk($auditedOrderIds,25) as $batch){
        $marks=implode(',',array_fill(0,count($batch),'?'));
        $currentOrders=array_merge($currentOrders,pf_jenny_query('SELECT order_id,customer_id,payment_status,downpayment_amount,payment_proof_path,payment_proof FROM orders WHERE order_id IN ('.$marks.') ORDER BY order_id',str_repeat('i',count($batch)),$batch));
    }
    if(count($currentOrders)!==2) return null;
    foreach($currentOrders as $order){
        if((int)$order['customer_id']!==25) return null;
        $protected=(string)($order['payment_status']??'')!=='Unpaid'
            || (float)($order['downpayment_amount']??0)!==0.0
            || (string)($order['payment_proof_path']??'')!==''
            || (string)($order['payment_proof']??'')!=='';
        if(!$protected) return null;
    }
    if(count($auditedOrderIds)-count($currentOrders)!==(int)$expectedDeleted['orders']) return null;

    $protectedOrderIds=array_map(static fn($row)=>(int)$row['order_id'],$currentOrders);
    $protectedCounts=['orders'=>2];
    $excludedReactionIds=pf_jenny_ranges([39]);
    $safeMessageIds=pf_jenny_ranges($spec['order_messages'][1]);
    $excludedReactions=pf_jenny_query('SELECT reaction_id FROM message_reactions WHERE reaction_id IN ('.implode(',',array_fill(0,count($excludedReactionIds),'?')).') AND sender = ? AND sender_id = 25 AND message_id NOT IN ('.implode(',',array_fill(0,count($safeMessageIds),'?')).')',str_repeat('i',count($excludedReactionIds)).'s'.str_repeat('i',count($safeMessageIds)),array_merge($excludedReactionIds,['Customer'],$safeMessageIds));
    if(array_map(static fn($row)=>(int)$row['reaction_id'],$excludedReactions)!==$excludedReactionIds) return null;
    $protectedCounts['message_reactions_outside_safe_messages']=count($excludedReactions);
    foreach([
        'order_items'=>['order_id','order_item_id'],
        'order_messages'=>['order_id','message_id'],
        'customizations'=>['order_id','customization_id'],
        'job_orders'=>['order_id','id'],
        'order_item_revisions'=>['order_id','revision_id'],
        'order_revision_requests'=>['order_id','revision_request_id'],
    ] as $table=>[$fk,$pk]){
        $total=0;
        foreach(array_chunk($protectedOrderIds,25) as $batch){$marks=implode(',',array_fill(0,count($batch),'?'));$total+=count(pf_jenny_query("SELECT `{$pk}` FROM `{$table}` WHERE `{$fk}` IN ({$marks})",str_repeat('i',count($batch)),$batch));}
        $protectedCounts[$table]=$total;
    }

    $safeCounts=array_fill_keys(array_keys($expectedDeleted),0);
    $safeCounts['orders']=0;
    return [
        'safe_remaining'=>0,
        'protected_remaining'=>2,
        'already_deleted'=>59,
        'counts'=>$safeCounts,
        'protected'=>$protectedCounts,
        'protected_excluded_by_scope'=>['label'=>'Protected / Excluded by scope','records'=>['chatbot_conversations'=>[],'chatbot_messages'=>[]]],
        'uncertain'=>['physical_file_reference_rows'=>267,'unique_physical_files'=>'Unverified; retain all files.','chatbot_inquiries'=>'Ownerless by schema; retain.'],
    ];
}

function pf_jenny_require_audit_table(): void
{
    $columns=pf_jenny_count("SELECT COUNT(DISTINCT COLUMN_NAME) AS row_count FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='customer_testing_data_cleanup_audit' AND COLUMN_NAME IN ('audit_id','customer_id','actor_customer_id','status','details','created_at')");
    $engine=pf_jenny_query("SELECT ENGINE FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='customer_testing_data_cleanup_audit'");
    if($columns!==6 || strtolower((string)($engine[0]['ENGINE']??''))!=='innodb') throw new RuntimeException('Cleanup audit migration is not installed or not transactional.');
}

if (($_SESSION['user_type'] ?? '') !== 'Customer' || (int)get_user_id() !== PF_JENNY_CLEANUP_CUSTOMER_ID) pf_jenny_json(404,['success'=>false,'error'=>'This temporary feature is not enabled for this account.']);
$customerId=(int)get_user_id();
$method=strtoupper((string)($_SERVER['REQUEST_METHOD']??''));
if ($method==='GET') {
    $GLOBALS['pf_jenny_cleanup_request_stage'] = 'preview';
    if (($_GET['diagnostic'] ?? '') === 'orders') {
        try {
            pf_jenny_json(200,pf_jenny_orders_diagnostic());
        } catch (Throwable $e) {
            global $conn;
            pf_jenny_json(200,[['query_step'=>'diagnostic_setup','table'=>'orders','row_count'=>null,'database_error_code'=>$conn instanceof mysqli ? (int)$conn->errno : 0,'error_type'=>(new ReflectionClass($e))->getShortName(),'sanitized_error_message'=>pf_jenny_sanitized_db_message($conn instanceof mysqli ? (int)$conn->errno : 0)]]);
        }
    }
    try {
        pf_jenny_require_audit_table();
        $completedPreview=pf_jenny_completed_cleanup_preview();
        if($completedPreview!==null){
            unset($_SESSION['jenny_cleanup_preview']);
            pf_jenny_json(200,[
                'success'=>true,
                'diagnostic_version'=>PF_JENNY_CLEANUP_DIAGNOSTIC_VERSION,
                'runtime'=>pf_jenny_runtime_diagnostics(),
                'preview_token'=>null,
                'deletion_available'=>false,
                'safe_remaining'=>$completedPreview['safe_remaining'],
                'protected_remaining'=>$completedPreview['protected_remaining'],
                'already_deleted'=>$completedPreview['already_deleted'],
                'counts'=>$completedPreview['counts'],
                'protected'=>$completedPreview['protected'],
                'protected_excluded_by_scope'=>$completedPreview['protected_excluded_by_scope'],
                'uncertain'=>$completedPreview['uncertain'],
                'delete_order'=>PF_JENNY_CLEANUP_DELETE_ORDER,
                'message'=>'The approved safe records were already deleted and verified. No additional deletion is available.'
            ]);
        }
        $plan=pf_jenny_plan();
        $token=bin2hex(random_bytes(32));
        $_SESSION['jenny_cleanup_preview']=['token_hash'=>hash('sha256',$token),'fingerprint'=>pf_jenny_fingerprint($plan),'expires'=>time()+600];
        $counts=[]; foreach($plan['ids'] as $table=>$values) if(is_array($values) && !in_array($table,['protected_counts','other_customer_snapshot'],true)) $counts[$table]=count($values);
        pf_jenny_json(200,['success'=>true,'diagnostic_version'=>PF_JENNY_CLEANUP_DIAGNOSTIC_VERSION,'runtime'=>pf_jenny_runtime_diagnostics(),'preview_token'=>$token,'counts'=>$counts,'protected'=>$plan['ids']['protected_counts'],'protected_excluded_by_scope'=>['label'=>'Protected / Excluded by scope','records'=>$plan['protected_excluded_by_scope']],'uncertain'=>['physical_file_reference_rows'=>267,'unique_physical_files'=>'Unverified; retain all files.','chatbot_inquiries'=>'Ownerless by schema; retain.'],'delete_order'=>PF_JENNY_CLEANUP_DELETE_ORDER]);
    } catch(Throwable $e) {
        $context=$GLOBALS['pf_jenny_cleanup_context']??[];
        $errorType=(new ReflectionClass($e))->getShortName();
        $failure=['stage'=>'preview_failed','table'=>(string)($context['table']??'unknown'),'query_phase'=>(string)($context['phase']??'plan'),'error_type'=>$errorType];
        foreach (['db_error_stage','db_errno','db_sqlstate','exception_type','exception_code','expected_count','actual_count','mismatch_count'] as $detailKey) {
            if (array_key_exists($detailKey,$context)) $failure[$detailKey]=$context[$detailKey];
        }
        pf_jenny_log_stage($failure['stage'],$failure['table'],$failure['query_phase'],$errorType);
        pf_jenny_json(503,['success'=>false,'diagnostic_version'=>PF_JENNY_CLEANUP_DIAGNOSTIC_VERSION,'runtime'=>pf_jenny_runtime_diagnostics(),'diagnostic_failure'=>$failure,'error'=>'A complete safe preview could not be verified. No changes were made. Refresh or contact support.']);
    }
}
if($method!=='POST') pf_jenny_json(405,['success'=>false,'error'=>'Method not allowed.']);
$input=json_decode(file_get_contents('php://input'),true)?:[];
if(!verify_csrf_token((string)($input['csrf_token']??''))) pf_jenny_json(403,['success'=>false,'error'=>'Invalid security token. Refresh the page.']);
if(($input['acknowledged']??false)!==true) pf_jenny_json(400,['success'=>false,'error'=>'Confirm the permanent deletion acknowledgment before continuing.']);
$saved=$_SESSION['jenny_cleanup_preview']??[];
if(empty($saved['token_hash'])||!hash_equals((string)$saved['token_hash'],hash('sha256',(string)($input['preview_token']??'')))||($saved['expires']??0)<time()) pf_jenny_json(409,['success'=>false,'error'=>'The preview expired. Refresh the preview before confirming.']);
global $conn;
$deleted=[]; $protected=[]; $plan=null;
try {
    $GLOBALS['pf_jenny_cleanup_request_stage'] = 'delete_validation';
    if(!$conn->begin_transaction()) throw new RuntimeException('Could not start cleanup transaction.');
    $GLOBALS['pf_jenny_cleanup_request_stage'] = 'locked_plan';
    $plan=pf_jenny_plan(true);
    $protected=$plan['ids']['protected_counts'];
    if(!hash_equals((string)$saved['fingerprint'],pf_jenny_fingerprint($plan))) throw new RuntimeException('The safe data changed since preview.');
    $deleteOrder=PF_JENNY_CLEANUP_DELETE_ORDER;
    $safeOrders=$plan['ids']['orders']; $safeJobs=$plan['ids']['job_orders']; $safeNotifications=$plan['ids']['notifications'];
    $scopes=[
        'push_notification_queue'=>['user_id = 25 AND user_type = ? AND notification_id IN ('.implode(',',array_fill(0,count($safeNotifications),'?')).')','s'.str_repeat('i',count($safeNotifications)),array_merge(['Customer'],$safeNotifications)],
        'notifications'=>['customer_id = 25 AND (user_id IS NULL OR user_id = 25)','',[]],
        'message_reactions'=>['sender = ? AND sender_id = 25','s',['Customer']],
        'review_helpful'=>['customer_id = 25 AND user_id = 25 AND user_type = ?','s',['Customer']],
        'order_revision_requests'=>['customer_id = 25 AND order_id IN ('.implode(',',array_fill(0,count($safeOrders),'?')).')',str_repeat('i',count($safeOrders)),$safeOrders],
        'order_item_revisions'=>['order_id IN ('.implode(',',array_fill(0,count($safeOrders),'?')).')',str_repeat('i',count($safeOrders)),$safeOrders],
        'job_order_materials'=>['job_order_id IN ('.implode(',',array_fill(0,count($safeJobs),'?')).') AND deducted_at IS NULL',str_repeat('i',count($safeJobs)),$safeJobs],
        'order_messages'=>['order_id IN ('.implode(',',array_fill(0,count($safeOrders),'?')).')',str_repeat('i',count($safeOrders)),$safeOrders],
        'order_items'=>['order_id IN ('.implode(',',array_fill(0,count($safeOrders),'?')).')',str_repeat('i',count($safeOrders)),$safeOrders],
        'customizations'=>['customer_id = 25 AND order_id IN ('.implode(',',array_fill(0,count($safeOrders),'?')).')',str_repeat('i',count($safeOrders)),$safeOrders],
        'job_orders'=>['customer_id = 25 AND order_id IN ('.implode(',',array_fill(0,count($safeOrders),'?')).')',str_repeat('i',count($safeOrders)),$safeOrders],
        'orders'=>['customer_id = 25','',[]],
    ];
    foreach($deleteOrder as $table){
        $GLOBALS['pf_jenny_cleanup_context'] = ['table' => $table, 'phase' => 'delete'];
        $ids=$plan['ids'][$table]??[]; if(!$ids) continue;
        $pk=['orders'=>'order_id','order_items'=>'order_item_id','order_messages'=>'message_id','customizations'=>'customization_id','job_orders'=>'id','job_order_materials'=>'id','order_item_revisions'=>'revision_id','order_revision_requests'=>'revision_request_id','notifications'=>'notification_id','push_notification_queue'=>'id','message_reactions'=>'reaction_id','review_helpful'=>'id','customer_cart'=>'product_id'][$table];
        if ($table === 'customer_cart') {
            foreach ($ids as $productId) {
                $affected=db_execute_affected_rows('DELETE FROM customer_cart WHERE customer_id = 25 AND product_id = ? AND variant_id = 0 AND quantity = 1','i',[$productId]);
                if($affected===false || $affected!==1) throw new RuntimeException('Delete count mismatch in customer_cart.');
                $deleted['customer_cart']=($deleted['customer_cart']??0)+$affected;
            }
            continue;
        }
        $where=pf_jenny_in_sql($pk,$ids); $scope=$scopes[$table]??['1=0','',[]];
        $affected=db_execute_affected_rows('DELETE FROM `'.$table.'` WHERE '.$where[0].' AND '.$scope[0],$where[1].$scope[1],array_merge($where[2],$scope[2]));
        if($affected===false || $affected!==count($ids)) throw new RuntimeException('Delete count mismatch in '.$table.'.');
        $deleted[$table]=$affected;
    }
    $remaining=[];
    foreach($deleted as $table=>$count){
        if ($table==='customer_cart') $remainingRows=pf_jenny_query('SELECT product_id FROM customer_cart WHERE customer_id = 25 AND variant_id = 0 AND quantity = 1 AND product_id IN (46,47,49,58,64)');
        else $remainingRows=pf_jenny_ids($table,['orders'=>'order_id','order_items'=>'order_item_id','order_messages'=>'message_id','customizations'=>'customization_id','job_orders'=>'id','job_order_materials'=>'id','order_item_revisions'=>'revision_id','order_revision_requests'=>'revision_request_id','notifications'=>'notification_id','push_notification_queue'=>'id','message_reactions'=>'reaction_id','review_helpful'=>'id'][$table],$plan['ids'][$table]);
        $remaining[$table]=count($remainingRows);if($remaining[$table]!==0)throw new RuntimeException('Target records remain in '.$table.'.');
    }
    $otherAfter=pf_jenny_other_customer_snapshot(true);
    if (!hash_equals(hash('sha256',json_encode($plan['ids']['other_customer_snapshot'],JSON_THROW_ON_ERROR)),hash('sha256',json_encode($otherAfter,JSON_THROW_ON_ERROR)))) throw new RuntimeException('Independent customer verification changed; transaction rolled back.');
    pf_jenny_audit('deleted',['deleted'=>$deleted,'protected'=>$protected,'protected_excluded_by_scope'=>$plan['protected_excluded_by_scope'],'failed'=>[],'remaining'=>$remaining,'delete_order'=>$deleteOrder]);
    if(!$conn->commit()) throw new RuntimeException('Could not commit cleanup transaction.');
    unset($_SESSION['jenny_cleanup_preview']);
    pf_jenny_json(200,['success'=>true,'deleted'=>$deleted,'protected'=>$protected,'failed'=>[],'remaining'=>$remaining,'customer_account'=>'retained','physical_files'=>'retained']);
} catch(Throwable $e){
    if($conn instanceof mysqli) $conn->rollback();
    $failed=['transaction_rolled_back'=>1];
    $remaining=[]; if(is_array($plan)) foreach($plan['ids'] as $table=>$values) if(is_array($values) && !in_array($table,['protected_counts','other_customer_snapshot'],true)) $remaining[$table]=count($values);
    try { pf_jenny_audit('failed',['deleted'=>[],'protected'=>$protected,'protected_excluded_by_scope'=>$plan['protected_excluded_by_scope']??[],'failed'=>$failed,'remaining'=>$remaining]); } catch(Throwable $auditError){ pf_jenny_log_stage('failure_audit','customer_testing_data_cleanup_audit','insert',(new ReflectionClass($auditError))->getShortName()); }
    $context=$GLOBALS['pf_jenny_cleanup_context']??[];
    pf_jenny_log_stage('delete_failed',(string)($context['table']??'unknown'),(string)($context['phase']??'transaction'),(new ReflectionClass($e))->getShortName());
    pf_jenny_json(500,['success'=>false,'error'=>'Cleanup failed and the database transaction was rolled back. See the audit log.']);
}
