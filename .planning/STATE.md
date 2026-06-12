# Project State

## Project Reference

See: .planning/PROJECT.md (updated 2026-06-12)

**Core value:** Tout ce qui fonctionne en local fonctionne à l'identique une fois déployé sur Vercel — le site en ligne est pleinement opérationnel pour de vrais utilisateurs.
**Current focus:** Phase 1 — Routing and Front Controller

## Current Position

Phase: 1 of 7 (Routing and Front Controller)
Plan: 0 of ? in current phase
Status: Ready to plan
Last activity: 2026-06-12 — Roadmap created; 22 v1 requirements mapped across 7 phases

Progress: [░░░░░░░░░░] 0%

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

## Accumulated Context

### Decisions

Decisions are logged in PROJECT.md Key Decisions table.
Recent decisions affecting current work:

- Init: Cloudflare R2 + `aws/aws-sdk-php` v3 chosen for image storage (NOT Vercel Blob — no official PHP SDK)
- Init: Aiven for MySQL 8.0 confirmed as managed DB (NOT TiDB — breaks `FOR UPDATE` pessimistic locking)
- Init: MySQL-backed `DatabaseSessionHandler` for sessions (reuses existing DB, no Redis dependency)
- Init: `Schema::ensure()` moved out of request path to `bin/migrate.php` one-shot CLI

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

Last session: 2026-06-12
Stopped at: Roadmap and STATE.md created; REQUIREMENTS.md traceability updated. Ready to run `/gsd:plan-phase 1`.
Resume file: None
