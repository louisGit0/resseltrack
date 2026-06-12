# Pitfalls Research

**Domain:** PHP 8.3 + MySQL app (ResellTrack) deployed to Vercel serverless
**Researched:** 2026-06-12
**Confidence:** HIGH (cross-referenced against codebase analysis in CONCERNS.md and official Vercel docs)

---

## Critical Pitfalls

### Pitfall 1: Ephemeral Filesystem — Uploads and Sessions Silently Die

**What goes wrong:**
`public/assets/uploads/` does not exist in a persistent state on Vercel. The filesystem is read-only except for `/tmp`, and `/tmp` is scoped to the function instance — a different invocation (even seconds later) gets a clean `/tmp`. Any image written during an upload either fails immediately (if the code tries to write outside `/tmp`) or succeeds into `/tmp` but is then invisible to the next request that serves the image URL. The result is images that upload "successfully" but return 404 when the browser tries to display them.

The exact same problem applies to PHP file-based sessions. PHP's default `session.save_handler = files` writes to a directory on disk. On Vercel, that directory is either the read-only root (error on write) or `/tmp` (write succeeds but the next request — possibly routed to a different Lambda instance — finds no session file). Users appear logged in for one page load then get redirected to `/login` on the next, or CSRF tokens are lost between form render and form submit, causing 419 errors.

**Why it happens:**
The app was built for Apache on a persistent filesystem (Docker volume). `ProductController::storeUploadedFile()` writes to `public/assets/uploads/` and returns a path relative to `public/`. This path is stored in the DB and served as `/assets/uploads/{filename}`. Nothing in this path is aware of object storage. `Auth::start()` calls `session_start()` with no custom handler, so PHP defaults to files.

**How to avoid:**
- Image uploads: replace `storeUploadedFile()` with a Vercel Blob upload (PUT to the Blob API using a PHP HTTP client or `curl`). Store the returned HTTPS URL in the `product_images` table instead of a relative path. Serve the stored URL directly in views.
- Sessions: implement `session_set_save_handler()` with a MySQL-backed handler, or use PHP's built-in `session.save_handler = pdo` option available in the community runtime. The `sessions` table schema needs `id`, `data`, `last_activity` columns. The `Database::connection()` singleton used elsewhere can back this handler.

**Warning signs:**
- Images 404 immediately after upload on the deployed site but work locally
- Users log out between page loads (especially after Vercel scales to more than one instance)
- Intermittent 419 CSRF errors on form submissions without any user action
- `session_start()` emitting warnings about failed directory creation (surfaced only if error logging is on)

**Phase to address:** Infrastructure setup — must be solved before any functional testing. Every authenticated feature and every image-bearing page is broken until both are fixed.

---

### Pitfall 2: Per-Request DDL (Schema::ensure) — Race Conditions and Latency on Cold Starts

**What goes wrong:**
`Database::connection()` calls `Schema::ensure()` on the first DB connection of every request. `Schema::ensure()` issues `SHOW COLUMNS` checks and may fire `ALTER TABLE` or `CREATE TABLE IF NOT EXISTS` DDL. On a warm Vercel instance (same Lambda reused) this is merely wasteful. On cold starts — which happen concurrently when traffic spikes or after a deploy — multiple PHP processes reach `Schema::ensure()` simultaneously. MySQL's `ALTER TABLE IF NOT EXISTS` is not fully atomic across concurrent sessions; two concurrent ALTERs on the same column produce a race where one succeeds and the other either throws a duplicate-column error or corrupts a mid-flight schema state.

Even without the race, the DDL path adds 50–200 ms to every cold-start request: two `SHOW COLUMNS` round-trips to a remote managed DB (add TLS handshake overhead) on top of the cold-start PDO connect time.

CONCERNS.md explicitly flags this: *"`Schema::ensure()` cannot be safely run in parallel (No Distributed Lock)"*.

**Why it happens:**
The hand-rolled migration system was designed for a single Docker container where workers start sequentially. The assumption of a single shared process pool (FPM) does not hold for serverless, where Lambda instances are independent OS processes that can start simultaneously.

**How to avoid:**
1. Extract `Schema::ensure()` entirely from the request path. Remove the call from `Database::connection()`.
2. Create a standalone CLI script (e.g. `bin/migrate.php`) that runs the same DDL, guarded by a MySQL advisory lock (`GET_LOCK('schema_migrate', 10)`).
3. Add this script to the Vercel deploy pipeline as a `postBuild` hook or a pre-deploy GitHub Action step. It runs once, against the target DB, before any Lambda instance starts serving traffic.
4. For the long term: adopt a numbered-files migration runner (Phinx or a minimal custom runner) so schema state is versioned and repeatable.

**Warning signs:**
- "Duplicate column" errors in Vercel function logs on the first deploy
- Intermittent 500 errors on cold starts that self-resolve on retry
- Every cold-start request takes 200+ ms more than warm requests (SQL profiling shows DDL queries)

**Phase to address:** Database setup phase — must be solved before the first Vercel deployment to a managed DB. The migration command must be established and the `Database::connection()` call to `Schema::ensure()` removed before go-live.

---

### Pitfall 3: MySQL Connection Exhaustion from Per-Invocation PDO Connects

**What goes wrong:**
`Database::$instance` is a static PDO singleton — but "singleton" means *per PHP process*. On Vercel, each serverless Lambda invocation may be a fresh process. With significant traffic or burst scale-out, every concurrent invocation opens a new TCP+TLS connection to the managed MySQL DB. Free/low-tier managed databases (TiDB Cloud Serverless free: 5 connections, Aiven free: 5 connections) hit their connection ceiling almost immediately under any real load. The symptom is "Too many connections" PDO exceptions, which in this app cause `Database::connection()` to `exit` with a plain-text 500 and no HTML page.

Even at low concurrency, each cold-start must complete a full TLS handshake to the remote DB (~100–300 ms depending on region match). A slow query during this phase can cascade into a timeout. Vercel Hobby plan default function timeout is 10 seconds (confirmed as 300s on current plans with Fluid Compute, but the community PHP runtime may have different caps).

CONCERNS.md flags the N+1 in `SaleController::productsMeta()`: `1 + N×3` queries per product. On serverless, each query has the additional overhead of a remote managed-DB round-trip. With 20 products that is 61 queries, each potentially 5–50 ms to a US-east managed DB. This alone can exceed 1–3 seconds per request.

**How to avoid:**
- Choose a managed MySQL provider with a built-in connection proxy/pooler. PlanetScale routes all connections through Vitess which absorbs connection count. Aiven MySQL supports PgBouncer-equivalent pooling. TiDB Cloud Serverless has a connection proxy but its free tier is very limited.
- Alternatively, add ProxySQL or PlanetScale's connection proxy in front of Aiven/TiDB.
- Do not use the Vercel `@vercel/functions` connection pooling API — it is Node.js-specific and has no PHP equivalent.
- Immediately address the N+1 in `SaleController::productsMeta()`: replace with a single aggregated SQL query (flagged HIGH in CONCERNS.md). This is not optional on serverless — it will cause timeouts.
- Add `waitTimeout` / `connectTimeout` PDO options so connection failures throw catchable exceptions rather than hanging until Vercel's function timeout fires.

**Warning signs:**
- "Too many connections" in Vercel function logs
- 504 FUNCTION_INVOCATION_TIMEOUT on the sale-create or product-list pages under any load
- Dashboard page timing out when a user has more than ~30 products
- DB provider dashboard showing connection count hitting its maximum

**Phase to address:** Database setup phase (choose provider + pooling config) + Performance adaptation phase (fix N+1 before go-live, not as future tech debt).

---

### Pitfall 4: SSL/TLS CA Verification Failure for Managed MySQL

**What goes wrong:**
Every current managed MySQL provider (TiDB Cloud, Aiven, PlanetScale) requires TLS. The PDO DSN must include TLS options. The CA certificate bundle path that works in the Docker container (`/etc/ssl/certs/ca-certificates.crt` on Debian) may differ from the path on Vercel's Lambda environment (Amazon Linux). If the path is wrong or omitted, PDO either:
- Refuses to connect (if `PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT => true` is set and the CA is not found)
- Connects insecurely without verification (if the option is absent), which providers like TiDB may reject server-side

For PlanetScale specifically, the DSN must include `PDO::MYSQL_ATTR_SSL_CA` pointing to the system bundle or a bundled CA file. For TiDB Cloud, TLS 1.2+ is required; TLS 1.0/1.1 connections are rejected.

`Database::connection()` currently has no TLS DSN options at all — it connects plaintext, which works inside Docker on a trusted network but will be rejected by all managed providers.

**How to avoid:**
- Add TLS PDO attributes to `Database::connection()`: `PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT => true` and `PDO::MYSQL_ATTR_SSL_CA => '/etc/ssl/certs/ca-certificates.crt'` (Amazon Linux path, verify on Vercel's runtime).
- Test the CA path by adding a health-check endpoint that reports `openssl_get_cert_locations()`.
- For PlanetScale's proxy, the CA bundle path on Lambda is `/etc/pki/tls/certs/ca-bundle.crt` (Amazon Linux) rather than the Debian path. Bundle the CA file with the deployment if uncertain.
- Set a `PDO::ATTR_TIMEOUT` (connect timeout) to prevent hanging on TLS negotiation failures.

**Warning signs:**
- "SSL connection error" or "Access denied" (can be misleading) in Vercel function logs
- Connection works in Docker with the same credentials but fails on Vercel
- PDO constructor throwing on first request, causing the `exit` path in `Database::connection()`

**Phase to address:** Database setup phase — must be tested in the actual Vercel Lambda environment, not just locally. A quick health-check route that does `Database::connection()` and returns "OK" is the fastest verification.

---

## Moderate Pitfalls

### Pitfall 5: SESSION_SECURE=0 in Production — Cookie Rejected or Leaked

**What goes wrong:**
`SESSION_SECURE=0` is the default in `.env.example` and is matched by the Docker defaults (CONCERNS.md flags this as LOW). On Vercel (HTTPS-only), the session cookie must have the `Secure` flag or modern browsers may refuse to set it in some contexts. More critically: `SESSION_SECURE=0` means the cookie is also sent over HTTP. Since Vercel serves exclusively over HTTPS, this is not an immediate exploit, but it disables a critical defense layer.

The CSRF token is stored in `$_SESSION['_csrf_token']`. If the session is absent or corrupted (see Pitfall 1), every form submission returns HTTP 419. If `SESSION_SECURE` is left at 0, the session cookie is also not `SameSite=Strict`-safe for cross-origin navigation flows.

Additionally, `SameSite=Lax` (the current setting) is fine for most navigation but will reject the session cookie on cross-origin POST requests — relevant if the Vercel preview URL (e.g. `reselltrack-git-main.vercel.app`) is different from the production domain.

**How to avoid:**
- Set `SESSION_SECURE=1` in Vercel environment variables (never in the committed `.env` file).
- Add a hard fail at boot: in `public/index.php`, after `Env::load()`, assert `APP_ENV !== 'production' || getenv('SESSION_SECURE') === '1'` and emit an HTTP 500 with a clear error message if violated.
- Add `Strict-Transport-Security` header when `SESSION_SECURE=1` (CONCERNS.md flags missing HSTS as LOW, but on a production HTTPS deployment this becomes HIGH).
- Rotate the CSRF token after each successful validation (CONCERNS.md MEDIUM issue) — on serverless this matters more because session state is more fragile.

**Warning signs:**
- Forms submit but produce 419 CSRF errors on first deployment
- Browser devtools shows session cookie without `Secure` flag on HTTPS site
- Security scanner (e.g. `securityheaders.com`) reports missing HSTS and insecure cookie flags

**Phase to address:** Configuration/security hardening phase — must be set before the first real-user access, ideally enforced as a boot assertion.

---

### Pitfall 6: vercel.json Routing — Static Assets Routed Through PHP, .htaccess Ignored

**What goes wrong:**
Vercel ignores Apache's `.htaccess` entirely. The front controller rewrite must be re-expressed in `vercel.json`. The common mistake is a single catch-all rewrite that routes everything — including CSS, JS, and font files — through the PHP Lambda function. Each Bootstrap CSS request, each Chart.js request, each icon font request becomes a serverless function invocation. This burns through Vercel's free-tier function execution limits, adds 100+ ms of cold-start latency to every asset, and can exhaust the 1,024 file descriptor limit.

The correct pattern requires ordering: static files are served by Vercel's CDN first; only requests that don't match a static asset fall through to PHP. The `vercel.json` routes array processes entries in order — static routes before the PHP catch-all.

Route ordering inside the PHP router also becomes load-bearing: the custom Router iterates entries and returns the first match. Static segments like `/products/create` must appear before `/products/{id}`. CONCERNS.md documents this explicitly as a fragile area.

**How to avoid:**
```json
{
  "functions": { "api/index.php": { "runtime": "vercel-php@0.9.0" } },
  "routes": [
    { "src": "^/assets/(.*)", "dest": "/assets/$1" },
    { "src": "^/(.*\\.(css|js|png|jpg|gif|ico|svg|woff2?))", "dest": "/$1" },
    { "src": "/(.*)", "dest": "/api/index.php" }
  ]
}
```
Serve `public/` as the root directory via `vercel.json`'s `"outputDirectory": "public"`. Route static asset extensions directly; route everything else to the PHP entry point.

Verify the existing route ordering in `public/index.php`: `/products/create` before `/products/{id}`, `/sales/create` before `/sales/{id}`, etc. This ordering was correct locally but must survive the move to `api/index.php` without any reordering.

**Warning signs:**
- CSS/JS assets return the PHP 404 "Page introuvable" message instead of file content
- Vercel function invocation count is 10–50x the expected page-view count
- Browser console shows 404 for Bootstrap and Chart.js
- Hot-reload of the page randomly loses styles (different Lambda instance, different state)

**Phase to address:** Routing configuration phase — the very first step of the Vercel adaptation, before any other work.

---

### Pitfall 7: ExchangeRateService — Silent Failure, Timeout Risk, Suppressed Errors

**What goes wrong:**
`ExchangeRateService::getRate()` uses `@file_get_contents('https://api.frankfurter.app/...')`. The `@` operator swallows all PHP warnings. If `allow_url_fopen` is disabled (possible via `api/php.ini` misconfiguration) or if frankfurter.app is unreliable, the function returns `null`. The controller that calls it does `floatval($rate ?? 0)` — purchases then save with `0.00` EUR cost, silently destroying all profitability data. No error is logged.

PHP's default `default_socket_timeout = 60` means a hanging frankfurter.app connection keeps the Lambda running for up to 60 seconds. Vercel Hobby has a 10-second function timeout (older documentation) — newer Vercel plans with Fluid Compute allow up to 300s, but community PHP runtime limits may differ. A slow external HTTP call is the most likely cause of FUNCTION_INVOCATION_TIMEOUT on the purchase-create endpoint.

`file_get_contents()` for HTTP also lacks proper timeout configuration. A 30-second stall on a purchase form submission is unacceptable UX.

CONCERNS.md flags the `@` operator as LOW severity in a local context, but on serverless it becomes a production data integrity risk.

**How to avoid:**
- Replace `@file_get_contents()` with a `curl` call that has explicit connect timeout (3s) and total timeout (5s):
  ```php
  $ch = curl_init($url);
  curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
  curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 3);
  curl_setopt($ch, CURLOPT_TIMEOUT, 5);
  $response = curl_exec($ch);
  $error = curl_error($ch);
  curl_close($ch);
  ```
- Log the error explicitly on failure: `error_log("ExchangeRateService failed: $error")`.
- Return `null` on failure and surface a user-visible warning in the purchase form ("Taux de change indisponible — saisir manuellement").
- Verify `allow_url_fopen = On` and `extension=curl` in `api/php.ini` (both are on by default in vercel-community/php but must not be overridden).

**Warning signs:**
- All purchases show `0.00 EUR` for purchase cost
- No error logged anywhere for exchange rate failures
- 504 FUNCTION_INVOCATION_TIMEOUT on the purchase create endpoint
- Function execution logs show invocations taking >5s

**Phase to address:** External services configuration phase — fix before deploying purchase functionality. The timeout fix is mandatory; the error visibility improvement is also mandatory for data integrity.

---

### Pitfall 8: 4.5 MB Request Body Limit — Large Image Uploads Fail Silently

**What goes wrong:**
Vercel enforces a hard 4.5 MB limit on function request bodies. Image uploads go through the PHP Lambda function (multipart/form-data). Any image larger than ~4 MB returns HTTP 413 `FUNCTION_PAYLOAD_TOO_LARGE` before PHP even sees the request. The app has no file size validation at the PHP level that matches this constraint — `storeUploadedFile()` checks MIME type but not byte size. Users uploading a phone photo (3–12 MB) will get a generic 413 error with no explanation.

**How to avoid:**
- Add an explicit `filesize` check in `ProductController::storeUploadedFile()`: reject files larger than 3.5 MB (leave headroom for multipart overhead) with a French error message matching the app's existing flash pattern.
- Add `max_filesize` and `post_max_size` in `api/php.ini` to match (e.g. `upload_max_filesize = 3M`, `post_max_size = 4M`).
- For larger images, implement client-side upload directly to Vercel Blob (bypasses the Lambda body limit entirely). This is the correct long-term architecture once Pitfall 1 is resolved.

**Warning signs:**
- Image upload returns a blank page or raw JSON error instead of the app's styled error flash
- Vercel function logs show `FUNCTION_PAYLOAD_TOO_LARGE` (413)
- Works locally (no size limit in Docker) but fails on Vercel

**Phase to address:** Image migration phase (Vercel Blob) — the size check is a stopgap that must land before image uploads go live.

---

### Pitfall 9: Unbounded Queries + Export Timeout Under Serverless Constraints

**What goes wrong:**
Three areas combine badly on serverless:

1. `SaleController::productsMeta()` issues `1 + N×3` queries (flagged HIGH in CONCERNS.md). With 20 products and a 50 ms round-trip to a US-east managed DB, this is 60+ queries × 50 ms = 3+ seconds just for the sale-create form. Vercel functions timeout at a platform-defined limit; even at 300s this is a terrible user experience and will fail under any burst load.

2. `ExportController` fetches entire tables with no `LIMIT`. A user with 5,000 sales rows loading a CSV export will hold a Lambda alive for the full query + CSV generation time, potentially exhausting memory (`2 GB default`) and timing out.

3. `Product::searchForUser()` returns all products without `LIMIT`; sorting, filtering, and pagination happen in PHP. Large catalogs load fully into Lambda memory on every product list page.

**How to avoid:**
- Fix `SaleController::productsMeta()` N+1 before first production deployment — this is not optional tech debt on serverless. Replace with a single aggregated query.
- Add streaming CSV export: use `fwrite(fopen('php://output', 'w'), ...)` row-by-row with `PDO::FETCH_LAZY` or a cursor, flushing headers before the query runs.
- Add `LIMIT`/`OFFSET` to `Product::searchForUser()` and move sort to SQL for the most common sort columns.
- Set `PDO::ATTR_TIMEOUT` (statement timeout) to catch runaway queries before the function timeout fires.

**Warning signs:**
- 504 on sale-create or product-list pages for users with many records
- Memory exhausted errors in Vercel logs on export endpoints
- Dashboard page timing out after users add more than ~50 products

**Phase to address:** Performance adaptation phase — the N+1 fix must happen before the sale create form is usable in production. Export streaming can follow in a subsequent iteration.

---

## Technical Debt Patterns

| Shortcut | Immediate Benefit | Long-term Cost | When Acceptable |
|----------|-------------------|----------------|-----------------|
| Keep `Schema::ensure()` on every request, just add a MySQL advisory lock | Avoids rewriting migration strategy | DDL on every cold-start, ~200 ms latency spike, contention risk remains | Never — remove from request path entirely |
| Use `/tmp` for sessions instead of MySQL | No session handler code to write | Sessions lost between Lambda instances, users logged out randomly | Never on serverless |
| Use `/tmp` for uploaded images | No Vercel Blob integration needed | Images 404 on every second request (different instance) | Never — /tmp is not shared |
| Keep `@file_get_contents()` for exchange rate | No curl changes | Silent 0.00 EUR data corruption, no timeout control, undebuggable | Never in production |
| Route all requests (including assets) through PHP Lambda | Simple vercel.json | Assets served via Lambda, wasted invocations, high latency for CSS/JS | Never |
| Keep default MySQL credentials matching `.env.example` | No credential rotation work | Well-known password in production, brute-forceable | Never in production |
| Deploy with `SESSION_SECURE=0` | No config change needed | Session cookies not Secure-flagged on HTTPS, missing HSTS | Never in production |

---

## Integration Gotchas

| Integration | Common Mistake | Correct Approach |
|-------------|----------------|------------------|
| Managed MySQL (TiDB/Aiven/PlanetScale) | No TLS options in PDO DSN, plaintext connect fails | Add `PDO::MYSQL_ATTR_SSL_CA` and `PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT` with correct Amazon Linux CA bundle path (`/etc/ssl/certs/ca-certificates.crt` or `/etc/pki/tls/certs/ca-bundle.crt`) |
| Vercel Blob | Using JS SDK examples, no PHP equivalent found | Use Vercel Blob REST API via PHP `curl` — `PUT https://blob.vercel-storage.com/{filename}` with `Authorization: Bearer $BLOB_READ_WRITE_TOKEN` header |
| frankfurter.app (ExchangeRateService) | `file_get_contents` with no timeout, `@` suppressing errors | Replace with `curl` + explicit 5s timeout + explicit error logging |
| MySQL session handler | Using PHP's built-in file session handler | Implement `session_set_save_handler()` with the existing PDO singleton; requires `sessions` table created in the one-shot migration |
| Vercel environment variables | Putting secrets in `.env` committed to repo | Set all DB credentials, `SESSION_SECRET`, `BLOB_READ_WRITE_TOKEN` in Vercel dashboard; `Env::load()` already prioritizes real env vars over `.env` file values |
| Vercel `vercel.json` rewrites | Single `"src": "/(.*)"` catch-all routes assets through PHP | Explicit static extension passthrough rules before the PHP catch-all |

---

## Performance Traps

| Trap | Symptoms | Prevention | When It Breaks |
|------|----------|------------|----------------|
| N+1 in `SaleController::productsMeta()` (`1 + N×3` queries) | 504 on sale-create; 3–10s response for 20+ products | Replace with single aggregated SQL query before first deploy | Any user with more than 10 products |
| PDO connect per Lambda invocation (no cross-request pooling) | High DB connection count; connection refusals under burst | Choose provider with connection proxy; optimize queries to minimize connection open time | 5+ concurrent users on free-tier DB |
| `Product::searchForUser()` unbounded + PHP-side sort/filter/paginate | Lambda memory spike; 504 on large catalogs | Add SQL `LIMIT`/`OFFSET` and SQL-level sort for common columns | Any user with more than ~200 products |
| `ExportController` full table fetch | Lambda OOM or 504 on large data sets | Stream CSV row-by-row | Any user with 1,000+ records |
| Static assets routed through PHP Lambda | 10–50x higher invocation count; CSS/JS served with 100–300ms cold-start latency | Explicit static extension passthrough in `vercel.json` | From first deployment |
| `Schema::ensure()` DDL on every cold-start | 200 ms+ extra latency on cold starts; race conditions on concurrent scale-out | Extract to one-shot deploy-time migration | From first deployment |

---

## Security Mistakes

| Mistake | Risk | Prevention |
|---------|------|------------|
| `SESSION_SECURE=0` deployed to HTTPS Vercel | Session cookie not Secure-flagged; missing HSTS allows protocol downgrade | `SESSION_SECURE=1` in Vercel env vars; add boot assertion; emit HSTS header when `SESSION_SECURE=1` |
| Default `.env.example` DB password (`reselltrack`) used in production | Well-known credential; any leaked DSN gives full DB access | Set strong random DB password in Vercel env vars; add boot warning when `APP_ENV=prod` and `DB_PASSWORD` matches the known default |
| CSRF token never rotated after use | Long-lived token increases exposure window if session leaks | Rotate `$_SESSION['_csrf_token']` in `Csrf::validate()` after each successful check (MEDIUM in CONCERNS.md — becomes more important with MySQL-backed sessions) |
| `Throwable` re-thrown in `SaleController::store()` without a global handler | Stack traces with file paths leaked to browser in production | Register a global exception handler in `public/index.php` before route dispatch that logs full trace and renders a styled 500 page |
| CDN resources (Bootstrap, Chart.js) without SRI | Compromised CDN injects malicious code | Add `integrity="sha384-..."` and `crossorigin="anonymous"` to all CDN `<link>` and `<script>` tags |
| `.env` file potentially committed or present on Vercel build | DB credentials and session secret in plaintext | Verify `.env` is in `.gitignore`; Vercel's build container respects `.gitignore`; set all secrets via Vercel dashboard env vars only |

---

## "Looks Done But Isn't" Checklist

- [ ] **Image upload:** Appears to work (upload returns success, no error) but images 404 on next page load — verify by refreshing the product page after upload on the deployed site
- [ ] **Sessions:** User appears logged in immediately after login but is redirected to `/login` on the next click — verify by logging in and navigating to two successive pages
- [ ] **CSRF:** Forms submit on first load but fail with 419 on retry — verify by submitting the same form twice within one session
- [ ] **Exchange rate:** Purchase creation appears to succeed but shows `0.00` EUR cost in the list — verify with a live purchase that uses USD-to-EUR conversion
- [ ] **Static assets:** Page loads but CSS is missing — verify that `vercel.json` routes pass asset extensions directly without hitting PHP
- [ ] **DB connection:** Site works for a single request but fails under two simultaneous requests — verify with `ab -c 5 -n 20` against the deployed URL
- [ ] **Schema migration:** First deploy works but a second deploy with no code change fails due to race in `Schema::ensure()` — verify by deploying twice and checking logs
- [ ] **SSL to DB:** Connection works in Docker but fails on Vercel — verify by checking for SSL errors in Vercel function logs on the very first request after deployment
- [ ] **SESSION_SECURE=1:** Check browser devtools after login — session cookie must show `Secure; HttpOnly; SameSite=Lax` flags
- [ ] **Export timeout:** CSV export works for 10 records but 504s for 1,000 — verify with a test account that has sufficient data

---

## Recovery Strategies

| Pitfall | Recovery Cost | Recovery Steps |
|---------|---------------|----------------|
| Images stored in /tmp, now 404 | HIGH | Add Vercel Blob integration; re-upload all existing product images from local Docker DB; update `product_images.image_path` records to Blob URLs |
| Sessions file-based, users lose login | MEDIUM | Implement MySQL session handler; existing sessions are lost (users must re-login, one-time acceptable) |
| Schema::ensure() race corrupted DB schema | HIGH | Restore from managed DB snapshot; run one-shot migration manually; remove Schema::ensure() from request path |
| Default DB credentials in production | CRITICAL | Immediately rotate DB password in provider dashboard and Vercel env vars; audit access logs for unauthorized queries |
| N+1 causing timeouts in production | MEDIUM | Fix `SaleController::productsMeta()` query; redeploy; no data loss |
| Exchange rate silent null, 0.00 EUR purchases | HIGH | Fix ExchangeRateService; manually audit and correct affected purchase records; no automated fix available |

---

## Pitfall-to-Phase Mapping

| Pitfall | Prevention Phase | Verification |
|---------|------------------|--------------|
| Ephemeral filesystem (images + sessions) | Infrastructure setup (Vercel Blob + MySQL session handler) | Upload an image, reload; login, navigate to two pages without re-logging in |
| Schema::ensure() per-request DDL | Database setup (one-shot migration script) | Two simultaneous cold-start requests produce no DDL errors in logs |
| MySQL connection exhaustion | Database setup (provider selection + pooling config) | `ab -c 10 -n 50` against the deployed URL shows no "Too many connections" errors |
| SSL/TLS CA verification | Database setup (PDO TLS options) | First request after deployment succeeds; no SSL errors in logs |
| SESSION_SECURE=0 in production | Configuration/security hardening | Browser devtools shows `Secure` flag on session cookie; `securityheaders.com` reports HSTS present |
| vercel.json routing / .htaccess replacement | Routing configuration (first Vercel step) | CSS and JS load correctly; all app routes return correct responses |
| ExchangeRateService silent failure + timeout | External services configuration | Purchase with USD amount shows correct EUR cost; frankfurter.app outage shows user-visible warning, not 0.00 EUR |
| 4.5 MB image upload limit | Image migration phase | Upload a 5 MB image — receives French error message; upload a 2 MB image succeeds |
| N+1 + export timeout | Performance adaptation | Sale-create page loads in under 2s with 50 products; export CSV with 1,000 rows completes without 504 |
| Default credentials / SESSION_SECURE=0 | Configuration/security hardening | Boot assertion fails with clear error if `APP_ENV=prod` and `SESSION_SECURE=0` or `DB_PASSWORD=reselltrack` |

---

## Sources

- Vercel Functions Limits (official, 2026-06-02): https://vercel.com/docs/functions/limitations
- vercel-community/php GitHub README: https://github.com/vercel-community/php
- Vercel connection pooling with serverless functions: https://vercel.com/guides/connection-pooling-with-serverless-functions
- Vercel Blob documentation: https://vercel.com/docs/vercel-blob
- Vercel rewrites: https://vercel.com/docs/routing/rewrites
- PlanetScale PHP PDO SSL connection: https://dev.to/iamluisj/connecting-to-planetscale-with-a-pdo-object-in-php-2h09
- TiDB Cloud TLS requirements: https://docs.pingcap.com/tidbcloud/secure-connections-to-serverless-clusters/
- Vercel /tmp ephemeral storage: https://community.vercel.com/t/how-can-i-use-tmp-directory/1895
- ResellTrack CONCERNS.md (2026-06-12) — cross-referenced for N+1, Schema::ensure(), SESSION_SECURE, ExchangeRateService @ operator, CSRF rotation, HSTS

---
*Pitfalls research for: PHP 8.3 + MySQL app (ResellTrack) deployed to Vercel serverless*
*Researched: 2026-06-12*
