# Staff Performance Optimization - 2026-07-11

## Scope

Audited Online Staff and Counter/POS Staff dashboard, orders, chats,
customizations, products, reports, reviews, notifications, and POS request
patterns. The implementation focused on confirmed repeated or blocking work and
left business rules, calculations, status transitions, permissions, and UI
structure unchanged.

## Confirmed Root Causes

1. The legacy Customizations page fetched up to 200 job rows and 250 pending
   rows every 10 seconds. Focus and visibility events could also overlap those
   refreshes.
2. Each legacy list refresh resolved order source and visible order code one row
   at a time. This produced multiple N+1 query patterns.
3. The job list calculated materials, inks, roll stock, transaction stock, and
   an unused total count even though the list table did not display them.
4. V2 Customizations loaded up to 400 orders before HTML and queried item/order
   context per record.
5. Chat, dashboard, and customization polling requests retained the PHP session
   lock while performing read-only database work.
6. Chats, reports, and notifications continued periodic network activity while
   their browser tab was hidden.
7. The Notifications default page queried and branch-filtered the same complete
   notification set twice.

## Changes

- Added batched order-source and order-code resolution to
  `admin/job_orders_api.php`.
- Added `summary_only=1` for Customizations list requests. Inventory readiness
  is still calculated by the existing detail/action path when it is required.
- Removed the unused job-list COUNT query unless pagination metadata is
  explicitly requested.
- Enforced Online/POS source scope on the server for staff list responses before
  returning JSON.
- Added `Server-Timing: app;dur=...` to the legacy job-order API and secure slow
  request logging for requests taking at least two seconds.
- Added 15-second request timeout, stale-request cancellation, refresh
  deduplication, 30-second visible-tab polling, skeleton rows, stable-data
  refresh behavior, failure messaging, and Retry to legacy Customizations.
- Batched V2 order-item and store-payload loading and included source detection
  in the main list SQL.
- Released PHP session locks before read-only dashboard, Customizations, and chat
  database work.
- Paused Chats, Reports, Notifications, Customizations, and inventory network
  refreshes while the browser tab is hidden. Chats and Notifications refresh
  once when the tab becomes visible.
- Reused the default Notifications query for unread totals instead of loading
  the same complete dataset twice.

## Expected Query Reduction

For N legacy Customizations rows, source and order-code lookup work changes from
up to several queries per row to two batched queries per list response. The
summary job list also skips one COUNT and up to four inventory aggregation
queries. V2 normal-path item loading changes from at least N item queries to one
batch query, and store payloads are preloaded in one batch.

Visible-tab Customizations refresh frequency changes from six refresh cycles per
minute to two, a 67 percent reduction. Hidden-tab periodic requests are removed.

## Verification

- `php -l` passes for all 12 modified PHP files.
- `git diff --check` passes; Git only reports the repository's existing
  LF-to-CRLF conversion notice.
- Existing prepared statements, authentication, branch checks, role checks,
  CSRF handling, status logic, payment logic, and mutation paths remain enabled.

Authenticated browser timings and SQL `EXPLAIN` could not be recorded in this
workspace session because the in-app browser was unavailable and the local
MySQL credentials were rejected. No before/after milliseconds are fabricated.
The new `Server-Timing` response header provides production-safe timing for the
two Customizations list requests in DevTools.

## Database Indexes

No indexes were added. The active database could not be inspected safely for
existing indexes or queried with `EXPLAIN`, so applying the repository's older
index scripts could create duplicate indexes. Index changes should only be made
after `SHOW INDEX` and `EXPLAIN` are captured on the deployment database.

## Rollback

The pre-change files are in `backups_staff_performance_20260711/`, preserving
their project-relative paths. Restore only the affected file from that directory
to reverse a specific optimization group. No database rollback is required
because this change did not alter schema or data.
