---
phase: 08-suppliers-and-product-cleanup
plan: 02
subsystem: infra
tags: [cloudinary, image-storage, product-delete, best-effort-purge, php]

# Dependency graph
requires:
  - phase: 04-image-storage-r2
    provides: CloudinaryStorage::delete() signed-curl helper that no-ops on legacy /assets/uploads/ paths
provides:
  - "ProductController::destroy() purges a deleted product's cover + every gallery image from Cloudinary, best-effort"
  - "Closes the OPS-06 v1.0 orphaned-image debt tracked in STATE.md"
affects: [product-deletion, cloudinary-asset-lifecycle]

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "Best-effort external-service purge on delete: collect paths BEFORE the cascading DB delete, dedupe, delete, then loop try/catch over \\Throwable with error_log() — never block the DB delete"

key-files:
  created: []
  modified:
    - src/Controllers/ProductController.php

key-decisions:
  - "Collect cover (products.image_path) + gallery (product_images.path) paths BEFORE Product::delete() — the ON DELETE CASCADE wipes product_images rows, so reading after delete would yield nothing"
  - "Dedupe paths with array_values(array_unique(array_filter(...))) — the cover is usually also a gallery row, and Cloudinary destroy is idempotent but dedupe halves the requests"
  - "Cloudinary failure is logged via error_log() and never re-thrown — the DB rows are always removed (D-11), mirroring the existing deleteImage() best-effort pattern"
  - "No new use statements — App\\Models\\ProductImage and App\\Services\\CloudinaryStorage were already imported"
  - "Kept the existing success flash text per the plan action ('Keep the existing success flash')"

patterns-established:
  - "Best-effort purge-on-delete loop: paths collected pre-cascade, deduped, then per-path try/catch(\\Throwable)+error_log that never blocks the primary DB delete"

requirements-completed: [OPS-06]

# Metrics
duration: 4min
completed: 2026-06-15
---

# Phase 8 Plan 02: Cloudinary Purge on Product Delete Summary

**ProductController::destroy() now collects a product's cover + all gallery image paths before the cascading DB delete, dedupes them, then purges each from Cloudinary best-effort — failures are logged and never block the delete (OPS-06 / D-11).**

## Performance

- **Duration:** ~4 min
- **Started:** 2026-06-15T19:18:00Z
- **Completed:** 2026-06-15T19:22:25Z
- **Tasks:** 1
- **Files modified:** 1

## Accomplishments
- Closed the v1.0 orphaned-image debt: deleting a product now removes its Cloudinary cover and every gallery asset.
- Paths are collected BEFORE `Product::delete()` so the `ON DELETE CASCADE` on `product_images` does not erase them first.
- Deduped cover+gallery paths (cover is usually also a gallery row) and skipped empty/legacy paths.
- Mirrored the existing `deleteImage()` try/catch over `\Throwable` so a Cloudinary outage logs and continues — the DB delete is never blocked.

## Task Commits

Each task was committed atomically:

1. **Task 1: Rewrite ProductController::destroy() to purge Cloudinary objects best-effort** - `33d6c1c` (fix)

**Plan metadata:** (this docs commit)

## Files Created/Modified
- `src/Controllers/ProductController.php` - Rewrote `destroy()`: collects cover (`image_path`) + gallery (`ProductImage::allForProduct()`) paths before delete, dedupes with `array_values(array_unique(array_filter(...)))`, runs the existing `Product::delete()`, then loops a best-effort `CloudinaryStorage::delete()` per path inside a `try/catch (\Throwable)` that `error_log()`s on failure.

## Decisions Made
- **Path collection before delete:** the FK `ON DELETE CASCADE` from `product_images` → `products` wipes gallery rows, so paths must be read first (RESEARCH Pitfall + Anti-Pattern).
- **Dedupe:** cover and the first gallery photo share the same Cloudinary asset (`uploadImages()` auto-sets first photo as cover), so `array_unique` avoids a redundant `destroy` call.
- **Best-effort, never block:** Cloudinary failures are logged only; the product rows are always removed (D-11). No re-throw, no `exit`.
- **No new imports / kept existing flash:** `ProductImage` and `CloudinaryStorage` were already imported; the success flash text was left unchanged per the plan's `<action>`.

## Deviations from Plan

None - plan executed exactly as written.

## Issues Encountered
None. `php -l` reported no syntax errors; the full PHPUnit suite stayed green (27 tests, 30 assertions) confirming `ProfitCalculator` and other untouched logic are unaffected.

## Verification
- `php -l src/Controllers/ProductController.php` → "No syntax errors detected".
- Source assertion: `$this->products->find(` (path collection, line 305) appears BEFORE `$this->products->delete(` (line 315). PASS.
- Source assertion: `grep -c "array_unique"` = 1 (dedupe present). PASS.
- Source assertion: `destroy()` contains `catch (\Throwable $e)` calling `error_log(` (line 325), no re-throw/`exit`. PASS.
- Source assertion: no new `use App\` import lines added (git diff shows no +/- on use lines). PASS.
- `vendor/bin/phpunit` → OK (27 tests, 30 assertions). PASS.
- Live purge confirmation is deferred to operator verification in Plan 06.

## User Setup Required
None - no external service configuration required (reuses existing `CLOUDINARY_*` vars set in Phase 4).

## Next Phase Readiness
- OPS-06 closed. Wave 1 of Phase 8 (independent of the supplier work) is done.
- No blockers. The supplier work (SUP-01/SUP-02) in other plans is unaffected by this change.

## Self-Check: PASSED

- FOUND: `src/Controllers/ProductController.php` (modified, committed)
- FOUND: `.planning/phases/08-suppliers-and-product-cleanup/08-02-SUMMARY.md`
- FOUND: commit `33d6c1c`

---
*Phase: 08-suppliers-and-product-cleanup*
*Completed: 2026-06-15*
