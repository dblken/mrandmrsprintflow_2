# Demo deletion status audit — 2026-10-08

**Live database audit pending. No production records deleted or modified.**

## Evidence currently available

The Admin screenshot shows these three labels, each `rolled_back` with registry rows `0`:

| Screenshot label only | Database-confirmed ID | Actual related counts | Deletion classification |
| --- | --- | --- | --- |
| `meet_20261001_full_v2` | Not yet verified | Unknown | Cannot safely verify |
| `meet_20261001_v2` | Not yet verified | Unknown | Cannot safely verify |
| `meet_20261001_v1` | Not yet verified | Unknown | Cannot safely verify |

These are screenshot observations, not a query result. Neither the Staff screenshot nor the Admin zero-row message proves deletion. The summary's imported orders/sales are historical import totals, not remaining-row counts.

## Source audit

- Registry: `demo_seed_rows`, linked by exact `batch_id`, `seed_row_key`, order/item/customer/customization/job IDs and `customer_created`.
- Import metadata: `demo_seed_batches`; records remain for historical audit after successful deletion. There is no separate imported-orders table: the importer writes normal `orders`, `order_items`, `customers`, `customizations`, `job_orders`, optional status history, plus hidden `_seed_batch_id` JSON witnesses.
- `DemoSeedDeletionTool::batches()` selects **all** metadata batches and adds a registry count. It does not filter completed batches or audit remaining related tables. This is why historical labels can remain.
- `admin/settings.php` renders those returned rows as options. After deletion its JavaScript relabels the selected option and refreshes only the preview; it does not reload the batch list or remove options.
- `admin/api/demo_seed_data.php` retains Admin/CSRF protection; its maintenance read actions skip demo table creation. Its delete action requires exact batch, phrase, backup acknowledgement and a bound preview token, then uses the transactional deletion module.
- The preview starts at registry IDs and hidden markers in order items/customizations, expands exact relationships, and protects shared or unproven rows. Once those witnesses are gone, it cannot attribute unmarked orphans. A zero preview is therefore evidence of zero **discoverable verified** records, not exhaustive historical proof.
- Staff actually uses `admin/job_orders_api.php` actions `list_orders`, `list_pending_orders`, `customization_counts`, with source/branch/date/status/pagination filtering. Absence from that page needs exact-ID comparison with database results and the actual list responses.

## Read-only evidence collector

New files:

- `includes/demo_seed_audit.php`: SELECT-only adapter, same relationship planner, additional exact marker search across schema tables, per-table counts and IDs, explicit uncertainty.
- `scripts/audit_demo_seed_batches.php`: CLI only, reads existing DB environment and outputs JSON. It does not create demo tables, change metadata/status, invoke deletion, or write activity logs.
- `tests/demo_seed_read_only_audit_test.php`: isolated fixture checks.

Run on an approved environment with a **SELECT-only database account**, or a restored export. Never put DB passwords in a shell command or chat. The configuration check prints booleans only:

```powershell
php scripts/audit_demo_seed_batches.php --check-config
php scripts/audit_demo_seed_batches.php
```

The second command queries every `demo_seed_batches` row, so it discovers database IDs rather than assuming the screenshot names or only three batches. Retain its JSON securely; it contains internal IDs but excludes customer names/payment payloads. Generic schema tables can have other identifier types. Run against a quiet snapshot: successive SELECTs are not an atomic snapshot under concurrent writes.

Counts distinguish `verified`, `protected_or_unverified`, and `related_total`. An absent schema table is marked `schema_present=false`; it is not represented as a successfully queried zero count. Inventory/payment table names come from actual schema, not only canonical names.

Additional markers outside importer roots are retained for manual review. Their unmarked descendants need a separate exact-ID relationship audit; a zero `related_total` is not a global table zero or a guarantee against lost-lineage orphans.

Local validation: PHP syntax checks passed; 7 read-only audit, 16 import, 26 transaction, 15 API, and 14 UI fixture checks passed. Transaction tests cover partial deletion, real FK failure, rollback, count mismatch, shared customers, real-record protection and repeated requests. The UI fixture verifies preview refresh, not the requested dropdown refresh, which is deferred until live audit completion. No production workflow or post-delete query verification has run.

`Not Found` means no discoverable related rows, **not Fully Deleted**. `Partially Deleted` is only used when retained deletion metadata says `partial`/`rolled_back` and related rows remain. Other uncertain cases say `Cannot Safely Verify`; `Hidden Only` requires actual Staff filter/API evidence. Do not force a four-way classification when evidence is missing.

## Next live steps

1. Establish the approved read-only connection or supply a full export and the three original CSVs/pre-delete backup. Local host/name/user/password are currently unset; browser inventory has no connected tabs.
2. Run the collector and retain all exact database IDs, table counts, registry links, ownership proofs and unverified IDs. Compare the three screenshot labels with returned database metadata.
3. If registry witnesses were removed, recover original linked IDs from the pre-delete backup or retained deletion receipt containing exact IDs. Re-query every FK/application child and relevant polymorphic reference. Counts-only receipts do not recover original IDs.
4. Compare database orders with the actual Staff list/count responses and filters. Record `Hidden Only` only if surviving demo rows are demonstrably excluded by those filters.
5. Only after this audit, filter dropdown options using the completed database audit. Keep history internally, hide confirmed completed batches, refresh `list_batches` after deletion, and show `No undeleted demo batches found.` when none remain. Do not hide unresolved batches based on a registry zero alone.
6. If verified records remain, review the existing dry run and safeguards before adding/enabling the requested **Delete Verified Demo Batch** action. Any ambiguous record stays `Unverified — Not Deleted`. Do not delete during this audit.
7. After a separately approved deletion, re-query every related table and compare protected real IDs, counts and balances to the baseline. Exercise POS, Staff, Admin, customer orders, reports, payments, inventory and dashboards without mutation where possible.

Dropdown/deletion production changes are deferred until live audit completion as requested. No live counts, full deletion, real-workflow regression proof or dropdown-refresh success is claimed.
