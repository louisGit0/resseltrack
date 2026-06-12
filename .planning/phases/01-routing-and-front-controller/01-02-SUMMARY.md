---
phase: 01-routing-and-front-controller
plan: "02"
subsystem: deployment
tags: [vercel, deployment, github, verification, operator]
dependency_graph:
  requires: [vercel-routing-config, api-entry-wrapper]
  provides: [live-vercel-deployment, deploy-smoke-verified]
  affects: [vercel-deployment]
tech_stack:
  added: []
  patterns: [github-vercel-autodeploy, cdn-static-asset-serving]
key_files:
  created: []
  modified:
    - vercel.json
decisions:
  - "Connected repo to Vercel via GitHub integration (D-02); auto-deploy on push to main"
  - "Removed outputDirectory:public and added explicit /assets/(.*) -> /public/assets/$1 rewrite (debug fix, supersedes 01-01 config) — see debug session vercel-routing-and-assets"
  - "Primary deploy blocker was that app source (public/, src/, composer.json) was never committed to git — fixed by committing all source in 0e3fb9d"
metrics:
  completed_date: "2026-06-12"
  tasks_completed: 3
  files_created: 0
  files_modified: 1
---

# Phase 1 Plan 02: Vercel Deployment & Verification Summary

**One-liner:** Connected the repo to Vercel via GitHub auto-deploy, surfaced and fixed two deployment-blocking bugs (uncommitted application source + a faulty `outputDirectory` config), and verified DEPLOY-01/02/03 green on the live public URL.

## Tasks Completed

| Task | Name | Result |
|------|------|--------|
| 1 | Create GitHub repo + Vercel account, push repository | `origin` → github.com/louisGit0/resseltrack; Vercel project `resseltrack` on team `louisgit0s-projects` |
| 2 | Import repo into Vercel, trigger first deploy | First deploy READY but functionally broken (see Deviations); refixed and redeployed |
| 3 | Verify DEPLOY-01/02/03 on live deployment | All three PASS on https://resseltrack-nu.vercel.app (commit 0e3fb9d) |

## Live Verification Results (deployment dpl_5NJhLkdN1tHmBPfVq9tmXk3oKeJo, commit 0e3fb9d)

| Requirement | Status | Evidence |
|-------------|--------|----------|
| DEPLOY-01: routes reach PHP | PASS | `GET /login` → HTTP 200, renders `<form>` ("Connexion — ResellTrack"), no PHP fatal |
| DEPLOY-02: assets via CDN | PASS | `/assets/css/style.css` → `content-type: text/css` + `x-vercel-cache: HIT`, no `x-powered-by`; `/assets/js/app.js` → `application/javascript` + cache HIT |
| DEPLOY-03: Docker dev unchanged | PASS | `public/index.php` and `public/.htaccess` byte-for-byte unchanged (committed identical to working tree) |

Public production alias: https://resseltrack-nu.vercel.app (deployment-hash URLs are SSO-401).

## Deviations from Plan

The plan assumed a clean first deploy from the 01-01 config. Two blocking bugs surfaced instead, resolved via debug session `vercel-routing-and-assets` (commit `0e3fb9d`):

1. **Primary — application source never committed to git.** Only `.planning/` docs and the four config files were tracked; `public/`, `src/`, and `composer.json` were untracked. Vercel deploys from GitHub, so the Lambda had `api/index.php` but no app code to `require` → `require(...public/index.php): No such file or directory` on every route. Fixed by committing all 67 application source files.
2. **Secondary — `outputDirectory: "public"` config.** It excluded `public/` from the Lambda bundle and failed to CDN-serve assets for a no-build PHP project. Fixed by removing `outputDirectory` and adding an explicit `/assets/(.*)` → `/public/assets/$1` rewrite ordered before the PHP catch-all.

The 01-01 config (`outputDirectory: "public"`) is superseded by this fix. 01-RESEARCH.md had a gap: it assumed `outputDirectory: "public"` + `rewrites` preserved both static CDN serving and Lambda bundling of `public/` — neither held.

## Self-Check: PASSED

| Check | Result |
|-------|--------|
| Live /login renders form, no PHP fatal | CONFIRMED |
| /assets css/js served by CDN, no x-powered-by | CONFIRMED |
| public/index.php, public/.htaccess unchanged | CONFIRMED |
| App source committed and pushed (0e3fb9d) | CONFIRMED |
| Debug session resolved | CONFIRMED (.planning/debug/vercel-routing-and-assets.md) |
