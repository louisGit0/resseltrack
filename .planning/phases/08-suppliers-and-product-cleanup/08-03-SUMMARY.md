---
phase: 08-suppliers-and-product-cleanup
plan: 03
subsystem: api
tags: [php, pdo, mvc, suppliers, crud, csrf, idor, routing, bootstrap]

# Dependency graph
requires:
  - phase: 08-01
    provides: suppliers table + orders.supplier_id nullable FK (Schema::ensure / schema.sql) that the model queries against
provides:
  - per-user Supplier model (allForUser with linked-orders COUNT, find/create/update/delete)
  - SupplierController CRUD (index/create/store/edit/update/destroy + validate(); no show action)
  - 6 /suppliers routes (static before parameterized) wired in public/index.php
  - Fournisseurs nav entry (bi-truck) in the Activite section, rendered in sidebar + offcanvas
affects: [08-04 (supplier views index/form + star widget consume this controller/model), 09 (product rating reuses the rating-range validation idiom)]

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "Per-user model mirror of Order.php: :uid-scoped prepared statements, find() returns null when not owned, LEFT JOIN COUNT GROUP BY aggregate for orders_count (D-07)"
    - "CRUD controller mirror of ProductController: Auth::require() in ctor, Csrf::validate() first line of every POST, ownership-or-redirect guard, validate() returning ?array with flashErrors() + old-input"
    - "FK ON DELETE SET NULL handles supplier unlink (D-08) — model delete() is a plain DELETE, no manual orders update"

key-files:
  created:
    - src/Models/Supplier.php
    - src/Controllers/SupplierController.php
  modified:
    - public/index.php
    - src/Views/layout.php

key-decisions:
  - "SUP-01 NOT marked complete this plan — backend only; the final SUP-01 mark lands in 08-04 (views + star widget). Per orchestrator instruction."
  - "URL stored trimmed/null with no server-side filter_var, matching the existing order_url convention (D-05 = 'validated like other URLs')"
  - "rating bound via $data['rating'] ?? null (defensive) since the controller always supplies int|null; equivalent to the plan's $data['rating']"

patterns-established:
  - "Supplier model = Order.php clone with orders_count aggregate"
  - "SupplierController = ProductController CRUD clone minus image/profit concerns and minus a show action (D-09 table-only)"
  - "Single nav line in the $navHtml closure covers both desktop sidebar and mobile offcanvas (D-10)"

requirements-completed: []  # SUP-01 is backend-only here; final completion mark deferred to 08-04 (views) per orchestrator instruction.

# Metrics
duration: 3min
completed: 2026-06-15
---

# Phase 8 Plan 03: SUP-01 Backend (Supplier model + controller + routes + nav) Summary

**Per-user Supplier CRUD plumbing — a Supplier model with a linked-orders COUNT, a CSRF/ownership-guarded SupplierController (no show action), 6 routes, and the Fournisseurs nav entry — all cloned 1:1 from the Order/Product analogs.**

## Performance

- **Duration:** 3 min
- **Started:** 2026-06-15T19:27:47Z
- **Completed:** 2026-06-15T19:30:16Z
- **Tasks:** 2
- **Files modified:** 4 (2 created, 2 modified)

## Accomplishments
- `Supplier` model: `allForUser()` returns each supplier with `COUNT(o.id) AS orders_count` via `LEFT JOIN orders` + `GROUP BY` + `ORDER BY name ASC` (D-07); `find($id,$userId)` returns null when not owned; every query `:uid`-scoped; `delete()` relies on the FK `ON DELETE SET NULL` for unlink (D-08).
- `SupplierController`: `final`, `extends Controller`, `Auth::require()` in ctor; `Csrf::validate()` is the first statement of `store`/`update`/`destroy`; `edit`/`update` guard ownership via `Supplier::find($id, Auth::id())`; `validate()` requires `name` and enforces `rating ∈ {null, 1..5}`; no `show()` action and no `GET /suppliers/{id}` route (D-09 table-only).
- 6 supplier routes registered with `/suppliers/create` before `/suppliers/{id}/edit`, plus the `SupplierController` import.
- "Fournisseurs" nav link (`bi-truck`) added once in the Activité `<nav>`, rendered automatically in both the desktop sidebar and the mobile offcanvas (D-10).

## Task Commits

Each task was committed atomically:

1. **Task 1: Create the Supplier model (per-user CRUD + linked-orders count)** — `7cd578f` (feat)
2. **Task 2: Create SupplierController (CRUD) and register routes + nav** — `6cc6ab6` (feat)

**Plan metadata:** committed separately (docs: complete plan)

## Files Created/Modified
- `src/Models/Supplier.php` — per-user CRUD model; `allForUser()` with linked-orders COUNT, `find/create/update/delete`, all `:uid`-scoped prepared statements.
- `src/Controllers/SupplierController.php` — CRUD controller (index/create/store/edit/update/destroy + private `validate()`); CSRF + ownership + input validation; no show action.
- `public/index.php` — added `use App\Controllers\SupplierController;` and 6 `/suppliers` routes after the Orders block (static before parameterized).
- `src/Views/layout.php` — added the Fournisseurs nav link after Ventes in the Activité section.

## Decisions Made
- **SUP-01 completion deferred:** This plan ships backend plumbing only; the views and star widget that render the data are Plan 08-04. Per the orchestrator instruction, SUP-01 is **not** marked complete here — the final requirement mark belongs to 08-04. `requirements-completed` is left empty.
- **URL validation:** matched the existing `order_url` convention — `trim` + store `null` if empty, no `filter_var(FILTER_VALIDATE_URL)`. The RESEARCH "honest note" confirms the codebase does not server-validate URLs; adding it would be stricter than convention.
- **rating binding:** used `$data['rating'] ?? null` in the model (defensive); the controller always supplies `int|null`, so this is functionally identical to the plan's `$data['rating']`.

## Deviations from Plan

None - plan executed exactly as written.

(Minor hygiene tweak, not a deviation: the `delete()` docblock comment was worded to avoid the literal substring "UPDATE orders" so it cannot trip the "no manual UPDATE orders" source assertion. The functional code contains no orders mutation, exactly as the plan requires.)

## Issues Encountered
None. Both tasks cloned cleanly from the Order/Product analogs. `php -l` clean on all 4 files; full PHPUnit suite green (27 tests, 30 assertions) confirming no regression to existing logic.

## Threat Model Compliance
- **T-08-08 (IDOR on edit/update/destroy):** mitigated — `edit`/`update` guard via `Supplier::find($id, Auth::id())` and redirect with a "Fournisseur introuvable." flash when null; `update`/`delete` SQL scoped `WHERE id AND user_id`.
- **T-08-09 (CSRF):** mitigated — `Csrf::validate()` is the first statement of `store`/`update`/`destroy`.
- **T-08-10 (SQLi):** mitigated — all values bound via PDO prepared statements (`ATTR_EMULATE_PREPARES=false` project-wide); no interpolation.
- **T-08-11 (invalid rating persisted):** mitigated — `validate()` enforces `rating ∈ {null, 1..5}`.

No new threat surface beyond the plan's `<threat_model>`.

## User Setup Required
None for this plan's code. Note (carried from 08-01): the operator must re-run `php bin/migrate.php` against Aiven so the `suppliers` table + `orders.supplier_id` column exist before the deployed `/suppliers` routes are hit at runtime (Wave-5 operator step).

## Next Phase Readiness
- The model + controller + routes + nav are in place; `SupplierController::index/create/edit` reference `suppliers/index` and `suppliers/form` views that **Plan 08-04 creates** (with the star widget). Until 08-04 lands, hitting `/suppliers` would 500 on the missing view — expected, backend-only scope.
- Ready for 08-04 (views) and 08-02 SUP-02 order↔supplier integration (which consumes `Supplier::find()` and `Supplier::allForUser()`).

## Self-Check: PASSED

- FOUND: `src/Models/Supplier.php`
- FOUND: `src/Controllers/SupplierController.php`
- FOUND: `.planning/phases/08-suppliers-and-product-cleanup/08-03-SUMMARY.md`
- FOUND: commit `7cd578f` (Task 1), `6cc6ab6` (Task 2)
- FOUND: `SupplierController` registered in `public/index.php`; `/suppliers` nav link in `src/Views/layout.php`
