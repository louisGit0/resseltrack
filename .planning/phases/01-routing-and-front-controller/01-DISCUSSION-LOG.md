# Phase 1: Routing and Front Controller - Discussion Log

> **Audit trail only.** Do not use as input to planning, research, or execution agents.
> Decisions are captured in CONTEXT.md — this log preserves the alternatives considered.

**Date:** 2026-06-12
**Phase:** 1-routing-and-front-controller
**Areas discussed:** Entry Point, Deployment Mechanism, Static Assets, PHP Runtime

---

## Entry Point Strategy

| Option | Description | Selected |
|--------|-------------|----------|
| Wrapper api/index.php | Keep public/index.php unchanged; api/index.php requires it. Local Docker dev 100% unchanged. | ✓ |
| Move to api/index.php | Move public/index.php → api/index.php (research reco). Cleaner but forces Docker docroot change. | |

**User's choice:** Wrapper api/index.php
**Notes:** Prioritizes zero regression to local Docker development (DEPLOY-03).

---

## Deployment Mechanism

| Option | Description | Selected |
|--------|-------------|----------|
| GitHub integration | Push repo to GitHub + connect to Vercel → auto-deploy on push, free preview deploys. Needs a GitHub repo. | ✓ |
| Vercel CLI (manual) | Install Vercel CLI and deploy on demand. No GitHub needed, but manual each time. | |

**User's choice:** GitHub integration
**Notes:** Local git repo has no remote yet — pushing to GitHub + creating a free Vercel account are prerequisite operator actions.

---

## Static Assets

| Option | Description | Selected |
|--------|-------------|----------|
| Defer uploads to Phase 4 | CSS/JS via CDN now; /assets/uploads handled in Phase 4 (R2). Existing images may 404 temporarily. | ✓ |
| Handle everything now | Pull R2 storage forward into Phase 1 (large scope expansion). | |

**User's choice:** Defer uploads to Phase 4
**Notes:** Keeps Phase 1 focused on routing; temporary image 404s acceptable until Phase 4.

---

## PHP Runtime

| Option | Description | Selected |
|--------|-------------|----------|
| Pin vercel-php@0.7.4 / PHP 8.3 | Exact match with local dev, maximum stability. | ✓ |
| Latest PHP version | Track latest supported (PHP 8.4+), risk of subtle drift from local dev. | |

**User's choice:** Pin vercel-php@0.7.4 / PHP 8.3
**Notes:** Bump deferred until the deployment is stable.

---

## Claude's Discretion

- Exact `vercel.json` structure, `.vercelignore` contents, and whether `vendor/` is committed or built on Vercel (recommendation: keep `vendor/` gitignored, let Vercel `composer install`).

## Deferred Ideas

- Aiven MySQL (Phase 2), MySQL sessions (Phase 3), Cloudflare R2 image storage + existing-image migration (Phase 4), HSTS/CSP/SESSION_SECURE (Phase 5) — all pre-existing roadmap phases, not new capabilities.
