<?php
declare(strict_types=1);

/** Strict branch ownership helpers for physical inventory rolls. */
function printflow_roll_belongs_to_branch($rollBranchId, int $branchId): bool
{
    $rollBranchId = filter_var($rollBranchId, FILTER_VALIDATE_INT);
    return $branchId > 0 && $rollBranchId !== false && (int)$rollBranchId > 0 && (int)$rollBranchId === $branchId;
}

/** Return an exact positive branch filter; null/zero roll rows never match a branch. */
function printflow_roll_branch_sql(string $column, int $branchId): array
{
    if (!preg_match('/^[A-Za-z_][A-Za-z0-9_.]*$/D', $column)) {
        throw new InvalidArgumentException('A safe roll branch column is required.');
    }
    if ($branchId <= 0) {
        throw new InvalidArgumentException('A positive authorized branch is required for roll inventory.');
    }
    return [" AND {$column} = ?", 'i', [$branchId]];
}
