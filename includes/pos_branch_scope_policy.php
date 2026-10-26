<?php
/** Pure branch-policy checks used by the operational POS scope helper. */

function printflow_pos_branch_from_authorized_context(array $context): int
{
    $selected = $context['selected_branch_id'] ?? 0;
    if ($selected === 'all') throw new RuntimeException('Select one authorized branch before using the POS.');
    $branchId = (int)$selected;
    if ($branchId <= 0) throw new RuntimeException('The authorized POS branch could not be identified.');
    $allowed = $context['allowed_branches'] ?? [];
    if ($allowed !== 'all' && (!is_array($allowed) || !in_array($branchId, array_map('intval', $allowed), true))) {
        throw new RuntimeException('The selected branch is not authorized for this POS session.');
    }
    return $branchId;
}

