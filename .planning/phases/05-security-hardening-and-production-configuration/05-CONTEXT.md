# Phase 5: Security Hardening and Production Configuration - Context

**Gathered:** 2026-06-15
**Status:** Ready for planning

<domain>
## Phase Boundary

Harden the live deployment: emit **HSTS** over HTTPS, **refuse to boot** in production with a dangerous configuration, and confirm secrets and the image CSP are correct. The surface is `public/index.php` (security headers + a boot safety gate) plus verification — no business-logic or feature changes.

Requirements: **SEC-01** (secrets only in Vercel env vars; no committed secret; `.env` gitignored), **SEC-02** (`Strict-Transport-Security` emitted in production), **SEC-03** (CSP `img-src` covers the image domain), **SEC-04** (app refuses to start in production with a dangerous config, e.g. `SESSION_SECURE=0` or default credentials).

**Already satisfied (verify, don't rebuild):** SEC-01 (`.env` gitignored since Phase 1; DB/session/Cloudinary secrets live in Vercel env vars; only the public CA cert is committed) and SEC-03 (`img-src 'self' data: https://res.cloudinary.com` added in Phase 4 — note the requirement text says "R2" but Cloudinary is the actual provider).

**Out of scope / deferred:** CSRF token rotation (v2/HARD), rate limiting on /register & /export (v2/HARD), graceful 503 page (v2/OPS), `securityheaders.com` A+ tuning beyond the required headers.
</domain>

<decisions>
## Implementation Decisions

### Production detection
- **D-01:** "Production" is detected by the request being **HTTPS**, via the `X-Forwarded-Proto: https` header that Vercel sets (`$_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https'`, with `$_SERVER['HTTPS']` as a fallback). **No `APP_ENV` env var is introduced** — so there is NO operator step for Phase 5. Local Docker (plain HTTP) is therefore never treated as production (HSTS off, boot gate off). Compute an `isHttps`/`isProduction` boolean once in `public/index.php` and reuse it for both D-02 and D-03.

### HSTS (SEC-02)
- **D-02:** Add `header('Strict-Transport-Security: max-age=31536000; includeSubDomains');` to the security-headers block of `public/index.php`, emitted **only when the request is HTTPS** (per D-01). **`max-age=31536000` (1 year), `includeSubDomains`, NO `preload`** (we don't control the `vercel.app` apex, and preload is hard to reverse). Per spec, HSTS is not sent over plain HTTP.

### Boot safety assertion (SEC-04)
- **D-03:** In `public/index.php`, when the request is HTTPS (production, per D-01), run a boot gate **before** `Auth::start()`/dispatch. If ANY of these dangerous conditions hold, refuse to start: render a clear French error page (HTTP 500, friendly message — NOT a stack trace or a half-working app) and `exit`; log the specific reason via `error_log`:
  - `SESSION_SECURE` !== `'1'` (the SEC-04 example),
  - `DB_PASSWORD` is empty OR equals the dev default `'reselltrack'`,
  - any of `DB_HOST` / `DB_NAME` / `DB_USER` is empty,
  - any of `CLOUDINARY_CLOUD_NAME` / `CLOUDINARY_API_KEY` / `CLOUDINARY_API_SECRET` is empty.
- **D-04:** Placement: after `Env::load()` and **after the `/health` early-return** (so the keep-alive ping still works regardless), and **before** `Auth::start()`. The gate must not run on local HTTP. The error page may be a small inline HTML/text block (reuse the project's friendly-error style; no new view layer required).

### Verification-only
- **D-05 (SEC-01):** Confirm no secret is committed (grep the repo for key/password patterns), `.env` is gitignored, and `certs/aiven-ca.pem` is a public CA cert. No code change.
- **D-06 (SEC-03):** Confirm CSP `img-src` includes the Cloudinary delivery domain (`res.cloudinary.com`) — already present from Phase 4. No code change (optionally reword the inline comment to drop the "Phase 5 SEC-03 pre-satisfy" note since this phase formalizes it).
</decisions>

<canonical_refs>
## Canonical References
- `.planning/REQUIREMENTS.md` §Sécurité (SEC-01..04)
- `.planning/PROJECT.md` §Constraints — "secrets hors du dépôt, cookies sécurisés, en-têtes de sécurité de production"
- `public/index.php` — the ONLY file modified: security-headers block (HSTS) + a new boot gate; `isHttps` detection. Note current ordering: Env::load → `/health` early-return → Auth::start → security headers → routes.
- `src/Core/Auth.php:24` — `SESSION_SECURE` read (`Env::get('SESSION_SECURE','0') === '1'`); the cookie `secure` flag depends on it (SEC-04 ties here).
- `src/Core/Database.php` — DB_* defaults (`DB_PASSWORD` default `'reselltrack'` — the dangerous-default to catch).
- `src/Services/CloudinaryStorage.php` — `CLOUDINARY_*` env reads (missing-creds check).
- `.gitignore` / `.vercelignore` — confirm `.env` excluded (SEC-01).
</canonical_refs>

<code_context>
## Existing Code Insights
### Reusable Assets
- `public/index.php` already emits X-Content-Type-Options, X-Frame-Options, Referrer-Policy, CSP — add HSTS in the same block (one `header()` call, gated on HTTPS).
- The DB-failure friendly page (`Database::connection()` catch: `http_response_code(500); echo '...'; exit;`) is the style template for the SEC-04 boot-gate error page.
- `Env::get()` is the single config accessor for every check.
### Established Patterns
- Single front controller boots everything; no framework. The boot gate is a small inline block, consistent with the existing inline `/health` handler and header emission.
### Integration Points
- D-01's HTTPS detection (`X-Forwarded-Proto`) is the same signal a future graceful-503 or env-aware behavior would use.
- SEC-04 references SESSION_SECURE (Phase 3), DB creds (Phase 2), Cloudinary creds (Phase 4) — it is the cross-cutting "is prod safely configured?" gate.
</code_context>

<specifics>
## Specific Ideas
- No operator step this phase (HTTPS detection avoids needing APP_ENV in Vercel) — purely autonomous code + verification. This is the first phase with no Wave-2 operator half.
- The boot gate doubles as a deploy-safety net: a future misconfigured redeploy (e.g. forgetting SESSION_SECURE) fails loudly with a clear page instead of silently shipping insecure cookies.
</specifics>

<deferred>
## Deferred Ideas
- CSRF token rotation after each POST (v2/HARD).
- Rate limiting on /register and /export/* (v2/HARD).
- Graceful 503 page on DB failure (v2/OPS) — distinct from the SEC-04 config gate.
- securityheaders.com A+ extras (Permissions-Policy, COOP/COEP) — not required by SEC-01..04.

### Research Questions (for gsd-phase-researcher)
- Reliable HTTPS detection under vercel-php@0.7.4: is `$_SERVER['HTTP_X_FORWARDED_PROTO']` always set to `https` on Vercel production requests? Any case where `$_SERVER['HTTPS']` is the better/only signal? Confirm the exact key.
- Confirm HSTS should NOT be sent over plain HTTP (spec) and that gating on HTTPS is correct; confirm `includeSubDomains` is harmless on a `*.vercel.app` host.
- Best placement of the boot gate vs `/health` and header emission so keep-alive is unaffected and the error page is clean (does the friendly page need the security headers set first?).
- Any Vercel platform header already providing HSTS (avoid duplication)? Confirm the app should own it.
</deferred>

---

*Phase: 5-Security Hardening and Production Configuration*
*Context gathered: 2026-06-15*
