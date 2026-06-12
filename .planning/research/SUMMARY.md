# Project Research Summary

**Project:** ResellTrack — Vercel Serverless Deployment
**Domain:** PHP 8.3 + MySQL MVC monolith adapted for Vercel serverless
**Researched:** 2026-06-12
**Confidence:** HIGH (routing, sessions, R2 storage, Aiven MySQL, Vercel env vars) / MEDIUM (free-tier connection limits, Aiven concurrent connection ceiling)

---

## Executive Summary

ResellTrack is a self-hosted resell-tracking app (PHP 8.3 + MySQL 8, Apache, Docker) that must be lifted onto Vercel serverless without rewriting the application. The core challenge is that three foundational assumptions of the current stack — persistent filesystem, a single long-lived process, and per-request DDL — are all incompatible with serverless. The recommended approach is surgical: add a `vercel.json` front-controller rewrite, swap local-filesystem I/O (sessions and images) for durable external services, and extract the schema migration to a one-shot CLI command. Every existing feature is preserved; the application code changes are limited to five files and two new service classes.

The two highest-risk items are the ephemeral filesystem (silently breaking both image uploads and PHP sessions) and the per-request `Schema::ensure()` call (racing on concurrent cold starts). Both must be resolved before the first user accesses the deployed site — they are not "deploy first, fix later" items. Connection exhaustion on free-tier managed databases is the third structural risk: the chosen provider and the N+1 query pattern in `SaleController::productsMeta()` together determine whether the site survives even light concurrent traffic.

The deployment will succeed cleanly if the implementation follows the dependency-ordered build sequence: routing first, database second, sessions third, object storage fourth, security hardening last. Skipping or reordering these steps introduces failures that are expensive to debug against a live serverless environment.

---

## Confirmed Technology Decisions

> **ACTION REQUIRED:** Two decisions in `PROJECT.md` are listed as "— Pending". Research has produced clear recommendations. Confirm or override both before roadmap creation, then update the Key Decisions table in `PROJECT.md`.

### Decision 1: Object Storage — Cloudflare R2 (NOT Vercel Blob)

**Recommendation: Cloudflare R2 + `aws/aws-sdk-php` v3**

PROJECT.md currently reads: *"Upload d'images via Vercel Blob"*. Research shows this is the wrong choice for a PHP backend.

| Factor | Cloudflare R2 | Vercel Blob |
|--------|--------------|-------------|
| PHP SDK | Official `aws/aws-sdk-php` v3 (S3-compatible) | None — no official PHP SDK |
| PHP integration | `composer require aws/aws-sdk-php:^3`; typed, documented, maintainable | Raw HTTP PUT via `curl`; hand-rolled, undocumented, fragile |
| Free tier | 10 GB + 1M writes + 10M reads/month, **permanent** | 5 GB + 100 GB transfer, Hobby plan |
| Egress fees | **Zero** | Yes (Blob Data Transfer) |
| SDK stability | AWS S3 API is stable and versioned | Internal `x-api-version` header can change without notice |

The FEATURES.md and ARCHITECTURE.md researchers assumed Vercel Blob and designed a hand-rolled `BlobStorage.php` using PHP `curl`. The STACK researcher explicitly eliminated Vercel Blob because no PHP SDK exists. **The STACK finding is correct.** Using Vercel Blob from PHP means calling an undocumented internal API. The `aws/aws-sdk-php` integration is the professionally maintainable path.

**Consequences for implementation:** Service class is `src/Services/R2Storage.php` (not `BlobStorage.php`). CSP `img-src` adds `https://pub-*.r2.dev` instead of `*.public.blob.vercel-storage.com`. New env vars: `R2_ACCOUNT_ID`, `R2_ACCESS_KEY_ID`, `R2_SECRET_ACCESS_KEY`, `R2_BUCKET`, `R2_PUBLIC_BASE_URL` (instead of `BLOB_READ_WRITE_TOKEN`).

### Decision 2: Managed MySQL — Aiven for MySQL 8.0 (NOT TiDB Cloud)

**Recommendation: Aiven for MySQL 8.0**

PROJECT.md currently reads: *"Base MySQL managée externe (ex. TiDB Cloud / Aiven / PlanetScale)"*. Research eliminates two of the three options.

| Factor | Aiven for MySQL | TiDB Cloud Starter | PlanetScale |
|--------|----------------|-------------------|-------------|
| Engine | **MySQL 8.0 InnoDB** | TiDB (wire-compatible, not MySQL) | Vitess (wire-compatible) |
| `FOR UPDATE` locking | **Pessimistic (InnoDB default)** — correct | **Optimistic by default** — breaks `SaleModel::create()` | Pessimistic via Vitess |
| Free tier | 1 GB storage, 1 GB RAM, **free forever**, no credit card | 5 GB + 50M RU/month | **None** since April 2024 (min $39/mo) |
| TLS | Custom CA cert (commit `ssl/ca-aiven.pem` to repo) | System CA bundle | System CA bundle |

**Why TiDB is wrong:** `SaleController` uses `START TRANSACTION; SELECT ... FOR UPDATE` for concurrent stock locking. TiDB's default optimistic transaction mode detects conflicts at commit time, not at lock acquisition — the pessimistic lock semantics the app depends on are silently ineffective. Aiven runs actual MySQL 8.0 InnoDB, the exact engine the app was designed and tested against.

**Free-tier risk:** Aiven's free-tier connection limit is not publicly disclosed. The per-invocation PDO connect pattern (one new TCP+TLS connection per Lambda invocation, no cross-request pooling) can exhaust the ceiling under burst traffic. Monitor from day one; add ProxySQL if needed.

**TLS CA cert requirement:** Aiven uses a private CA. Download from Aiven Console and commit to `ssl/ca-aiven.pem`. PDO must set `PDO::MYSQL_ATTR_SSL_CA` and `PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT`.

---

## Key Findings

### Recommended Stack

The stack is minimal and well-constrained. Pin `vercel-php@0.7.4` (PHP 8.3.x) to match the development environment — do not upgrade to 0.8.0 (PHP 8.4) until the deployment is stable. The only new Composer dependency is `aws/aws-sdk-php:^3` for R2; everything else is already present.

**Core technologies:**
- `vercel-php@0.7.4`: PHP 8.3 serverless runtime — only community PHP runtime; `pdo_mysql`, `openssl`, `session`, `curl` confirmed available
- **Aiven for MySQL 8.0**: managed database — real MySQL 8 InnoDB; `FOR UPDATE` pessimistic locking preserved; free forever
- **Cloudflare R2 + `aws/aws-sdk-php` v3**: object storage — official PHP SDK; 10 GB free permanent; zero egress fees
- **MySQL-backed `DatabaseSessionHandler`**: persistent sessions — reuses existing DB; ~50 lines; no new service
- **Vercel project env vars**: secrets management — `getenv()` reads them natively; `Env::get()` needs no changes

**New dependency:** `aws/aws-sdk-php:^3` added at this milestone.

### Expected Features

This milestone adds zero product features. Every "feature" is a deployment prerequisite that makes an existing local feature work in serverless production.

**Must have — all 7 required before the milestone is complete:**
- `vercel.json` routing to front controller
- External managed MySQL (Aiven) + TLS env-var DSN
- One-shot schema migration (`bin/migrate.php`)
- MySQL-backed session handler
- Image upload to Cloudflare R2 (replaces Vercel Blob from PROJECT.md)
- `SESSION_SECURE=1` + HSTS header
- All secrets via Vercel env vars only

**Should have — after P1 items are working:**
- Graceful 503 DB failure page
- Health check endpoint (`/health`)
- Preview deploy DB isolation (per `VERCEL_ENV`)

**Defer to v2+:** Custom domain, CSRF token rotation, rate limiting on register/export endpoints.

### Architecture Approach

The architecture is a thin serverless adapter wrapped around an unchanged MVC core. The front controller moves from `public/index.php` to `api/index.php`; `vercel.json` routing uses `rewrites` (not legacy `routes`) so static assets serve from Vercel CDN without touching PHP. Two new service classes replace the two filesystem dependencies. `dirname(__DIR__)` from `api/` resolves to the same project root as from `public/`, so no autoloader path changes are needed.

**Components and changes:**
1. `vercel.json` (new): front-controller rewrite, PHP runtime config, HSTS header
2. `api/index.php` (moved): add `session_set_save_handler()` before `Auth::start()`
3. `src/Core/DatabaseSessionHandler.php` (new): MySQL session CRUD via `SessionHandlerInterface`
4. `src/Services/R2Storage.php` (new): Cloudflare R2 upload/delete via `aws/aws-sdk-php`
5. `src/Core/Database.php` (modify): remove `Schema::ensure()` from `connection()`
6. `bin/migrate.php` (new): one-shot migration runner
7. `src/Controllers/ProductController.php` (modify): `storeUploadedFile()` and `deleteImage()` use `R2Storage`
8. `sql/schema.sql` + `Schema::ensure()` (modify): add `sessions` table DDL; widen `image_path` to `VARCHAR(500)`

**What does not change:** Router, all Models, all Views, Csrf, RateLimiter, ProfitCalculator, ExchangeRateService, CsvExporter — all serverless-compatible without modification.

### Critical Pitfalls

1. **Ephemeral filesystem silently breaks uploads and sessions** — images in `/tmp` are invisible to the next Lambda invocation; file sessions lost between instances. Must fix before any functional testing on Vercel.

2. **`Schema::ensure()` races on concurrent cold starts** — two Lambda processes hitting `ALTER TABLE` simultaneously produce duplicate-column errors. Remove from `Database::connection()` before first deployment. Non-negotiable.

3. **Free-tier MySQL connection exhaustion** — each invocation opens a fresh PDO connection. `SaleController::productsMeta()` N+1 (61 queries for 20 products) multiplies the risk. Fix the N+1 before real users access the app.

4. **SSL/TLS CA verification failure** — `Database::connection()` currently has no TLS PDO options. Aiven requires `PDO::MYSQL_ATTR_SSL_CA` pointing to the committed `ssl/ca-aiven.pem`. Must test in the actual Vercel Lambda environment.

5. **`ExchangeRateService` silent failure** — `@file_get_contents()` with no timeout can hang 60s or return `null`, saving `0.00 EUR` purchase costs silently. Replace with `curl` + 5s timeout + error logging. Mandatory before purchase functionality goes live.

---

## Implications for Roadmap

### Phase 1: Routing and Front Controller

**Rationale:** Nothing runs on Vercel until the PHP function is reachable. Prerequisite for all phases.
**Delivers:** Vercel-deployed PHP responding to all routes; static CSS/JS from CDN; `.htaccess` preserved for local Docker dev.
**Addresses:** Table-stakes "vercel.json routing".
**Avoids:** Pitfall 6 (use `rewrites` not `routes`; explicit static extension passthrough).
**Verification:** CSS/JS load; all routes return correct responses; function invocation count matches page views.
**Research flag:** Standard patterns — HIGH confidence, no additional research needed.

### Phase 2: Database Provisioning and Schema Migration

**Rationale:** Managed DB must exist before sessions, uploads, or any CRUD feature can be tested.
**Delivers:** Aiven MySQL 8 live; `ssl/ca-aiven.pem` committed; `bin/migrate.php` creates all tables including `sessions`; TLS PDO options configured; `Database::connection()` safe without DDL side effects.
**Addresses:** Table-stakes "external managed MySQL" and "one-shot schema migration".
**Avoids:** Pitfalls 2 (Schema::ensure race), 3 (connection exhaustion), 4 (SSL/TLS CA verification).
**Verification:** `php bin/migrate.php` runs without error; two simultaneous requests produce no DDL errors in logs.
**Research flag:** Aiven free-tier connection limit is unknown — add health-check endpoint in this phase; treat limit as a runtime unknown.

### Phase 3: Persistent Sessions

**Rationale:** Login state must persist before the full app is testable end-to-end. CSRF tokens and all authenticated features depend on working sessions.
**Delivers:** `DatabaseSessionHandler` wired into `Auth::start()`; `SESSION_SECURE=1` set in Vercel env vars; login persists across Lambda instances.
**Addresses:** Table-stakes "MySQL-backed session handler".
**Avoids:** Pitfall 1 (ephemeral sessions), Pitfall 5 (SESSION_SECURE=0).
**Verification:** Log in; navigate three successive pages without re-login; browser devtools shows `Secure; HttpOnly; SameSite=Lax` cookie flags.
**Research flag:** Standard patterns — HIGH confidence.

### Phase 4: Image Storage Migration (Cloudflare R2)

**Rationale:** Independent of sessions; can be developed in parallel. Must ship before production images are uploaded.
**Delivers:** `R2Storage.php` using `aws/aws-sdk-php`; `ProductController` routes uploads to R2; `image_path` widened to `VARCHAR(500)`; plan for migrating existing local-path DB records.
**Addresses:** Table-stakes "image upload to object storage" (now R2, not Vercel Blob).
**Avoids:** Pitfall 1 (ephemeral uploads), Pitfall 8 (4.5 MB limit — add 3.5 MB PHP guard).
**Verification:** Upload image; reload product page — image loads from `https://pub-*.r2.dev/...`; delete image — R2 object removed.
**Research flag:** Standard patterns — Cloudflare official PHP R2 example covers S3Client setup. HIGH confidence.

### Phase 5: Security Hardening and Production Configuration

**Rationale:** Applied last because HSTS and CSP `img-src` require knowing the final storage domain (R2 URL from Phase 4); `SESSION_SECURE=1` is only meaningful once sessions work (Phase 3).
**Delivers:** HSTS in `vercel.json`; CSP updated for R2 public bucket domain; all secrets in Vercel dashboard only; boot assertions for production safety; `.env` confirmed gitignored.
**Addresses:** Table-stakes "SESSION_SECURE=1 + HSTS" and "secrets via Vercel env vars".
**Avoids:** Pitfall 5, security mistakes (default credentials, CSRF exposure, CDN SRI).
**Verification:** `securityheaders.com` scan shows HSTS; session cookie has `Secure` flag; no `.env` in Vercel build.
**Research flag:** Standard patterns — HIGH confidence.

### Phase 6: Performance Fixes (Mandatory Before Real Users)

**Rationale:** `SaleController::productsMeta()` N+1 is not optional tech debt on serverless — 20 products = ~61 queries × 50ms = 3s+ response time, timing out under load. `ExchangeRateService` silent failure corrupts purchase cost data. Both must ship before real users access the app.
**Delivers:** `SaleController::productsMeta()` replaced with single aggregated query; `ExchangeRateService` uses `curl` with 5s timeout and explicit error logging; user-visible warning on exchange rate failure.
**Avoids:** Pitfall 3 (N+1 multiplies connections), Pitfall 7 (ExchangeRateService silent failure), Pitfall 9 (unbounded queries and export timeout).
**Verification:** Sale-create page loads under 2s with 50 products; purchase with USD amount shows correct EUR cost; frankfurter.app outage shows user-visible warning, not 0.00 EUR.
**Research flag:** Standard SQL optimization — no additional research needed.

### Phase Ordering Rationale

- Routing first: no code runs without it; no external service dependency.
- Database second: sessions and uploads both require a live managed DB; `sessions` table must exist before Phase 3.
- Sessions before uploads: login is the gateway to all features; can't test upload flow without authentication.
- Security hardening last: HSTS and CSP require the confirmed R2 domain from Phase 4.
- Performance before real users: N+1 does not block a test deployment but must land before anyone with real data uses the app.

### Research Flags

Phases needing deeper research during planning:
- **Phase 2 (Database):** Aiven free-tier concurrent connection limit is unpublished. Treat as unknown until first load test. Mitigation path (ProxySQL) may need evaluation.

Phases with standard patterns (skip research-phase):
- **Phase 1 (Routing):** vercel-community/php README provides exact `vercel.json` pattern. HIGH confidence.
- **Phase 3 (Sessions):** PHP `SessionHandlerInterface` is stable documented API. HIGH confidence.
- **Phase 4 (R2 Storage):** Cloudflare official PHP example covers S3Client completely. HIGH confidence.
- **Phase 5 (Security):** All headers and env var patterns are documented. HIGH confidence.
- **Phase 6 (Performance):** Standard SQL N+1 fix. No research needed.

---

## Confidence Assessment

| Area | Confidence | Notes |
|------|------------|-------|
| Stack | HIGH | PHP runtime verified against CHANGELOG; R2 example from official Cloudflare docs; Aiven free tier from official docs (March 2026); Vercel env vars from official docs |
| Features | HIGH | All 7 table-stakes items verified against codebase map + Vercel hard constraints; anti-features drawn from CONCERNS.md |
| Architecture | HIGH / MEDIUM | Routing and sessions HIGH (documented API); R2 service class design inferred from official example (HIGH); original ARCHITECTURE.md used Vercel Blob — superseded by R2 |
| Pitfalls | HIGH | Cross-referenced against actual CONCERNS.md codebase analysis and official Vercel limits docs |

**Overall confidence: HIGH**

### Gaps to Address

- **Aiven free-tier connection limit:** Not publicly disclosed. Add connection-count health-check in Phase 2 and monitor it. If limit hit at < 10 concurrent connections, evaluate ProxySQL or tier upgrade.
- **Existing image migration:** Products in Docker dev have `/assets/uploads/...` paths; these 404 on Vercel. A one-time data migration (re-upload all to R2, update DB records) must be planned before go-live. Not a code change, but a deployment step.
- **`image_path` column width:** Current `VARCHAR(255)` is marginal for R2 URLs. Widen to `VARCHAR(500)` in Phase 2 migration script, matching the existing `order_url` precedent.
- **N+1 replacement query design:** The `SaleController::productsMeta()` fix is mandatory but the replacement query has not been designed. Needs model layer review during Phase 6 planning.

---

## Sources

### Primary (HIGH confidence)
- [vercel-community/php GitHub](https://github.com/vercel-community/php) — runtime versions, extensions, vercel.json patterns
- [vercel-community/php CHANGELOG](https://github.com/vercel-community/php/blob/master/CHANGELOG.md) — confirmed 0.7.4 = PHP 8.3
- [Aiven for MySQL free tier docs](https://aiven.io/docs/products/mysql/concepts/mysql-free-tier) — free tier specs (March 2026)
- [Aiven MySQL PHP connection guide](https://aiven.io/docs/products/mysql/howto/connect-with-php) — PDO DSN + TLS CA cert requirement
- [Cloudflare R2 aws-sdk-php example](https://developers.cloudflare.com/r2/examples/aws/aws-sdk-php/) — S3Client constructor and upload pattern
- [Cloudflare R2 pricing](https://developers.cloudflare.com/r2/pricing/) — 10 GB + 1M writes + 10M reads/month free permanent
- [Cloudflare R2 public buckets](https://developers.cloudflare.com/r2/buckets/public-buckets/) — r2.dev public URL model
- [Vercel environment variables docs](https://vercel.com/docs/environment-variables) — OS-level env var injection, getenv() access
- [Vercel Functions Limits](https://vercel.com/docs/functions/limitations) — 4.5 MB request body, timeout behaviour
- [PHP SessionHandlerInterface manual](http://docs.php.net/manual/en/class.sessionhandlerinterface.php) — required method signatures
- ResellTrack `.planning/codebase/CONCERNS.md` (2026-06-12) — N+1, Schema::ensure(), SESSION_SECURE, ExchangeRateService, CSRF, HSTS

### Secondary (MEDIUM confidence)
- [Vercel Blob server upload docs](https://vercel.com/docs/vercel-blob/server-upload) — confirms no PHP SDK; cited to establish what was eliminated
- [vercel-community/php issue #567](https://github.com/vercel-community/php/issues/567) — $_ENV vs getenv() behaviour
- [TiDB Cloud TLS requirements](https://docs.pingcap.com/tidbcloud/secure-connections-to-serverless-clusters/) — TLS 1.2+ required; confirms TiDB elimination rationale

### Tertiary (LOW confidence)
- Aiven free-tier concurrent connection limit — not publicly documented; treat as unknown until measured in production

---

*Research completed: 2026-06-12*
*Ready for roadmap: yes*
