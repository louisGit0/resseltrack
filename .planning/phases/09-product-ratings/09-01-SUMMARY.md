---
phase: 09-product-ratings
plan: 01
subsystem: schema / DDL
tags: [migration, products, rating, additive-column, idempotent]
requires: []
provides:
  - products.rating (TINYINT UNSIGNED NULL)
  - products.rating_note (TEXT NULL)
affects:
  - src/Models/Product.php (later plans add to create()/update() column lists)
  - src/Controllers/ProductController.php (later plans validate/persist rating)
tech_stack:
  added: []
  patterns:
    - "SHOW COLUMNS-guarded ALTER TABLE (idempotent additive migration)"
    - "semicolon-free schema.sql comments (bin/migrate.php splits on ';')"
key_files:
  created: []
  modified:
    - sql/schema.sql
    - src/Core/Schema.php
decisions:
  - "Guard on a single sentinel column ('rating') — one SHOW COLUMNS covers both columns, mirroring the market_price guard."
  - "No FK and no index — plain nullable columns, exactly like market_price_*; rating types mirror suppliers.rating / suppliers.comment."
  - "Quick-rate persistence (Model/Controller/View) deferred to later Phase 9 plans; this plan is storage-only."
requirements: [RATE-01]
metrics:
  duration: ~3m
  tasks: 2
  files: 2
  completed: 2026-06-16
---

# Phase 9 Plan 01: Product Ratings Schema Summary

Adds the two additive nullable columns RATE-01 needs — `products.rating` (TINYINT UNSIGNED NULL) and `products.rating_note` (TEXT NULL) — to both the fresh-install DDL (`sql/schema.sql`) and the idempotent live migration (`src/Core/Schema.php`), mirroring the Phase 8 `orders.supplier_id` / `market_price_*` additive-column pattern. No FK, no index, zero data migration — existing products keep `rating = NULL`.

## What Was Built

### Task 1 — `sql/schema.sql` (commit 80bd5c5)
Inside the existing `products` `CREATE TABLE IF NOT EXISTS` block, between `market_price_used` and `created_at`, added:
```sql
  rating      TINYINT UNSIGNED NULL,     -- note produit 1-5 (RATE-01, NULL = non noté)
  rating_note TEXT NULL,                 -- commentaire libre sur le produit
```
Both inline comments are **semicolon-free**, matching the existing `-- prix constaté…` style — this avoids the Phase 8 deviation where a `;` inside a comment broke `bin/migrate.php`'s `explode(';')` splitter (commit 6c8dcd7). This block only affects fresh data volumes; the live DB gets the columns from `Schema::ensure()`.

### Task 2 — `src/Core/Schema.php` (commit b625c06)
In `Schema::ensure()`, immediately after the `market_price` guarded block, added an analogous guarded ALTER:
```php
$hasRating = $db->query("SHOW COLUMNS FROM products LIKE 'rating'")->fetch();
if (!$hasRating) {
    $db->exec(
        'ALTER TABLE products
            ADD COLUMN rating TINYINT UNSIGNED NULL AFTER market_price_used,
            ADD COLUMN rating_note TEXT NULL AFTER rating'
    );
}
```
The `SHOW COLUMNS FROM products LIKE 'rating'` guard makes a second `php bin/migrate.php` run a clean no-op (no Duplicate column error) — the same idempotency proven for `orders.supplier_id` in 08-06. Column types mirror `suppliers.rating` (`TINYINT UNSIGNED NULL`) and `suppliers.comment` (`TEXT NULL`).

## Verification

- `grep -c "rating_note TEXT NULL" sql/schema.sql` → 1
- `grep -c "rating      TINYINT UNSIGNED NULL" sql/schema.sql` → 1
- No `;` in either new schema.sql comment (grep for `rating.*--.*;` returned nothing)
- `php -l src/Core/Schema.php` → "No syntax errors detected"
- `grep -c "SHOW COLUMNS FROM products LIKE 'rating'"` → 1; `ADD COLUMN rating TINYINT UNSIGNED NULL` → 1; `ADD COLUMN rating_note TEXT NULL` → 1; `if (!$hasRating)` guard present
- `vendor/bin/phpunit` → OK (27 tests, 30 assertions) — ProfitCalculator regression guard green
- Live idempotency (migrate.php x2 → exit 0, `SHOW COLUMNS FROM products LIKE 'rating'` returns one row) is verified by the operator in Plan 09-05 — not run here per plan (no migrations in this plan).

## Deviations from Plan

None — plan executed exactly as written. No auth gates, no checkpoints, no architectural changes.

## Threat Surface

The plan's threat register (T-09-DDL idempotency, T-09-SPL `;`-splitter) is mitigated as specified: the SHOW COLUMNS guard provides idempotency and the new comments are `;`-free. No new security-relevant surface introduced (additive nullable columns, no FK, no request-path DDL — migration runs operator-only via bin/migrate.php).

## Known Stubs

None. The two columns are storage only by design; the read path (`Product::find`/`allForUser` use `SELECT *`) picks them up automatically. Write path (Model column lists, validation, views, quick-rate) is intentionally deferred to later Phase 9 plans per the phase decomposition — not a stub blocking this plan's goal (RATE-01 storage).

## Commits

- 80bd5c5: feat(09-01): add products.rating + rating_note to fresh-install schema
- b625c06: feat(09-01): add SHOW COLUMNS-guarded ALTER for products rating + rating_note

## Self-Check: PASSED

- FOUND: sql/schema.sql
- FOUND: src/Core/Schema.php
- FOUND: .planning/phases/09-product-ratings/09-01-SUMMARY.md
- FOUND: commit 80bd5c5
- FOUND: commit b625c06
