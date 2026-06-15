# Phase 6: Performance and Reliability - Context

**Gathered:** 2026-06-15
**Status:** Ready for planning

<domain>
## Phase Boundary

Two targeted fixes to real application logic (first non-infra phase):
1. **PERF-01** — kill the N+1 in `SaleController::productsMeta()` (currently 3 queries per product) so the sale create/edit page loads fast with 20+ products.
2. **PERF-02** — harden `ExchangeRateService` (curl + timeout + logging + robust error handling) and use it as a **server-side fallback** so a USD purchase never silently stores a `0.00` (or wrong) EUR cost; on total failure, block with a visible French error.

Requirements: **PERF-01** (aggregate query replaces the N+1), **PERF-02** (curl+5s timeout+logging; API failure never silently writes 0.00 — visible warning).

**Out of scope / deferred:** broader query optimization elsewhere, caching of FX rates, retry/backoff on the FX API, dashboard query tuning. Only the two named hotspots.
</domain>

<decisions>
## Implementation Decisions

### PERF-01 — N+1 fix (batch-fetch + compute in PHP)
- **D-01:** Replace the per-product loop with **3 fixed queries** total (regardless of product count), preserving `ProfitCalculator` logic exactly:
  - `Product::allForUser($userId)` — already 1 query.
  - **New `Purchase::lotsForUser($userId)`** — `SELECT product_id, (unit_cost_eur * quantity) AS cost_eur, quantity FROM purchases WHERE user_id = :uid` (the same row shape `lotsForProduct` returns — `cost_eur` + `quantity` — plus `product_id` for grouping). 1 query for ALL lots.
  - **New `Sale::soldQtyByProduct($userId)`** — `SELECT product_id, COALESCE(SUM(quantity),0) AS qty FROM sales WHERE user_id = :uid GROUP BY product_id` → map `[product_id => soldQty]`. 1 query.
- **D-02:** `productsMeta()` rewritten: group the batched lots by `product_id` in PHP; for each product compute `purchasedQty = sum(quantity)` of its lots, `cump = ProfitCalculator::cump($lotsForThatProduct)` (UNCHANGED — same `cost_eur`/`quantity` rows it gets today), `stock = ProfitCalculator::stock($purchasedQty, $soldByProduct[$pid] ?? 0)`. Products with no lots → cump 0.0, stock from 0 purchased. **CUMP/stock outputs must be byte-identical to the current per-product implementation** (pure refactor of data access, not of the math). Do NOT compute CUMP in SQL (the weighted-average + port/customs allocation lives in ProfitCalculator and must stay there).

### PERF-02 — ExchangeRateService hardening
- **D-03:** Rewrite `ExchangeRateService::latest()` to use **curl** (`CURLOPT_TIMEOUT=5`, `CURLOPT_CONNECTTIMEOUT` ~5, `CURLOPT_RETURNTRANSFER=true`) instead of `file_get_contents`. Keep the `$from === $to → 1.0` shortcut and the `frankfurter.app/latest?from=&to=` endpoint. Return `?float` (unchanged signature). **`error_log` every failure** with context. Return `null` cleanly on: curl error/`false`, HTTP status ≠ 200, empty body, JSON decode failure, or missing `rates[$to]`. (curl is available on vercel-php — CloudinaryStorage already uses it.)

### PERF-02 — Server-side rate fallback + no silent 0.00
- **D-04:** In `PurchaseController::validate()` (covers both `store()` and `update()` — both call it), when `currency !== 'EUR'`: if the submitted `exchange_rate` is missing or `<= 0`, attempt a **server-side fallback** `(new ExchangeRateService())->latest($currency, 'EUR')` (lazy instantiation, Services convention). If it returns a positive rate → use it. Keep using the submitted rate when it is already valid (> 0). The stored `exchange_rate` is therefore always a validated value `> 0`.
- **D-05:** If `currency !== 'EUR'` AND the submitted rate is invalid AND the fallback returns `null` → **block the submission**: add a clear French error via `flashErrors` (e.g. "Impossible d'obtenir un taux de change valide pour {DEVISE}. Réessayez ou saisissez le taux manuellement.") and `return null` BEFORE `ProfitCalculator::unitCostEur()` runs. **Never** compute or persist `unit_cost_eur` from an invalid rate — no `0.00`/wrong-cost write. EUR stays rate `1.0` (unchanged).

### Out-of-scope guard
- **D-06:** No new dependencies, no caching layer, no retry. Touch only `SaleController::productsMeta()`, the two new model methods, `ExchangeRateService`, and `PurchaseController::validate()` (+ the existing positive-rate guard which is extended by the fallback). `ProfitCalculator` math is NOT modified.
</decisions>

<canonical_refs>
## Canonical References
- `.planning/REQUIREMENTS.md` §Performance (PERF-01, PERF-02)
- `src/Controllers/SaleController.php` — `productsMeta()` (the N+1 loop)
- `src/Models/Purchase.php` — `lotsForProduct` (row shape `cost_eur`,`quantity`), `allForUser`, `purchasedQty`; add `lotsForUser`
- `src/Models/Sale.php` — `soldQty`; add `soldQtyByProduct` (GROUP BY)
- `src/Services/ProfitCalculator.php` — `cump(array $lots)` reads `$lot['cost_eur']` + `$lot['quantity']`; `stock(int,int)` — UNCHANGED, the math source of truth
- `src/Services/ExchangeRateService.php` — `latest()` (rewrite to curl)
- `src/Controllers/PurchaseController.php` — `validate()` (lines ~146-205: currency/rate guard at 173-181, `unitCostEur` at 191) for the fallback + block
- `src/Services/CloudinaryStorage.php` — the established curl pattern to mirror in ExchangeRateService
</canonical_refs>

<code_context>
## Existing Code Insights
### Reusable Assets
- `ProfitCalculator::cump()`/`stock()` are pure and already tested — reuse unchanged; the N+1 fix only changes HOW lots are fetched, not the math.
- `lotsForProduct` already returns exactly `cost_eur` + `quantity`; `lotsForUser` is the same SELECT minus the `product_id` filter, plus `product_id` in the projection.
- `CloudinaryStorage` shows the curl idiom (timeout, RETURNTRANSFER, RESPONSE_CODE check, throw/return on error) to mirror in `ExchangeRateService` (return null instead of throw, per its `?float` contract).
- `PurchaseController::validate()` already rejects `rate <= 0` for non-EUR — D-04 inserts the fallback fetch *before* that rejection becomes terminal, and D-05 turns the unrecoverable case into a clear blocking error.
### Established Patterns
- Models own SQL, scoped to `:uid`; controllers never write SQL. Services lazily `new`ed inside controller methods.
- `final` classes, `declare(strict_types=1)`, prepared statements.
### Integration Points
- ExchangeRateService becomes used SERVER-SIDE for the first time (today only the client JS button uses the rate; the service was a dormant fallback).
- productsMeta() feeds the sale create/edit views — output shape per product (adds `cump`, `stock`) must stay identical so views are untouched.
</code_context>

<specifics>
## Specific Ideas
- PERF-01 is a pure data-access refactor: the acceptance bar is "same numbers, far fewer queries" — a correctness-preserving change, easy to verify by comparing cump/stock before/after on seeded data.
- PERF-02's real win is making the server authoritative about a valid FX rate instead of trusting client JS — the block-on-failure path is the safety guarantee.
</specifics>

<deferred>
## Deferred Ideas
- FX rate caching / retry-backoff (v2).
- Dashboard / other query optimization (not a named hotspot).
- Persisting a "rate fetched server-side vs client" provenance flag.

### Research Questions (for gsd-phase-researcher)
- Confirm `lotsForProduct`'s exact SELECT so `lotsForUser` matches it (just add `product_id`); confirm no other caller depends on `productsMeta`'s internal query pattern.
- Confirm `Sale` table column for sold quantity (`quantity`?) for the `soldQtyByProduct` GROUP BY, and that `purchasedQty`'s current definition equals `SUM(quantity)` (so deriving it from lots in PHP matches).
- Exact curl options for a 5s-bounded request on vercel-php; confirm frankfurter.app response shape (`{"rates":{"EUR":x}}`) and that it's reachable from the Lambda (connect-src already allows api.frankfurter.app, but that's CSP for the browser — server-side curl is unaffected).
- Whether `update()` re-runs `validate()` identically (so the fallback covers edit too) and whether any other controller computes `unit_cost_eur`.
- Nyquist: ProfitCalculator is the tested unit and is unchanged; the new model methods + curl are I/O (out of the `src/Services/` business-logic coverage scope) — document the verification approach (before/after value parity for PERF-01; block-behavior + service-null-handling for PERF-02), mirroring prior phases.
</deferred>

---

*Phase: 6-Performance and Reliability*
*Context gathered: 2026-06-15*
