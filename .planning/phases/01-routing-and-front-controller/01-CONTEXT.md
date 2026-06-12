# Phase 1: Routing and Front Controller - Context

**Gathered:** 2026-06-12
**Status:** Ready for planning

<domain>
## Phase Boundary

Make ResellTrack reachable on Vercel serverless: all routes reach the PHP front controller via `vercel.json` (replacing the Apache `.htaccess` rewrite), static CSS/JS are served by the Vercel CDN without invoking PHP, and local Docker development remains unchanged. Covers DEPLOY-01, DEPLOY-02, DEPLOY-03.

Out of this phase (already in roadmap): external database (Phase 2), sessions (Phase 3), uploaded-image storage on R2 (Phase 4), security hardening (Phase 5), performance fixes (Phase 6).

</domain>

<decisions>
## Implementation Decisions

### Entry Point Strategy
- **D-01:** Use a **wrapper** `api/index.php` that `require`s the existing `public/index.php` front controller (e.g. via `dirname(__DIR__).'/public/index.php'`). Do NOT move or modify `public/index.php`. Rationale: keeps local Docker dev 100% unchanged (DEPLOY-03), zero regression risk. The PSR-4 autoloader, env loading, session start, security headers, and route registration all stay in `public/index.php`.

### Deployment Mechanism
- **D-02:** Deploy via **GitHub → Vercel integration** (auto-deploy on push, free preview deploys), NOT the Vercel CLI. Prerequisite: the repo must be pushed to a GitHub remote (currently only a local git repo exists, no remote yet) and connected to a free Vercel account. Account creation and the GitHub push are user/operator actions the plan must call out.

### Static Assets
- **D-03:** CSS/JS served directly by the Vercel CDN this phase. `vercel.json` must route static files/`/assets/*` so they bypass the PHP function (use `rewrites`, not legacy `routes`); a naive catch-all that sends `.css`/`.js` through PHP is the documented pitfall to avoid.
- **D-04:** Uploaded images under `/assets/uploads` are **NOT** handled in this phase — deferred to Phase 4 (Cloudflare R2). Existing product images that reference local upload paths may 404 on the Vercel deploy temporarily; this is acceptable until Phase 4.

### PHP Runtime
- **D-05:** Pin **`vercel-php@0.7.4` (PHP 8.3.x)** in `vercel.json` to match local dev exactly. Defer any PHP version bump (0.8.0 / PHP 8.4) until the deployment is stable.

### Claude's Discretion
- Exact `vercel.json` structure, `.vercelignore` contents, and whether `vendor/` is committed or built on Vercel. Recommendation for the planner: Vercel runs `composer install` during build, so keep `vendor/` gitignored (do not commit it) and let Vercel build dependencies.

</decisions>

<canonical_refs>
## Canonical References

**Downstream agents MUST read these before planning or implementing.**

### Vercel routing, runtime & config
- `.planning/research/STACK.md` — `vercel.json` config snippet, `vercel-php@0.7.4` runtime pin, env-var mapping to the existing `Env` class
- `.planning/research/ARCHITECTURE.md` — `api/index.php` entry strategy, `rewrites` vs `routes`, static-asset serving, `dirname(__DIR__)` path resolution from `api/`
- `.planning/research/PITFALLS.md` — routing pitfalls: `.htaccess` ignored by Vercel, static assets wrongly routed through the PHP Lambda, static-vs-parameterized route ordering

### Current app routing (to preserve)
- `.planning/codebase/ARCHITECTURE.md` — current Front Controller + Router, route registration in `public/index.php`, the load-bearing route-ordering constraint
- `.planning/codebase/STRUCTURE.md` — file layout (`public/`, `src/`, autoloader paths)

</canonical_refs>

<code_context>
## Existing Code Insights

### Reusable Assets
- `public/index.php`: the entire front controller (PSR-4 autoloader, `Env::load()`, `Auth::start()`, security headers, route registration, `Router::dispatch()`) — reused **as-is** via the `api/index.php` wrapper (D-01).
- `public/.htaccess`: local Apache rewrite to `index.php` — preserved untouched for Docker dev (DEPLOY-03).
- `src/Core/Router.php`: regex front-controller router — no change needed for this phase.

### Established Patterns
- Single entry point + front controller; PSR-4 autoloader built into `index.php` using `dirname`-based paths, which resolve identically whether entered from `public/` or `api/`.
- Route ordering is load-bearing: static segments (`/products/create`) are registered before parameterized ones (`/products/{id}`) in `public/index.php` — must remain intact.

### Integration Points
- `vercel.json` (new, project root): replaces `.htaccess` behavior on Vercel only; `outputDirectory`/static carve-out serves assets from CDN.
- `api/index.php` (new): the Vercel PHP function entry point; requires `public/index.php`.
- `.vercelignore` (new): exclude dev-only files (Docker, tests, `.planning/` optional) from the deployment bundle.

</code_context>

<specifics>
## Specific Ideas

- The user must create a free **Vercel account** and a **GitHub repository**, then connect them. The local git repo has no remote yet — pushing to GitHub is the first operator step before any Vercel deploy is possible.
- Keep `.env` gitignored (already is); secrets will be set as Vercel env vars in later phases.

</specifics>

<deferred>
## Deferred Ideas

- External Aiven MySQL connection + TLS — Phase 2 (already in roadmap).
- MySQL-backed session handler — Phase 3 (already in roadmap).
- Cloudflare R2 image storage + one-time migration of existing local-path images — Phase 4 (already in roadmap).
- HSTS / CSP / `SESSION_SECURE=1` — Phase 5 (already in roadmap).

None — discussion stayed within phase scope (all deferrals are pre-existing roadmap phases, not new capabilities).

</deferred>

---

*Phase: 1-routing-and-front-controller*
*Context gathered: 2026-06-12*
