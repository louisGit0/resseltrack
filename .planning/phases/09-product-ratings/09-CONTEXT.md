# Phase 9: Product Ratings - Context

**Gathered:** 2026-06-15
**Status:** Ready for planning

<domain>
## Phase Boundary

Add a per-product **rating (1-5) + free-text comment**, editable, shown on the product list and detail page. This is **RATE-01**.

Schema: two new nullable columns on `products` (`rating TINYINT UNSIGNED NULL`, `rating_note TEXT NULL`), applied idempotently via `Schema::ensure()` + `sql/schema.sql`, then `php bin/migrate.php` re-run against Aiven (operator step — same as Phase 8).

The phase reuses the **`initStarRating()` widget already shipped in Phase 8** (08-04, `public/assets/js/app.js`) — no new widget engine.

**Out of scope:** supplier ratings (done in Phase 8), URL auto-fill (Phase 10), any change to CUMP/stock/margin logic.

</domain>

<decisions>
## Implementation Decisions

### Rating availability (RATE-01 "after receipt")
- **D-01:** Rating is **always editable on any product** — no gating on stock/purchase existence. The product has no formal "received" state; keep it frictionless. ("After receipt" is satisfied in practice — the user rates whenever they want, typically after receiving.)

### Where the rating is entered
- **D-02:** Two entry points:
  1. **Product edit form** (`products/form.php`) — a "Note" section with the clickable star widget (`initStarRating`) + a "Commentaire" textarea, saved alongside all other product fields via the existing `ProductController::update()` / `store()`.
  2. **Quick inline rate on the detail page** — the stars in the product detail header are interactive: clicking a star submits the rating immediately via a dedicated lightweight action, without opening the full edit form.
- **D-03:** The quick-rate posts to a **new route `POST /products/{id}/rate`** → `ProductController::rate()`, which validates ownership (`Product::find($id, Auth::id())`) + CSRF, updates only `rating` (and optionally clears it), and redirects back to the detail page (or returns JSON). Registered alongside the other `/products/{id}/...` sub-routes (e.g. before the bare parameterized routes, same as `/edit`, `/delete`, `/images`).

### List display
- **D-04:** **Badge accolé au nom** — small stars/badge rendered next to the product name in `products/index.php`; **no dedicated column**. Unrated products show a neutral placeholder (or nothing). The **comment is NOT shown in the list** (detail only).

### Detail page display
- **D-05:** **Stars in the detail header, next to the product name** (these are the interactive quick-rate stars per D-02/D-03); the **full comment is shown just below**. Unrated product shows a discreet "noter" affordance (the empty stars are clickable; editing the comment goes through "Modifier").

### Validation & data
- **D-06:** `rating` is **optional**, an integer **1-5 or NULL** (NULL = unrated), validated server-side exactly like the supplier rating in Phase 8 (`SupplierController::validate()` pattern). `rating_note` is optional TEXT, escaped with `e()` on output. Both are additive and backward-compatible: existing products keep `rating = NULL`, no data migration.

### Claude's Discretion
- Exact star-rating markup reuse vs. small adaptation of `initStarRating` for the inline auto-submit case (the form case is a drop-in reuse; the inline quick-rate may need a tiny "submit on click" wrapper) — planner decides, must reuse the existing widget, not reimplement it.
- Badge styling in the list (size/color), placeholder rendering for unrated, and whether the quick-rate action returns a redirect vs. JSON — within D-03/D-04/D-05.

</decisions>

<canonical_refs>
## Canonical References

**Downstream agents MUST read these before planning or implementing.**

### Phase scope & requirements
- `.planning/ROADMAP.md` §"Phase 9: Product Ratings" — goal, success criteria, new/modified file list, the `bin/migrate.php` operator step
- `.planning/REQUIREMENTS.md` — RATE-01 (v2.0 section)

### Reuse from Phase 8 (the rating pattern already shipped)
- `public/assets/js/app.js` — `initStarRating()` (clickable stars + hidden input + clear-to-unrated; built in 08-04) — REUSE for the form; adapt minimally for inline quick-rate
- `src/Controllers/SupplierController.php` — `validate()` rating 1-5-or-null pattern to mirror for products
- `src/Views/suppliers/form.php` — the star-input form markup to mirror in `products/form.php`
- `src/Views/suppliers/index.php` — the read-only star display to mirror for the list badge
- `public/assets/css/style.css` — `.star-btn` / `.supplier-stars` rules (reuse/generalize)
- `src/Core/Schema.php` + `sql/schema.sql` — idempotent additive-column pattern; **NOTE the Phase 8 deviation: never put a `;` inside a schema.sql comment — `bin/migrate.php` splits on `;`** (see `.planning/phases/08-suppliers-and-product-cleanup/08-06-SUMMARY.md`)

### Files to modify
- `src/Models/Product.php` — include `rating` + `rating_note` in the `create`/`update` column lists and `find`/`allForUser` reads
- `src/Controllers/ProductController.php` — `validate()` adds rating fields; `store()`/`update()` save them; new `rate()` action for quick inline rate
- `src/Views/products/form.php` — rating section (stars + comment textarea), inserted near the existing sections
- `src/Views/products/index.php` — rating badge next to the product name
- `src/Views/products/show.php` — header stars (interactive quick-rate) + comment below
- `public/index.php` — register `POST /products/{id}/rate` (alongside the other `/products/{id}/...` routes; static/specific segments before the bare parameterized route)

No external specs/ADRs — requirements fully captured in the decisions above.

</canonical_refs>

<code_context>
## Existing Code Insights

### Reusable Assets
- **`initStarRating()`** (app.js, from 08-04) — the exact clickable 1-5 star widget with clear-to-unrated; built to be reused by Phase 9. The product edit-form rating reuses it verbatim (hidden `name="rating"` input).
- **Supplier rating pattern** (Model/Controller/View/CSS) — Phase 9 is the product-side mirror of what Phase 8 did for suppliers. Validation, star markup, and display are all already proven.
- **`ProductController::validate()`** — already centralizes product field validation; rating validation slots in here.
- **Idempotent additive migration** — `Schema::ensure()` `SHOW COLUMNS`-guarded `ALTER TABLE products ADD COLUMN ...` (mirror the `orders.supplier_id` guard from Phase 8 / `fk_purchases_order`).

### Established Patterns
- Models own `:uid`-scoped SQL (raw PDO prepared statements); `final` classes; `declare(strict_types=1)`; `Csrf::validate()` on every POST; `e()` escaping; route ordering (specific before bare parameterized).
- Schema changes: `sql/schema.sql` (fresh installs) + `Schema::ensure()` (live), applied via `php bin/migrate.php` (operator, local → Aiven). DDL never runs per-request.

### Integration Points
- **`products.rating` / `products.rating_note`** new nullable columns (additive, back-compat).
- **`POST /products/{id}/rate`** new route → `ProductController::rate()` (ownership + CSRF + rating-only update).
- **Product list/detail views** — badge near name (list) + interactive stars near title with comment below (detail).
- **Operator step:** re-run `php bin/migrate.php` against Aiven after deploy (additive → run before deploy for zero-downtime, like Phase 8).

</code_context>

<specifics>
## Specific Ideas

- Keep the rating widget consistent with the supplier rating shipped in Phase 8 (same stars, same clear-to-unrated UX) so the app feels coherent.
- Additive + backward-compatible is the hard constraint: existing products render unchanged (rating NULL → neutral placeholder), zero data migration.
- The inline quick-rate on the detail page is the headline UX win — rate a product in one click after receiving it, without opening the edit form.

</specifics>

<deferred>
## Deferred Ideas

- Filtering/sorting the product list by rating — nice-to-have, not in RATE-01.
- Aggregate rating analytics (avg rating per category, etc.) — future reporting phase.
- URL auto-fill (Phase 10) — already on the roadmap, out of this phase.

None block Phase 9.

</deferred>

---

*Phase: 9-product-ratings*
*Context gathered: 2026-06-15*
