---
phase: 03-persistent-sessions
plan: "01"
subsystem: session-handler
tags: [php, mysql, sessions, auth]
dependency_graph:
  requires: [02-01]
  provides: [DatabaseSessionHandler, sessions-table-ddl]
  affects: [Auth::start, Schema::ensure, sql/schema.sql, api/php.ini]
tech_stack:
  added: []
  patterns: [SessionHandlerInterface, INSERT-ON-DUPLICATE-KEY-UPDATE, lazy-expiry]
key_files:
  created:
    - src/Core/DatabaseSessionHandler.php
  modified:
    - src/Core/Auth.php
    - src/Core/Schema.php
    - sql/schema.sql
    - api/php.ini
decisions:
  - "DatabaseSessionHandler reuses Database::connection() singleton (no new PDO construction)"
  - "Row-alias UPSERT syntax (MySQL 8.0.19+) — avoids deprecated VALUES() function"
  - "read() returns '' on miss (not false) — empty string = fresh session to PHP"
  - "session_set_save_handler registered before session_set_cookie_params for clarity"
  - "session.gc_probability = 0 in api/php.ini — lazy read-time expiry makes per-request GC unnecessary"
  - "No use import for DatabaseSessionHandler in Auth.php — same App\\Core namespace"
metrics:
  duration: "~15 minutes"
  completed: "2026-06-12"
  tasks_completed: 3
  tasks_total: 3
  files_created: 1
  files_modified: 4
---

# Phase 3 Plan 1: DatabaseSessionHandler + Sessions DDL + Auth Wiring Summary

MySQL-backed PHP session handler implementing `\SessionHandlerInterface` via the existing `Database::connection()` TLS PDO singleton, with lazy-expiry read, row-alias UPSERT write, sessions table added to both schema locations, and Auth::start() wired with a 30-day persistent cookie.

## Tasks Completed

| Task | Name | Commit | Files |
|------|------|--------|-------|
| 1 | Create DatabaseSessionHandler.php | f9e5a45 | src/Core/DatabaseSessionHandler.php |
| 2 | Add sessions DDL + disable GC | e6f4b8b | sql/schema.sql, src/Core/Schema.php, api/php.ini |
| 3 | Wire handler + 30-day cookie in Auth::start() | 3585f65 | src/Core/Auth.php |

## What Was Built

### Task 1: src/Core/DatabaseSessionHandler.php

`final class DatabaseSessionHandler implements \SessionHandlerInterface` in `namespace App\Core` with `declare(strict_types=1)` and `use PDO`. Six methods:

- `open()` / `close()`: no-op stubs returning `true` (PDO managed by the singleton)
- `read()`: `SELECT data FROM sessions WHERE id = ? AND expires_at >= UNIX_TIMESTAMP()` — returns `(string) $row['data']` on hit, `''` on miss (not `false`)
- `write()`: row-alias UPSERT `INSERT ... VALUES (?, ?, ?) AS new_row ON DUPLICATE KEY UPDATE data = new_row.data, expires_at = new_row.expires_at` with `$expiresAt = time() + 30 * 86400`
- `destroy()`: `DELETE FROM sessions WHERE id = ?`
- `gc()`: `DELETE FROM sessions WHERE expires_at < UNIX_TIMESTAMP()`, returns `$stmt->rowCount()`

Private constant `TTL = 30 * 86400`. No constructor — PDO fetched lazily inside each method via `Database::connection()`. No new Composer packages.

### Task 2: Sessions Table DDL (sql/schema.sql + Schema::ensure())

Both locations contain the identical DDL:
```sql
CREATE TABLE IF NOT EXISTS sessions (
    id         VARCHAR(128) NOT NULL,
    data       MEDIUMBLOB   NOT NULL,
    expires_at INT UNSIGNED NOT NULL,
    PRIMARY KEY (id),
    KEY idx_sessions_expires (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
```

In `sql/schema.sql`: inserted after `login_attempts` table, before `sales` table.
In `Schema::ensure()`: added as `$db->exec(...)` block alongside the other idempotent DDL.

No `user_id` column, no FK (deferred per D-03). The `idx_sessions_expires` index enables efficient `gc()` bulk deletes and range queries.

`api/php.ini` gets `session.gc_probability = 0` — disables per-request probabilistic GC since lazy read-time expiry in `read()` is the correctness guard; bulk cleanup via explicit `session_gc()` if needed.

### Task 3: Auth::start() — Handler Registration + 30-day Cookie

Two changes only inside `start()`, after the `PHP_SESSION_ACTIVE` early-return:

1. Before `session_set_cookie_params(...)`:
```php
$handler = new DatabaseSessionHandler();
session_set_save_handler($handler, true); // true = register session_write_close as shutdown fn
```

2. Cookie `lifetime` changed from `0` to `30 * 86400` via `$lifetime = 30 * 86400`.

`login()`, `logout()`, `attempt()`, `check()`, `id()`, `name()`, `require()` are all byte-for-byte unchanged. `Csrf.php` is untouched.

## Verification Results

| Check | Result |
|-------|--------|
| `php -l src/Core/DatabaseSessionHandler.php` | No syntax errors |
| `php -l src/Core/Schema.php` | No syntax errors |
| `php -l src/Core/Auth.php` | No syntax errors |
| `implements \SessionHandlerInterface` present | PASS |
| All six interface methods present | PASS |
| UPSERT with ON DUPLICATE KEY UPDATE | PASS |
| `expires_at >= UNIX_TIMESTAMP()` in read() | PASS |
| No `VALUES()` deprecated function | PASS |
| `Database::connection()` singleton reused | PASS |
| `CREATE TABLE IF NOT EXISTS sessions` in sql/schema.sql | PASS |
| `MEDIUMBLOB` + `idx_sessions_expires` in sql/schema.sql | PASS |
| `CREATE TABLE IF NOT EXISTS sessions` in Schema::ensure() | PASS |
| No `user_id` column in sessions DDL | PASS |
| `session.gc_probability = 0` in api/php.ini | PASS |
| `session_set_save_handler` before `session_start()` (line 22 vs 36) | PASS |
| Cookie `lifetime = 30 * 86400` | PASS |
| `secure`/`httponly`/`samesite=Lax` flags intact | PASS |
| `session_regenerate_id(true)` in login() unchanged | PASS |
| `session_destroy()` in logout() unchanged | PASS |
| `Csrf.php` unchanged | PASS |
| PHPUnit regression (ProfitCalculator suite) | N/A (vendor/ not installed locally; no src/Services/ touched) |

**Note on verify-command escaping:** The plan's PowerShell verify commands use `\\?` for optional-backslash regex. When run through the Bash tool on Windows, Bash processes `\\` to `\` before PowerShell sees it, causing some pattern checks to fail with regex parse errors. All structural checks were validated using equivalent PowerShell scripts that avoid the escaping issue. The actual code meets all acceptance criteria.

## Deviations from Plan

None — plan executed exactly as written. No bugs encountered, no missing functionality discovered, no architectural changes needed.

## Known Stubs

None. The handler is fully implemented; no placeholder methods or hardcoded returns other than the intentional `open()`/`close()` no-ops required by the interface contract.

## Threat Flags

No new threat surface introduced beyond what was modeled in the plan's `<threat_model>`. The `sessions` table is accessed only through the existing least-privilege MySQL user over the Phase 2 TLS PDO connection.

## Next Steps (Wave 2 — Plan 03-02)

1. Run `bin/migrate.php` against Aiven to create the `sessions` table (D-09)
2. Set `SESSION_SECURE=1` in Vercel environment variables (D-10)
3. Push + redeploy to Vercel
4. Smoke test: login → navigate → verify session persists (SESS-01)
5. Verify `RESELLTRACK_SESS` cookie has Secure + HttpOnly + SameSite=Lax + ~30d expiry in DevTools (SESS-03)
6. Submit a form → verify no 419 (SESS-04 live proof)

## Self-Check: PASSED

- `src/Core/DatabaseSessionHandler.php`: EXISTS (created, lint-clean)
- `src/Core/Auth.php`: MODIFIED (session_set_save_handler + 30d cookie)
- `src/Core/Schema.php`: MODIFIED (sessions CREATE TABLE IF NOT EXISTS)
- `sql/schema.sql`: MODIFIED (sessions table after login_attempts)
- `api/php.ini`: MODIFIED (session.gc_probability = 0)
- Commits f9e5a45, e6f4b8b, 3585f65: ALL PRESENT in git log
