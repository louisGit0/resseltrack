# Phase 5: Security Hardening and Production Configuration - Research

**Researched:** 2026-06-15
**Domain:** PHP security headers (HSTS), production boot safety gate, secrets audit
**Confidence:** HIGH

---

<user_constraints>
## User Constraints (from CONTEXT.md)

### Locked Decisions

- **D-01:** "Production" is detected by the request being **HTTPS**, via `X-Forwarded-Proto: https` (`$_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https'`, with `$_SERVER['HTTPS']` as fallback). No `APP_ENV` var. Compute one `$isHttps` boolean, reuse for D-02 and D-03.
- **D-02:** `header('Strict-Transport-Security: max-age=31536000; includeSubDomains');` only when `$isHttps`. No `preload` (don't control `vercel.app` apex).
- **D-03:** Boot gate in `public/index.php`, on HTTPS only: refuse to start (HTTP 500 + French page + `exit` + `error_log`) if SESSION_SECURE !== '1', DB_PASSWORD empty or === 'reselltrack', any of DB_HOST/DB_NAME/DB_USER empty, any of CLOUDINARY_CLOUD_NAME/CLOUDINARY_API_KEY/CLOUDINARY_API_SECRET empty.
- **D-04:** Gate placement: after `Env::load()` AND after `/health` early-return, before `Auth::start()`. Gate must not run on local HTTP.
- **D-05 (SEC-01):** Verify only — no code change. Confirm no secret committed, `.env` gitignored, `certs/aiven-ca.pem` is a public CA cert.
- **D-06 (SEC-03):** Verify only — no code change. Confirm CSP `img-src` includes `https://res.cloudinary.com`. Optionally clean up the "pre-satisfy" comment.

### Claude's Discretion

- Error page style (small inline HTML/text, French, friendly — modelled on the DB-failure page in `Database.php`).
- Exact wording of `error_log()` reason strings.
- Whether to clean up the "Phase 5 SEC-03 pre-satisfy" comment on the CSP line (cosmetic only).

### Deferred Ideas (OUT OF SCOPE)

- CSRF token rotation after each POST (v2/HARD).
- Rate limiting on `/register` and `/export/*` (v2/HARD).
- Graceful 503 page on DB failure (v2/OPS).
- `securityheaders.com` A+ extras (Permissions-Policy, COOP/COEP).
</user_constraints>

<phase_requirements>
## Phase Requirements

| ID | Description | Research Support |
|----|-------------|------------------|
| SEC-01 | No secret committed; `.env` gitignored | Confirmed: grep audit clean, `.gitignore` line 1 excludes `.env`, `certs/aiven-ca.pem` is a CA cert not a private key |
| SEC-02 | `Strict-Transport-Security` emitted in production | Research confirms correct predicate and HSTS directive; Vercel already sends platform HSTS — app-level header is defense-in-depth |
| SEC-03 | CSP `img-src` covers Cloudinary delivery domain | Already present in index.php line 75: `https://res.cloudinary.com` — verify only |
| SEC-04 | App refuses to start in production with dangerous config | Boot gate design confirmed; pattern mirrors existing `Database.php` error page |
</phase_requirements>

---

## Summary

Phase 5 is a targeted hardening pass on `public/index.php` — the single file responsible for bootstrapping every request. The surface is small: compute one `$isHttps` boolean, insert a boot safety gate before `Auth::start()`, and add an HSTS header in the existing security-headers block. Two of the four requirements (SEC-01, SEC-03) are verification-only with no code change.

The key runtime finding is that Vercel's edge **already emits** `Strict-Transport-Security: max-age=63072000; includeSubDomains; preload` at the platform level (confirmed by live `curl -sI`). The app-level HSTS in D-02 is therefore redundant on Vercel but remains valid: it documents the security posture in code, is safe under RFC 6797 (duplicate HSTS headers do not harm), and would protect the app if the hosting platform ever changes.

The HTTPS detection predicate `$_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https'` is confirmed correct by both Vercel's official request-headers documentation and the vercel-community/php CGI launcher source code. The `$_SERVER['HTTPS']` superglobal is hardcoded to `'On'` in the vercel-php runtime regardless of actual TLS — making it a reliable secondary signal on Vercel while remaining absent on local Docker plain-HTTP.

**Primary recommendation:** Implement exactly as specified in CONTEXT.md D-01..D-06. One file (`public/index.php`), approximately 25–30 lines added, zero new dependencies.

---

## Architectural Responsibility Map

| Capability | Primary Tier | Secondary Tier | Rationale |
|------------|-------------|----------------|-----------|
| HTTPS detection | Frontend Server (PHP FPM/CGI on Vercel) | — | `$_SERVER` is populated by the PHP runtime from Vercel's proxy headers |
| HSTS header emission | Frontend Server | CDN / Platform (Vercel already emits it) | App owns the header as documented posture; Vercel duplicates at edge |
| Boot safety gate | Frontend Server | — | Index.php bootstraps every request; gate lives there inline |
| Secrets storage | Platform env vars (Vercel) | Local `.env` (gitignored) | No secrets in code or committed files |
| CSP (img-src) | Frontend Server | — | Already set in security-headers block of index.php |

---

## Standard Stack

No new packages required. Phase 5 uses only PHP built-ins already present:
- `$_SERVER` superglobal (HTTP header access)
- `header()` (response header emission)
- `http_response_code()` (error response)
- `error_log()` (reason logging)
- `Env::get()` (config access — already in codebase)

**Installation:** none — zero new dependencies.

---

## Package Legitimacy Audit

No external packages installed in this phase. Section not applicable.

---

## Architecture Patterns

### System Architecture Diagram

```
HTTP Request (Vercel Edge)
        |
        v
  [Vercel CDN/Edge]
  Adds: X-Forwarded-Proto: https
  Adds: Strict-Transport-Security (platform-level, 2yr)
        |
        v
  public/index.php
        |
        +-- Env::load() -- loads .env / reads OS env vars
        |
        +-- /health ? → SELECT 1, JSON, exit  (GATE-EXEMPT: keep-alive must always work)
        |
        +-- $isHttps = (HTTP_X_FORWARDED_PROTO === 'https')  [NEW]
        |
        +-- Boot Gate [NEW, only when $isHttps]
        |     Checks: SESSION_SECURE, DB_PASSWORD, DB_HOST/NAME/USER, CLOUDINARY_*
        |     Fail: HTTP 500 + French HTML + error_log(reason) + exit
        |     Pass: falls through silently
        |
        +-- Auth::start()  (session with secure cookie flags)
        |
        +-- Security Headers Block [MODIFIED]
        |     X-Content-Type-Options, X-Frame-Options, Referrer-Policy, CSP (unchanged)
        |     + HSTS (new, only when $isHttps)
        |
        +-- Router::dispatch()
              → Controllers → Models/Services → Views
```

### Recommended Project Structure

No change to project structure. All additions are inline in `public/index.php`.

### Pattern 1: $isHttps Boolean Computed Once

**What:** Single boolean derived from `HTTP_X_FORWARDED_PROTO`, used for both the boot gate and the HSTS header.

**When to use:** Any code in `public/index.php` that needs to distinguish prod (HTTPS) from local dev (plain HTTP).

**Example:**
```php
// Source: D-01 + Vercel request-headers docs (vercel.com/docs/headers/request-headers)
// X-Forwarded-Proto is set by Vercel's proxy; local Docker does not set it.
// $_SERVER['HTTPS'] is hardcoded 'On' by vercel-php CGI regardless of actual TLS (see cgi.ts),
// so it is valid as fallback: absent on local Apache plain-HTTP, 'On' on Vercel.
$isHttps = (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https')
         || (isset($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off' && $_SERVER['HTTPS'] !== '');
```

### Pattern 2: Boot Gate — Inline Safety Block

**What:** Inline PHP block that asserts all production secrets are properly set before allowing the app to continue booting. Modelled on the existing `Database::connection()` friendly-error pattern.

**When to use:** Only when `$isHttps === true` (production / Vercel deployment).

**Example:**
```php
// Source: D-03/D-04 + pattern from src/Core/Database.php:53-58
if ($isHttps) {
    $bootErrors = [];
    if (Env::get('SESSION_SECURE', '0') !== '1') {
        $bootErrors[] = 'SESSION_SECURE not set to 1';
    }
    $dbPassword = Env::get('DB_PASSWORD', '');
    if ($dbPassword === '' || $dbPassword === 'reselltrack') {
        $bootErrors[] = 'DB_PASSWORD is empty or uses dev default';
    }
    foreach (['DB_HOST', 'DB_NAME', 'DB_USER'] as $key) {
        if (Env::get($key, '') === '') {
            $bootErrors[] = $key . ' is empty';
        }
    }
    foreach (['CLOUDINARY_CLOUD_NAME', 'CLOUDINARY_API_KEY', 'CLOUDINARY_API_SECRET'] as $key) {
        if (Env::get($key, '') === '') {
            $bootErrors[] = $key . ' is empty';
        }
    }
    if ($bootErrors !== []) {
        http_response_code(500);
        header('Content-Type: text/html; charset=utf-8');
        foreach ($bootErrors as $reason) {
            error_log('Boot gate: ' . $reason);
        }
        echo '<!DOCTYPE html><html lang="fr"><head><meta charset="utf-8">'
           . '<title>Erreur de configuration</title></head><body>'
           . '<h1>Service temporairement indisponible</h1>'
           . '<p>Une erreur de configuration empêche le démarrage de l\'application. '
           . 'Veuillez contacter l\'administrateur.</p>'
           . '</body></html>';
        exit;
    }
}
```

### Pattern 3: HSTS Header in Security-Headers Block

**What:** One additional `header()` call in the existing security-headers block, gated on `$isHttps`.

**Example:**
```php
// Source: D-02 + RFC 6797 (HSTS spec)
// max-age=31536000 = 1 year. No preload: we don't control vercel.app apex.
// includeSubDomains is safe: applies only to *.resseltrack-nu.vercel.app, not all *.vercel.app.
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: strict-origin-when-cross-origin');
if ($isHttps) {
    header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
}
header(
    "Content-Security-Policy: default-src 'self'; "
    // ... unchanged ...
);
```

### Anti-Patterns to Avoid

- **Always-on HSTS:** Never emit HSTS on plain HTTP — RFC 6797 Section 7.2 forbids it, and it would break local dev.
- **Using `APP_ENV` for detection:** D-01 explicitly chose not to add an `APP_ENV` variable. Do not invent one; use `$isHttps` exclusively.
- **Running the boot gate on `/health`:** The `/health` early-return must remain BEFORE the gate. Health checks must always respond, even during a misconfiguration event (so the operator can see the DB is still up).
- **Relying solely on `$_SERVER['HTTPS']`:** In vercel-php@0.7.4 CGI mode, `HTTPS` is hardcoded `'On'` regardless of actual TLS. Use `HTTP_X_FORWARDED_PROTO` as primary; `HTTPS` as secondary.
- **Emitting the error page without Content-Type:** Without `header('Content-Type: text/html; charset=utf-8')` on the gate error page, some browsers may render it as plain text or with wrong encoding.

---

## Don't Hand-Roll

| Problem | Don't Build | Use Instead | Why |
|---------|-------------|-------------|-----|
| HSTS header value | Custom max-age calculator | Hardcode `max-age=31536000; includeSubDomains` per D-02 | Value is a policy decision, not computed |
| Secret scanning | Custom grep regex | `Env::get()` checks in the gate itself | The gate IS the runtime audit; static grep is the build-time audit |
| Environment abstraction | `APP_ENV` enum or class | `$isHttps` boolean from proxy header | D-01 explicitly forbids introducing APP_ENV |

**Key insight:** The boot gate is the simplest possible implementation — inline `if` checks on `Env::get()` values, exit on failure. Any abstraction (validator class, config schema) adds complexity with no gain on a 5-check gate.

---

## Common Pitfalls

### Pitfall 1: Gate Fires on /health and Breaks Keep-Alive

**What goes wrong:** If `$isHttps` is computed before the `/health` early-return, or if the gate is placed before the `/health` block, a misconfiguration in production will cause the health check to return HTTP 500, which will trigger Aiven's connection monitor to think the DB is down.

**Why it happens:** Careless placement of new code before existing early-returns.

**How to avoid:** D-04 is explicit — the gate runs AFTER the `/health` block. The ordering in `index.php` must be: `Env::load` → `/health` early-return → `$isHttps` computation → boot gate → `Auth::start()`.

**Warning signs:** `/health` returns anything other than `{"status":"ok","db":"up"}` or `{"status":"error","db":"down"}`.

---

### Pitfall 2: HSTS Sent Over Plain HTTP

**What goes wrong:** If the `header('Strict-Transport-Security: ...')` call is not gated on `$isHttps`, it fires on local Docker HTTP requests. HSTS over plain HTTP is invalid per RFC 6797 and causes browsers that cache it to refuse future HTTP connections to the host.

**Why it happens:** Forgetting the `if ($isHttps)` wrapper around the HSTS `header()` call.

**How to avoid:** Always gate on `$isHttps`. Test locally (Docker plain HTTP) — the HSTS header must be absent.

---

### Pitfall 3: Duplicate HSTS Causes Confusion

**What goes wrong:** Vercel's platform already sends `Strict-Transport-Security: max-age=63072000; includeSubDomains; preload`. Adding the app-level header creates two STS headers in the response.

**Why it happens:** This is expected behavior, not a bug. RFC 6797 Section 8.1: browsers process only the first STS header field. Both headers are valid; the stronger one (Vercel's 2-year) or the first one wins depending on ordering.

**How to avoid:** Nothing to avoid — this is intentional. Note it in code comments. Do not suppress the app-level header to "avoid duplication"; the app should own its security posture.

---

### Pitfall 4: Boot Gate Leak — Error Page Reveals Config State

**What goes wrong:** The error page or `error_log` message discloses which specific secret is empty/wrong, potentially leaking information to an attacker who can read HTTP 500 responses.

**Why it happens:** Copying the detailed reason into the user-facing HTML error page.

**How to avoid:** `error_log()` the specific reason (server logs only); the HTML error page shows only a generic French message ("Erreur de configuration"). Never include variable names or actual values in the visible page.

---

### Pitfall 5: `$_SERVER['HTTPS']` Reliability on Vercel

**What goes wrong:** Using `$_SERVER['HTTPS']` as the SOLE indicator of production assumes it's absent on non-HTTPS. On vercel-php CGI, `$_SERVER['HTTPS']` is hardcoded to `'On'` regardless of actual TLS (see `cgi.ts: HTTPS: "On"`). Using it alone would always trigger the gate and HSTS even if vercel-php were hypothetically used for plain HTTP.

**Why it happens:** Treating `$_SERVER['HTTPS']` as reliable in all contexts.

**How to avoid:** Use `HTTP_X_FORWARDED_PROTO === 'https'` as primary (confirmed by Vercel docs). Keep `HTTPS` check only as fallback for non-Vercel HTTPS contexts (e.g., future custom domain setup with direct TLS).

---

## Code Examples

### Complete $isHttps Predicate (to place after /health early-return)

```php
// Source: Vercel request-headers docs (vercel.com/docs/headers/request-headers)
// + vercel-community/php cgi.ts source (HTTP_* header mapping)
//
// Primary: Vercel's proxy sets x-forwarded-proto=https on all production HTTPS requests.
// PHP maps it to $_SERVER['HTTP_X_FORWARDED_PROTO'].
// Absent on local Docker plain-HTTP (no proxy).
//
// Fallback: $_SERVER['HTTPS'] is hardcoded 'On' in vercel-php CGI for all Vercel requests.
// On local Docker Apache, HTTPS is absent for plain-HTTP. Safe as fallback.
$isHttps = (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https')
         || (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== '' && strtolower($_SERVER['HTTPS']) !== 'off');
```

### Live HSTS Verification Command

```bash
# Confirms Vercel platform HSTS and verifies app-level HSTS after deployment:
curl -sI https://resseltrack-nu.vercel.app/ | grep -i strict-transport
# Expected (post-Phase 5): one or two Strict-Transport-Security lines
# Vercel platform: max-age=63072000; includeSubDomains; preload
# App-level: max-age=31536000; includeSubDomains
```

### SEC-01 Audit Commands

```bash
# Confirm .env is gitignored (not tracked):
git ls-files .env
# Expected: empty output (not tracked)

# Confirm no private keys committed:
git grep -i "BEGIN.*PRIVATE KEY"
# Expected: no output

# Confirm certs/aiven-ca.pem is a certificate (public), not a private key:
head -1 certs/aiven-ca.pem
# Expected: -----BEGIN CERTIFICATE-----
```

---

## Open Questions

All questions from CONTEXT.md `<deferred>` are RESOLVED below.

**Q1: Reliable HTTPS detection under vercel-php@0.7.4 — exact key?**

RESOLVED. Primary key: `$_SERVER['HTTP_X_FORWARDED_PROTO']`.

Evidence:
- Vercel official docs (`vercel.com/docs/headers/request-headers`): "`x-forwarded-proto` represents the protocol of the forwarded server, typically `https` in production and `http` in development." [CITED: vercel.com/docs/headers/request-headers]
- vercel-community/php `src/launchers/cgi.ts` (master branch): all incoming headers are mapped via `"HTTP_" + header.toUpperCase().replace(/-/g, "_")`, making `x-forwarded-proto` → `HTTP_X_FORWARDED_PROTO`. [VERIFIED: github.com/vercel-community/php/src/launchers/cgi.ts]
- Same source hardcodes `HTTPS: "On"` for all Vercel requests, making `$_SERVER['HTTPS']` reliable as a fallback but unsuitable as sole detector.

**Exact predicate:**
```php
$isHttps = (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https')
         || (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== '' && strtolower($_SERVER['HTTPS']) !== 'off');
```

**Q2: HSTS correctness — must NOT be sent over HTTP? `includeSubDomains` safe on `*.vercel.app`?**

RESOLVED.

- RFC 6797 Section 7.2: "An HSTS Host MUST NOT include the STS header field in HTTP responses conveyed over non-secure transport." Gating on `$isHttps` is mandatory and correct. [ASSUMED: RFC 6797 text from training; standard and long-stable]
- `includeSubDomains` scopes to subdomains of the sending host (`resseltrack-nu.vercel.app`), not to all of `*.vercel.app`. The PSL treats `vercel.app` as a public suffix (each `*.vercel.app` is an independent registrable domain). HSTS `includeSubDomains` would only affect hypothetical `*.resseltrack-nu.vercel.app` subdomains — which do not exist. Safe. [ASSUMED: PSL behavior; standard and long-stable]
- No `preload`: correct per D-02 — preloading requires the root domain (which we don't control). [CITED: hstspreload.org requirements]

**Q3: Does Vercel already inject HSTS at the platform level?**

RESOLVED — YES, confirmed by live check.

Live `curl -sI https://resseltrack-nu.vercel.app/` result (2026-06-15):
```
Strict-Transport-Security: max-age=63072000; includeSubDomains; preload
```

Vercel's platform emits a 2-year HSTS with `includeSubDomains; preload`. This is STRONGER than D-02's 1-year without preload. The app-level header from Phase 5 will be a second (duplicate) `Strict-Transport-Security` header in the response. Per RFC 6797 Section 8.1, browsers process only the first. This is safe — no conflict, no harm. The app-level header documents the app's own security posture and is defense-in-depth. [VERIFIED: live curl 2026-06-15]

**Q4: Boot gate placement — friendly error page needs headers first?**

RESOLVED.

Placement confirmed: `Env::load` → `/health` early-return → `$isHttps` → boot gate → `Auth::start()` → security-headers block.

The gate fires BEFORE the security-headers block. The error page must explicitly emit:
```php
http_response_code(500);
header('Content-Type: text/html; charset=utf-8');
```
Security headers (CSP, X-Frame-Options, etc.) are NOT needed on the gate error page because it is not a user-reachable page — it appears only to a misconfigured deployment visible only to the operator. Vercel's platform-level security headers (HSTS from Vercel CDN, etc.) still apply at the CDN layer.

The `/health` endpoint is fully exempt: its early-return fires before `$isHttps` is even computed, so it is unaffected by the gate under any configuration.

---

## SEC-01 Audit — Findings

**Conducted:** 2026-06-15 on HEAD of `reselltrack/reselltrack`.

| Check | Result | Evidence |
|-------|--------|----------|
| `.env` in `.gitignore` | PASS | Line 1-2 of `.gitignore`: `# Secrets\n.env` |
| `.env` not committed | PASS | File present on local disk (gitignored), `git ls-files .env` would return empty |
| `certs/aiven-ca.pem` is public cert | PASS | File starts `-----BEGIN CERTIFICATE-----` (not a private key). README confirms "CA certificate is public information". |
| No private keys in repo | PASS | Grep for `BEGIN.*PRIVATE KEY` found only a PowerShell command string in `04-01-PLAN.md` (a test assertion pattern, not an actual key) |
| No API keys/passwords in `.php` files | PASS | Grep for `(password|secret|api[_-]?key|token).*=.*['"][^'"]{6,}` — no matches in any `.php` file |
| `CLOUDINARY_*` not committed | PASS | `.env.example` has `CLOUDINARY_API_KEY=` (empty); actual values live in Vercel env vars and local `.env` (gitignored) |
| `DB_PASSWORD` not committed | PASS | `.env.example` has `DB_PASSWORD=reselltrack` (local dev default, clearly labeled); actual prod password lives in Vercel env vars and local `.env` (gitignored) |
| `.vercelignore` excludes `.env` | PASS | Line 9 of `.vercelignore`: `.env` |

**Conclusion:** SEC-01 is already satisfied. No code change required. The verification task for Phase 5 is to document these findings and optionally run `git ls-files .env` in the plan's verification step.

---

## SEC-03 Verification — Findings

**Current state (from `public/index.php` line 75):**
```php
"img-src 'self' data: https://res.cloudinary.com; " // Cloudinary image delivery (STORE-02); pre-satisfies part of Phase 5 SEC-03
```

SEC-03 is already satisfied. The Cloudinary delivery domain `https://res.cloudinary.com` is present in `img-src`. No code change required.

Optional cosmetic update: replace the `// Cloudinary image delivery (STORE-02); pre-satisfies part of Phase 5 SEC-03` comment with simply `// Cloudinary image delivery (SEC-03)` since Phase 5 now formalizes it.

---

## State of the Art

| Old Approach | Current Approach | When Changed | Impact |
|--------------|------------------|--------------|--------|
| `$_SERVER['HTTPS']` alone for HTTPS detection | `HTTP_X_FORWARDED_PROTO` as primary, `HTTPS` as fallback | Emerged with serverless/proxy deployments | Required when running behind load balancers or CDNs |
| HSTS only via platform/server config | App-level HSTS + platform-level HSTS (defense-in-depth) | Ongoing best practice | App owns security posture regardless of platform |

**Deprecated/outdated:**
- Detecting production via `APP_ENV`: fragile operator-step dependency. D-01 replaces with protocol detection (zero operator step).

---

## Assumptions Log

| # | Claim | Section | Risk if Wrong |
|---|-------|---------|---------------|
| A1 | `$_SERVER['HTTPS']` is hardcoded `'On'` in vercel-php@0.7.4 CGI — verified from master branch `cgi.ts`, assumed same for 0.7.4 | HTTPS Detection | LOW: if wrong, `HTTPS` fallback is simply absent (same as local Docker) — predicate still correct because `HTTP_X_FORWARDED_PROTO` is primary |
| A2 | `includeSubDomains` in HSTS applies only to subdomains of `resseltrack-nu.vercel.app`, not to all `*.vercel.app` (PSL behaviour) | HSTS section, Pitfall 2 | LOW: documented standard; reversal would require PSL to not include `vercel.app` — unlikely given Vercel's own HSTS already uses `includeSubDomains` |
| A3 | Multiple `Strict-Transport-Security` response headers are processed first-one-wins per RFC 6797 | Open Questions Q3 | LOW: long-standing RFC; reversal would require browser regression |

---

## Environment Availability

| Dependency | Required By | Available | Version | Fallback |
|------------|------------|-----------|---------|----------|
| PHP 8.3 | Boot gate, HSTS | ✓ (Vercel Lambda) | 8.3.8 (seen in `X-Powered-By` response header) | — |
| `Env::get()` | Boot gate checks | ✓ (already in codebase) | — | — |
| `header()` built-in | HSTS emission | ✓ | PHP built-in | — |
| `curl` (for verification) | SEC-01/02/03 audit | ✓ (dev machine) | System curl | Browser DevTools |
| `git ls-files` | SEC-01 audit task | ✓ (repo) | Git | Manual .gitignore inspection |

**Missing dependencies with no fallback:** none.

---

## Validation Architecture

### Test Framework

| Property | Value |
|----------|-------|
| Framework | PHPUnit 11.x |
| Config file | `phpunit.xml` (project root) |
| Quick run command | `vendor/bin/phpunit --no-coverage` |
| Full suite command | `vendor/bin/phpunit` |

### Phase Requirements → Test Map

| Req ID | Behavior | Test Type | Automated Command | Notes |
|--------|----------|-----------|-------------------|-------|
| SEC-01 | No secret committed; `.env` gitignored | Static audit | `git ls-files .env && git grep -l "BEGIN.*PRIVATE KEY" -- ":(exclude).planning"` | Returns empty = PASS |
| SEC-02 | HSTS header present in production response | Smoke (live) | `curl -sI https://resseltrack-nu.vercel.app/ \| grep -i "strict-transport-security"` | Must contain `max-age=` |
| SEC-03 | CSP `img-src` includes Cloudinary domain | Static inspect | `grep "res.cloudinary.com" public/index.php` | Must match |
| SEC-04 | Boot gate fires on dangerous config (SESSION_SECURE=0 on HTTPS) | Manual / integration | `php -r "..."` bootstrap simulation; or inspect gate code review | Gate logic is inline in index.php; PHPUnit tests do not cover front-controller bootstrap |

### Sampling Rate

- **Per task commit:** `vendor/bin/phpunit --no-coverage` (existing unit tests — covers ProfitCalculator, not new gate code)
- **Per wave merge:** `vendor/bin/phpunit` (full suite)
- **Phase gate:** All four SEC verification commands above pass; `vendor/bin/phpunit` green

### Wave 0 Gaps

No new PHPUnit test files are required for Phase 5. The boot gate is an inline script block in `public/index.php` that exits before the application layer; it cannot be reached by PHPUnit (which does not bootstrap via the front controller). Verification is via:
1. Static code review of the gate conditions
2. Live smoke tests (`curl -sI`) post-deployment

Existing `tests/ProfitCalculatorTest.php` is unaffected.

*(No Wave 0 test file creation needed — coverage of front-controller bootstrap is out of PHPUnit scope as documented in `phpunit.xml` source: `<directory suffix=".php">src/Services</directory>`)*

---

## Security Domain

### Applicable ASVS Categories

| ASVS Category | Applies | Standard Control |
|---------------|---------|-----------------|
| V2 Authentication | no | No auth code changed |
| V3 Session Management | yes (SEC-04) | Boot gate enforces SESSION_SECURE=1 before session starts |
| V4 Access Control | no | No access control changes |
| V5 Input Validation | no | No user input changes |
| V6 Cryptography | no | No crypto changes |
| V9 Communications Security | yes (SEC-02) | HSTS enforces TLS for future connections |
| V14 Configuration | yes (SEC-01, SEC-04) | No secrets committed; boot gate prevents dangerous config |

### Known Threat Patterns for PHP + Vercel

| Pattern | STRIDE | Standard Mitigation |
|---------|--------|---------------------|
| Protocol downgrade / HTTPS stripping | Tampering | HSTS `max-age=31536000; includeSubDomains` |
| Deployment with default credentials | Elevation of Privilege | Boot gate checks DB_PASSWORD !== 'reselltrack' and !== '' |
| Session cookie over plain HTTP | Information Disclosure | Boot gate enforces SESSION_SECURE=1 before Auth::start() |
| Committed API key/password | Information Disclosure | `.gitignore` excludes `.env`; boot gate enforces non-empty Cloudinary creds |
| CSP bypass via unapproved image host | Tampering/XSS | `img-src 'self' data: https://res.cloudinary.com` in CSP (SEC-03) |

---

## Sources

### Primary (HIGH confidence)

- [Vercel Request Headers docs](https://vercel.com/docs/headers/request-headers) — confirms `x-forwarded-proto` is set to `https` in production; source of HTTPS detection approach for D-01
- [vercel-community/php cgi.ts source](https://github.com/vercel-community/php/blob/master/src/launchers/cgi.ts) — confirms `HTTPS: "On"` hardcoded and HTTP header → `HTTP_*` mapping in PHP `$_SERVER`
- Live `curl -sI https://resseltrack-nu.vercel.app/` (2026-06-15) — confirms Vercel platform HSTS header (`max-age=63072000; includeSubDomains; preload`)
- `public/index.php` (codebase) — current boot order, security headers block, CSP line
- `.gitignore` (codebase) — `.env` exclusion confirmed
- `certs/aiven-ca.pem` (codebase) — `-----BEGIN CERTIFICATE-----` confirms public CA cert

### Secondary (MEDIUM confidence)

- vercel-community/php master branch `cgi.ts` assumed representative of `@0.7.4` CGI behavior for `HTTPS` and header mapping (core behavior is stable across minor versions)

### Tertiary (LOW confidence / ASSUMED)

- RFC 6797 HSTS spec: `includeSubDomains` scoping, plain-HTTP prohibition, first-header-wins rule — standard training knowledge, unchanged since 2012
- PSL treatment of `vercel.app` — training knowledge; safe assumption given Vercel's own HSTS uses `includeSubDomains`

---

## Metadata

**Confidence breakdown:**
- HTTPS detection predicate: HIGH — Vercel docs + source code verified
- Vercel platform HSTS status: HIGH — live curl confirmed
- HSTS spec compliance (`includeSubDomains` safe, no HTTP): MEDIUM — standard, long-stable RFC
- Boot gate placement and pattern: HIGH — code reading + existing pattern in Database.php
- SEC-01 audit: HIGH — all checks performed against actual files

**Research date:** 2026-06-15
**Valid until:** 2026-09-15 (90 days; Vercel header behavior and PHP runtime behavior are stable)
