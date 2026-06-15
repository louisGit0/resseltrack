---
phase: 06-performance-and-reliability
plan: 01
subsystem: data-access + http-client
tags: [perf, reliability, n+1, curl, fx-fallback]
dependency_graph:
  requires: []
  provides:
    - Purchase::lotsForUser — batch lots query for all products in 1 SQL
    - Sale::soldQtyByProduct — batch sold-qty map via GROUP BY in 1 SQL
    - SaleController::productsMeta() — 3 fixed queries replacing O(3N) loop
    - ExchangeRateService::latest() — curl with 5s timeout, null on all failures
    - PurchaseController::validate() — server-side FX fallback + block on failure
  affects:
    - SaleController.productsMeta callers: create(), duplicate(), edit()
    - PurchaseController.validate callers: store(), update()
tech_stack:
  added: []
  patterns:
    - batch-fetch + PHP grouping (PERF-01 N+1 elimination)
    - curl GET with bounded timeout and structured error logging (PERF-02 service)
    - lazy server-side FX fallback with hard block on null (PERF-02 controller)
key_files:
  created:
    - tests/ExchangeRateServiceTest.php
  modified:
    - src/Models/Purchase.php
    - src/Models/Sale.php
    - src/Controllers/SaleController.php
    - src/Services/ExchangeRateService.php
    - src/Controllers/PurchaseController.php
decisions:
  - "D-01/D-02: Replace O(3N) productsMeta loop with 3 fixed queries + PHP grouping; ProfitCalculator unchanged"
  - "D-03: curl replaces file_get_contents in ExchangeRateService; 5s timeout; logs every failure path; returns null on all errors"
  - "D-04: PurchaseController::validate() lazy-fetches server-side FX rate when submitted rate is invalid/missing"
  - "D-05: If server-side fallback returns null/0, block submission with French error before unitCostEur() — no 0.00 write"
  - "D-06: OrderController::persistLines() rate gap is OUT OF SCOPE — left untouched, documented as known gap"
metrics:
  duration: "~15 minutes"
  completed: "2026-06-15"
  tasks: 3
  files: 6
---

# Phase 06 Plan 01: Performance and Reliability — N+1 fix + FX hardening Summary

Two surgical reliability fixes across six files: eliminate the O(3N) query loop in `SaleController::productsMeta()` (PERF-01) and harden `ExchangeRateService` + `PurchaseController` against a slow or unavailable FX API (PERF-02).

## What Was Built

### PERF-01 — N+1 elimination in productsMeta() (D-01/D-02)

**Purchase::lotsForUser(int $userId): array** added to `src/Models/Purchase.php`:
```sql
SELECT product_id, (unit_cost_eur * quantity) AS cost_eur, quantity
FROM purchases WHERE user_id = :uid
```
Returns all lots for all products in one query. Each row explicitly cast: `product_id => (int)`, `cost_eur => (float)`, `quantity => (int)` — matching the existing `lotsForProduct` cast pattern.

**Sale::soldQtyByProduct(int $userId): array** added to `src/Models/Sale.php`:
```sql
SELECT product_id, COALESCE(SUM(quantity), 0) AS qty
FROM sales WHERE user_id = :uid GROUP BY product_id
```
Returns `[product_id => (int)qty]` map. Products with no sales have no row; callers use `?? 0`.

**SaleController::productsMeta()** rewritten from an O(3N) per-product loop:
- Before the product loop: fetch all lots via `lotsForUser`, group by `product_id` in PHP; fetch sold map via `soldQtyByProduct` once.
- Inside the loop: `$lots = $lotsByProduct[$pid] ?? []`; `$purchasedQty = (int) array_sum(array_column($lots, 'quantity'))`; `ProfitCalculator::cump($lots)` and `ProfitCalculator::stock($purchasedQty, $soldByProduct[$pid] ?? 0)` — identical inputs to the same pure functions → byte-identical output.
- Now exactly 3 queries regardless of product count. `sales/form.php` untouched.

### PERF-02 service — curl rewrite (D-03)

**ExchangeRateService::latest()** in `src/Services/ExchangeRateService.php`:
- Replaced `stream_context_create` + `@file_get_contents` with `curl_init` + `curl_setopt_array`.
- Options: `CURLOPT_RETURNTRANSFER => true`, `CURLOPT_TIMEOUT => 5`, `CURLOPT_CONNECTTIMEOUT => 5` (GET — no POST options).
- On `$body === false`: reads `curl_error($ch)` BEFORE `curl_close($ch)` (Pitfall 3), logs, returns null.
- Reads `$status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE)` then `curl_close($ch)`.
- Returns null + `error_log` on: `$status !== 200`, `$body === ''`, json decode failure or missing `$data['rates'][$to]`.
- 4 `error_log` sites total, each with `$from->$to` context; body truncated to 200 chars in JSON failure log.
- Preserved: identity shortcut (`if ($from === $to) return 1.0`), `?float` signature, `ENDPOINT` const, `$data['rates'][$to]` access.

### PERF-02 test — Wave-0 Nyquist gap (D-03)

**tests/ExchangeRateServiceTest.php** created:
- 3 tests: `latest('EUR','EUR')`, `latest('USD','USD')`, `latest('usd','usd')` all `assertSame(1.0, ...)`.
- No network I/O (identity shortcut exits before curl is called).
- `declare(strict_types=1)`, `namespace Tests`, `use App\Services\ExchangeRateService`, `final class ExchangeRateServiceTest extends TestCase` — mirrors `ProfitCalculatorTest` style.

### PERF-02 controller — fallback + block (D-04/D-05)

**PurchaseController** in `src/Controllers/PurchaseController.php`:
- Added `use App\Services\ExchangeRateService;` import (after `ProfitCalculator`).
- Expanded `elseif ($rate <= 0)` branch in `validate()`:
  - D-04: `$fallback = (new ExchangeRateService())->latest($currency, 'EUR');` — lazy instantiation.
  - If `$fallback !== null && $fallback > 0`: `$rate = $fallback` (used silently, no error added).
  - Else D-05: `$errors['exchange_rate'] = sprintf("Impossible d'obtenir un taux de change valide pour %s. Réessayez ou saisissez le taux manuellement.", htmlspecialchars($currency, ENT_QUOTES, 'UTF-8'))`.
- Existing `if ($errors !== []) { $this->flashErrors($errors, $_POST); return null; }` short-circuits before `ProfitCalculator::unitCostEur()` — no 0.00/wrong-cost write possible.
- Covers both `store()` and `update()` (both call `validate()`).
- EUR stays rate 1.0; valid submitted rate (> 0) unchanged.

## Deviations from Plan

### Verify command broader than acceptance criterion (no code change)

The Task 1 verify command `($c -notmatch 'lotsForProduct')` checks the whole `SaleController.php` file, but `SaleController::validate()` still legitimately calls `lotsForProduct` (line 266) to freeze CUMP at actual sale-time inside a transaction. The plan explicitly says "Do NOT touch create()/duplicate()/edit() or any other method." The acceptance criterion (`productsMeta()` no longer references `lotsForProduct`) is fully met — the per-product CUMP freeze in `validate()` is unrelated to the N+1 loop fix. This is a verify-command scope issue, not a functional gap. Reported honestly; no code change made to validate().

**Full test suite: 27/27 GREEN** (24 ProfitCalculator + 3 ExchangeRateService).

## Unchanged Files (scope guard D-06)

- `src/Services/ProfitCalculator.php` — unmodified; all 24 tests green.
- `src/Views/sales/form.php` — untouched; productsMeta() output keys unchanged.
- `src/Controllers/OrderController.php` — out of scope; known gap documented in plan and threat model.
- No new Composer packages installed.

## Known Stubs

None — no placeholder values, hardcoded data, or TODO stubs introduced.

## Threat Flags

None — all T-6-01 through T-6-05 mitigations implemented as planned. T-6-06 (OrderController gap) remains accepted/deferred per D-06.

## Post-Deploy Smoke Checks (for orchestrator)

These require the live Vercel environment (`https://resseltrack-nu.vercel.app`) after redeploy:

1. **PERF-01 (Success Criterion 1):** Open `/sales/create` with 20+ products in the catalog → page loads in under 2 seconds; spot-check that per-product CUMP/stock values in the product selector match pre-refactor values.
2. **PERF-02 happy path (Success Criterion 2):** Record a USD purchase with a valid exchange rate while frankfurter.app is reachable → saved `unit_cost_eur` is the correct non-zero EUR cost.
3. **PERF-02 block path (Success Criterion 3):** Submit a non-EUR purchase with `exchange_rate=0` while the FX API is unreachable → form shows "Impossible d'obtenir un taux de change valide…" flash and NO new row is written to `purchases`. When the API is reachable with the same invalid rate, the purchase instead saves with the server-fetched rate (fallback path).

## Self-Check: PASSED

All 6 files exist (5 modified + 1 created):
- FOUND: src/Models/Purchase.php
- FOUND: src/Models/Sale.php
- FOUND: src/Controllers/SaleController.php
- FOUND: src/Services/ExchangeRateService.php
- FOUND: src/Controllers/PurchaseController.php
- FOUND: tests/ExchangeRateServiceTest.php

All 4 commits verified:
- 0ed2dd1: perf(06-01): PERF-01 batch queries
- f806a16: test(06-01): ExchangeRateServiceTest RED gate
- 7b8f2e7: feat(06-01): PERF-02 service curl rewrite
- d4666af: feat(06-01): PERF-02 controller fallback+block
