<?php

/**
 * Stable, deliberately small staff list-row contract.
 *
 * Keep this file dependency-free so the contract and payload ceiling can be
 * tested without a database or authenticated web session.
 */
function jo_api_summary_row(array $row): array
{
    static $allowed = [
        'id', 'order_id', 'job_order_id', 'order_item_id', 'customer_id', 'branch_id',
        'first_name', 'last_name', 'customer_full_name', 'customer_name',
        'customer_type', 'transaction_count', 'order_type', 'order_source', 'order_code',
        'service_type', 'job_title', 'width_ft', 'height_ft', 'quantity', 'status',
        'payment_proof_status', 'payment_status', 'provider_payment_status',
        'created_at', 'updated_at', 'order_date', 'due_date', 'priority',
        'estimated_total', 'amount_paid', 'required_payment', 'readiness', 'estimated_cost',
        'items',
    ];

    $summary = [];
    foreach ($allowed as $key) {
        if (array_key_exists($key, $row)) {
            $summary[$key] = $row[$key];
        }
    }
    return $summary;
}

/** @param array<int,array<string,mixed>> $rows */
function jo_api_summary_rows(array $rows): array
{
    return array_map('jo_api_summary_row', $rows);
}
