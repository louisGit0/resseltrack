<!-- GSD:project-start source:PROJECT.md -->
## Project

**ResellTrack**

ResellTrack est une plateforme multi-utilisateurs de **suivi d'achat-revente avec calcul de rentabilité** : achat en lots (souvent AliExpress, en USD avec port et douane) revendus à l'unité (Vinted & co). PHP 8.3 + MySQL 8, architecture MVC maison sans framework, conteneurisée avec Docker.

Aujourd'hui l'application ne tourne qu'**en local**. L'objectif de ce jalon est de la rendre **accessible publiquement en ligne, déployée et pleinement fonctionnelle sur Vercel**.

**Core Value:** Tout ce qui fonctionne en local doit fonctionner **à l'identique une fois déployé sur Vercel** — le site en ligne est pleinement opérationnel pour de vrais utilisateurs (connexion qui persiste, images qui s'affichent, données qui se sauvegardent).

### Constraints

- **Plateforme**: Cible imposée = **Vercel** (serverless) — choix explicite de l'utilisateur, malgré un stack PHP/Apache/MySQL peu adapté.
- **Tech stack**: Conserver PHP 8.3 et **MySQL** (pas de migration Postgres) ; adapter le code au minimum, ne pas réécrire l'application.
- **Filesystem**: Aucune écriture disque persistante en serverless — uploads et sessions doivent passer par des services externes.
- **Sécurité**: Déploiement HTTPS public → secrets hors du dépôt, cookies sécurisés, en-têtes de sécurité de production.
<!-- GSD:project-end -->

<!-- GSD:stack-start source:codebase/STACK.md -->
## Technology Stack

## Languages
- PHP 8.3 — all server-side logic under `src/` and `public/index.php`
- JavaScript (ES2020, vanilla, no transpile step) — `public/assets/js/app.js`
- SQL (MySQL 8 dialect) — `sql/schema.sql`, `sql/seed.sql`
- CSS — `public/assets/css/style.css` (custom styles layered on top of Bootstrap)
## Runtime
- PHP 8.3 on Apache 2.4 (mod_rewrite enabled)
- Base image: `php:8.3-apache` — defined in `Dockerfile`
- Composer (PHP) — `composer.json`
- Lockfile: `composer.lock` (auto-generated, not shown in source listing but expected at root)
- No Node/npm — zero JS build step; CDN assets only
## Frameworks
- No Laravel, Symfony, or any PHP framework — fully custom MVC built in `src/Core/`
- Bootstrap 5.3.3 — CSS framework + JS bundle (`cdn.jsdelivr.net`)
- Bootstrap Icons 1.11.3 — icon font (`cdn.jsdelivr.net`)
- Chart.js 4.4.1 — dashboard charts (`cdn.jsdelivr.net`)
- Google Fonts — Inter + Sora (`fonts.googleapis.com`)
- PHPUnit 11.x — `require-dev` in `composer.json`
- Config: `phpunit.xml` at project root
- Coverage source: `src/Services/` only (unit tests for business logic)
- Docker Compose — `docker-compose.yml` (three services: `app`, `db`, `phpmyadmin`)
- Apache `.htaccess` — `public/.htaccess` (single front controller rewrite)
- Apache virtual host config — `docker/apache.conf`
## Key Dependencies
- `ext-pdo` (`*`) — only Composer extension dependency; MySQL PDO extension installed via `docker-php-ext-install pdo_mysql` in Dockerfile
- `phpunit/phpunit` (`^11.0`) — test framework (dev only)
- No ORM, no query builder, no migration framework — raw PDO with prepared statements throughout
- No Composer autoloader beyond PSR-4 — `App\` namespace mapped to `src/`, `Tests\` to `tests/`
## Configuration
- Runtime config via `.env` file (loaded by `src/Core/Env.php`) or real environment variables
- Docker Compose injects environment variables directly; they take precedence over `.env`
- Example: `.env.example` at project root
- Key variables: `APP_ENV`, `APP_PORT`, `SESSION_SECURE`, `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER`, `DB_PASSWORD`, `DB_ROOT_PASSWORD`, `PMA_PORT`
- No secrets committed; `.env` excluded from version control
- `Dockerfile` — single-stage, copies app into `php:8.3-apache` image
- `docker-compose.yml` — dev stack definition
- `docker/apache.conf` — Apache virtual host (document root: `/var/www/html/public`)
- `public/.htaccess` — URL rewriting to `index.php`
## Platform Requirements
- Docker + Docker Compose (recommended: `docker compose up`)
- PHP 8.3+ with Composer (for running tests directly outside Docker)
- MySQL 8.0 (provided by Docker service `db`)
- phpMyAdmin available at `APP_PORT+1` (dev only)
- PHP 8.3 + Apache with `mod_rewrite` and `pdo_mysql`
- MySQL 8.0
- Writable path: `public/assets/uploads/` (product images)
- Set `SESSION_SECURE=1` when serving over HTTPS
<!-- GSD:stack-end -->

<!-- GSD:conventions-start source:CONVENTIONS.md -->
## Conventions

## Standards
- PSR-4 autoloading via Composer: `App\` maps to `src/` (`composer.json` lines 13-15)
- Tests namespace `Tests\` maps to `tests/` (`composer.json` lines 18-20)
- Every `.php` file opens with `declare(strict_types=1);` — no exceptions found
## Naming Patterns
- `App\Controllers` — HTTP layer classes
- `App\Core` — framework internals (router, auth, CSRF, database, etc.)
- `App\Models` — thin database access classes (Active Record-like)
- `App\Services` — pure business logic (no I/O, fully testable)
- `Tests` — test classes
- One class per file, filename matches class name exactly (PascalCase)
- Example: `AuthController.php`, `ProfitCalculator.php`, `Csrf.php`
- Exception: `helpers.php` (global function file, lowercase)
- All classes are `final` unless intended as a base (only `Controller` is abstract/non-final)
- Example: `final class AuthController extends Controller`, `final class Router`
- Public/protected methods: camelCase verbs — `showLogin()`, `store()`, `validate()`, `pullErrors()`
- Private helpers that map to HTTP patterns: `validate()`, `handleUpload()`, `storeUploadedFile()`
- Class constants: `UPPER_SNAKE_CASE` — `PER_PAGE`, `MAX_UPLOAD_BYTES`, `MAX_GALLERY_PHOTOS`, `COST_SCALE`, `MONEY_SCALE`
- camelCase throughout — `$productId`, `$lotPrice`, `$unitCost`, `$exchangeRate`
- `src/helpers.php` — lowercase snake_case: `e()`, `money()`, `cur_sym()`, `pct()`, `dateFr()`, `th_sort()`, `old()`
- Every helper is wrapped in `if (!function_exists(...))` guard for safety
## Type Declarations
- Scalar type hints on all parameters: `string`, `int`, `float`, `bool`, `array`
- Return types declared everywhere, including `void`, `?string`, `?array`, `?float`
- Nullable types use the `?` prefix: `?string`, `?int`, `?array`
- PHPDoc `@return` used for complex types (arrays with shapes): `@return array<int, array{type:string, message:string}>`
- `mixed` used for parameters that can accept any type (e.g., `e(mixed $value)`)
## Import Organization
- All referenced classes are imported with explicit `use` statements — no FQCN inline (except one `\App\Core\Csrf::field()` call in views)
- Ordering: framework-internal classes first, then models, then services
## View Rendering
## Error Handling
- `\InvalidArgumentException` thrown by `ProfitCalculator::unitCostEur()` for invalid quantity
- `\RuntimeException` thrown by `Controller::renderPartial()` for missing view files
- Database errors caught in `Database::connection()` — logs via `error_log()`, shows friendly message, exits
## CSRF Usage
- Token generated lazily per session (`src/Core/Csrf.php` line 17)
- Token embedded in every HTML form via `<?= \App\Core\Csrf::field() ?>` or `\App\Core\Csrf::field()`
- Validation: `Csrf::validate()` — aborts with HTTP 419 and a French error message on failure
- Uses `hash_equals()` for timing-safe comparison
- `AuthController`: `login()`, `register()`, `logout()`
- `ProductController`: `store()`, `update()`, `destroy()`, `uploadImages()`, `deleteImage()`, `setCoverImage()`
- `PurchaseController`: `store()`, `update()`, `destroy()`
- `SaleController`: `store()`, `update()`, `destroy()`
- `ProfileController`: `update()`, `updatePassword()`
- `OrderController`: `store()`, `update()`, `destroy()`
## Input Validation Patterns
- Allowlist validation for enum-like parameters using `in_array(..., true)` (strict)
- `max(1, ...)` guards pagination
- Dates validated with `DateTimeImmutable::createFromFormat('Y-m-d', ...)` + round-trip check
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
<!-- GSD:conventions-end -->

<!-- GSD:architecture-start source:ARCHITECTURE.md -->
## Architecture

## System Overview
```text
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
- Single entry point in `public/index.php`; Apache `.htaccess` rewrites all non-file requests there
- Custom PSR-4 autoloader built directly into the front controller (`spl_autoload_register`)
- Static utility classes (`Auth`, `Csrf`, `Database`, `Env`) act as service locators — not injected
- Controllers are concrete `final` classes that instantiate models with `new Model()` directly
- No dependency injection container
## Layers
- Purpose: Bootstrap the application for every request
- Location: `public/index.php`
- Contains: Autoloader, env loading, session start, security headers, route definitions
- Depends on: All `App\Core\*` and `App\Controllers\*`
- Used by: Apache via `.htaccess` rewrite
- Purpose: Framework infrastructure (routing, auth, DB, CSRF, rate limiting, schema)
- Location: `src/Core/`
- Contains: `Router`, `Controller` (base), `Auth`, `Database`, `Csrf`, `RateLimiter`, `Schema`, `Env`
- Depends on: PHP built-ins, PDO extension
- Used by: Front controller and all Controllers
- Purpose: Handle HTTP requests; validate input; orchestrate models and services; produce responses
- Location: `src/Controllers/`
- Contains: `AuthController`, `DashboardController`, `ProductController`, `OrderController`, `PurchaseController`, `SaleController`, `ProfileController`, `ExportController`
- Depends on: `Core\*`, `Models\*`, `Services\*`
- Used by: Router
- Purpose: All database access, always scoped to authenticated `user_id`
- Location: `src/Models/`
- Contains: `User`, `Product`, `ProductImage`, `Purchase`, `Sale`, `Order`
- Depends on: `Core\Database`
- Used by: Controllers (and `Core\Auth` for user lookup)
- Purpose: Reusable business logic and utilities decoupled from HTTP and database
- Location: `src/Services/`
- Contains: `ProfitCalculator` (pure), `ExchangeRateService` (external API), `CsvExporter` (file output)
- Depends on: PHP built-ins only; no Models or Core dependencies
- Used by: Controllers
- Purpose: HTML presentation layer; receive pre-computed data as PHP variables
- Location: `src/Views/`
- Contains: Templates grouped by domain (`auth/`, `dashboard/`, `products/`, `orders/`, `purchases/`, `sales/`, `profile/`), `layout.php`, `partials/`
- Depends on: `src/helpers.php` global functions
- Used by: `Core\Controller::view()` and `renderPartial()`
## Data Flow
### Primary GET Request Path
### POST Request Path (State-Changing)
### Auth Login Flow
### CSV Export Flow
- All user state stored in PHP session (`$_SESSION`): `user_id`, `user_name`, `_csrf_token`, `_flash`, `_errors`, `_old`
- No client-side state management; each request is fully server-rendered
- Flash messages survive one redirect then are consumed by `pullFlash()` in the next `view()` call
## Key Abstractions
- Purpose: Shared HTTP response helpers for all feature controllers
- Examples: every controller in `src/Controllers/`
- Pattern: Template method — subclasses override action methods; base provides `view()`, `redirect()`, `json()`, flash helpers
- Purpose: Single shared PDO connection for the request lifecycle
- Examples: acquired in every Model constructor via `Database::connection()`
- Pattern: Singleton; `PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC`; `PDO::ATTR_EMULATE_PREPARES => false`
- Purpose: Encapsulate all SQL for one domain entity
- Examples: `src/Models/Product.php`, `src/Models/Sale.php`, `src/Models/Purchase.php`
- Pattern: All query methods accept `int $userId` as the first argument; data-isolation enforced at SQL level via `WHERE user_id = :uid`
- Purpose: Session-backed authentication guard
- Pattern: `Auth::require()` called in every controller constructor for protected controllers; `Auth::id()` returns `?int` from session
- Purpose: URL-to-handler mapping
- Pattern: Ordered array of `(method, regex, vars, handler)` entries; first match wins; `{name}` segments become named `$params` keys passed to controller actions
## Entry Points
- Location: `public/index.php`
- Triggers: Every HTTP request (after `.htaccess` rewrite)
- Responsibilities: Autoloader setup, env loading, session start, security headers, route registration, dispatch
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
### Model instantiated inside controller action bodies
### Static service locators for core concerns
## Error Handling
- CSRF failure: HTTP 419, plain text message, `exit` (`src/Core/Csrf.php:43-45`)
- Auth guard failure: `header('Location: /login')` + `exit` (`src/Core/Auth.php:86-88`)
- DB connection failure: HTTP 500, friendly message, `error_log()`, `exit` (`src/Core/Database.php:43-47`)
- Validation errors: `flashErrors()` stores errors + old input in session, `redirect()` back to form; view reads via `pullErrors()` (`src/Core/Controller.php:69-82`)
- Model "not found": Controller checks for `null` return, flashes 'danger', redirects to list page
- 404: Router falls through to `http_response_code(404); echo '404 — Page introuvable.'` (`src/Core/Router.php:74-75`)
- File upload errors: returns `[null, $errorMessage]` tuple from `storeUploadedFile()`; controller flashes warning and continues
## Cross-Cutting Concerns
<!-- GSD:architecture-end -->

<!-- GSD:skills-start source:skills/ -->
## Project Skills

No project skills found. Add skills to any of: `.claude/skills/`, `.agents/skills/`, `.cursor/skills/`, `.github/skills/`, or `.codex/skills/` with a `SKILL.md` index file.
<!-- GSD:skills-end -->

<!-- GSD:workflow-start source:GSD defaults -->
## GSD Workflow Enforcement

Before using Edit, Write, or other file-changing tools, start work through a GSD command so planning artifacts and execution context stay in sync.

Use these entry points:
- `/gsd-quick` for small fixes, doc updates, and ad-hoc tasks
- `/gsd-debug` for investigation and bug fixing
- `/gsd-execute-phase` for planned phase work

Do not make direct repo edits outside a GSD workflow unless the user explicitly asks to bypass it.
<!-- GSD:workflow-end -->



<!-- GSD:profile-start -->
## Developer Profile

> Profile not yet configured. Run `/gsd-profile-user` to generate your developer profile.
> This section is managed by `generate-claude-profile` -- do not edit manually.
<!-- GSD:profile-end -->
