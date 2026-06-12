# Phase 3: Persistent Sessions - Context

**Gathered:** 2026-06-12
**Status:** Ready for planning

<domain>
## Phase Boundary

Replace PHP's ephemeral **file-based** sessions (which do not survive serverless Lambda invocations) with a **MySQL-backed** session store, so login state, flash messages, and the CSRF token persist across successive requests on Vercel. Cookie carries production security flags; CSRF continues to work on the new store.

Requirements: **SESS-01** (session persists across serverless invocations), **SESS-02** (stored in MySQL via `SessionHandlerInterface`), **SESS-03** (cookie `Secure`+`HttpOnly`+`SameSite=Lax` in prod), **SESS-04** (CSRF still works on the new store).

**Out of scope (own phases / deferred):** image storage / R2 (Phase 4), HSTS / boot safety assertion / CSP (Phase 5), connection pooling (v2), "logout everywhere" / per-user session admin (v2), session write dirty-tracking optimization (v2).
</domain>

<decisions>
## Implementation Decisions

### Session storage mechanism
- **D-01:** New `final class DatabaseSessionHandler implements \SessionHandlerInterface` in `src/Core/`. Implements open/close/read/write/destroy/gc. Uses the existing `Database::connection()` singleton PDO (no new connection). Reaffirms the locked PROJECT.md decision (MySQL store, no Redis).
- **D-02:** Wire it in `Auth::start()` via `session_set_save_handler($handler, true)` **before** `session_start()` (the `true` registers shutdown write-close). All other `$_SESSION` usage across the app is unchanged — the store is transparent.

### Sessions table schema
- **D-03:** New `sessions` table: `id VARCHAR(128) NOT NULL PRIMARY KEY` (the PHP session id), `data MEDIUMBLOB NOT NULL` (serialized payload — MEDIUMBLOB is binary-safe), `expires_at INT UNSIGNED NOT NULL` (Unix timestamp) with an index for cleanup. `ENGINE=InnoDB DEFAULT CHARSET=utf8mb4`. **No `user_id` column** this phase (deferred). Added to BOTH `sql/schema.sql` and `Schema::ensure()` (`CREATE TABLE IF NOT EXISTS`) so re-running `bin/migrate.php` creates it on Aiven idempotently.

### Expiration & cleanup
- **D-04:** **Lazy expiration, TTL 30 days.** `read()` selects `data` only `WHERE id = ? AND expires_at >= UNIX_TIMESTAMP()`; an expired/absent row returns `''` (PHP treats as a fresh session). `write()` sets `expires_at = now + 30 days`. `gc()` does an optional bulk `DELETE FROM sessions WHERE expires_at < UNIX_TIMESTAMP()`. Does NOT rely on PHP's probabilistic GC firing in serverless.

### Cookie
- **D-05:** **Persistent cookie, lifetime 30 days** (aligned with the server TTL). Change `session_set_cookie_params` `lifetime` in `Auth::start()` from `0` to `30 * 86400`. Keep `secure` (from `SESSION_SECURE`), `httponly => true`, `samesite => 'Lax'` (SESS-03). The `RESELLTRACK_SESS` cookie name stays.

### Write strategy
- **D-06:** **Write on every request** — `write()` does an UPSERT (`INSERT ... ON DUPLICATE KEY UPDATE data = VALUES(data), expires_at = VALUES(expires_at)`) on every `session_write_close`. No dirty-tracking this phase (simple; consistent with Phase 2 D-10/D-11 "accept simple, defer optimization").

### Security continuity
- **D-07:** **CSRF (SESS-04) needs no code change** — `Csrf` stores its token in `$_SESSION['_csrf_token']` (`src/Core/Csrf.php`); once `$_SESSION` is MySQL-backed, the token persists across Lambdas, which actually *fixes* the latent serverless CSRF-419 problem. Verify a real POST (e.g. `/register` or product create) returns no 419.
- **D-08:** `session_regenerate_id(true)` on login (`Auth::login`) must keep working with the custom handler — it triggers the handler's `destroy()` on the old id + `write()` of the new id. Verify login rotates the id with no orphan row left behind.

### Operator steps (autonomous:false)
- **D-09:** **Re-run `bin/migrate.php` against Aiven** to create the new `sessions` table (idempotent; operator runs locally, same as Phase 2).
- **D-10:** **Set `SESSION_SECURE=1` as a Vercel env var** so the `Secure` cookie flag is emitted on the HTTPS production site (currently only in local `.env`). Operator dashboard step.
</decisions>

<canonical_refs>
## Canonical References

**Downstream agents MUST read these before planning or implementing.**

### Requirements & decisions
- `.planning/REQUIREMENTS.md` §Sessions (SESS-01, SESS-02, SESS-03, SESS-04)
- `.planning/PROJECT.md` §Key Decisions — "Sessions stockées en base MySQL (`SessionHandlerInterface`)", no Redis
- `.planning/phases/02-database-and-schema-migration/02-CONTEXT.md` — DB connection + migration mechanics this phase reuses (TLS, bin/migrate.php run model, no seed)

### Code to modify / reference
- `src/Core/Auth.php` — `start()` (wire `session_set_save_handler` + lifetime change), `login()` (regenerate id), `logout()` (destroy)
- `src/Core/Csrf.php` — token in `$_SESSION`; unchanged, must keep working (SESS-04)
- `src/Core/Database.php` — PDO singleton the handler reuses (TLS connection from Phase 2)
- `sql/schema.sql` + `src/Core/Schema.php` — add the `sessions` table (CREATE TABLE IF NOT EXISTS)
- `bin/migrate.php` — re-run against Aiven to create the table (D-09)
- `.env.example` — already has `SESSION_SECURE`; document the Vercel requirement (D-10)
</canonical_refs>

<code_context>
## Existing Code Insights

### Reusable Assets
- `Database::connection()` — TLS-capable PDO singleton (Phase 2); the handler calls it directly. No new connection logic.
- `Schema::ensure()` + `bin/migrate.php` — the idempotent migration path; just add the `sessions` CREATE and re-run (D-09).
- `Auth::start()` already centralizes session bootstrap (cookie params + `session_start`) — single wiring point for the handler.

### Established Patterns
- `final` classes, `declare(strict_types=1)`, PSR-4 `App\Core\`. The handler follows the same shape as other `Core` services.
- Raw PDO prepared statements everywhere (no ORM) — the handler uses prepared UPSERT/SELECT/DELETE.

### Integration Points
- `$_SESSION` is read/written by `Auth` (user_id/user_name), `Csrf` (_csrf_token), and `Controller` flash/old-input — all become MySQL-backed transparently once the handler is registered.
- Session writes happen at request shutdown (`session_write_close`); the handler must hold a valid PDO at that point — `Database::connection()` singleton persists for the request, so this is safe.
</code_context>

<specifics>
## Specific Ideas

- The persistent-session fix also resolves a latent bug: on file-based serverless sessions the CSRF token (and login) silently fail across cold starts. SESS-01 + SESS-04 are two faces of the same fix.
- Reuse the Phase 2 operator rhythm exactly: edit code (autonomous) → re-run `bin/migrate.php` + set one Vercel env var (operator) → verify live.
</specifics>

<deferred>
## Deferred Ideas

- **`user_id` column on sessions** + "log out everywhere" / session admin — v2.
- **Write dirty-tracking** (only write when session data changed) — v2 perf optimization.
- **Session payload encryption at rest** — not needed (Aiven encrypts storage; payload is non-sensitive app session state).

None are Phase 3 blockers.

### Research Questions (for gsd-phase-researcher)
- Correct PHP 8.3 `SessionHandlerInterface` implementation for a serverless/stateless request: exact `session_set_save_handler($h, true)` ordering vs `session_start()`, and whether an explicit `register_shutdown_function('session_write_close')` is needed.
- `session_regenerate_id(true)` semantics with a custom handler — confirm it calls `destroy(old)` then `write(new)` and leaves no orphan row; verify CSRF token survives the rotation.
- Whether to disable PHP's native GC (`session.gc_probability = 0`) and rely solely on lazy read-time expiry + explicit `gc()` cleanup.
- MEDIUMBLOB sizing vs default PHP session serialization; any encoding pitfalls writing serialized session data through PDO prepared statements (binary-safe).
- Reusing the `Database::connection()` singleton inside handler `open()`/`write()` at request shutdown — confirm the PDO is still alive and no "MySQL server has gone away" at close.
</deferred>

---

*Phase: 3-Persistent Sessions*
*Context gathered: 2026-06-12*
