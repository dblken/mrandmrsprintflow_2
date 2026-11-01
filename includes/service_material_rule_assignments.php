<?php
declare(strict_types=1);

require_once __DIR__ . '/pos_service_stock_helpers.php';

/** Return only REQUIRED rules after checking explicit multiplier approval. */
function printflow_filter_approved_required_material_rules(array $rules, string $serviceLabel = 'service'): array
{
    $approvedRules = [];
    foreach ($rules as $rule) {
        if (!is_array($rule)) continue;
        $ruleType = strtoupper(trim((string)($rule['rule_type'] ?? '')));
        if ($ruleType === 'OPTIONAL') continue;
        if ($ruleType !== 'REQUIRED') {
            throw new RuntimeException('Material rule configuration for ' . $serviceLabel . ' has an unsupported rule type. Ask an administrator to review it.');
        }
        $itemId = (int)($rule['item_id'] ?? 0);
        if ($itemId <= 0) throw new RuntimeException('A REQUIRED material rule for ' . $serviceLabel . ' has no valid inventory item.');
        if (array_key_exists('item_status', $rule) && strtoupper(trim((string)$rule['item_status'])) !== 'ACTIVE') {
            throw new RuntimeException('REQUIRED material item #' . $itemId . ' for ' . $serviceLabel . ' is not active in inventory. No automatic assignment was created.');
        }
        try {
            $rule['approved_multiplier'] = printflow_pos_approved_service_multiplier(
                $rule['qty_multiplier'] ?? null,
                $rule['qty_multiplier_approved'] ?? null,
                $rule['qty_multiplier_approved_by'] ?? null,
                $rule['qty_multiplier_approved_at'] ?? null
            );
        } catch (RuntimeException $error) {
            throw new RuntimeException(
                'REQUIRED material item #' . $itemId . ' for ' . $serviceLabel . ' has no valid Admin-approved multiplier and audit. An Admin must configure and approve its multiplier before the order can be assigned.',
                0,
                $error
            );
        }
        $approvedRules[] = $rule;
    }
    return $approvedRules;
}

/** Build automatic assignments from REQUIRED rules only; OPTIONAL rows are user choices. */
function printflow_build_approved_service_material_assignments(array $rules, int $serviceQuantity, float $heightFt, string $serviceLabel = 'service'): array
{
    if ($serviceQuantity < 1 || !is_finite($heightFt) || $heightFt < 0) {
        throw new InvalidArgumentException('A positive service quantity and valid dimensions are required to assign materials.');
    }

    $assignments = [];
    foreach (printflow_filter_approved_required_material_rules($rules, $serviceLabel) as $rule) {
        $itemId = (int)($rule['item_id'] ?? 0);
        $multiplier = (float)$rule['approved_multiplier'];
        $quantity = $serviceQuantity * $multiplier;
        $computedLength = $heightFt > 0 ? $heightFt * $serviceQuantity * $multiplier : 0.0;
        if (!is_finite($quantity) || $quantity <= 0 || !is_finite($computedLength)) {
            throw new RuntimeException('Approved material demand for item #' . $itemId . ' could not be calculated safely.');
        }
        $assignments[] = [
            'item_id' => $itemId,
            'quantity' => $quantity,
            'uom' => $heightFt > 0 ? 'ft' : 'pcs',
            'computed_len' => $computedLength,
        ];
    }
    return $assignments;
}
