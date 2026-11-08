<?php
declare(strict_types=1);

require_once __DIR__ . '/pos_service_stock_helpers.php';

/** Build a prepared-query identity scope, preferring stable service IDs. */
function printflow_service_material_rule_identity_scope(int $serviceId, array $serviceTypes, string $alias = 'r', bool $hasServiceIdColumn = true): array
{
    if (!preg_match('/^[a-zA-Z][a-zA-Z0-9_]*$/', $alias) || $serviceId < 1 || $serviceTypes === []) {
        throw new InvalidArgumentException('A valid service identity scope is required.');
    }
    $serviceTypes = array_values(array_unique(array_filter(array_map(
        static fn($value): string => trim((string)$value),
        $serviceTypes
    ), static fn(string $value): bool => $value !== '')));
    if ($serviceTypes === []) throw new InvalidArgumentException('At least one explicit service type is required.');
    $marks = implode(',', array_fill(0, count($serviceTypes), '?'));
    if ($hasServiceIdColumn) {
        return [
            'sql' => "({$alias}.service_id = ? OR ({$alias}.service_id IS NULL AND {$alias}.service_type IN ({$marks})))",
            'types' => 'i' . str_repeat('s', count($serviceTypes)),
            'params' => array_merge([$serviceId], $serviceTypes),
        ];
    }
    return [
        'sql' => "{$alias}.service_type IN ({$marks})",
        'types' => str_repeat('s', count($serviceTypes)),
        'params' => $serviceTypes,
    ];
}

/** Resolve a legacy text key only through the explicit, verified service alias registry. */
function printflow_service_material_rule_legacy_service_id(string $serviceType): ?int
{
    $serviceType = trim($serviceType);
    if ($serviceType === '') return null;
    foreach ([36, 39, 64, 67, 71, 40] as $serviceId) {
        $aliases = printflow_pos_service_material_type_aliases($serviceId);
        if ($serviceId === 40) $aliases = ['Tarpaulin Printing'];
        foreach ($aliases as $alias) {
            if ($serviceType === $alias) return $serviceId;
        }
    }
    return null;
}

/** Stable identity wins; legacy text is used only for rows not yet backfilled. */
function printflow_service_material_rule_row_service_id(array $row): ?int
{
    $stableId = (int)($row['service_id'] ?? 0);
    $serviceType = (string)($row['service_type'] ?? '');
    if ($stableId > 0) {
        $resolvedTextId = printflow_service_material_rule_legacy_service_id($serviceType);
        return $resolvedTextId === $stableId ? $stableId : null;
    }
    return printflow_service_material_rule_legacy_service_id($serviceType);
}
