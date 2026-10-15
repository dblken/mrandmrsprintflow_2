# Admin dashboard live diagnosis — 16 Oct 2026

Authenticated live measurements (`nocache=1`, Server-Timing) on production **before** deploying this fix batch.

## Root causes (confirmed)

1. **Secondary AJAX re-ran the full KPI/summary SQL block** (`kpi_revenue` ~7–8s, customer/order KPIs ~1–1.5s) even though KPIs were already loaded on the initial HTML request. Secondary requests used `$dashSections = ['summary', 'secondary']`.

2. **`pf_reports_branch_performance_merged` dominated secondary time** (~16s single branch wide, ~40–43s All Branches wide). Job-order aggregation used a **correlated subquery** for business date (`pf_reports_job_order_sales_date_expr`) instead of a join, forcing per-row lookups over large `job_orders` scans.

3. **All Branches product/service charts used N+1 queries** (~7–8s): one `pf_reports_sales_by_official_product` + one `pf_reports_sales_by_service_category` **per branch** (`per_branch_charts` phase).

4. **Production was missing the prior progressive deploy** (`dashboard_section=branch_charts` returned full 196KB HTML with `kpi_revenue` timing; no `data-defer-branch-charts-url`).

5. **Not duplicate browser requests** — slowness is **sequential PHP work** on summary then secondary (and redundant summary inside secondary).

## Live timings (before fix deploy)

| Request | Wall (ms) | Dominant phases (ms) |
| --- | ---: | --- |
| Summary, branch 1, Jan–Oct | 6251 | kpi_revenue 4784 |
| Secondary, branch 1, Jan–Oct | 29055 | kpi_revenue 5275, sales_by_category 5763, branch_performance 16250 |
| Summary, all, Oct 12–16 | 9846 | kpi_revenue 8975 |
| Secondary, all, Oct 12–16 | 57156 | kpi_revenue 7871, per_branch_charts 7002, branch_performance 40867 |
| Summary, all, Jan–Oct | 8576 | kpi_revenue 7698 |
| Secondary, all, Jan–Oct | 59334 | kpi_revenue 7497, per_branch_charts 7850, branch_performance 42546 |
| `branch_charts`, all, Jan–Oct | 8646 | **Wrong:** full summary page, not fragment |

## Fixes in this batch

- Secondary requests: **`secondary` section only** + hydrate KPIs from `v3-summary` cache (single-branch revenue-only fallback when cache cold).
- Branch performance + canonical orphan jobs: **join-based job business date** (equivalent to existing COALESCE/subquery).
- All Branches chart slices: **two grouped queries** via `pf_reports_dashboard_all_branches_product_service_slices`.
- Retain: deferred `branch_charts` section, wide-range growth skip, v3 cache keys, indexes, abort stale fetches.

## Expected after deploy

- Secondary cold: **~7–8s less** (no duplicate KPI block); **~7–8s less** on All Branches (batch charts or defer); **material branch_performance reduction** from job date join.
- User-perceived load: KPIs from summary (~8s) then secondary without duplicate KPI SQL.
