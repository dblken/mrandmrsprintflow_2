# PayMongo Test Mode Setup

This integration is intentionally restricted to PayMongo Test Mode. Do not add
live keys until a separate, reviewed production rollout is approved.

## Hostinger environment

Keep these values only in `public_html/.env` on Hostinger:

```dotenv
PAYMONGO_MODE=test
PAYMONGO_PUBLIC_KEY=pk_test_your_public_key
PAYMONGO_SECRET_KEY=sk_test_your_secret_key
PAYMONGO_API_URL=https://api.paymongo.com
PAYMONGO_WEBHOOK_SECRET=whsk_your_test_webhook_secret
```

The webhook secret is different from the PayMongo API secret key.

## Database migration

From the deployed project root, run:

```bash
php database/migrate_paymongo_provider_payments_20260729.php
```

This adds the shared `provider_payments` ledger and the idempotent
`provider_webhook_events` inbox. It also widens `orders.payment_status` without
removing existing values or proof-verification data.

## Webhook registration

1. Open the PayMongo Dashboard and switch to **Test Mode**.
2. Go to **Settings -> Webhooks** and create one endpoint.
3. Set the URL to `https://mrandmrsprintflow.com/webhooks/paymongo.php`.
4. Subscribe only to `link.payment.paid`.
5. Save the endpoint and place its displayed signing secret in
   `PAYMONGO_WEBHOOK_SECRET` in Hostinger's `public_html/.env`.

Do not create one webhook per order. Do not register this endpoint in Live
Mode.

To verify delivery, complete a Test Mode Payment Link and inspect the endpoint's
delivery history in PayMongo. A valid delivery returns HTTP 200 JSON. Invalid
signatures return HTTP 401, amount/mode mismatches return an error, and duplicate
events are acknowledged without applying the payment twice. Failed deliveries
can be inspected and resent from the PayMongo Webhooks dashboard.

## Deployment verification

After Hostinger deploys the commit:

1. Run the migration.
2. Register the Test Mode webhook and add its signing secret to `.env`.
3. Clear LiteSpeed and PHP OPcache.
4. Generate and complete one online customizable-order Test Mode checkout.
5. Generate and complete one POS Test Mode checkout.
6. Confirm each order becomes `Paid` only after webhook or authenticated
   server-side polling verification.
7. Confirm the POS receipt remains unavailable until the paid state.
8. Confirm manual proof upload and staff verification still work for an unpaid
   order with no completed PayMongo payment.
