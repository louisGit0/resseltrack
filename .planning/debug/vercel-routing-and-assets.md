---
slug: vercel-routing-and-assets
status: resolved
trigger: "Phase 1 Vercel deployment built (READY) but broken: PHP wrapper can't require public/index.php in the Lambda, and static assets are served through PHP instead of the CDN."
created: 2026-06-12
updated: 2026-06-12
phase: 01-routing-and-front-controller
---

# Debug Session: vercel-routing-and-assets

## Symptoms

**Expected behavior:**
- Opening any app route on the public Vercel URL returns PHP-rendered HTML (DEPLOY-01).
- `/assets/css/style.css` and `/assets/js/app.js` are served directly by the Vercel CDN — `content-type: text/css`/`application/javascript`, an `x-vercel-cache` header, and NO `x-powered-by: PHP` (DEPLOY-02).
- Local `docker compose up` still serves the app through Apache, `public/.htaccess` unchanged (DEPLOY-03).

**Actual behavior:**
1. DEPLOY-01 FAILS — every route (incl. `/` and `/login`) returns an HTTP 200 page containing a PHP fatal:
   ```
   Warning: require(/var/task/user/public/index.php): Failed to open stream:
   No such file or directory in /var/task/user/api/index.php on line 3
   Fatal error: Uncaught Error: Failed opening required '/var/task/user/public/index.php'
   ```
   The rewrite reaches `api/index.php`, but `public/index.php` (and `src/`) are NOT in the Lambda filesystem, so `require dirname(__DIR__) . '/public/index.php'` fatals.
2. DEPLOY-02 FAILS — `GET /assets/css/style.css` returns `Content-Type: text/html`, `X-Powered-By: PHP/8.3.8`, `X-Vercel-Cache: MISS`. Static assets are routed through the PHP function, not the CDN. Same for `/assets/js/app.js`.

**Error messages:** see the require/fatal above (returned in the HTML body of every route).

**Timeline:** First-ever Vercel deployment of this app (Phase 1). Never worked on Vercel; works fine in local Docker.

**Reproduction:**
```bash
curl -s  https://resseltrack-nu.vercel.app/            # PHP require fatal
curl -sI https://resseltrack-nu.vercel.app/assets/css/style.css   # text/html + X-Powered-By: PHP
```
Public production alias: https://resseltrack-nu.vercel.app (deployment-hash URLs are SSO-401, do not use).

## Environment

- Vercel team `louisgit0s-projects`, project `resseltrack` (projectId prj_4SplAfOzTf0gNB4DULjWOScMIvZQ), runtime `vercel-php@0.7.4` (PHP 8.3.8), Node 24.x.
- Latest deployment `dpl_E6PU5oxpvLxSA4PXUFwNSU1kfiAR` — readyState READY, target production. Build succeeded.
- Repo root (NESTED): `C:\Users\louis\Desktop\projets perso\RST\reselltrack\reselltrack`. Local files confirmed present: `public/index.php`, `public/assets/css/style.css`, `public/assets/js/app.js`.
- Config files created by plan 01-01 (the suspect): `vercel.json`, `api/index.php`, `api/php.ini`, `.vercelignore` at repo root.
- `vercel.json` current shape: `outputDirectory: "public"`, `functions["api/index.php"] = { runtime: "vercel-php@0.7.4", maxDuration: 30 }`, single `rewrites` rule `source /(.*) → destination /api/index.php`. No `routes`, no `memory`, no `buildCommand`.
- `.vercelignore` excludes: /vendor, /.git, /docker, /docker-compose.yml, /Dockerfile, /.planning, /tests, /sql, .env, .env.example (does NOT exclude public/, src/, composer.json).
- App autoloader is self-contained (spl_autoload_register in public/index.php); zero third-party Composer runtime deps.
- Redeploy mechanism: user pushes to GitHub `main` → Vercel auto-deploys. The agent cannot trigger deploys; it prepares the config fix and commits.

## Leading Hypothesis (from orchestrator triage)

`outputDirectory: "public"` causes BOTH failures: (a) it relocates `public/` to the static-output root so it is excluded from the serverless function bundle → the wrapper's dynamic `require` of `public/index.php` can't resolve; (b) combined with the catch-all `rewrites /(.*) → /api/index.php`, asset requests are funneled into PHP instead of being served from the CDN. Fix is expected to be config-only (rework `vercel.json`, possibly `api/` layout / `.vercelignore`) — confirm against vercel-php@0.7.4 bundling + static-serving behavior before changing.

## Current Focus

- hypothesis: CONFIRMED — two compounding root causes found (see Evidence).
- test: CONFIRMED — vercel.json fixed, app source files staged for commit.
- expecting: push to GitHub main will resolve both DEPLOY-01 and DEPLOY-02.
- next_action: user pushes to GitHub, verifies deployment.

## Evidence

- 2026-06-12: Both failures reproduce on `/` and `/login` on the public alias; local files all present; DEPLOY-03 (Docker) unaffected — points to config, not code.
- 2026-06-12: Deployment is READY/production, so the build accepted vercel.json and the rewrite IS active (errors originate from api/index.php) — the bug is bundling + static precedence, not a build failure.
- 2026-06-12: `git ls-files --cached` reveals ONLY the following committed files: `.planning/**`, `.vercelignore`, `CLAUDE.md`, `api/index.php`, `api/php.ini`, `vercel.json`. All app source (`public/`, `src/`, `composer.json`) are **untracked** (`??` in `git status`). This is the PRIMARY cause: Vercel deploys from the git repo and only sees committed files; `public/index.php` was never pushed to GitHub and therefore never present in the Lambda.
- 2026-06-12: SECONDARY cause confirmed: `outputDirectory: "public"` in vercel.json causes Vercel to treat `public/` as CDN-only static output and exclude it from the Lambda bundle. Additionally, for a PHP project with no build step, `outputDirectory: "public"` does NOT reliably serve files from `public/` as CDN assets (confirmed by DEPLOY-02 failure: assets routing through PHP even though `outputDirectory: "public"` was set).

## Eliminated

- vercel.json syntax error: config was valid JSON and accepted by Vercel build.
- Network/deployment failure: build is READY, rewrite is active (Lambda executes and returns PHP error).
- `api/index.php` wrong path: `require dirname(__DIR__) . '/public/index.php'` is correct PHP — `__DIR__` inside `public/index.php` always resolves to its own directory regardless of where it is required from (RESEARCH Pitfall 3 verified).

## Resolution

**Root cause (primary):** All application source files (`public/`, `src/`, `composer.json`) are untracked in git and were never committed. Vercel deploys from GitHub and only sees committed files. The Lambda bundle contained only `api/index.php`, `api/php.ini`, `vercel.json`, `.vercelignore` — zero application code.

**Root cause (secondary):** `outputDirectory: "public"` in `vercel.json` instructs Vercel's build system to treat `public/` as CDN-only static output and remove it from the Lambda function bundle. For PHP projects with no build step this also fails to set up CDN static serving reliably (DEPLOY-02 broken: assets returning `X-Powered-By: PHP`). The 01-RESEARCH.md had a gap on this: it assumed `outputDirectory: "public"` + `rewrites` would preserve static-file priority for CDN serving AND keep `public/` in the Lambda bundle. Neither assumption held in practice.

**Fix applied:**
1. `vercel.json` — removed `outputDirectory: "public"`, added explicit `/assets/(.*)` → `/public/assets/$1` rewrite before the PHP catch-all. Without `outputDirectory`, all project files (minus `.vercelignore` exclusions) are in the Lambda bundle. The asset rewrite routes `/assets/*` to the static file at `public/assets/*` (Vercel serves non-function project files as CDN-cached static assets). The catch-all rewrite routes everything else to `api/index.php`.
2. Application source committed to git: `public/`, `src/`, `composer.json`, `.gitignore`, `.env.example`, and remaining project files. These were never previously committed; without them Vercel had no application code to bundle.

**Files changed:** `vercel.json` (config fix), plus `git add public/ src/ composer.json ...` to stage previously untracked app source.

**Verification pending:** user must push to GitHub main; Vercel auto-deploys. Expected: `curl -s https://resseltrack-nu.vercel.app/login` returns HTML form; `curl -sI https://resseltrack-nu.vercel.app/assets/css/style.css` returns `content-type: text/css` + `x-vercel-cache` header + no `x-powered-by: PHP`.
