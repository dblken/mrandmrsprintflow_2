# Unpaid Product Order Retention

Operational staff/admin lists hide **ready-made catalog product** orders
(including stickers) when they are **Cancelled** or remain unpaid in
**Pending / To Pay / Awaiting Payment / Expired** for more than 24 hours from
`orders.order_date` (awaiting) or the recorded expired transition (expired).
Service and custom orders are unaffected. Rows remain in the database for audit
unless physical cleanup is explicitly enabled.

`cron/cleanup_expired_product_orders.php` can permanently remove eligible
ready-made online product orders that remain unpaid and have been in **Awaiting
Payment** or **Expired** for **more than 24 hours** when
`PRINTFLOW_EXPIRED_PRODUCT_ORDER_PHYSICAL_CLEANUP` is set to true at deploy time.
By default the cron run is a no-op and visibility is handled in SQL only. The cleanup rechecks the current order and payment
ledger while holding row locks immediately before deleting. Payment status
values indicating success and active/successful order statuses are protected.
Payment submissions, linked job records, and inventory movement rows do not
block removal of an otherwise eligible order. The cleanup deletes the order
and its existing order-owned rows handled by `printflow_expired_product_order_delete_single()`;
separately stored payment-submission, job-order, and inventory-ledger rows are
retained as records and are not themselves deleted by this cleanup.

## Clock and timestamps

Age comparisons use MySQL `NOW()` and a strict `< DATE_SUB(..., INTERVAL 24 HOUR)`
condition: an order is retained at exactly 24 hours and becomes eligible after
it is older than 24 hours. The
application DB connection sets the MySQL session timezone to `+08:00`
(Asia/Manila). Awaiting Payment age is measured from `orders.order_date`,
which online order creation writes with `NOW()`. Expired age uses the payment
status-history `expired` transition when available, then the payment/order
`updated_at` timestamp as a conservative compatibility fallback.

## Hostinger scheduler setup

After code review and deployment, an account owner must configure a Hostinger
hPanel Cron Job to run every minute. Use the PHP CLI binary shown by
hPanel and the absolute path to the deployed script, for example:

```text
* * * * * /usr/bin/php /home/<HOSTINGER_USER>/domains/mrandmrsprintflow.com/public_html/cron/cleanup_expired_product_orders.php
```

Replace `<HOSTINGER_USER>` and `/usr/bin/php` with the actual hPanel account
home and supported PHP CLI path. Keep any scheduler logs outside `public_html`.
The job is CLI-only and obtains a MySQL advisory lock to avoid overlapping
scheduled instances. It processes up to 500 orders per run; failed per-order
transactions roll back and are reported in the run summary/error log.

The Online Operational Staff Product Orders page also runs a branch-scoped
cleanup before rendering its list, filters, pagination, archive response, and
counters. SQL list/count filters independently exclude overdue eligible rows
if deletion is delayed or fails.

## Review and verification

Before production rollout, verify the live columns and table engines for
`orders`, `provider_payments`, and `provider_payment_status_history`. The
deletion transaction requires transactional tables for all dependent order
rows that it removes.
After deployment, run the scheduled command manually once, inspect its
database clock and counts, and confirm a disposable test order at each time
boundary before enabling the recurring schedule. Do not use a real customer
order for boundary tests.
