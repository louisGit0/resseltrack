# Phase 1: Routing and Front Controller — Research

**Researched:** 2026-06-12
**Domain:** Vercel serverless routing for PHP — vercel.json rewrites, api/ wrapper, static asset CDN carve-out
**Confidence:** HIGH (routing schema, wrapper approach, path resolution verified against official docs and codebase analysis)

---

<user_constraints>
## User Constraints (from CONTEXT.md)

### Locked Decisions

- **D-01:** Use a **wrapper** `api/index.php` that `require`s the existing `public/index.php` front controller (e.g. via `dirname(__DIR__).'/public/index.php'`). Do NOT move or modify `public/index.php`. Rationale: keeps local Docker dev 100% unchanged (DEPLOY-03), zero regression risk. The PSR-4 autoloader, env loading, session start, security headers, and route registration all stay in `public/index.php`.
- **D-02:** Deploy via **GitHub → Vercel integration** (auto-deploy on push, free preview deploys), NOT the Vercel CLI. Prerequisite: the repo must be pushed to a GitHub remote (currently only a local git repo exists, no remote yet) and connected to a free Vercel account. Account creation and the GitHub push are user/operator actions the plan must call out.
- **D-03:** CSS/JS served directly by the Vercel CDN this phase. `vercel.json` must route static files/`/assets/*` so they bypass the PHP function (use `rewrites`, not legacy `routes`); a naive catch-all that sends `.css`/`.js` through PHP is the documented pitfall to avoid.
- **D-04:** Uploaded images under `/assets/uploads` are **NOT** handled in this phase — deferred to Phase 4 (Cloudflare R2). Existing product images that reference local upload paths may 404 on the Vercel deploy temporarily; this is acceptable until Phase 4.
- **D-05:** Pin **`vercel-php@0.7.4` (PHP 8.3.x)** in `vercel.json` to match local dev exactly. Defer any PHP version bump (0.8.0 / PHP 8.4) until the deployment is stable.

### Claude's Discretion

- Exact `vercel.json` structure, `.vercelignore` contents, and whether `vendor/` is committed or built on Vercel. Recommendation for the planner: Vercel runs `composer install` during build, so keep `vendor/` gitignored (do not commit it) and let Vercel build dependencies.

### Deferred Ideas (OUT OF SCOPE)

- External Aiven MySQL connection + TLS — Phase 2
- MySQL-backed session handler — Phase 3
- Cloudflare R2 image storage + one-time migration of existing local-path images — Phase 4
- HSTS / CSP / `SESSION_SECURE=1` — Phase 5
</user_constraints>

<phase_requirements>
## Phase Requirements

| ID | Description | Research Support |
|----|-------------|------------------|
| DEPLOY-01 | The application responds to all its routes once deployed on Vercel (front controller reached via `vercel.json`, without Apache/`.htaccess`) | D-01 wrapper + D-05 runtime pin + `rewrites` catch-all in vercel.json |
| DEPLOY-02 | Static assets (CSS, JS) are served by the Vercel CDN without going through a PHP function | `outputDirectory: "public"` + `rewrites` (not `routes`) — static files are served before rewrites apply |
| DEPLOY-03 | Local Docker development continues to work unchanged (`.htaccess` preserved for dev) | D-01 keeps `public/index.php` and `public/.htaccess` completely untouched |
</phase_requirements>

---

## Summary

Phase 1 is a configuration-and-wrapper phase with zero changes to existing application code. Two new files are created (`api/index.php`, `vercel.json`) plus two supporting files (`api/php.ini`, `.vercelignore`), and a GitHub remote must be set up before Vercel can auto-deploy.

The central mechanism is Vercel's `rewrites` routing combined with `outputDirectory: "public"`. Under this setup, Vercel's CDN checks for a matching static file in `public/` first; only unmatched requests fall through to the single rewrite rule that forwards all paths to `api/index.php`. The `api/index.php` wrapper is a single `require` statement — PHP's `__DIR__` magic constant inside `public/index.php` always resolves to the file's own directory regardless of where it is `require`d from, so every path resolved from `$root = dirname(__DIR__)` in `public/index.php` computes correctly whether called by Apache or by Vercel.

The phase also requires manual operator steps (GitHub repo creation, Vercel account setup, project import) that cannot be automated and must be called out explicitly in the plan.

**Primary recommendation:** Create `vercel.json` with `outputDirectory: "public"` and a `rewrites` catch-all; create `api/index.php` as a one-line `require` wrapper; keep `public/index.php` and `public/.htaccess` completely unchanged.

---

## Architectural Responsibility Map

| Capability | Primary Tier | Secondary Tier | Rationale |
|------------|-------------|----------------|-----------|
| Dynamic request routing (PHP) | API / Backend (Vercel serverless function) | — | `api/index.php` is the single serverless entry point |
| Static asset serving (CSS, JS) | CDN / Static (Vercel Edge) | — | `outputDirectory: "public"` → served before any rewrite applies |
| Local dev routing (Apache) | Frontend Server (Docker) | — | `public/.htaccess` unchanged; Apache handles rewrite locally |
| Route dispatch (app-level) | API / Backend | — | `Router::dispatch()` inside `public/index.php`, unchanged |
| Auth, session, CSRF | API / Backend | — | In `public/index.php` — no change this phase |

---

## Standard Stack

### Core (Phase 1 only)

No new Composer packages are installed in this phase. All changes are configuration files and a one-line PHP wrapper.

| File | Purpose | Notes |
|------|---------|-------|
| `vercel.json` | Vercel routing + runtime config | New file at project root |
| `api/index.php` | Vercel PHP function entry point — wraps `public/index.php` | New file; single `require` |
| `api/php.ini` | PHP runtime settings for the serverless function | New file; sets `variables_order`, `memory_limit` |
| `.vercelignore` | Excludes dev-only files from deployment bundle | New file |

### Runtime (pinned)

| Runtime | Version | PHP Version | Source |
|---------|---------|-------------|--------|
| `vercel-php` (community runtime) | `0.7.4` | PHP 8.3.x | [VERIFIED: github.com/vercel-community/php CHANGELOG] — 0.7.x series introduced PHP 8.3 support (0.7.0 2024-02-22); 0.7.4 released 2025-07-17 "Upgrade PHP 7.4-8.3 runtimes for Node 22" |

### Alternatives Considered

| Instead of | Could Use | Tradeoff |
|------------|-----------|----------|
| `rewrites` catch-all | Legacy `routes` catch-all | `routes` overrides static file serving — CSS/JS route through PHP Lambda. Never use for this project. |
| `outputDirectory: "public"` | Explicit asset route rules | Explicit rules require maintaining a list of extensions; `outputDirectory` handles all static files automatically |
| `api/index.php` wrapper (D-01) | Move `public/index.php` to `api/` | Move would require updating all `__DIR__`-based paths and breaks Docker dev. Wrapper is safer. |

**Installation:** No `composer install` needed for Phase 1. Vercel runs `composer install --no-dev --optimize-autoloader` automatically at build time when it detects `composer.json` + `composer.lock`. [ASSUMED from vercel-community/php documentation and standard Vercel build behavior]

---

## Package Legitimacy Audit

> Phase 1 installs **no new Composer packages or npm packages**. The only dependency on an external package is the `vercel-php` community runtime, which is referenced by name in `vercel.json` and downloaded by Vercel's build system at deploy time — not installed locally.

| Package | Registry | Notes | Disposition |
|---------|----------|-------|-------------|
| `vercel-php@0.7.4` | npm (used by Vercel build system only) | Referenced in `vercel.json` runtime field; Vercel resolves it. Not installed via local `npm install`. | N/A — Vercel-managed |

**No slopcheck audit required:** This phase adds no locally-installed packages.

---

## Architecture Patterns

### System Architecture Diagram

```
HTTPS Request (Vercel Edge)
        │
        ▼
Does path match a static file in public/ ?
  ├── YES (e.g. /assets/css/style.css) ──► Vercel CDN serves file directly
  │                                        (no PHP invocation, Cache-Control headers)
  │
  └── NO (e.g. /login, /products/create) ─► vercel.json rewrite
                                              │
                                              ▼
                                    api/index.php (PHP 8.3 Lambda)
                                    require '../public/index.php'
                                              │
                                              ▼
                                    public/index.php (unchanged)
                                    • PSR-4 autoloader
                                    • Env::load() — reads from real env vars
                                    • Auth::start() — file sessions (Phase 3 fixes)
                                    • Security headers
                                    • Route registration
                                    • Router::dispatch($_SERVER['REQUEST_METHOD'],
                                                       $_SERVER['REQUEST_URI'])
                                              │
                                    First-match routing (order preserved)
                                              │
                                    Controller → Model → View
                                              │
                                    HTML response

Local Docker (unchanged)
HTTP Request
  │
  ▼
Apache + public/.htaccess
RewriteRule → public/index.php
(identical bootstrap path)
```

### Recommended Project Structure (changes only)

```
reselltrack/
├── api/
│   ├── index.php          ← NEW: one-line require wrapper
│   └── php.ini            ← NEW: PHP runtime settings for Vercel
├── public/
│   ├── .htaccess          ← UNCHANGED (Docker dev only; Vercel ignores)
│   ├── index.php          ← UNCHANGED (front controller)
│   └── assets/            ← UNCHANGED (served by CDN via outputDirectory)
├── vercel.json            ← NEW: routing + runtime config
└── .vercelignore          ← NEW: deployment bundle exclusions
```

### Pattern 1: vercel.json with `rewrites` + `outputDirectory`

**What:** Static files in `public/` are served by the Vercel CDN. All other requests are rewritten to `api/index.php`.

**When to use:** Any PHP project deploying a front controller to Vercel serverless.

**Exact file content:**

```json
{
  "$schema": "https://openapi.vercel.sh/vercel.json",
  "outputDirectory": "public",
  "functions": {
    "api/index.php": {
      "runtime": "vercel-php@0.7.4",
      "maxDuration": 30
    }
  },
  "rewrites": [
    { "source": "/(.*)", "destination": "/api/index.php" }
  ]
}
```

Sources: [VERIFIED: vercel.com/docs/project-configuration/vercel-json#rewrites] + [VERIFIED: vercel.com/docs/project-configuration/vercel-json#functions] + [VERIFIED: vercel.com/docs/routing/rewrites]

**Critical notes:**
- `memory` field is **NOT** included. As of April 23, 2025, Fluid Compute is enabled by default for new Vercel projects, and memory cannot be set in `vercel.json` when Fluid Compute is enabled — it must be configured in the Vercel dashboard Functions section instead. Default memory is 1024 MB, which is the same value STACK.md recommended. [VERIFIED: vercel.com/docs/project-configuration/vercel-json#functions — "Memory cannot be set in vercel.json with Fluid compute enabled"]
- `maxDuration: 30` is appropriate because `ExchangeRateService` makes outbound HTTP calls (Phase 6 will fix timeouts, but the 30s buffer is safe now).
- The `rewrites` field uses `source`/`destination` (not `src`/`dest` — those are the legacy `routes` API syntax). [VERIFIED: vercel.com/docs/routing/rewrites]
- `outputDirectory: "public"` makes files in `public/` (e.g., `assets/css/style.css`) available at their relative paths (`/assets/css/style.css`) via the CDN. The default output directory is already `public`, but setting it explicitly makes the intent clear. [CITED: vercel.com/docs/project-configuration/vercel-json#outputdirectory]

**How `rewrites` preserves static file priority:**
Vercel's routing pipeline resolves in this order: (1) static files from `outputDirectory`, (2) `rewrites`. A request for `/assets/css/style.css` matches `public/assets/css/style.css` and is served directly — the rewrite rule `/(.*) → /api/index.php` never fires for it. The legacy `routes` API does NOT have this behavior and would route assets through PHP. [CITED: vercel.com/docs/routing/rewrites — "rewrites route a request to a different destination without changing the URL" and SPA example confirming static-first priority]

**HSTS header NOT included in Phase 1 vercel.json.** HSTS is deferred to Phase 5 (SEC-02). Adding it now before sessions work properly (Phase 3) is premature. The security headers in `public/index.php` (X-Content-Type-Options, X-Frame-Options, Referrer-Policy, CSP) continue to apply via PHP.

### Pattern 2: `api/index.php` One-Line Wrapper

**What:** A single PHP file that delegates to the existing front controller.

**Exact file content:**

```php
<?php
declare(strict_types=1);
require dirname(__DIR__) . '/public/index.php';
```

Source: [VERIFIED: PHP manual `__DIR__` — magic constant resolves to the directory of the file in which it is used, regardless of how that file was included]

**Why this works correctly:**

`__DIR__` inside `public/index.php` ALWAYS resolves to the directory of `public/index.php` itself (i.e., `<project_root>/public`), regardless of whether the file is executed directly by Apache or `require`d from `api/index.php`. This is fundamental PHP behavior. Therefore:

```
$root = dirname(__DIR__)     # inside public/index.php
      = dirname('<root>/public')
      = '<root>'             # project root — correct whether called by Apache or Vercel
```

All paths derived from `$root` in `public/index.php` (autoloader: `$root . '/src/'`, env: `$root . '/.env'`) resolve correctly from `api/index.php`.

**What happens at runtime:**
1. Vercel invokes `api/index.php`
2. `require` loads `public/index.php` with correct `__DIR__`
3. PSR-4 autoloader registers `App\` → `<root>/src/`
4. `Env::load()` reads from real OS env vars (no `.env` file on Vercel — `Env::load()` already checks `is_file()` before reading)
5. `Auth::start()` starts file-based session (good enough for Phase 1; Phase 3 adds MySQL sessions)
6. Security headers emitted
7. Routes registered
8. `Router::dispatch($_SERVER['REQUEST_METHOD'], $_SERVER['REQUEST_URI'])` — `REQUEST_URI` contains the original path (e.g. `/products/create`), not `/api/index.php`

**`$_SERVER['REQUEST_URI']` under Vercel rewrites:** Vercel preserves the original request URI in `$_SERVER['REQUEST_URI']` when using `rewrites`. A request to `/products/create` has `$_SERVER['REQUEST_URI'] = '/products/create'`. The `Router::dispatch()` call in `public/index.php` reads this correctly and matches `/products/create` against the registered routes. [ASSUMED from standard Vercel rewrite behavior — rewrites do not modify the visible URL]

### Pattern 3: `api/php.ini`

**What:** PHP runtime configuration for the serverless function.

**Exact file content:**

```ini
; Ensure $_ENV superglobal is populated from OS-level env vars (Vercel injects at OS level).
; Env::get() uses getenv() which works without this, but belt-and-suspenders for any
; code that reads $_ENV directly.
variables_order = EGPCS

; Headroom for Composer autoload + request processing
memory_limit = 256M

; Disable dangerous functions not needed in serverless
disable_functions = exec,passthru,shell_exec,system,proc_open,popen
```

Source: [CITED: github.com/vercel-community/php README — "Create a new file api/php.ini and place there your configuration"]

Note: `allow_url_fopen = On` and `extension=curl` are already enabled by default in vercel-php. Do not disable them — `ExchangeRateService` needs them.

### Pattern 4: `.vercelignore`

**What:** Excludes files from the Vercel deployment bundle to reduce build time and bundle size.

**Exact file content:**

```
/vendor
/.git
/docker
/docker-compose.yml
/Dockerfile
/.planning
/tests
/sql
.env
.env.example
```

**Notes:**
- `/vendor` is excluded because Vercel runs `composer install --no-dev --optimize-autoloader` during build. Committing `vendor/` would increase bundle size and override Vercel's optimized install. [ASSUMED: standard vercel-php convention]
- `public/.htaccess` is NOT excluded — it stays in `public/` for Docker dev. Vercel does not process `.htaccess` files (Apache-specific). [ASSUMED: Vercel does not serve dotfiles from static CDN by default, so `.htaccess` is not publicly accessible]
- `.env` must never reach Vercel's build environment. It is already gitignored; listing it in `.vercelignore` is belt-and-suspenders.
- `/sql` excluded — schema/seed files are not needed at serverless runtime.

### Pattern 5: GitHub → Vercel Setup (Operator Steps)

These are manual steps that the operator (user) must complete. They cannot be automated by the plan executor.

**Prerequisites:**
1. A GitHub account (free)
2. A Vercel account (free, vercel.com — no credit card required for Hobby plan)

**Steps:**
1. Create a new empty GitHub repository (e.g. `reselltrack`)
2. Add GitHub remote to the local git repo:
   ```bash
   git remote add origin https://github.com/<username>/reselltrack.git
   git branch -M main
   git push -u origin main
   ```
3. Go to vercel.com → "Add New Project" → "Import Git Repository"
4. Select the `reselltrack` GitHub repo
5. Set **Framework Preset = "Other"** (not Next.js, not PHP — Other)
6. Leave **Root Directory** as `.` (project root)
7. Leave **Build Command** and **Output Directory** empty — `vercel.json` overrides these
8. **Do NOT add environment variables yet** — no DB or session secrets are needed until Phase 2/3
9. Click "Deploy"

**Expected outcome:** Vercel deploys and provides a URL like `reselltrack-<hash>.vercel.app`. The first deploy will fail or show a PHP error about DB connection — this is expected because no `DB_HOST` env var is set. Phase 1's goal is only to verify routing works (DEPLOY-01, DEPLOY-02, DEPLOY-03), not full app functionality.

**Phase 1 success verification on the live URL:**
- `GET /login` returns an HTML page (not a 404 or function invocation error) → DEPLOY-01
- `GET /assets/css/style.css` returns CSS content with no PHP involvement → DEPLOY-02
- `docker compose up` locally still serves the app → DEPLOY-03

### Anti-Patterns to Avoid

- **Using `routes` instead of `rewrites`:** The legacy `routes` API (keys `src`/`dest`) overrides static file serving. All CSS/JS requests route through the PHP function. Always use `rewrites` (`source`/`destination`). [VERIFIED: PITFALLS.md + confirmed against vercel.com/docs/routing/rewrites]
- **Including `memory` in `vercel.json` on new projects:** Fluid Compute is enabled by default since April 23, 2025. Setting `memory` in `vercel.json` is invalid when Fluid is enabled. Omit it; configure via the Vercel dashboard if needed. [VERIFIED: vercel.com/docs/project-configuration/vercel-json#functions]
- **Committing `vendor/`:** Doubles build time, breaks `--no-dev --optimize-autoloader` optimization. Keep `vendor/` gitignored.
- **Moving or modifying `public/index.php`:** Breaks Docker dev. Use the wrapper (D-01).
- **Reordering routes in `public/index.php`:** Route ordering is load-bearing. `/products/create` MUST stay before `/products/{id}` (line 74 comment in the existing file documents this). The wrapper pattern preserves the existing file unchanged.

---

## Don't Hand-Roll

| Problem | Don't Build | Use Instead | Why |
|---------|-------------|-------------|-----|
| Static asset carve-out | Custom route rules for every file extension | `outputDirectory: "public"` + `rewrites` | Vercel handles static-first routing automatically; manual extension lists are brittle |
| PHP function routing | Custom Vercel middleware | `functions` + `runtime: vercel-php@0.7.4` | vercel-community/php handles PHP-CGI bridge, superglobal population, multipart parsing |
| PHP build step | Custom `buildCommand` for composer | Vercel auto-detects `composer.json` + `composer.lock` | Vercel's build system runs `composer install --no-dev --optimize-autoloader` automatically |

**Key insight:** Phase 1 has zero application code to write. The entire implementation is 3-4 configuration files and a one-line PHP require statement. Any additional complexity is a sign something is wrong.

---

## Common Pitfalls

### Pitfall 1: `routes` vs `rewrites` — Assets Route Through PHP

**What goes wrong:** Using legacy `"routes": [{"src": "/(.*)", "dest": "/api/index.php"}]` sends every request — including Bootstrap CSS, Chart.js, icon fonts — through the PHP Lambda. Invocation count spikes 10-50x. Assets take 100-300ms cold-start latency. Free tier limit exhausted.

**Why it happens:** Old tutorials and the vercel-community/php README (some versions) use the legacy `routes` API. The `src`/`dest` key names are the tell.

**How to avoid:** Always use `rewrites` with `source`/`destination`. Confirm with: if there's a `"routes"` key in your vercel.json, it's wrong.

**Warning signs:** CSS missing in browser, function invocation count > page count in Vercel dashboard.

### Pitfall 2: `memory` Field with Fluid Compute

**What goes wrong:** `"memory": 1024` in `vercel.json` under `functions` causes a deployment error on new Vercel projects where Fluid Compute is enabled by default.

**Why it happens:** Fluid Compute changed how memory is configured. Documentation and examples from before April 2025 still include the `memory` field.

**How to avoid:** Omit `memory` from `vercel.json`. The default (1024 MB) is sufficient. Configure via Vercel dashboard if needed.

**Warning signs:** Build fails with an error about memory configuration or Fluid Compute.

### Pitfall 3: `__DIR__` Misunderstanding in Wrapper

**What goes wrong:** Developer adds path adjustments to `api/index.php` (e.g., redefining `$root`) thinking `__DIR__` will point to the wrong location. This breaks path resolution.

**Why it happens:** Confusion about whether `__DIR__` in a `require`d file refers to the requiring file or the required file.

**How to avoid:** `__DIR__` in `public/index.php` ALWAYS refers to `public/index.php`'s own directory (`<root>/public`). The `$root = dirname(__DIR__)` in `public/index.php` therefore always resolves to the project root. The wrapper needs zero additional path logic.

**Warning signs:** 500 errors with "file not found" for autoloader or helpers.php.

### Pitfall 4: First Deployment Shows PHP Errors — Expected

**What goes wrong:** The first Vercel deployment succeeds (no build error) but visiting the site shows a PHP error about missing DB connection or environment variables.

**Why it happens:** Phase 1 only sets up routing. No `DB_HOST`, `DB_USER`, etc. env vars are configured. `Database::connection()` fails. This is expected and acceptable per the phase scope.

**How to avoid:** Verify DEPLOY-01 by checking `/login` (which shows a form before any DB call) and DEPLOY-02 by checking `/assets/css/style.css` directly. The DB error on `/dashboard` is not a routing failure.

**Warning signs (that ARE problems):** 404 on `/login`, `FUNCTION_INVOCATION_FAILED` on any route, CSS loading through PHP (Content-Type: text/html for a .css request).

### Pitfall 5: Route Ordering Disrupted

**What goes wrong:** If `public/index.php` is edited (violating D-01) or routes are reordered, `/products/{id}` matches before `/products/create`, causing `create` to be treated as a product ID.

**How to avoid:** Never touch `public/index.php` in this phase. The wrapper requires it unchanged. Static segments MUST appear before parameterized ones.

---

## Code Examples

### Complete `vercel.json`

```json
{
  "$schema": "https://openapi.vercel.sh/vercel.json",
  "outputDirectory": "public",
  "functions": {
    "api/index.php": {
      "runtime": "vercel-php@0.7.4",
      "maxDuration": 30
    }
  },
  "rewrites": [
    { "source": "/(.*)", "destination": "/api/index.php" }
  ]
}
```

Source: [VERIFIED: vercel.com/docs/project-configuration/vercel-json] + [VERIFIED: vercel.com/docs/routing/rewrites]

### Complete `api/index.php`

```php
<?php
declare(strict_types=1);
require dirname(__DIR__) . '/public/index.php';
```

Source: [VERIFIED: PHP manual — `__DIR__` resolves to the directory of the file in which it appears, not the requiring file] + [VERIFIED: codebase analysis — `public/index.php` line 21: `$root = dirname(__DIR__)`]

### Complete `api/php.ini`

```ini
variables_order = EGPCS
memory_limit = 256M
disable_functions = exec,passthru,shell_exec,system,proc_open,popen
```

Source: [CITED: github.com/vercel-community/php README — api/php.ini location and variables_order recommendation]

### Complete `.vercelignore`

```
/vendor
/.git
/docker
/docker-compose.yml
/Dockerfile
/.planning
/tests
/sql
.env
.env.example
```

Source: [ASSUMED — standard Vercel/PHP deployment conventions]

### Verifying Static Asset Bypass (DEPLOY-02 Check)

```bash
# After deployment, verify CSS is served by CDN (not PHP)
# PHP responses include X-Powered-By and lack CDN cache headers
curl -sI https://<your-deployment>.vercel.app/assets/css/style.css \
  | grep -E "content-type|x-powered-by|cache-control|x-vercel"

# Expected:
# content-type: text/css
# cache-control: public, max-age=...  (CDN served)
# x-vercel-cache: HIT  (or MISS on first request)
# (no x-powered-by: PHP — that would indicate PHP is processing the CSS)
```

### Verifying Route Reach (DEPLOY-01 Check)

```bash
# /login returns the login form HTML (no DB needed)
curl -s https://<your-deployment>.vercel.app/login | grep -c "<form"
# Expected: >= 1

# /products redirects to /login (Auth::require() fires before any DB call)
curl -sI https://<your-deployment>.vercel.app/products | grep location
# Expected: Location: /login
```

---

## State of the Art

| Old Approach | Current Approach | When Changed | Impact |
|--------------|------------------|--------------|--------|
| `vercel.json` `routes` (legacy, `src`/`dest`) | `vercel.json` `rewrites` (`source`/`destination`) | 2020+ | `rewrites` preserves static file priority; `routes` overrides it |
| `memory` in `vercel.json` functions block | Fluid Compute: configure memory in Vercel dashboard | April 2025 | Can't set `memory` in vercel.json on new projects with Fluid Compute enabled |
| vercel-php@0.7.x requiring PHP in `routes` | `functions` block with `runtime` key | 2022+ | `builds` key deprecated; `functions` is current API |

**Deprecated/outdated:**
- `"builds": [{"src": "api/*.php", "use": "vercel-php@..."}]` — legacy `builds` key replaced by `functions`. Do not use.
- `"routes"` with `"src"/"dest"` — replaced by `"rewrites"` with `"source"/"destination"`.

---

## Assumptions Log

| # | Claim | Section | Risk if Wrong |
|---|-------|---------|---------------|
| A1 | Vercel auto-runs `composer install --no-dev --optimize-autoloader` when `composer.json` + `composer.lock` are detected | Standard Stack / .vercelignore | If wrong: `vendor/` is missing on Vercel, autoloader fails, 500 on every request. Mitigation: add a `buildCommand: "composer install --no-dev --optimize-autoloader"` to `vercel.json` as a fallback. |
| A2 | `$_SERVER['REQUEST_URI']` contains the original request path (e.g. `/products/create`) when a Vercel rewrite forwards to `api/index.php` | Code Examples / Pattern 2 | If wrong: `Router::dispatch()` receives `/api/index.php` or an empty path; all routes 404. Mitigation: add a debug endpoint in `api/index.php` that dumps `$_SERVER` before going live. |
| A3 | `.htaccess` files in `public/` are not accessible via Vercel's CDN (dotfiles not served) | Pattern 4 / .vercelignore | If wrong: `public/.htaccess` contents are readable via GET `/.htaccess`. Low risk (just Apache rewrite rules), but should be verified post-deploy. Mitigation: add `/.htaccess` to `.vercelignore` if concerned. |
| A4 | `vendor/` gitignored + Vercel build install is the correct approach (not committing vendor) | Standard Stack | If wrong: Vercel build fails without vendor. Mitigation: if A1 fails, commit `vendor/` temporarily. |
| A5 | Fluid Compute is enabled by default for new Vercel projects | Pattern 1 / `memory` note | If wrong: setting `memory: 1024` in vercel.json would work fine. Risk: build fails if Fluid IS enabled and `memory` is set. Safer to omit it either way. |

---

## Open Questions

1. **`composer install` trigger**
   - What we know: vercel-community/php README documents build-time composer install; Vercel auto-detects `composer.json`
   - What's unclear: Whether the auto-detection works for the community PHP runtime when `framework: null` (no framework preset)
   - Recommendation: If the first deploy shows missing class errors, add `"buildCommand": "composer install --no-dev --optimize-autoloader"` to `vercel.json` and redeploy

2. **DB error on first deploy**
   - What we know: Phase 1 sets no DB env vars; `Database::connection()` will fail on routes that need DB
   - What's unclear: Whether the DB failure causes a hard 500 exit or whether `/login` (which does need DB for the register form but not the page itself) is reachable
   - Recommendation: Test `/login` as the Phase 1 smoke test — if it renders the form HTML, routing works (DEPLOY-01 is satisfied)

3. **Vercel Hobby plan `maxDuration`**
   - What we know: Hobby plan maximum is 60s; default is 10s; `maxDuration: 30` is safe
   - What's unclear: Whether the community PHP runtime has separate limits
   - Recommendation: Keep `maxDuration: 30`; adjust if Vercel rejects the config during deploy

---

## Environment Availability

| Dependency | Required By | Available | Version | Fallback |
|------------|------------|-----------|---------|----------|
| git | Push to GitHub (operator step) | Confirmed (repo exists locally) | — | — |
| GitHub account | D-02: GitHub → Vercel integration | Operator must create | — | Operator action |
| Vercel account | D-02: Vercel deployment | Operator must create | — | Operator action |
| composer | Vercel build (automatic) | Vercel build environment | Latest | Operator adds `buildCommand` manually |
| Docker + Docker Compose | DEPLOY-03 local verification | Confirmed (project runs locally) | — | — |

**Missing dependencies with no fallback:** None for the code implementation tasks. GitHub repo and Vercel account creation are operator prerequisites.

**Missing dependencies with fallback:** If Vercel's automatic `composer install` fails (A1), add `buildCommand` to `vercel.json`.

---

## Validation Architecture

### Test Framework

| Property | Value |
|----------|-------|
| Framework | PHPUnit 11 (existing) |
| Config file | `phpunit.xml` (project root) |
| Quick run command | `vendor/bin/phpunit` |
| Full suite command | `vendor/bin/phpunit --coverage-text` |

Note: Phase 1 adds zero new PHP business logic. The existing PHPUnit suite (`ProfitCalculatorTest`) remains the only automated test. The phase-specific verification (DEPLOY-01, DEPLOY-02, DEPLOY-03) is manual.

### Phase Requirements → Test Map

| Req ID | Behavior | Test Type | Automated Command | File Exists? |
|--------|----------|-----------|-------------------|-------------|
| DEPLOY-01 | All routes reach PHP front controller on Vercel | Manual smoke | `curl -sI https://<vercel-url>/login` — expect 200 or redirect to /login HTML | N/A (deployment check) |
| DEPLOY-02 | CSS/JS served by CDN without PHP | Manual smoke | `curl -sI https://<vercel-url>/assets/css/style.css` — expect `content-type: text/css`, `x-vercel-cache`, no `x-powered-by: PHP` | N/A (deployment check) |
| DEPLOY-03 | Local Docker dev unchanged | Manual + unit | `docker compose up` + `vendor/bin/phpunit` + browser test of local URL | ✅ PHPUnit exists |
| Regression | No regressions to existing PHP business logic | Unit | `vendor/bin/phpunit` | ✅ `tests/ProfitCalculatorTest.php` |

### Sampling Rate

- **Per task commit:** `vendor/bin/phpunit` (ensures no regressions to local PHP code)
- **Per wave merge:** `vendor/bin/phpunit` + manual smoke on Vercel preview URL
- **Phase gate:** All 3 success criteria verified on the live Vercel deployment URL before `/gsd:verify-work`

### Wave 0 Gaps

No new test files are required for Phase 1. All changes are configuration files and a one-line wrapper. The existing `ProfitCalculatorTest.php` covers the only tested unit. DEPLOY-01/02/03 verification is inherently manual (requires a live Vercel deployment).

---

## Security Domain

### Applicable ASVS Categories

| ASVS Category | Applies | Standard Control |
|---------------|---------|-----------------|
| V2 Authentication | No change this phase | Handled in `public/index.php` (unchanged) |
| V3 Session Management | No change this phase | File-based sessions acceptable for Phase 1; Phase 3 adds MySQL sessions |
| V4 Access Control | No change this phase | `Auth::require()` in controllers (unchanged) |
| V5 Input Validation | No change this phase | All validation stays in `public/index.php` / Controllers |
| V6 Cryptography | No | Not applicable to routing |

### Phase 1-Specific Security Notes

| Item | Risk | Mitigation |
|------|------|------------|
| `.env` file on Vercel | CRITICAL — secrets exposed | Already gitignored; `.vercelignore` adds belt-and-suspenders. `Env::load()` skips missing files gracefully. |
| `vendor/` excluded from git | LOW | Vercel rebuilds at deploy time. No secrets in vendor. |
| `public/.htaccess` accessibility | LOW | Apache rewrite rules; no secrets. Assumed Vercel does not serve dotfiles [A3]. |
| Security headers in `api/index.php` | LOW | Headers emitted by `public/index.php` on every response. No HSTS yet (Phase 5). |
| `SESSION_SECURE` env var | MEDIUM — NOT set this phase | Acceptable for Phase 1 (sessions exist but user is not expected to use the live site until Phase 3). Must be set before Phase 3 go-live. |

---

## Sources

### Primary (HIGH confidence)
- [vercel.com/docs/routing/rewrites](https://vercel.com/docs/routing/rewrites) — `rewrites` schema (`source`/`destination`), static-first routing priority, SPA catch-all pattern
- [vercel.com/docs/project-configuration/vercel-json#functions](https://vercel.com/docs/project-configuration/vercel-json#functions) — `runtime` field for community runtimes, `maxDuration`, Fluid Compute `memory` restriction
- [vercel.com/docs/project-configuration/vercel-json#outputdirectory](https://vercel.com/docs/project-configuration/vercel-json#outputdirectory) — output directory configuration
- [github.com/vercel-community/php CHANGELOG.md](https://github.com/vercel-community/php/blob/master/CHANGELOG.md) — vercel-php@0.7.4 → PHP 8.3.x version mapping
- PHP manual `__DIR__` — magic constant resolves to directory of file in which it appears
- Codebase analysis: `public/index.php` (full source), `src/Core/Router.php` (full source), `public/.htaccess`, `composer.json`
- `.planning/research/STACK.md`, `.planning/research/ARCHITECTURE.md`, `.planning/research/PITFALLS.md` — prior research with citations
- `.planning/codebase/ARCHITECTURE.md`, `.planning/codebase/STRUCTURE.md` — codebase analysis

### Secondary (MEDIUM confidence)
- [github.com/vercel-community/php README](https://github.com/vercel-community/php/blob/master/README.md) — `api/php.ini` location, vercel.json structure for PHP projects, basic routing patterns

### Tertiary (LOW / ASSUMED)
- Vercel auto-runs `composer install --no-dev --optimize-autoloader` at build time [A1]
- `$_SERVER['REQUEST_URI']` contains original path under Vercel rewrites [A2]
- Vercel CDN does not serve dotfiles (`.htaccess` not publicly accessible) [A3]

---

## Metadata

**Confidence breakdown:**
- vercel.json schema (rewrites, functions, outputDirectory): HIGH — verified against official Vercel docs
- api/index.php wrapper (one-line require): HIGH — verified against PHP `__DIR__` behavior and codebase path analysis
- PHP 8.3 via vercel-php@0.7.4: HIGH — verified against CHANGELOG.md
- Composer install auto-detection: ASSUMED — not explicitly verified for no-framework projects
- $_SERVER['REQUEST_URI'] behavior: ASSUMED — inferred from Vercel rewrite semantics

**Research date:** 2026-06-12
**Valid until:** 2026-09-12 (stable Vercel API; vercel-php runtime version may change sooner)
