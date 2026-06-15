# Phase 6 Discussion Log

**Date:** 2026-06-15
**Mode:** discuss (interactive)

## Gray areas presented
N+1 fix approach (PERF-01) · Rate source (PERF-02) · API-failure behavior · ExchangeRateService hardening.
User chose to discuss **all four**.

## Decisions

| Area | Decision |
|------|----------|
| PERF-01 N+1 | **Batch-fetch + compute in PHP** — Purchase::lotsForUser + Sale::soldQtyByProduct; group in PHP; ProfitCalculator::cump/stock unchanged. 3N+1 → 3 fixed queries (D-01/D-02) |
| PERF-02 rate source | **Server-side fallback** via hardened ExchangeRateService when non-EUR & submitted rate missing/≤0 (D-04) |
| API-failure behavior | **Block with visible FR error** — never write 0.00/wrong cost (D-05) |
| Service hardening | **curl + 5s timeout + error_log + handle non-200/empty/malformed JSON → null** (D-03) |

## Notes
- ProfitCalculator math is the source of truth and is NOT modified — PERF-01 is a data-access refactor that must keep cump/stock outputs identical.
- ExchangeRateService is used SERVER-SIDE for the first time (was a dormant fallback; rate came only from the client JS button).
- validate() covers both store() and update(), so the fallback+block applies to create and edit.
- Routed to researcher: exact lotsForProduct/sold-quantity column shapes, curl options on vercel-php, frankfurter.app reachability from the Lambda, Nyquist verification approach (value-parity for PERF-01; block-behavior for PERF-02).
