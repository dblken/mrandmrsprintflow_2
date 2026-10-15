# Reports Material-Cost Architecture

## Current temporary basis

Reports currently presents **Estimated Material Cost** using production assignments in
`job_order_materials` and the captured `unit_cost_at_assignment`. This is an estimate,
not confirmed actual COGS, because an assignment can differ from the physical quantity
that was ultimately consumed.

Normal production keeps ink expense-only: material deduction calls use `inks = false`,
and Reports does not include `job_order_ink_usage` in material costing.

## Future authoritative basis

1. `inventory_transactions` rows with `direction = 'OUT'` are authoritative for the
   consumed quantity and production reference.
2. Receiving/FIFO cost layers are authoritative for the historical cost assigned to
   each consumed quantity, including roll-material layers.
3. `job_order_materials` remains the planned/assigned production requirement and a
   reconciliation source, not the final actual-consumption ledger.

Before implementing this design, the system should add an immutable cost snapshot to
each qualifying OUT movement, such as `unit_cost_at_transaction` and
`total_cost_at_transaction`, or persist an immutable receiving-layer reference. This
requires a reviewed migration and backfill strategy; no fields are added by the
current reporting correction.
