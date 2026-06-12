# Architecture Research

**Domain:** PHP MVC monolith adapted for Vercel serverless — ResellTrack deployment
**Researched:** 2026-06-12
**Confidence:** HIGH (routing, sessions, uploads) / MEDIUM (Vercel Blob HTTP API exact headers)

---

## Standard Architecture

### System Overview — Before vs After

**Current (Docker/Apache):**
```
┌──────────────────────────────────────────────────────────────────┐
│  HTTP Request                                                      │
│  public/.htaccess → public/index.php (front controller)           │
│  Static files served from public/ by Apache directly              │
└─────────────────────────┬────────────────────────────────────────┘
                          │
            ┌─────────────▼─────────────────────┐
            │         public/index.php            │
            │  autoloader · Env::load() · session │
            │  security headers · route dispatch  │
            └─────────────┬─────────────────────-┘
                          │
        ┌─────────────────┴──────────────────────┐
        ▼                                         ▼
┌──────────────┐                        ┌─────────────────┐
│ PHP file     │                        │  MySQL (Docker)  │
│ sessions     │                        │  + Schema::      │
│ /tmp/sess_*  │                        │  ensure() on     │
└──────────────┘                        │  every boot     │
                                        └─────────────────┘
        ▼
┌──────────────────────────────┐
│  public/assets/uploads/      │
│  (local disk, ephemeral)     │
└──────────────────────────────┘
```

**Target (Vercel serverless):**
```
┌──────────────────────────────────────────────────────────────────┐
│  HTTPS Request via Vercel Edge                                     │
│  vercel.json rewrites → api/index.php (serverless function)       │
│  /assets/* served from public/ as Vercel static CDN              │
└─────────────────────────┬────────────────────────────────────────┘
                          │
            ┌─────────────▼──────────────────────┐
            │         api/index.php               │
            │  (moved from public/index.php)       │
            │  autoloader · Env::load() ·          │
            │  DatabaseSessionHandler register ·   │
            │  Auth::start() · security headers   │
            │  (+ HSTS) · route dispatch           │
            └─────────────┬──────────────────────┘
                          │
  ┌───────────────────────┼──────────────────────┐
  ▼                       ▼                      ▼
┌──────────────────┐  ┌──────────────┐  ┌──────────────────────┐
│ DatabaseSession  │  │ External     │  │ BlobStorage service  │
│ Handler          │  │ managed MySQL│  │ (new)                │
│ src/Core/        │  │ (TiDB/Aiven) │  │ src/Services/        │
│  ↓               │  │              │  │  ↓                   │
│ sessions table   │  │ Schema run   │  │ Vercel Blob HTTP API │
│ in MySQL         │  │ once via     │  │ BLOB_READ_WRITE_TOKEN│
└──────────────────┘  │ bin/migrate  │  │  ↓                   │
                      └──────────────┘  │ *.public.blob.       │
                                        │ vercel-storage.com   │
                                        └──────────────────────┘
```

---

## Component Boundaries

### What Changes and Where

| Component | Current State | Target State | Files Affected |
|-----------|--------------|--------------|----------------|
| Front controller | `public/index.php` routed by Apache `.htaccess` | `api/index.php` routed by `vercel.json` rewrites | Move file; `$root = dirname(__DIR__)` still correct (both 1 level from project root) |
| Session storage | PHP file sessions in `/tmp/sess_*` (ephemeral) | MySQL-backed via `DatabaseSessionHandler` | New `src/Core/DatabaseSessionHandler.php`; modify `src/Core/Auth.php` |
| Schema migrations | `Schema::ensure()` called from `Database::connection()` on every boot | One-shot CLI script `bin/migrate.php` | Modify `src/Core/Database.php`; new `bin/migrate.php` |
| Image upload | `move_uploaded_file` to `public/assets/uploads/` | PUT to Vercel Blob API, store returned URL | Modify `src/Controllers/ProductController.php`; new `src/Services/BlobStorage.php` |
| Image deletion | `@unlink($file)` | DELETE call via Vercel Blob API | Modify `ProductController::deleteImage()` |
| Image serving | Static Apache from `/assets/uploads/` | Served from `*.public.blob.vercel-storage.com` CDN | Views unchanged (full URL works in `src` attribute) |
| Security headers | PHP `header()` calls only | `vercel.json` headers block (HSTS for all responses) + PHP (CSP) | `vercel.json`; `api/index.php` |
| Static assets (CSS/JS) | Served by Apache from `public/assets/` | Served by Vercel CDN from `public/assets/` via `outputDirectory: "public"` | No file changes |
| `.htaccess` | Apache rewrite rules | Keep for local Docker dev; Vercel ignores it | Unchanged |

### What Does Not Change

These components are serverless-compatible without modification:

- `src/Core/Router.php` — pure PHP, no I/O
- `src/Core/Controller.php` — output buffering, no I/O
- `src/Core/Auth.php` (except `start()` method) — static session calls work once handler is registered
- `src/Core/Csrf.php` — reads/writes `$_SESSION`, transparent to handler
- `src/Core/RateLimiter.php` — already MySQL-backed
- `src/Models/*` — PDO queries, user_id scoping intact
- `src/Services/ProfitCalculator.php` — pure functions
- `src/Services/ExchangeRateService.php` — outbound HTTP, fine on serverless
- `src/Services/CsvExporter.php` — in-memory streaming, no disk I/O
- `src/Views/*` — PHP templates; image paths switching to full URLs requires no template changes because `<?= e($product['image_path']) ?>` works equally with relative paths and absolute URLs

---

## Architectural Patterns

### Pattern 1: vercel.json Front-Controller Routing

**What:** A single PHP function file (`api/index.php`) handles all dynamic requests. Static files (CSS, JS) are served by Vercel's CDN from the `public/` output directory without touching PHP.

**How:** Use `rewrites` (not the legacy `routes`) in `vercel.json`. Vercel's routing pipeline checks for a matching static file first; only unmatched requests fall through to the rewrite rule. This is the key difference: `rewrites` preserves static file priority, while the legacy `routes` API overrides it and requires explicit pass-throughs.

**vercel.json structure:**
```json
{
  "outputDirectory": "public",
  "functions": {
    "api/index.php": {
      "runtime": "vercel-php@0.7.4",
      "maxDuration": 30
    }
  },
  "rewrites": [
    { "source": "/(.*)", "destination": "/api/index.php" }
  ],
  "headers": [
    {
      "source": "/(.*)",
      "headers": [
        { "key": "Strict-Transport-Security", "value": "max-age=31536000; includeSubDomains" }
      ]
    }
  ]
}
```

**Runtime:** `vercel-php@0.7.4` provides PHP 8.3.x. Confirm the exact patch at https://github.com/vercel-community/php before deploying.

**Local development:** Docker + Apache continues to work unchanged. The `public/.htaccess` is ignored by Vercel. No dev/prod divergence in application code.

**Path continuity in `api/index.php`:**
- `public/index.php` had `$root = dirname(__DIR__)` → pointed to project root from `public/`
- `api/index.php` has the same line → `dirname(__DIR__)` from `api/` also points to project root
- No path changes needed in `$root . '/src/'` autoloader or `$root . '/.env'` loader

**Static assets URL path:** Files in `public/assets/css/style.css` are served at `/assets/css/style.css`. No URL changes in views or templates.

---

### Pattern 2: MySQL Session Handler via SessionHandlerInterface

**What:** Replace PHP's default file session storage with a custom handler that reads/writes the `sessions` MySQL table. PHP's session machinery (`$_SESSION`, `session_start()`, `session_regenerate_id()`) is transparent to the rest of the application — `Auth.php`, `Csrf.php`, flash messages all continue to work without change.

**Why MySQL and not Redis:** Reuses the existing DB infrastructure. The project explicitly chose this to avoid adding a new service dependency.

**New file: `src/Core/DatabaseSessionHandler.php`**

Implements `SessionHandlerInterface`. Key design choices:
- `open()` calls `Database::connection()` lazily (triggered by `session_start()`, not before)
- `write()` uses `REPLACE INTO` (MySQL upsert) — idempotent, handles both insert and update
- `read()` filters expired sessions inline (avoids reading stale data even before GC runs)
- `gc()` hard-deletes rows older than `session.gc_maxlifetime`

**Sessions table schema** (add to `sql/schema.sql` and to `Schema::ensure()`):
```sql
CREATE TABLE IF NOT EXISTS sessions (
    id            VARCHAR(128)     NOT NULL,
    payload       MEDIUMTEXT       NOT NULL,
    last_activity INT UNSIGNED     NOT NULL,
    PRIMARY KEY (id),
    KEY idx_sessions_last_activity (last_activity)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

- `id` = PHP session ID (up to 128 chars for any hash algorithm)
- `payload` = serialized `$_SESSION` data (MEDIUMTEXT supports up to 16MB; TEXT at 64KB is too small for flash+old-input combined)
- `last_activity` = Unix timestamp (integer comparison is faster than DATETIME for GC queries)

**Wiring in `Auth::start()`** — insert before the existing `session_set_cookie_params()` block:
```php
// Register MySQL session handler before session_start().
$handler = new DatabaseSessionHandler();
session_set_save_handler($handler, true); // true = register shutdown function for write/close
```

**Cookie params for HTTPS** — already handled: `Auth::start()` reads `SESSION_SECURE` from env. Set `SESSION_SECURE=1` as a Vercel environment variable. No code change required.

**SameSite=Lax** is already set, which is correct for normal browser navigations. Do not change to `Strict` (it breaks redirect flows after OAuth in future).

---

### Pattern 3: Vercel Blob Upload via PHP HTTP Client

**What:** Replace `move_uploaded_file($tmp, $dest)` with an HTTP PUT to the Vercel Blob API. The returned full URL is stored in the database instead of a relative path. Image serving then happens directly from Vercel's CDN without any PHP involvement.

**Vercel Blob API (HTTP):**
- PUT `https://vercel.com/api/blob?pathname={filename}`
- Headers: `Authorization: Bearer {BLOB_READ_WRITE_TOKEN}`, `Content-Type: {mime}`, `x-api-version: 7`
- Body: raw file bytes (read from `$_FILES[*][tmp_name]`)
- Response JSON: `{ "url": "https://{hash}.public.blob.vercel-storage.com/{filename}", ... }`
- Vercel Functions body size limit: **4.5 MB**. The existing `MAX_UPLOAD_BYTES = 2 MB` guard already satisfies this.

Note: There is no official PHP SDK for Vercel Blob. Use PHP's `stream_context_create` + `file_get_contents` or `curl`. The `x-api-version` header value should be verified against the current SDK source at time of implementation (it was `7` at research time but increments with API changes).

**New file: `src/Services/BlobStorage.php`**

Responsibilities:
- `upload(string $tmpPath, int $size, string $mimeType, string $filename): string` — PUT to API, return the blob URL
- `delete(string $blobUrl): void` — DELETE the blob (via `https://vercel.com/api/blob/delete` POST with JSON `{"urls": [url]}`)

Reads `BLOB_READ_WRITE_TOKEN` via `Env::get()`. Throws `\RuntimeException` on API error or missing token (consistent with existing error handling style — results in 500 exit in caller).

**Changes to `src/Controllers/ProductController.php`:**

- `storeUploadedFile(string $tmp, int $size): array` — replace `move_uploaded_file` with `BlobStorage::upload()`. Return type stays `[?string $url, ?string $error]`. The returned URL is now a full `https://...` URL instead of `/assets/uploads/filename`.
- `deleteImage()` — replace `@unlink($file)` with `BlobStorage::delete($image['path'])`. The `path` column now stores a full URL; pass it directly.

**No changes to:**
- `src/Models/ProductImage.php` — `path` column stores whatever string is passed; switching to a full URL is transparent
- `src/Models/Product.php` — `image_path` column is the same
- All views — `<?= e($product['image_path']) ?>` in `<img src="...">` works equally with `/assets/uploads/foo.jpg` and `https://abc.public.blob.vercel-storage.com/foo.jpg`

**Column length:** `products.image_path` and `product_images.path` are currently `VARCHAR(255)`. Vercel Blob URLs are typically 80–130 characters. `VARCHAR(255)` is sufficient; no schema migration needed for this.

**CSP update required in `api/index.php`:**
```php
// Before:
"img-src 'self' data:; "

// After:
"img-src 'self' data: https://*.public.blob.vercel-storage.com; "
```

---

### Pattern 4: One-Shot Schema Migration

**What:** Remove `Schema::ensure()` from the per-request `Database::connection()` path. Move it to a standalone CLI script run once per deployment.

**Why:** On serverless, every cold start currently executes 5+ DDL queries (`SHOW COLUMNS`, `ALTER TABLE`, `CREATE TABLE IF NOT EXISTS`) per request boot. These are cheap on a warm connection but wasteful and inappropriate in a shared managed DB context where DDL requires brief table locks.

**Change to `src/Core/Database.php`:**

Remove the `Schema::ensure(self::$instance)` call from `connection()`. After the change, `connection()` only opens the PDO connection. The connection failure handler (`http_response_code(500); echo...; exit;`) is unchanged.

**New file: `bin/migrate.php`**

Standalone PHP CLI script. Replicates the minimal bootstrap needed (autoloader + env + DB connect + Schema::ensure):
```php
#!/usr/bin/env php
<?php
declare(strict_types=1);
$root = dirname(__DIR__);
spl_autoload_register(static function (string $class) use ($root): void {
    $prefix = 'App\\';
    if (!str_starts_with($class, $prefix)) { return; }
    $file = $root . '/src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (is_file($file)) { require $file; }
});
require $root . '/src/helpers.php';
use App\Core\Env;
use App\Core\Database;
use App\Core\Schema;
Env::load($root . '/.env');
echo 'Running migrations...' . PHP_EOL;
$db = Database::connection(); // connects without Schema::ensure now
Schema::ensure($db);
echo 'Done.' . PHP_EOL;
```

**Add Composer script for convenience** (`composer.json`):
```json
"scripts": {
    "db:migrate": "php bin/migrate.php"
}
```

**Deployment workflow:** Run `php bin/migrate.php` (or `composer db:migrate`) once after the external managed DB is provisioned and env vars are configured. Do not hook into Vercel `buildCommand` — the DB is typically unreachable at build time.

**Sessions table:** Add the sessions table DDL to both `sql/schema.sql` (as reference) and inside `Schema::ensure()` as a new `CREATE TABLE IF NOT EXISTS sessions (...)` block. The migration script will create it on first run.

---

### Pattern 5: Security Headers / HTTPS

**What:** HSTS belongs in `vercel.json` (applied to all responses, including CSS/JS static assets). All other app-specific headers stay in `api/index.php` via `header()` calls.

**`vercel.json` — HSTS for all responses:**
```json
"headers": [
  {
    "source": "/(.*)",
    "headers": [
      { "key": "Strict-Transport-Security", "value": "max-age=31536000; includeSubDomains" }
    ]
  }
]
```

Do not set `preload` until the domain is confirmed for the HSTS preload list.

**`api/index.php` — unchanged headers + additions:**
- Keep: `X-Content-Type-Options`, `X-Frame-Options`, `Referrer-Policy`
- Update CSP: add `https://*.public.blob.vercel-storage.com` to `img-src` (required for uploaded images to load)
- Remove HSTS from PHP headers (already in vercel.json, avoiding duplication)

**Vercel environment variables to set in dashboard:**
- `SESSION_SECURE=1` — `Auth::start()` already reads this; no code change needed
- `BLOB_READ_WRITE_TOKEN` — created automatically when a Blob store is linked to the project
- `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER`, `DB_PASSWORD` — from the managed MySQL provider
- `APP_ENV=production` — optional, for future environment-specific logic

**No `.env` file on Vercel:** `Env::load()` already handles a missing `.env` file gracefully (checks `is_file()` before reading). `Env::get()` calls `getenv()` first, which reads real environment variables. No code change needed.

---

## Data Flow Changes

### Request Flow — Dynamic Pages (After)

```
HTTPS request → Vercel Edge
    ↓
Does /assets/(.*) match a file in public/ ?
    YES → serve static file from CDN (CSS, JS)
    NO  → rewrite to /api/index.php
              ↓
         api/index.php bootstraps:
           Env::load() → reads from real env vars (no .env file in prod)
           session_set_save_handler(new DatabaseSessionHandler())
           Auth::start() → session_start() → DatabaseSessionHandler::open()
                                            → Database::connection() (lazy)
                                            → read() fetches payload from sessions table
           Router::dispatch() → Controller → Models → Views
           Response sent → shutdown function → DatabaseSessionHandler::write()
                                             → REPLACE INTO sessions
```

### Upload Flow — Before vs After

**Before:**
```
Browser multipart POST
    ↓
ProductController::storeUploadedFile($tmp, $size)
    → move_uploaded_file($tmp, '{root}/public/assets/uploads/{hex8}.{ext}')
    → returns '/assets/uploads/{hex8}.{ext}'
    ↓
products.image_path = '/assets/uploads/{hex8}.{ext}'  (relative path in DB)
    ↓
View: <img src="/assets/uploads/{hex8}.{ext}">  (served by Apache)
```

**After:**
```
Browser multipart POST (≤ 2 MB, within Vercel's 4.5 MB limit)
    ↓
ProductController::storeUploadedFile($tmp, $size)
    → BlobStorage::upload($tmp, $size, $mime, '{hex8}.{ext}')
        → PUT https://vercel.com/api/blob?pathname={hex8}.{ext}
          Authorization: Bearer {BLOB_READ_WRITE_TOKEN}
          Content-Type: image/jpeg (or png/webp/gif)
          Body: raw bytes from $tmp
        → Response JSON: { "url": "https://{hash}.public.blob.vercel-storage.com/{hex8}.{ext}" }
    → returns full blob URL
    ↓
products.image_path = 'https://{hash}.public.blob.vercel-storage.com/{hex8}.{ext}'
    ↓
View: <img src="https://{hash}.public.blob.vercel-storage.com/{hex8}.{ext}">
       (served from Vercel CDN, no PHP involved)
```

### Delete Flow — Before vs After

**Before:**
```
ProductController::deleteImage()
    → imageModel->delete($id, $userId)   // removes DB row
    → @unlink('{root}/public' . $image['path'])  // removes local file
```

**After:**
```
ProductController::deleteImage()
    → imageModel->delete($id, $userId)   // removes DB row (unchanged)
    → BlobStorage::delete($image['path'])  // $image['path'] is now the full blob URL
        → POST https://vercel.com/api/blob/delete
          Body: {"urls": ["https://..."]}
```

### Session Flow — Before vs After

**Before (file sessions):**
```
session_start() → read /tmp/sess_{id}
request runs; $_SESSION modified
script ends → write /tmp/sess_{id}
```
On serverless: each function invocation runs on an isolated container. `/tmp/` is per-invocation. Sessions written in invocation A are never visible in invocation B. Auth state is lost between requests.

**After (MySQL sessions):**
```
session_start() → DatabaseSessionHandler::read($id)
    → SELECT payload FROM sessions WHERE id = :id AND last_activity > (now - gc_maxlifetime)
request runs; $_SESSION modified
script ends (shutdown fn) → DatabaseSessionHandler::write($id, $data)
    → REPLACE INTO sessions (id, payload, last_activity) VALUES (:id, :payload, :now)
```
Session data is durable across all serverless invocations because it lives in the shared managed MySQL.

---

## Recommended Project Structure After Changes

Only additions and moves are shown; everything else is unchanged.

```
reselltrack/
├── api/
│   └── index.php          ← MOVED from public/index.php
├── bin/
│   └── migrate.php        ← NEW: one-shot migration runner
├── public/                ← static root (outputDirectory in vercel.json)
│   ├── .htaccess          ← unchanged (local Docker dev only)
│   └── assets/
│       ├── css/style.css  ← unchanged
│       ├── js/app.js      ← unchanged
│       └── uploads/       ← .gitkeep stays; no longer written at runtime
├── src/
│   ├── Core/
│   │   ├── Auth.php           ← MODIFY: register DatabaseSessionHandler in start()
│   │   ├── Database.php       ← MODIFY: remove Schema::ensure() call
│   │   └── DatabaseSession    ← NEW: implements SessionHandlerInterface
│   │       Handler.php
│   └── Services/
│       └── BlobStorage.php    ← NEW: Vercel Blob HTTP upload/delete
├── sql/
│   └── schema.sql         ← ADD: sessions table DDL
├── src/Controllers/
│   └── ProductController  ← MODIFY: storeUploadedFile → BlobStorage::upload();
│       .php                   deleteImage → BlobStorage::delete()
└── vercel.json            ← NEW: routing + runtime + headers config
```

---

## Build Order and Dependencies

The five changes have explicit dependency relationships. Build in this order:

```
[1] Routing (vercel.json + move to api/)
    │
    └──► [2] Schema migration (remove from request path + bin/migrate.php)
         │
         ├──► [3] Session handler (MySQL sessions)
         │        Depends on: managed DB live + sessions table created by step 2
         │
         └──► [4] Blob upload (BlobStorage service + ProductController)
                  Depends on: BLOB_READ_WRITE_TOKEN env var configured
                  Independent of: session handler (can be done in parallel with step 3)
                  │
                  └──► [5] Security hardening (HSTS in vercel.json + CSP update + SESSION_SECURE=1)
                           Depends on: step 4 (need blob domain for CSP img-src)
                           Depends on: step 3 (SESSION_SECURE=1 only meaningful once sessions work)
```

**Why this order:**

1. **Routing first** — nothing works on Vercel until the PHP function is reachable. This is the minimum viable deployment. Validate with a test page before building on top.

2. **Schema migration second** — the managed DB must be provisioned and the schema applied before any other feature can work. Step 2 produces `bin/migrate.php` and makes `Database::connection()` safe to call without DDL side effects.

3. **Sessions third** — `Auth::start()` must work (sessions persist) before the full app is testable end-to-end. CSRF tokens, flash messages, and login all depend on sessions. This is the first real auth-to-auth integration test point.

4. **Uploads fourth** — self-contained change. Can be developed and tested independently once the DB is live. Does not block session work.

5. **Security hardening last** — HSTS + CSP are easy to add but need to know the final blob storage domain (from step 4). `SESSION_SECURE=1` needs sessions working (step 3). Running these last avoids blocking earlier steps on environment configuration.

---

## Integration Points

### External Services

| Service | Integration Pattern | Authentication | Notes |
|---------|---------------------|----------------|-------|
| Managed MySQL (TiDB/Aiven/PlanetScale) | PDO TCP via `DB_HOST:DB_PORT` | `DB_USER` / `DB_PASSWORD` env vars | Requires SSL/TLS; set `PDO::MYSQL_ATTR_SSL_CA` or use `sslmode=required` in DSN if provider mandates it |
| Vercel Blob | HTTP PUT/DELETE via `file_get_contents` + stream context | `BLOB_READ_WRITE_TOKEN` Bearer header | No PHP SDK; implement raw HTTP. Verify current `x-api-version` header at implementation time |
| frankfurter.app | Existing `ExchangeRateService` unchanged | None | Already in CSP `connect-src`; no changes needed |

### Internal Boundaries

| Boundary | Communication | Notes |
|----------|---------------|-------|
| `Auth::start()` ↔ `DatabaseSessionHandler` | `session_set_save_handler()` OOP invocation | Handler must be registered before `session_start()`; order in `index.php` matters |
| `Database::connection()` ↔ `Schema::ensure()` | Direct call removed; now called only from `bin/migrate.php` | Breaking this dependency is the key change in Database.php |
| `ProductController` ↔ `BlobStorage` | Direct static call or instantiation | Consistent with existing pattern (models instantiated with `new`); keep `BlobStorage` as a static-method class matching the `ProfitCalculator` style |
| `vercel.json` headers ↔ `api/index.php` headers | Both emit HTTP response headers | No conflict; Vercel merges them. Avoid duplicating HSTS in PHP — vercel.json already covers it |

---

## Anti-Patterns to Avoid

### Anti-Pattern 1: Using `routes` Instead of `rewrites` in vercel.json

**What people do:** Copy the vercel-community/php README example that uses `"routes": [{"src": "/(.*)", "dest": "/api/index.php"}]`.

**Why it's wrong:** The legacy `routes` API overrides all static file serving. CSS, JS, and any other files in `public/assets/` stop being served as static assets. You must then explicitly add pass-through routes for every static path, which breaks the clean separation.

**Do this instead:** Use `"rewrites"` with `"outputDirectory": "public"`. Vercel's routing pipeline checks for static files first and only invokes the rewrite when no static file matches.

### Anti-Pattern 2: Registering the Session Handler After `session_start()`

**What people do:** Call `session_set_save_handler()` after `session_start()` because the order looks natural.

**Why it's wrong:** The handler must be registered before `session_start()` is called. PHP ignores a handler registered after the session is already active. Sessions silently fall back to file storage, defeating the entire purpose.

**Do this instead:** In `Auth::start()`, call `session_set_save_handler($handler, true)` as the first line, before `session_set_cookie_params()` and `session_start()`.

### Anti-Pattern 3: Keeping Schema::ensure() on the Per-Request Path

**What people do:** Leave `Schema::ensure()` in `Database::connection()` because it "worked fine locally."

**Why it's wrong:** On every serverless cold start, the very first request executes multiple `SHOW COLUMNS FROM ...` queries and potentially `ALTER TABLE` DDL against the managed production DB. This adds ~100ms+ latency to cold starts, holds brief locks on managed DBs with connection limits, and is semantically wrong (migrations should be explicit, not implicit).

**Do this instead:** Remove the `Schema::ensure()` call from `Database::connection()`. Run `php bin/migrate.php` once after DB provisioning and after each schema change during deployment.

### Anti-Pattern 4: Storing Blob URLs Narrower Than VARCHAR(500)

**What people do:** Keep `image_path VARCHAR(255)` and truncate long blob URLs.

**Why it's wrong:** Vercel Blob URLs are currently around 80–120 characters, but the store hash and path can grow. The existing `order_url VARCHAR(500)` in the schema sets the right precedent for external URLs.

**Do this instead:** Alter `products.image_path` and `product_images.path` to `VARCHAR(500)` in the migration script. They are currently `VARCHAR(255)`. Most blob URLs fit today, but this prevents a silent truncation bug if URLs grow. Add to `bin/migrate.php` alongside the sessions table.

---

## Scaling Considerations

| Scale | Consideration |
|-------|--------------|
| 0–100 users | Current architecture is fine. Sessions in MySQL add a DB read+write per request — negligible at this scale. |
| 100–10K users | Session table becomes a write-heavy hotspot. Add `last_activity` index (already in schema above). GC probabilities may need tuning via `session.gc_probability` in `api/php.ini`. |
| 10K+ users | Replace MySQL sessions with Vercel KV (Redis). This is a one-file swap (`DatabaseSessionHandler` → `RedisSessionHandler`). The rest of the application is unaffected. |

---

## Sources

- [vercel-community/php GitHub README](https://github.com/vercel-community/php/blob/master/README.md) — vercel.json format, runtime versions, file structure (HIGH confidence)
- [PHP `session_set_save_handler` manual](https://www.php.net/manual/en/function.session-set-save-handler.php) — registration order, shutdown function flag (HIGH confidence)
- [PHP `SessionHandlerInterface` manual](http://docs.php.net/manual/en/class.sessionhandlerinterface.php) — required method signatures (HIGH confidence)
- [Vercel Blob server upload docs](https://vercel.com/docs/vercel-blob/server-upload) — BLOB_READ_WRITE_TOKEN, 4.5 MB limit, environment variable name (HIGH confidence)
- [Vercel Blob SDK source — helpers.ts](https://github.com/vercel/storage/blob/main/packages/blob/src/helpers.ts) — base API URL `https://vercel.com/api/blob` (MEDIUM confidence — internal SDK detail, may change)
- [Vercel Blob SDK source — api.ts](https://github.com/vercel/storage/blob/main/packages/blob/src/api.ts) — `x-api-version` header, Bearer token pattern (MEDIUM confidence — verify at implementation time)

---

*Architecture research for: ResellTrack — Vercel serverless deployment*
*Researched: 2026-06-12*
