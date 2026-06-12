# Coding Conventions

**Analysis Date:** 2026-06-12

## Standards

**PSR compliance:**
- PSR-4 autoloading via Composer: `App\` maps to `src/` (`composer.json` lines 13-15)
- Tests namespace `Tests\` maps to `tests/` (`composer.json` lines 18-20)
- Every `.php` file opens with `declare(strict_types=1);` — no exceptions found

**PHP version:** requires `>=8.3` (`composer.json` line 7). Modern PHP features are used throughout: `match`, `str_starts_with`, `str_ends_with`, `readonly`-capable style, `DateTimeImmutable`, named array shapes in docblocks.

## Naming Patterns

**Namespaces:**
- `App\Controllers` — HTTP layer classes
- `App\Core` — framework internals (router, auth, CSRF, database, etc.)
- `App\Models` — thin database access classes (Active Record-like)
- `App\Services` — pure business logic (no I/O, fully testable)
- `Tests` — test classes

**Files:**
- One class per file, filename matches class name exactly (PascalCase)
- Example: `AuthController.php`, `ProfitCalculator.php`, `Csrf.php`
- Exception: `helpers.php` (global function file, lowercase)

**Classes:**
- All classes are `final` unless intended as a base (only `Controller` is abstract/non-final)
- Example: `final class AuthController extends Controller`, `final class Router`

**Methods:**
- Public/protected methods: camelCase verbs — `showLogin()`, `store()`, `validate()`, `pullErrors()`
- Private helpers that map to HTTP patterns: `validate()`, `handleUpload()`, `storeUploadedFile()`

**Constants:**
- Class constants: `UPPER_SNAKE_CASE` — `PER_PAGE`, `MAX_UPLOAD_BYTES`, `MAX_GALLERY_PHOTOS`, `COST_SCALE`, `MONEY_SCALE`

**Variables:**
- camelCase throughout — `$productId`, `$lotPrice`, `$unitCost`, `$exchangeRate`

**View helpers (global functions):**
- `src/helpers.php` — lowercase snake_case: `e()`, `money()`, `cur_sym()`, `pct()`, `dateFr()`, `th_sort()`, `old()`
- Every helper is wrapped in `if (!function_exists(...))` guard for safety

## Type Declarations

- Scalar type hints on all parameters: `string`, `int`, `float`, `bool`, `array`
- Return types declared everywhere, including `void`, `?string`, `?array`, `?float`
- Nullable types use the `?` prefix: `?string`, `?int`, `?array`
- PHPDoc `@return` used for complex types (arrays with shapes): `@return array<int, array{type:string, message:string}>`
- `mixed` used for parameters that can accept any type (e.g., `e(mixed $value)`)

## Import Organization

**`use` statements:**
- All referenced classes are imported with explicit `use` statements — no FQCN inline (except one `\App\Core\Csrf::field()` call in views)
- Ordering: framework-internal classes first, then models, then services

**Example pattern from `ProductController.php`:**
```php
use App\Core\Auth;
use App\Core\Controller;
use App\Core\Csrf;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\Purchase;
use App\Models\Sale;
use App\Services\ProfitCalculator;
```

## View Rendering

**Pattern:** Output buffering via `renderPartial()` in `src/Core/Controller.php` (lines 28-38).

1. `$this->view('products/index', $data, 'Page Title')` — renders view file inside `layout.php`
2. `$this->renderPartial('layout', $layoutData)` — wraps the view content
3. View files live at `src/Views/{view-name}.php` — path is constructed by `Controller::renderPartial()`
4. Data is passed as a flat associative array; `extract($data, EXTR_SKIP)` makes keys available as variables in the view
5. Views use `<?= e($variable) ?>` shorthand for escaped output
6. All user-generated content goes through `e()` (`htmlspecialchars` wrapper) before output
7. Layout file uses PHPDoc `@var` annotation at top: `/** @var string $content @var string $title ... */`

**Flash messages:** Set via `$this->flash('success'|'danger'|'warning', $message)`, stored in `$_SESSION['_flash']`, pulled and cleared at render time. Available in layout as `$flash` array.

**Old input (form repopulation):** `$this->flashErrors($errors, $old)` stores validation errors and old POST values in session. Pulled with `$this->pullErrors()` as `[$errors, $old]`. Views call `old($old, 'field_name')` helper to repopulate form fields.

## Error Handling

**Validation pattern (controllers):**
1. `Csrf::validate()` called first on every POST handler — aborts with 419 on failure
2. Private `validate()` method collects `$_POST` values with casting: `trim((string) ($_POST['field'] ?? ''))`
3. Errors collected into `$errors = []` array
4. If errors exist: `$this->flashErrors($errors, $old); $this->redirect('/route'); return;`
5. `validate()` returns `null` on failure or `array<string,mixed>` on success
6. Caller checks `if ($data === null)` before proceeding

**Example from `src/Controllers/AuthController.php` (lines 77-97):**
```php
$errors = [];
if ($name === '') {
    $errors['name'] = 'Le nom est requis.';
}
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $errors['email'] = 'Adresse email invalide.';
}
if ($errors !== []) {
    $this->flashErrors($errors, ['name' => $name, 'email' => $email]);
    $this->redirect('/register');
}
```

**Exception usage:**
- `\InvalidArgumentException` thrown by `ProfitCalculator::unitCostEur()` for invalid quantity
- `\RuntimeException` thrown by `Controller::renderPartial()` for missing view files
- Database errors caught in `Database::connection()` — logs via `error_log()`, shows friendly message, exits

**Never silently ignored:** errors always result in a redirect-with-flash or an exception.

## CSRF Usage

**Pattern:** `Csrf::validate()` is called at the top of every POST handler without exception.

- Token generated lazily per session (`src/Core/Csrf.php` line 17)
- Token embedded in every HTML form via `<?= \App\Core\Csrf::field() ?>` or `\App\Core\Csrf::field()`
- Validation: `Csrf::validate()` — aborts with HTTP 419 and a French error message on failure
- Uses `hash_equals()` for timing-safe comparison

**Controllers that call `Csrf::validate()`:**
- `AuthController`: `login()`, `register()`, `logout()`
- `ProductController`: `store()`, `update()`, `destroy()`, `uploadImages()`, `deleteImage()`, `setCoverImage()`
- `PurchaseController`: `store()`, `update()`, `destroy()`
- `SaleController`: `store()`, `update()`, `destroy()`
- `ProfileController`: `update()`, `updatePassword()`
- `OrderController`: `store()`, `update()`, `destroy()`

## Input Validation Patterns

**GET parameters (filter/sort/pagination):**
```php
$sort = (string) ($_GET['sort'] ?? 'date');
if (!in_array($sort, ['date', 'product', 'quantity', 'price', 'margin'], true)) {
    $sort = 'date';
}
$page = max(1, (int) ($_GET['page'] ?? 1));
```
- Allowlist validation for enum-like parameters using `in_array(..., true)` (strict)
- `max(1, ...)` guards pagination
- Dates validated with `DateTimeImmutable::createFromFormat('Y-m-d', ...)` + round-trip check

**POST parameters (form data):**
- Cast to target type immediately: `(int)`, `(float)`, `trim((string) (...))`
- Comma-to-dot normalisation for decimal inputs: `(float) str_replace(',', '.', ...)`
- Business rules validated before database write
- Model ownership verified per user: `$this->products->find($id, $userId)` — returns null if not owned

## Database Access Pattern

- All SQL uses PDO prepared statements via `Database::connection()` singleton (`src/Core/Database.php`)
- `PDO::ATTR_EMULATE_PREPARES => false` enforces real prepared statements
- Models own all SQL; controllers never write SQL
- Models receive `$userId` on every query to enforce data isolation

## Security Headers

Set in `public/index.php` (lines 43-53):
- `X-Content-Type-Options: nosniff`
- `X-Frame-Options: DENY`
- `Referrer-Policy: strict-origin-when-cross-origin`
- `Content-Security-Policy` (no `unsafe-eval`; `unsafe-inline` only for styles)

## Session Management

- Session started once via `Auth::start()` (`src/Core/Auth.php`)
- Cookie flags: `httponly: true`, `samesite: Lax`, `secure` from `SESSION_SECURE` env var
- Session ID regenerated on login (prevents session fixation)
- Session name: `RESELLTRACK_SESS`

## Code Structure Within Classes

- Controllers extend `App\Core\Controller` and are `final`
- Constructor calls `Auth::require()` to gate authenticated sections
- One public method per route action (matching the route registration in `public/index.php`)
- Private helpers extracted for reuse within the class: `validate()`, `handleUpload()`, `dateOrNull()`, `isValidDate()`
- Class-level model properties injected in constructor: `private Purchase $purchases;`
- `private const` for configuration values: `PER_PAGE = 15`, `MAX_UPLOAD_BYTES`

## Comments

- File-level PHPDoc block on every class explaining purpose and key design decisions
- Inline comments for non-obvious business logic (CUMP, market price computation)
- `@param` and `@return` tags used for complex array types
- Views use `/** @var type $varName */` at top for IDE support

## Function Design

**Controller actions:** Typically 10-40 lines; complex ones (like `ProductController::index()`) reach ~130 lines due to in-PHP sorting/filtering but remain comprehensible
**Service methods:** Each static method in `ProfitCalculator` is ≤20 lines with a single responsibility
**Helpers:** Each global helper function in `src/helpers.php` is ≤10 lines

---

*Convention analysis: 2026-06-12*
