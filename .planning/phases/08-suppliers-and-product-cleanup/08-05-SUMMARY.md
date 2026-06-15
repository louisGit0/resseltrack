---
phase: 08-suppliers-and-product-cleanup
plan: 05
subsystem: orders
tags: [SUP-02, idor, dual-write, backward-compat]
requires:
  - "Supplier model (find/allForUser) — Plan 08-03"
  - "orders.supplier_id nullable FK (ON DELETE SET NULL) — Plan 08-01"
  - "app.js order-form initializer + initStarRating() — Plans 08-04 / prior"
provides:
  - "Order ↔ supplier link with id+name dual-write (D-02)"
  - "OrderController::parseInput() IDOR-guarded supplier resolution"
  - "orders/form.php supplier <select> + 'Autre' free-text toggle"
  - "app.js syncSupplier() select-driven .d-none toggle"
affects:
  - src/Controllers/OrderController.php
  - src/Models/Order.php
  - src/Views/orders/form.php
  - public/assets/js/app.js
tech-stack:
  added: []
  patterns:
    - "IDOR re-validation: Supplier::find($postedId, Auth::id()) before linking"
    - "Dual-write: resolved supplier name into existing free-text column (D-02)"
    - "Select-driven .d-none toggle mirroring syncCurrency() (app.js)"
key-files:
  created: []
  modified:
    - src/Controllers/OrderController.php
    - src/Models/Order.php
    - src/Views/orders/form.php
    - public/assets/js/app.js
decisions:
  - "Free-text input nested inside the same column as the select (#o-supplier-free wrapper) rather than a sibling grid column — cleaner default layout, still a select-driven .d-none toggle"
  - "supplier_id bound with `?? null` idiom matching the existing optional-column convention"
metrics:
  duration: 6m
  completed: 2026-06-15
  tasks: 2
  files: 4
---

# Phase 8 Plan 5: Order ↔ Supplier Link (SUP-02) Summary

Orders can now optionally link a saved supplier through a dropdown plus an "Autre" free-text fallback; selecting a supplier dual-writes both `orders.supplier_id` and the resolved name into the existing free-text column (D-02), the posted id is re-validated for ownership (IDOR guard), no selection is accepted (D-03), and legacy free-text orders render and save unchanged (D-04).

## What Was Built

**Task 1 — Controller resolution + model persistence** (`9130622`)
- `OrderController::parseInput()` now reads both the free-text `supplier` and the dropdown `supplier_id`. It defaults to the free-text path; only when `supplier_id > 0` AND `Supplier::find($id, Auth::id())` returns an owned row does it set the resolved id and dual-write the resolved supplier name into the existing `supplier` text column. A null/unowned id falls through to free text and `supplier_id` stays `null` — the posted id is never trusted (IDOR control T-08-15).
- `$header` now carries both `supplier` (resolved-or-free name) and `supplier_id` (resolved id or null). `persistLines()` is unchanged — it still copies `$header['supplier']` onto each purchase line (D-04); no `supplier_id` on purchase lines.
- `create()` and `edit()` pass `'suppliers' => $this->suppliers->allForUser(<userId>)` to `orders/form`.
- `Supplier` injected as a `private Supplier $suppliers;` ctor property (matching the `$this->orders`/`$this->products` convention) via a `use App\Models\Supplier;` import.
- `Order::create()` INSERT column list + placeholders + binds and `Order::update()` SET clause + binds now include `supplier_id`, bound `?? null` when absent. `find()`/`allForUser()` (SELECT *) untouched — reads pick up the column automatically.

**Task 2 — Form select + toggle** (`f2acbe5`)
- `orders/form.php`: the free-text supplier `<input>` became a `<select name="supplier_id" id="o-supplier">` with an empty "Autre / saisie libre…" first option then one `e()`-escaped option per `$suppliers` row, pre-selected via `$val('supplier_id')` for edit mode. The free-text `<input name="supplier">` is nested inside an `id="o-supplier-free"` wrapper that carries `.d-none` when a supplier is selected; its value stays `$val('supplier')` so legacy orders render. `@var array $suppliers` added to the docblock.
- `app.js`: `syncSupplier()` added inside `initOrderForm()` alongside `syncCurrency()` — toggles `#o-supplier-free` `.d-none` based on whether `#o-supplier` is empty, bound to the select `change` event, and called once on load (so edit-mode legacy orders show the free-text field). Element-guarded. `initStarRating()` from Plan 04 left intact.

## Verification

- `php -l src/Controllers/OrderController.php` — clean
- `php -l src/Models/Order.php` — clean (`grep -c supplier_id` = 5, ≥ 4)
- `php -l src/Views/orders/form.php` — clean
- `node --check public/assets/js/app.js` — exit 0
- `grep -c syncSupplier public/assets/js/app.js` = 3 (≥ 1)
- `grep -c initStarRating public/assets/js/app.js` = 2 (≥ 1 — Plan 04 not regressed)
- `vendor/bin/phpunit` — green (27 tests, 30 assertions) after each task

Live order create/edit + legacy-order render is the Wave-5 operator verification (post-migration on Vercel).

## Threat Model Compliance

| Threat ID | Disposition | Status |
|-----------|-------------|--------|
| T-08-15 (IDOR linking another user's supplier) | mitigate | `Supplier::find($supplierId, Auth::id())` ownership re-check in parseInput(); unowned → free text, supplier_id null |
| T-08-16 (CSRF on order create/update) | mitigate | existing `Csrf::validate()` in store()/update() unchanged |
| T-08-17 (SQLi via supplier_id / name) | mitigate | supplier_id cast to int + bound; name bound, never interpolated |
| T-08-18 (legacy backward-incompat) | mitigate | D-02 dual-write preserves free-text column; persistLines() unchanged; supplier_id nullable |
| T-08-19 (stored XSS via option labels) | mitigate | option labels escaped with `e()` |

## Deviations from Plan

None - plan executed exactly as written. (Implementation choice: the free-text input is nested inside the select's column under `#o-supplier-free` rather than a sibling grid column — still the prescribed select-driven `.d-none` toggle, just a tidier default layout.)

## Known Stubs

None.

## Self-Check: PASSED

All 4 modified files + SUMMARY.md present on disk; both task commits (`9130622`, `f2acbe5`) exist in history.
