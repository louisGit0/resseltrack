# Feature Research

**Domain:** Serverless deployment of a PHP 8.3 + MySQL MVC app on Vercel
**Researched:** 2026-06-12
**Confidence:** HIGH (table stakes verified against codebase map + Vercel docs; anti-features drawn directly from CONCERNS.md findings)

> **Scope note:** "Features" here means deployment/operational capabilities required to
> make the existing app work on Vercel serverless. No new product features are in scope.
> Everything that works locally must work identically in production.

---

## Feature Landscape

### Table Stakes — Must Work or the Deployed App Is Broken

| Capability | Why Broken Without It | Complexity | Code Area Affected |
|------------|----------------------|------------|-------------------|
| Serverless routing via `vercel.json` | Apache `.htaccess` is not processed by Vercel's PHP runtime; all non-asset requests return 404 | LOW | `public/.htaccess` (superseded), new `vercel.json`, `public/index.php` location may move to `api/index.php` |
| External managed MySQL + env-var DSN | Docker-local MySQL is unavailable on Vercel; app has zero persistent storage without it | LOW (code) / MEDIUM (service + SSL cert) | `src/Core/Database.php`, Vercel project env vars (DB_HOST, DB_PORT, DB_NAME, DB_USER, DB_PASSWORD) |
| One-shot schema migration (not per-request) | `Schema::ensure()` fires DDL on every cold start; concurrent cold starts race on `ALTER TABLE` producing errors; cost per request is unacceptable at serverless scale | MEDIUM | `src/Core/Database.php` (remove `Schema::ensure()` call), `src/Core/Schema.php` (convert to CLI-runnable), new `migrate.php` script, `vercel.json` build command |
| MySQL-backed session handler | PHP file sessions write to `/tmp` which is ephemeral — a different Vercel function instance never sees them; login state is lost between requests | MEDIUM | `src/Core/Auth.php` (`Auth::start()` must register handler before `session_start()`), new `src/Core/SessionHandler.php` implementing `SessionHandlerInterface`, new `sessions` table in schema |
| Image upload to Vercel Blob (no local disk) | `move_uploaded_file()` writes to `public/assets/uploads/` which is ephemeral on Vercel; uploaded images vanish after the function invocation that wrote them | HIGH | `src/Controllers/ProductController.php` (`storeUploadedFile()`, `deleteImage()`, `setCoverImage()`), `src/Models/Product.php` (`image_path` semantics change from relative path to full CDN URL), `src/Models/ProductImage.php` (`path` column), all `<img src>` references in `src/Views/products/` |
| `SESSION_SECURE=1` + HSTS header | Vercel always serves HTTPS; without `Secure` flag the session cookie is misconfigured; without HSTS the browser allows protocol downgrade. Both are production security blockers | LOW | `public/index.php` (add HSTS when `APP_ENV=prod`), `src/Core/Auth.php` (already reads `SESSION_SECURE`), Vercel env var dashboard |
| Secrets exclusively via Vercel env vars | `.env` file must not reach the deployed container; all DB credentials, `BLOB_READ_WRITE_TOKEN`, and session secrets must come from Vercel's environment variable dashboard | LOW | `src/Core/Env.php` (already prioritises real env over file), `.gitignore` (ensure `.env` excluded), Vercel project settings |

**Why these seven and not more:**
Every item maps to a hard failure mode confirmed in CONCERNS.md or the ARCHITECTURE.md data flow: ephemeral FS breaks sessions + uploads; missing `vercel.json` breaks routing; per-request DDL breaks cold starts; insecure cookies break production auth. Removing any one of these leaves a broken deployment.

---

### Differentiators — Nice to Have, Not Blocking

| Capability | Value Proposition | Complexity | Code Area Affected |
|------------|-------------------|------------|-------------------|
| Preview deploys per PR/branch | Every branch push gets a unique `*.vercel.app` URL for manual QA without touching production | LOW (auto from Vercel) / MEDIUM (needs isolated DB per preview) | `vercel.json`, possibly `DB_NAME` driven by `VERCEL_ENV` env var |
| Automatic CI deploy on push to main | No manual deploy step; merge to main = production update | LOW (free, automatic once repo is connected) | Vercel project settings only |
| Custom domain + canonical HTTPS | `reselltrack.example.com` instead of `*.vercel.app`; HSTS already planned | LOW | Vercel dashboard + DNS; HSTS header already in plan |
| Composer build caching | Vercel caches `vendor/` between deploys; reduces cold build time if dependencies are added (e.g. session handler library) | LOW | `composer.json`, `composer.lock` checked in |
| Graceful DB failure page (503 HTML) | `Database.php` currently echoes plain text + exits on connection failure — browsers render it poorly; a real 503 page improves debugging and user experience | LOW | `src/Core/Database.php` (replace `echo + exit` with proper HTTP 503 response) |
| Health check endpoint (`/health`) | Returns 200 + DB ping; enables uptime monitoring and Vercel deployment checks | LOW | New route in `public/index.php`, new action in an existing or new controller |

---

### Anti-Features — Explicitly Do NOT Do These

| Anti-Feature | Why It Seems Reasonable | Why It Breaks Serverless | What to Do Instead |
|--------------|------------------------|--------------------------|-------------------|
| File-based PHP sessions (default) | Sessions "just work" locally with no config | `/tmp` is ephemeral per Vercel function invocation; every cold start is a new process with no session memory | MySQL PDO session handler via `session_set_save_handler()` |
| Local filesystem image storage (`public/assets/uploads/`) | No code change, works in Docker | Ephemeral filesystem — images written to one invocation are gone on the next; gallery shows broken images | Vercel Blob via REST API (PUT to `blob.vercel-storage.com` with `Authorization: Bearer BLOB_READ_WRITE_TOKEN`) |
| `Schema::ensure()` running on every request / cold start | Idempotent DDL "safe" to run repeatedly in Docker (single process) | Multiple simultaneous cold starts race on `ALTER TABLE`; DDL adds latency to every request; documented race condition in CONCERNS.md | One-shot `php migrate.php` CLI run in Vercel build command |
| phpMyAdmin in the Vercel deployment | Useful admin tool in Docker | Cannot be deployed to Vercel's serverless functions; it's a PHP app that requires its own persistence and config | Use the managed MySQL provider's own web console (TiDB Cloud UI, Aiven Console, etc.) |
| Relying on Apache `.htaccess` for routing | Works perfectly with Docker + Apache | Vercel's PHP runtime (`vercel-php@0.9.0`) runs PHP as a CGI function — no Apache process, no `.htaccess` | `vercel.json` catch-all rewrite: `{ "src": "/(.*)", "dest": "/api/index.php" }` |
| Committing `.env` with real credentials | Convenient for local dev | Any credential in git history is compromised; Vercel injects env vars at build/runtime without a file | Set all secrets in Vercel project settings; `.env` stays gitignored; `.env.example` stays as documentation only |
| Storing Vercel Blob URLs as relative paths | Existing `image_path` column stores relative paths like `/assets/uploads/abc.jpg` | After migration, paths are absolute CDN URLs; prepending a base URL again would double the hostname | Store the full `https://xxx.public.blob.vercel-storage.com/abc.jpg` URL in the DB column; views render `<img src="<?= e($path) ?>">` unchanged |
| Caching PDO singleton across invocations | `Database::$instance` static singleton is fine within one PHP process | Each Vercel invocation is a fresh PHP process; the static singleton resets naturally — no issue here, but do not add any assumption of cross-invocation state | No change needed; singleton works correctly scoped to a single invocation |

---

## Feature Dependencies

```
[External MySQL service ready]
    └──required by──> [DB connectivity from functions]
                          └──required by──> [One-shot schema migration]
                          └──required by──> [MySQL session handler]
                          └──required by──> [All CRUD functionality]

[Vercel Blob store created + BLOB_READ_WRITE_TOKEN available]
    └──required by──> [Image upload to Vercel Blob]

[vercel.json routing]
    └──required by──> [All HTTP routing / any feature]

[SESSION_SECURE=1 + HSTS]
    └──required by──> [Safe production auth]
    └──depends on──> [HTTPS termination by Vercel — automatic, no code needed]

[One-shot schema migration]
    └──creates──> [sessions table]
                     └──required by──> [MySQL session handler]
```

### Dependency Notes

- **External MySQL must be provisioned before schema migration can run:** The build command `php migrate.php` connects to the managed DB — if no DB is available, the build fails. Provision the managed MySQL instance as the first action in implementation.
- **Sessions table must exist before deploying the session handler:** `SessionHandler` reads/writes the `sessions` table; if the migration hasn't run, session writes fail with a SQL error on the first login attempt. Sequence: provision DB → run migration (creates sessions table) → deploy app.
- **Image paths in the DB will be a mix of relative and absolute URLs during migration:** Existing products have `/assets/uploads/...` paths; new uploads post-migration get `https://blob.vercel-storage.com/...` URLs. Views must handle both formats during the transition period, or a one-time data migration must update all existing paths. Plan explicitly for this.
- **`vercel.json` routing is a prerequisite for everything:** Without it, no PHP code runs at all on Vercel — the front controller is never reached.

---

## MVP Definition

### Launch With (v1) — All 7 Table Stakes

- [ ] `vercel.json` routing to front controller — without this nothing runs
- [ ] External managed MySQL connectivity — without this no data persists
- [ ] One-shot schema migration — without this concurrent cold starts corrupt schema
- [ ] MySQL-backed session handler — without this login breaks between requests
- [ ] Image upload to Vercel Blob — without this product photos break
- [ ] `SESSION_SECURE=1` + HSTS — without this production is insecure
- [ ] Secrets via Vercel env vars — without this credentials leak

All 7 are blocking. There is no subset of these that constitutes a "partially working" deployment — each failure mode is a complete functional break of one or more existing features.

### Add After the App Works (v1.x)

- [ ] Preview deploy isolation (separate DB schema per VERCEL_ENV) — add once the production deployment is confirmed stable
- [ ] Graceful 503 DB failure page — low-effort polish once the app is live
- [ ] Health check endpoint — useful for uptime monitoring, add after baseline works
- [ ] SRI hashes on CDN resources (Bootstrap) — security hardening, noted in CONCERNS.md, easy to add

### Future Consideration (v2+)

- [ ] Custom domain — requires DNS access; defer until core deployment is validated
- [ ] CSRF token rotation after use — security hardening noted in CONCERNS.md; not a deployment blocker
- [ ] Rate limiting on register + export endpoints — security hardening; not a deployment blocker

---

## Feature Prioritization Matrix

| Capability | Deployment Value | Implementation Cost | Priority |
|------------|-----------------|---------------------|----------|
| `vercel.json` routing | HIGH — prerequisite for everything | LOW | P1 |
| External MySQL + env vars | HIGH — all CRUD dead without it | LOW–MEDIUM | P1 |
| One-shot schema migration | HIGH — concurrent cold start safety | MEDIUM | P1 |
| MySQL session handler | HIGH — login broken without it | MEDIUM | P1 |
| Image upload to Vercel Blob | HIGH — product photos broken without it | HIGH | P1 |
| `SESSION_SECURE=1` + HSTS | HIGH — production security requirement | LOW | P1 |
| Secrets via Vercel env vars | HIGH — credentials safety | LOW | P1 |
| Graceful 503 DB failure page | LOW — UX polish | LOW | P2 |
| Preview deploy DB isolation | MEDIUM — staging hygiene | MEDIUM | P2 |
| Health check endpoint | LOW–MEDIUM — observability | LOW | P2 |
| Custom domain | LOW — cosmetic | LOW | P3 |

**Priority key:**
- P1: Required for a functional deployment — must complete before declaring the milestone done
- P2: Should have — add after P1 items are confirmed working end-to-end
- P3: Nice to have — defer to a future milestone

---

## Sources

- [vercel-community/php GitHub](https://github.com/vercel-community/php) — PHP 8.3–8.5 serverless runtime, `vercel.json` configuration patterns, currently at v0.9.0
- [Vercel Blob server uploads](https://vercel.com/docs/vercel-blob/server-upload) — 4.5 MB server-side limit; REST API authenticated with `BLOB_READ_WRITE_TOKEN`
- [Vercel Blob PHP SDK issue #511](https://github.com/vercel-community/php/issues/511) — no official PHP SDK; REST API must be called directly with `PUT` + `Authorization: Bearer` header
- [Symfony PdoSessionHandler](https://github.com/symfony/http-foundation/blob/7.3/Session/Storage/Handler/PdoSessionHandler.php) — reference implementation for `SessionHandlerInterface` with MySQL; either borrow the pattern or use `symfony/http-foundation` as a dependency
- `.planning/codebase/CONCERNS.md` — `Schema::ensure()` race condition (MEDIUM), `SESSION_SECURE=0` default (LOW), missing HSTS (LOW), phpMyAdmin exposure (LOW)
- `.planning/codebase/ARCHITECTURE.md` — session lifecycle in `Auth::start()`, image upload flow in `ProductController::storeUploadedFile()`, `Database::connection()` PDO singleton, `.htaccess` rewrite pattern

---
*Feature research for: Vercel deployment of ResellTrack (PHP 8.3 + MySQL)*
*Researched: 2026-06-12*
