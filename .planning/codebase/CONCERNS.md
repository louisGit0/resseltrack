# Codebase Concerns

**Analysis Date:** 2026-06-12

---

## Security Considerations

### [MEDIUM] JSON Chart Data Injected Unescaped Into HTML

**Risk:** `$chartJson` and `$categoryJson` are output raw inside `<script type="application/json">` tags. The values are built from database-sourced strings (product category names, product names). If a category name contains `</script>`, it breaks the JSON block and enables script injection.
**Files:** `src/Views/dashboard/index.php` lines 94, 135
**Current mitigation:** `json_encode` is called with `JSON_UNESCAPED_UNICODE` but **without** `JSON_HEX_TAG` or `JSON_HEX_AMP`, so `<`, `>`, and `&` in string values are not escaped.
**Recommendation:** Pass `JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT` to every `json_encode` call whose output lands in HTML. Affected sites: `src/Controllers/DashboardController.php` lines 101–102.

---

### [MEDIUM] `order_url` / Supplier URL Rendered Directly Into `href` Without Protocol Validation

**Risk:** `order_url` values entered by users are stored verbatim and rendered with `e()` (HTML-attribute-escaped) but not validated against an allowed-protocol whitelist. A `javascript:` URL would be HTML-encoded but still a live javascript: link, depending on how the browser handles it.
**Files:**
- `src/Views/orders/index.php` line 46 — `href="<?= e($o['order_url']) ?>"`
- `src/Views/products/show.php` line 36 — `href="<?= e($reorder['url']) ?>"`
- `src/Views/orders/show.php` — similar pattern
**Current mitigation:** `e()` HTML-encodes the value; `rel="noopener"` is present for external links.
**Recommendation:** Validate `order_url` at the controller level to allow only `http://` and `https://` schemes (or strip to empty) before storing. Add the same check to `PurchaseController::validate()` and `OrderController::parseInput()`.

---

### [MEDIUM] Rate Limiting Only Covers Login — No Protection on Registration or Export Endpoints

**Risk:** `/register` has no rate limiting, allowing bulk account creation. `/export/*` endpoints stream unbounded result sets to any authenticated user with no throttle, enabling data-exfiltration or denial-of-service via repeated large CSV downloads.
**Files:**
- `src/Controllers/AuthController.php` — `showRegister()` / `register()` lack `RateLimiter` usage
- `src/Controllers/ExportController.php` — `purchases()`, `sales()`, `products()` have no rate limiting
- `src/Core/RateLimiter.php` — only instantiated in `AuthController`
**Recommendation:** Apply `RateLimiter` (or a simpler session/IP throttle) to `register` and the export endpoints.

---

### [MEDIUM] CSRF Token Is Per-Session (Never Rotated After Use)

**Risk:** `Csrf::token()` generates a token once per session and never rotates it after a successful form submission. A CSRF token that never changes offers weaker protection than single-use tokens, especially in contexts where an attacker can observe the session cookie long-term (e.g., XSS leaking the token value).
**Files:** `src/Core/Csrf.php` — `token()` method, line 16–19
**Current mitigation:** `hash_equals` prevents timing attacks; `SameSite=Lax` on the session cookie provides first-line CSRF defence.
**Recommendation:** Rotate `$_SESSION['_csrf_token']` inside `Csrf::validate()` after each successful check.

---

### [LOW] `SESSION_SECURE` Defaults to `0` — Session Cookie Not Marked Secure in Docker Defaults

**Risk:** The `.env.example` and `docker-compose.yml` both set `SESSION_SECURE=0`, meaning the session cookie lacks the `Secure` flag by default. If the app is ever proxied behind TLS without updating this value, session cookies can leak over plain HTTP.
**Files:** `.env.example` line 9; `src/Core/Auth.php` line 20
**Recommendation:** Document prominently that `SESSION_SECURE=1` must be set for any HTTPS deployment. Consider failing fast at boot when `APP_ENV=prod` and `SESSION_SECURE=0`.

---

### [LOW] No `Strict-Transport-Security` (HSTS) Header

**Risk:** `public/index.php` emits `X-Content-Type-Options`, `X-Frame-Options`, `Referrer-Policy`, and CSP, but not `Strict-Transport-Security`. Any production HTTPS deployment is missing the header that prevents protocol downgrade attacks.
**Files:** `public/index.php` lines 43–53
**Recommendation:** Add `header('Strict-Transport-Security: max-age=31536000; includeSubDomains');` when `SESSION_SECURE=1` / `APP_ENV=prod`.

---

### [LOW] CDN Resources Loaded Without SRI (Subresource Integrity)

**Risk:** Bootstrap CSS/JS and Bootstrap Icons are loaded from `cdn.jsdelivr.net` without `integrity=` attributes. A compromised CDN could inject malicious code.
**Files:** `src/Views/layout.php` lines 65–66, 141–142
**Current mitigation:** CSP `script-src` restricts to `'self' cdn.jsdelivr.net` — this limits the attack surface but does not verify specific file hashes.
**Recommendation:** Add `integrity="sha384-..."` and `crossorigin="anonymous"` to each CDN `<link>` and `<script>`.

---

### [LOW] PHPMyAdmin Exposed With No Authentication Layer in docker-compose

**Risk:** The `docker-compose.yml` starts a `phpmyadmin` service on `PMA_PORT` (default 8081) with no HTTP basic auth or network restriction. On a remote server, the database admin panel is publicly accessible.
**Files:** `docker-compose.yml` lines 50–59
**Recommendation:** Add a comment warning this must not be deployed publicly, or bind the port to `127.0.0.1` only (`"127.0.0.1:${PMA_PORT:-8081}:80"`).

---

### [LOW] `.env.example` Contains Obvious Default Credentials That Match Docker Defaults

**Risk:** `DB_PASSWORD=reselltrack`, `DB_ROOT_PASSWORD=rootsecret` in `.env.example` exactly match the docker-compose fallback values. Deployments that never override these run with well-known credentials.
**Files:** `.env.example` lines 15–18; `docker-compose.yml` environment fallbacks
**Recommendation:** Add a startup warning when `APP_ENV=prod` and `DB_PASSWORD` equals the default value.

---

## Performance Bottlenecks

### [HIGH] N+1 Query Pattern in `SaleController::productsMeta()`

**Problem:** For every product returned by `allForUser()`, two additional queries are fired: `lotsForProduct()` and `purchasedQty()` + `soldQty()`. With N products, this is `1 + N×3` queries per page load of the sale-create/edit forms.
**Files:** `src/Controllers/SaleController.php` lines 289–305
**Cause:** No batch aggregation; each product is individually queried for CUMP and stock.
**Improvement path:** Replace with a single aggregated SQL query (similar to `Product::statsForUser()`) that returns CUMP-ready data for all products in one round trip.

---

### [HIGH] Product List Page Fetches All Products Into PHP Before Filtering, Sorting, and Paginating

**Problem:** `Product::searchForUser()` returns **all** matching rows without a `LIMIT`. Sorting, category filtering, stock-status filtering, and pagination all happen in PHP (`usort`, `array_filter`, `array_slice`). With large catalogues this loads the entire product set into memory on every page view.
**Files:**
- `src/Controllers/ProductController.php` lines 46–134
- `src/Models/Product.php` lines 57–69 — `searchForUser()` has no `LIMIT`
**Cause:** Computed columns (CUMP, stock, profit) are not SQL columns, so sorting had to move to PHP. However, the full fetch-then-slice approach does not scale.
**Improvement path:** Introduce a materialized stats view or a summary table updated on purchase/sale writes to allow SQL-level ordering and pagination.

---

### [MEDIUM] Export Endpoints Fetch Entire Tables With No Limit

**Problem:** `ExportController` calls `allForUser()` on Purchase, Sale, and Product without any constraint. For users with thousands of records, this loads everything into memory in one query.
**Files:** `src/Controllers/ExportController.php` lines 26, 51, 77
**Improvement path:** Stream the CSV output row-by-row using a cursor/generator, or add a `LIMIT` with chunked iteration.

---

### [MEDIUM] `Schema::ensure()` Runs Two Queries (Plus DDL When Missing) on Every Single Request

**Problem:** `Schema::ensure()` is called inside `Database::connection()` on the first connection of every request. The two `SHOW COLUMNS` checks and the `ensureCurrencyEnum()` loop add latency to every cold page load.
**Files:** `src/Core/Schema.php` lines 17–101; `src/Core/Database.php` line 41
**Cause:** Runtime migration system designed for incremental schema evolution without a proper migration tool.
**Improvement path:** Cache the "schema is up to date" state in `$_SESSION` or an APCu flag, or use a dedicated migration command run at deploy time (not per-request).

---

### [LOW] `Purchase::latestOrderUrls()` Loads All Non-Null URLs for a User, Then Deduplicates in PHP

**Problem:** The method fetches every purchase row with a non-null `order_url` for the user (ordered by date DESC), then keeps only the first per product in PHP. Large purchase histories will load many rows unnecessarily.
**Files:** `src/Models/Purchase.php` lines 192–211
**Improvement path:** Rewrite with `SELECT DISTINCT ON` (PostgreSQL) or a `GROUP BY product_id` with `MAX(id)` subquery to do deduplication in SQL.

---

## Tech Debt

### [HIGH] Schema Migration Strategy Is Hand-Rolled and Fragile

**Issue:** Schema evolution is handled through `src/Core/Schema.php` which issues `ALTER TABLE` and `CREATE TABLE IF NOT EXISTS` statements on every boot. There is no migration versioning, no rollback support, and no way to track which migrations have been applied to a given database.
**Files:** `src/Core/Schema.php` (entire file); `sql/schema.sql`
**Impact:** A failed ALTER mid-way through `ensure()` leaves the schema in an unknown state. Adding future columns requires touching this file and writing idempotent DDL manually.
**Fix approach:** Adopt a migration tool (e.g., Phinx, Doctrine Migrations, or a minimal numbered-files runner). Move `Schema::ensure()` to a CLI command run at deploy time, not on every request.

---

### [MEDIUM] Custom Router Has No Support for PUT/PATCH/DELETE HTTP Methods

**Issue:** `src/Core/Router.php` only exposes `get()` and `post()` methods. Delete and update operations use non-RESTful `POST /resource/{id}/delete` and `POST /resource/{id}` patterns.
**Files:** `src/Core/Router.php`; `public/index.php` lines 81, 93, 101, 110
**Impact:** Harder to add REST-style API endpoints in the future; `POST` overloading makes route intent less clear.
**Fix approach:** Add `put()`, `patch()`, and `delete()` methods to the router, then convert destructive routes progressively.

---

### [MEDIUM] `isValidDate()` Is Duplicated Across Three Controllers

**Issue:** An identical private `isValidDate(string $date): bool` method is copy-pasted in `PurchaseController`, `SaleController`, and `OrderController`.
**Files:**
- `src/Controllers/PurchaseController.php` line 208
- `src/Controllers/SaleController.php` line 307
- `src/Controllers/OrderController.php` line 394
**Impact:** Any future change (e.g., adding timezone support) must be applied in three places.
**Fix approach:** Move to a shared trait or a static helper function in `src/helpers.php`.

---

### [MEDIUM] `$_POST` Is Read Directly in Every Controller — No Request Abstraction

**Issue:** All controllers access `$_POST`, `$_GET`, `$_FILES`, and `$_SERVER` directly. There is no request DTO, no centralized input sanitisation layer, and no way to inject test data without mocking superglobals.
**Files:** All controllers in `src/Controllers/`
**Impact:** Controllers are untestable without a running HTTP environment; input parsing logic is scattered across every action method.
**Fix approach:** Introduce a thin `Request` value object wrapping the superglobals, and inject it into controller actions.

---

### [MEDIUM] `Env::load()` Does Not Handle Multi-Line Values or Variable Interpolation Correctly

**Issue:** `src/Core/Env.php` splits on the first `=` and strips only leading/trailing whitespace and one layer of quotes. A value containing `=` (e.g., a base64-encoded secret) is correctly handled by `explode('=', $line, 2)`, but inline comments (e.g., `KEY=value # comment`) are not stripped and would include the comment in the value.
**Files:** `src/Core/Env.php` lines 27–33
**Impact:** A secret with a trailing `# comment` in the `.env` file is loaded with the comment as part of the value, silently causing authentication failures or mismatches.
**Fix approach:** Strip inline comments before processing (or use `symfony/dotenv` / `vlucas/phpdotenv` which handle edge cases correctly).

---

### [LOW] `RateLimiter::minutesUntilRetry()` Query Contains an Inline Integer Constant

**Issue:** `self::WINDOW_MINUTES` is interpolated directly into the SQL string in `minutesUntilRetry()`. While the value is a PHP `const` (not user input), mixing dynamic values into SQL text is a pattern to avoid.
**Files:** `src/Core/RateLimiter.php` line 36
**Impact:** No security risk (the value is a typed PHP constant), but sets a precedent that could be accidentally repeated with user-supplied data.
**Fix approach:** Use a named placeholder with `INTERVAL :minutes MINUTE` and bind the constant as a parameter.

---

### [LOW] Upload Directory Has No `.htaccess` Guard Against PHP Execution

**Issue:** `public/assets/uploads/` stores user-uploaded image files. There is no `.htaccess` or Apache `<Directory>` directive that prevents PHP execution from that directory. If a non-image file were somehow placed there (e.g., race condition, bypass of MIME check), it could be executed as PHP.
**Files:** `public/assets/uploads/` directory; `docker/apache.conf`; `public/.htaccess` (only covers dotfiles)
**Current mitigation:** MIME type is checked via `finfo` and only image MIMEs are accepted (`src/Controllers/ProductController.php` lines 474–481); filenames are randomised with `bin2hex(random_bytes(8))`.
**Recommendation:** Add a `.htaccess` inside `public/assets/uploads/` with `php_flag engine off` or equivalent to disable PHP execution for that subtree.

---

## Missing Test Coverage

### [HIGH] Zero Tests for Controllers, Models, Core Classes, and Services (Except ProfitCalculator)

**What's not tested:** All HTTP flows, CSRF validation, authentication, database queries, rate limiting, file upload logic, schema migration, and the router.
**Files:** `tests/` contains only `ProfitCalculatorTest.php` (184 lines)
**Risk:** Regressions in auth, stock calculation, CUMP freezing, and CSRF protection are completely invisible to automated testing.
**Priority:** High — business-critical paths (sale store, stock guard, login brute-force) have no coverage.

---

### [HIGH] `SaleController::store()` Transaction + Stock Guard Has No Integration Test

**What's not tested:** The concurrency path where two simultaneous sales attempt to consume the last unit of stock. The `FOR UPDATE` lock in `purchasedQty()`/`soldQty()` is the only guard.
**Files:** `src/Controllers/SaleController.php` lines 103–125; `src/Models/Purchase.php` lines 237–246; `src/Models/Sale.php` lines 337–351
**Risk:** A bug in the lock or rollback path could allow negative stock without detection.
**Priority:** High.

---

### [MEDIUM] Order Edit Repercussion Guard (`stockConflicts`) Has No Test

**What's not tested:** The logic in `OrderController::stockConflicts()` that prevents editing an order's quantities below what has already been sold.
**Files:** `src/Controllers/OrderController.php` lines 346–384
**Risk:** A regression could allow an order edit to create negative stock without warning.
**Priority:** Medium.

---

## Fragile Areas

### [MEDIUM] Custom Router Relies on Regex Match Order — Route Declaration Order Is Load-Bearing

**Why fragile:** `public/index.php` already has a comment warning that `/products/create` must be declared before `/products/{id}` to avoid the static segment being consumed by the placeholder regex. Any future developer adding routes in the wrong order will silently break existing paths.
**Files:** `public/index.php` lines 74–84; `src/Core/Router.php` lines 57–71
**Safe modification:** Always declare static routes (`/resource/create`, `/resource/export`) before parameterised routes (`/resource/{id}`) in `public/index.php`.

---

### [MEDIUM] `Schema::ensure()` Cannot Be Safely Run in Parallel (No Distributed Lock)

**Why fragile:** On a cold start or fresh volume, multiple PHP-FPM workers may simultaneously reach `Schema::ensure()`. MySQL `CREATE TABLE IF NOT EXISTS` and `ALTER TABLE IF NOT EXISTS` are not fully atomic across concurrent sessions — a race condition can produce duplicate `ALTER TABLE` errors.
**Files:** `src/Core/Schema.php`; `src/Core/Database.php` line 41
**Safe modification:** Wrap `Schema::ensure()` in a `GET_LOCK` / `RELEASE_LOCK` advisory lock, or gate it behind a one-shot deployment command.

---

### [LOW] `ExchangeRateService` Uses `@file_get_contents` (Silent Error Suppression)

**Why fragile:** The `@` operator swallows any warning or notice from the HTTP call. If `allow_url_fopen` is disabled in production PHP config, the function silently returns `null` with no log entry.
**Files:** `src/Services/ExchangeRateService.php` line 33
**Safe modification:** Remove `@`; catch the error via `error_get_last()` after a failed call and log it explicitly.

---

## Known Bugs / Edge Cases

### [MEDIUM] Editing a Purchase Does Not Update `weight_grams` or `order_id`

**Issue:** `Purchase::update()` does not include `weight_grams` or `order_id` in its `UPDATE` statement, and `PurchaseController::validate()` does not extract those fields from `$_POST`. Editing an existing purchase silently drops any weight or order association back to their original values (they are never overwritten, but also never re-submitted).
**Files:** `src/Models/Purchase.php` lines 158–171; `src/Controllers/PurchaseController.php` lines 146–206
**Trigger:** Edit any purchase that originally had `weight_grams` or `order_id` set.

---

### [LOW] CSRF Token Missing-or-Invalid Returns an Unlocalized French String With HTTP 419

**Issue:** `Csrf::validate()` hard-codes a French error message and exits with `http_response_code(419)` with no JSON fallback. If a JavaScript fetch or AJAX request hits a CSRF-protected endpoint with an expired token, the caller receives a raw French HTML fragment, not a structured error.
**Files:** `src/Core/Csrf.php` lines 38–45
**Trigger:** Any POST request with an expired or missing CSRF token.

---

## Error Handling Gaps

### [MEDIUM] Uncaught `\Throwable` in Transaction Controllers Re-Throws Without HTTP Response

**Issue:** `SaleController::store()` and `SaleController::update()` (and the equivalent in `OrderController`) catch `\Throwable`, roll back the transaction, then `throw $e`. PHP's default exception handler will produce a generic 500 page with a stack trace in development. In production (without a custom error handler or Whoops), the stack trace leaks file paths and logic.
**Files:**
- `src/Controllers/SaleController.php` lines 117–122
- `src/Controllers/OrderController.php` lines 123–127, 168–172
**Recommendation:** Register a global exception handler in `public/index.php` that logs the error and renders a friendly 500 page. Never expose stack traces in production.

---

### [MEDIUM] `Database::connection()` Calls `exit` on PDO Failure — No Error Page Rendered

**Issue:** On a database connection failure, `src/Core/Database.php` outputs a plain-text string and calls `exit`. The user gets a raw text response with no HTTP Content-Type or HTML wrapping, which renders poorly in browsers.
**Files:** `src/Core/Database.php` lines 42–47
**Recommendation:** Render a proper HTML 503 page, or use a registered shutdown/exception handler.

---

*Concerns audit: 2026-06-12*
