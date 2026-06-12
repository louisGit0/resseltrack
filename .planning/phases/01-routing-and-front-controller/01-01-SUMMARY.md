---
phase: 01-routing-and-front-controller
plan: "01"
subsystem: deployment-config
tags: [vercel, routing, serverless, php, config]
dependency_graph:
  requires: []
  provides: [vercel-routing-config, api-entry-wrapper, php-runtime-settings, deploy-bundle-exclusions]
  affects: [vercel-deployment]
tech_stack:
  added: [vercel-php@0.7.4]
  patterns: [vercel-rewrites-catch-all, php-require-wrapper, outputDirectory-static-serving]
key_files:
  created:
    - api/index.php
    - api/php.ini
    - vercel.json
    - .vercelignore
  modified: []
decisions:
  - "Used vercel.json rewrites (source/destination) instead of legacy routes (src/dest) to preserve static file CDN serving"
  - "Omitted memory field from vercel.json functions block — Fluid Compute enabled by default on new Vercel projects"
  - "api/index.php is a 3-line require wrapper; no $root redefinition, no $_SERVER manipulation per D-01 and RESEARCH Pitfall 3"
  - "api/php.ini disables exec/passthru/shell_exec/system/proc_open/popen but does NOT disable allow_url_fopen or curl (ExchangeRateService needs outbound HTTP)"
  - "No buildCommand added to vercel.json — reserved as contingency for Plan 01-02 if class-not-found errors appear"
metrics:
  duration: "~2 minutes"
  completed_date: "2026-06-12"
  tasks_completed: 2
  files_created: 4
  files_modified: 0
---

# Phase 1 Plan 01: Vercel Routing and Entry Wrapper Summary

**One-liner:** Created four Vercel config files (vercel.json with rewrites catch-all, api/index.php one-line require wrapper, api/php.ini PHP runtime settings, .vercelignore bundle exclusions) to make ResellTrack deployable on Vercel serverless without modifying any existing application code.

## Tasks Completed

| Task | Name | Commit | Files |
|------|------|--------|-------|
| 1 | Create api/ serverless entry wrapper and runtime config | ca45b16 | api/index.php, api/php.ini |
| 2 | Create vercel.json routing config and .vercelignore bundle exclusions | a3544c0 | vercel.json, .vercelignore |

## What Was Built

### api/index.php
A three-line PHP file that serves as the Vercel serverless function entry point. Opens with `<?php` and `declare(strict_types=1);` following project convention, then delegates to the existing front controller via `require dirname(__DIR__) . '/public/index.php';`. This wrapper pattern (D-01) leaves `public/index.php` and `public/.htaccess` completely untouched, preserving local Docker development.

### api/php.ini
PHP runtime configuration for the serverless function. Sets `variables_order = EGPCS` to populate `$_ENV` from Vercel's OS-level environment injection, `memory_limit = 256M` for autoload headroom, and disables dangerous shell functions. Does not disable `allow_url_fopen` or curl — required by `ExchangeRateService`.

### vercel.json
Vercel project configuration at the repo root. Key properties:
- `outputDirectory: "public"` — serves static files (CSS, JS) directly from the CDN without invoking PHP
- `functions["api/index.php"].runtime: "vercel-php@0.7.4"` — pins PHP 8.3.x runtime per D-05
- `functions["api/index.php"].maxDuration: 30` — headroom for outbound HTTP calls
- `rewrites: [{ source: "/(.*)", destination: "/api/index.php" }]` — catch-all forwards dynamic requests to the PHP function
- No `routes` key (would break static serving), no `memory` field (Fluid Compute incompatible), no `buildCommand` (reserved contingency)

### .vercelignore
Deployment bundle exclusion list with 10 entries: `/vendor`, `/.git`, `/docker`, `/docker-compose.yml`, `/Dockerfile`, `/.planning`, `/tests`, `/sql`, `.env`, `.env.example`. Does not exclude `public/`, `src/`, `composer.json`, or `public/.htaccess`.

## Requirements Addressed

| Requirement | Status | Evidence |
|-------------|--------|----------|
| DEPLOY-01: All routes reach PHP front controller | Config ready | vercel.json rewrites catch-all → api/index.php → public/index.php |
| DEPLOY-02: Static assets served by CDN | Config ready | outputDirectory: "public" + rewrites (not routes) ensures static-first serving |
| DEPLOY-03: Local Docker dev unchanged | Verified | public/index.php and public/.htaccess are byte-for-byte unchanged (git diff confirms) |

## Deviations from Plan

None — plan executed exactly as written. All four files created with the exact content specified in RESEARCH Pattern 1-4. No existing files modified.

## Known Stubs

None. This plan creates configuration files only; there is no data flow or UI rendering involved.

## Threat Flags

No new security surface beyond what is documented in the plan's threat model. The `.vercelignore` belt-and-suspenders for `.env` and `.env.example` are in place (T-1-01 mitigated).

## Self-Check: PASSED

| Check | Result |
|-------|--------|
| api/index.php exists | FOUND |
| api/php.ini exists | FOUND |
| vercel.json exists | FOUND |
| .vercelignore exists | FOUND |
| Commit ca45b16 exists | FOUND |
| Commit a3544c0 exists | FOUND |
| public/index.php unmodified | CONFIRMED (not in git diff) |
| public/.htaccess unmodified | CONFIRMED (not in git diff) |

Note: `.planning/config.json` shows as modified in `git diff HEAD` — this was a pre-existing modification present before plan execution started and was not staged or touched by this plan.
