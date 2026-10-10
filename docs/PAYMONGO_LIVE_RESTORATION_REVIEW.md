# PayMongo Live restoration review

Status: local code is reviewed and validated; production deployment and runtime
verification are still pending Hostinger access.

## Exact source diff

`PAYMONGO_LIVE_RESTORATION_REVIEW.patch` is the runtime/migration patch from
pre-Test commit `94ff9b03` to the current validated source `c1efa16b`. It was
reverse-checked against the current source tree. The patch includes only these
eight files:

- `includes/paymongo.php`
- `includes/provider_payments.php`
- `customer/payment.php`
- `customer/api_paymongo_status.php`
- `staff/api/paymongo_payment.php`
- PayMongo-specific changes in `staff/pos.php`
- `database/migrate_paymongo_post_payment_workflow_20260730.php`
- `database/migrate_paymongo_provider_livemode_20261009.php`

The local Live-mode correction makes an incomplete or ambiguous Live
configuration fail closed; it never falls back to Test for a Live request.
Existing Live QR display requires a provider response verified as
`livemode: true`; a customer browser return is not payment confirmation.

Files changed for review or tests but intentionally excluded from the runtime
patch are `.env.example`, `docs/PAYMONGO_TEST_MODE.md`, and the PayMongo test
files. Keep `.env.example` as a Test template; do not copy it over the existing
production configuration. Do not upload the `.patch` file as application code.

## Additional runtime files to verify on the server

These are unchanged by the Test-mode commits but are required by the final
flow. Compare them with the current repository and restore only if absent or
outdated:

- `webhooks/paymongo_live.php` — pins the existing shared webhook handler to
  Live mode.
- `webhooks/paymongo.php` — remains the separate Test endpoint.
- `includes/paymongo_webhook_events.php` — provides the durable, idempotent
  webhook inbox used by the shared handler.
- `database/migrate_paymongo_provider_payments_20260729.php` and
  `database/paymongo_provider_payments_20260729.sql` — base ledger tables.
- `database/migrate_paymongo_reconciliation_20260806.php` — reconciliation,
  mode separation, event metadata, and history dependencies.
- `database/migrate_paymongo_payment_intents_20260821.php` — Payment Intent,
  QRPh, and supporting index fields.

The livemode PHP migration is complete; its companion SQL file adds only
`provider_livemode`, not the verification timestamp and simulator URL. It is
not included in the deployment patch; do not use it as a substitute for the
PHP migration.

## Production checklist

1. Restore Hostinger connector/browser access before any production operation.
   The last website-list call timed out; the browser helper failed before a
   consent page opened. Do not treat either event as successful authentication.
2. Back up the exact changed production files and external PHP configuration.
   Export the production database structure and data before schema changes.
3. Compare the deployed source with the patch base. If it differs from
   `94ff9b03`, merge the reviewed PayMongo hunks into the actual production
   version; in particular, do not overwrite all of `staff/pos.php` with a
   version containing unrelated POS work.
4. Deploy the runtime PHP files and migration PHP files above. Preserve the
   currently configured Live public key, secret key, webhook secret, and
   registered Live webhook. Do not deploy or copy `.env.example`.
5. In the production configuration already loaded by `includes/env.php`, set
   `ONLINE_PAYMENT_MODE=paymongo`, `PAYMONGO_MODE=live`,
   `PAYMONGO_LIVE_ENABLED=true`, and `PAYMONGO_LIVE_DIRECT_METHODS=qrph`.
   Retain the existing credential values. Verify only safe presence/prefix
   markers: Live public key `pk_live_`, API key `sk_live_`, and a configured
   Live webhook secret. Leave all Test settings intact.
6. Inspect the database and run only missing migrations in this order:

   Before choosing the scripts, use phpMyAdmin or the configured MySQL client
   against the selected production database and inspect the ledger tables,
   columns, and indexes. These read-only inventory queries use the active
   database and do not reveal payment/customer rows:

   ```sql
   SELECT table_name
   FROM information_schema.tables
   WHERE table_schema = DATABASE()
     AND table_name IN ('provider_payments', 'provider_webhook_events',
                        'provider_payment_status_history');

   SELECT table_name, column_name, column_type
   FROM information_schema.columns
   WHERE table_schema = DATABASE()
     AND table_name IN ('provider_payments', 'provider_webhook_events',
                        'orders', 'order_items')
   ORDER BY table_name, ordinal_position;

   SELECT table_name, index_name, non_unique,
          GROUP_CONCAT(column_name ORDER BY seq_in_index) AS indexed_columns
   FROM information_schema.statistics
   WHERE table_schema = DATABASE()
     AND table_name IN ('provider_payments', 'provider_webhook_events',
                        'provider_payment_status_history', 'order_items')
   GROUP BY table_name, index_name, non_unique
   ORDER BY table_name, index_name;
   ```

   Compare the output with each migration source. Export structure and data
   first. Do not infer historical Live/Test mode from `provider_livemode` when
   it is `NULL`; the migration intentionally leaves old rows unverified.

   ```bash
   php database/migrate_paymongo_provider_payments_20260729.php
   php database/migrate_paymongo_post_payment_workflow_20260730.php
   php database/migrate_paymongo_reconciliation_20260806.php
   php database/migrate_paymongo_payment_intents_20260821.php
   php database/migrate_paymongo_provider_livemode_20261009.php
   ```

   The base migration can early-exit after checking the two tables and
   `orders.payment_status`; independently verify its indexes if the database
   was only partly migrated. Do not drop/recreate tables, change historical
   modes, delete rows, or rerun any non-idempotent manual SQL blindly. The
   `20260730` script backfills status fields only when those columns are first
   added, preserving later reconciliation values on rerun.
7. Clear PHP OPcache/application cache. From the deployed project root, run
   this redacted CLI probe. It prints only the public-key prefix and presence
   booleans, never credential values:

   ```bash
   php <<'PHP'
   <?php
   require 'includes/env.php';
   printflow_load_project_env();
   require 'includes/paymongo.php';
   $publicKey = printflow_paymongo_public_key_for_mode('live');
   echo json_encode([
       'online_payment_mode' => printflow_paymongo_env('ONLINE_PAYMENT_MODE'),
       'mode' => printflow_paymongo_mode(),
       'live_enabled' => printflow_paymongo_live_enabled(),
       'live_public_key_prefix' => substr($publicKey, 0, 8),
       'live_secret_present' => printflow_paymongo_secret_key_for_mode('live') !== '',
       'live_webhook_secret_present' => printflow_paymongo_webhook_secret_for_mode('live') !== '',
       'live_qrph_enabled' => in_array('qrph', printflow_paymongo_enabled_methods('live'), true),
   ], JSON_UNESCAPED_SLASHES), PHP_EOL;
   PHP
   ```

   Confirm `online_payment_mode=paymongo`, `mode=live`, `live_enabled=true`,
   `live_public_key_prefix=pk_live_`, and all three presence/QRPh booleans are
   true. The command loads the same project `.env` used by application
   bootstraps; it does not prove FPM environment parity by itself. Never print
   keys or secrets.
8. Start a fresh customer QRPh attempt and inspect the actual PayMongo
   Payment Intent response. Verify boolean `livemode: true`, a matching Live
   payment ledger row, `provider_livemode=1` and its verification timestamp,
   and the normal Live customer QR. Leave the QR unpaid; do not scan or pay it.
9. Verify the retained Live webhook endpoint and mode-specific signature
   verification without creating a new webhook. Confirm valid server-side
   reconciliation is the only route that can mark the order Paid. Preserve all
   old Live/Test payment records and keep cash/manual payment behavior intact.

Production configuration, schema readiness, webhook delivery, and a fresh
Live response have not been verified in this review. Do not report Live
restoration complete until step 8 has actual provider evidence.
