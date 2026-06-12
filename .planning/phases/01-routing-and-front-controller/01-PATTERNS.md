# Phase 1: Routing and Front Controller - Pattern Map

**Mapped:** 2026-06-12
**Files analyzed:** 4 new files (vercel.json, api/index.php, api/php.ini, .vercelignore)
**Analogs found:** 3 / 4

---

## File Classification

| New File | Role | Data Flow | Closest Analog | Match Quality |
|----------|------|-----------|----------------|---------------|
| `vercel.json` | config | request-response | `public/.htaccess` | partial-match (same routing intent: static-first, then front controller) |
| `api/index.php` | entry-point / middleware | request-response | `public/index.php` | exact (one-line delegate to the real front controller) |
| `api/php.ini` | config | N/A | — | no-analog (Docker handles PHP config in dev; no php.ini in codebase) |
| `.vercelignore` | config | N/A | `.gitignore` | exact (identical exclusion-list syntax; overlapping entries) |

---

## Pattern Assignments

### `vercel.json` (config, request-response)

**Analog:** `public/.htaccess`

Both files implement the same routing strategy for their respective platform: serve real static files directly; forward everything else to the single PHP entry point. The `.htaccess` does this with `RewriteCond`/`RewriteRule`; `vercel.json` does it with `outputDirectory` + `rewrites`.

**Routing intent from analog** (`public/.htaccess` lines 1-8):
```apache
# Route every request that is not a real file/dir to the single front controller.
<IfModule mod_rewrite.c>
    RewriteEngine On
    RewriteCond %{REQUEST_FILENAME} -f [OR]
    RewriteCond %{REQUEST_FILENAME} -d
    RewriteRule ^ - [L]
    RewriteRule ^ index.php [L]
</IfModule>
```

The `.htaccess` pattern to mirror in `vercel.json`:
- Static files/dirs pass through unchanged (`RewriteRule ^ - [L]` → `outputDirectory: "public"`)
- Everything else goes to the front controller (`RewriteRule ^ index.php [L]` → `rewrites` catch-all to `api/index.php`)
- Security: dotfiles denied (`<FilesMatch "^\.">`) → not needed in vercel.json (Vercel does not serve dotfiles from CDN)

**Exact `vercel.json` to create** (from RESEARCH.md Pattern 1, verified against official docs):
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

**Critical constraints (copy from RESEARCH.md):**
- Use `rewrites` with `source`/`destination` keys — NOT legacy `routes` with `src`/`dest`
- Do NOT include a `memory` field — Fluid Compute is enabled by default on new projects
- PHP runtime: `vercel-php@0.7.4` (PHP 8.3.x) — pinned per D-05

---

### `api/index.php` (entry-point, request-response)

**Analog:** `public/index.php` (the file being delegated to)

`api/index.php` is a one-line wrapper that requires the existing front controller. The only pattern to copy is the PHP file-opening convention established throughout the project.

**PHP opening convention** (from `public/index.php` lines 1-2):
```php
<?php
declare(strict_types=1);
```

Every `.php` file in this project opens with `declare(strict_types=1);` — no exceptions. The wrapper must follow the same convention.

**Path resolution pattern** (`public/index.php` line 21 — the key mechanism being leveraged):
```php
$root = dirname(__DIR__);
```

This line inside `public/index.php` resolves to `<project_root>` regardless of whether the file is executed directly by Apache or `require`d from `api/index.php`. `__DIR__` is a PHP magic constant that always refers to the directory of the file it physically appears in — not the requiring file. The wrapper does NOT need to set `$root` or adjust any paths.

**Exact `api/index.php` to create** (from RESEARCH.md Pattern 2):
```php
<?php
declare(strict_types=1);
require dirname(__DIR__) . '/public/index.php';
```

Three lines total. No additional logic. Any path adjustments, variable definitions, or `require` of additional files would be wrong — `public/index.php` handles everything (autoloader, `Env::load()`, `Auth::start()`, security headers, route registration, `Router::dispatch()`).

**What NOT to add to `api/index.php`:**
- No `$root` redefinition (already resolved correctly inside `public/index.php`)
- No additional `require` statements
- No `$_SERVER` manipulation (Vercel preserves `REQUEST_URI` with the original path)
- No output buffering setup

---

### `api/php.ini` (config, N/A)

**Analog:** None found in codebase.

PHP runtime configuration for local Docker dev is embedded in the `Dockerfile` (`docker-php-ext-install pdo_mysql`) and Docker Compose environment variables. There is no `php.ini` in the project. The planner must use RESEARCH.md Pattern 3 directly.

**Exact `api/php.ini` to create** (from RESEARCH.md Pattern 3):
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

**Placement:** `api/php.ini` (same directory as `api/index.php`). The vercel-community/php runtime picks it up automatically from the `api/` directory.

**Do NOT disable:** `allow_url_fopen` or `curl` — `ExchangeRateService` uses outbound HTTP to `api.frankfurter.app`. Both are enabled by default in vercel-php.

---

### `.vercelignore` (config, N/A)

**Analog:** `.gitignore` (project root)

Both files use the same line-by-line exclusion syntax. `.vercelignore` mirrors and extends `.gitignore` with deployment-specific additions.

**Exclusion pattern from analog** (`.gitignore` full content):
```gitignore
# Secrets
.env

# Dependencies
/vendor/

# User uploads (keep the folder, ignore its contents)
/public/assets/uploads/*
!/public/assets/uploads/.gitkeep

# PHPUnit
.phpunit.result.cache
/tests/coverage/

# OS / editor noise
.DS_Store
Thumbs.db
*.log
.idea/
.vscode/
```

**Exact `.vercelignore` to create** (from RESEARCH.md Pattern 4):
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

**Mapping from `.gitignore` to `.vercelignore`:**
- `/vendor` — already in `.gitignore`; must also be in `.vercelignore`. Vercel builds `vendor/` fresh via `composer install --no-dev --optimize-autoloader`.
- `.env` — already in `.gitignore`; belt-and-suspenders exclusion. `Env::load()` checks `is_file()` before reading so a missing `.env` on Vercel is handled gracefully.
- `.env.example` — template file, not needed at runtime.
- `/docker`, `/docker-compose.yml`, `/Dockerfile` — dev-only infrastructure; not in `.gitignore` but must be excluded from deployment bundle.
- `/.planning` — planning artifacts; not needed at runtime.
- `/tests` — PHPUnit tests; not needed at runtime. Already in `.gitignore` (coverage only; test files themselves are tracked in git but excluded from Vercel bundle here).
- `/sql` — schema/seed DDL; not needed in the serverless function.
- `/.git` — git history; excluded for bundle size.

**What NOT to add to `.vercelignore`:**
- `public/.htaccess` — stays in `public/` for Docker dev. Vercel does not process Apache directives; the file is harmless (dotfiles not served from CDN). If concerned, it can be added as `public/.htaccess` later.
- `public/assets/uploads/` — deferred to Phase 4 (D-04). Any existing uploads will 404 on Vercel temporarily; this is acceptable.

---

## Shared Patterns

### PHP File Opening Convention
**Source:** `public/index.php` lines 1-2
**Apply to:** `api/index.php`
```php
<?php
declare(strict_types=1);
```
Every PHP file in this project opens with `declare(strict_types=1);`. The one-line wrapper must follow this convention.

### Static-First Routing (Analog: `.htaccess`)
**Source:** `public/.htaccess` lines 1-8
**Apply to:** `vercel.json`

The `.htaccess` pattern — serve real files directly, forward everything else to the front controller — is the conceptual template for `vercel.json`. The mechanism differs (Apache mod_rewrite vs. Vercel `outputDirectory` + `rewrites`) but the intent is identical.

### Exclusion List Syntax
**Source:** `.gitignore`
**Apply to:** `.vercelignore`

Both files use the same line-by-line path pattern syntax. Entries from `.gitignore` that are relevant to deployment (particularly `/vendor` and `.env`) must be present in `.vercelignore`.

---

## No Analog Found

| File | Role | Data Flow | Reason |
|------|------|-----------|--------|
| `api/php.ini` | config | N/A | PHP runtime is configured via Docker in the dev environment; no `php.ini` exists in the project. Use RESEARCH.md Pattern 3 verbatim. |

---

## Implementation Notes for Planner

This is a configuration-and-wrapper phase. All four files have fully specified content in RESEARCH.md. The pattern mapper's role here is to confirm that:

1. `api/index.php` must use `declare(strict_types=1);` — project convention, confirmed from `public/index.php`.
2. The `dirname(__DIR__)` path in `api/index.php` works correctly because `__DIR__` inside `public/index.php` always refers to `<root>/public`, not to `api/` — confirmed from `public/index.php` line 21 analysis.
3. Route ordering in `public/index.php` (line 74 comment: "MUST stay before") is preserved automatically because `public/index.php` is not touched.
4. The `.vercelignore` syntax is identical to `.gitignore` — same tool knowledge applies.
5. No new PHP classes, namespaces, or autoloader registrations are needed. The `api/` directory does not require a namespace — `api/index.php` is not a class file.

**Regression risk:** Zero for existing code. `public/index.php`, `public/.htaccess`, `src/Core/Router.php`, and all controllers/models/services are untouched. Local Docker dev is unaffected.

---

## Metadata

**Analog search scope:** Project root, `public/`, `src/Core/`, `docker/`
**Files read:** `public/index.php`, `public/.htaccess`, `.gitignore`, `.planning/codebase/ARCHITECTURE.md`, `.planning/codebase/STRUCTURE.md`
**Files scanned for additional analogs:** None required — 3 strong matches found in first pass
**Pattern extraction date:** 2026-06-12
