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

function printflow_pos_assert_cart_branch(int $branchId, bool $allowEmptyCart = false): void
{
    $cart = is_array($_SESSION['pos_cart'] ?? null) ? $_SESSION['pos_cart'] : [];
    $locked = (int)($_SESSION['pos_cart_branch_id'] ?? 0);
    if ($locked > 0 && $locked !== $branchId && $cart !== []) {
        throw new RuntimeException('The selected branch changed while this cart was open. Clear the cart and add the items again for the selected branch.');
    }
    if ($cart !== []) {
        $_SESSION['pos_cart_branch_id'] = $branchId;
    } elseif (!$allowEmptyCart || $locked <= 0) {
        $_SESSION['pos_cart_branch_id'] = $branchId;
    }
}
