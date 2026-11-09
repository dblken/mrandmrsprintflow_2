# Tarpaulin stock verification — 2026-11-09

## Scope and outcome

Target: mrandmrsprintflow.com, application root `/public_html/`, database `u618446170_printflow`. Authenticated POS role: Counter Staff, Cabuyao (branch 1). Code-only deployment through the authorized Hostinger File Manager route. No Git commit or push performed. No schema, inventory, ledger, mapping, cart, reservation, or order records changed.

The live modal now reports a roll-width reconciliation problem rather than an ordinary zero-stock shortage. Add to Order remains blocked. Successful allocation cannot be verified live until the physical widths of the existing rolls are measured and corrected through the audited workflow. Item configuration does not establish an individual roll's physical width.

## Confirmed production rows

All four items are ACTIVE, use ft, and have the configured width unit ft. All listed rolls are OPEN at branch 1; each has total length 2,000 ft.

| Item | Configured width | Roll | Saved width | Remaining ft | Received timestamp | Receipt ledger ID |
|---|---:|---:|---:|---:|---|---:|
| 13 — 3ft Tarpaulin | 3 | 155 | 1 | 53.60 | 2026-10-16 04:56:38 | 35841 |
| 14 — 4ft Tarpaulin | 4 | 154 | 1 | 45.10 | 2026-10-16 04:56:33 | 35840 |
| 15 — 5ft Tarpaulin | 5 | 157 | 1 | 50.10 | 2026-10-16 04:57:08 | 36880 |
| 16 — 6ft Tarpaulin | 6 | 156 | 1 | 50.00 | 2026-10-16 04:56:42 | 35842 |

Recorded remaining footage totals 198.80 ft; matching usable footage is zero because the saved physical widths conflict with item configuration. Admin totals sum remaining footage; they do not prove fit. Fresh read-only, fully qualified SQL confirmed these rows after deployment. The runtime and Admin use the shared database bootstrap; its source matched the local version.

## Confirmed causes

1. Existing receipt paths could omit width or supply a 1-ft fallback. These four saved rolls actually contain width 1; this is not a POS serialization conversion of 3–6 ft to 1 ft. Receipt metadata alone cannot establish what was physically received or the historical browser payload.
2. The allocator already supplied reconciliation details, but the POS adapter rebuilt a generic shortage and discarded the allocator's message and roll-width evidence.
3. Production `inv_rolls.width_ft` is INT(11), while lengths are decimal. Fractional feet can therefore be truncated. No schema migration was executed. Exact inserted-row readback now rejects altered measurements and rolls back rather than accepting truncation.

## Deployed runtime files

| File under public_html | Change |
|---|---|
| includes/pos_service_stock_validation.php | Preserve allocator diagnostics, load configured width/unit, include independently calculated demand in reconciliation text. |
| includes/tarpaulin_material_calculation.php | Normalize configured material width/unit; reject invalid metadata; preserve allocation policy. |
| includes/roll_width.php | Shared explicit width normalization and exact inserted-row receipt verification. |
| includes/RollService.php | Reject missing width, verify saved item/branch/width/lengths before committing. |
| admin/inventory_transactions_api.php | Require explicit measured width and unit; normalize to feet. |
| admin/inventory_rolls_api.php | Forward measured width to roll creation instead of omitting it. |
| admin/inv_rolls_management.php | Width and unit controls on the secondary intake form. |
| admin/api_tarp_rolls.php | Remove integer width cast; normalize explicit measurements. |

No pricing, payment, service mapping, or other-service POS files were changed. The main Inventory Items receiving form already contained width confirmation. No stock receipt was submitted during verification.

### Verified deployed normalized SHA-256

Hashes normalize CRLF to LF. File content was read back through File Manager after upload; no reliance on Git references or timestamps alone.

```
includes/pos_service_stock_validation.php 4f32082502469295045afcdbf132a642a5e0331d09bc7b7206f1154c503c32a3
includes/RollService.php 9bca75c40c11fac9ae7ec9897fed41f26011f9478a52e978ed3d1acf8e2e8335
includes/roll_width.php 2dd8a5161247063c890b136e23bc2f2e218904b4d930b3920edf868245ef00da
includes/tarpaulin_material_calculation.php 576f5adb74c5af377a40b915136b53436c0ebbc5bb310c9b326d8f631edd1986
admin/inventory_transactions_api.php 1b359a4f85ad1672c0c4e419156d9029f727cdd612b94e0b65314f968645438d
admin/inventory_rolls_api.php 0b3f81cd660f986d7dba1401dcbef9c280190e24e88a2649f1044c96d06b41e1
admin/inv_rolls_management.php f73852cbaf9b0c22984cd8f549c32281f2498c74f5823926da2681395220994f
admin/api_tarp_rolls.php 0c363cb3509f9550af88f05e165457531826503890d6d54737b8d298fc5a2965
```

## Backup and rollback

Verified outside-webroot backups: `/tarpaulin_stock_backup_20261109/admin/`, `/tarpaulin_stock_backup_20261109/includes/`, and `/tarpaulin_stock_backup_20261109/pos_service_stock_validation.php`.

The standalone validator backup is the pre-edit original; use it for rollback. The directory backup was taken after the first validator patch, so do not use its validator copy as the original. For the other seven files, restore the same relative file from the directory backups. Restore only these eight files, then re-read source hashes and reopen POS. No database rollback is required because no database writes occurred.

Original LF UTF-8 sizes and File Manager modification times:

| File | Bytes | Original mtime (UTC) |
|---|---:|---|
| includes/pos_service_stock_validation.php | 43653 | 2026-11-09T04:46:34.089753712Z |
| includes/RollService.php | 29038 | 2026-11-09T04:06:27.576884815Z |
| includes/roll_width.php | 1558 | 2026-11-08T04:14:26.002496736Z |
| includes/tarpaulin_material_calculation.php | 61559 | 2026-11-09T04:46:34.089753712Z |
| admin/inventory_transactions_api.php | 19803 | 2026-11-09T03:18:28.248207876Z |
| admin/inventory_rolls_api.php | 5126 | 2026-11-08T04:14:26.001496738Z |
| admin/inv_rolls_management.php | 21536 | 2026-11-08T04:14:26.001496738Z |
| admin/api_tarp_rolls.php | 3848 | 2026-11-01T15:23:30.737702172Z |

Fractional-width schema support remains separate work requiring a current restorable database backup and a compatibility review. A decimal width column would preserve existing integer values; rollback to integer would be unsafe after fractional widths are stored. Do not alter existing roll measurements based on item names/configuration.

## Verification

Live POS was reloaded and Tarpaulin reopened. Selecting 2×2 ft immediately produced the reconciliation result, naming all four rolls and showing calculated demand 2 ft at quantity 1. Quantity 100 updated demand to 200 ft without an unrelated field change. A custom 6×6 ft at quantity 1 showed demand 6 ft and remained blocked by reconciliation. No Add to Order action was attempted; the pre-existing cart was left untouched. Successful wider-roll allocation and genuine-shortage behavior are fixture-tested, not claimed live while the records conflict.

Passing local checks: nine focused PHP tests (reconciliation handoff, roll fit, width intake/readback, panel joining, material calculation, dimension selection, branch isolation, branch policy, release isolation), three JavaScript tests (stock input, dimension handoff, removed joining fields), PHP lint on all eight runtime files, JavaScript syntax checks, and git diff --check.

Current working-tree additions beyond the existing tested revision: calculated-demand reconciliation text, its regression assertion, and this report. Deployment consists of the eight verified runtime files above; no release commit was created.
