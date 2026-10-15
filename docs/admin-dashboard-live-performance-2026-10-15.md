# Admin dashboard live investigation, October 15, 2026

Authenticated integrated-browser tests clicked Cabuyao, Las Piñas, All Branches,
the weekly preset, and custom October 1–14 dates on all three branch scopes.
The weekly preset settled on October 12–15. There are date inputs, no time inputs.
Branch links currently reset the date to Today; this existing behavior is unchanged.

## Live server durations

Read from authenticated opt-in DOM diagnostic JSON (`dashboard_perf=1`). These
are PHP server durations, not browser load, network, TTFB or paint measurements.

| Scope / dates | Summary | Secondary | Cache state |
| --- | ---: | ---: | --- |
| All / Oct 1–14 | 87.3 ms | 332.6 ms | hits |
| All / Oct 1–14 repeat | 104.3 ms | 323.6 ms | hits |
| Cabuyao / Oct 1–14 | 82.8 ms | 339.6 ms | hits |
| Las Piñas / Oct 1–14 | 83.0 ms | 351.4 ms | hits |
| All / Oct 2–14 | 2779.0 ms | 9336.7 ms | summary and secondary miss-stored |
| All / Oct 2–14 later cold repeat | 2939.5 ms | 9094.6 ms | summary and secondary miss-stored |

On the first cold Oct 2–14 request, revenue took 2339.5 ms, order counts
360.8 ms; secondary branch performance took 8585.2 ms, recent orders375.3 ms,
per-branch charts21.4 ms and low stock8.3 ms. The second cold branch performance
took8294.0 ms. A subsequent cache-hit secondary took365.1 ms and a summary hit
took99.3 ms. Cache entries expire after60 seconds by default, so a later repeat
can legitimately be cold. Cache reuse is working; it does not eliminate cold SQL.

Skeleton loading and separate summary/secondary responses were observed live.
Page console warning/error inspection returned an empty list. Browser-tool
Statsig telemetry produced external Cloudflare errors; these were not dashboard
console entries.

## Displayed data cross-check

| Scope | Today revenue | Custom orders | Pending |
| --- | ---: | ---: | ---: |
| Cabuyao | 6732 | 33 | 21 |
| Las Piñas | 10940 | 14 | 14 |
| All | 17672 | 47 | 35 |

October1–14 revenues53821+13250=67071, custom orders118+24=142 and
pending23+24=47 also reconciled. Customer registration totals have different
branch association scope; no claim that they are additive or DB-audited.

## Additional local fix

The dashboard branch aggregate now opts out of redundant product/custom
classification EXISTS probes. Product scope is `(archive AND NOT custom)` and
custom scope is `(archive AND custom)`; their union is archive, already required
by the sales predicate. The default report caller path stays unchanged. Current
and previous period SQL parameters, revenue rules and output fields stay intact.
An isolated test runs the real helper and verifies SQL/binding differences are
limited to that equivalent predicate, with classification truth-table coverage.

PHP lint, cache/progressive/revenue/branch-aggregate tests, both JS loader/filter
tests, JS syntax, report double-count, dashboard service label, staff revenue/top
sales, order completion/payment and26 QRPh regression checks passed locally.
These tests use isolated/stubbed data, not production DB execution.

The new aggregate simplification has NOT been deployed by this agent. No usable
deployment/server/DB connection is available in the exposed tools; the Hostinger
connector exposes billing only. There is no EXPLAIN evidence for index changes,
so none were added. Revenue's remaining2.3s query phase needs query-plan evidence
before further SQL changes. No end-to-end before/after improvement or full
unrelated-feature regression claim is established. Earlier broad POS processing
and inventory movement checks have known unrelated failures recorded in prior
work; they were not changed as part of this patch.
