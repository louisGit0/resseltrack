# Phase 9: Product Ratings - Pattern Map

**Mapped:** 2026-06-16
**Files analyzed:** 9 (all MODIFIED — no new files; one new model method recommended)
**Analogs found:** 9 / 9 (every file maps to a verified in-repo analog — the Phase 8 supplier rating code, shipped and live-verified)

> Phase 9 is the **product-side mirror** of the supplier rating already shipped in Phase 8 (SUP-01). Every excerpt below was **re-read and verified against live source this session**; all line numbers are confirmed. The planner should hand each "code to copy" block straight to the executor. Scope is strictly **RATE-01** — no URL auto-fill (Phase 10), no rating analytics/filtering (deferred).
>
> **Hard deviation to avoid (08-06-SUMMARY):** never put a `;` inside a `sql/schema.sql` comment — `bin/migrate.php` splits statements on `;`. The existing products-block comments (`schema.sql:35-36`) are `;`-free; keep the new rating comments `;`-free too.

## File Classification

| Modified File | Role | Data Flow | Closest Analog | Match Quality |
|---------------|------|-----------|----------------|---------------|
| `sql/schema.sql` | config / DDL | batch (migration) | `products` CREATE TABLE block (`schema.sql:28-43`) | exact (add two columns in-block) |
| `src/Core/Schema.php` | config / DDL | batch (migration) | `Schema::ensure()` `market_price` guarded ALTER (`Schema.php:43-50`) | exact |
| `src/Models/Product.php` | model | CRUD | self — `create()` `:139-156`, `update()` `:158-189`; **`setCover()` `:107-114`** for the rate-only setter | self (extend in place) |
| `src/Controllers/ProductController.php` | controller | request-response (CRUD) + new action | `SupplierController::validate()` `:91-115`; `ProductController` self for store/update/show | exact (rating validate) + self |
| `src/Views/products/form.php` | view (form) | request-response | `suppliers/form.php:32-47` (star widget + comment block) | exact (drop-in widget) |
| `src/Views/products/index.php` | view (card grid) | request-response | `suppliers/index.php:46-55` (read-only stars) | role-match (badge, not a column) |
| `src/Views/products/show.php` | view (detail) | request-response | `suppliers/form.php:34-40` widget + `suppliers/index.php:50-54` read-only | partial (new interactive quick-rate) |
| `public/index.php` | config (routes) | — | Products route block `:159-169` | exact |
| `public/assets/js/app.js` | client widget | event-driven | `initStarRating()` `:575-623` | self — **REUSE, do not reimplement** |
| `public/assets/css/style.css` | styles | — | `.star-btn`/`.star-rating`/`.supplier-stars` `:962-985` | self — reuse / generalize |

---

## Pattern Assignments

### `sql/schema.sql` (config / DDL) — MODIFIED

**Analog (same file):** the `products` `CREATE TABLE IF NOT EXISTS` block at `schema.sql:28-43`. The `market_price_*` columns at `:35-36` are the exact precedent: nullable additive columns **with `;`-free inline comments**.

**Add two columns inside the existing products block** (after `market_price_used`, before `created_at` at `:37`):
```sql
  market_price_used DECIMAL(10,2) NULL,  -- prix constaté plateformes de revente
  rating      TINYINT UNSIGNED NULL,     -- note produit 1-5 (RATE-01, NULL = non noté)
  rating_note TEXT NULL,                 -- commentaire libre sur le produit
  created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
```
> **CRITICAL (08-06 deviation):** the comments above contain **no `;`**. Match the existing `-- prix constaté…` style exactly. A `;` in a comment breaks `bin/migrate.php`'s `explode(';')` splitter (cost Phase 8 a failed first run + the `6c8dcd7` fix).
> This block only affects **fresh** data volumes. The live DB gets the columns from `Schema::ensure()` below. `bin/migrate.php` runs `schema.sql` first, then `Schema::ensure()`.

---

### `src/Core/Schema.php` (config / DDL) — MODIFIED

**Analog (same file):** the `market_price` guarded ALTER at `Schema.php:43-50` — the canonical "add nullable columns to `products` idempotently" pattern (`SHOW COLUMNS` guard + multi-column `ALTER`). This is a closer analog than the FK blocks because rating needs **no FK and no index** (plain nullable columns, exactly like `market_price_*`).

**`Schema.php:43-50` (the shape to copy):**
```php
$hasMarket = $db->query("SHOW COLUMNS FROM products LIKE 'market_price_new'")->fetch();
if (!$hasMarket) {
    $db->exec(
        'ALTER TABLE products
            ADD COLUMN market_price_new DECIMAL(10,2) NULL AFTER image_path,
            ADD COLUMN market_price_used DECIMAL(10,2) NULL AFTER market_price_new'
    );
}
```

**Add an analogous guarded block inside `Schema::ensure()`** (anywhere after the products table is guaranteed to exist — e.g. right after the `market_price` block at `:50`):
```php
// Products: per-product rating (1-5) + free-text note (RATE-01). Additive,
// nullable, backward-compatible — existing products keep rating = NULL.
$hasRating = $db->query("SHOW COLUMNS FROM products LIKE 'rating'")->fetch();
if (!$hasRating) {
    $db->exec(
        'ALTER TABLE products
            ADD COLUMN rating TINYINT UNSIGNED NULL AFTER market_price_used,
            ADD COLUMN rating_note TEXT NULL AFTER rating'
    );
}
```
> Guard on a single sentinel column (`rating`) like `market_price_new` guards both market columns — one `SHOW COLUMNS` covers the pair. Idempotent: 2nd `migrate.php` run is a clean no-op (proven for `supplier_id` in 08-06). Column types mirror `suppliers.rating` (`Schema.php:106`: `TINYINT UNSIGNED NULL`) and `suppliers.comment` (`:107`: `TEXT NULL`).
> **Operator step (same as Phase 8):** re-run `php bin/migrate.php` against Aiven. Additive → run **before** deploy for zero-downtime.

---

### `src/Models/Product.php` (model, CRUD) — MODIFIED

The column lists in `create()`/`update()` are **explicit** (not `SELECT *` / `INSERT *`), so `rating` + `rating_note` must be added in every list and bind or they are silently never written (the Phase 8 "Pitfall 5" for `Order.supplier_id`). `find()`/`allForUser()` use `SELECT *` (`:46`, `:132`) — reads pick up the new columns automatically, **no read change needed**.

**1. `create()` (`:139-156`)** — add to the column list, the placeholders, and the binds. Mirror the `$data['x'] ?: null` idiom already used for `category`/`description` (`:149-150`). For `rating`, mirror `Supplier::create()` which binds the already-`int|null` value with `?? null` (`Supplier.php:57`):
```php
public function create(int $userId, array $data): int
{
    $stmt = $this->db->prepare(
        'INSERT INTO products
            (user_id, name, category, description, image_path, market_price_new, market_price_used, rating, rating_note)
         VALUES (:uid, :name, :category, :description, :image_path, :mnew, :mused, :rating, :rating_note)'
    );
    $stmt->execute([
        'uid'         => $userId,
        'name'        => $data['name'],
        'category'    => $data['category'] ?: null,
        'description' => $data['description'] ?: null,
        'image_path'  => $data['image_path'] ?: null,
        'mnew'        => $data['market_price_new'] ?? null,
        'mused'       => $data['market_price_used'] ?? null,
        'rating'      => $data['rating'] ?? null,        // already int|null from validate()
        'rating_note' => $data['rating_note'] ?: null,   // empty string → null
    ]);
    return (int) $this->db->lastInsertId();
}
```

**2. `update()` (`:158-189`)** — note this method has **two SQL branches** (with/without `image_path`, `:172` and `:183`). Add `rating = :rating, rating_note = :rating_note` to **both** SET clauses and add the two keys to the shared `$params` array at `:160-168`:
```php
$params = [
    'name'        => $data['name'],
    'category'    => $data['category'] ?: null,
    'description' => $data['description'] ?: null,
    'mnew'        => $data['market_price_new'] ?? null,
    'mused'       => $data['market_price_used'] ?? null,
    'rating'      => $data['rating'] ?? null,
    'rating_note' => $data['rating_note'] ?: null,
    'id'          => $id,
    'uid'         => $userId,
];
// both UPDATE statements gain: ... market_price_used = :mused, rating = :rating, rating_note = :rating_note WHERE ...
```

**3. NEW `setRating()` method for the quick inline rate (D-02/D-03).**
**Analog (same file): `setCover()` `:107-114`** — the exact "targeted single-column UPDATE scoped to id+user, nullable" pattern. **Do NOT route the quick-rate through `update()`**: `update()` binds `$data['name']` with no fallback (`:161`), so a rating-only call would require re-sending the whole product (and would clobber `rating_note`). `setRating()` keeps the quick-rate to the single column D-03 specifies.
```php
// Copy of setCover() :107-114, swap column. Used by ProductController::rate() (D-03).
/** Set (or clear with null) the product rating shown in lists & detail. */
public function setRating(int $id, int $userId, ?int $rating): void
{
    $stmt = $this->db->prepare(
        'UPDATE products SET rating = :rating WHERE id = :id AND user_id = :uid'
    );
    $stmt->execute(['rating' => $rating, 'id' => $id, 'uid' => $userId]);
}
```

---

### `src/Controllers/ProductController.php` (controller) — MODIFIED

**1. `validate()` (`:442-478`)** — add rating + rating_note parsing and the 1-5 range check. **Copy the rating idiom verbatim from `SupplierController::validate()` `:96-97,103-105,109`** (the proven, live-verified pattern):
```php
// add near the other trims (:444-450)
$ratingNote = trim((string) ($_POST['rating_note'] ?? ''));
$rawRating  = trim((string) ($_POST['rating'] ?? ''));
$rating     = $rawRating === '' ? null : (int) $rawRating;

// add to the $errors checks (after :461) — copied from SupplierController.php:103-105
if ($rating !== null && ($rating < 1 || $rating > 5)) {
    $errors['rating'] = 'La note doit être comprise entre 1 et 5.';
}

// add to the flashErrors old-input array (:464-467) — preserve raw inputs
'rating' => $rawRating, 'rating_note' => $ratingNote,

// add to the returned cleaned-data array (:471-477)
'rating'      => $rating,
'rating_note' => $ratingNote,
```
> `store()` (`:248-259`) and `update()` (`:277-293`) need **no change** — they already call `validate()` and pass the full `$data` array to `Product::create()`/`update()`, which now persist the two new keys.

**2. NEW `rate()` action (D-03)** — quick inline rate from the detail page. **Compose the proven control-flow primitives:** `Csrf::validate()` first line (like every POST action, `:250,279,297`), ownership-or-redirect (`:281-284`), then the single-column `setRating()`, then redirect back to the detail page. Parse rating with the same `'' → null` idiom as `validate()`:
```php
/** Quick inline rate from the product detail page (D-03): CSRF + ownership + rating-only update. */
public function rate(array $params): void
{
    Csrf::validate();                                   // :297 convention
    $userId = Auth::id();
    $pid = (int) $params['id'];
    if ($this->products->find($pid, $userId) === null) {   // ownership / IDOR guard, mirrors :281-284
        $this->flash('danger', 'Produit introuvable.');
        $this->redirect('/products');
    }
    $raw = trim((string) ($_POST['rating'] ?? ''));
    $rating = $raw === '' ? null : (int) $raw;          // '' / 0 → clear to NULL
    if ($rating !== null && ($rating < 1 || $rating > 5)) {
        $this->flash('danger', 'La note doit être comprise entre 1 et 5.');
        $this->redirect('/products/' . $pid);
    }
    $this->products->setRating($pid, $userId, $rating); // rating-only (D-02: comment edited via the form)
    $this->flash('success', $rating === null ? 'Note retirée.' : 'Note enregistrée.');
    $this->redirect('/products/' . $pid);
}
```
> **Redirect, not JSON** (Claude's discretion within D-03): a redirect-back keeps it consistent with every other POST action in this controller and needs no fetch/JSON client code. The detail page re-renders with the new stars.
> Quick-rate touches **only `rating`** (per D-02 — the comment/`rating_note` is edited through the full form). This is exactly why `setRating()` (not `update()`) is used.

---

### `src/Views/products/form.php` (view, form) — MODIFIED

**Analog:** `suppliers/form.php:32-47` — the star-widget block + comment textarea is a **drop-in copy**. The `$val()` helper (`products/form.php:5`) is identical to the supplier form's (`suppliers/form.php:5`), and `$product['rating']`/`$product['rating_note']` are available (Product::find SELECT *).

**Insert a "Note" section.** The cleanest spot is a new `form-section-title` block after the market-price section (after `:90`, before the closing `</div>` at `:91`), mirroring the existing `Prix du marché` section header at `:52`. Copy the widget markup **verbatim** from `suppliers/form.php:34-42` (only the comment field name changes to `rating_note`):
```php
<div class="form-section-title mt-4">Note du produit</div>
<div class="row g-3">
    <div class="col-12">
        <label class="form-label d-block">Note</label>
        <div class="star-rating" data-star-rating>            <!-- suppliers/form.php:34 -->
            <input type="hidden" name="rating" value="<?= e($val('rating')) ?>">
            <?php for ($i = 1; $i <= 5; $i++): ?>
                <button type="button" class="star-btn" data-value="<?= $i ?>" aria-label="<?= $i ?> étoile<?= $i > 1 ? 's' : '' ?>"><i class="bi bi-star"></i></button>
            <?php endfor; ?>
            <button type="button" class="star-clear btn btn-sm btn-link text-muted px-1" data-star-clear>Effacer</button>
        </div>
        <?php if (isset($errors['rating'])): ?><div class="text-danger" style="font-size:.8rem"><?= e($errors['rating']) ?></div><?php endif; ?>
        <div class="form-text">Optionnel — cliquez sur une étoile pour noter de 1 à 5, « Effacer » pour retirer la note.</div>
    </div>
    <div class="col-12">
        <label class="form-label">Commentaire</label>
        <textarea name="rating_note" class="form-control" rows="3" placeholder="Qualité, conformité, ressenti…"><?= e($val('rating_note')) ?></textarea>
    </div>
</div>
```
> The `data-star-rating` container is auto-wired by the existing `initStarRating()` (`app.js:575-623`) on `DOMContentLoaded` — **no JS change for the form**. `$val('rating')` pre-fills edit mode; empty → server stores `null` (handled in `validate()`).
> **Name collision check:** the product form already uses `name="description"` (`:37`); the rating comment must be `name="rating_note"` (distinct) — do NOT reuse `comment`/`description`.

---

### `src/Views/products/index.php` (view, card grid) — MODIFIED

**Analog:** `suppliers/index.php:46-55` (read-only stars with NULL placeholder). Note: products list is a **card grid**, not a table, and D-04 says **badge next to the name, no dedicated column, comment not shown**. So render the read-only stars inline next to the product name.

**Insert next to the product name** — in the identity block, the name link is at `:134` and the badge row at `:135-146`. Add a small stars span right after the name link, gated on `rating !== null` (D-04: unrated shows nothing/neutral, copied from `suppliers/index.php:46-55`):
```php
<a href="/products/<?= $pid ?>" class="pcard-name d-block text-truncate"><?= e($p['name']) ?></a>
<?php if ($p['rating'] !== null): $rating = (int) $p['rating']; ?>
    <span class="supplier-stars" title="<?= $rating ?>/5" aria-label="<?= $rating ?> sur 5" style="font-size:.8rem">
        <?php for ($i = 1; $i <= 5; $i++): ?>
            <i class="bi <?= $i <= $rating ? 'bi-star-fill' : 'bi-star' ?>"></i>
        <?php endfor; ?>
    </span>
<?php endif; ?>
```
> Reuses the existing `.supplier-stars` CSS class (`style.css:984-985`) verbatim — see CSS note about generalizing the name. `$p['rating']` is on every row (`$r['product']` comes from `searchForUser`→`SELECT *`). The comment (`rating_note`) is intentionally **not** rendered here (D-04). No new column header (it's a card grid, not a table).

---

### `src/Views/products/show.php` (view, detail) — MODIFIED — interactive quick-rate

This is the **headline UX** (D-05): interactive stars in the header next to the title that **submit on click** via `POST /products/{id}/rate`, plus the full comment below. No 1:1 analog exists for the interactive-submit case (suppliers only had a form widget + read-only display) — compose from two analogs:
- markup of the clickable widget: `suppliers/form.php:34-40`
- the inline POST form + CSRF idiom on the detail page: the photo "cover" form at `show.php:195-198` (`<form method="post"> + Csrf::field()`)

**Add a rating row in the header**, just under the title/badges block (after `:32`, inside the left `<div class="d-flex align-items-center gap-3">` column, or as a new row after `:45`). The quick-rate posts to the new route; clicking a star auto-submits (see the app.js wrapper below). Pre-fill from `$product['rating']`:
```php
<?php $rating = $product['rating'] !== null ? (int) $product['rating'] : 0; ?>
<form method="post" action="/products/<?= $pid ?>/rate" id="quick-rate-form" class="d-inline-flex align-items-center gap-1 mt-1">
    <?= \App\Core\Csrf::field() ?>                       <!-- mirrors show.php:196 -->
    <div class="star-rating" data-star-rating data-star-submit>   <!-- data-star-submit = auto-submit variant -->
        <input type="hidden" name="rating" value="<?= $rating ?: '' ?>">
        <?php for ($i = 1; $i <= 5; $i++): ?>
            <button type="submit" class="star-btn" data-value="<?= $i ?>" aria-label="Noter <?= $i ?>/5"><i class="bi <?= $i <= $rating ? 'bi-star-fill' : 'bi-star' ?>"></i></button>
        <?php endfor; ?>
        <button type="submit" class="star-clear btn btn-sm btn-link text-muted px-1" data-star-clear-submit aria-label="Retirer la note">Effacer</button>
    </div>
    <?php if ($rating === 0): ?><span class="text-muted" style="font-size:.78rem">noter</span><?php endif; ?>
</form>
```

**Show the full comment below** the header (after the description `<p>` at `:48`, or in/near the market card). `rating_note` escaped with `e()` (D-06):
```php
<?php if (!empty($product['rating_note'])): ?>
    <p class="page-sub mt-2 mb-0" style="max-width:640px"><i class="bi bi-chat-left-quote me-1 text-muted"></i><?= e($product['rating_note']) ?></p>
<?php endif; ?>
```
> **Editing the comment** goes through the existing "Modifier" button (`show.php:43` → the form) per D-05 — the quick-rate only sets the star value.
> Discretion (D-05): using native `type="submit"` buttons inside a `<form>` means the quick-rate works **even without JS** (each star is a submit button carrying its value via the hidden input set by the click handler). The app.js wrapper just sets the hidden value before the native submit fires.

---

### `public/index.php` (config, routes) — MODIFIED

**Analog (same file):** the Products route block `:159-169`. The router is first-match-wins on a per-method regex where `{id}` matches a single non-slash segment, so `POST /products/{id}/rate` is a **distinct path** from `POST /products/{id}` (update) — exactly like `/delete`, `/images` already coexist at `:166-169` despite being registered after `:165`. Ordering relative to `/products/{id}` is therefore safe; register it **alongside the other `/products/{id}/...` POST routes** (D-03).

**Add one line in the Products block (after `:166`, next to the other `{id}` POST sub-routes):**
```php
$router->post('/products/{id}/delete', [ProductController::class, 'destroy']);
$router->post('/products/{id}/rate', [ProductController::class, 'rate']);   // NEW — quick inline rate (RATE-01, D-03)
$router->post('/products/{id}/images', [ProductController::class, 'uploadImages']);
```
> **No import change** — `ProductController` is already imported and used throughout the Products block. (Contrast Phase 8, which needed a new `use SupplierController` line; here the controller already exists.)

---

### `public/assets/js/app.js` (client widget) — MODIFIED (minimal)

**Analog (same file): `initStarRating()` `:575-623`** — the existing, generic, reusable widget (built in 08-04 explicitly "pour être réutilisé (ex. note produit, Phase 9)", comment at `:573`). It auto-targets every `[data-star-rating]` on the page (`:576`), so the **product form widget needs ZERO new JS** — it just works.

**The ONLY new JS is a tiny auto-submit wrapper for the detail-page quick-rate** (D-02 discretion: "the inline quick-rate may need a tiny submit-on-click wrapper… must reuse the existing widget, not reimplement it"). The base `initStarRating()` already sets `input.value` and repaints on click (`:605-608`); for the `[data-star-submit]` variant, also submit the closest form. Add a minimal helper and call it on `DOMContentLoaded` next to `initStarRating()` (`:632`):
```js
// Detail-page quick-rate: reuse the rendered widget, just submit on click.
// initStarRating() (above) already painted/wired data-star-rating; this only
// adds the auto-submit for the [data-star-submit] container.
function initQuickRate() {
    document.querySelectorAll('[data-star-submit]').forEach(function (widget) {
        const form = widget.closest('form');
        const input = widget.querySelector('input[type="hidden"]');
        if (!form || !input) return;
        widget.querySelectorAll('.star-btn').forEach(function (btn) {
            btn.addEventListener('click', function () {
                input.value = btn.getAttribute('data-value') || '';
                form.submit();
            });
        });
        const clear = widget.querySelector('[data-star-clear-submit]');
        if (clear) clear.addEventListener('click', function () { input.value = ''; form.submit(); });
    });
}
// in the DOMContentLoaded block (:625-636), after initStarRating();
initQuickRate();
```
> **Do NOT** add a second rating engine or fork `initStarRating()`. The form widget reuses `initStarRating()` verbatim; the detail page reuses the same markup/CSS and only layers a submit-on-click. Since the quick-rate stars are native `type="submit"` buttons, the page still degrades gracefully without JS.

---

### `public/assets/css/style.css` (styles) — MODIFIED (reuse / rename)

**Analog (same file):** `.star-rating` / `.star-btn` / `.star-clear` `:962-981` (interactive widget) and `.supplier-stars` `:984-985` (read-only display). The interactive classes are already entity-agnostic — **the form widget and the detail quick-rate need no new CSS**.

**Only suggested change (cosmetic, optional):** the read-only class is named `.supplier-stars` but is now used by products too. Either:
- **(simplest, recommended)** reuse `.supplier-stars` as-is on the product list/detail (it is purely visual — gold fill + neutral empty, `:984-985`), or
- generalize by renaming to a neutral `.rating-stars` and updating both `suppliers/index.php:50` and the new product usages.

If reused as-is, **no CSS edit at all** is required for Phase 9. The `:984` comment ("Étoiles en lecture seule (liste fournisseurs)") could be updated to mention products.
```css
/* :984-985 — already product-ready; reuse verbatim */
.supplier-stars { color: var(--rt-warning); white-space: nowrap; font-size: .95rem; }
.supplier-stars .bi-star { color: var(--rt-border-strong); }
```

---

## Shared Patterns

### Idempotent additive migration (apply to: `Schema.php`, `sql/schema.sql`)
**Source:** `Schema.php:43-50` (`SHOW COLUMNS` guard + multi-column nullable `ALTER`, no FK needed). `schema.sql` companion: in-block nullable columns (`schema.sql:35-36`). **Deviation guard:** no `;` in `schema.sql` comments (08-06). Operator re-runs `php bin/migrate.php` against Aiven, before deploy (additive → zero-downtime).

### Rating validation 1-5-or-NULL (apply to: `ProductController::validate()` + `rate()`)
**Source:** `SupplierController::validate()` `:96-97,103-105` — `'' → null`, then `($rating < 1 || $rating > 5)` range check, French error message. Already live-verified in Phase 8.

### Explicit column lists must include new columns (apply to: `Product::create()`/`update()`)
**Source:** `Order.supplier_id` Phase 8 "Pitfall 5" — `create()`/`update()` use explicit lists, so add `rating`/`rating_note` to every INSERT/SET clause **and** binds, or they are silently never written. `Product::update()` has **two** SQL branches (`:172`, `:183`) — update both.

### Single-column targeted setter (apply to: `Product::setRating()`)
**Source:** `Product::setCover()` `:107-114` — `UPDATE products SET <col> = :v WHERE id = :id AND user_id = :uid`. Reuse this shape for the rating-only quick-rate (never route quick-rate through `update()`, which requires the full payload).

### CSRF + ownership on every POST (apply to: `ProductController::rate()`)
**Source:** `Csrf::validate()` first line (`:250,279,297`) + ownership-or-redirect (`:281-284`) + `Csrf::field()` in the form (`show.php:196`, `suppliers/form.php:18`). `rate()` composes exactly these.

### Output escaping (apply to: `products/show.php` comment, `products/index.php` badge, `products/form.php`)
**Source:** every view — `e($product['rating_note'])` for the comment (D-06), integer cast `(int) $p['rating']` for star counts.

### Reusable star widget, do-not-reimplement (apply to: `app.js`, `products/form.php`, `products/show.php`)
**Source:** `initStarRating()` `:575-623` (auto-targets `[data-star-rating]`). Form = verbatim reuse (no JS). Detail quick-rate = same markup/CSS + a tiny `[data-star-submit]` auto-submit wrapper (`initQuickRate()`), native submit buttons for no-JS graceful degradation.

---

## No Analog Found

None. Every Phase 9 file maps to a verified in-repo analog — overwhelmingly the Phase 8 supplier rating code (Model/Controller/View/JS/CSS/Schema), which shipped and was **live-verified on Aiven + Vercel** (08-06-SUMMARY). The only genuinely new behavior is the **detail-page interactive quick-rate auto-submit** (`initQuickRate()` + `POST /products/{id}/rate`); even that composes the established `initStarRating()` widget, the `setCover()` single-column-setter shape, and the CSRF+ownership POST idiom — no new pattern is invented.

---

## Metadata

**Analog search scope:** `src/Models/{Product,Supplier}.php`, `src/Controllers/{Product,Supplier}Controller.php`, `src/Views/products/{form,index,show}.php`, `src/Views/suppliers/{form,index}.php`, `src/Core/Schema.php`, `sql/schema.sql`, `public/index.php` (routes), `public/assets/js/app.js` (star widget), `public/assets/css/style.css` (star rules)
**Files read this session:** Product.php, Supplier.php, SupplierController.php, ProductController.php, products/form.php, products/show.php, products/index.php, suppliers/form.php, suppliers/index.php, Schema.php, schema.sql (products block), public/index.php (products + suppliers route blocks), app.js (initStarRating :565-637), style.css (star block :958-989)
**All line numbers verified against live source this session.**
**Pattern extraction date:** 2026-06-16
