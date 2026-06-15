# Phase 5: Security Hardening — Validation Map

**Generated:** 2026-06-15
**Source:** 05-RESEARCH.md "Validation Architecture"
**Nyquist:** ENABLED — every requirement has an automated gate (Wave 1) and/or a deterministic live smoke (post-deploy).

---

## Test Framework

| Property | Value |
|----------|-------|
| Framework | PHPUnit 11.x (existing) |
| Config file | `phpunit.xml` (project root) |
| Coverage source | `src/Services/` only (business logic) — front-controller bootstrap is OUT of PHPUnit scope by design |
| Quick run | `vendor/bin/phpunit --no-coverage` |
| Full suite | `vendor/bin/phpunit` |

The Phase 5 changes are inline in `public/index.php` (the front controller). They `exit` before the application layer and cannot be reached by PHPUnit. Per REQUIREMENTS.md ("seul ProfitCalculator reste testé") no new PHPUnit file is created. Validation is `php -l` + structural grep + `$isHttps` predicate simulation (Wave 1) and `curl` smoke (post-deploy).

---

## SEC-01..04 → Verification Map

| Req ID | Behavior | Type | Automated command (Wave 1 = local) | Plan task | Pass = |
|--------|----------|------|------------------------------------|-----------|--------|
| **SEC-01** | No secret committed; `.env` gitignored; CA cert is public | Static audit | `git ls-files .env` (empty) + `.gitignore` matches `^\.env` + `git grep -I -l "BEGIN.*PRIVATE KEY" -- ':(exclude).planning'` (empty) + `certs/aiven-ca.pem` first line `-----BEGIN CERTIFICATE-----` | Task 2 | all four checks clean |
| **SEC-02** | HSTS emitted in production, gated on `$isHttps`, no preload | Static + live | Wave 1: `php -l` + `(?s)if ($isHttps) { header('Strict-Transport-Security: max-age=31536000; includeSubDomains'` match + no `preload`. Live: `curl -sI https://resseltrack-nu.vercel.app/ \| grep -i strict-transport` | Task 1 | gated header present locally; `max-age=` line present live |
| **SEC-03** | CSP `img-src` covers Cloudinary delivery domain | Static inspect | `Get-Content -Raw public/index.php` matches `img-src` AND `res\.cloudinary\.com` | Task 2 | match (already present since Phase 4) |
| **SEC-04** | App refuses to start on dangerous prod config | Static + live | Wave 1: structural greps (SESSION_SECURE!=='1', `=== 'reselltrack'`, CLOUDINARY_API_SECRET, http_response_code(500), Content-Type text/html) + IndexOf ordering (gate after `/health`, before `Auth::start`) + `$isHttps` predicate sim (LOCAL→off / PROD→armed). Live (negative test): set `SESSION_SECURE=0` in Vercel, redeploy → French 500 config page; revert | Task 1 | gate present + correctly placed + gates off on local HTTP |

---

## $isHttps Predicate Simulation (the local proof the gate gates correctly)

Both branches run via `php -r` with no app bootstrap:

- **Local HTTP** (no `HTTP_X_FORWARDED_PROTO`, no `HTTPS`) → predicate evaluates `false` → `LOCAL` → boot gate skipped, HSTS not emitted. This is the load-bearing proof that local Docker dev and the `/health` keep-alive path are unaffected (D-01/D-02).
- **Production HTTPS** (`HTTP_X_FORWARDED_PROTO=https`) → predicate evaluates `true` → `PROD` → gate armed, HSTS emitted.

The predicate string under test is the exact expression from 05-RESEARCH "Complete $isHttps Predicate" — the simulation mirrors what `public/index.php` computes, so a drift between the file and the predicate would be caught by the Task 1 structural grep (`HTTP_X_FORWARDED_PROTO` + `$isHttps` present).

---

## Sampling Rate

| Trigger | Gate |
|---------|------|
| Per task commit | `php -l public/index.php` + that task's structural greps |
| Per plan (Wave 1) merge | `vendor/bin/phpunit` (existing ProfitCalculator suite — must stay green; no Services logic changed) + all SEC-01/SEC-03 audit commands |
| Phase gate (post-deploy) | All four SEC verification rows pass; live `curl` HSTS present; `/login` 200; SEC-04 negative test fires then reverted; `/health` returns `{"status":"ok","db":"up"}` throughout |

---

## Wave 0 Gaps

**None.** No new PHPUnit test file is required for Phase 5.

The boot gate and HSTS header are inline in `public/index.php` and `exit`/`header()` before the application layer; PHPUnit (coverage source `src/Services/`) does not bootstrap via the front controller. This matches the Plan 03-01 and 04-01 precedent. The intentionally-absent front-controller bootstrap test (`tests/` does not cover `public/index.php`) is recorded here, not scaffolded — REQUIREMENTS.md explicitly scopes extended controller/Core tests OUT of this milestone.

`tests/ProfitCalculatorTest.php` is unaffected by Phase 5 and must remain green.

---

## Post-Deploy Live Smoke (orchestrator runs after Vercel redeploy)

```
# SEC-02 — HSTS present (one or two STS lines is fine; RFC 6797 first-header-wins)
curl -sI https://resseltrack-nu.vercel.app/ | grep -i "strict-transport-security"

# Site still works under correct prod config (gate does NOT fire)
curl -sI https://resseltrack-nu.vercel.app/login   # expect HTTP/2 200

# SEC-04 — deliberate negative test, THEN revert:
#   1. Set SESSION_SECURE=0 in Vercel env vars, redeploy
#   2. curl -sI https://resseltrack-nu.vercel.app/   → expect HTTP 500 + French config page (not a broken app)
#   3. curl -s  https://resseltrack-nu.vercel.app/health → still {"status":"ok","db":"up"}
#   4. Restore SESSION_SECURE=1, redeploy
```

These are NOT run in Wave 1 (no live deploy in an autonomous code plan). This phase has no operator/Wave-2 plan, so the live smoke is handed to the orchestrator.
