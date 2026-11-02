# Unpaid Product Order Retention

`cron/cleanup_expired_product_orders.php` permanently removes only online
product orders which remain unpaid and have been in **Awaiting Payment** for
24 hours, or have been **Expired** for 24 hours. The cleanup rechecks the
current order and payment ledger while holding row locks immediately before
deleting. Paid/active orders, payment submissions, and orders with product
stock-out movements are protected.

## Clock and timestamps

Age comparisons use MySQL `NOW()` and `DATE_SUB(..., INTERVAL 24 HOUR)`. The
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
`orders`, `provider_payments`, `provider_payment_status_history`,
`inventory_transactions`, and `payment_submissions`. The deletion transaction
requires transactional tables for all dependent order rows that it removes.
After deployment, run the scheduled command manually once, inspect its
database clock and counts, and confirm a disposable test order at each time
boundary before enabling the recurring schedule. Do not use a real customer
order for boundary tests.
