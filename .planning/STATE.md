---
gsd_state_version: 1.0
milestone: v1.0
milestone_name: milestone
status: executing
stopped_at: "Phase 2 complete — live Aiven MySQL verified end-to-end"
last_updated: "2026-06-12T15:10:00.000Z"
last_activity: 2026-06-12 -- Phase 2 complete; DB-01/02/03 verified live (registration persisted to Aiven over TLS)
progress:
  total_phases: 7
  completed_phases: 2
  total_plans: 4
  completed_plans: 4
  percent: 29
---

# Project State

## Project Reference

See: .planning/PROJECT.md (updated 2026-06-12)

**Core value:** Tout ce qui fonctionne en local fonctionne à l'identique une fois déployé sur Vercel — le site en ligne est pleinement opérationnel pour de vrais utilisateurs.
**Current focus:** Phase 3 — Persistent Sessions (next)

## Current Position

Phase: 2 of 7 COMPLETE (Database and Schema Migration) → next: Phase 3
Plan: 2 of 2 in Phase 2 (both complete)
Status: Phase 2 verified live — Aiven MySQL 8.4 reachable over TLS; registration persists to Aiven
Last activity: 2026-06-12 -- 02-02 complete; DB-01/02/03 verified live; test user cleaned up

Progress: [███░░░░░░░] 29% (2/7 phases)

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

### Pending Todos

None yet.

### Blockers/Concerns

- Phase 2: Aiven free-tier concurrent connection limit is not publicly disclosed — treat as unknown until first load test. Mitigation path is ProxySQL if limit is hit.
- Phase 4: Existing products in Docker dev have `/assets/uploads/...` image paths that will 404 on Vercel. A migration or fallback strategy must be applied before go-live.

## Deferred Items

Items acknowledged and carried forward from research:

| Category | Item | Status | Deferred At |
|----------|------|--------|-------------|
| v2 / OPS | Health endpoint `/health` (DB status + connection count) | Deferred | Init |
| v2 / OPS | Graceful 503 page on DB failure | Deferred | Init |
| v2 / OPS | Preview deploy DB isolation (`VERCEL_ENV`) | Deferred | Init |
| v2 / OPS | ProxySQL pooler if Aiven connection ceiling hit | Deferred | Init |
| v2 / OPS | Custom domain | Deferred | Init |
| v2 / HARD | CSRF token rotation after each successful POST | Deferred | Init |
| v2 / HARD | Rate limiting on `/register` and `/export/*` | Deferred | Init |

## Session Continuity

Last session: 2026-06-12T13:51:04.242Z
Stopped at: Phase 1 context gathered
Resume file: None
