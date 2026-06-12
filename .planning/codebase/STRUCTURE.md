<!-- refreshed: 2026-06-12 -->
# Codebase Structure

**Analysis Date:** 2026-06-12

## Directory Layout

```
reselltrack/                        # Project root
├── composer.json                   # PHP >=8.3, ext-pdo; phpunit dev dep
├── docker-compose.yml              # Dev stack (PHP + MySQL containers)
├── Dockerfile                      # PHP 8.3 + Apache image
├── phpunit.xml                     # PHPUnit configuration
├── .env.example                    # Required env vars template
├── public/                         # Web root (only directory exposed by Apache)
│   ├── .htaccess                   # Rewrite all non-file requests → index.php
│   ├── index.php                   # Front controller: bootstrap + all routes
│   └── assets/
│       ├── css/style.css           # Application stylesheet (custom tokens)
│       ├── js/app.js               # Client-side JS (Bootstrap, Chart.js wiring)
│       └── uploads/                # User-uploaded product images (runtime, gitignored)
├── sql/
│   ├── schema.sql                  # Full DDL for fresh installs
│   └── seed.sql                    # Dev seed data
├── src/
│   ├── helpers.php                 # Global template functions (e, money, dateFr, …)
│   ├── Controllers/                # HTTP transport layer
│   │   ├── AuthController.php      # Login, register, logout
│   │   ├── DashboardController.php # KPI dashboard with period filter
│   │   ├── ExportController.php    # CSV downloads for purchases/sales/products
│   │   ├── OrderController.php     # Purchase orders (grouping purchase lines)
│   │   ├── ProductController.php   # Products + gallery image management
│   │   ├── ProfileController.php   # Profile + password update
│   │   ├── PurchaseController.php  # Individual purchase lots
│   │   └── SaleController.php      # Sale records
│   ├── Core/                       # Framework internals
│   │   ├── Auth.php                # Session-based auth (static)
│   │   ├── Controller.php          # Base controller: view, redirect, json, flash
│   │   ├── Csrf.php                # CSRF token (static)
│   │   ├── Database.php            # PDO singleton (static)
│   │   ├── Env.php                 # .env loader (static)
│   │   ├── RateLimiter.php         # Login brute-force guard (MySQL-backed)
│   │   ├── Router.php              # Regex router: GET/POST + {param} segments
│   │   └── Schema.php              # Idempotent runtime migrations
│   ├── Models/                     # Data access layer
│   │   ├── Order.php               # Purchase orders table
│   │   ├── Product.php             # Products table + per-product stats
│   │   ├── ProductImage.php        # Product gallery images table
│   │   ├── Purchase.php            # Purchases table
│   │   ├── Sale.php                # Sales table + dashboard aggregates
│   │   └── User.php                # Users table
│   ├── Services/                   # Business logic + utilities
│   │   ├── CsvExporter.php         # UTF-8 BOM CSV builder + streamer
│   │   ├── ExchangeRateService.php # Frankfurter.app FX rate fetch
│   │   └── ProfitCalculator.php    # Pure: CUMP, net margin, ROI, stock value
│   └── Views/                      # PHP templates
│       ├── layout.php              # Main shell: sidebar, topbar, flash, auth wrap
│       ├── auth/
│       │   ├── login.php
│       │   └── register.php
│       ├── dashboard/
│       │   └── index.php
│       ├── orders/
│       │   ├── form.php            # Create / edit order form
│       │   ├── index.php           # Order list
│       │   └── show.php            # Order detail with line items
│       ├── products/
│       │   ├── form.php            # Create / edit product form
│       │   ├── index.php           # Product list with KPI columns
│       │   └── show.php            # Product detail + gallery + history
│       ├── purchases/
│       │   ├── form.php
│       │   └── index.php
│       ├── sales/
│       │   ├── form.php
│       │   └── index.php
│       ├── profile/
│       │   └── index.php
│       └── partials/
│           └── pagination.php
└── tests/
    └── ProfitCalculatorTest.php    # Unit tests (PHPUnit 11)
```

## Directory Purposes

**`public/`:**
- Purpose: The only directory reachable from the web. Apache document root.
- Contains: Front controller (`index.php`), `.htaccess` rewrite rule, static assets, user uploads
- Key files: `public/index.php` (all route definitions live here), `public/.htaccess`

**`src/Core/`:**
- Purpose: Hand-rolled framework infrastructure — never domain-specific logic
- Contains: Router, base Controller, Auth, Database, CSRF, RateLimiter, Schema, Env
- Key files: `src/Core/Router.php`, `src/Core/Controller.php`, `src/Core/Auth.php`, `src/Core/Database.php`

**`src/Controllers/`:**
- Purpose: One controller class per domain area; handles HTTP input/output only
- Contains: 8 controller classes, all `final` and extending `Core\Controller`
- Key files: `src/Controllers/ProductController.php` (most complex; gallery + CRUD)

**`src/Models/`:**
- Purpose: All SQL queries for one domain entity per file; always filtered by `user_id`
- Contains: 6 model classes; return plain associative arrays (`PDO::FETCH_ASSOC`)
- Key files: `src/Models/Product.php` (includes cross-join stats), `src/Models/Sale.php` (dashboard aggregates)

**`src/Services/`:**
- Purpose: Logic that does not belong to HTTP or to a single DB table
- Contains: Pure computation (`ProfitCalculator`), external API wrapper (`ExchangeRateService`), output formatter (`CsvExporter`)
- Key files: `src/Services/ProfitCalculator.php` — only class covered by unit tests

**`src/Views/`:**
- Purpose: PHP templates; never contain business logic; use `e()` for all output
- Contains: One subdirectory per domain area; shared shell in `layout.php`
- Key files: `src/Views/layout.php` (two-column shell, Bootstrap 5, Chart.js, flash toasts)

**`src/helpers.php`:**
- Purpose: Global template utility functions loaded unconditionally by the front controller
- Contains: `e()`, `money()`, `cur_sym()`, `pct()`, `dateFr()`, `th_sort()`, `old()`

**`sql/`:**
- Purpose: Reference schema and seed data
- Generated: No (hand-authored)
- Committed: Yes

**`public/assets/uploads/`:**
- Purpose: Runtime storage for user-uploaded product images; served as static files
- Generated: Yes (at runtime)
- Committed: Only `.gitkeep`; actual upload files are gitignored

**`tests/`:**
- Purpose: PHPUnit tests (currently only `ProfitCalculator` is covered)
- Generated: No

## Key File Locations

**Entry Points:**
- `public/index.php`: Front controller — the only file Apache executes for application requests

**Route Definitions:**
- `public/index.php` (lines 56–116): All `$router->get()` / `$router->post()` calls

**Configuration:**
- `.env.example`: Lists all required environment variables
- `composer.json`: PHP version constraint, PSR-4 map, dev dependencies
- `phpunit.xml`: PHPUnit bootstrap path and test suite definition
- `docker-compose.yml`: Dev service configuration (PHP + MySQL)
- `docker/apache.conf`: Apache VirtualHost configuration

**Core Logic:**
- `src/Core/Router.php`: URL dispatch
- `src/Core/Controller.php`: Base view/redirect/flash helpers
- `src/Core/Auth.php`: Session auth and guard
- `src/Core/Database.php`: PDO singleton

**Business Rules:**
- `src/Services/ProfitCalculator.php`: All profitability formulas (CUMP, margin, ROI, stock value, weight allocation)

**Schema:**
- `sql/schema.sql`: Full DDL for a fresh database volume
- `src/Core/Schema.php`: Additive runtime migrations applied on first DB connection

**Testing:**
- `tests/ProfitCalculatorTest.php`: Unit tests for `ProfitCalculator`
- `phpunit.xml`: Test runner config

## Naming Conventions

**Files:**
- Controllers: `PascalCase` with `Controller` suffix — e.g. `ProductController.php`
- Models: `PascalCase` matching the domain noun — e.g. `Product.php`, `ProductImage.php`
- Core classes: `PascalCase` matching the concern — e.g. `Router.php`, `RateLimiter.php`
- Services: `PascalCase` with descriptive suffix — e.g. `ProfitCalculator.php`, `CsvExporter.php`
- Views: `snake_case` — e.g. `index.php`, `form.php`, `show.php`
- Helpers: `snake_case` — `helpers.php`

**PHP Namespaces:**
- `App\Controllers\*` → `src/Controllers/`
- `App\Core\*` → `src/Core/`
- `App\Models\*` → `src/Models/`
- `App\Services\*` → `src/Services/`
- `Tests\*` → `tests/`
- Views and `helpers.php` are not namespaced; loaded via `require`

**Classes:**
- All classes declared `final`
- Controllers extend `App\Core\Controller`
- Models and Services are standalone (no base class)

**Methods:**
- Controller actions: `camelCase` verb — `index`, `show`, `create`, `store`, `edit`, `update`, `destroy`
- URL parameter variants: suffixed — `uploadImages`, `deleteImage`, `setCoverImage`, `duplicate`
- Model methods: `camelCase` — `findByEmail`, `allForUser`, `statsForUser`, `categoriesForUser`

**Views:**
- Domain views stored in subdirectory matching controller name (lowercase): `products/`, `orders/`, etc.
- Standard templates per resource: `index.php` (list), `form.php` (create/edit), `show.php` (detail)
- Shared components in `partials/`

## Where to Add New Code

**New Feature (full CRUD resource):**
- Controller: `src/Controllers/FeatureController.php` — extend `App\Core\Controller`, call `Auth::require()` in constructor, `Csrf::validate()` in POST handlers
- Model: `src/Models/Feature.php` — inject `Database::connection()` in constructor, scope all queries with `user_id`
- Views: `src/Views/feature/index.php`, `src/Views/feature/form.php`, `src/Views/feature/show.php`
- Routes: Register in `public/index.php` — static paths before `{id}` parameterized paths
- Tests: `tests/FeatureTest.php` (for any pure logic extracted to a Service)

**New Business Rule:**
- Add a `static` method to `src/Services/ProfitCalculator.php` if it is a pure calculation
- Create a new Service class in `src/Services/` for rules with external dependencies

**New Template Helper:**
- Add a guarded function (`if (!function_exists(...))`) to `src/helpers.php`

**New Database Column / Table:**
- Add `ALTER TABLE` or `CREATE TABLE IF NOT EXISTS` block to `src/Core/Schema.php::ensure()`
- Also update `sql/schema.sql` to keep the reference DDL in sync

**New Partial View:**
- Add `src/Views/partials/my-partial.php`
- Render from a controller or parent view via `$this->renderPartial('partials/my-partial', $data)`

**New Core Infrastructure (e.g. middleware-like behavior):**
- Add a `final class` to `src/Core/` following the static-utility or object pattern already established
- Call it from the front controller (`public/index.php`) or controller constructors as appropriate

## Special Directories

**`public/assets/uploads/`:**
- Purpose: Runtime product image storage; files written by `ProductController::storeUploadedFile()`
- Generated: Yes (runtime)
- Committed: No (`.gitignore` excludes `*.png`, `*.jpg`, `*.webp`, `*.gif`; `.gitkeep` holds the directory)
- Web path: `/assets/uploads/{hex8}.{ext}` — served directly by Apache as static files

**`vendor/`:**
- Purpose: Composer dependencies (PHPUnit and its dependencies only)
- Generated: Yes (`composer install`)
- Committed: No

**`docker/`:**
- Purpose: Apache VirtualHost configuration for the Docker dev environment
- Key file: `docker/apache.conf`

---

*Structure analysis: 2026-06-12*
