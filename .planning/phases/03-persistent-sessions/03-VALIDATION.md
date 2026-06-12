# Phase 3: Persistent Sessions — Validation Map

**Created:** 2026-06-12
**Nyquist validation:** enabled (`workflow.nyquist_validation = true`)
**Source:** extracted from `03-RESEARCH.md` → "Validation Architecture"

---

## Test Framework

| Property | Value |
|----------|-------|
| Framework | PHPUnit 11.x |
| Config file | `phpunit.xml` (project root) |
| Quick run | `composer test` |
| Full suite | `vendor/bin/phpunit` |
| Coverage source | `src/Services/` only — `src/Core/` (incl. `DatabaseSessionHandler`) is NOT in the coverage source |

Per REQUIREMENTS.md (Out of Scope): "Couverture de tests étendue (contrôleurs, modèles, Core) — hors périmètre ; seul `ProfitCalculator` reste testé." This phase adds no `src/Services/` change, so the existing `ProfitCalculatorTest` suite must remain green as a non-regression signal, and no new unit-test coverage is required for sign-off.

---

## SESS-01..04 → Verification Map

| Req ID | Behavior | Test Type | Verification (automated where possible) | Wave / Plan |
|--------|----------|-----------|------------------------------------------|-------------|
| SESS-02 | `DatabaseSessionHandler` implements `\SessionHandlerInterface` (6 methods); reuses `Database::connection()`; row-alias UPSERT; read returns `''` on miss | Static / lint | `php -l src/Core/DatabaseSessionHandler.php` + Select-String gates (implements iface, six methods, `ON DUPLICATE KEY UPDATE`, no `VALUES(`, `expires_at >= UNIX_TIMESTAMP`) | Wave 1 / 03-01 Task 1 |
| SESS-02 | sessions table defined in BOTH `sql/schema.sql` and `Schema::ensure()` with the expiry index; `session.gc_probability = 0` in api/php.ini | Static / lint | `php -l src/Core/Schema.php` + Select-String gates (`CREATE TABLE IF NOT EXISTS sessions`, `MEDIUMBLOB`, `idx_sessions_expires`, `session.gc_probability = 0`) | Wave 1 / 03-01 Task 2 |
| SESS-02 | sessions table created on the live Aiven DB | Manual after migrate | `php bin/migrate.php` exits 0; `SHOW TABLES LIKE 'sessions';` + `DESCRIBE sessions;` on Aiven | Wave 2 / 03-02 Task 1 |
| SESS-01 / SESS-03 | `Auth::start()` registers handler before `session_start()`; cookie lifetime 30d with Secure/HttpOnly/SameSite=Lax | Static / lint | `php -l src/Core/Auth.php` + Select-String gates (`session_set_save_handler($handler, true)` before `session_start`, `30 * 86400`, flags intact) | Wave 1 / 03-01 Task 3 |
| SESS-01 | Logged-in user stays authenticated across 3 successive navigations (no `/login` redirect); session row present in Aiven | Manual E2E (no live Aiven in CI) | Browser: login → `/dashboard` → `/products` → `/orders`; `SELECT id FROM sessions ...` on Aiven | Wave 2 / 03-02 Task 2 |
| SESS-03 | Production `RESELLTRACK_SESS` cookie has Secure + HttpOnly + SameSite=Lax + ~30-day expiry | Manual — DevTools or curl | `curl -sI https://<URL>/login | findstr /I RESELLTRACK_SESS` + DevTools cookie inspection | Wave 2 / 03-02 Task 2 |
| SESS-04 | A real POST form (product create / register) returns no HTTP 419 CSRF error | Manual E2E | Browser POST form submit on `<URL>` succeeds; `Csrf.php` unchanged | Wave 2 / 03-02 Task 2 |
| SESS-01 (logout) | After logout, a protected route redirects to `/login` (session invalidated via `destroy()`) | Manual E2E | Browser: logout → `/dashboard` → redirected to `/login` | Wave 2 / 03-02 Task 2 |

---

## Wave 0 Gaps (deferred — out of scope per REQUIREMENTS.md)

- [ ] `tests/Core/DatabaseSessionHandlerTest.php` — unit tests for `read()` / `write()` / `destroy()` / `gc()` against a test database or mock PDO.
  - **Status:** NOT created. Explicitly out of scope per REQUIREMENTS.md ("couverture de tests étendue … hors périmètre"); `src/Core/` is not in the `phpunit.xml` coverage source. No live Aiven / CI secrets are available for an integration test. The handler is instead validated by lint + structural gates in Wave 1 and by live end-to-end checks in Wave 2.

This is the Nyquist-documented gap: there is no per-method automated behavioral test for the handler. The behavioral guarantee is provided by the Wave 2 operator E2E checks (SESS-01..04 on the live URL), which exercise read/write/destroy/gc through real session lifecycles.

---

## Practical Verification Plan (no CI secrets needed)

1. `php bin/migrate.php` → verify exit 0 and "Migration complete." (03-02 Task 1)
2. `SHOW TABLES LIKE 'sessions';` / `DESCRIBE sessions;` on Aiven → table + index present (03-02 Task 1)
3. Browser: login → navigate `/dashboard` → `/products` → `/orders` → still authenticated (SESS-01)
4. DevTools → Application → Cookies (or `curl -sI`) → `RESELLTRACK_SESS` has HttpOnly, Secure, SameSite=Lax, ~30-day expiry (SESS-03)
5. Submit a POST form → no 419 (SESS-04)
6. Logout → navigate to a protected route → redirected to `/login` (SESS-01 logout side)
7. Cross-check Vercel logs: no "table 'sessions' doesn't exist", no PDO write() exception, no "MySQL server has gone away"

---

*Phase: 3-Persistent Sessions*
*Validation map created: 2026-06-12*
