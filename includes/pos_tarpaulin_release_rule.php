<?php
/**
 * Confirm Set Price / Production Assignment rules for Tarpaulin (service 40).
 * Pure helpers — no inventory writes and no DB access.
 */

/**
 * Confirm Set Price Tarpaulin assignments must include at least one ACTIVE
 * track_by_roll inventory item. Optional non-roll extras may accompany it.
 * Does not invent or relax stock rules — callers still add demand separately.
 *
 * @param array<int, array<string, mixed>> $resolvedItems InventoryManager::getItem rows
 */
function printflow_pos_tarpaulin_assert_resolved_active_roll(array $resolvedItems): void
{
    $hasActiveRoll = false;
    foreach ($resolvedItems as $item) {
        if (!is_array($item)) {
            throw new RuntimeException('Select an active Tarpaulin roll before saving its actual usage.');
        }
        if (strtoupper((string)($item['status'] ?? 'ACTIVE')) !== 'ACTIVE') {
            throw new RuntimeException('Select an active Tarpaulin roll before saving its actual usage.');
        }
        if (!empty($item['track_by_roll'])) {
            $hasActiveRoll = true;
        }
    }
    if ($resolvedItems === [] || !$hasActiveRoll) {
        throw new RuntimeException('Select an active Tarpaulin roll before saving its actual usage.');
    }
}
