<!-- refreshed: 2026-06-12 -->
# Architecture

**Analysis Date:** 2026-06-12

## System Overview

```text
┌──────────────────────────────────────────────────────────────────┐
│                     HTTP Request (Apache)                         │
│           public/.htaccess → rewrites to public/index.php         │
└────────────────────────────┬─────────────────────────────────────┘
                             │
                             ▼
┌──────────────────────────────────────────────────────────────────┐
│                  Front Controller                                  │
│  `public/index.php`                                               │
│  • PSR-4 autoloader (App\ → src/)                                 │
│  • Env::load() / Auth::start()                                    │
│  • Security headers                                               │
│  • Route registration → Router::dispatch()                        │
└────────────────────────────┬─────────────────────────────────────┘
                             │
                             ▼
┌──────────────────────────────────────────────────────────────────┐
│                  Core\Router                                       │
│  `src/Core/Router.php`                                            │
│  Regex-based: {param} placeholders → named captures               │
│  Matches method + path, instantiates controller, calls action      │
└──────┬──────────────┬──────────────────────────────┬─────────────┘
       │              │                              │
       ▼              ▼                              ▼
┌──────────┐  ┌──────────────┐             ┌──────────────────────┐
│ Auth     │  │ Csrf         │             │ RateLimiter          │
│ (static) │  │ (static)     │             │ `src/Core/`          │
│ session  │  │ token/valid. │             │ MySQL-backed         │
└──────────┘  └──────────────┘             └──────────────────────┘
                             │
                             ▼
┌──────────────────────────────────────────────────────────────────┐
│              Controllers   `src/Controllers/`                      │
│  extends Core\Controller                                           │
│  • Constructor: Auth::require() — redirect if unauthenticated     │
│  • POST handlers: Csrf::validate() at the top                     │
│  • Reads $_GET / $_POST, delegates to Models + Services            │
│  • Calls $this->view() / redirect() / json()                      │
└────────────────────────────┬─────────────────────────────────────┘
               ┌─────────────┴──────────────┐
               ▼                            ▼
┌──────────────────────────┐  ┌─────────────────────────────────────┐
│  Models  `src/Models/`   │  │  Services  `src/Services/`           │
│  Direct PDO prepared     │  │  ProfitCalculator — pure functions   │
│  statements, all scoped  │  │  ExchangeRateService — ext. HTTP API │
│  by user_id              │  │  CsvExporter — file download         │
│  Database::connection()  │  └─────────────────────────────────────┘
│  (PDO singleton)         │
└──────────────────────────┘
               │
               ▼
┌──────────────────────────────────────────────────────────────────┐
│              Views   `src/Views/`                                  │
│  PHP templates — data injected via extract()                      │
│  Two-phase rendering: partial buffered → wrapped in layout.php    │
│  `src/helpers.php` global functions (e, money, dateFr, …)        │
└──────────────────────────────────────────────────────────────────┘
```

## Component Responsibilities

| Component | Responsibility | File |
|-----------|----------------|------|
| Front Controller | Bootstrap, autoload, env, session, security headers, route registration | `public/index.php` |
| Router | Match method + path, extract URL params, instantiate controller, call action | `src/Core/Router.php` |
| Base Controller | Render views (with layout), redirect, JSON response, flash messages, old-input | `src/Core/Controller.php` |
| Auth | Session lifecycle, password verify, login/logout, `Auth::require()` guard | `src/Core/Auth.php` |
| Csrf | Per-session CSRF token generation, hidden field helper, POST validation | `src/Core/Csrf.php` |
| Database | PDO singleton, MySQL config via Env, triggers Schema::ensure on first connect | `src/Core/Database.php` |
| Schema | Idempotent runtime migrations (`CREATE TABLE IF NOT EXISTS`, `ALTER TABLE` checks) | `src/Core/Schema.php` |
| Env | `.env` file loader; real environment variables take precedence over file values | `src/Core/Env.php` |
| RateLimiter | MySQL-backed brute-force guard for login (5 attempts / 15 min per email+IP) | `src/Core/RateLimiter.php` |
| Controllers | HTTP transport layer; validate input, orchestrate models/services, render views | `src/Controllers/` |
| Models | Encapsulate all SQL queries; always scoped to `user_id`; return associative arrays | `src/Models/` |
| ProfitCalculator | Pure, stateless business rules: CUMP, net margin, ROI, stock, weight allocation | `src/Services/ProfitCalculator.php` |
| ExchangeRateService | HTTP fetch from `api.frankfurter.app`; returns `?float` on failure | `src/Services/ExchangeRateService.php` |
| CsvExporter | Builds and streams UTF-8 BOM CSV files compatible with Excel FR | `src/Services/CsvExporter.php` |
| Views | PHP templates; receive variables via `extract()`; use `e()` to escape output | `src/Views/` |
| Layout | Single shell: sidebar nav, topbar, flash messages, auth-aware content wrapper | `src/Views/layout.php` |
| Helpers | Global template functions: `e()`, `money()`, `cur_sym()`, `pct()`, `dateFr()`, `th_sort()`, `old()` | `src/helpers.php` |

## Pattern Overview

**Overall:** Front Controller + MVC (hand-rolled, no framework)

**Key Characteristics:**
- Single entry point in `public/index.php`; Apache `.htaccess` rewrites all non-file requests there
- Custom PSR-4 autoloader built directly into the front controller (`spl_autoload_register`)
- Static utility classes (`Auth`, `Csrf`, `Database`, `Env`) act as service locators — not injected
- Controllers are concrete `final` classes that instantiate models with `new Model()` directly
- No dependency injection container

## Layers

**Front Controller:**
- Purpose: Bootstrap the application for every request
- Location: `public/index.php`
- Contains: Autoloader, env loading, session start, security headers, route definitions
- Depends on: All `App\Core\*` and `App\Controllers\*`
- Used by: Apache via `.htaccess` rewrite

**Core:**
- Purpose: Framework infrastructure (routing, auth, DB, CSRF, rate limiting, schema)
- Location: `src/Core/`
- Contains: `Router`, `Controller` (base), `Auth`, `Database`, `Csrf`, `RateLimiter`, `Schema`, `Env`
- Depends on: PHP built-ins, PDO extension
- Used by: Front controller and all Controllers

**Controllers:**
- Purpose: Handle HTTP requests; validate input; orchestrate models and services; produce responses
- Location: `src/Controllers/`
- Contains: `AuthController`, `DashboardController`, `ProductController`, `OrderController`, `PurchaseController`, `SaleController`, `ProfileController`, `ExportController`
- Depends on: `Core\*`, `Models\*`, `Services\*`
- Used by: Router

**Models:**
- Purpose: All database access, always scoped to authenticated `user_id`
- Location: `src/Models/`
- Contains: `User`, `Product`, `ProductImage`, `Purchase`, `Sale`, `Order`
- Depends on: `Core\Database`
- Used by: Controllers (and `Core\Auth` for user lookup)

**Services:**
- Purpose: Reusable business logic and utilities decoupled from HTTP and database
- Location: `src/Services/`
- Contains: `ProfitCalculator` (pure), `ExchangeRateService` (external API), `CsvExporter` (file output)
- Depends on: PHP built-ins only; no Models or Core dependencies
- Used by: Controllers

**Views:**
- Purpose: HTML presentation layer; receive pre-computed data as PHP variables
- Location: `src/Views/`
- Contains: Templates grouped by domain (`auth/`, `dashboard/`, `products/`, `orders/`, `purchases/`, `sales/`, `profile/`), `layout.php`, `partials/`
- Depends on: `src/helpers.php` global functions
- Used by: `Core\Controller::view()` and `renderPartial()`

## Data Flow

### Primary GET Request Path

1. Apache receives request, `.htaccess` rewrites to `public/index.php` (`public/.htaccess`)
2. `public/index.php` sets up autoloader, calls `Env::load()`, `Auth::start()` (starts PHP session), emits security headers
3. `public/index.php` registers all routes and calls `$router->dispatch($_SERVER['REQUEST_METHOD'], $_SERVER['REQUEST_URI'])` (`public/index.php:117`)
4. `Router::dispatch()` strips query string, iterates routes, matches method + regex, extracts `{param}` values (`src/Core/Router.php:43-76`)
5. Router instantiates controller: `$controller = new $class()` — controller constructor calls `Auth::require()`, which redirects to `/login` if session is empty (`src/Core/Auth.php:82-88`)
6. Router calls `$controller->$action($params)` with the named URL parameters array
7. Controller reads `$_GET`, calls Model methods (e.g. `$this->products->searchForUser($userId, $q)`)
8. Model acquires PDO via `Database::connection()` (singleton), runs prepared statement, returns `array` (`src/Models/Product.php:57-70`)
9. Controller assembles the view-data array, calls `$this->view('products/index', $data, 'Produits')` (`src/Core/Controller.php:13`)
10. `Controller::view()` calls `renderPartial()` for the view template (output buffered), then calls `renderPartial('layout', ...)` to wrap the content in `src/Views/layout.php`
11. Layout receives `$content` (rendered view), `$title`, `$flash`, `$authName`, `$authCheck` and emits the full HTML page

### POST Request Path (State-Changing)

1. Steps 1–6 as above
2. Controller action calls `Csrf::validate()` immediately; aborts with HTTP 419 on failure (`src/Core/Csrf.php:37-45`)
3. Controller reads and validates `$_POST` fields; on error calls `$this->flashErrors($errors, $old)` and `$this->redirect()`
4. Controller calls Model mutating method (e.g. `$this->products->create($userId, $data)`)
5. Controller calls `$this->flash('success', '...')` then `$this->redirect('/products')`

### Auth Login Flow

1. POST `/login` → `AuthController::login()` (`src/Controllers/AuthController.php:24`)
2. `Csrf::validate()` — abort if token invalid
3. `RateLimiter::minutesUntilRetry($email, $ip)` — redirect if locked
4. `Auth::attempt($email, $password)` → `User::findByEmail()` → `password_verify()` (`src/Core/Auth.php:35-46`)
5. On success: `session_regenerate_id(true)`, store `$_SESSION['user_id']` and `$_SESSION['user_name']`
6. `RateLimiter::reset()`, flash success, redirect to `/dashboard`
7. On failure: `RateLimiter::recordFailure()`, flash error, redirect to `/login`

### CSV Export Flow

1. GET `/export/{type}` → `ExportController::purchases/sales/products()` (`src/Controllers/ExportController.php`)
2. `Auth::require()` in constructor
3. Model fetches all rows for `Auth::id()`
4. `ProfitCalculator` computes derived values (CUMP, margin %)
5. `CsvExporter::build()` assembles UTF-8 BOM string; `download()` sets headers and echoes content + `exit` (`src/Services/CsvExporter.php`)

**State Management:**
- All user state stored in PHP session (`$_SESSION`): `user_id`, `user_name`, `_csrf_token`, `_flash`, `_errors`, `_old`
- No client-side state management; each request is fully server-rendered
- Flash messages survive one redirect then are consumed by `pullFlash()` in the next `view()` call

## Key Abstractions

**Core\Controller (base class):**
- Purpose: Shared HTTP response helpers for all feature controllers
- Examples: every controller in `src/Controllers/`
- Pattern: Template method — subclasses override action methods; base provides `view()`, `redirect()`, `json()`, flash helpers

**Database (PDO singleton):**
- Purpose: Single shared PDO connection for the request lifecycle
- Examples: acquired in every Model constructor via `Database::connection()`
- Pattern: Singleton; `PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC`; `PDO::ATTR_EMULATE_PREPARES => false`

**Model convention:**
- Purpose: Encapsulate all SQL for one domain entity
- Examples: `src/Models/Product.php`, `src/Models/Sale.php`, `src/Models/Purchase.php`
- Pattern: All query methods accept `int $userId` as the first argument; data-isolation enforced at SQL level via `WHERE user_id = :uid`

**Auth (static class):**
- Purpose: Session-backed authentication guard
- Pattern: `Auth::require()` called in every controller constructor for protected controllers; `Auth::id()` returns `?int` from session

**Router:**
- Purpose: URL-to-handler mapping
- Pattern: Ordered array of `(method, regex, vars, handler)` entries; first match wins; `{name}` segments become named `$params` keys passed to controller actions

## Entry Points

**HTTP Entry Point:**
- Location: `public/index.php`
- Triggers: Every HTTP request (after `.htaccess` rewrite)
- Responsibilities: Autoloader setup, env loading, session start, security headers, route registration, dispatch

**CLI / Test Entry Point:**
- Location: `phpunit.xml` → `tests/`
- Triggers: `composer test` / `vendor/bin/phpunit`

## Architectural Constraints

- **Threading:** PHP-FPM (or Apache mod_php) process-per-request model; no shared mutable state across requests other than the database and session store
- **Global state:** `Database::$instance` (static PDO singleton, `src/Core/Database.php:14`); `Env::$vars` (static array, `src/Core/Env.php:13`); `$_SESSION` (PHP superglobal); `$_GET`/`$_POST`/`$_FILES` (PHP superglobals read directly in controllers)
- **Circular imports:** None; dependency graph is strictly layered: Core ← Controllers ← (Models, Services)
- **No DI container:** Models and services are instantiated with `new` inside controllers; static calls to `Auth`, `Csrf`, `Database`
- **Route ordering matters:** Static segments (e.g. `/products/create`) must be registered before parameterized ones (e.g. `/products/{id}`) — comment in `public/index.php:74` documents this explicitly
- **User-scoped data:** Every model query includes `AND user_id = :uid`; there is no cross-user data leakage by design

## Anti-Patterns

### Business logic in controllers

**What happens:** `ProductController::index()` fetches all products from the DB, then sorts, filters, paginates, and computes aggregates in PHP loops (`src/Controllers/ProductController.php:46-134`).

**Why it's wrong:** For users with many products this loads the full result set into memory; sorting/filtering should be in SQL or a dedicated query method on the model. Controller methods grow beyond 50 lines.

**Do this instead:** Add sort/filter/paginate parameters to `Product::searchForUser()` in `src/Models/Product.php` and push aggregation to SQL.

### Model instantiated inside controller action bodies

**What happens:** `new Purchase()`, `new Sale()`, `new ProductImage()` are called mid-action inside controller methods rather than injected.

**Why it's wrong:** Cannot be mocked for unit testing; dependencies are hidden inside methods.

**Do this instead:** Declare model dependencies as constructor-injected properties (consistent with how `ProductController` already stores `$this->products`).

### Static service locators for core concerns

**What happens:** `Auth::require()`, `Csrf::validate()`, `Database::connection()` are static calls from controllers and models.

**Why it's wrong:** Makes unit testing without a running session/DB impossible; creates hidden global coupling.

**Do this instead:** For new code, inject collaborators through constructors. For existing code, tolerate at the current scale but avoid adding new static globals.

## Error Handling

**Strategy:** Fail-fast with redirects and flash messages for user-facing errors; `http_response_code()` + `echo` + `exit` for security-critical failures (CSRF 419, DB 500, 404).

**Patterns:**
- CSRF failure: HTTP 419, plain text message, `exit` (`src/Core/Csrf.php:43-45`)
- Auth guard failure: `header('Location: /login')` + `exit` (`src/Core/Auth.php:86-88`)
- DB connection failure: HTTP 500, friendly message, `error_log()`, `exit` (`src/Core/Database.php:43-47`)
- Validation errors: `flashErrors()` stores errors + old input in session, `redirect()` back to form; view reads via `pullErrors()` (`src/Core/Controller.php:69-82`)
- Model "not found": Controller checks for `null` return, flashes 'danger', redirects to list page
- 404: Router falls through to `http_response_code(404); echo '404 — Page introuvable.'` (`src/Core/Router.php:74-75`)
- File upload errors: returns `[null, $errorMessage]` tuple from `storeUploadedFile()`; controller flashes warning and continues

## Cross-Cutting Concerns

**Logging:** PHP built-in `error_log()` only; used in `Database.php` on connection failure. No structured logging framework.

**Validation:** Inline in controller action methods (e.g. `ProductController::validate()`). Errors collected in `$errors` array, stored in session via `flashErrors()`, displayed in view via `$_SESSION['_errors']`.

**Authentication:** `Auth::require()` called in every protected controller's constructor. `Auth::id()` provides the user scope for all model queries. Unauthenticated requests to protected routes redirect to `/login` immediately.

**Output escaping:** `e()` helper (`src/helpers.php:8-13`) wraps `htmlspecialchars()`; all view templates must call `e()` on dynamic values. Raw `$content` is trusted output from `renderPartial()` (already escaped inside the partial).

**CSRF protection:** `Csrf::validate()` called at the start of every POST handler. `Csrf::field()` included in every HTML form via `src/Views/layout.php:49` (logout) and all form views.

---

*Architecture analysis: 2026-06-12*
