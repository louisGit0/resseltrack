# Phase 8: Suppliers and Product Cleanup - Pattern Map

**Mapped:** 2026-06-15
**Files analyzed:** 11 (4 new, 7 modified)
**Analogs found:** 11 / 11 (every file maps 1:1 to a verified in-repo analog)

> This map builds on `08-RESEARCH.md` (which already cites line ranges) and **re-verifies every excerpt** against the live source read this session. All line numbers below are confirmed accurate. The planner should hand each "code to copy" block straight to the executor. Scope is strictly SUP-01 / SUP-02 / OPS-06 — no extra features.

## File Classification

| New/Modified File | Role | Data Flow | Closest Analog | Match Quality |
|-------------------|------|-----------|----------------|---------------|
| `src/Models/Supplier.php` *(new)* | model | CRUD | `src/Models/Order.php` | exact |
| `src/Controllers/SupplierController.php` *(new)* | controller | request-response (CRUD) | `src/Controllers/ProductController.php` | exact |
| `src/Views/suppliers/index.php` *(new)* | view (list table) | request-response | `src/Views/products/index.php` | role-match (table + delete form + confirm) |
| `src/Views/suppliers/form.php` *(new)* | view (create/edit form) | request-response | `src/Views/products/form.php` | exact (form shell) + `orders/form.php` (toggle JS hook) |
| `src/Core/Schema.php` *(modified)* | config / DDL | batch (migration) | `Schema::ensure()` `fk_purchases_order` block | exact |
| `sql/schema.sql` *(modified)* | config / DDL | batch (migration) | `orders` CREATE TABLE block | exact |
| `src/Controllers/OrderController.php` *(modified)* | controller | request-response | its own `parseInput()` / `create()` / `edit()` | self (extend in place) |
| `src/Models/Order.php` *(modified)* | model | CRUD | its own `create()` / `update()` | self (add `supplier_id` column) |
| `src/Views/orders/form.php` *(modified)* | view | request-response | the `#o-currency` `<select>` + `.d-none` toggle in same file | self + `app.js:279-281` |
| `src/Controllers/ProductController.php` *(modified)* | controller | file-I/O (purge) | its own `deleteImage()` best-effort loop | self (`:361-390`) |
| `src/Views/layout.php` *(modified)* | view (nav) | — | `$navHtml` "Activité" `<nav>` | self (`:25-31`) |
| `public/index.php` *(modified)* | config (routes) | — | Orders route block | exact (`:170-177`) |

---

## Pattern Assignments

### `src/Models/Supplier.php` (model, CRUD) — NEW

**Analog:** `src/Models/Order.php` (the whole file is the template; `final`, `private PDO $db`, ctor `Database::connection()`, every method `:uid`-scoped, `find()` returns `null`).

**Class skeleton + imports** (copy `Order.php:1-16`):
```php
<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\Database;
use PDO;

final class Supplier
{
    private PDO $db;

    public function __construct()
    {
        $this->db = Database::connection();
    }
```

**`allForUser()` with linked-orders COUNT (D-07)** — mirror the LEFT JOIN + COUNT + GROUP BY shape proven in `Order::allForUser()` (`Order.php:22-38`):
```php
// Order.php:24-37 is the exact aggregate shape to copy (LEFT JOIN ... COUNT ... GROUP BY ... ORDER BY)
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
```

**`find()` ownership check** (copy `Order.php:40-48` verbatim, swap table):
```php
public function find(int $id, int $userId): ?array
{
    $stmt = $this->db->prepare('SELECT * FROM suppliers WHERE id = :id AND user_id = :uid LIMIT 1');
    $stmt->execute(['id' => $id, 'uid' => $userId]);
    $row = $stmt->fetch();
    return $row ?: null;
}
```

**`create()` / `update()` / `delete()`** — mirror `Order.php:86-113`. Note the established `$data['x'] ?: null` idiom for optional columns (`Order.php:74-75,97-98`); apply it to `url`, `comment`, and `rating` (empty → `null`, per D-05/D-06):
```php
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
        'rating'  => $data['rating'],   // already int|null from the controller
        'comment' => $data['comment'] ?: null,
    ]);
    return (int) $this->db->lastInsertId();
}

public function update(int $id, int $userId, array $data): void  // mirror Order.php:64-84 (UPDATE ... WHERE id AND user_id)
public function delete(int $id, int $userId): void               // mirror Order.php:109-113 (DELETE ... WHERE id AND user_id)
```
> D-08 unlink is handled by the FK `ON DELETE SET NULL` (see Schema below) — `delete()` is a plain `DELETE`, no manual `UPDATE orders`.

---

### `src/Controllers/SupplierController.php` (controller, CRUD) — NEW

**Analog:** `src/Controllers/ProductController.php` (CRUD shape `:16-301`, `validate()` `:411-447`). Use `ProductController` rather than `OrderController` because suppliers are a simple single-entity CRUD (no lines, no transaction, no fee allocation).

**Imports + ctor + model property** (copy `ProductController.php:1-24`, drop the image/profit imports — only `Auth`, `Controller`, `Csrf` + the new `Supplier` model are needed):
```php
<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Csrf;
use App\Models\Supplier;

final class SupplierController extends Controller
{
    private Supplier $suppliers;

    public function __construct()
    {
        Auth::require();              // ProductController.php:22 — auth gate in ctor
        $this->suppliers = new Supplier();
    }
```

**`index()`** — simpler than ProductController's (no sort/filter/pagination required by D-09; default sort by name comes from the model). Mirror the minimal `OrderController::index()` (`OrderController.php:39-44`) instead:
```php
public function index(): void
{
    $this->view('suppliers/index', [
        'suppliers' => $this->suppliers->allForUser(Auth::id()),
    ], 'Fournisseurs');
}
```

**`create()` / `edit()` / `store()` / `update()` / `destroy()`** — copy the exact control flow from `ProductController.php:237-301`:
- `create()` → `pullErrors()` then `view('suppliers/form', ['supplier'=>null,'errors'=>$errors,'old'=>$old], 'Nouveau fournisseur')` (mirror `:237-246`).
- `store()` → `Csrf::validate()`; `$data = $this->validate()`; `if ($data === null) redirect('/suppliers/create')`; `create()`; `flash('success', …)`; `redirect('/suppliers')` (mirror `:248-259`).
- `edit()` → `find()` ownership → `null` ⇒ `flash('danger', 'Fournisseur introuvable.')` + `redirect('/suppliers')` (mirror `:261-275`).
- `update()` → `Csrf::validate()`; re-check `find()` ownership; `validate()`; `update()`; redirect (mirror `:277-293`).
- `destroy()` → `Csrf::validate()`; `delete()`; `flash`; `redirect('/suppliers')` (mirror `:295-301`).

**`validate()` private helper** — copy `ProductController.php:411-447` structure (trim casts, `$errors` array, `flashErrors()` on failure, typed return). Add the rating-range check from RESEARCH Pattern 2:
```php
/** @return array<string,mixed>|null */
private function validate(): ?array
{
    $name    = trim((string) ($_POST['name'] ?? ''));
    $url     = trim((string) ($_POST['url'] ?? ''));
    $comment = trim((string) ($_POST['comment'] ?? ''));
    $rawRating = trim((string) ($_POST['rating'] ?? ''));
    $rating    = $rawRating === '' ? null : (int) $rawRating;

    $errors = [];
    if ($name === '') {
        $errors['name'] = 'Le nom du fournisseur est requis.';        // mirrors ProductController.php:422-424
    }
    if ($rating !== null && ($rating < 1 || $rating > 5)) {
        $errors['rating'] = 'La note doit être comprise entre 1 et 5.';
    }

    if ($errors !== []) {
        $this->flashErrors($errors, [                                 // mirrors ProductController.php:432-437
            'name' => $name, 'url' => $url, 'rating' => $rawRating, 'comment' => $comment,
        ]);
        return null;
    }

    return ['name' => $name, 'url' => $url, 'rating' => $rating, 'comment' => $comment];
}
```
> **URL handling (D-05):** mirror the existing `order_url` convention — `type="url"` input + trim + store `null` if empty (`Order.php:75`, `OrderController.php:198`). The codebase does NOT run `filter_var(FILTER_VALIDATE_URL)` server-side; match that (RESEARCH "Honest note on URL validation"). Do not add stricter validation unasked.
> **No `show()` action** — D-09 is a table-only list; do not add `GET /suppliers/{id}` (RESEARCH Anti-Patterns).

---

### `src/Views/suppliers/index.php` (view, list table) — NEW

**Analog:** `src/Views/products/index.php`. Note the Products list is a **card grid**, but D-09 asks for a **full table consistent with Products** — the load-bearing pieces to copy are the **page header + actions** (`:20-30`), the **empty-state block** (`:101-115`), and especially the **delete-form + confirm pattern** (`:211-215`):

**Page header (copy `products/index.php:20-30`, retitle):**
```php
<div class="page-header d-flex flex-wrap align-items-end justify-content-between gap-3">
    <div>
        <div class="page-eyebrow">Carnet</div>
        <h1 class="page-title">Fournisseurs</h1>
        <p class="page-sub">Vos fournisseurs et le nombre de commandes liées à chacun</p>
    </div>
    <a href="/suppliers/create" class="btn btn-primary btn-sm"><i class="bi bi-plus-lg me-1"></i>Nouveau fournisseur</a>
</div>
```

**Delete button — reuse the generic confirm modal (copy `products/index.php:211-215` exactly, swap route/label):**
```php
<form method="post" action="/suppliers/<?= (int) $s['id'] ?>/delete" class="d-inline"
      data-confirm="Supprimer « <?= e($s['name']) ?> » ? Les commandes liées conserveront leur nom de fournisseur.">
    <?= \App\Core\Csrf::field() ?>
    <button class="btn btn-sm btn-outline-danger" type="submit" title="Supprimer"><i class="bi bi-trash"></i></button>
</form>
```
> The global `form[data-confirm]` handler (`app.js:520-538`) + `#confirm-modal` (in `layout.php`) fire automatically — **no new JS**.

**Clickable URL cell** — escape in `href`, open safely (mirror the reorder link `products/index.php:194` and `orders/show.php` convention):
```php
<?php if (!empty($s['url'])): ?>
    <a href="<?= e($s['url']) ?>" target="_blank" rel="noopener"><?= e($s['url']) ?> <i class="bi bi-box-arrow-up-right" style="font-size:.7rem"></i></a>
<?php else: ?>
    <span class="text-muted">—</span>
<?php endif; ?>
```

**Columns (D-09):** name · clickable URL · rating (stars — render `bi-star-fill` × `rating`, `bi-star` for the rest; neutral "—" when `rating` is null, D-06) · comment · `orders_count` · edit/delete actions. Edit link: `<a href="/suppliers/<?= (int)$s['id'] ?>/edit" class="btn btn-sm btn-outline-secondary"><i class="bi bi-pencil"></i></a>` (mirror `products/index.php:210`). Wrap the whole list in the empty-state `if (empty($suppliers))` guard copied from `products/index.php:101-115`.

**Top docblock** (every view starts with one — copy the `products/index.php:1-4` style):
```php
<?php /** @var array $suppliers */ ?>
```

---

### `src/Views/suppliers/form.php` (view, create/edit form) — NEW

**Analog:** `src/Views/products/form.php` for the form shell; `orders/form.php` for the select-toggle hook idiom (used here for the star widget container).

**Top docblock + edit/action/`$val` helper (copy `products/form.php:1-6` verbatim, swap entity):**
```php
<?php
/** @var array|null $supplier @var array $errors @var array $old */
$isEdit = $supplier !== null;
$action = $isEdit ? '/suppliers/' . (int) $supplier['id'] : '/suppliers';
$val = static fn(string $k, $def = '') => $old[$k] ?? ($supplier[$k] ?? $def);
?>
```

**Form shell + CSRF + name field + is-invalid feedback (copy `products/form.php:7-27` pattern):**
```php
<form method="post" action="<?= e($action) ?>" id="supplier-form" novalidate>
    <?= \App\Core\Csrf::field() ?>            <!-- products/form.php:18 -->
    <div class="card mb-3"><div class="card-body p-4">
        <div class="row g-3">
            <div class="col-md-7">
                <label class="form-label">Nom *</label>
                <input type="text" name="name" class="form-control <?= isset($errors['name']) ? 'is-invalid' : '' ?>"
                       value="<?= e($val('name')) ?>" required autofocus>
                <?php if (isset($errors['name'])): ?><div class="invalid-feedback"><?= e($errors['name']) ?></div><?php endif; ?>
            </div>
            <div class="col-md-5">
                <label class="form-label">URL</label>
                <input type="url" name="url" class="form-control" value="<?= e($val('url')) ?>" placeholder="https://…">
            </div>
            <!-- rating (hidden input + clickable bi-star widget — see app.js note), comment <textarea> mirror products/form.php:35-38 -->
        </div>
    </div></div>
    <div class="d-flex gap-2">     <!-- submit/cancel buttons: copy products/form.php:94-99 -->
        <button class="btn btn-primary" type="submit"><i class="bi bi-check-lg me-1"></i><?= $isEdit ? 'Enregistrer' : 'Créer le fournisseur' ?></button>
        <a href="/suppliers" class="btn btn-outline-secondary">Annuler</a>
    </div>
</form>
```

**Comment textarea** — copy `products/form.php:35-38` (`<textarea name="comment" rows="3"><?= e($val('comment')) ?></textarea>`).

**Star widget** (Claude's discretion D-06): recommend a hidden `<input name="rating" value="<?= e($val('rating')) ?>">` + a row of `<button type="button" class="star-btn"><i class="bi bi-star"></i></button>` toggled by a small `initStarRating()` added to `app.js`. Pre-fill from `$val('rating')` for edit mode. Empty/0 ⇒ server stores `null` (already handled in `validate()`). Keep it self-contained so Phase 9 product rating reuses it.

---

### `src/Core/Schema.php` (config / DDL) — MODIFIED

**Analog (in same file):** the `fk_purchases_order` guarded block at `Schema.php:81-95` — the canonical "add a nullable FK column to an existing table, idempotently" pattern. Exact reference:
```php
// Schema.php:82-95 — copy this SHAPE for orders.supplier_id
$hasOrderId = $db->query("SHOW COLUMNS FROM purchases LIKE 'order_id'")->fetch();
if (!$hasOrderId) {
    $db->exec('ALTER TABLE purchases ADD COLUMN order_id INT UNSIGNED NULL AFTER product_id, ...');
    $db->exec(
        'ALTER TABLE purchases
            ADD KEY idx_purchases_order (order_id),
            ADD CONSTRAINT fk_purchases_order FOREIGN KEY (order_id)
                REFERENCES orders (id) ON DELETE CASCADE'
    );
}
```
And the `CREATE TABLE IF NOT EXISTS` style for a new table from `Schema.php:53-68` (`product_images` block — KEY + two FK constraints + InnoDB/utf8mb4 footer).

**Changes to make inside `Schema::ensure()` (`:16-96`), in this order:**
1. **New `suppliers` table** via `CREATE TABLE IF NOT EXISTS` — place it **before** the `orders.supplier_id` guard so the FK target exists. Column types per RESEARCH A2 (mirror existing widths): `name VARCHAR(150)`, `url VARCHAR(500)`, `rating TINYINT UNSIGNED NULL`, `comment TEXT NULL`, `created_at`/`updated_at` timestamps, `fk_suppliers_user … REFERENCES users(id) ON DELETE CASCADE` (copy the `fk_pimages_user` FK style `:63-64`).
2. **`orders.supplier_id` nullable FK** — guarded ALTER, mirroring `:82-95`:
```php
$hasSupplierId = $db->query("SHOW COLUMNS FROM orders LIKE 'supplier_id'")->fetch();
if (!$hasSupplierId) {
    $db->exec('ALTER TABLE orders ADD COLUMN supplier_id INT UNSIGNED NULL AFTER user_id');
    $db->exec(
        'ALTER TABLE orders
            ADD KEY idx_orders_supplier (supplier_id),
            ADD CONSTRAINT fk_orders_supplier FOREIGN KEY (supplier_id)
                REFERENCES suppliers (id) ON DELETE SET NULL'   // D-03 nullable / D-08 unlink-on-delete
    );
}
```
> **Anti-pattern (RESEARCH):** do NOT try to add `supplier_id` inside `schema.sql`'s `CREATE TABLE IF NOT EXISTS orders` block — it is a no-op on the live DB. The guarded `Schema::ensure()` ALTER is the single source for the column.

---

### `sql/schema.sql` (config / DDL) — MODIFIED

**Analog (in same file):** the `orders` `CREATE TABLE IF NOT EXISTS` block at `schema.sql:68-84` (verified) — copy its in-file style (PRIMARY KEY, KEY, CONSTRAINT, `ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;`) for a new `CREATE TABLE IF NOT EXISTS suppliers (...)` block so a fresh volume gets the table.

**Do NOT** add `supplier_id` to the in-file `orders` block (it diverges from the live table and never applies to existing DBs). `bin/migrate.php` runs `schema.sql` first, then `Schema::ensure()`, so the table exists before the guarded FK ALTER runs (RESEARCH "schema.sql companion change").

---

### `src/Controllers/OrderController.php` (controller, request-response) — MODIFIED (SUP-02)

**Analog:** its own `parseInput()` (`:195-286`), `create()` (`:46-57`), `edit()` (`:59-76`). The free-text `$supplier` is already read at `:197` and packed into `$header['supplier']` at `:276`.

**1. `parseInput()` — resolve optional `supplier_id` to a name (dual-write D-02).** Add alongside the existing `$supplier` read (`:197`); ownership check is mandatory (Pitfall 4 / IDOR control):
```php
// near OrderController.php:197
$supplierFree = trim((string) ($_POST['supplier'] ?? ''));   // existing free-text "Autre"
$supplierId   = (int) ($_POST['supplier_id'] ?? 0);
$supplierName = $supplierFree;
$resolvedId   = null;
if ($supplierId > 0) {
    $sup = (new \App\Models\Supplier())->find($supplierId, Auth::id());  // OWNERSHIP CHECK — never trust posted id
    if ($sup !== null) {
        $resolvedId   = (int) $sup['id'];
        $supplierName = (string) $sup['name'];   // D-02: write resolved name into the text column too
    }
}
```
Then extend the `$header` array (currently `:275-283`) with **both** keys:
```php
$header = [
    'supplier'      => $supplierName,   // existing column — persistLines() copies it onto each line (D-04, unchanged)
    'supplier_id'   => $resolvedId,     // new column — written by Order::create()/update()
    'order_url'     => $orderUrl,
    // ... unchanged keys ...
];
```
> Inject `private Supplier $suppliers;` in the ctor (mirror the model-as-property convention `:27-37`) and `use App\Models\Supplier;` rather than the inline FQCN, to match the import convention. Either is acceptable; the property form matches `$this->orders`/`$this->products`/`$this->purchases`.

**2. `persistLines()` is UNCHANGED (D-04)** — it already copies `$header['supplier']` onto each purchase line at `:332`. Leave it.

**3. `create()` / `edit()` — pass the supplier list to the form.** Add one key to each `$this->view('orders/form', [...])` call (`:49-56`, `:68-75`), mirroring how `productNames`/`categories` are already passed via `array_column(...)`:
```php
'suppliers' => $this->suppliers->allForUser(Auth::id()),   // create():54 area
'suppliers' => $this->suppliers->allForUser($userId),      // edit():73 area
```

---

### `src/Models/Order.php` (model, CRUD) — MODIFIED (SUP-02)

**Analog:** its own `create()` (`:86-106`) and `update()` (`:64-84`). The column lists are explicit (not `*`), so `supplier_id` must be added in three places or it is silently never written (Pitfall 5).

- **`create()`** — add `supplier_id` to the INSERT column list (`:89-91`), the `VALUES` placeholders (`:92-93`), and the bound params (`:95-104`): `'supplier_id' => $data['supplier_id'] ?? null,`.
- **`update()`** — add `supplier_id = :supplier_id` to the SET clause (`:67-70`) and bind it (`:73-83`): `'supplier_id' => $data['supplier_id'] ?? null,`.
- **`find()` / `allForUser()`** use `SELECT *` / `o.*` (`:43`, `:25`) — reads pick up the new column automatically, no change needed.

Bind `null` when no supplier (the `?? null` idiom matches the existing `$data['x'] ?: null` convention at `:74,97`).

---

### `src/Views/orders/form.php` (view, request-response) — MODIFIED (SUP-02)

**Analog (in same file):** the `#o-currency` `<select>` (`:86-91`) + the `#o-rate-wrapper` `d-none` wrapper (`:92-101`) — the exact "select drives a `.d-none` toggle" idiom; and `app.js:279-281` (`rateWrap.classList.toggle('d-none', !isForeign)`).

**Replace the current free-text supplier field (`orders/form.php:69-72`):**
```php
<!-- CURRENT (:69-72) — becomes a <select> + a hidden free-text wrapper -->
<div class="col-md-4">
    <label class="form-label">Fournisseur</label>
    <input type="text" name="supplier" class="form-control" value="<?= e($val('supplier')) ?>" placeholder="Superbuy, AliExpress…">
</div>
```
**With** a `<select name="supplier_id" id="o-supplier">` listing `$suppliers` (option value = id, label = name) plus an empty/"Autre" option, and the existing free-text `<input name="supplier">` moved into an `id="o-supplier-free"` wrapper toggled `.d-none`. Mirror the `#o-currency` select markup (`:86-91`) for the dropdown and the `#o-rate-wrapper` (`:92`) for the toggled wrapper. Pre-select via `$val('supplier_id')` for edit mode; the free-text value stays `$val('supplier')` so legacy orders (no `supplier_id`, free-text present) render correctly.

> Requires the new `@var array $suppliers` in the top docblock (`:2-3`) — pass it from the controller (above). A `<select>` is required over a `<datalist>` because D-01/D-02 need a distinct supplier **id** (RESEARCH "Alternatives Considered").

**Client JS (`app.js`, inside `initOrderForm()`):** add a `syncSupplier()` that mirrors `syncCurrency()` (`app.js:279-281`):
```js
// mirror of app.js:279-281
const supplier = document.getElementById('o-supplier');
const supplierFreeWrap = document.getElementById('o-supplier-free');
function syncSupplier() { supplierFreeWrap.classList.toggle('d-none', supplier.value !== ''); }
supplier.addEventListener('change', syncSupplier); syncSupplier();   // call once on load (edit-mode legacy orders)
```

---

### `src/Controllers/ProductController.php` (controller, file-I/O purge) — MODIFIED (OPS-06)

**Analog (in same file):** `deleteImage()` best-effort try/catch at `ProductController.php:376-381` (verified):
```php
// ProductController.php:376-381 — the best-effort purge pattern to replicate in a loop
try {
    (new CloudinaryStorage())->delete((string) $image['path']);
} catch (\Throwable $e) {
    error_log('Cloudinary delete failed for path ' . $image['path'] . ': ' . $e->getMessage());
    // best-effort: continue, the DB row is already gone
}
```

**Rewrite `destroy()` (currently `:295-301`)** to collect cover + gallery paths **before** the cascading DB delete, dedupe, delete, then purge best-effort:
```php
public function destroy(array $params): void
{
    Csrf::validate();                                   // :297
    $userId = Auth::id();
    $pid = (int) $params['id'];

    // 1) Collect Cloudinary paths BEFORE the cascade wipes product_images (Pitfall: cover is usually also a gallery row).
    $paths = [];
    $product = $this->products->find($pid, $userId);
    if ($product !== null && !empty($product['image_path'])) {
        $paths[] = (string) $product['image_path'];                 // cover
    }
    foreach ((new ProductImage())->allForProduct($userId, $pid) as $img) {  // ProductImage::allForProduct() exists (:19-28)
        $paths[] = (string) $img['path'];                           // gallery
    }
    $paths = array_values(array_unique(array_filter($paths)));      // dedupe + drop empties (Pitfall 2)

    // 2) DB delete (FK CASCADE removes product_images, purchases, sales).
    $this->products->delete($pid, $userId);                         // existing :298

    // 3) Best-effort purge — never blocks (D-11). Copy of :376-381 in a loop.
    $storage = new CloudinaryStorage();
    foreach ($paths as $path) {
        try {
            $storage->delete($path);                                // no-ops on legacy /assets/uploads/... paths (Pitfall 3)
        } catch (\Throwable $e) {
            error_log('Cloudinary delete failed for path ' . $path . ': ' . $e->getMessage());
        }
    }

    $this->flash('success', 'Produit supprimé (avec ses achats, ventes et images liés).');
    $this->redirect('/products');
}
```
> **No new imports** — `ProductController` already imports `App\Models\ProductImage` (`:10`) and `App\Services\CloudinaryStorage` (`:14`). `CloudinaryStorage::delete()` (`:59-81`) safely no-ops on non-Cloudinary paths via `derivePublicId()` returning `''` (`:135-144`), so legacy local paths need no special handling.

---

### `src/Views/layout.php` (view, nav) — MODIFIED (SUP-01 / D-10)

**Analog (in same file):** the "Activité" `<nav>` block at `layout.php:25-31` inside the `$navHtml` closure (`:13-54`). The closure is rendered twice — desktop sidebar (`:72`) and mobile offcanvas (`:78`) — so one line covers both.

**Add one nav link after the "Ventes" line (`:30`):**
```php
<a class="nav-link <?= $isActive('/sales') ?>" href="/sales"><i class="bi bi-cash-coin"></i> Ventes</a>
<a class="nav-link <?= $isActive('/suppliers') ?>" href="/suppliers"><i class="bi bi-truck"></i> Fournisseurs</a>  <!-- NEW (D-10) -->
```
> `$isActive` helper is at `:5-10`; `bi-truck` (or `bi-shop`) per D-10. No other change.

---

### `public/index.php` (config, routes) — MODIFIED (SUP-01)

**Analog (in same file):** the Orders route block at `public/index.php:170-177` (verified) — static `/orders/create` registered before parameterized `{id}`. There is **no** `GET /suppliers/{id}` show route (D-09 = table only), so ordering is trivially satisfied; still place `create` before `{id}/edit` by convention.

**1. Add import in the `use` block (`:12-19`):**
```php
use App\Controllers\SupplierController;
```
**2. Add the route block after the Orders block (~`:177`):**
```php
// Suppliers — /suppliers/create MUST stay before /suppliers/{id}/edit (match order; no {id} GET show — D-09)
$router->get('/suppliers', [SupplierController::class, 'index']);
$router->get('/suppliers/create', [SupplierController::class, 'create']);
$router->get('/suppliers/{id}/edit', [SupplierController::class, 'edit']);
$router->post('/suppliers', [SupplierController::class, 'store']);
$router->post('/suppliers/{id}', [SupplierController::class, 'update']);
$router->post('/suppliers/{id}/delete', [SupplierController::class, 'destroy']);
```

---

## Shared Patterns

### Authentication (apply to: `SupplierController`)
**Source:** `ProductController.php:20-24` / `OrderController.php:31-37`
```php
public function __construct()
{
    Auth::require();              // gate the whole controller
    $this->suppliers = new Supplier();   // model-as-property convention
}
```

### CSRF (apply to: every POST action in `SupplierController`; every new form)
**Source:** `ProductController.php:250,279,297` (action) and `products/form.php:18` / `products/index.php:213` (form field)
```php
Csrf::validate();                 // first line of store()/update()/destroy()
// in views:
<?= \App\Core\Csrf::field() ?>    // inside every <form method="post">
```

### Ownership / IDOR control (apply to: `SupplierController::edit/update/destroy`, `OrderController::parseInput` supplier resolution)
**Source:** `ProductController.php:263-267` ownership-or-redirect; `Order::find()` `Order.php:40-48` returns `null` when not owned
```php
if ($this->suppliers->find($id, Auth::id()) === null) {
    $this->flash('danger', 'Fournisseur introuvable.');
    $this->redirect('/suppliers');
}
// and in parseInput(): Supplier::find($postedId, Auth::id()) === null ⇒ treat as "Autre" (free text)
```

### Validation + flash-back-old-input (apply to: `SupplierController::validate`)
**Source:** `ProductController.php:411-447`
```php
$errors = [];
if ($name === '') { $errors['name'] = '…'; }
if ($errors !== []) { $this->flashErrors($errors, [/* raw inputs */]); return null; }
return [/* cleaned data */];
```

### Output escaping (apply to: `suppliers/index.php`, `suppliers/form.php`)
**Source:** every view; URLs via `e()` in `href` + `target="_blank" rel="noopener"` (`products/index.php:194`)
```php
<?= e($s['name']) ?>
<a href="<?= e($s['url']) ?>" target="_blank" rel="noopener">…</a>
```

### Best-effort external-service purge (apply to: `ProductController::destroy`)
**Source:** `ProductController.php:376-381` (`deleteImage`) — try/`\Throwable`/`error_log`/continue; never block the DB delete (D-11).

### Idempotent additive migration (apply to: `Schema.php`, `sql/schema.sql`)
**Source:** `Schema.php:82-95` (`SHOW COLUMNS` guard + ALTER column & FK together) and `:53-68` (`CREATE TABLE IF NOT EXISTS` for a new table).

### Generic delete-confirmation (apply to: `suppliers/index.php`)
**Source:** `app.js:520-538` global `form[data-confirm]` handler + `#confirm-modal` in `layout.php`; usage at `products/index.php:211-215`. **No new JS** beyond the supplier toggle + star widget.

### Select-driven `.d-none` toggle (apply to: `orders/form.php` supplier select, `suppliers/form.php` star widget hook)
**Source:** `app.js:279-281` (`classList.toggle('d-none', cond)`) driven by a `<select>` change listener; markup idiom `orders/form.php:86-92`.

---

## No Analog Found

None. Every file in this phase maps 1:1 to a verified in-repo analog. The only **net-new client code** is the star-rating widget JS/CSS (`initStarRating()` in `app.js` + `style.css`) — that is *new behavior*, not a missing analog; its DOM-toggle mechanics still mirror the established `app.js:279-281` `.d-none` toggle pattern, and the project has no existing rating widget to copy (Phase 9 will reuse this one).

---

## Metadata

**Analog search scope:** `src/Models/`, `src/Controllers/`, `src/Views/{products,orders}/`, `src/Views/layout.php`, `src/Core/Schema.php`, `src/Services/CloudinaryStorage.php`, `sql/schema.sql`, `public/index.php`, `public/assets/js/app.js`
**Files read this session:** Order.php, Schema.php, ProductController.php, OrderController.php, products/index.php, orders/form.php, layout.php, products/form.php, CloudinaryStorage.php, ProductImage.php, public/index.php (routes + boot), app.js (toggle + confirm-modal), schema.sql (orders block)
**All line numbers verified against live source.**
**Pattern extraction date:** 2026-06-15
