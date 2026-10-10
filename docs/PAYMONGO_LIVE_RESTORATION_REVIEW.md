# PayMongo Live restoration review

Status: the three runtime files directly responsible for mode resolution,
schema readiness, and customer QR display are deployed and match local source.
Production schema inspection on 2026-10-10 found only the three columns from
`migrate_paymongo_provider_livemode_20261009.php` missing. After a verified
backup, those nullable columns were added and Hostinger's website cache was
cleared. A fresh unpaid QRPh attempt for order 12146 displays a real QR image;
the customer status response reports `mode: live` and boolean
`livemode: true`. Payment completion and a new webhook delivery remain
unverified because the QR was intentionally not paid.

### Production evidence (2026-10-10)

- `customer/payment.php`, `includes/provider_payments.php`, and
  `includes/paymongo.php` downloaded from `public_html` matched the reviewed
  local files byte-for-byte after line-ending normalization by Git.
- The selected runtime database was `u618446170_printflow`. The payment ledger,
  webhook inbox, and payment status history tables already existed. Payment
  Intent, Payment Method, QR, reconciliation, idempotency, and status-history
  fields and indexes were already present.
- The exact missing objects were
  `provider_payments.provider_livemode`,
  `provider_payments.provider_livemode_verified_at`, and
  `provider_payments.provider_test_url`.
- A table export containing structure and data was downloaded and hash-checked
  before migration. It contained the same 70 ledger rows reported by the live
  database: 61 Live and 9 Test.
- Only the three additive nullable columns above were applied. Post-migration
  counts remained 61 Live and 9 Test, and every historical livemode value
  remained `NULL` until provider verification.
- Order 12146 created one new Live Payment Intent row with a Payment Method and
  QR image. The provider mode was stored as `provider_livemode = 1` with a
  verification timestamp. Secure status reconciliation returned
  `livemode: true`, `mode: live`, `payment_flow: payment_intent`, and
  `status: awaiting_payment`.
- Production configuration had `PAYMONGO_MODE=live`, Live enabled, QRPh in the
  Live direct-method allowlist, a Live-prefixed public key, a Live-prefixed
  secret key, and a Live webhook secret. `ONLINE_PAYMENT_MODE` was not explicit;
  the deployed resolver safely defaults it to `paymongo`, which the successful
  customer request confirmed. No credential value was copied into this review.
- The order remained `Unpaid`, as required for an unscanned QR. A new webhook
  delivery, Paid transition, and Staff/Admin Paid display require an actual
  payment and were not exercised.

## Exact source diff

`PAYMONGO_LIVE_RESTORATION_REVIEW.patch` applies the reviewed PayMongo runtime
changes from pre-Test commit `94ff9b03`, plus the latest local
schema-readiness repair. It was reverse-checked against the current source
tree. The patch includes only these eight runtime/migration files:

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

### Latest schema-readiness repair

The pre-Test source at `94ff9b03` required the original Payment Intent columns.
Commit `4f7a5087` (2026-10-10 00:04 Asia/Manila) expanded the readiness gate
to require `provider_livemode`, `provider_livemode_verified_at`, and
`provider_test_url`. The first two remain required for the current secure Live
QR flow. `provider_test_url` is now required only for Test checkout, since it
stores the Test simulator URL and is not needed for Live checkout.

The readiness check now queries the tables and columns directly, checks that
the `mode` enum supports the selected mode, and distinguishes a missing schema
object from a schema-inspection failure in server diagnostics. It bypasses
the prior cached `db_table_has_column()` results for this checkout guard. The
customer response stays generic; diagnostic logs identify only the missing
schema names or query error code/state. The targeted contract test is
`tests/paymongo_schema_readiness_test.php`; it now exercises the actual readiness
function against schema fixtures as well as the migration/source contracts.
All 12 checks pass, including Live readiness without `provider_test_url`, missing
provider verification timestamps, failed schema queries, and unsupported mode
enums. It does not inspect production. The 16 mode-resolution checks also pass.

This change does not remove the requirement for `provider_livemode` and
`provider_livemode_verified_at`. If either is missing in production, the
additive livemode migration remains necessary. The customer-facing message
alone does not prove which table, column, or index is absent.

The review patch also includes the later Test simulator response fixes in
`customer/payment.php` and `includes/provider_payments.php`. A verified Test
response is accepted even though the customer DTO intentionally omits the
scannable QR image. A reused, provider-verified Test payment can render its
simulator state even if PayMongo omits the optional simulator URL. These
branches do not change Live QR display or allow a Test response in Live mode.

### Current production error and access evidence

The exact message in the latest screenshot, `QRPh checkout is temporarily
unavailable. Please try again later.`, is returned by
`printflow_provider_payment_create_intent()` when the selected mode's schema
status is not ready. This happens before Payment Intent creation, Payment
Method creation, attachment, or QR rendering. The API's `code` distinguishes
`payment_intent_schema_missing` from `payment_intent_schema_inspection_failed`.
The server's `[paymongo-schema]` diagnostic names the actual missing objects or
the inspection error code. Obtain that evidence before selecting a migration.
The screenshot alone cannot establish a missing column or an account/key issue.

The initial Hostinger MCP request timed out, but the authenticated hPanel,
File Manager, and phpMyAdmin sessions became controllable after the browser
runtime was reloaded. Production inspection and the migration evidence above
therefore supersede the earlier access-blocked state.

Earlier CLI checks that only required `includes/paymongo.php` did not explicitly
load the project `.env`; that is not a valid application-runtime check. The
corrected probe explicitly calls `printflow_load_project_env()`. In the local
workspace, the file is readable but neither an active PayMongo mode nor usable
PayMongo credentials are configured. This is local evidence only.

Files changed for review or tests but intentionally excluded from the runtime
patch are `.env.example`, `docs/PAYMONGO_TEST_MODE.md`, and the PayMongo test
files, including `tests/paymongo_schema_readiness_test.php`. Keep `.env.example`
as a Test template; do not copy it over the existing production configuration.
Do not upload the `.patch` file as application code.

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

1. Production access was established through the authenticated browser and the
   selected site was verified as `mrandmrsprintflow.com`.
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
6. Inspect the selected production database and compare actual objects against
   the migration sources. Do not infer a missing migration from the generic
   customer error and do not run every migration blindly. The current Live
   readiness contract requires both ledger tables and these `provider_payments`
   columns: `mode` (supporting `live`), `payment_flow`, `payment_intent_id`,
   `payment_method_id`, `qr_image_url`, `qr_expires_at`, `client_key`,
   `idempotency_key`, `payment_status`, `provider_status`, `provider_livemode`,
   and `provider_livemode_verified_at`. `provider_test_url` is needed only when
   the selected mode is Test. Also inspect migration-defined indexes; readiness
   does not validate indexes. Check `provider_webhook_events.payment_intent_id`
   and `.payment_method_id` as well. Expected intent indexes are
   `uq_provider_payment_intent(payment_intent_id)`,
   `uq_provider_payment_method(payment_method_id)`, and
   `idx_provider_payment_flow_status(provider,mode,payment_flow,status)`.
   Compare base/reconciliation indexes against their migrations too, including
   `uq_provider_payment_idempotency`, `idx_provider_payment_reconciliation`,
   `idx_provider_webhook_retry`, `idx_provider_webhook_link`,
   `idx_provider_webhook_transaction`, and the status-history keys. Do not
   create duplicate indexes under new names without checking their existing
   column definitions.

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

   The following is dependency order only. Run a script only when its own
   required objects are absent, and only after a verified backup:

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
8. Use one designated, eligible unpaid order with no previous provider attempt
   for controlled Live verification. Retain normal idempotent reuse behavior for
   existing orders; do not reset or overwrite their Test/Live records. Start a
   fresh customer QRPh attempt and inspect the actual PayMongo
   Payment Intent response. Verify boolean `livemode: true`, a matching Live
   payment ledger row, `provider_livemode=1` and its verification timestamp,
   and the normal Live customer QR. Record the new intent identity, creation
   time, actual boolean `livemode: true`, amount, and valid unexpired QR image.
   Do not infer the environment from the dashboard, key prefixes, local ledger,
   or the `mode` label alone.
9. Verify the retained Live webhook endpoint and mode-specific signature
   verification without creating a new webhook. Confirm valid server-side
   reconciliation is the only route that can mark the order Paid. Preserve all
   old Live/Test payment records and keep cash/manual payment behavior intact.
   The latest user request authorizes one small real Live verification payment
   after configuration is confirmed. Obtain any action-time financial
   confirmation required by the selected payment/UI tool before submitting it.
   Verify the real Live event's signature and the server's retrieved provider
   state, then check the designated payment ledger, customer order, and Staff/
   Admin displays. Verify duplicate delivery is idempotent and does not create
   another provider payment or repeat order/inventory transitions. A fresh
   unpaid Live intent verifies QR creation only, not the Paid/webhook flow.

Production configuration, schema readiness, a fresh Live response, and QR
display have been verified. A new webhook delivery and the resulting Paid/order
display transitions have not been verified because the QR was left unpaid.
