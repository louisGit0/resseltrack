# Roadmap: ResellTrack — Vercel Serverless Deployment

## Overview

ResellTrack runs today in Docker/Apache with local filesystem sessions and image uploads. This milestone makes it fully operational on Vercel serverless: routing rewired through `vercel.json`, sessions moved to MySQL, images moved to Cloudflare R2, schema extracted to a one-shot migration, production secrets and security headers wired in, and every existing feature verified on the public URL. The application code changes are surgical — five files modified, three files added — while all models, views, and business logic remain untouched.

## Phases

**Phase Numbering:**

- Integer phases (1, 2, 3): Planned milestone work
- Decimal phases (2.1, 2.2): Urgent insertions (marked with INSERTED)

Decimal phases appear between their surrounding integers in numeric order.

- [x] **Phase 1: Routing and Front Controller** - Wire `vercel.json` so Vercel serves the app and CDN delivers static assets ✅ live on Vercel
- [x] **Phase 2: Database and Schema Migration** - Provision Aiven MySQL 8, configure TLS, and apply the schema via a one-shot CLI script ✅ live Aiven MySQL verified
- [ ] **Phase 3: Persistent Sessions** - Replace ephemeral file sessions with MySQL-backed sessions that survive across Lambda invocations
- [ ] **Phase 4: Image Storage on Cloudflare R2** - Migrate image upload/delete to R2 via `aws/aws-sdk-php`; enforce the 3.5 MB size guard
- [ ] **Phase 5: Security Hardening and Production Configuration** - Lock down secrets, emit HSTS, update CSP for R2 domain, add boot safety assertion
- [ ] **Phase 6: Performance and Reliability** - Fix the N+1 in `SaleController::productsMeta()` and harden `ExchangeRateService` with timeout and visible error
- [ ] **Phase 7: Production Verification** - End-to-end verification of every existing feature on the live Vercel URL

## Phase Details

### Phase 1: Routing and Front Controller

**Goal**: The application responds correctly on Vercel — all routes reach PHP, static assets are served by the CDN without invoking PHP, and local Docker development is unchanged
**Depends on**: Nothing (first phase)
**Requirements**: DEPLOY-01, DEPLOY-02, DEPLOY-03
**Success Criteria** (what must be TRUE):

  1. Opening the Vercel deployment URL loads the app (no 404, no "Function invocation failed")
  2. All app routes (`/login`, `/products`, `/dashboard`, etc.) return correct PHP-rendered responses
  3. CSS and JS assets load directly from Vercel's CDN — browser devtools shows no PHP function invocation for `.css` or `.js` requests
  4. `docker-compose up` still serves the app locally through Apache with `.htaccess` unchanged

**Plans**: 2 plans
Plans:
**Wave 1**

- [x] 01-01-PLAN.md — Create the four Vercel config files (api/index.php wrapper, api/php.ini, vercel.json rewrites, .vercelignore) without touching existing code

**Wave 2** *(blocked on Wave 1 completion)*

- [ ] 01-02-PLAN.md — Connect GitHub → Vercel, deploy, and verify DEPLOY-01/02/03 on the live URL (operator steps)

### Phase 2: Database and Schema Migration

**Goal**: A live Aiven MySQL 8 instance is reachable from Vercel over TLS, the full schema is applied once via `bin/migrate.php` (the `sessions` table is Phase 3 scope), and `Database::connection()` no longer executes DDL on every request
**Depends on**: Phase 1
**Requirements**: DB-01, DB-02, DB-03
**Success Criteria** (what must be TRUE):

  1. `php bin/migrate.php` runs against the Aiven database and exits cleanly with all tables created
  2. The application connects to Aiven MySQL over TLS from a Vercel Lambda with no SSL errors in function logs
  3. Two simultaneous cold-start requests produce no DDL errors, duplicate-column exceptions, or race conditions in Vercel logs
  4. Product and user CRUD operations persist correctly in the external managed database (not in Docker)

**Plans**: 2 plans
Plans:
**Wave 1**

- [x] 02-01-PLAN.md — Add cert-guarded TLS options to Database::connection(), remove the per-request Schema::ensure() call, create bin/migrate.php + certs/ scaffolding (autonomous code)

**Wave 2** *(blocked on Wave 1 completion)*

- [x] 02-02-PLAN.md — Provision Aiven MySQL 8, commit the CA cert, set Vercel env vars, run bin/migrate.php, and verify DB-01/02/03 + local-dev regression on the live URL (operator steps)

### Phase 3: Persistent Sessions

**Goal**: User login state persists across successive serverless Lambda invocations — sessions are stored in MySQL, cookies carry the correct production flags, and CSRF protection continues to work
**Depends on**: Phase 2
**Requirements**: SESS-01, SESS-02, SESS-03, SESS-04
**Success Criteria** (what must be TRUE):

  1. A user who logs in stays logged in across 3 successive page navigations on the deployed Vercel URL (no redirect to `/login` between pages)
  2. Browser devtools shows the session cookie with `Secure`, `HttpOnly`, and `SameSite=Lax` flags on the production HTTPS URL
  3. Submitting a POST form (e.g. creating a product) does not produce a CSRF 419 error
  4. Logging out invalidates the session — navigating to a protected route immediately redirects to `/login`

**Plans**: 2 plans
Plans:
**Wave 1**

- [ ] 03-01-PLAN.md — Create DatabaseSessionHandler (\SessionHandlerInterface), wire it into Auth::start() with a 30-day cookie, add the sessions table to sql/schema.sql + Schema::ensure(), and set session.gc_probability=0 in api/php.ini (autonomous code)

**Wave 2** *(blocked on Wave 1 completion)*

- [ ] 03-02-PLAN.md — Re-run bin/migrate.php against Aiven to create the sessions table, set SESSION_SECURE=1 in Vercel, redeploy, and verify SESS-01/02/03/04 on the live URL (operator steps)

### Phase 4: Image Storage on Cloudflare R2

**Goal**: Product image upload writes to Cloudflare R2 and stores the public R2 URL; delete removes the R2 object; existing local-path records are addressed; uploads over 3.5 MB are rejected with a clear user message
**Depends on**: Phase 2
**Requirements**: STORE-01, STORE-02, STORE-03, STORE-04, STORE-05
**Success Criteria** (what must be TRUE):

  1. Uploading a product image succeeds and the image immediately displays from an `r2.dev` URL on the product page after reload
  2. Deleting a product image removes the object from Cloudflare R2 (verified via R2 console or a HEAD request returning 404)
  3. Uploading a file larger than 3.5 MB returns a user-visible French error message — no blank page, no raw 413 JSON
  4. The strategy for existing local-path database records (migration to R2 or documented fallback) is applied before go-live

**Plans**: TBD
**UI hint**: yes

### Phase 5: Security Hardening and Production Configuration

**Goal**: All secrets live exclusively in Vercel environment variables, HSTS is emitted for every response, the CSP `img-src` directive covers the R2 public domain, and the application refuses to start in production with a dangerous configuration
**Depends on**: Phase 3, Phase 4
**Requirements**: SEC-01, SEC-02, SEC-03, SEC-04
**Success Criteria** (what must be TRUE):

  1. A `securityheaders.com` scan of the Vercel URL reports the `Strict-Transport-Security` header present with a valid `max-age`
  2. The application returns a clear error page (not a broken app) when deployed with `SESSION_SECURE=0` in a production environment
  3. No `.env` file, hardcoded credential, or secret appears in the Vercel build output or committed codebase
  4. Product images from R2 load without any CSP violation in the browser console

**Plans**: TBD

### Phase 6: Performance and Reliability

**Goal**: The sale creation page loads in under 2 seconds for any real catalog size, and a failed or slow exchange rate API call surfaces a visible warning rather than silently saving a 0.00 EUR purchase cost
**Depends on**: Phase 2
**Requirements**: PERF-01, PERF-02
**Success Criteria** (what must be TRUE):

  1. The sale creation page loads in under 2 seconds on the deployed site with a product catalog of 20 or more items
  2. A purchase recorded in USD saves the correct EUR cost (not `0.00`) when frankfurter.app responds normally
  3. When frankfurter.app is unreachable or returns an error, the purchase form shows a user-visible warning — no silent `0.00` write to the database

**Plans**: TBD

### Phase 7: Production Verification

**Goal**: Every feature that works locally is confirmed working end-to-end on the live Vercel URL under real production conditions
**Depends on**: Phase 3, Phase 4, Phase 5, Phase 6
**Requirements**: VERIF-01
**Success Criteria** (what must be TRUE):

  1. Registration, login, and logout work on the Vercel URL for a new test account
  2. A product is created with an image upload — the image displays from its R2 URL on the product list and detail pages
  3. A batch purchase in USD calculates and saves the correct EUR cost with port and customs allocation (not 0.00)
  4. A sale deducts stock correctly, the concurrent stock guard (`FOR UPDATE`) prevents overselling, and the sale appears on the dashboard charts
  5. A CSV export downloads a valid UTF-8 BOM file containing all records for the test account

**Plans**: TBD

## Progress

**Execution Order:**
Phases execute in numeric order: 1 → 2 → 3 → 4 → 5 → 6 → 7

| Phase | Plans Complete | Status | Completed |
|-------|----------------|--------|-----------|
| 1. Routing and Front Controller | 2/2 | Complete | 2026-06-12 |
| 2. Database and Schema Migration | 2/2 | Complete | 2026-06-12 |
| 3. Persistent Sessions | 0/2 | Planned | - |
| 4. Image Storage on Cloudflare R2 | 0/? | Not started | - |
| 5. Security Hardening and Production Configuration | 0/? | Not started | - |
| 6. Performance and Reliability | 0/? | Not started | - |
| 7. Production Verification | 0/? | Not started | - |
