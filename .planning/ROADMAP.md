# Roadmap: ResellTrack — Vercel Serverless Deployment

## Overview

ResellTrack runs today in Docker/Apache with local filesystem sessions and image uploads. This milestone makes it fully operational on Vercel serverless: routing rewired through `vercel.json`, sessions moved to MySQL, images moved to Cloudflare R2, schema extracted to a one-shot migration, production secrets and security headers wired in, and every existing feature verified on the public URL. The application code changes are surgical — five files modified, three files added — while all models, views, and business logic remain untouched.

**v2.0 milestone** (phases 8-10) adds supplier management, product ratings, and best-effort URL auto-fill on top of the live v1.0 deployment. Tech stack unchanged: PHP 8.3 MVC maison, Aiven MySQL 8, Cloudinary, Vercel. Schema changes go through `sql/schema.sql` + `bin/migrate.php` re-run (operator step).

## Phases

**Phase Numbering:**

- Integer phases (1, 2, 3): Planned milestone work
- Decimal phases (2.1, 2.2): Urgent insertions (marked with INSERTED)

Decimal phases appear between their surrounding integers in numeric order.

- [x] **Phase 1: Routing and Front Controller** - Wire `vercel.json` so Vercel serves the app and CDN delivers static assets ✅ live on Vercel
- [x] **Phase 2: Database and Schema Migration** - Provision Aiven MySQL 8, configure TLS, and apply the schema via a one-shot CLI script ✅ live Aiven MySQL verified
- [x] **Phase 3: Persistent Sessions** - Replace ephemeral file sessions with MySQL-backed sessions that survive across Lambda invocations ✅ verified live
- [x] **Phase 4: Image Storage** - Image upload/delete via Cloudinary (pivoted from R2); 3.5 MB size guard ✅ STORE-01..05 verified live
- [x] **Phase 5: Security Hardening and Production Configuration** - Lock down secrets, emit HSTS, update CSP for image domain, add boot safety assertion ✅ SEC-01..04 verified live
- [x] **Phase 6: Performance and Reliability** - Fix the N+1 in `SaleController::productsMeta()` and harden `ExchangeRateService` with timeout and visible error (completed 2026-06-15)
- [x] **Phase 7: Production Verification** - End-to-end verification of every existing feature on the live Vercel URL ✅ VERIF-01 PASS (milestone v1.0 complete)
- [x] **Phase 8: Suppliers and Product Cleanup** - CRUD fournisseurs, lien optionnel sur les commandes, purge Cloudinary à la suppression produit ⚠ nécessite re-run `bin/migrate.php` (completed 2026-06-15)
- [ ] **Phase 9: Product Ratings** - Note 1-5 + commentaire par produit, éditable après réception, affichée en liste et fiche ⚠ nécessite re-run `bin/migrate.php` (avant déploiement — additif/zéro-downtime)
- [ ] **Phase 10: Product URL Auto-fill** - Scrape best-effort d'une URL produit publique (AliExpress) pour pré-remplir titre/prix/image, repli manuel

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

- [x] 03-01-PLAN.md — Create DatabaseSessionHandler (\SessionHandlerInterface), wire it into Auth::start() with a 30-day cookie, add the sessions table to sql/schema.sql + Schema::ensure(), and set session.gc_probability=0 in api/php.ini (autonomous code)

**Wave 2** *(blocked on Wave 1 completion)*

- [x] 03-02-PLAN.md — Re-run bin/migrate.php against Aiven to create the sessions table, set SESSION_SECURE=1 in Vercel, redeploy, and verify SESS-01/02/03/04 on the live URL (operator steps)

### Phase 4: Image Storage on Cloudflare R2

**Goal**: Product image upload writes to Cloudflare R2 and stores the public R2 URL; delete removes the R2 object; existing local-path records are addressed; uploads over 3.5 MB are rejected with a clear user message
**Depends on**: Phase 2
**Requirements**: STORE-01, STORE-02, STORE-03, STORE-04, STORE-05
**Success Criteria** (what must be TRUE):

  1. Uploading a product image succeeds and the image immediately displays from an `r2.dev` URL on the product page after reload
  2. Deleting a product image removes the object from Cloudflare R2 (verified via R2 console or a HEAD request returning 404)
  3. Uploading a file larger than 3.5 MB returns a user-visible French error message — no blank page, no raw 413 JSON
  4. The strategy for existing local-path database records (migration to R2 or documented fallback) is applied before go-live

**Plans**: 2 plans
Plans:
**Wave 1**

- [x] 04-01-PLAN.md — Add aws/aws-sdk-php, wire vendor/autoload.php + CSP img-src r2.dev into public/index.php, create R2Storage service, swap ProductController upload/delete to R2, raise the 3.5 MB guard + php.ini limits (autonomous code)

**Wave 2** *(blocked on Wave 1 completion)*

- [x] 04-02-PLAN.md — Operator storage setup + verify STORE-01..05 live. NOTE: pivoted to Cloudinary (free, no card) instead of R2 — operator created a Cloudinary account + set 3 CLOUDINARY_* Vercel vars. See 04-CONTEXT AMENDMENT.

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

**Plans**: 1 plan
Plans:
**Wave 1**

- [x] 05-01-PLAN.md — Add HTTPS detection + production boot safety gate (SEC-04) + HSTS header (SEC-02) to public/index.php; verify SEC-01 (no committed secret) and SEC-03 (CSP covers Cloudinary). Single autonomous plan — no operator/Wave-2 step (HTTPS detection avoids needing APP_ENV).

### Phase 6: Performance and Reliability

**Goal**: The sale creation page loads in under 2 seconds for any real catalog size, and a failed or slow exchange rate API call surfaces a visible warning rather than silently saving a 0.00 EUR purchase cost
**Depends on**: Phase 2
**Requirements**: PERF-01, PERF-02
**Success Criteria** (what must be TRUE):

  1. The sale creation page loads in under 2 seconds on the deployed site with a product catalog of 20 or more items
  2. A purchase recorded in USD saves the correct EUR cost (not `0.00`) when frankfurter.app responds normally
  3. When frankfurter.app is unreachable or returns an error, the purchase form shows a user-visible warning — no silent `0.00` write to the database

**Plans**: 1 plan
Plans:
**Wave 1**

- [x] 06-01-PLAN.md — Replace the N+1 in SaleController::productsMeta() with 3 fixed queries (new Purchase::lotsForUser + Sale::soldQtyByProduct, PHP grouping, ProfitCalculator unchanged); rewrite ExchangeRateService::latest() to curl (5s timeout + logging); add server-side FX fallback + block-on-failure in PurchaseController::validate() + identity unit test. Single autonomous plan — no operator/Wave-2 step (live smoke checks folded into verification for the orchestrator).

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

---

## v2.0 Phases

### Phase 8: Suppliers and Product Cleanup

**Goal**: Users can manage a personal supplier list (rated), link orders to suppliers from a dropdown, and product deletion no longer leaves orphaned images on Cloudinary
**Depends on**: Phase 7 (v1.0 shipped and live)
**Requirements**: SUP-01, SUP-02, OPS-06
**DB/Operator step**: YES — re-run `php bin/migrate.php` after code deploy (creates `suppliers` table, adds nullable `supplier_id` FK column to `orders`)
**Success Criteria** (what must be TRUE):

  1. The navigation has a "Fournisseurs" tab; user can create, edit, and delete a supplier record (name, URL, rating 1-5, optional comment) and the data belongs only to their account
  2. The order create/edit form shows an optional supplier dropdown pre-populated from the user's supplier list; saving with no selection is accepted and existing orders with a free-text `supplier` value continue to display correctly (backward compatible)
  3. Deleting a product removes its cover image and every gallery image from Cloudinary (best-effort via the same try/catch pattern as `deleteImage()`); a Cloudinary failure is logged but does not block the delete, and the DB rows are always removed

**New files**: `src/Models/Supplier.php`, `src/Controllers/SupplierController.php`, `src/Views/suppliers/index.php`, `src/Views/suppliers/form.php`
**Modified files**: `sql/schema.sql` (suppliers table + orders.supplier_id column), `src/Controllers/OrderController.php` (pass suppliers list to form; accept supplier_id POST), `src/Views/orders/form.php` (supplier dropdown), `src/Controllers/ProductController.php` (destroy() adds Cloudinary purge), `src/Views/layout.php` (nav entry), `public/index.php` (route registration for suppliers CRUD)
**Plans**: 6 plans
Plans:
**Wave 1**

- [x] 08-01-PLAN.md — Schema foundation: add suppliers table (sql/schema.sql + Schema::ensure()) and guarded orders.supplier_id nullable FK (ON DELETE SET NULL)
- [x] 08-02-PLAN.md — OPS-06: ProductController::destroy() purges cover + gallery from Cloudinary best-effort (deduped, logged, never blocks)

**Wave 2** *(blocked on Wave 1)*

- [x] 08-03-PLAN.md — SUP-01 backend: Supplier model (per-user CRUD + linked-orders count), SupplierController, routes, Fournisseurs nav entry

**Wave 3** *(blocked on Wave 2)*

- [x] 08-04-PLAN.md — SUP-01 UI: suppliers index table + create/edit form + reusable clickable star-rating widget (app.js/style.css)

**Wave 4** *(blocked on Wave 3)*

- [x] 08-05-PLAN.md — SUP-02: order↔supplier dropdown + "Autre" fallback, owned-id resolution + id/name dual-write (D-02), Order model supplier_id, syncSupplier() toggle

**Wave 5** *(blocked on Waves 1-4)*

- [x] 08-06-PLAN.md — Operator: run bin/migrate.php against Aiven + verify SUP-01/SUP-02/OPS-06 on the live Vercel URL (checkpoints)

**UI hint**: yes

### Phase 9: Product Ratings

**Goal**: Users can record a 1-5 star rating and a short note on any product after receiving it; ratings appear on the product list and detail page
**Depends on**: Phase 8
**Requirements**: RATE-01
**DB/Operator step**: YES — re-run `php bin/migrate.php` after code deploy (adds `rating TINYINT UNSIGNED NULL` and `rating_note TEXT NULL` columns to `products`)
**Success Criteria** (what must be TRUE):

  1. The product edit form has a "Note" field (1-5) and a "Commentaire" textarea; submitting saves the rating alongside all other product fields without touching any existing data
  2. The product list shows a visual indicator (stars or numeric badge) for rated products; unrated products show a neutral placeholder — the layout is not broken for either case
  3. The product detail page displays the rating and the full note text, editable from the existing edit link

**New files**: none (columns on existing table, views updated in place)
**Modified files**: `sql/schema.sql` (products.rating + products.rating_note), `src/Core/Schema.php` (guarded ALTER), `src/Models/Product.php` (rating in create/update + new setRating), `src/Controllers/ProductController.php` (validate + rate action), `src/Views/products/form.php` (rating inputs), `src/Views/products/index.php` (rating badge in list), `src/Views/products/show.php` (interactive quick-rate + comment), `public/index.php` (POST /products/{id}/rate), `public/assets/js/app.js` (initQuickRate wrapper)
**Plans**: 5 plans
Plans:
**Wave 1** *(parallel — no file overlap)*

- [x] 09-01-PLAN.md — Add products.rating + products.rating_note (schema.sql + Schema::ensure() guarded ALTER, idempotent, ;-free comments)
- [ ] 09-02-PLAN.md — Product model (rating in create/both update branches + new setRating) + ProductController (validate 1-5/NULL + rate() action, CSRF + ownership)

**Wave 2** *(parallel — blocked on Wave 1)*

- [ ] 09-03-PLAN.md — products/form.php Note section (reused star widget + comment textarea) + products/index.php rating badge near the name (D-02/D-04)
- [ ] 09-04-PLAN.md — products/show.php interactive quick-rate + comment, POST /products/{id}/rate route, app.js initQuickRate() submit-on-click (D-03/D-05)

**Wave 3** *(operator — blocked on Waves 1-2)*

- [ ] 09-05-PLAN.md — Operator: run php bin/migrate.php against Aiven (additive, before deploy) + live verification of RATE-01 on the Vercel URL
**UI hint**: yes

### Phase 10: Product URL Auto-fill

**Goal**: Pasting a public product URL (initially AliExpress product pages) into the product form attempts a server-side scrape and pre-fills title, price, and image; the form remains fully usable by manual entry when scraping fails or is blocked
**Depends on**: Phase 9
**Requirements**: IMPORT-01
**DB/Operator step**: NO (no schema change; redeploy on next Vercel push)
**Success Criteria** (what must be TRUE):

  1. Clicking "Remplir depuis URL" on a recognised AliExpress product page URL pre-fills at least the product name field (and price/image when the page layout is parseable) — the fields are editable before the user saves
  2. When the target site blocks the request (non-200, captcha, JS-rendered-only, or unrecognised HTML layout), the form shows a user-visible French message such as "Remplissage automatique indisponible — veuillez saisir manuellement" and all fields remain editable with no data loss
  3. A product saved after using URL auto-fill is identical in the database to one saved via manual entry — the import path does not bypass validation or introduce extra fields

**New files**: `src/Services/ProductImportService.php` (curl + HTML parsing; site-by-site; AliExpress first), new route and action (e.g. `POST /products/fetch-url` → `ProductController::fetchUrl()` returning JSON)
**Modified files**: `src/Controllers/ProductController.php` (add `fetchUrl()` action returning JSON meta), `src/Views/products/form.php` (URL input + minimal JS to call the endpoint and populate fields), `public/index.php` (register the fetch-url route before parameterised product routes)
**Plans**: TBD
**UI hint**: yes

## Progress

**Execution Order:**
v1.0 phases (1-7) complete. v2.0 executes in numeric order: 8 → 9 → 10

| Phase | Plans Complete | Status | Completed |
|-------|----------------|--------|-----------|
| 1. Routing and Front Controller | 2/2 | Complete | 2026-06-12 |
| 2. Database and Schema Migration | 2/2 | Complete | 2026-06-12 |
| 3. Persistent Sessions | 2/2 | Complete | 2026-06-12 |
| 4. Image Storage (Cloudinary) | 2/2 | Complete | 2026-06-15 |
| 5. Security Hardening and Production Configuration | 1/1 | Complete | 2026-06-15 |
| 6. Performance and Reliability | 1/1 | Complete   | 2026-06-15 |
| 7. Production Verification | 1/1 | Complete | 2026-06-15 |
| 8. Suppliers and Product Cleanup | 6/6 | Complete   | 2026-06-15 |
| 9. Product Ratings | 1/5 | In Progress|  |
| 10. Product URL Auto-fill | 0/TBD | Not started | - |
