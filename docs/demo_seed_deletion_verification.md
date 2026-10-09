# CSV demo batch deletion

Local implementation only. Production deletion and post-delete verification have not run.

## Why the previous screen was blocked

Deletion reused `demo_seed_verify_batch_integrity()`, which validates a newly imported batch. It requires the original customer/item/customization/job counts to equal the CSV row count and rejects material/payment rows. These assumptions do not establish whether current rows can safely be deleted. The screen also hid the specific integrity errors behind a generic warning. The screenshot's `DELETING MEETING DATA` is independently incorrect: the exact phrase is `DELETE MEETING DATA`.

The precise live failing count cannot be recovered from that screenshot. The current tool reports missing IDs, incomplete registry entries, conflicting markers, ownership evidence, and every protected related row.

## Files to deploy together

- `includes/demo_seed_deletion.php` (new dependency)
- `includes/demo_seed_data.php`
- `admin/api/demo_seed_data.php`
- `admin/settings.php`

The new file is required by `includes/demo_seed_data.php`. Deploy it together with the three updated files.

## Ownership and dependency checks

- Exact batch selection comes from `demo_seed_batches`; deletion never defaults to the latest batch.
- `demo_seed_rows` IDs and exact hidden `_seed_batch_id` JSON markers establish ownership. Conflicting batch claims are protected.
- Missing parent rows can still have verified descendants through their original registry IDs. Unknown references retain the registry/marker evidence.
- Current table columns, primary keys, engines, and foreign keys are read from MySQL `INFORMATION_SCHEMA` on every dry run. The preview exposes this metadata and the actual deletion order.
- Registry rows are removed last unless a live foreign key requires them before a parent. No foreign-key checks are disabled.
- The core application relationships explicitly written by the CSV importer also establish links for schemas without physical FK constraints. A column name alone in an unrelated table is never sufficient proof.
- Unmarked financial/inventory records, deducted material assignments, ambiguous `notifications.data_id` matches, unsupported composite keys, and shared records are protected. Their parents are retained if deleting them would cascade or unlink protected records.
- Only customers with explicit `customer_created=1` proof and no non-target order or protected reference can be deleted.
- Deletion uses only the preview's exact primary-key IDs. A session token binds the batch, mode, admin, schema and plan; the locked plan must still match before any DELETE.
- All targeted tables must support transactions. Row counts, remaining target IDs, a fresh post-delete plan, and the audit insert are checked before commit.
- `activity_logs` records the admin ID, batch, start/end times, actual deleted counts, protected counts, and any failure. A failed success-audit insert rolls back deletion. Failure audits are written after rollback.
- Partial results explicitly report retained registry/protected rows and never claim the whole batch was deleted.
- A partial batch remains active for import protection, so another CSV import cannot silently replace its retained evidence.

## Local checks

```powershell
php -l includes/demo_seed_deletion.php
php -l includes/demo_seed_data.php
php -l admin/api/demo_seed_data.php
php -l admin/settings.php
php tests/demo_seed_validation_rules_test.php
php tests/demo_seed_deletion_transaction_test.php
php tests/demo_seed_deletion_api_test.php
node tests/demo_seed_deletion_ui_test.js
git diff --check
```

Transaction/FK tests use isolated SQLite fixtures and the same deletion engine. API tests execute an unchanged endpoint copy with fixture auth/bootstrap. UI tests use DOM/fetch fixtures. These do not prove production MySQL locking, production authentication, or rendered browser behavior.

## Live verification sequence

1. Deploy the four production files together, then open Admin → Settings → Demo Data.
2. Select the exact imported batch from the selector. Do not type or assume a remembered batch ID.
3. Click **Dry Run / Preview**. Demo maintenance uses SELECT queries for this action, with no demo deletion or demo maintenance table creation.
4. Review the batch ID, registry keys, each verified ID and ownership proof, protected IDs/reasons, and the inspected schema/deletion order. Ensure real/default orders are outside the verified list.
5. Check **Inventory transaction rows** and the individual inventory/material tables. Unmarked transaction rows must be protected. Any deducted material assignment requires separate review.
6. If the registry is incomplete, review each diagnostic. Select **Delete only verified records** only when the listed proof is adequate, then run Dry Run again. Protected records remain untouched.
7. Create a real database backup and retain it. The checkbox is an acknowledgement, not a backup creation tool.
8. Type exactly **DELETE MEETING DATA** and acknowledge the backup. Click **Delete Demo Data** to open the final per-table count dialog.
9. Review the selected batch, counts, and protected records in that dialog, then confirm once. A stale plan, SQL error, count mismatch, or audit failure rolls back the transaction.
10. Keep the result receipt showing actual deleted/protected counts and timestamps. A partial result means protected/unverified records remain; it is not complete deletion.
11. Review the automatically refreshed dry run, then refresh the page and run Dry Run again. Complete deletion must show zero verified rows and zero registry rows; an incomplete batch must retain its protected evidence.
12. Enter an old CSV `seed_row_key` and click **Trace row** with the same batch selected. Complete deletion must return `found=false`. A protected partial batch may still return its retained registry entry.
13. Run deletion/preview again safely: an empty batch reports **No demo records found**, and duplicate requests remove no additional rows.
14. Compare real POS orders, customers, payments, inventory/ledger, reports, Admin pages and Staff pages against the pre-delete backup/read-only baseline. Check Activity Logs for the deletion receipt or rollback failure reason.

Production batch IDs, protected/deleted counts and the final dependency order remain unknown until the live dry run is available. Never substitute fixture counts for production evidence.
