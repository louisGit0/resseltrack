# Phase 8: Suppliers and Product Cleanup - Context

**Gathered:** 2026-06-15
**Status:** Ready for planning

<domain>
## Phase Boundary

This phase delivers three things on top of the live v1.0 deployment:

1. **SUP-01** — A "Fournisseurs" tab with full per-user CRUD (name, URL, rating 1-5, comment).
2. **SUP-02** — Orders can *optionally* reference a saved supplier. The current free-text `supplier` header field becomes a dropdown of the user's suppliers **plus** a free-text fallback. Fully backward compatible: existing orders that hold a free-text supplier name keep displaying correctly.
3. **OPS-06** — Deleting a product purges its Cloudinary objects (cover + every gallery image), best-effort with logging, mirroring what `deleteImage()` already does for a single photo.

**Out of scope:** product ratings (Phase 9), URL auto-fill (Phase 10), any change to fee allocation / CUMP / stock logic.

</domain>

<decisions>
## Implementation Decisions

### Supplier ↔ order link (SUP-02)
- **D-01:** The order form's supplier field becomes a **dropdown of the user's saved suppliers + an "Autre" option** that reveals the existing free-text input. Picking a supplier stores `orders.supplier_id`; choosing "Autre" (or leaving the dropdown empty) falls back to the free-text `orders.supplier` column.
- **D-02:** **Always keep the free-text name too.** The existing `supplier` VARCHAR column stays. When a supplier is selected from the dropdown, persist both `supplier_id` AND the supplier's name into the existing `supplier` text column — so order views render unchanged and there is zero data migration for old orders.
- **D-03:** `supplier_id` is a **nullable FK** on `orders` (`ON DELETE SET NULL`). No `NOT NULL`, no required selection — saving an order with no supplier is accepted.
- **D-04:** The free-text fallback path through `OrderController::persistLines()` (which copies `header['supplier']` onto each purchase line) is preserved as-is — purchase lines keep the text name.

### Supplier fields & rating (SUP-01)
- **D-05:** Fields: `name` (required), `url` (optional, validated like other URLs — accept empty), `rating` (optional 1-5), `comment` (optional TEXT).
- **D-06:** Rating is **optional** and entered via **clickable stars** (1-5). Unrated suppliers display a neutral placeholder.
- **D-07:** The suppliers list shows the **count of orders linked** to each supplier (derived via a COUNT join on `orders.supplier_id`).

### Supplier deletion when linked (SUP-01 / SUP-02)
- **D-08:** Deleting a supplier that is referenced by orders **unlinks** them (`ON DELETE SET NULL` on `orders.supplier_id`). The orders keep their free-text `supplier` name (per D-02) so they still display the supplier label after the supplier record is gone. No blocking, no cascade-delete of orders.

### Suppliers list UI (SUP-01)
- **D-09:** **Full table**, consistent with the Products list: columns = name, clickable URL, rating (stars), comment, linked-orders count, edit/delete actions. (Default sort by name; sorting by rating is a nice-to-have, not required.)
- **D-10:** Nav entry "Fournisseurs" goes in the existing **"Activité"** sidebar section of `layout.php`, alongside Produits / Commandes / Achats / Ventes (rendered in both the desktop sidebar and the mobile offcanvas via the shared `$navHtml` closure). Suggested icon: `bi-truck` or `bi-shop`.

### Product delete Cloudinary purge (OPS-06)
- **D-11:** `ProductController::destroy()` collects the product's cover + all gallery image paths **before** deleting DB rows, then calls `(new CloudinaryStorage())->delete($path)` for each in a best-effort try/catch (same pattern as `deleteImage()`). A Cloudinary failure is logged via `error_log()` and **never blocks** the DB delete — the product rows are always removed.

### Claude's Discretion
- Exact star-rating widget implementation (CSS/JS in `app.js` + `style.css`), table column order/styling, validation copy wording, and whether sorting-by-rating is added — left to planning/execution within the decisions above.
- Whether the supplier dropdown is rendered with a `<select>` + JS toggle or a datalist — planner decides; must satisfy D-01 (dropdown + free-text fallback).

</decisions>

<canonical_refs>
## Canonical References

**Downstream agents MUST read these before planning or implementing.**

### Phase scope & requirements
- `.planning/ROADMAP.md` §"Phase 8: Suppliers and Product Cleanup" — goal, success criteria, new/modified file list, the `bin/migrate.php` operator step
- `.planning/REQUIREMENTS.md` — SUP-01, SUP-02, OPS-06 (and the v2.0 section)

### Existing code to mirror / modify
- `src/Controllers/OrderController.php` — `parseInput()` (reads `supplier`), `persistLines()` (copies supplier onto each purchase line), `create()`/`edit()` (pass form data) — the integration point for SUP-02
- `src/Views/orders/form.php` §"Commande" card (the current free-text `supplier` input at line ~70) — becomes the dropdown + "Autre"
- `src/Controllers/ProductController.php` — `destroy()` (OPS-06 target) and `deleteImage()` (the best-effort purge pattern to copy)
- `src/Services/CloudinaryStorage.php` — `delete()` signed-curl helper used by OPS-06
- `src/Views/layout.php` §`$navHtml` closure — where the "Fournisseurs" nav entry is added (both sidebar + offcanvas)
- `src/Core/Schema.php` + `sql/schema.sql` — idempotent DDL home for the new `suppliers` table and the `orders.supplier_id` column
- `src/Models/Order.php`, `src/Models/Product.php` — model patterns (per-user `:uid` scoping, prepared statements) to mirror for the new `Supplier` model
- `.planning/codebase/CONVENTIONS.md` — naming, `final` classes, `declare(strict_types=1)`, model SQL ownership, route-ordering rule

### New files to create (from ROADMAP.md)
- `src/Models/Supplier.php`, `src/Controllers/SupplierController.php`, `src/Views/suppliers/index.php`, `src/Views/suppliers/form.php`

No external specs/ADRs — requirements fully captured in the decisions above.

</canonical_refs>

<code_context>
## Existing Code Insights

### Reusable Assets
- **`CloudinaryStorage::delete()`** — already used by `ProductController::deleteImage()` for single-photo best-effort purge; OPS-06 reuses it in a loop over cover + gallery.
- **`$navHtml` closure in `layout.php`** — single source rendered twice (sidebar + offcanvas); adding the "Fournisseurs" link there covers both automatically.
- **Generic confirm modal** (`#confirm-modal` in `layout.php`, forms with `data-confirm`) — reuse for supplier delete confirmation.
- **Datalist + `array_column(...->allForUser(), 'name')` pattern** in `OrderController::create()` — analogous source for populating the supplier dropdown.
- **Per-user model pattern** (`WHERE user_id = :uid`, prepared statements, `find($id, $userId)` ownership check returning null) — the `Supplier` model mirrors `Order`/`Product`.

### Established Patterns
- Schema changes live in `sql/schema.sql` + `Schema::ensure()` (idempotent: `CREATE TABLE IF NOT EXISTS`, `SHOW COLUMNS` guards), applied by re-running `php bin/migrate.php` (operator step, local → Aiven).
- Route registration in `public/index.php`: static segments BEFORE parameterized ones; suppliers CRUD routes follow the products/orders route shape.
- Controllers `final`, extend `Controller`, call `Auth::require()` in constructor, one public method per route action; CSRF via `Csrf::validate()` on every POST.
- Views escape with `e()`; forms embed `\App\Core\Csrf::field()`.

### Integration Points
- **`orders.supplier_id`** new nullable FK → `suppliers.id`, `ON DELETE SET NULL`. `OrderController::parseInput()` reads an optional `supplier_id` from POST; when set, also resolves the supplier name into the existing `supplier` text column (D-02).
- **`OrderController::create()`/`edit()`** pass the user's supplier list to `orders/form.php` for the dropdown.
- **`ProductController::destroy()`** gathers image paths before row deletion, purges Cloudinary best-effort.
- **Nav** in `layout.php` and **routes** in `public/index.php`.

</code_context>

<specifics>
## Specific Ideas

- Backward compatibility is the hard constraint: existing orders (free-text supplier, no `supplier_id`) MUST render unchanged with zero migration of historical data. The "keep the text name even when a supplier is picked" rule (D-02) is what guarantees this.
- Star rating should match the app's existing visual language (Bootstrap 5.3 + Bootstrap Icons `bi-star`/`bi-star-fill`), consistent with the upcoming product rating in Phase 9 — keep the widget reusable.

</specifics>

<deferred>
## Deferred Ideas

- **Sorting / filtering the suppliers list by rating** — nice-to-have; not required for Phase 8. Can be added later if wanted.
- **Aggregate supplier stats** (total spend per supplier, average margin) — a reporting feature, its own future phase.
- Product rating (Phase 9) and URL auto-fill (Phase 10) — already on the roadmap, out of this phase.

None of these block Phase 8.

</deferred>

---

*Phase: 8-suppliers-and-product-cleanup*
*Context gathered: 2026-06-15*
