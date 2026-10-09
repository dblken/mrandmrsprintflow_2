# PayMongo Test and Live Deployment

PayMongo payment creation and reconciliation support both environments.
`PAYMONGO_MODE=test` pins every new mutating request to Test Mode, even when
preserved Live credentials and `PAYMONGO_LIVE_ENABLED=true` are present. Live
webhook verification remains separately gated by the Live setting.

## Environment configuration

Keep credentials only in the project-level `.env` on the deployed server. Never
commit or log them.

```dotenv
PAYMONGO_MODE=test
PAYMONGO_API_URL=https://api.paymongo.com

PAYMONGO_TEST_PUBLIC_KEY=pk_test_your_public_key
PAYMONGO_TEST_SECRET_KEY=sk_test_your_secret_key
PAYMONGO_TEST_WEBHOOK_SECRET=whsk_your_test_webhook_secret
PAYMONGO_TEST_DIRECT_METHODS=qrph

PAYMONGO_LIVE_ENABLED=true
PAYMONGO_LIVE_PUBLIC_KEY=pk_live_your_public_key
PAYMONGO_LIVE_SECRET_KEY=sk_live_your_secret_key
PAYMONGO_LIVE_WEBHOOK_SECRET=whsk_your_live_webhook_secret
PAYMONGO_LIVE_DIRECT_METHODS=qrph
```

The webhook signing secrets are different from the API secret keys. Legacy
`PAYMONGO_PUBLIC_KEY`, `PAYMONGO_SECRET_KEY`, and `PAYMONGO_WEBHOOK_SECRET`
values remain compatible only when their key prefix and configured environment
match. Prefer the mode-specific names above.

To generate live links after the live endpoint has been tested, explicitly set:

```dotenv
PAYMONGO_MODE=live
PAYMONGO_LIVE_ENABLED=true
```

While `PAYMONGO_MODE=test`, PrintFlow creates new payments only through test API
keys and verifies Test webhooks with the Test webhook secret. Live credentials,
the Live webhook endpoint, and existing Live ledger rows remain available.
Read-only Live API calls may reconcile historical Live rows without rewriting
their mode. New Test attempts use a distinct ledger row; an unpaid Live row is
preserved. A paid Live row continues to block creation because the order is
already paid.

For QRPh, PrintFlow requires PayMongo's actual boolean `livemode: false` on the
Payment Intent, Payment Method, and attached payment response. Missing,
non-boolean, or `true` values fail closed. A Test QR image is never presented to
customers or POS staff; PrintFlow exposes PayMongo's `test_url` simulator only
after the Test Payment Intent has been verified. Use that simulator to produce
test outcomes; do not scan or pay a Test QR code. Live QR display also requires
an actual verified `livemode: true` response.

Do not reuse a test credential or test webhook secret in Live Mode.

When `PAYMONGO_MODE` is unset but only test API keys are configured, PrintFlow
defaults new checkouts to **test**. Setting `PAYMONGO_MODE=live` without live
API keys also falls back to test instead of creating live payments. Live
checkout still requires `PAYMONGO_LIVE_ENABLED=true` plus matching `pk_live_` /
`sk_live_` credentials.

## Database migrations

For a new installation, run these from the deployed project root in order:

```bash
php database/migrate_paymongo_provider_payments_20260729.php
php database/migrate_paymongo_post_payment_workflow_20260730.php
php database/migrate_paymongo_reconciliation_20260806.php
php database/migrate_paymongo_provider_livemode_20261009.php
```

The livemode migration stores the provider's `livemode` flag and the time it
was confirmed by a successful provider response. It does not infer verification
from the local ledger mode. Historical rows remain unverified until a later
successful API reconciliation. QR display and download remain unavailable until
a Payment Intent's environment is confirmed with PayMongo.

Existing installations that have already applied both earlier PayMongo
migrations only need the 20260806 migration. If the post-payment workflow
migration has not been recorded as applied, run it before 20260806 as shown
above. All three scripts are idempotent. The 20260806 migration is additive and
it:

- separates test and live ledger rows;
- stores provider payment/reference/method/amount/timestamp metadata;
- adds a stable link-creation idempotency key;
- adds durable webhook attempt, error, and payload-fingerprint fields;
- ensures payment transition history exists;
- adds nullable order price-finalization markers; and
- adds nullable explicit product/service identity fields for new order lines.

The migration does not delete records or change authoritative order amounts,
payment evidence, links, or notifications. It may populate only the new price
audit and item-identity columns when existing workflow/source metadata is
unambiguous; uncertain order lines remain on the legacy resolver.

## Webhook registration

Create one webhook in each PayMongo dashboard environment. Webhook endpoints
are scoped to the mode in which they are created.

### Test Mode

1. Switch the PayMongo dashboard to **Test Mode**.
2. Register `https://mrandmrsprintflow.com/webhooks/paymongo.php`.
3. Subscribe to `payment.paid`, `payment.failed`, and `qrph.expired` for QRPh
   Payment Intents. Add `link.payment.paid` only if Test Payment Links are used.
4. Save its signing secret as `PAYMONGO_TEST_WEBHOOK_SECRET`.

### Live Mode

1. Keep `PAYMONGO_MODE=test`; new payment creation remains pinned to Test even
   when `PAYMONGO_LIVE_ENABLED=true` is set for the preserved Live setup.
2. Switch the PayMongo dashboard to **Live Mode**.
3. Register `https://mrandmrsprintflow.com/webhooks/paymongo_live.php`.
4. Subscribe to `payment.paid`, `payment.failed`, and `qrph.expired` for QRPh
   Payment Intents. Add `link.payment.paid` only if Live Payment Links are used.
5. Save its signing secret as `PAYMONGO_LIVE_WEBHOOK_SECRET`.
6. Keep the Test endpoint registered independently. To enable Live checkout
   later, set `PAYMONGO_MODE=live` after reviewing credentials and deployment.

The Test endpoint verifies the `te` signature and rejects live payloads. The
Live endpoint verifies the `li` signature and rejects test payloads. Both hash
the exact raw request body with the endpoint's signing secret and enforce a
timestamp tolerance.

## Webhook durability and data safety

The webhook inbox is keyed by PayMongo event ID. A processed event is
acknowledged without applying payment or notifications again. Failed events and
processing attempts older than five minutes can be claimed again. A missing
ledger/link association remains retryable instead of being permanently ignored.

The new webhook handler does not store the complete request. It stores only a
minimal normalized envelope, the raw payload's SHA-256 fingerprint, processing
attempt metadata, and verified payment identifiers. API keys, signing secrets,
HTTP signatures, and complete customer/payment payloads must never be stored in
the inbox or application logs. The additive migration does not destructively
rewrite older inbox evidence; keep database access restricted and handle any
legacy-payload retention/redaction under a separately approved policy.

Payment Intent events are verified against the corresponding Payment Intent
and Payment resources. Payment Link events are verified with
`GET /v1/payment_links/:id/payments`. Browser return parameters are never used
as proof of payment.

## Deployment verification

After deploying code and running the migration:

1. Clear LiteSpeed and PHP OPcache.
2. Confirm a GET request to each registered endpoint returns HTTP 405.
3. Complete one Test Mode online customizable-order payment.
4. Confirm the ledger, order, customization, and payment UI become paid once.
5. Confirm the provider payment ID, link reference, paid amount, QRPh method,
   test environment, and provider paid time are visible to authorized users.
6. Redeliver the same webhook event and confirm no duplicate payment transition
   or notification is created.
7. Simulate a failed/stale inbox attempt and confirm a later delivery can claim
   and process it.
8. Confirm an amount, currency, link, customer/order, or environment mismatch is
   rejected without marking the order paid.
9. Confirm the customer status fallback reconciles a paid link when webhook
   delivery is delayed.
10. Confirm payment stops at Awaiting Production; staff must explicitly start
    production.
11. Complete one POS Test Mode checkout and confirm the receipt stays unavailable
    until server-side payment verification.
12. Confirm manual proof upload still works for an unpaid order with no completed
    PayMongo payment.

Only after all Test Mode checks pass should the shop enable and verify a
controlled Live Mode transaction.

## Rollback

If live verification fails, immediately set `PAYMONGO_LIVE_ENABLED=false` and
restore `PAYMONGO_MODE=test`. Revert application code if necessary, but leave
the additive columns and webhook/payment history intact. Do not down-migrate or
delete payment evidence, links, provider IDs, events, or notifications.
