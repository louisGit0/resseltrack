---
phase: 08-suppliers-and-product-cleanup
plan: 01
subsystem: schema
tags: [ddl, migration, suppliers, idempotent, foreign-key]
dependency_graph:
  requires: []
  provides:
    - "suppliers table (per-user supplier directory)"
    - "orders.supplier_id nullable FK (ON DELETE SET NULL)"
  affects:
    - "src/Core/Schema.php"
    - "sql/schema.sql"
    - "operator step: bin/migrate.php re-run (Plan 06)"
tech_stack:
  added: []
  patterns:
    - "Idempotent additive migration: CREATE TABLE IF NOT EXISTS + SHOW COLUMNS-guarded ALTER (mirror of fk_purchases_order)"
key_files:
  created: []
  modified:
    - "src/Core/Schema.php"
    - "sql/schema.sql"
decisions:
  - "supplier_id added ONLY via guarded Schema::ensure() ALTER, never in schema.sql orders block (no-op on live table)"
  - "FK ON DELETE SET NULL (D-08) unlinks orders on supplier delete; free-text orders.supplier preserved (D-02)"
  - "SUP-01/SUP-02 requirements NOT marked complete — this plan is schema-only; CRUD + order-link UI land in later plans (08-02..08-05)"
metrics:
  duration: "~3m"
  tasks: 2
  files: 2
  completed: "2026-06-15"
---

# Phase 8 Plan 01: Suppliers Schema Foundation Summary

Additive, idempotent DDL for Phase 8: a per-user `suppliers` table plus a nullable `orders.supplier_id` foreign key (`ON DELETE SET NULL`), added to both `sql/schema.sql` (fresh installs) and `Schema::ensure()` (idempotent live application), mirroring the existing `fk_purchases_order` guard pattern.

## What Was Built

- **`src/Core/Schema.php`** — inside `Schema::ensure()`, two new blocks appended after the purchases guard, in order:
  1. `CREATE TABLE IF NOT EXISTS suppliers` (id, user_id, name VARCHAR(150), url VARCHAR(500) NULL, rating TINYINT UNSIGNED NULL, comment TEXT NULL, created_at/updated_at timestamps, `idx_suppliers_user`, `fk_suppliers_user → users(id) ON DELETE CASCADE`).
  2. A `SHOW COLUMNS FROM orders LIKE 'supplier_id'` guard; when absent, `ALTER TABLE orders ADD COLUMN supplier_id INT UNSIGNED NULL AFTER user_id` followed by a second ALTER adding `idx_orders_supplier` + `fk_orders_supplier → suppliers(id) ON DELETE SET NULL`.
  The suppliers table is created **before** the orders ALTER so the FK target exists even when `Schema::ensure()` runs standalone.
- **`sql/schema.sql`** — a matching `CREATE TABLE IF NOT EXISTS suppliers` block placed after the `orders` table block, using the in-file 2-space style and the `ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;` footer. `supplier_id` is intentionally **not** added to the orders block here (it is a no-op on the live table — added only via the guarded `Schema::ensure()` ALTER).

## How to Verify

- `php -l src/Core/Schema.php` → "No syntax errors detected" (passed).
- `grep -c "CREATE TABLE IF NOT EXISTS suppliers" src/Core/Schema.php` → 1; same in `sql/schema.sql` → 1.
- `grep -c "SHOW COLUMNS FROM orders LIKE 'supplier_id'" src/Core/Schema.php` → 1.
- `grep -c "fk_orders_supplier" src/Core/Schema.php` → 1 (block contains `ON DELETE SET NULL`).
- `grep -c "fk_suppliers_user" src/Core/Schema.php` → 1 (references `users (id) ON DELETE CASCADE`).
- `grep -c "supplier_id" sql/schema.sql` → 0 (column absent from schema.sql per the anti-pattern).
- `vendor/bin/phpunit` → 27 tests / 30 assertions, OK (no logic touched).

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 3 - Blocking] Reworded schema.sql comment to satisfy the literal `supplier_id` grep criterion**
- **Found during:** Task 2 verification.
- **Issue:** The first draft of the explanatory comment in `sql/schema.sql` literally contained the token `supplier_id` ("The orders.supplier_id FK is added by Schema::ensure()…"), which made `grep -c "supplier_id" sql/schema.sql` return 1, violating the plan's exact acceptance criterion of 0. The column itself was correctly absent — only the prose tripped the assertion.
- **Fix:** Reworded the comment to "The orders FK to this table is added by Schema::ensure() (guarded ALTER), NOT here…", removing the literal token while preserving the explanation.
- **Files modified:** `sql/schema.sql`
- **Commit:** 5354ab2

## Notes / Follow-ups

- **Operator migration is required later (Plan 06):** No DDL runs per request (DB-03). The operator re-runs `php bin/migrate.php` against Aiven to apply these additions. The schema is purely additive and backward-compatible with live v1.0 code, so migrating before deploying new code gives a zero-downtime window.
- **Requirements SUP-01 / SUP-02 intentionally NOT marked complete:** This plan delivers only the schema foundation. The full Fournisseurs CRUD (model/controller/views/routes) and the order↔supplier dropdown UI are delivered in later plans (08-02..08-05). Marking these requirements complete now would misrepresent state; they will be checked off by their final contributing plan.

## Self-Check: PASSED

- `src/Core/Schema.php` — FOUND (modified, php -l clean)
- `sql/schema.sql` — FOUND (modified)
- Commit 528ca91 — FOUND (Task 1)
- Commit 5354ab2 — FOUND (Task 2)
