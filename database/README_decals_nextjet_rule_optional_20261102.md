# Decals/Nextjet rule correction: operator prerequisites and recovery

These scripts are **not part of routine web deployment** and were not run as part of local validation.

## Migration prerequisites

- Obtain a change window and explicit production database-change approval.
- Confirm the target database is the intended production schema and has a recent, restorable database backup.
- Run from the application checkout using the supported PHP CLI, with the application's existing `includes/db.php` configuration available. Do not put database credentials in command arguments or logs.
- Confirm `service_material_rules` has the multiplier and approval columns and uses InnoDB; the script checks both.
- Confirm `$HOME` resolves to a writable directory outside the project root. The script creates `.printflow-service-material-rule-backups` with mode `0700` and a unique snapshot with mode `0600`.
- The locked query must find exactly one canonical `Decals/Stickers (Print/Cut)` rule for item ID 18, and the joined active inventory identity must still be named Nextjet. The rule must be REQUIRED, or already OPTIONAL with cleared approval audit. Any mismatch stops without changing a row.

## Controlled execution and verification

From the application root, an authorized operator may run `php database/migrate_decals_nextjet_rule_optional_20261102.php`. The script snapshots and checksums the complete original row, updates only that row's rule type and approval audit, reads it back under lock, verifies service/item/name, unchanged multiplier, OPTIONAL status, and cleared audit, then commits. Any failed check rolls back. Record the printed `RULE_BACKUP_PATH`, `RULE_BACKUP_SHA256`, and `NEXTJET_RULE_RESULT` with the change record.

## Recovery

If an approved rollback is required, first verify the snapshot remains in the private backup directory and retain a copy of the reported path. From the application root run `php database/rollback_decals_nextjet_rule_optional_20261102.php <absolute-snapshot-path>`. The script checks the snapshot checksum and restrictive permissions, locks the exact row, and refuses if the live row is not still in the expected post-migration state. It restores only the captured rule type and approval audit, verifies all captured target fields including the unchanged multiplier, and commits only after readback. Any mismatch aborts; do not edit the snapshot or force restoration—investigate manually with the authorized database operator.
