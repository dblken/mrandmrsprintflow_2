<?php
/**
 * Resolve POS Set Price draft-order links for checkout material revalidation.
 *
 * After Set Price, the cart line's pending_order_id is the authoritative link.
 * The session map pos_pending_orders[serviceId] is only a fallback and can be
 * missing/stale after update_service_link without rewriting the map.
 */

/**
 * Match a checkout payload service line to the server-authored POS cart row.
 */
function printflow_pos_checkout_match_session_service_line(array $item, array $sessionCart): ?array
{
    $lineId = trim((string)($item['pos_cart_line_id'] ?? ''));
    if ($lineId !== '') {
        $matches = [];
        foreach ($sessionCart as $sessionItem) {
            if (!is_array($sessionItem)) {
                continue;
            }
            if (!hash_equals(trim((string)($sessionItem['pos_cart_line_id'] ?? '')), $lineId)) {
                continue;
            }
            $matches[] = $sessionItem;
        }
        if (count($matches) === 1) {
            return $matches[0];
        }
    }

    $productId = (int)($item['id'] ?? $item['product_id'] ?? 0);
    if ($productId <= 0) {
        return null;
    }

    $matches = [];
    foreach ($sessionCart as $sessionItem) {
        if (!is_array($sessionItem)) {
            continue;
        }
        if ((int)($sessionItem['product_id'] ?? 0) !== $productId) {
            continue;
        }
        $isService = !empty($sessionItem['is_service']);
        if (!$isService) {
            $custom = is_array($sessionItem['customization'] ?? null) ? $sessionItem['customization'] : [];
            $isService = !empty($custom['service_id']) || !empty($custom['service_type']);
        }
        if (!$isService) {
            continue;
        }
        $matches[] = $sessionItem;
    }

    return count($matches) === 1 ? $matches[0] : null;
}

/**
 * Resolve which draft order ID should be used to revalidate Production Assignment
 * materials for a POS service checkout line.
 *
 * @param array<int|string,int|string> $pendingOrdersByServiceId
 * @return array{status:string,pending_order_id:int,product_id:int,message:?string}
 */
function printflow_pos_checkout_resolve_service_pending_order(
    array $item,
    array $pendingOrdersByServiceId,
    array $sessionCart = []
): array {
    $productId = (int)($item['id'] ?? $item['product_id'] ?? 0);
    $sessionLine = printflow_pos_checkout_match_session_service_line($item, $sessionCart);
    if ($sessionLine !== null) {
        $sessionProductId = (int)($sessionLine['product_id'] ?? 0);
        if ($sessionProductId > 0) {
            $productId = $sessionProductId;
        }
    }

    $sessionLinePending = $sessionLine !== null ? (int)($sessionLine['pending_order_id'] ?? 0) : 0;
    $payloadPending = (int)($item['pending_order_id'] ?? 0);
    $mapPending = 0;
    if ($productId > 0) {
        if (array_key_exists($productId, $pendingOrdersByServiceId)) {
            $mapPending = (int)$pendingOrdersByServiceId[$productId];
        } elseif (array_key_exists((string)$productId, $pendingOrdersByServiceId)) {
            $mapPending = (int)$pendingOrdersByServiceId[(string)$productId];
        }
    }

    // Authoritative order: server cart line → payload → session map.
    $pendingOrderId = 0;
    if ($sessionLinePending > 0) {
        $pendingOrderId = $sessionLinePending;
    } elseif ($payloadPending > 0) {
        $pendingOrderId = $payloadPending;
    } elseif ($mapPending > 0) {
        $pendingOrderId = $mapPending;
    }

    if ($pendingOrderId <= 0) {
        return [
            'status' => 'skip',
            'pending_order_id' => 0,
            'product_id' => $productId,
            'message' => null,
        ];
    }

    if ($productId <= 0) {
        return [
            'status' => 'error',
            'pending_order_id' => 0,
            'product_id' => 0,
            'message' => 'The assigned service materials could not be verified for this POS item. Open Set Price again and retry.',
        ];
    }

    return [
        'status' => 'ok',
        'pending_order_id' => $pendingOrderId,
        'product_id' => $productId,
        'message' => null,
    ];
}

/**
 * Format a branch stock shortage from JobOrderService::validatePendingMaterialStockPublic.
 */
function printflow_pos_checkout_material_stock_issue_message(array $issue): string
{
    $uom = trim((string)($issue['uom'] ?? ''));
    $required = rtrim(rtrim(number_format((float)($issue['required'] ?? 0), 3, '.', ''), '0'), '.');
    $available = rtrim(rtrim(number_format((float)($issue['available'] ?? 0), 3, '.', ''), '0'), '.');
    $name = trim((string)($issue['name'] ?? 'Material'));
    if ($name === '') {
        $name = 'Material';
    }
    return $name . ' has only ' . $available . ($uom !== '' ? ' ' . $uom : '')
        . ' available; ' . $required . ($uom !== '' ? ' ' . $uom : '') . ' required.';
}

/**
 * Evaluate assigned-material stock for one resolved pending order.
 * Callables keep unit tests free of live DB / inventory writes.
 *
 * @param callable(int,int):int $findJobId function(pendingOrderId, pendingItemId): jobId
 * @param callable(int,int):array $validateStock function(jobId, qty): list of shortage issues
 */
function printflow_pos_checkout_evaluate_pending_material_stock(
    int $pendingOrderId,
    int $pendingItemId,
    int $qty,
    callable $findJobId,
    callable $validateStock
): ?string {
    if ($pendingOrderId <= 0 || $qty < 1) {
        return null;
    }
    $jobId = (int)$findJobId($pendingOrderId, $pendingItemId);
    if ($jobId <= 0) {
        return null;
    }
    try {
        $issues = $validateStock($jobId, $qty);
    } catch (RuntimeException $stockError) {
        return $stockError->getMessage();
    }
    if (!is_array($issues) || $issues === []) {
        return null;
    }
    $issue = is_array($issues[0] ?? null) ? $issues[0] : [];
    return printflow_pos_checkout_material_stock_issue_message($issue);
}
