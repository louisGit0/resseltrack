# Phase 6: Performance and Reliability — Validation Map

**Created:** 2026-06-15
**Plan:** 06-01-PLAN.md (single autonomous plan, one wave)
**Coverage scope (phpunit.xml):** `src/Services` only — PHPUnit 11.x, quick run `vendor/bin/phpunit`

This map records how PERF-01 and PERF-02 are verified, and which behaviors are intentionally smoke-only (I/O outside the unit coverage scope) per the Nyquist policy used in Phases 3–5.

---

## PERF-01 → Verification

N+1 fix in `SaleController::productsMeta()` (D-01/D-02). Pure data-access refactor — same numbers, far fewer queries.

| Behavior | Type | Automated command | Status |
|----------|------|-------------------|--------|
| ProfitCalculator::cump() and stock() math unchanged (the values productsMeta feeds) | unit | `vendor/bin/phpunit tests/ProfitCalculatorTest.php` | EXISTS — 25 tests, must stay GREEN (baseline for PERF-01 correctness) |
| `Purchase::lotsForUser` + `Sale::soldQtyByProduct` exist with the exact batched SQL; `productsMeta` no longer per-product-loops | structural | `php -l` on the 3 files + PowerShell greps in Task 1 `<verify>` | gate in plan |
| Sale create/edit page loads < 2s with 20+ products AND per-product cump/stock unchanged | smoke (live) | Manual: open `/sales/create` on the deployed URL with 20+ products; spot-check cump/stock vs. pre-refactor | N/A — I/O (DB), outside `src/Services` scope |

**Smoke gap (intentional, not scaffolded):** the PHP grouping (`array_sum` / `array_column` / `?? []` / `?? 0`) is trivial and DB-bound; it is verified by the unchanged ProfitCalculator suite + structural greps + the live visual smoke. No grouping-level unit test is created — it would require DB fixtures outside the `src/Services` coverage scope.

---

## PERF-02 → Verification

ExchangeRateService curl hardening (D-03) + PurchaseController server-side fallback/block (D-04/D-05).

| Behavior | Type | Automated command | Status |
|----------|------|-------------------|--------|
| `latest('EUR','EUR') === 1.0` (and `'USD','USD'`, `'usd','usd'`) — identity path, no network | unit | `vendor/bin/phpunit tests/ExchangeRateServiceTest.php` | **Wave-0 gap CLOSED** — new file created in Task 2 (in `src/Services` coverage scope) |
| `latest()` uses curl + 5s timeouts, `!== 200` check, ≥4 error_log sites, curl_error before curl_close, no file_get_contents | structural | `php -l` + PowerShell greps in Task 2 `<verify>` | gate in plan |
| Import + `$fallback > 0` fallback + exact French block message before `unitCostEur` | structural | `php -l` + PowerShell greps in Task 3 `<verify>` | gate in plan |
| USD purchase with valid rate (API normal) saves correct non-zero EUR cost | smoke (live) | Manual: record USD purchase, verify `unit_cost_eur` correct | N/A — I/O (network + DB) |
| USD purchase with invalid rate + API down → French flash, NO `purchases` row written (no 0.00) | smoke (live) | Manual: submit `exchange_rate=0` with API unreachable; verify flash + no new row | N/A — I/O (network + DB) |
| USD purchase with invalid rate + API up → saves with server-fetched fallback rate | smoke (live) | Manual: submit `exchange_rate=0` with API reachable; verify purchase created with API rate | N/A — I/O (network + DB) |

**Smoke gaps (intentional, not scaffolded):** the curl HTTP paths (timeout, non-200, empty body, JSON failure) and the controller fallback/block paths require live network + DB I/O, which is outside the `src/Services` unit coverage scope and is not mockable without restructuring the service (D-06 forbids new dependencies). These are verified live post-deploy. Only the network-free identity path is unit-tested.

---

## Sampling Rate

- **Per task commit:** `vendor/bin/phpunit` — all existing ProfitCalculator tests + the new ExchangeRateService identity test stay GREEN.
- **Per wave / phase gate:** `vendor/bin/phpunit --coverage-text` — `src/Services` coverage must not regress (ExchangeRateService identity path now covered; ProfitCalculator unchanged).
- **Post-deploy (orchestrator):** the three live success-criteria smoke checks above on https://resseltrack-nu.vercel.app before `/gsd:verify-work`.

## Wave-0 Gaps

- [x] `tests/ExchangeRateServiceTest.php` — identity path (`latest('EUR','EUR') === 1.0`) — created in Task 2. No test framework install needed (PHPUnit configured, `vendor/` populated).

## Out-of-Scope (documented, not validated here)

- `OrderController::persistLines()` rate fallback — deferred per D-06 (T-6-06). Same `$rate <= 0` guard exists without a server-side fallback; risk unchanged from today. To be closed in a future phase.
