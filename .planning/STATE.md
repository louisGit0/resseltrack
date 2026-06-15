---
gsd_state_version: 1.0
milestone: v1.0
milestone_name: milestone
status: executing
stopped_at: "Phase 4 Wave 1 deployed (R2 code + composer.lock fix + /health keep-alive); BLOCKED on operator powering Aiven back on"
last_updated: "2026-06-15T07:25:00.000Z"
last_activity: 2026-06-15 -- Phase 4 04-01 code live; Aiven free-tier powered off (DNS unresolved) — awaiting operator Power on; keep-alive added to prevent recurrence
progress:
  total_phases: 7
  completed_phases: 3
  total_plans: 8
  completed_plans: 7
  percent: 43
---

# Project State

## Project Reference

See: .planning/PROJECT.md (updated 2026-06-12)

**Core value:** Tout ce qui fonctionne en local fonctionne à l'identique une fois déployé sur Vercel — le site en ligne est pleinement opérationnel pour de vrais utilisateurs.
**Current focus:** Phase 4 — Image Storage on R2 (Wave 1 code deployed; blocked on Aiven power-on, then Wave 2 = R2 bucket)

## Current Position

Phase: 4 of 7 IN PROGRESS (Image Storage on R2) → Plan 04-01 code live; Plan 04-02 (operator R2 setup) pending
Plan: 1 of 2 in Phase 4 (04-01 deployed)
Status: 04-01 R2 code deployed (build green after composer.lock fix). /health + GitHub Actions keep-alive added. BLOCKED: Aiven free-tier DB powered off (DNS unresolved) — operator must Power on at console.aiven.io; then verify site + proceed to 04-02 (R2 bucket).
Last activity: 2026-06-15

Progress: [████░░░░░░] 43% (3/7 phases)

## Active blocker
- Aiven free-tier MySQL `resseltrack` powered off after ~3 days inactivity (DNS no longer resolves → site 500 "Database connection failed"). Operator chose to Power on via console. Keep-alive (/health pinged every 10 min by .github/workflows/keepalive.yml) now in place to prevent recurrence once back up. NOT a code regression — Phase 4 code/build are healthy.

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

Last session: 2026-06-15T07:06:04.691Z
Stopped at: Completed 04-01-PLAN.md (aws/aws-sdk-php + R2Storage service + ProductController disk→R2 swap — all 3 tasks committed, unpushed)
Resume file: None
