# Phase 8: Suppliers and Product Cleanup - Research

**Researched:** 2026-06-15
**Domain:** PHP 8.3 MVC maison (no framework) — per-user CRUD, nullable FK + idempotent migration, best-effort Cloudinary purge
**Confidence:** HIGH (every claim verified by reading the live source files; no external packages, no library-version lookups required)

## Summary

Phase 8 is a **brownfield feature addition** to a working, deployed app. All three requirements are implementable by **mirroring patterns that already exist in the codebase** — there is nothing novel to research and no new dependency to add. SUP-01 (suppliers CRUD) clones the `Product`/`Order` model + `ProductController` controller + `products/index.php`/`form.php` view triad. SUP-02 (order ↔ supplier link) extends the existing `OrderController::parseInput()`/`persistLines()` flow and the `orders/form.php` "Commande" card, reusing the exact `#o-currency` → `.d-none` JS toggle already in `app.js`. OPS-06 (Cloudinary purge on product delete) replicates the best-effort try/catch loop already proven in `ProductController::deleteImage()`.

The single non-trivial technical point is the **idempotent FK migration**: `orders.supplier_id` must be added via the `SHOW COLUMNS` guard pattern in `Schema::ensure()` (mirroring the existing `fk_purchases_order` block at `Schema.php:82-95`), because `CREATE TABLE IF NOT EXISTS orders` in `schema.sql` will not alter the already-existing `orders` table. The schema additions are **purely additive and backward-compatible with the live v1.0 code**, which lets the operator run `bin/migrate.php` against Aiven *before* deploying the new code (zero-downtime ordering).

**Primary recommendation:** Build by cloning existing patterns verbatim. Add the `suppliers` table to both `sql/schema.sql` AND `Schema::ensure()`; add `orders.supplier_id` only via the `SHOW COLUMNS`-guarded ALTER in `Schema::ensure()`; persist both `supplier_id` AND the resolved supplier name into the existing `supplier` text column (D-02); collect cover + gallery paths **before** the cascading product delete, then purge Cloudinary best-effort.

<user_constraints>
## User Constraints (from CONTEXT.md)

### Locked Decisions
- **D-01:** The order form's supplier field becomes a **dropdown of the user's saved suppliers + an "Autre" option** that reveals the existing free-text input. Picking a supplier stores `orders.supplier_id`; choosing "Autre" (or leaving the dropdown empty) falls back to the free-text `orders.supplier` column.
- **D-02:** **Always keep the free-text name too.** The existing `supplier` VARCHAR column stays. When a supplier is selected from the dropdown, persist both `supplier_id` AND the supplier's name into the existing `supplier` text column — so order views render unchanged and there is zero data migration for old orders.
- **D-03:** `supplier_id` is a **nullable FK** on `orders` (`ON DELETE SET NULL`). No `NOT NULL`, no required selection — saving an order with no supplier is accepted.
- **D-04:** The free-text fallback path through `OrderController::persistLines()` (which copies `header['supplier']` onto each purchase line) is preserved as-is — purchase lines keep the text name.
- **D-05:** Fields: `name` (required), `url` (optional, validated like other URLs — accept empty), `rating` (optional 1-5), `comment` (optional TEXT).
- **D-06:** Rating is **optional** and entered via **clickable stars** (1-5). Unrated suppliers display a neutral placeholder.
- **D-07:** The suppliers list shows the **count of orders linked** to each supplier (derived via a COUNT join on `orders.supplier_id`).
- **D-08:** Deleting a supplier that is referenced by orders **unlinks** them (`ON DELETE SET NULL`). The orders keep their free-text `supplier` name (per D-02) so they still display the supplier label after the supplier record is gone. No blocking, no cascade-delete of orders.
- **D-09:** **Full table**, consistent with the Products list: columns = name, clickable URL, rating (stars), comment, linked-orders count, edit/delete actions. Default sort by name; sorting by rating is a nice-to-have, not required.
- **D-10:** Nav entry "Fournisseurs" goes in the existing **"Activité"** sidebar section of `layout.php`, alongside Produits / Commandes / Achats / Ventes (rendered in both desktop sidebar and mobile offcanvas via the shared `$navHtml` closure). Suggested icon: `bi-truck` or `bi-shop`.
- **D-11:** `ProductController::destroy()` collects the product's cover + all gallery image paths **before** deleting DB rows, then calls `(new CloudinaryStorage())->delete($path)` for each in a best-effort try/catch. A Cloudinary failure is logged via `error_log()` and **never blocks** the DB delete — the product rows are always removed.

### Claude's Discretion
- Exact star-rating widget implementation (CSS/JS in `app.js` + `style.css`), table column order/styling, validation copy wording, and whether sorting-by-rating is added.
- Whether the supplier dropdown is rendered with a `<select>` + JS toggle or a datalist — must satisfy D-01 (dropdown + free-text fallback).

### Deferred Ideas (OUT OF SCOPE)
- Sorting / filtering the suppliers list by rating.
- Aggregate supplier stats (total spend per supplier, average margin).
- Product rating (Phase 9) and URL auto-fill (Phase 10).
- Any change to fee allocation / CUMP / stock logic.
</user_constraints>

<phase_requirements>
## Phase Requirements

| ID | Description | Research Support |
|----|-------------|------------------|
| SUP-01 | Onglet **Fournisseurs** avec CRUD complet (nom, URL, note 1-5, commentaire), scopé par utilisateur | New `Supplier` model (mirrors `Product`/`Order`), `SupplierController` (mirrors `ProductController`), `suppliers/index.php` + `form.php` views, nav entry, routes — all detailed below |
| SUP-02 | Les commandes peuvent référencer **optionnellement** un fournisseur, rétrocompatible | `orders.supplier_id` nullable FK; `OrderController::parseInput()`/`create()`/`edit()` extension; `orders/form.php` `<select>` + "Autre" toggle; D-02 dual-write to `supplier` text column |
| OPS-06 | La suppression d'un produit **purge ses objets Cloudinary** (cover + galerie), best-effort | `ProductController::destroy()` collects `products.image_path` + `product_images.path[]` before the cascading delete, then loops `CloudinaryStorage::delete()` in try/catch (pattern from `deleteImage()`) |
</phase_requirements>

## Project Constraints (from CLAUDE.md)

These are mandatory directives extracted from the project's `CLAUDE.md` and the codebase conventions. The planner must verify compliance for every task.

- **PHP & SQL stack frozen:** PHP 8.3, MySQL 8 dialect. No framework, no ORM, no query builder, no migration framework. Raw PDO prepared statements only. `[VERIFIED: CLAUDE.md "Constraints", ARCHITECTURE]`
- **`declare(strict_types=1);` on every PHP file** — no exceptions found in the codebase. `[VERIFIED: every src/ file read]`
- **All classes `final`** except the abstract base `Controller`. `[VERIFIED: CLAUDE.md "Naming Patterns"]`
- **Models own ALL SQL; controllers never write SQL.** Every query is scoped `WHERE user_id = :uid` with `find($id, $userId)` returning `null` when not owned. `PDO::ATTR_EMULATE_PREPARES => false`. `[VERIFIED: Order.php, Product.php, ProductImage.php]`
- **CSRF on every POST:** `Csrf::validate()` first line of every state-changing action; `\App\Core\Csrf::field()` in every form. `[VERIFIED: ProductController, OrderController]`
- **Auth gate:** controller constructor calls `Auth::require()`. `[VERIFIED: ProductController:22, OrderController:33]`
- **Output escaping:** views escape all dynamic output with `e()`. `[VERIFIED: all views read]`
- **Route ordering:** static segments registered BEFORE parameterized ones (`public/index.php:158` comment). `[VERIFIED: index.php:158,170]`
- **No persistent disk writes** (serverless): uploads/sessions via external services only. `[VERIFIED: CLAUDE.md "Constraints"]`
- **No secrets committed; `.env` gitignored.** `[VERIFIED: CLAUDE.md "Configuration Management"]`
- **GSD workflow enforcement:** edits must go through a GSD command. `[VERIFIED: CLAUDE.md "GSD Workflow Enforcement"]`
- **File size:** functions < 50 lines, files < 800 lines (global rules). The new `SupplierController` and `Supplier` model will be small; OK.

## Architectural Responsibility Map

| Capability | Primary Tier | Secondary Tier | Rationale |
|------------|-------------|----------------|-----------|
| Supplier CRUD persistence | Model (`Supplier`) | Database (`suppliers` table) | All SQL lives in models, scoped to `:uid` |
| Supplier HTTP transport + validation | Controller (`SupplierController`) | — | Validate input, orchestrate model, render views |
| Supplier list table + form rendering | View (`suppliers/index.php`, `form.php`) | — | Presentation only; receives pre-computed rows |
| Order ↔ supplier resolution (dual-write D-02) | Controller (`OrderController::parseInput`) | Model (`Order::create/update`) | Controller resolves `supplier_id` → name; model persists both columns |
| Supplier dropdown + "Autre" toggle | View (`orders/form.php`) | Client JS (`app.js`) | `<select>` change toggles `.d-none` on free-text wrapper (mirrors `#o-currency`) |
| Star rating widget | Client JS (`app.js`) + CSS (`style.css`) | View (`suppliers/form.php`) | Hidden input + clickable `bi-star` icons; reusable for Phase 9 |
| Cloudinary object purge on product delete | Controller (`ProductController::destroy`) | Service (`CloudinaryStorage::delete`) | Controller collects paths + orchestrates best-effort loop |
| Schema additions (`suppliers` table, `orders.supplier_id`) | DDL home (`sql/schema.sql` + `Schema::ensure()`) | Operator (`bin/migrate.php`) | Idempotent; applied out of request path (DB-03) |

## Standard Stack

No new packages. Everything required is already present and verified in the repo.

### Core (already in use)
| Component | Version | Purpose | Source |
|-----------|---------|---------|--------|
| PHP | 8.3 | All server logic | `[VERIFIED: CLAUDE.md, composer.json]` |
| MySQL | 8 (Aiven 8.4 prod) | Persistence over TLS | `[VERIFIED: CLAUDE.md, MEMORY]` |
| PDO (`pdo_mysql`) | bundled | Prepared statements | `[VERIFIED: Database.php usage in models]` |
| Bootstrap | 5.3.3 (CDN) | UI table/form/cards | `[VERIFIED: layout.php:65]` |
| Bootstrap Icons | 1.11.3 (CDN) | `bi-star`, `bi-truck`, etc. | `[VERIFIED: layout.php:66]` |
| Vanilla JS (ES2020) | — | Dropdown toggle, star widget | `[VERIFIED: app.js, no build step]` |
| CloudinaryStorage (in-repo service) | — | Signed-curl upload/delete | `[VERIFIED: src/Services/CloudinaryStorage.php]` |

### Alternatives Considered
| Instead of | Could Use | Tradeoff |
|------------|-----------|----------|
| `<select name="supplier_id">` + JS toggle | `<datalist>` | A datalist cannot return a supplier **id** distinct from free text — and D-01/D-02 require capturing `supplier_id`. **Use `<select>`** (also matches the existing `#o-currency` toggle pattern). |
| Star widget via hidden input + JS | 5 radio inputs | Radios work without JS but are clunky for "clear to unrated". Either is acceptable (Claude's discretion D); recommend hidden input + clickable `bi-star` for reuse in Phase 9. |

**Installation:** None. No `composer require`, no npm. `[VERIFIED: no build step — CLAUDE.md "No Node/npm"]`

## Package Legitimacy Audit

**Not applicable — this phase installs zero external packages.** SUP-01/SUP-02/OPS-06 are implemented entirely with existing in-repo code and already-loaded CDN assets (Bootstrap 5.3.3, Bootstrap Icons 1.11.3). No `composer require`, no npm install, no registry lookup. The slopcheck / registry-verification gate is moot. `[VERIFIED: composer.json has only ext-pdo + phpunit dev-dep; no build step]`

## Architecture Patterns

### System Architecture Diagram (Phase 8 data flow)

```
SUP-01 (Suppliers CRUD)
  Browser ──GET /suppliers──▶ Router ──▶ SupplierController::index
                                              │
                                              ▼
                                    Supplier::allForUser(uid)  ──SQL──▶ suppliers
                                    (LEFT JOIN orders COUNT)            LEFT JOIN orders
                                              │
                                              ▼
                                    view('suppliers/index') ──▶ HTML table (name, URL, stars, comment, orders_count, actions)

  Browser ──POST /suppliers──▶ Csrf::validate ──▶ validate() ──▶ Supplier::create(uid, data) ──▶ redirect /suppliers

SUP-02 (Order ↔ Supplier link)
  Browser ──GET /orders/create──▶ OrderController::create ──▶ Supplier::allForUser(uid) ──▶ view('orders/form')
                                                                                                │
                                                          <select supplier_id> + free-text "Autre" (JS toggle)
                                                                                                │
  Browser ──POST /orders──▶ parseInput():                                                       ▼
       supplier_id>0 & owned ?  ──yes──▶ supplier(text) = resolved name   (D-02 dual write)
                              ──no──▶  supplier(text) = free-text "Autre"
                              ▼
       header[supplier_id], header[supplier] ──▶ Order::create() ──▶ orders(supplier_id, supplier)
                              └──▶ persistLines() copies header[supplier] onto each purchase line (D-04, unchanged)

OPS-06 (Cloudinary purge on product delete)
  Browser ──POST /products/{id}/delete──▶ ProductController::destroy
       1. find(id,uid) ──▶ cover = products.image_path
       2. ProductImage::allForProduct(uid,id) ──▶ gallery paths[]
       3. dedupe(cover + gallery) ──▶ paths[]
       4. Product::delete(id,uid)  ──CASCADE──▶ product_images / purchases / sales rows gone
       5. foreach paths: try { CloudinaryStorage::delete(path) } catch { error_log(); continue }  (best effort, never blocks)
```

### Recommended Project Structure (new + modified)
```
src/
├── Models/
│   └── Supplier.php          # NEW — mirrors Order.php/Product.php
├── Controllers/
│   ├── SupplierController.php # NEW — mirrors ProductController CRUD shape
│   ├── OrderController.php    # MODIFIED — parseInput/create/edit accept supplier_id
│   └── ProductController.php  # MODIFIED — destroy() purges Cloudinary
├── Core/
│   └── Schema.php             # MODIFIED — suppliers table + orders.supplier_id guard
├── Views/
│   ├── suppliers/
│   │   ├── index.php          # NEW — full table (D-09)
│   │   └── form.php           # NEW — create/edit, star widget
│   ├── orders/form.php        # MODIFIED — supplier <select> + "Autre"
│   └── layout.php             # MODIFIED — "Fournisseurs" nav entry
public/
├── index.php                  # MODIFIED — register /suppliers routes
└── assets/
    ├── js/app.js              # MODIFIED — supplier toggle + star widget
    └── css/style.css          # MODIFIED (maybe) — star widget styling
sql/
└── schema.sql                 # MODIFIED — CREATE TABLE suppliers (+ doc note)
```

### Pattern 1: Per-user Model (mirror for `Supplier`)
**What:** Thin Active-Record-like class; every method takes `int $userId` first; all SQL scoped `WHERE user_id = :uid`; `find()` returns `null` when not owned.
**When to use:** The new `Supplier` model — copy `Order.php` structure exactly.
**Example (recommended `Supplier` model shape):**
```php
// Source pattern: src/Models/Order.php:22-113 (allForUser aggregate, find, create, update, delete)
final class Supplier
{
    private PDO $db;
    public function __construct() { $this->db = Database::connection(); }

    /** Suppliers with linked-orders count (D-07). @return array<int,array<string,mixed>> */
    public function allForUser(int $userId): array
    {
        $stmt = $this->db->prepare(
            'SELECT s.*, COUNT(o.id) AS orders_count
             FROM suppliers s
             LEFT JOIN orders o ON o.supplier_id = s.id AND o.user_id = s.user_id
             WHERE s.user_id = :uid
             GROUP BY s.id
             ORDER BY s.name ASC'
        );
        $stmt->execute(['uid' => $userId]);
        return $stmt->fetchAll();
    }

    public function find(int $id, int $userId): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM suppliers WHERE id = :id AND user_id = :uid LIMIT 1');
        $stmt->execute(['id' => $id, 'uid' => $userId]);
        return $stmt->fetch() ?: null;
    }

    public function create(int $userId, array $data): int
    {
        $stmt = $this->db->prepare(
            'INSERT INTO suppliers (user_id, name, url, rating, comment)
             VALUES (:uid, :name, :url, :rating, :comment)'
        );
        $stmt->execute([
            'uid'     => $userId,
            'name'    => $data['name'],
            'url'     => $data['url'] ?: null,
            'rating'  => $data['rating'] ?? null,   // null when unrated
            'comment' => $data['comment'] ?: null,
        ]);
        return (int) $this->db->lastInsertId();
    }

    public function update(int $id, int $userId, array $data): void { /* mirror Order::update */ }
    public function delete(int $id, int $userId): void { /* DELETE ... WHERE id AND user_id (orders.supplier_id ON DELETE SET NULL handles unlink — D-08) */ }
}
```
`[VERIFIED: mirrors Order.php:22-113 and Product.php:43-195]`

The `LEFT JOIN ... GROUP BY` count pattern is **already proven** in `Order::allForUser()` (`Order.php:24-37`) which does `LEFT JOIN purchases ... COUNT(pu.id) AS line_count ... GROUP BY o.id`. The supplier count is the identical shape.

### Pattern 2: CRUD Controller (mirror for `SupplierController`)
**What:** `final` controller, `Auth::require()` in constructor, one public method per route action, `Csrf::validate()` on POST, `$this->validate()` private helper returning `?array`, `flashErrors()`/`flash()` + `redirect()`.
**Example:** clone the shape of `ProductController::index/create/store/edit/update/destroy` (`ProductController.php:28-301`) and its `validate()` (`:411-447`). The supplier `validate()` validates `name` (required), `url` (optional), `rating` (optional, must be 1-5 or null), `comment` (optional).
```php
// rating validation (D-05/D-06): optional, 1..5
$rawRating = trim((string) ($_POST['rating'] ?? ''));
$rating = $rawRating === '' ? null : (int) $rawRating;
if ($rating !== null && ($rating < 1 || $rating > 5)) {
    $errors['rating'] = 'La note doit être comprise entre 1 et 5.';
}
```
`[VERIFIED: mirrors ProductController.php:411-447 validation style]`

### Pattern 3: Idempotent additive migration (the one non-trivial bit)
**What:** New table → `CREATE TABLE IF NOT EXISTS` (idempotent, safe to re-run). New column on an EXISTING table → `SHOW COLUMNS ... LIKE` guard, and add the column **and its FK constraint together inside the guard** so neither is re-applied on re-run.
**Why a guard is mandatory for the column:** `schema.sql` already contains `CREATE TABLE IF NOT EXISTS orders (...)`. On a live database the `orders` table already exists, so `IF NOT EXISTS` makes the whole statement a **no-op** — it will NOT add a new `supplier_id` column. The column can only be added by an `ALTER TABLE`, which is not idempotent on its own (re-running throws "Duplicate column"). The `SHOW COLUMNS` guard is the established idempotency mechanism.
**Example (recommended `Schema::ensure()` additions):**
```php
// Source pattern: src/Core/Schema.php:82-95 (fk_purchases_order — column+FK added together under a SHOW COLUMNS guard)

// 1) New table — idempotent on its own. Place BEFORE the orders.supplier_id block
//    so the FK target exists even if Schema::ensure() runs standalone.
$db->exec(
    "CREATE TABLE IF NOT EXISTS suppliers (
        id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
        user_id    INT UNSIGNED NOT NULL,
        name       VARCHAR(150) NOT NULL,
        url        VARCHAR(500) NULL,
        rating     TINYINT UNSIGNED NULL,   -- 1..5, NULL = unrated
        comment    TEXT NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY idx_suppliers_user (user_id),
        CONSTRAINT fk_suppliers_user FOREIGN KEY (user_id)
            REFERENCES users (id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
);

// 2) New nullable FK column on the existing orders table — GUARDED.
$hasSupplierId = $db->query("SHOW COLUMNS FROM orders LIKE 'supplier_id'")->fetch();
if (!$hasSupplierId) {
    $db->exec('ALTER TABLE orders ADD COLUMN supplier_id INT UNSIGNED NULL AFTER user_id');
    $db->exec(
        'ALTER TABLE orders
            ADD KEY idx_orders_supplier (supplier_id),
            ADD CONSTRAINT fk_orders_supplier FOREIGN KEY (supplier_id)
                REFERENCES suppliers (id) ON DELETE SET NULL'  // D-03 / D-08
    );
}
```
`[VERIFIED: exact mirror of Schema.php:82-95 fk_purchases_order guard]`

**`schema.sql` companion change:** also add a `CREATE TABLE IF NOT EXISTS suppliers (...)` block to `sql/schema.sql` (matching the in-file style of `orders`/`product_images`) so a fresh volume gets the table. `bin/migrate.php` runs `schema.sql` first then `Schema::ensure()`, so the suppliers table will exist before the `orders.supplier_id` FK is added. Do **not** try to add `supplier_id` to the `orders` block in `schema.sql` — it would not apply on existing DBs and would diverge from the live table; the guarded `Schema::ensure()` ALTER is the single source for it. `[VERIFIED: bin/migrate.php:54-85 runs schema.sql statements then Schema::ensure()]`

### Pattern 4: Dropdown + "Autre" free-text toggle (SUP-02 UI)
**What:** A `<select name="supplier_id">` listing the user's suppliers + an empty/"Autre" option; a free-text `<input name="supplier">` wrapper that is shown only when "Autre"/empty is selected. JS mirrors the existing currency toggle.
**Reuse the existing JS pattern verbatim:** `app.js:279-281` does
```js
function syncCurrency() { const isForeign = currency.value !== 'EUR'; rateWrap.classList.toggle('d-none', !isForeign); ... }
currency.addEventListener('change', syncCurrency); syncCurrency();
```
The supplier toggle is the same shape: `select.addEventListener('change', sync)` where `sync()` does `freeTextWrap.classList.toggle('d-none', select.value !== '')` (show free text only when nothing/"Autre" is picked). On page load, call `sync()` once so edit-mode legacy orders (supplier_id null, free-text present) render correctly. `[VERIFIED: app.js:264-281,367]`

### Pattern 5: Generic confirm modal (reuse, no new code)
Supplier delete buttons just need `<form method="post" action="/suppliers/{id}/delete" data-confirm="Supprimer « ... » ?">`. The global handler at `app.js:522-538` intercepts any `form[data-confirm]` submit and shows `#confirm-modal` (in `layout.php:124-139`). No new JS. `[VERIFIED: app.js:522-538, layout.php:124-139, used by products/index.php:211-215]`

### Anti-Patterns to Avoid
- **Adding `supplier_id` to `orders` via `schema.sql`'s `CREATE TABLE` block.** It is a no-op on the live DB (table already exists) — the column would silently never be created in production. Use the guarded `Schema::ensure()` ALTER. `[VERIFIED: schema.sql:68 uses CREATE TABLE IF NOT EXISTS]`
- **Storing only `supplier_id` and dropping the free-text name.** Violates D-02 and breaks display of legacy orders. Always dual-write the resolved name into `supplier`.
- **Blocking the product delete on a Cloudinary failure.** Violates D-11/OPS-06. The DB delete must always succeed; purge is best-effort.
- **Purging Cloudinary after the DB delete without collecting paths first.** The `ON DELETE CASCADE` from `product_images`→`products` wipes the gallery rows, so paths must be read **before** `Product::delete()`.
- **Writing SQL in a controller.** All supplier SQL goes in the `Supplier` model.
- **A `GET /suppliers/{id}` show route.** D-09 specifies a full table list with inline edit/delete — no detail page is needed (ROADMAP lists only `index.php` + `form.php`). Don't add a show action.

## Don't Hand-Roll

| Problem | Don't Build | Use Instead | Why |
|---------|-------------|-------------|-----|
| Cloudinary object deletion | Custom signed-REST call | `CloudinaryStorage::delete($url)` | Already handles signing, CDN invalidation, and **safely no-ops on non-Cloudinary/legacy paths** (`derivePublicId()` returns `''` for `/assets/uploads/...`) `[VERIFIED: CloudinaryStorage.php:59-81,135-144]` |
| Delete-confirmation UX | New modal/JS | `data-confirm` attribute + global handler | `app.js:522-538` + `#confirm-modal` already wired `[VERIFIED]` |
| Dual nav rendering (sidebar + offcanvas) | Duplicate markup | Single line in `$navHtml` closure | `layout.php:13-54` renders the closure twice automatically `[VERIFIED: layout.php:72,78]` |
| Linked-orders count | N+1 per supplier | `LEFT JOIN orders ... COUNT ... GROUP BY` | One aggregate query; mirrors `Order::allForUser` `[VERIFIED: Order.php:24-37]` |
| Supplier-unlink-on-delete | Manual `UPDATE orders SET supplier_id=NULL` | FK `ON DELETE SET NULL` | DB enforces D-08 automatically `[VERIFIED: pattern from fk_purchases_order]` |
| Show/hide free-text toggle | New JS framework/logic | Mirror `syncCurrency()` `.d-none` toggle | Established pattern `app.js:279-281` `[VERIFIED]` |

**Key insight:** This phase has **no genuinely new technical problem**. Every sub-task maps 1:1 to an existing, verified pattern in the repo. The plan should be prescriptive "clone file X, change lines Y" rather than exploratory.

## Common Pitfalls

### Pitfall 1: Deploy/migrate ordering window
**What goes wrong:** If the new code is deployed before `bin/migrate.php` runs against Aiven, requests touching `suppliers`/`orders.supplier_id` (e.g. `SupplierController::index`, `Order::create` with the new column) throw SQL errors → 500s.
**Why it happens:** Vercel deploy and the manual Aiven migration are two separate operator steps (DB-02/DB-03: no per-request DDL). `[VERIFIED: bin/migrate.php CLI-gated :11-14; Database.php no longer calls Schema::ensure per DB-03]`
**How to avoid:** Because the schema additions are **purely additive and the live v1.0 code never references them**, run `php bin/migrate.php` against Aiven **first**, then deploy the new code. This gives a zero-downtime window. The plan should make the migration an explicit operator step/checkpoint (ROADMAP already flags Phase 8 "⚠ nécessite re-run bin/migrate.php").
**Warning signs:** `Base table or view not found: suppliers` or `Unknown column 'supplier_id'` in Vercel function logs.

### Pitfall 2: Cover image is usually also a gallery row (double-delete)
**What goes wrong:** `products.image_path` is set by `setCover()` to a path that is typically **also** a `product_images.path` row (`ProductController::uploadImages:344-348` auto-sets the first uploaded gallery photo as cover). Collecting cover + gallery without dedupe sends the same URL to Cloudinary twice.
**Why it happens:** Cover and gallery share the same underlying Cloudinary asset. `[VERIFIED: ProductController.php:342-349, setCoverImage:393-408]`
**How to avoid:** Dedupe the collected paths (`array_unique` on non-empty paths) before the purge loop. Double-deleting is harmless on Cloudinary (idempotent `destroy`), but dedupe is cleaner and halves the requests.
**Warning signs:** Two `destroy` calls for the same `public_id` in logs.

### Pitfall 3: Legacy / non-Cloudinary cover paths
**What goes wrong:** Some products may carry a legacy `/assets/uploads/...` local path (pre-Cloudinary migration, see STATE.md Blockers) or `null` in `image_path`.
**Why it happens:** Historical data from the Docker era. `[VERIFIED: STATE.md:123 "Existing products ... /assets/uploads/... image paths"]`
**How to avoid:** No special handling needed — `CloudinaryStorage::delete()` calls `derivePublicId()` which regex-matches `/image/upload/...`; a non-matching path yields `''` and `delete()` early-returns (no-op). Skip empty/null paths in the loop anyway. `[VERIFIED: CloudinaryStorage.php:59-64,135-144]`

### Pitfall 4: Form field name collision (`supplier` vs `supplier_id`)
**What goes wrong:** The order form will post BOTH `supplier_id` (the `<select>`) and `supplier` (the free-text "Autre" input). If `parseInput()` reads only one, data is lost.
**How to avoid:** In `parseInput()`, read both. Resolve: if `supplier_id` > 0 AND `Supplier::find($id, $userId)` returns a row → set `header['supplier_id'] = id` and `header['supplier'] = resolvedName` (D-02 dual-write, overriding free text). Else → `header['supplier_id'] = null` and `header['supplier'] = trim(free-text)`. **Ownership check is mandatory** (never trust a posted id) — `Supplier::find($id, $userId)` returning `null` means "not owned → treat as Autre". `[VERIFIED: parseInput pattern OrderController.php:195-286; ownership pattern Product::find]`

### Pitfall 5: `Order::create()`/`update()` column list must include `supplier_id`
**What goes wrong:** Adding the DB column is not enough; the model's INSERT/UPDATE column lists are explicit (not `*`), so `supplier_id` will silently never be written.
**How to avoid:** Extend `Order::create()` (`Order.php:86-106`) INSERT column list + values, and `Order::update()` (`Order.php:64-84`) SET clause, to include `supplier_id`. `find()`/`allForUser()` use `SELECT *`/`o.*` so reads pick it up automatically. Bind `null` when no supplier. `[VERIFIED: Order.php:64-106 explicit column lists]`

## Runtime State Inventory

> Phase 8 is an additive feature (new table + nullable column), **not** a rename/refactor/migration of existing data. There is **no string-rename or data-migration of historical records**. This section is included only to confirm that explicitly.

| Category | Items Found | Action Required |
|----------|-------------|------------------|
| Stored data | **None requiring migration.** Existing `orders` rows keep `supplier_id = NULL` and their existing free-text `supplier` value — they display unchanged (D-02). No backfill. | None — verified by D-02 backward-compat design + `Order` schema |
| Live service config | **None.** No external service stores a supplier string. Cloudinary holds image assets only; suppliers are DB-only. | None — verified by reading services (only CloudinaryStorage exists) |
| OS-registered state | **None.** No OS-level registrations involved. | None |
| Secrets/env vars | **None new.** Reuses existing `DB_*` and `CLOUDINARY_*` vars. No new secret. | None — verified by `public/index.php:80-98` env list |
| Build artifacts / installed packages | **None.** No build step; no new package; no compiled artifact. | None — verified by CLAUDE.md "No Node/npm", composer.json |

**Canonical question — after migration, what runtime state still has the old shape?** Nothing. Old orders simply have `supplier_id IS NULL` and continue to render via the preserved `supplier` text column. This is the entire point of D-02.

## Code Examples

### OPS-06: `ProductController::destroy()` purge (recommended)
```php
// Source pattern: best-effort loop from ProductController::deleteImage() (:376-381)
public function destroy(array $params): void
{
    Csrf::validate();
    $userId = Auth::id();
    $pid = (int) $params['id'];

    // 1) Collect every Cloudinary path BEFORE the cascading delete wipes product_images.
    $paths = [];
    $product = $this->products->find($pid, $userId);
    if ($product !== null && !empty($product['image_path'])) {
        $paths[] = (string) $product['image_path'];               // cover
    }
    foreach ((new ProductImage())->allForProduct($userId, $pid) as $img) {
        $paths[] = (string) $img['path'];                          // gallery
    }
    $paths = array_values(array_unique(array_filter($paths)));     // dedupe + drop empties (Pitfall 2)

    // 2) DB delete (cascade removes product_images, purchases, sales rows).
    $this->products->delete($pid, $userId);

    // 3) Best-effort Cloudinary purge — never blocks (D-11 / OPS-06).
    $storage = new CloudinaryStorage();
    foreach ($paths as $path) {
        try {
            $storage->delete($path);                               // no-ops on legacy non-Cloudinary paths (Pitfall 3)
        } catch (\Throwable $e) {
            error_log('Cloudinary delete failed for path ' . $path . ': ' . $e->getMessage());
        }
    }

    $this->flash('success', 'Produit supprimé (avec ses achats, ventes et images liés).');
    $this->redirect('/products');
}
```
`[VERIFIED: deleteImage best-effort pattern ProductController.php:361-390; cascade FKs schema.sql:113-114,168-169,59-60]`

> Note: `ProductController` already imports `App\Models\ProductImage` (`:10`) and `App\Services\CloudinaryStorage` (`:14`) — no new `use` statements needed.

### SUP-02: `OrderController::parseInput()` resolution (recommended addition)
```php
// Add near the top of parseInput(), alongside the existing $supplier read (OrderController.php:197).
$supplierFree = trim((string) ($_POST['supplier'] ?? ''));      // free-text "Autre"
$supplierId   = (int) ($_POST['supplier_id'] ?? 0);
$supplierName = $supplierFree;
$resolvedId   = null;

if ($supplierId > 0) {
    $sup = (new \App\Models\Supplier())->find($supplierId, Auth::id());  // ownership check (Pitfall 4)
    if ($sup !== null) {
        $resolvedId   = (int) $sup['id'];
        $supplierName = (string) $sup['name'];   // D-02: dual-write resolved name into the text column
    }
}
// ... build $header with both keys:
//   'supplier'    => $supplierName,   // existing column, persistLines() copies it onto lines (D-04)
//   'supplier_id' => $resolvedId,     // new column, written by Order::create/update
```
And `OrderController::create()`/`edit()` (`:46-76`) pass the supplier list to the form:
```php
'suppliers' => (new \App\Models\Supplier())->allForUser($userId),
```
(Inject a `private Supplier $suppliers;` in the constructor to match the model-as-property convention `[VERIFIED: OrderController.php:27-37]`.)

### SUP-01: nav entry (D-10)
```php
// Add inside the "Activité" <nav> in layout.php (after line 30, the Ventes link):
<a class="nav-link <?= $isActive('/suppliers') ?>" href="/suppliers"><i class="bi bi-truck"></i> Fournisseurs</a>
```
`[VERIFIED: layout.php:25-31 Activité section, $isActive helper :5-10]`

### SUP-01: routes (`public/index.php`, after the Orders block ~line 177)
```php
// Suppliers — /suppliers/create MUST stay before /suppliers/{id}/edit (match order; no {id} GET show)
$router->get('/suppliers', [SupplierController::class, 'index']);
$router->get('/suppliers/create', [SupplierController::class, 'create']);
$router->get('/suppliers/{id}/edit', [SupplierController::class, 'edit']);
$router->post('/suppliers', [SupplierController::class, 'store']);
$router->post('/suppliers/{id}', [SupplierController::class, 'update']);
$router->post('/suppliers/{id}/delete', [SupplierController::class, 'destroy']);
```
Plus `use App\Controllers\SupplierController;` in the import block (`:12-19`). There is **no** `GET /suppliers/{id}` show route (D-09 = table only), so the static-before-parameterized rule is trivially satisfied; still place `create` before `{id}/edit` for convention. `[VERIFIED: index.php:158-177 products/orders route shape + ordering comment]`

## State of the Art

| Old Approach | Current Approach | When Changed | Impact |
|--------------|------------------|--------------|--------|
| Free-text supplier on orders | Optional FK + preserved free text | This phase | Backward-compatible; no data migration (D-02) |
| Product delete leaves orphaned Cloudinary assets | Best-effort purge loop | This phase | Closes the orphan bug tracked in STATE.md:51,35 |

**Deprecated/outdated:** Nothing. The stack (PHP 8.3, MySQL 8, Bootstrap 5.3.3, Bootstrap Icons 1.11.3, Cloudinary signed-REST) is current and frozen by project constraint. No version churn relevant to this phase.

## Assumptions Log

| # | Claim | Section | Risk if Wrong |
|---|-------|---------|---------------|
| A1 | `suppliers.rating` typed `TINYINT UNSIGNED NULL` (1-5) is the right column type | Pattern 3 DDL | LOW — any small-int nullable type works; cosmetic. Planner may choose `TINYINT` signed; no behavioral impact. |
| A2 | `suppliers.name VARCHAR(150)` / `url VARCHAR(500)` lengths mirror `orders.supplier`(150) / `orders.order_url`(500) | Pattern 3 DDL | LOW — chosen to match existing columns exactly `[VERIFIED: schema.sql:71-72]`; adjust only if longer values expected. |
| A3 | No `GET /suppliers/{id}` detail page is wanted | Routes / Anti-patterns | LOW — derived from D-09 (table only) + ROADMAP file list (index+form only). If a detail page is later wanted it is additive. |
| A4 | Operator runs `bin/migrate.php` against Aiven **before** deploying new code (zero-downtime ordering) | Pitfall 1 | LOW — additive schema is back-compat with old code, so either order works; "migrate first" only avoids a transient 500 window. Planner should still gate the migration as an explicit operator step. |

**All other claims are `[VERIFIED]` against the live source files read this session.** No external/registry/training-data claims were made (no packages, no library-version lookups).

## Open Questions

1. **Star-rating widget exact implementation** (clickable stars, optional/clearable).
   - What we know: D-06 wants clickable 1-5 stars, optional, neutral placeholder when unrated; Bootstrap Icons `bi-star`/`bi-star-fill` available; reusable for Phase 9. Claude's discretion D explicitly leaves the impl open.
   - What's unclear: hidden-input+JS vs radio inputs; how "clear to unrated" is exposed.
   - Recommendation: hidden `<input name="rating">` + a row of `bi-star` buttons toggled by a small `initStarRating()` in `app.js`, with a "0/effacer" affordance; server already treats empty rating as `null`. Keep the widget self-contained so Phase 9 (product rating) reuses it.

2. **Whether to expose sort-by-rating on the suppliers list.**
   - What we know: Deferred/nice-to-have per CONTEXT (Deferred Ideas) and D-09.
   - Recommendation: Default sort by name only; do **not** build sort-by-rating this phase (it is explicitly deferred).

## Environment Availability

| Dependency | Required By | Available | Version | Fallback |
|------------|------------|-----------|---------|----------|
| PHP CLI (`php bin/migrate.php`) | Schema migration (operator) | ✓ (operator's local machine, per Phase 2/3 precedent) | 8.3+ | None — migration is mandatory for this phase |
| Aiven MySQL 8 (TLS, CA cert) | All persistence + migration target | ✓ | 8.4 (prod) | None — primary datastore |
| Cloudinary (signed REST) | OPS-06 purge | ✓ (3 `CLOUDINARY_*` Vercel vars set in Phase 4) | — | OPS-06 is best-effort: a Cloudinary outage logs + continues, never blocks |
| Bootstrap 5.3.3 / Icons 1.11.3 (CDN) | Supplier UI + stars | ✓ | 5.3.3 / 1.11.3 | None needed |
| Vercel (deploy target) | Hosting the new routes | ✓ | — | — |

**Missing dependencies with no fallback:** None. The only hard external action is the operator re-running `bin/migrate.php` against Aiven (a proven step from Phases 2/3 — `[VERIFIED: ROADMAP Phase 2/3 Wave 2 operator steps]`).

**Migration / serverless caveat (answers Q6):** DDL never runs per request — `Database::connection()` no longer calls `Schema::ensure()` (DB-03), and `bin/migrate.php` is hard-gated to CLI (`PHP_SAPI !== 'cli'` → 403, `bin/migrate.php:11-14`). The migration is an **explicit operator step** the plan must include (a Wave-2 / `checkpoint:human-verify` task): run `php bin/migrate.php` against Aiven, confirm the `suppliers` table + `orders.supplier_id` column exist, then deploy. `[VERIFIED: bin/migrate.php, Database.php per DB-03, STATE.md]`

## Validation Architecture

> nyquist_validation = true (config.json:19). Section included. Note: the project **explicitly descopes extended automated tests** — "Couverture de tests étendue (contrôleurs, modèles, Core) — Hors périmètre ; seul ProfitCalculator reste testé" (REQUIREMENTS.md:103). So validation for this phase is **predominantly operator/manual verification on the live URL**, consistent with how Phases 1-7 were verified, plus PHPUnit for any pure logic (this phase introduces almost none).

### Test Framework
| Property | Value |
|----------|-------|
| Framework | PHPUnit 11.x (dev-only) `[VERIFIED: CLAUDE.md, composer.json]` |
| Config file | `phpunit.xml` (project root) |
| Coverage source | `src/Services/` only (business logic) — controllers/models intentionally untested |
| Quick run command | `vendor/bin/phpunit` |
| Full suite command | `vendor/bin/phpunit` |

### Phase Requirements → Test Map
| Req ID | Behavior | Test Type | Automated Command | File Exists? |
|--------|----------|-----------|-------------------|-------------|
| SUP-01 | Supplier CRUD persists, user-scoped | manual / integration on live URL | (manual — no model/controller test harness exists) | n/a (descoped by project policy) |
| SUP-02 | Order saves with/without supplier; legacy orders still render | manual on live URL | (manual) | n/a |
| OPS-06 | Product delete removes cover+gallery from Cloudinary; failure logged not blocking | manual (Cloudinary console / HEAD 404) | (manual) | n/a |

> If the planner wants any automated coverage, the only pure-logic candidate is the `parseInput()` supplier-resolution branch (id→name dual-write). It could be extracted/tested, but the current code reads `$_POST` directly inside the controller (no DTO), matching the established non-tested-controller convention. Recommendation: follow project policy — manual verification, mirroring the VERIF-01 checklist style.

### Sampling Rate
- **Per task commit:** `vendor/bin/phpunit` (fast; only `ProfitCalculator` tests exist — they must stay green to prove no regression to untouched logic).
- **Per wave merge:** `vendor/bin/phpunit` + manual smoke of the affected route.
- **Phase gate:** Manual verification on the live Vercel URL (post-migration): create/edit/delete a supplier; create an order with a supplier and one with "Autre"; confirm a legacy order still shows its name; delete a product with cover+gallery and confirm Cloudinary objects are gone (or failure logged).

### Wave 0 Gaps
- None blocking. No new test infrastructure required (project policy descopes controller/model tests). Existing `ProfitCalculator` suite + `phpunit.xml` already present.
- *(If the planner elects to add a `Supplier`-resolution unit test, it would need a refactor to make `parseInput()` resolution pure — out of step with current conventions; flag as optional only.)*

## Security Domain

> security_enforcement absent in config → enabled. Suppliers CRUD handles user input and a posted foreign id, so input-validation and access-control controls apply.

### Applicable ASVS Categories
| ASVS Category | Applies | Standard Control (in this codebase) |
|---------------|---------|-----------------|
| V2 Authentication | yes (indirect) | `Auth::require()` in every controller constructor `[VERIFIED]` |
| V3 Session Management | no (no change) | MySQL session handler unchanged |
| V4 Access Control | **yes** | Every model query scoped `WHERE user_id = :uid`; posted `supplier_id` MUST be re-checked via `Supplier::find($id, $userId)` before use (Pitfall 4). Supplier delete/edit/update gated by `find()` ownership returning `null`. |
| V5 Input Validation | **yes** | Server-side validation in `SupplierController::validate()` (name required, rating 1-5 or null, url/comment optional). Cast/trim at boundary per CONVENTIONS. |
| V6 Cryptography | no | No crypto introduced |

### Known Threat Patterns for this stack
| Pattern | STRIDE | Standard Mitigation (in this codebase) |
|---------|--------|---------------------|
| SQL injection | Tampering | PDO prepared statements, `ATTR_EMULATE_PREPARES=false`; all values bound — never interpolated `[VERIFIED: all models]` |
| IDOR via posted `supplier_id` (linking another user's supplier to your order) | Elevation/Tampering | `Supplier::find($supplierId, Auth::id())` ownership check; treat `null` as "Autre" (Pitfall 4). **Critical control — the planner must include it.** |
| IDOR on `/suppliers/{id}/edit|delete` | Elevation | `find($id, $userId)` returns `null` for non-owned → flash + redirect (mirror `ProductController`) |
| CSRF on supplier create/update/delete | Tampering | `Csrf::validate()` first line of every POST action; `Csrf::field()` in every form `[VERIFIED]` |
| Stored XSS via supplier name/url/comment | Tampering/Repudiation | Views escape with `e()`; clickable URL rendered with `e()` in `href` + `rel="noopener" target="_blank"` (mirror `orders/show.php:23-27`). Optionally `filter_var($url, FILTER_VALIDATE_URL)` server-side (note: existing `order_url` is NOT server-validated — see Open Question note below). |
| CSP for any new asset | — | No new external origin; existing CSP (`index.php:130-137`) already covers CDN + Cloudinary. No CSP change needed. `[VERIFIED]` |

> **Honest note on URL validation (D-05 "validated like other URLs"):** the existing codebase does **not** run server-side `filter_var` on `order_url`/`line_url`; it relies on the `type="url"` HTML hint and stores the trimmed string (nullable) — see `OrderController.php:198,333`. "Validated like other URLs" therefore means *mirror that*: `type="url"` input + trim + store `null` if empty. Adding `filter_var(FILTER_VALIDATE_URL)` server-side would be *stricter* than the current convention; recommend matching existing behavior for consistency, and flag stricter validation as an optional hardening the user can opt into.

## Sources

### Primary (HIGH confidence — read this session)
- `src/Controllers/OrderController.php` — parseInput/persistLines/create/edit (SUP-02 integration points)
- `src/Controllers/ProductController.php` — destroy/deleteImage (OPS-06 pattern)
- `src/Services/CloudinaryStorage.php` — delete/derivePublicId (best-effort, legacy-path-safe)
- `src/Models/Order.php`, `src/Models/Product.php`, `src/Models/ProductImage.php` — model patterns to mirror
- `src/Core/Schema.php` — idempotent migration patterns (SHOW COLUMNS guard, FK add)
- `sql/schema.sql` — table styles + cascade FKs
- `bin/migrate.php` — operator migration flow (schema.sql then Schema::ensure)
- `src/Views/orders/form.php`, `orders/show.php`, `products/index.php`, `products/form.php`, `layout.php` — view + nav patterns
- `public/index.php` — route registration + ordering + CSP + boot gate
- `public/assets/js/app.js` — `#o-currency`/`.d-none` toggle (:264-281), confirm modal (:522-538)
- `.planning/` CONTEXT / REQUIREMENTS / ROADMAP / STATE / config.json
- `CLAUDE.md` — project constraints, conventions, architecture

### Secondary / Tertiary
- None. No web search or external docs were needed — the phase is fully grounded in-repo and the stack is frozen by project constraint.

## Metadata

**Confidence breakdown:**
- Standard stack: HIGH — no new packages; all components verified present in repo.
- Architecture / patterns: HIGH — every pattern is a direct mirror of code read this session, with file:line citations.
- Migration (FK idempotency): HIGH — exact mirror of the existing `fk_purchases_order` guard.
- Pitfalls: HIGH — derived from reading the actual cascade FKs, cover/gallery coupling, and deploy/migrate split.
- Validation/security: HIGH on controls; the testing posture is intentionally manual per documented project policy.

**Research date:** 2026-06-15
**Valid until:** Stable — ~30 days (frozen stack; no fast-moving dependency). Re-validate only if `OrderController`, `ProductController`, `Schema.php`, or `app.js` change before planning.
