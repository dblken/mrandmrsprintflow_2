# Admin Reports performance investigation — October 15, 2026

Status: local changes and isolated checks complete for the scoped optimizations
below; deployment, production SQL plans and measured speedup remain unverified.

## Confirmed unnecessary work in the existing route

- Current and prior KPIs called the complete official sales breakdown but only
  consumed its canonical summary. Each executed14 data queries, including full
  transaction results, item/branch breakdowns that were discarded. They now use
  the exact canonical aggregate helper:2 data queries per uncached summary.
- Identical resolved branch activity was checked twice. It is now checked once.
- Inventory alerts performed an item list plus per-item stock queries, including
  schema setup, despite having no rendered consumer. Recent transactions and
  customer count were also unused. Those blocks are removed from this route.
- The Top Customers card is permanently hidden. Its SQL is skipped; its export
  endpoint and hidden markup are preserved.
- The full-year heatmap matrix was computed below the fold on every filter
  refresh. Its existing authenticated API now runs when the heatmap approaches
  the viewport. The year selector still uses the existing available-year query.
- Branch performance repeated a redundant union of product and customization
  classification probes. The existing aggregate fast path removes only that
  equivalent predicate, retaining current/prior periods and revenue rules.
- Filter updates were dropped while another update was running. A latest-filter
  queue now suppresses stale responses, including the debounce interval race.

No index migration was added without production EXPLAIN/schema evidence. This
investigation has source/query-count evidence, not per-query production timings.

## Cache and invalidation

Only sales totals and branch-performance arrays are cached, separately by
resolved branch and full date/previous-period arguments. Existing60-second TTL
and refresh/nocache switches apply. Profit/material costs and expenses remain
fresh. Errors are not cached. Cache reuse does not change reporting formulas.

Report entries use the existing admin derived-cache branch invalidation hooks.
A reports-only generation token additionally changes at start and shutdown of
non-GET/non-HEAD database-backed requests, including payment webhooks, and CLI
jobs. This conservatively invalidates all report aggregate entries, including
after failed or read-only POSTs. It changes no database queries or records.
Concurrent computations from an older generation cannot become reusable after
invalidation. Stable filter keys avoid creating a file per mutation generation.
GET-based changes must use the existing explicit branch invalidation hooks.

The shared DB bootstrap only includes that reports-cache invalidation helper.
No payment/POS/stock/status calculation or permission code is changed.

## Loading and response safeguards

Filters show a shimmer skeleton overlay with aria-busy; heatmap placeholders use
skeletal bars and reduced-motion CSS. Card placement and report controls remain.
Heatmap requests coalesce identical years, abort older years and ignore detached
branch/filter mounts. HTTP failures and authentication redirects cannot replace
report content. Verbose console logging of the full report payload is removed.

Other report datasets still compute on the initial route. This patch does NOT
claim full per-section progressive server loading. The existing chart rendering
logic is otherwise preserved. Further splitting needs deployment phase timings
and rendered-data regression checks rather than speculative query rewrites.

`Server-Timing` exposes computation phases; authenticated `reports_perf=1` also
adds inert JSON diagnostics with cache states, durations and DB-error count.
There is no SQL text, credential or record content in these diagnostics. Browser
tool call duration is not a measurement of HTTP or paint time.

## Live baseline checks

The existing authenticated browser showed Cabuyao Today revenue6732, Las Piñas
Today10940 and All Branches17672, which reconcile. The All Branches October1–14
filter settled at67071, with branch revenue53821 and13250. Console error/warning
inspection returned an empty list. The All Branches inspection initially timed
out; a subsequent page inspection confirmed it finished. The timeout is not
reported as server/request timing. External Statsig/Cloudflare tool telemetry
errors are separate from the site's console.

No production after-fix claim: these changes have not been deployed by this
agent and no deployment/server/DB connection is exposed in the current tools.

## Local validation

Passed PHP syntax for reports, report scripts, cache/generation helpers and DB
bootstrap; inline JavaScript syntax; new report cache, filter queue and lazy
heatmap tests; canonical revenue parity/query-cost tests for branches1,2,all;
branch aggregate equivalence; report double-count; staff revenue/top sales;
dashboard cache/progressive/loader/filter tests; order completion/payment;
26 QR PH regression assertions; diff whitespace checks.

Two existing source-contract checks still fail: POS cart refresh must release
its session before slow cleanup, and online completion must use the shared
inventory movement helper. Both test files and their implicated POS/status
sources are unchanged relative to HEAD. They are not fixed in this report patch.

These are isolated/source checks, not production DB/payment lifecycle tests.
Deploy the new generation helper BEFORE deploying the DB include, then the cache
helper and Reports changes as one coordinated release. After deployment, verify
cold/warm timing JSON, every branch, presets/custom/reversed/all-time ranges,
year switching and rapid filter changes, plus actual report totals/exports.
