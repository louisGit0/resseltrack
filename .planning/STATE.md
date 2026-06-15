---
gsd_state_version: 1.0
milestone: v2.0
milestone_name: Suppliers, ratings & auto-fill
status: executing
stopped_at: Completed 08-03-PLAN.md
last_updated: "2026-06-15T19:32:31.919Z"
last_activity: 2026-06-15
progress:
  total_phases: 3
  completed_phases: 0
  total_plans: 6
  completed_plans: 3
  percent: 50
---

# Project State

## Project Reference

See: .planning/PROJECT.md (updated 2026-06-12)

**Core value:** Tout ce qui fonctionne en local fonctionne à l'identique une fois déployé sur Vercel — le site en ligne est pleinement opérationnel pour de vrais utilisateurs.
**Current focus:** Phase 08 — suppliers-and-product-cleanup

## Current Position

Phase: 08 (suppliers-and-product-cleanup) — EXECUTING
Plan: 4 of 6
Status: Ready to execute
Last activity: 2026-06-15

## Possible next steps (post-v1.0, optional)

- Spawned task: purge Cloudinary images on product delete.
- OrderController FX fallback parity (P6 D-06 gap).
- Deferred v2 backlog: custom domain, ProxySQL, /health connection-count, graceful 503, CSRF rotation, rate limiting on /register & /export.

## Optional follow-up

- SEC-04 negative test (deliberately set SESSION_SECURE=0 in Vercel → confirm the French 500 config page, then revert) NOT run — it temporarily breaks the live site. Gate logic proven by local predicate simulation + code review + good-config live pass. Run only if explicitly desired.

## Resolved (this session)

- Aiven DB power-off (free-tier inactivity): RESOLVED — operator powered it back on. Keep-alive (.github/workflows/keepalive.yml pings /health every 10 min) prevents recurrence.
- composer.lock was missing → build failed; fixed (now dev-only after dropping aws-sdk-php).
- Provider pivot R2→Supabase→Cloudinary (card/quota constraints) — landed on Cloudinary (free, no card).

## Open follow-up (out of scope, tracked)

- ProductController::destroy() does not purge a deleted product's Cloudinary cover/gallery objects (orphans). deleteImage() already does. Spawned as a separate task.
- Phase 5 SEC-03 (CSP for image domain) is partly pre-satisfied: img-src already allows res.cloudinary.com.

## Resolved follow-ups

- Local Docker TLS regression (02-02): RESOLVED as non-applicable — user confirmed (2026-06-12) Docker local dev is no longer used. is_file TLS guard left as-is; no code change needed.

## Performance Metrics

**Velocity:**

- Total plans completed: 0
- Average duration: —
- Total execution time: —

**By Phase:**

| Phase | Plans | Total | Avg/Plan |
|-------|-------|-------|----------|
| - | - | - | - |

**Recent Trend:**

- Last 5 plans: —
- Trend: —

*Updated after each plan completion*
| Phase 01-routing-and-front-controller P01 | 2m | 2 tasks | 4 files |
| Phase 02-database-and-schema-migration P01 | 2m | 2 tasks | 5 files |
| Phase 03-persistent-sessions P01 | 15m | 3 tasks | 5 files |
| Phase 04-image-storage-r2 P01 | 6 | 3 tasks | 6 files |
| Phase 06-performance-and-reliability P01 | 15m | 3 tasks | 6 files |
| Phase 08-suppliers-and-product-cleanup P01 | 3m | 2 tasks | 2 files |
| Phase 08 P02 | 4m | 1 tasks | 1 files |
| Phase 08 P03 | 3min | 2 tasks | 4 files |

## Accumulated Context

### Decisions

Decisions are logged in PROJECT.md Key Decisions table.
Recent decisions affecting current work:

- Init: Cloudflare R2 + `aws/aws-sdk-php` v3 chosen for image storage (NOT Vercel Blob — no official PHP SDK)
- Init: Aiven for MySQL 8.0 confirmed as managed DB (NOT TiDB — breaks `FOR UPDATE` pessimistic locking)
- Init: MySQL-backed `DatabaseSessionHandler` for sessions (reuses existing DB, no Redis dependency)
- Init: `Schema::ensure()` moved out of request path to `bin/migrate.php` one-shot CLI
- 01-01: Used vercel.json `rewrites` (source/destination) not legacy `routes` — preserves static file CDN serving for CSS/JS (DEPLOY-02)
- 01-01: Omitted `memory` field from vercel.json functions block — Fluid Compute enabled by default rejects it on new Vercel projects
- 01-01: api/index.php is a 3-line require wrapper around public/index.php — D-01 pattern, zero change to existing code (DEPLOY-03)
- 02-01: SSL options gated on is_file(certPath) so local Docker dev (no cert) connects plaintext; Aiven (cert present) uses TLS with VERIFY_SERVER_CERT=true
- 02-01: Cert path resolved via dirname(__FILE__,3) — absolute and cwd-independent on both Lambda and operator CLI
- 02-01: Schema::ensure() removed from Database::connection() (DB-03) — all DDL lives exclusively in bin/migrate.php
- 03-01: DatabaseSessionHandler in App\Core reuses Database::connection() singleton — no new PDO; row-alias UPSERT (MySQL 8.0.19+); lazy expires_at read; session.gc_probability=0
- 03-01: Auth::start() registers handler before session_start(); cookie lifetime changed from 0 to 30*86400 (30 days); Csrf.php + login/logout untouched
- 04-01: Full aws/aws-sdk-php SDK ships in Lambda (~37.5MB) — no removeUnusedServices because vercel-php uses --no-scripts; fits within 250MB limit
- 04-01: CSP img-src updated in plan 04-01 (not deferred to Phase 5) — STORE-02 images display is false while r2.dev blocked; pre-satisfies Phase 5 SEC-03
- 04-01: Full r2.dev URL stored in DB (D-05); views render img src unchanged; key derived by stripping base URL at delete time
- 04-01: PHP class constants cannot use cast expressions; MAX_UPLOAD_BYTES = 3670016 (literal, =(int)(3.5*1024*1024)) with comment documentation
- 05-01: HTTPS detection via HTTP_X_FORWARDED_PROTO (Vercel edge) not APP_ENV — zero operator step (D-01); $_SERVER['HTTPS'] as fallback
- 05-01: Boot gate placed after /health early-return and before Auth::start() — keep-alive always responds (D-04)
- 05-01: App-level HSTS max-age=31536000; includeSubDomains, no preload-list submission — Vercel platform already emits 2-year HSTS; app header is defense-in-depth (D-02)
- 05-01: Boot gate error page is generic French HTML; specific reason goes to error_log only — no config state leaked to HTTP (RESEARCH Pitfall 4)
- 06-01: productsMeta() N+1 → 3 fixed queries (Purchase::lotsForUser + Sale::soldQtyByProduct, grouped in PHP); ProfitCalculator::cump/stock unchanged
- 06-01: ExchangeRateService rewritten to curl (5s timeout, !==200, null on all failure paths, error_log each)
- 06-01: PurchaseController validate() server-side FX fallback + block before unitCostEur on null (no silent 0.00); covers store()+update()
- 06-01 (fix): FX API endpoint api.frankfurter.app → api.frankfurter.dev/v1 (the .app domain was retired → 301); fixed in ExchangeRateService, app.js (x2), CSP connect-src
- [Phase 08]: 08-01: orders.supplier_id added only via guarded Schema::ensure() ALTER (ON DELETE SET NULL, D-03/D-08), never in schema.sql orders block; suppliers table mirrors the fk_purchases_order idempotency pattern
- [Phase 08]: 08-02: ProductController::destroy() collects cover+gallery Cloudinary paths BEFORE Product::delete() (CASCADE wipes product_images), dedupes via array_unique, then purges best-effort in try/catch over Throwable + error_log — never blocks the DB delete (D-11/OPS-06)
- [Phase ?]: [Phase 08]: 08-03: Supplier model mirrors Order.php (LEFT JOIN orders COUNT(o.id) AS orders_count GROUP BY for D-07; :uid-scoped; find() returns null when not owned; delete() plain DELETE — FK ON DELETE SET NULL handles unlink per D-08)
- [Phase ?]: [Phase 08]: 08-03: SupplierController clones ProductController CRUD (Auth::require() ctor; Csrf::validate() first line of every POST; ownership-or-redirect on edit/update; validate() enforces name required + rating in {null,1..5}); no show action / no GET /suppliers/{id} (D-09 table-only); URL stored trimmed/null without filter_var (matches order_url convention)
- [Phase ?]: [Phase 08]: 08-03: SUP-01 backend only — model/controller/6 routes (create before {id}/edit)/Fournisseurs nav (bi-truck, single navHtml line covers sidebar+offcanvas, D-10). Views land in 08-04; SUP-01 requirement NOT marked complete until 08-04

### Pending Todos

None yet.

### Blockers/Concerns

- Phase 2: Aiven free-tier concurrent connection limit is not publicly disclosed — treat as unknown until first load test. Mitigation path is ProxySQL if limit is hit.
- Phase 4: Existing products in Docker dev have `/assets/uploads/...` image paths that will 404 on Vercel. A migration or fallback strategy must be applied before go-live.

## Deferred Items

Items acknowledged and carried forward from research:

| Category | Item | Status | Deferred At |
|----------|------|--------|-------------|
| v2 / OPS | Health endpoint `/health` (DB status) | DONE 2026-06-15 (pulled forward for keep-alive; connection-count metric still deferred) | Init |
| v2 / OPS | Graceful 503 page on DB failure | Deferred | Init |
| v2 / OPS | Preview deploy DB isolation (`VERCEL_ENV`) | Deferred | Init |
| v2 / OPS | ProxySQL pooler if Aiven connection ceiling hit | Deferred | Init |
| v2 / OPS | Custom domain | Deferred | Init |
| v2 / HARD | CSRF token rotation after each successful POST | Deferred | Init |
| v2 / HARD | Rate limiting on `/register` and `/export/*` | Deferred | Init |

## Session Continuity

Last session: 2026-06-15T19:31:36.235Z
Stopped at: Phase 8 context gathered
Resume file: None
