# Manual GCash online payments

PrintFlow currently uses the existing manual GCash proof-verification workflow for customer online payments. PayMongo is preserved but is not the active online path while onboarding is incomplete.

## Mode selection

`ONLINE_PAYMENT_MODE` controls the customer-facing online payment path:

- unset or `manual_gcash`: manual GCash is active (safe default)
- `paymongo`: PayMongo is active for online orders

This setting does not delete or rewrite historical PayMongo ledger rows. POS PayMongo handling is also left intact.

## Runtime payment details

The customer page reads enabled payment methods and QR assets from the existing payment-method configuration managed through Admin settings. The current file-backed configuration is `public/assets/uploads/qr/payment_methods.json`; QR images remain under the configured uploads directory. Public account details belong in that configuration, not in PHP source or environment secrets.

## Canonical manual flow

1. Staff saves the final custom-order price. The server stores the authoritative order total and optional `price_finalized_at` / `price_finalized_by` audit fields.
2. The customer payment page displays the server-side amount, enabled GCash instructions, and configured QR.
3. The customer uploads a JPEG, PNG, or WebP receipt. Uploading creates a pending `payment_submissions` record and does not mark the order paid.
4. Store-order state becomes `To Verify` / `Payment Proof Submitted`; linked production state becomes `VERIFY_PAY` / `SUBMITTED` / `UNDER VERIFICATION`.
5. Staff reviews the proof in Payment Verification.
6. Approval records `Approved`, `verified_by`, and `verified_at`, marks the order paid, and advances service work to `Processing` / `IN_PRODUCTION` (or a plain product order to `Ready for Pickup` / `READY_TO_COLLECT`).
7. Rejection records `Rejected`, retains proof history and the reason, leaves the order unpaid, returns linked work to `TO_PAY` / `UNPAID`, and allows the customer to resubmit.

The `(customer_id, submission_token)` unique key prevents retry-created duplicate submissions. Staff decisions lock the submission row and only finalize a pending decision once.

## Re-enabling PayMongo

Complete PayMongo production onboarding first, configure the intended live/test keys and webhook secrets, verify the existing PayMongo diagnostics and callback URLs, then set `ONLINE_PAYMENT_MODE=paymongo`. Perform a controlled end-to-end test before making it customer-facing. Existing provider rows and PayMongo source files do not need to be recreated.

## Schema

No new migration is introduced by this restoration. The existing `payment_submissions` schema from `database/migrate_payment_verification_storage_20260730.php` must already be applied. If an environment predates that migration, run the repository migration through the normal deployment process before enabling manual uploads.
