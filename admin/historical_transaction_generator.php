<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/product_branch_stock.php';
require_once __DIR__ . '/../includes/product_option_stock.php';
require_once __DIR__ . '/../includes/pos_customer_helpers.php';
require_once __DIR__ . '/../includes/service_order_helper.php';
require_once __DIR__ . '/../includes/service_field_config_helper.php';
require_once __DIR__ . '/../includes/JobOrderService.php';
require_role('Admin');
global $conn;
$error = ''; $success = ''; $preview = $_SESSION['historical_generator_preview'] ?? null;
function htg_h(string $v): string { return htmlspecialchars($v, ENT_QUOTES, 'UTF-8'); }
function htg_business_timezone(): DateTimeZone {
    return new DateTimeZone(date_default_timezone_get() ?: 'Asia/Manila');
}
function htg_now(): DateTimeImmutable {
    return new DateTimeImmutable('now', htg_business_timezone());
}
function htg_date(string $v): DateTimeImmutable {
    $d = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $v . ' 00:00:00', htg_business_timezone());
    $e = DateTimeImmutable::getLastErrors();
    if (!$d || ($e !== false && ($e['warning_count'] || $e['error_count']))) throw new RuntimeException('Enter valid calendar dates.');
    return $d;
}
function htg_service_price(array $service, ?string $dimensions): array {
    $serviceId = (int)($service['id'] ?? 0);
    $customization = [];
    $configs = $serviceId > 0 ? get_service_field_config($serviceId) : [];
    foreach ($configs as $key => $config) {
        if (empty($config['visible']) || !in_array((string)($config['type'] ?? ''), ['radio', 'select', 'dimension'], true)) continue;
        $options = is_array($config['options'] ?? null) ? $config['options'] : [];
        $selected = null;
        if (($config['type'] ?? '') === 'dimension' && $dimensions !== null) {
            foreach ($options as $option) {
                $value = is_array($option) ? (string)($option['value'] ?? '') : (string)$option;
                if ($value !== '' && htg_dimension_matches($value, $dimensions)) { $selected = $value; break; }
            }
        }
        if ($selected === null) {
            foreach ($options as $option) {
                $value = is_array($option) ? trim((string)($option['value'] ?? '')) : trim((string)$option);
                if ($value !== '' && strcasecmp($value, 'Others') !== 0) { $selected = $value; break; }
            }
        }
        if ($selected !== null) {
            $customization[(string)$key] = $selected;
            $customization[(string)($config['label'] ?? $key)] = $selected;
        }
    }
    if ($dimensions !== null) $customization['dimensions'] = $dimensions;
    $pricing = function_exists('printflow_calculate_service_unit_price')
        ? printflow_calculate_service_unit_price($serviceId, $customization)
        : ['ok' => false, 'unit_price' => 0.0, 'message' => 'Shared service pricing is unavailable.'];
    $price = (float)($pricing['unit_price'] ?? 0);
    if (empty($pricing['ok']) || $price <= 0) {
        return ['ok' => false, 'price' => 0.0, 'customization' => $customization, 'reason' => (string)($pricing['message'] ?? 'No positive configured service price was found.')];
    }
    return ['ok' => true, 'price' => round($price, 2), 'customization' => $customization, 'reason' => ''];
}
function htg_dimension_matches(string $left, string $right): bool {
    $normalize = static fn(string $value): string => strtolower(preg_replace('/[^0-9.]+/', 'x', $value) ?? '');
    return $normalize($left) === $normalize($right);
}
function htg_stage_times(DateTimeImmutable $order, DateTimeImmutable $now): array {
    $orderTs = $order->getTimestamp();
    $latest = max($orderTs, $now->getTimestamp() - 60);
    $productionWindowEnd = min($latest, $order->modify('+3 days')->getTimestamp());
    $productionStart = min($productionWindowEnd, $orderTs + 3600);
    $productionTs = $productionStart >= $productionWindowEnd
        ? $productionWindowEnd
        : random_int($productionStart, $productionWindowEnd);
    $production = (new DateTimeImmutable('@' . $productionTs))->setTimezone(htg_business_timezone());
    $completionWindowEnd = min($latest, $production->modify('+2 days')->getTimestamp());
    $completionStart = min($completionWindowEnd, $production->getTimestamp() + 3600);
    $completionTs = $completionStart >= $completionWindowEnd
        ? $completionWindowEnd
        : random_int($completionStart, $completionWindowEnd);
    $completion = (new DateTimeImmutable('@' . $completionTs))->setTimezone(htg_business_timezone());
    return [$production, $completion];
}
function htg_sales_total(array $rows): float {
    return round(array_sum(array_map(static fn(array $row): float => (float)($row['total'] ?? 0), $rows)), 2);
}
function htg_target_average(?float $target, array $rows, int $remaining): ?float {
    if ($target === null || $remaining < 1) return null;
    return max(0.01, ($target - htg_sales_total($rows)) / $remaining);
}
function htg_choose_target_ready_choice(array $choices, array $stock, float $desired): array {
    $best = null;
    $bestDistance = INF;
    foreach ($choices as $choice) {
        $key = (int)$choice['p']['id'] . '|' . ($choice['o']['option_value'] ?? '');
        $available = (int)($stock[$key] ?? 0);
        $unit = (float)($choice['p']['price'] ?? 0);
        if ($available < 1 || $unit <= 0) continue;
        $ideal = min($available, max(1, (int)round($desired / $unit)));
        foreach (array_unique([1, $ideal, max(1, $ideal - 1), min($available, $ideal + 1), $available]) as $quantity) {
            $distance = abs($desired - ($unit * $quantity));
            if ($distance < $bestDistance) {
                $bestDistance = $distance;
                $best = ['choice' => $choice, 'key' => $key, 'quantity' => (int)$quantity];
            }
        }
    }
    return $best ?? ['choice' => $choices[array_rand($choices)], 'key' => '', 'quantity' => 1];
}
function htg_choose_target_service(array $services, float $desired): array {
    $best = null;
    $bestDistance = INF;
    foreach ($services as $service) {
        $price = (float)($service['_htg_price'] ?? 0);
        if ($price <= 0) continue;
        $distance = abs($desired - $price);
        if ($distance < $bestDistance) {
            $bestDistance = $distance;
            $best = $service;
        }
    }
    return $best ?? $services[array_rand($services)];
}
function htg_least_used_choices(array $choices, array $usage): array {
    if (count($choices) < 2) return $choices;
    $minimum = null;
    foreach ($choices as $choice) {
        $key = 'ready:' . (int)$choice['p']['id'];
        $minimum = $minimum === null ? (int)($usage[$key] ?? 0) : min($minimum, (int)($usage[$key] ?? 0));
    }
    return array_values(array_filter($choices, static fn(array $choice): bool => (int)($usage['ready:' . (int)$choice['p']['id']] ?? 0) === $minimum));
}
function htg_least_used_services(array $services, array $usage): array {
    if (count($services) < 2) return $services;
    $minimum = null;
    foreach ($services as $service) {
        $minimum = $minimum === null ? (int)($usage['custom:' . (int)$service['id']] ?? 0) : min($minimum, (int)($usage['custom:' . (int)$service['id']] ?? 0));
    }
    return array_values(array_filter($services, static fn(array $service): bool => (int)($usage['custom:' . (int)$service['id']] ?? 0) === $minimum));
}
function htg_apply_target_sales(array &$rows, array &$stock, ?float $target): void {
    if ($target === null) return;
    $total = htg_sales_total($rows);
    while (true) {
        $bestIndex = null;
        $bestDistance = abs($target - $total);
        foreach ($rows as $index => &$row) {
            if (($row['type'] ?? '') !== 'ready') continue;
            $key = (string)($row['_stock_key'] ?? '');
            $unit = (float)($row['unit_price'] ?? 0);
            if ($key === '' || $unit <= 0 || (int)($stock[$key] ?? 0) < 1) continue;
            $distance = abs($target - ($total + $unit));
            if ($distance + 0.00001 < $bestDistance) {
                $bestDistance = $distance;
                $bestIndex = $index;
            }
        }
        unset($row);
        if ($bestIndex === null) break;
        $row = &$rows[$bestIndex];
        $key = (string)$row['_stock_key'];
        $row['quantity']++;
        $row['total'] = round((float)$row['unit_price'] * (int)$row['quantity'], 2);
        $optionSummary = !empty($row['customization']) ? 'Options: ' . implode(', ', array_unique(array_map('strval', array_values($row['customization']))) ) . ' | ' : '';
        $row['expected_usage'] = $optionSummary . 'Ready-made stock: ' . (int)$row['quantity'] . ' pcs';
        $stock[$key]--;
        $total = htg_sales_total($rows);
        unset($row);
    }
    foreach ($rows as &$row) {
        unset($row['_stock_key']);
    }
    unset($row);
}
function htg_branch(int $id): array {
    $rows = db_query("SELECT id, branch_name FROM branches WHERE id = ? AND status != 'Archived' LIMIT 1", 'i', [$id]) ?: [];
    if (!$rows) throw new RuntimeException('Select a valid active branch.'); return $rows[0];
}
function htg_catalog(string $table): array {
    if ($table === 'products') return db_query("SELECT product_id AS id, name, price FROM products WHERE LOWER(TRIM(product_type))='fixed' AND LOWER(TRIM(COALESCE(status,''))) NOT IN ('archived','inactive') ORDER BY product_id") ?: [];
    return db_query("SELECT service_id AS id, name, category, COALESCE(price,0) AS price FROM services WHERE status='Activated' ORDER BY service_id") ?: [];
}
function htg_schema_ready(): bool {
    foreach ([['orders','customer_id'],['orders','branch_id'],['orders','reference_id'],['orders','total_amount'],['orders','status'],['orders','payment_status'],['orders','payment_method'],['orders','payment_reference'],['orders','order_date'],['orders','updated_at'],['orders','notes'],['orders','pos_guest_display_name'],['orders','pos_guest_email'],['order_items','customization_data'],['inventory_transactions','transaction_date'],['inventory_transactions','transaction_id']] as $f) if (!db_table_has_column($f[0],$f[1])) return false;
    return true;
}
function htg_dimension_options(int $serviceId): array {
    if ($serviceId <= 0 || !db_table_has_column('service_field_configs', 'field_options')) return [];
    $rows = db_query('SELECT field_key, field_label, field_options FROM service_field_configs WHERE service_id = ? ORDER BY display_order ASC', 'i', [$serviceId]) ?: [];
    $values = [];
    $walk = static function ($value) use (&$walk, &$values): void {
        if (is_string($value)) {
            $value = trim($value);
            if (preg_match('/^\s*\d+(?:\.\d+)?\s*[x×]\s*\d+(?:\.\d+)?\s*(?:ft|feet|in|cm)?\s*$/iu', $value)) $values[] = preg_replace('/\s*[×]\s*/u', 'x', $value);
        } elseif (is_array($value)) {
            foreach ($value as $key => $item) $walk($key === 'value' || $key === 'label' ? $item : $item);
        }
    };
    foreach ($rows as $row) $walk(json_decode((string)($row['field_options'] ?? ''), true));
    return array_values(array_unique(array_filter($values)));
}
function htg_material_plan(string $serviceType, int $quantity, int $serviceId = 0): array {
    $rules = db_query("SELECT r.item_id, r.rule_type, i.name, i.track_by_roll, i.unit_of_measure
                       FROM service_material_rules r
                       JOIN inv_items i ON i.id = r.item_id
                       WHERE r.service_type = ? ORDER BY r.item_id", 's', [$serviceType]) ?: [];
    $materials = [];
    $dimensions = htg_dimension_options($serviceId);
    foreach ($rules as $rule) {
        if ((int)($rule['track_by_roll'] ?? 0) === 1) {
            if (!$dimensions) return ['supported' => false, 'warning' => 'Service "' . $serviceType . '" was skipped because no valid configured dimensions were found.', 'materials' => [], 'dimensions' => null, 'label' => 'Skipped: no configured dimensions'];
            [$width, $height] = array_map('floatval', preg_split('/\s*x\s*/i', (string)$dimensions[0]));
            $rollLength = max(0.01, $height * max(1, $quantity));
        } else {
            $rollLength = 0;
        }
        $materials[] = [
            'item_id' => (int)$rule['item_id'],
            'quantity' => max(1, $quantity),
            'uom' => ((int)($rule['track_by_roll'] ?? 0) === 1) ? 'ft' : (string)($rule['unit_of_measure'] ?? 'pcs'),
            'computed_len' => $rollLength,
        ];
    }
    return ['supported' => true, 'warning' => '', 'materials' => $materials, 'dimensions' => $dimensions[0] ?? null, 'label' => $materials ? implode(', ', array_map(static fn($m) => 'material #' . (int)$m['item_id'] . ' x ' . (float)($m['computed_len'] ?: $m['quantity']), $materials)) : 'No configured material rules'];
}
function htg_insert(array $r, string $token, string $reason): int {
    global $conn;
    $guest = printflow_pos_resolve_checkout_customer_context(['customer_id'=>'guest','guest_display_name'=>'Historical Walk-in','customer_email'=>'']);
    $total = round((float)$r['unit_price'] * (int)$r['quantity'], 2);
    $note = '[Historical generator] Entered by user #' . (int)get_user_id() . ' at ' . date('Y-m-d H:i:s') . '. Business date: ' . $r['business_at'] . '. Reason: ' . $reason;
    $cols=['customer_id','pos_guest_display_name','pos_guest_email','branch_id','reference_id','total_amount','status','payment_status','payment_method','payment_reference','order_date','notes'];
    $vals=[(int)$guest['customer_id'],'Historical Walk-in','',(int)$r['branch_id'],'HGEN-'.$token,$total,$r['type']==='custom'?'Pending':'Completed','Paid','Cash','HGEN-'.$token,$r['business_at'],$note]; $types='issisdssssss';
    foreach ([['amount_paid','d',$total],['order_type','s',$r['type']],['order_source','s','historical_generator']] as $o) if (db_table_has_column('orders',$o[0])) { $cols[]=$o[0];$vals[]=$o[2];$types.=$o[1]; }
    if (!db_execute('INSERT INTO orders ('.implode(',',$cols).') VALUES ('.implode(',',array_fill(0,count($cols),'?')).')',$types,$vals)) throw new RuntimeException('Generated order creation failed.');
    $oid=(int)$conn->insert_id; if (!db_execute('UPDATE orders SET updated_at=NOW() WHERE order_id=?','i',[$oid])) throw new RuntimeException('Generated order audit timestamp failed.');
    $json=json_encode($r['customization'] ?? [],JSON_UNESCAPED_SLASHES);
    if ($json===false || !db_execute('INSERT INTO order_items (order_id,product_id,quantity,unit_price,customization_data) VALUES (?,?,?,?,?)','iiids',[$oid,(int)$r['product_id'],(int)$r['quantity'],(float)$r['unit_price'],$json])) throw new RuntimeException('Generated item creation failed.');
    $item=(int)$conn->insert_id;
    if ($r['type']==='ready') { printflow_apply_product_order_item_inventory($item,(int)$r['branch_id'],(int)get_user_id(),'Historical generated sale',$r['business_at']); return $oid; }
    if (!db_execute('INSERT INTO customizations (order_id,order_item_id,customer_id,service_type,customization_details,status,created_at,updated_at) VALUES (?,?,?,?,?,?,NOW(),NOW())','iiisss',[$oid,$item,(int)$guest['customer_id'],$r['service_name'],$json,'In Production'])) throw new RuntimeException('Generated customization creation failed.');
    $job=JobOrderService::createOrder(['order_id'=>$oid,'order_item_id'=>$item,'customer_id'=>(int)$guest['customer_id'],'branch_id'=>(int)$r['branch_id'],'job_title'=>$r['service_name'],'service_type'=>$r['service_type'],'quantity'=>(int)$r['quantity'],'estimated_total'=>$total,'notes'=>$reason.' Planned production: '.$r['production_at'].'; planned completion: '.$r['completion_at'],'priority'=>'NORMAL','created_by'=>(int)get_user_id()],[]);
    if ((int)$job<=0) throw new RuntimeException('Generated production job creation failed.');
    $materialPlan = htg_material_plan((string)$r['service_type'], (int)$r['quantity'], (int)($r['service_id'] ?? 0));
    foreach ($materialPlan['materials'] as $material) {
        JobOrderService::addMaterial((int)$job, (int)$material['item_id'], (float)$material['quantity'], (string)$material['uom'], null, 'Historical generator assignment', null, 'JOB');
    }
    JobOrderService::processHistoricalProductionDeductions((int)$job, (string)$r['production_at']);
    return $oid;
}
function htg_preview(string $start,string $end,int $branch,int $count,int $readyPct,int $customPct,array $products,array $services,string $reason,?float $targetSales=null,array $selectedReadyIds=[],array $selectedServiceIds=[]): array {
    $b = htg_branch($branch);
    $skippedServices = [];
    $selectedReadyIds = array_values(array_unique(array_filter(array_map('intval', $selectedReadyIds), static fn(int $id): bool => $id > 0)));
    $selectedServiceIds = array_values(array_unique(array_filter(array_map('intval', $selectedServiceIds), static fn(int $id): bool => $id > 0)));
    if ($selectedReadyIds === [] && $selectedServiceIds === []) throw new RuntimeException('Select at least one product or service to generate.');
    $products = array_values(array_filter($products, static fn(array $product): bool => in_array((int)$product['id'], $selectedReadyIds, true)));
    $services = array_values(array_filter($services, static fn(array $service): bool => in_array((int)$service['id'], $selectedServiceIds, true)));
    if ($readyPct > 0 && $products === []) throw new RuntimeException('Select at least one ready-made product for the selected mix.');
    if ($customPct > 0 && $services === []) throw new RuntimeException('Select at least one custom/service item for the selected mix.');
    if ($customPct > 0) {
        $eligibleServices = [];
        foreach ($services as $service) {
            $plan = htg_material_plan(JobOrderService::inferServiceTypeFromProduct((string)$service['category'], (string)$service['name']), 1, (int)$service['id']);
            if (!empty($plan['supported'])) {
                $pricing = htg_service_price($service, $plan['dimensions'] ?? null);
                if (!empty($pricing['ok'])) {
                    $service['_htg_plan'] = $plan;
                    $service['_htg_price'] = $pricing['price'];
                    $service['_htg_customization'] = $pricing['customization'];
                    $eligibleServices[] = $service;
                } else {
                    $skippedServices[] = (string)$service['name'] . ': ' . (string)$pricing['reason'];
                }
            } else {
                $skippedServices[] = (string)$service['name'] . ': ' . (string)$plan['warning'];
            }
        }
        $services = $eligibleServices;
        if (!$services) throw new RuntimeException('No custom/service items can be generated safely. ' . implode(' ', $skippedServices));
    }
    $from=htg_date($start);$to=htg_date($end)->setTime(23,59,59);$now=htg_now();if($from>$to)throw new RuntimeException('Start Date cannot be after End Date.');if($to->format('Y-m-d')>$now->format('Y-m-d'))throw new RuntimeException('The date range must end today or earlier.');if($count<1||$count>500)throw new RuntimeException('Transactions must be between 1 and 500.');if($readyPct+$customPct!==100)throw new RuntimeException('Percentages must total 100.');if($targetSales !== null && $targetSales <= 0)throw new RuntimeException('Target Total Sales must be positive.');if($readyPct&&!$products)throw new RuntimeException('No active products are available.');if($customPct&&!$services)throw new RuntimeException('No active services are available.');if($customPct&&!db_query("SELECT product_id FROM products WHERE sku='POS-SERVICE' LIMIT 1"))throw new RuntimeException('The existing POS service placeholder product is missing.');
    $stock=[];foreach($products as $p){$pid=(int)$p['id'];$opts=printflow_product_option_stock_map($pid,$branch);if($opts)foreach($opts as $o)$stock[$pid.'|'.$o['option_value']]=(int)$o['stock_quantity'];else{[$n]=printflow_product_effective_stock($pid,$branch);$stock[$pid.'|']=(int)$n;}}
    $times=[];$span=$to->getTimestamp()-$from->getTimestamp();for($i=0;$i<$count;$i++)$times[]=$from->getTimestamp()+($span?random_int(0,$span):0);sort($times);$target=(int)round($count*$readyPct/100);$rows=[];$ready=0;$custom=0;$usage=[];
    foreach ($times as $ts) {
        $isReady = $ready < $target && ($custom >= $count - $target || random_int(0, 1));
        $desired = htg_target_average($targetSales, $rows, $count - count($rows));
        if ($isReady) {
            $choices = [];
            foreach ($products as $p) {
                $pid = (int)$p['id'];
                if ($targetSales !== null && (float)($p['price'] ?? 0) <= 0) continue;
                $opts = printflow_product_option_stock_map($pid, $branch);
                if ($opts) {
                    foreach ($opts as $o) {
                        if (($stock[$pid . '|' . $o['option_value']] ?? 0) > 0) $choices[] = ['p' => $p, 'o' => $o];
                    }
                } elseif (($stock[$pid . '|'] ?? 0) > 0) {
                    $choices[] = ['p' => $p, 'o' => null];
                }
            }
            if (!$choices) throw new RuntimeException('Current branch stock cannot support this preview.');
            $choices = htg_least_used_choices($choices, $usage);
            if ($desired !== null) {
                $targetChoice = htg_choose_target_ready_choice($choices, $stock, $desired);
                $c = $targetChoice['choice'];
                $key = (string)$targetChoice['key'];
                $available = max(1, (int)$stock[$key]);
                $q = min((int)$targetChoice['quantity'], $available);
            } else {
                $c = $choices[array_rand($choices)];
                $key = (int)$c['p']['id'] . '|' . ($c['o']['option_value'] ?? '');
                $available = max(1, (int)$stock[$key]);
                $q = min(random_int(1, 3), $available);
            }
            $stock[$key] -= $q;
            $customization = [];
            if ($c['o']) {
                $customization[$c['o']['field_key']] = $c['o']['option_value'];
                $customization[$c['o']['field_label']] = $c['o']['option_value'];
            }
            $optionSummary = $customization !== [] ? 'Options: ' . implode(', ', array_unique(array_map('strval', array_values($customization)))) . ' | ' : '';
            $rows[] = ['type' => 'ready', 'business_at' => date('Y-m-d H:i:s', $ts), 'production_at' => '-', 'completion_at' => '-', 'branch_id' => $branch, 'branch_name' => $b['branch_name'], 'name' => $c['p']['name'], 'product_id' => (int)$c['p']['id'], 'quantity' => $q, 'unit_price' => (float)$c['p']['price'], 'total' => (float)$c['p']['price'] * $q, 'customization' => $customization, 'expected_usage' => $optionSummary . 'Ready-made stock: ' . $q . ' pcs', '_stock_key' => $key];
            $usage['ready:' . (int)$c['p']['id']] = (int)($usage['ready:' . (int)$c['p']['id']] ?? 0) + 1;
            $ready++;
        } else {
            $serviceChoices = htg_least_used_services($services, $usage);
            $s = $desired !== null ? htg_choose_target_service($serviceChoices, $desired) : $serviceChoices[array_rand($serviceChoices)];
            $plan = $s['_htg_plan'] ?? htg_material_plan(JobOrderService::inferServiceTypeFromProduct((string)$s['category'], (string)$s['name']), 1, (int)$s['id']);
            $at = new DateTimeImmutable(date('Y-m-d H:i:s', $ts), htg_business_timezone());
            [$prod, $done] = htg_stage_times($at, $now);
            $ph = db_query("SELECT product_id FROM products WHERE sku='POS-SERVICE' LIMIT 1") ?: [];
            $price = (float)($s['_htg_price'] ?? 0);
            $rows[] = ['type' => 'custom', 'business_at' => $at->format('Y-m-d H:i:s'), 'production_at' => $prod->format('Y-m-d H:i:s'), 'completion_at' => $done->format('Y-m-d H:i:s'), 'branch_id' => $branch, 'branch_name' => $b['branch_name'], 'name' => $s['name'], 'service_name' => $s['name'], 'service_type' => JobOrderService::inferServiceTypeFromProduct((string)$s['category'], (string)$s['name']), 'product_id' => (int)($ph[0]['product_id'] ?? 0), 'quantity' => 1, 'unit_price' => $price, 'total' => $price, 'customization' => array_merge($s['_htg_customization'] ?? [], ['source' => 'HISTORICAL_GENERATOR', 'service_id' => (int)$s['id'], 'service_type' => $s['name'], 'specifications' => 'Historical walk-in service transaction from existing service catalog.', 'dimensions' => $plan['dimensions'] ?? null]), 'service_id' => (int)$s['id'], 'materials' => $plan['materials'], 'expected_usage' => $plan['label'] . ($plan['dimensions'] ? ' | Dimensions: ' . $plan['dimensions'] : '')];
            $usage['custom:' . (int)$s['id']] = (int)($usage['custom:' . (int)$s['id']] ?? 0) + 1;
            $custom++;
        }
    }
    if (count($rows) !== $count) throw new RuntimeException('The requested mix cannot be generated from current stock.');
    htg_apply_target_sales($rows, $stock, $targetSales);
    usort($rows, static fn($a, $b) => strcmp($a['business_at'], $b['business_at']));
    $generatedSales = htg_sales_total($rows);
    return ['token' => bin2hex(random_bytes(24)), 'expires_at' => time() + 900, 'start' => $start, 'end' => $end, 'branch_id' => $branch, 'branch_name' => $b['branch_name'], 'reason' => $reason, 'target_sales' => $targetSales, 'generated_sales' => $generatedSales, 'difference' => $targetSales === null ? null : round($generatedSales - $targetSales, 2), 'skipped_services' => $skippedServices, 'rows' => $rows];
}
$csrf=function_exists('generate_csrf_token')?generate_csrf_token():($_SESSION['csrf_token']??'');$today=htg_now()->format('Y-m-d');$branches=db_query("SELECT id,branch_name FROM branches WHERE status!='Archived' ORDER BY branch_name")?:[];$old=$_POST;$selectionProducts=htg_catalog('products');$selectionServices=htg_catalog('services');
ob_start(static function (string $html) use ($selectionProducts,$selectionServices): string {
    $html = str_replace('name="reason" required', 'name="reason"', $html);
    $targetValue = htg_h((string)($_POST['target_total_sales'] ?? ''));
    $html = str_replace(
        '<label>Reason / Note',
        '<label>Target Total Sales (optional)<input type="number" name="target_total_sales" min="0.01" step="0.01" placeholder="e.g. 75000" value="' . $targetValue . '"></label><label>Reason / Note',
        $html
    );
    $selectedReady = array_map('intval', (array)($_POST['selected_ready_ids'] ?? []));
    $selectedServices = array_map('intval', (array)($_POST['selected_service_ids'] ?? []));
    $selection = '<fieldset style="grid-column:1/-1;border:1px solid #d9e2e8;border-radius:6px;padding:14px"><legend><strong>Products / Services to Include</strong></legend><div style="display:flex;gap:8px;margin-bottom:10px"><button type="button" onclick="document.querySelectorAll(\'input[data-generator-item]\').forEach(function(e){e.checked=true})">Select All</button><button type="button" onclick="document.querySelectorAll(\'input[data-generator-item]\').forEach(function(e){e.checked=false})">Clear All</button></div><div style="display:grid;grid-template-columns:repeat(3,minmax(180px,1fr));gap:8px">';
    foreach ($selectionProducts as $product) {
        $id = (int)$product['id'];
        $checked = in_array($id, $selectedReady, true) ? ' checked' : '';
        $selection .= '<label><span><input data-generator-item type="checkbox" name="selected_ready_ids[]" value="' . $id . '"' . $checked . '> Ready-made: ' . htg_h((string)$product['name']) . '</span></label>';
    }
    foreach ($selectionServices as $service) {
        $id = (int)$service['id'];
        $checked = in_array($id, $selectedServices, true) ? ' checked' : '';
        $selection .= '<label><span><input data-generator-item type="checkbox" name="selected_service_ids[]" value="' . $id . '"' . $checked . '> Service: ' . htg_h((string)$service['name']) . '</span></label>';
    }
    $selection .= '</div></fieldset>';
    $html = str_replace('<label>Reason / Note', $selection . '<label>Reason / Note', $html);
    $stored = $_SESSION['historical_generator_preview'] ?? [];
    if (($stored['target_sales'] ?? null) !== null) {
        $target = (float)$stored['target_sales'];
        $difference = (float)$stored['difference'];
        $outsideTolerance = $target > 0 && abs($difference) > ($target * 0.05);
        $differencePercent = $target > 0 ? abs($difference) / $target * 100 : 0;
        $status = $outsideTolerance ? 'Outside tolerance' : 'Within tolerance';
        $warning = $outsideTolerance ? ' The closest safe configured combination is outside the preferred 5% tolerance; no unsupported prices were used.' : '';
        $summary = '<div class="notice"><strong>Target Sales:</strong> PHP ' . number_format($target, 2) . ' &nbsp; <strong>Generated Sales:</strong> PHP ' . number_format((float)$stored['generated_sales'], 2) . ' &nbsp; <strong>Difference:</strong> PHP ' . number_format($difference, 2) . ' &nbsp; <strong>Difference %:</strong> ' . number_format($differencePercent, 2) . '% &nbsp; <strong>Status:</strong> ' . htmlspecialchars($status, ENT_QUOTES, 'UTF-8') . htmlspecialchars($warning, ENT_QUOTES, 'UTF-8') . '</div>';
        $html = str_replace('<h2>Preview (nothing has been saved)</h2>', '<h2>Preview (nothing has been saved)</h2>' . $summary, $html);
    }
    if (!empty($stored['skipped_services'])) {
        $skipped = htmlspecialchars(implode(' ', $stored['skipped_services']), ENT_QUOTES, 'UTF-8');
        $html = str_replace('The current job API does not expose a separate historical production timestamp.', 'Skipped services: ' . $skipped . ' The current job API does not expose a separate historical completion timestamp.', $html);
    }
    return $html;
});
if($_SERVER['REQUEST_METHOD']==='POST')try{if(!verify_csrf_token($_POST['csrf_token']??''))throw new RuntimeException('Invalid security token.');$action=(string)($_POST['action']??'preview');if($action==='preview'){$reason=trim((string)($_POST['reason']??''));if(strlen($reason)>1000)throw new RuntimeException('Enter a reason of 1,000 characters or fewer.');$targetRaw=trim((string)($_POST['target_total_sales']??''));$targetSales=null;if($targetRaw!==''){$normalizedTarget=preg_replace('/[^0-9.]+/','',$targetRaw);if($normalizedTarget===''||!is_numeric($normalizedTarget))throw new RuntimeException('Enter a valid positive Target Total Sales amount.');$targetSales=(float)$normalizedTarget;if($targetSales<=0)throw new RuntimeException('Target Total Sales must be positive.');}$selectedReadyIds=(array)($_POST['selected_ready_ids']??[]);$selectedServiceIds=(array)($_POST['selected_service_ids']??[]);$preview=htg_preview((string)($_POST['start_date']??''),(string)($_POST['end_date']??''),(int)($_POST['branch_id']??0),(int)($_POST['transaction_count']??0),(int)($_POST['ready_pct']??0),(int)($_POST['custom_pct']??0),htg_catalog('products'),htg_catalog('services'),$reason,$targetSales,$selectedReadyIds,$selectedServiceIds);$_SESSION['historical_generator_preview']=$preview;}elseif($action==='confirm'){$token=trim((string)($_POST['preview_token']??''));$preview=$_SESSION['historical_generator_preview']??null;if(!$preview||!hash_equals((string)$preview['token'],$token)||(int)$preview['expires_at']<time())throw new RuntimeException('Preview expired. Generate a new preview.');if(isset($_SESSION['historical_generator_used'][$token]))throw new RuntimeException('This preview was already processed.');if(!htg_schema_ready())throw new RuntimeException('Required order or inventory fields are missing.');$conn->begin_transaction();$ids=[];try{foreach($preview['rows'] as $r)$ids[]=htg_insert($r,$token.'-'.count($ids),$preview['reason']);if(!$conn->commit())throw new RuntimeException('Batch commit failed.');}catch(Throwable $e){if(printflow_db_in_transaction($conn))$conn->rollback();throw $e;}$_SESSION['historical_generator_used'][$token]=true;$_SESSION['historical_generator_preview']=null;log_activity((int)get_user_id(),'Historical transaction batch generated',json_encode(['orders'=>$ids,'preview_token'=>$token,'real_processed_at'=>date('Y-m-d H:i:s')],JSON_UNESCAPED_SLASHES));$success='Generated '.count($ids).' historical transaction(s).';$preview=null;}else throw new RuntimeException('Invalid action.');}catch(Throwable $e){$error=$e->getMessage();}
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Historical Transaction Generator</title><style>body{font-family:Arial;background:#f4f7f9;color:#173047;margin:0}.wrap{max-width:1180px;margin:30px auto;padding:20px}.panel{background:#fff;border:1px solid #d9e2e8;border-radius:10px;padding:24px;margin:18px 0}.notice,.warning,.error,.success{padding:14px;border-radius:7px;margin:14px 0}.notice{background:#fff7d6}.warning{background:#eaf4ff}.error{background:#ffe6e6;color:#a21919}.success{background:#e5f8eb;color:#176b35}.grid{display:grid;grid-template-columns:repeat(4,1fr);gap:14px}label{font-weight:700;font-size:13px;display:flex;flex-direction:column;gap:6px}input,select,textarea{font:inherit;padding:10px;border:1px solid #b8c9d4;border-radius:6px}textarea{min-height:70px}button{padding:11px 16px;border:0;border-radius:6px;background:#0b6174;color:#fff;font-weight:700}table{width:100%;border-collapse:collapse;font-size:13px}th,td{padding:9px;border-bottom:1px solid #e2e9ee;text-align:left}th{background:#f3f7f9}@media(max-width:800px){.grid{grid-template-columns:repeat(2,1fr)}}@media(max-width:520px){.grid{grid-template-columns:1fr}}</style></head><body><main class="wrap"><h1>Historical Transaction Generator</h1><p>Admin-only preview tool for historical walk-in transactions.</p><div class="notice"><strong>Use this page only for real historical business transactions.</strong> Stock will be deducted from current inventory. Enter transactions from oldest to newest.</div><?php if($error):?><div class="error"><?=htg_h($error)?></div><?php endif;?><?php if($success):?><div class="success"><?=htg_h($success)?></div><?php endif;?><section class="panel"><form method="post"><input type="hidden" name="csrf_token" value="<?=htg_h((string)$csrf)?>"><div class="grid"><label>Start Date<input type="date" name="start_date" max="<?=$today?>" value="<?=htg_h((string)($old['start_date']??$today))?>" required></label><label>End Date<input type="date" name="end_date" max="<?=$today?>" value="<?=htg_h((string)($old['end_date']??$today))?>" required></label><label>Branch<select name="branch_id" required><option value="">Select branch</option><?php foreach($branches as $b):?><option value="<?=htg_h((string)$b['id'])?>"><?=htg_h((string)$b['branch_name'])?></option><?php endforeach;?></select></label><label>Number of Transactions<input type="number" name="transaction_count" min="1" max="500" value="10" required></label><label>Ready-Made %<input type="number" name="ready_pct" min="0" max="100" value="100" required></label><label>Custom/Service %<input type="number" name="custom_pct" min="0" max="100" value="0" required></label><label>Reason / Note<textarea name="reason" required>Historical transaction backfill</textarea></label></div><p>Walk-in customer only · Cash only · Paid only</p><button name="action" value="preview">Generate Preview</button></form></section><?php if(is_array($preview)&&!empty($preview['rows'])):?><section class="panel"><h2>Preview (nothing has been saved)</h2><div style="overflow:auto"><table><tr><th>Date/time</th><th>Type</th><th>Product/service</th><th>Qty</th><th>Total</th><th>Production</th><th>Completion</th><th>Expected usage</th></tr><?php foreach($preview['rows'] as $r):?><tr><td><?=htg_h($r['business_at'])?></td><td><?=htg_h($r['type'])?></td><td><?=htg_h($r['name'])?></td><td><?=htg_h((string)$r['quantity'])?></td><td>PHP <?=number_format((float)$r['total'],2)?></td><td><?=htg_h($r['production_at'])?></td><td><?=htg_h($r['completion_at'])?></td><td><?=htg_h($r['expected_usage'])?></td></tr><?php endforeach;?></table></div><form method="post" onsubmit="return confirm('Generate this exact preview?');"><input type="hidden" name="csrf_token" value="<?=htg_h((string)$csrf)?>"><input type="hidden" name="preview_token" value="<?=htg_h($preview['token'])?>"><button name="action" value="confirm">Confirm and Generate</button></form><p class="warning">Custom/service rows create paid pending jobs and keep material deductions on the normal production workflow. The current job API does not expose a separate historical production timestamp.</p></section><?php endif;?></main></body></html>
