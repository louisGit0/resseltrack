# Phase 3: Persistent Sessions — Research

**Researched:** 2026-06-12
**Domain:** PHP 8.3 `SessionHandlerInterface`, MySQL UPSERT, vercel-php@0.7.4 request lifecycle
**Confidence:** HIGH

---

<user_constraints>
## User Constraints (from CONTEXT.md)

### Locked Decisions
- **D-01:** New `final class DatabaseSessionHandler implements \SessionHandlerInterface` in `src/Core/`. Implements open/close/read/write/destroy/gc. Uses the existing `Database::connection()` singleton PDO (no new connection).
- **D-02:** Wire it in `Auth::start()` via `session_set_save_handler($handler, true)` **before** `session_start()`. The `true` registers shutdown write-close.
- **D-03:** New `sessions` table: `id VARCHAR(128) NOT NULL PRIMARY KEY`, `data MEDIUMBLOB NOT NULL`, `expires_at INT UNSIGNED NOT NULL` with an index. `ENGINE=InnoDB DEFAULT CHARSET=utf8mb4`. No `user_id`. Added to `sql/schema.sql` AND `Schema::ensure()`.
- **D-04:** Lazy expiration, TTL 30 days. `read()` SELECTs `WHERE id = ? AND expires_at >= UNIX_TIMESTAMP()`. `write()` sets `expires_at = now + 30d`. `gc()` DELETEs `WHERE expires_at < UNIX_TIMESTAMP()`.
- **D-05:** Persistent cookie lifetime 30 days. Change `session_set_cookie_params` `lifetime` from `0` to `30 * 86400`. Keep `secure`/`httponly`/`samesite=Lax`.
- **D-06:** Write on every request — `write()` does an UPSERT on every `session_write_close`. No dirty-tracking.
- **D-07:** CSRF (`Csrf.php`) needs no code change — `$_SESSION['_csrf_token']` persists transparently.
- **D-08:** `session_regenerate_id(true)` on login must keep working.
- **D-09:** Operator re-runs `bin/migrate.php` after deploy to create the sessions table.
- **D-10:** Operator sets `SESSION_SECURE=1` as a Vercel env var.

### Claude's Discretion
- Exact SQL (row-alias syntax vs VALUES() in UPSERT).
- Whether to add `session.gc_probability = 0` to `api/php.ini`.
- Exact return types / error handling inside handler methods.

### Deferred Ideas (OUT OF SCOPE)
- `user_id` column on sessions + "log out everywhere".
- Write dirty-tracking.
- Session payload encryption at rest.
</user_constraints>

---

<phase_requirements>
## Phase Requirements

| ID | Description | Research Support |
|----|-------------|------------------|
| SESS-01 | Session persists across serverless invocations | vercel-php php-cgi model confirmed: per-request process runs to completion, shutdown functions fire; MySQL-backed sessions survive across Lambda cold starts |
| SESS-02 | Sessions stored in MySQL via `SessionHandlerInterface` | PHP 8.3 interface contract confirmed; DDL, UPSERT, SELECT patterns documented below |
| SESS-03 | Cookie `Secure`+`HttpOnly`+`SameSite=Lax` in prod | `session_set_cookie_params` already wires these; only `lifetime` change needed; `SESSION_SECURE` env var already exists |
| SESS-04 | CSRF still works on new store | `session_regenerate_id(true)` lifecycle confirmed: `$_SESSION` preserved; CSRF token survives; no code change to `Csrf.php` |
</phase_requirements>

---

## Summary

Phase 3 replaces PHP's default file-based session save handler with a MySQL-backed one by implementing `\SessionHandlerInterface`. The implementation is a single new class (`DatabaseSessionHandler`) that reuses the existing `Database::connection()` PDO singleton. The only other code changes are two lines in `Auth::start()` and one line in `api/php.ini`.

All six research questions from 03-CONTEXT.md are resolved with HIGH confidence. The key findings: vercel-php@0.7.4 uses php-cgi (one process per request, normal shutdown fires), so `session_set_save_handler($h, true)` wires everything correctly without any extra `register_shutdown_function` call. The PDO singleton is alive when `write()` fires at shutdown. `session_regenerate_id(true)` destroys the old row and writes the new ID's data with the `$_SESSION` payload intact (CSRF token survives). MEDIUMBLOB with `PARAM_STR` and `ATTR_EMULATE_PREPARES=false` is binary-safe. No new Composer packages are needed.

**Primary recommendation:** Implement `DatabaseSessionHandler` as a `final` class in `src/Core/` using the exact patterns below. Add `session.gc_probability = 0` to `api/php.ini`. Add the sessions table to `sql/schema.sql` and `Schema::ensure()`, then re-run `bin/migrate.php`.

---

## Architectural Responsibility Map

| Capability | Primary Tier | Secondary Tier | Rationale |
|------------|-------------|----------------|-----------|
| Session persistence across requests | API / Backend (`DatabaseSessionHandler`) | Database / Storage (MySQL `sessions` table) | PHP session save handler is a server-side concern; client only carries the cookie ID |
| Session TTL enforcement | API / Backend (`read()` WHERE clause) | Database / Storage (`expires_at` index) | Lazy expiry at read-time is the primary guard; index enables gc() cleanup |
| Cookie security flags | API / Backend (`Auth::start()`) | — | `session_set_cookie_params()` is server-side; no frontend changes |
| CSRF token persistence | API / Backend (`$_SESSION`) | — | Already in `$_SESSION`; transparent once store is MySQL-backed |
| Table creation | Operator CLI (`bin/migrate.php`) | — | Same migration pattern as Phase 2; no runtime DDL |

---

## Standard Stack

### Core (no new packages)

| Component | Purpose | Notes |
|-----------|---------|-------|
| `\SessionHandlerInterface` (PHP 8.3 built-in) | Contract for the custom handler | 6 methods: open, close, read, write, destroy, gc |
| `session_set_save_handler($h, true)` (PHP built-in) | Registers handler + shutdown write | `true` = calls `session_register_shutdown()` internally |
| `Database::connection()` | PDO singleton reused inside handler | No new connection logic; already TLS-capable from Phase 2 |
| MySQL `INSERT ... ON DUPLICATE KEY UPDATE` | UPSERT in `write()` | Row-alias syntax (MySQL 8.0.19+) avoids deprecated `VALUES()` function |

**No new Composer packages.** This phase is pure PHP + SQL.

### Package Legitimacy Audit

> No external packages are installed in this phase. Section not applicable.

---

## Architecture Patterns

### System Architecture Diagram

```
Per-request php-cgi process (vercel-php@0.7.4)
  │
  ├─ Auth::start()
  │    ├─ new DatabaseSessionHandler()
  │    ├─ session_set_save_handler($h, true)   ← registers shutdown fn
  │    ├─ session_set_cookie_params([lifetime=30d, ...])
  │    └─ session_start()
  │         ├─ open()   → return true
  │         └─ read()   → SELECT data WHERE id=? AND expires_at >= UNIX_TIMESTAMP()
  │                         └─ returns ''  (fresh) or serialized $_SESSION (existing)
  │
  ├─ [Request handles: Auth, Csrf, Controllers read/write $_SESSION]
  │
  └─ PHP shutdown sequence
       ├─ session_write_close()  (registered via $register_shutdown=true)
       │    ├─ write()  → INSERT ... ON DUPLICATE KEY UPDATE
       │    └─ close()  → return true
       └─ static/object destructors (PDO still alive here — fires AFTER above)


Auth::login()  ─── session_regenerate_id(true) ──►
    destroy(old_id) → close() → open() → read(new_id='') → [shutdown] write(new_id, data)
    $_SESSION preserved in memory throughout → CSRF token survives
```

### Recommended Project Structure Changes

```
src/Core/
├── DatabaseSessionHandler.php   # NEW — implements \SessionHandlerInterface
├── Auth.php                     # MODIFY — 2 lines in start()
sql/
└── schema.sql                   # MODIFY — add sessions table
src/Core/
└── Schema.php                   # MODIFY — add sessions CREATE TABLE IF NOT EXISTS
api/
└── php.ini                      # MODIFY — add session.gc_probability = 0
```

### Pattern 1: session_set_save_handler Ordering

**What:** Must call `session_set_save_handler($handler, true)` BEFORE `session_start()`. The second argument `true` internally calls `session_register_shutdown()` which registers `session_write_close()` as a PHP shutdown function.

**Why the ordering matters:** `session_start()` immediately calls `open()` then `read()` on the handler. If the handler is not registered yet, PHP falls back to the default file-based handler for that request.

**Why `true` is required (not optional):** PHP's documentation warns: "write and close handlers are called AFTER object destruction." This applies to the *old* callback-based API. With `$register_shutdown=true`, `session_write_close()` fires as a registered shutdown function — which runs BEFORE object destructors. This is the safe path. [CITED: php.net/manual/en/function.session-set-save-handler.php]

**On vercel-php@0.7.4:** The runtime uses php-cgi (confirmed from `src/launchers/cgi.ts` in the vercel-php source: spawns a php-cgi child process, awaits its `'close'` event). This means each request is a normal PHP process that terminates cleanly — shutdown functions ARE called before the process exits. No special handling needed. [VERIFIED: github.com/juicyfx/vercel-php source]

```php
// Source: Auth::start() in src/Core/Auth.php — modified for Phase 3
public static function start(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    // D-02: Register handler BEFORE session_start().
    $handler = new DatabaseSessionHandler();
    session_set_save_handler($handler, true); // true = register shutdown fn

    $secure   = Env::get('SESSION_SECURE', '0') === '1';
    $lifetime = 30 * 86400; // D-05: 30-day persistent cookie

    session_set_cookie_params([
        'lifetime' => $lifetime,
        'path'     => '/',
        'domain'   => '',
        'secure'   => $secure,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_name('RESELLTRACK_SESS');
    session_start();
}
```

### Pattern 2: session_regenerate_id(true) Lifecycle

**What:** When `Auth::login()` calls `session_regenerate_id(true)`, PHP drives the handler through this exact sequence: [CITED: gist.github.com/franksacco/d6e943c41189f8ee306c182bf8f07654]

1. `destroy(old_id)` — old sessions row deleted immediately
2. `close()` — handler cleanup (no-op in our impl)
3. `open()` — reinitialise (no-op in our impl)
4. `create_sid()` — new session ID generated by PHP
5. `read(new_id)` — returns `''` (no row exists for new ID yet)
6. [At shutdown] `write(new_id, data)` — stores the session under the new ID

`$_SESSION` superglobal is preserved in memory throughout steps 1–5 — the CSRF token at `$_SESSION['_csrf_token']` is untouched. It gets written to MySQL under `new_id` at step 6. No orphan rows: the old ID was deleted at step 1 before any new write. **D-07 and D-08 are satisfied with zero changes to `Csrf.php` or `Auth::login()`.** [CITED: php.net/manual/en/function.session-regenerate-id.php]

### Pattern 3: UPSERT SQL (write method)

**What:** `write()` runs on every request. It must create a new row or overwrite an existing one atomically.

**Row-alias syntax (MySQL 8.0.19+):** Avoids the deprecated `VALUES()` function while remaining compatible with Aiven MySQL 8. [CITED: dev.mysql.com/doc/refman/8.0/en/insert-on-duplicate.html]

```sql
INSERT INTO sessions (id, data, expires_at)
VALUES (?, ?, ?) AS new_row
ON DUPLICATE KEY UPDATE
    data       = new_row.data,
    expires_at = new_row.expires_at
```

`expires_at` = `time() + 30 * 86400` (calculated in PHP, passed as the third `?` bind).

### Anti-Patterns to Avoid

- **Calling `session_set_save_handler()` after `session_start()`:** Handler registration silently fails; PHP keeps the file-based handler for that request. Always wire before `session_start()`.
- **Omitting `$register_shutdown=true`:** Without it, `write()` is called during PHP's internal session shutdown which fires AFTER static property destruction. `Database::$instance` may be gone. Always pass `true`.
- **Returning `false` from `read()` on miss:** PHP's internal session handling distinguishes between `false` (error) and `''` (empty/new session). Return `''` for a not-found or expired row — PHP creates a fresh session silently.
- **Using `VALUES()` in the UPSERT:** Deprecated in MySQL 8.0.20 and subject to removal. Use row-alias syntax.
- **Binding MEDIUMBLOB data as `PDO::PARAM_LOB`:** With `pdo_mysql` and `ATTR_EMULATE_PREPARES=false`, `PDO::PARAM_LOB` maps to a stream interface. `PDO::PARAM_STR` correctly transmits raw bytes in the MySQL binary protocol without charset conversion, which is what we want for BLOB columns.
- **Registering `session.save_handler = user` in `api/php.ini`:** Not needed; `session_set_save_handler()` sets this at runtime. A php.ini setting would be ignored anyway once `session_set_save_handler()` is called.

---

## Sessions Table DDL

```sql
-- Add to sql/schema.sql (after login_attempts table, before sales table)
CREATE TABLE IF NOT EXISTS sessions (
    id         VARCHAR(128) NOT NULL,
    data       MEDIUMBLOB   NOT NULL,
    expires_at INT UNSIGNED NOT NULL,
    PRIMARY KEY (id),
    KEY idx_sessions_expires (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

**Notes:**
- `id VARCHAR(128)`: PHP's default session ID is 26 hex chars; 128 gives headroom for any custom SID generator.
- `data MEDIUMBLOB`: stores PHP's serialized `$_SESSION` payload. For this app (user_id int + user_name string + _csrf_token 64-char hex + optional flash/old-input), a few KB at most. MEDIUMBLOB (16 MB cap) is massively sufficient and matches D-03. BLOB columns ignore the table's charset/collation — they are binary by definition.
- `expires_at INT UNSIGNED`: Unix timestamp, indexed for gc() DELETE performance.
- `DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci`: Table-level charset setting affects future TEXT columns only; BLOB column is unaffected. Keeps DDL consistent with the rest of the schema.

**Schema::ensure() addition:**
```php
// Add to Schema::ensure() in src/Core/Schema.php
$db->exec(
    "CREATE TABLE IF NOT EXISTS sessions (
        id         VARCHAR(128) NOT NULL,
        data       MEDIUMBLOB   NOT NULL,
        expires_at INT UNSIGNED NOT NULL,
        PRIMARY KEY (id),
        KEY idx_sessions_expires (expires_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
);
```

---

## Don't Hand-Roll

| Problem | Don't Build | Use Instead | Why |
|---------|-------------|-------------|-----|
| Session serialization | Custom serialize/deserialize | PHP's built-in session serialization (default `php` format) | Transparent; `$_SESSION` is serialized/unserialized by PHP automatically before/after `read()`/`write()` calls |
| Expiry-based cleanup | Custom TTL logic in read() string parsing | Unix timestamp in `expires_at` column + `WHERE expires_at >= UNIX_TIMESTAMP()` | Database handles timestamp math natively; no PHP parsing needed |
| Concurrent write safety | Application-level locking | `INSERT ... ON DUPLICATE KEY UPDATE` (atomic at the MySQL level) | InnoDB row-level locking on PRIMARY KEY makes the UPSERT atomic |
| GC scheduling | Custom PHP loop with sleep | `session.gc_probability = 0` + explicit `session_gc()` call | Let the operator or a future maintenance endpoint trigger cleanup; don't add per-request overhead |

**Key insight:** `SessionHandlerInterface` delegates all serialization to PHP. The handler only receives and stores opaque strings — zero knowledge of the session structure needed.

---

## Complete DatabaseSessionHandler Design

```php
<?php
// Source: PHP docs SessionHandlerInterface + project conventions [CITED: php.net/manual/en/class.sessionhandlerinterface.php]
declare(strict_types=1);

namespace App\Core;

use PDO;

/**
 * MySQL-backed PHP session handler.
 * Uses the Database::connection() singleton (TLS PDO from Phase 2).
 * Called by Auth::start() via session_set_save_handler($this, true).
 *
 * Table: sessions(id VARCHAR(128) PK, data MEDIUMBLOB, expires_at INT UNSIGNED)
 * TTL: 30 days (aligned with cookie lifetime D-05).
 */
final class DatabaseSessionHandler implements \SessionHandlerInterface
{
    private const TTL = 30 * 86400; // 30 days in seconds

    /**
     * Called by session_start(). PDO is managed by Database::connection().
     */
    public function open(string $path, string $name): bool
    {
        return true;
    }

    /**
     * Called after write() at shutdown. PDO connection persists for request life.
     */
    public function close(): bool
    {
        return true;
    }

    /**
     * Lazy expiry: expired rows return '' (PHP treats as a fresh session).
     * Returns '' (not false) on miss — false signals an error to PHP.
     */
    public function read(string $id): string|false
    {
        $stmt = Database::connection()->prepare(
            'SELECT data FROM sessions WHERE id = ? AND expires_at >= UNIX_TIMESTAMP()'
        );
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row !== false ? (string) $row['data'] : '';
    }

    /**
     * UPSERT on every request (D-06). Row-alias syntax (MySQL 8.0.19+).
     * PDO::PARAM_STR is binary-safe for MEDIUMBLOB with ATTR_EMULATE_PREPARES=false.
     */
    public function write(string $id, string $data): bool
    {
        $expiresAt = time() + self::TTL;
        $stmt = Database::connection()->prepare(
            'INSERT INTO sessions (id, data, expires_at)
             VALUES (?, ?, ?) AS new_row
             ON DUPLICATE KEY UPDATE
                 data       = new_row.data,
                 expires_at = new_row.expires_at'
        );
        $stmt->execute([$id, $data, $expiresAt]);
        return true;
    }

    /**
     * Called by session_regenerate_id(true) for old ID, and by Auth::logout().
     */
    public function destroy(string $id): bool
    {
        $stmt = Database::connection()->prepare(
            'DELETE FROM sessions WHERE id = ?'
        );
        $stmt->execute([$id]);
        return true;
    }

    /**
     * Bulk cleanup. Only called explicitly (session.gc_probability = 0 in api/php.ini).
     * Ignores $max_lifetime in favour of the stored expires_at timestamp (D-04).
     */
    public function gc(int $max_lifetime): int|false
    {
        $stmt = Database::connection()->prepare(
            'DELETE FROM sessions WHERE expires_at < UNIX_TIMESTAMP()'
        );
        $stmt->execute();
        return $stmt->rowCount();
    }
}
```

---

## api/php.ini Change

Add one line to `api/php.ini`:

```ini
; Disable PHP's probabilistic GC (default 1/100 per request).
; DatabaseSessionHandler uses lazy read-time expiry (expires_at column).
; Run session_gc() explicitly from a maintenance endpoint if bulk cleanup is needed.
session.gc_probability = 0
```

**Why:** With the default `session.gc_probability = 1` and `session.gc_divisor = 100`, PHP would call `gc()` on roughly 1% of requests — a bulk `DELETE FROM sessions WHERE expires_at < UNIX_TIMESTAMP()` each time. This is unnecessary overhead because expired sessions are already invisible to `read()` (the `WHERE expires_at >= UNIX_TIMESTAMP()` guard). Setting to 0 prevents the random cleanup while the lazy expiry keeps the behaviour correct from a user perspective.

**Existing settings:** `variables_order = EGPCS`, `memory_limit = 256M`, `disable_functions = ...` are all unaffected. Custom session save handlers are part of PHP's core session module (`ext/session`) and require no extra php.ini directives to activate.

---

## Research Questions — All Resolved

### RQ1: Ordering of session_set_save_handler vs session_start; explicit register_shutdown_function needed?

**RESOLVED — HIGH confidence**

`session_set_save_handler($h, true)` MUST precede `session_start()`. The `true` argument calls `session_register_shutdown()` which registers `session_write_close()` as a PHP shutdown function. This is sufficient — no explicit `register_shutdown_function('session_write_close')` is needed.

**vercel-php request model (confirmed):** `src/launchers/cgi.ts` in the juicyfx/vercel-php repository spawns a `php-cgi` child process for every request and awaits its `'close'` event before resolving the Lambda response. The php-cgi process terminates normally after each request, which triggers PHP's standard shutdown sequence: (1) registered shutdown functions (including `session_write_close`), (2) object/static destructors. `Database::$instance` is alive at step 1. No custom handling needed. [VERIFIED: github.com/juicyfx/vercel-php src/launchers/cgi.ts]

### RQ2: session_regenerate_id(true) with custom handler — orphan rows, CSRF survival

**RESOLVED — HIGH confidence**

Confirmed lifecycle from PHP session internals: `session_regenerate_id(true)` calls `destroy(old_id)` synchronously, then on next `session_write_close` calls `write(new_id, data)`. The old row is deleted before the new row is written — no orphan rows possible. [CITED: gist.github.com/franksacco/d6e943c41189f8ee306c182bf8f07654]

`$_SESSION` superglobal is preserved in memory during regeneration. The CSRF token at `$_SESSION['_csrf_token']` is untouched and written under the new ID at shutdown. `Csrf.php` requires no changes (D-07 confirmed).

### RQ3: session.gc_probability=0 in api/php.ini

**RESOLVED — HIGH confidence**

`session.gc_probability = 0` completely disables PHP's automatic per-request GC invocations. The `gc()` method on the handler is never called automatically. Lazy `expires_at` check in `read()` handles session expiry from the user's perspective. `session_gc()` can be called explicitly for bulk cleanup. Recommended to add to `api/php.ini`. [CITED: php.net/manual/en/session.configuration.php]

### RQ4: MEDIUMBLOB + PDO binary safety; PDO param type

**RESOLVED — HIGH confidence**

PHP's default session serialization format (`php`) is a text-based length-prefixed format. Its output is always valid byte sequences that are safe to store in a BLOB.

`Database::connection()` already sets `PDO::ATTR_EMULATE_PREPARES => false`, which activates the MySQL binary protocol for prepared statements. In binary protocol mode, string parameters bound with `PDO::PARAM_STR` are transmitted as raw bytes without any charset conversion — the DSN's `charset=utf8mb4` only affects TEXT/VARCHAR columns, not BLOB columns. **Use `PDO::PARAM_STR` (the default) for the `data` binding.** [CITED: php.net/manual/en/pdo.lobs.php]

Session payload for this app: user_id (int), user_name (string ~30 chars), _csrf_token (64-char hex string), optional flash/old-input (a few hundred bytes). Total: well under 10 KB. MEDIUMBLOB cap (16 MB) is not a concern.

### RQ5: Database::connection() singleton alive at session_write_close shutdown

**RESOLVED — HIGH confidence**

PHP's shutdown sequence runs registered shutdown functions (including `session_write_close`) BEFORE destructing static properties and objects. `Database::$instance` (a static PDO property) is only destroyed after shutdown functions complete. In the php-cgi model, the PDO connection is established at the start of the request (seconds before shutdown) and remains alive until process termination. No "MySQL server has gone away" risk at connection ages under 30 seconds. [CITED: php.net/manual/en/function.session-set-save-handler.php (object destruction warning)]

### RQ6: api/php.ini + custom session save handlers on vercel-php@0.7.4

**RESOLVED — HIGH confidence**

The existing `api/php.ini` settings (`variables_order`, `memory_limit`, `disable_functions`) have no interaction with the custom session save handler. PHP's session module (`ext/session`) is built into PHP core and always available. `session_set_save_handler()` sets the save handler to "user" at runtime — no `session.save_handler = user` needed in php.ini. The only recommended addition is `session.gc_probability = 0`.

---

## Common Pitfalls

### Pitfall 1: Handler Registered After session_start()
**What goes wrong:** PHP has already opened the file-based handler during `session_start()`; the `DatabaseSessionHandler` is silently ignored for that request. Sessions still use files.
**Why it happens:** Wiring in the wrong order — `session_start()` before `session_set_save_handler()`.
**How to avoid:** The early-return guard in `Auth::start()` (`if PHP_SESSION_ACTIVE return`) must fire before both calls, and both calls must be inside the non-active branch in the order: handler registration → cookie params → name → start.
**Warning signs:** Sessions work locally (files persist) but are lost across Vercel Lambda invocations.

### Pitfall 2: read() Returning false Instead of ''
**What goes wrong:** PHP interprets `false` from `read()` as a handler error, not a fresh session. Behaviour is undefined; may trigger warnings or unexpected session state.
**Why it happens:** Treating a not-found row the same as a PDO fetch failure.
**How to avoid:** When `$stmt->fetch()` returns `false` (no row found), return `''` (empty string), not `false`. Only return `false` if a genuine database exception occurs (which our code converts to a PDO exception and propagates).
**Warning signs:** Session data appears to reset on every request even when the sessions table has rows.

### Pitfall 3: Missing expires_at Index
**What goes wrong:** `gc()` runs a full table scan; `read()`'s `WHERE expires_at >= UNIX_TIMESTAMP()` can't use an index for the range predicate (though the PRIMARY KEY lookup on `id` is dominant).
**Why it happens:** Forgetting to add `KEY idx_sessions_expires (expires_at)` to the DDL.
**How to avoid:** Include it in both `sql/schema.sql` and `Schema::ensure()` DDL.
**Warning signs:** Slow `gc()` runs; `EXPLAIN` shows full scan on sessions.

### Pitfall 4: VALUES() Deprecation Warnings in MySQL 8.0.20+
**What goes wrong:** `INSERT ... ON DUPLICATE KEY UPDATE data = VALUES(data)` emits deprecation warnings in MySQL 8.0.20+; will break on a future MySQL version.
**Why it happens:** Using the old syntax.
**How to avoid:** Use row-alias syntax: `VALUES (?, ?, ?) AS new_row ON DUPLICATE KEY UPDATE data = new_row.data, expires_at = new_row.expires_at`.
**Warning signs:** MySQL general log shows deprecation warnings; MySQL 9+ may throw an error.

### Pitfall 5: session_write_close Called Without Active Session
**What goes wrong:** If `Auth::start()` is called multiple times (guarded by `PHP_SESSION_ACTIVE` check) and then `session_destroy()` is called without a subsequent `session_start()`, the shutdown-registered `session_write_close()` may find no active session.
**Why it happens:** `Auth::logout()` calls `session_destroy()` which ends the session; PHP's shutdown function still fires.
**How to avoid:** This is not a real issue — PHP's `session_write_close()` is a no-op if no session is active. The existing `Auth::logout()` implementation is correct.

---

## Code Examples

### Complete Auth::start() (modified)
```php
// Source: src/Core/Auth.php — Phase 3 modification
public static function start(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    // D-02: Register MySQL-backed handler before session_start().
    $handler = new DatabaseSessionHandler();
    session_set_save_handler($handler, true); // true = register session_write_close as shutdown fn

    $secure   = Env::get('SESSION_SECURE', '0') === '1';
    $lifetime = 30 * 86400; // D-05: 30-day persistent cookie

    session_set_cookie_params([
        'lifetime' => $lifetime, // changed from 0
        'path'     => '/',
        'domain'   => '',
        'secure'   => $secure,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_name('RESELLTRACK_SESS');
    session_start();
}
```

### read() — Lazy Expiry
```php
// Source: SessionHandlerInterface contract + PHP docs [CITED: php.net/manual/en/class.sessionhandlerinterface.php]
public function read(string $id): string|false
{
    $stmt = Database::connection()->prepare(
        'SELECT data FROM sessions WHERE id = ? AND expires_at >= UNIX_TIMESTAMP()'
    );
    $stmt->execute([$id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row !== false ? (string) $row['data'] : '';
}
```

### write() — UPSERT with Row Alias
```php
// Source: MySQL 8.0 docs — INSERT ON DUPLICATE KEY UPDATE row alias syntax
// [CITED: dev.mysql.com/doc/refman/8.0/en/insert-on-duplicate.html]
public function write(string $id, string $data): bool
{
    $expiresAt = time() + self::TTL;
    $stmt = Database::connection()->prepare(
        'INSERT INTO sessions (id, data, expires_at)
         VALUES (?, ?, ?) AS new_row
         ON DUPLICATE KEY UPDATE
             data       = new_row.data,
             expires_at = new_row.expires_at'
    );
    $stmt->execute([$id, $data, $expiresAt]);
    return true;
}
```

---

## State of the Art

| Old Approach | Current Approach | When Changed | Impact |
|--------------|------------------|--------------|--------|
| File-based sessions (default) | MySQL-backed `SessionHandlerInterface` | Phase 3 | Sessions survive Lambda cold starts |
| Session cookie `lifetime = 0` (session cookie) | `lifetime = 30 * 86400` (persistent cookie) | Phase 3 | User stays logged in across browser restarts |
| `VALUES()` in UPSERT | Row-alias syntax in UPSERT | MySQL 8.0.19 / 8.0.20 deprecation | Forward-compatible SQL |
| PHP automatic GC (1% per request) | `gc_probability = 0` + lazy expiry | Phase 3 | No per-request DELETE overhead |

**Deprecated/outdated:**
- `VALUES(column_name)` in `ON DUPLICATE KEY UPDATE`: deprecated MySQL 8.0.20, avoid in new code.
- Callback-based `session_set_save_handler(open, close, read, write, destroy, gc)`: superseded by the OOP `SessionHandlerInterface` since PHP 5.4.

---

## Environment Availability

| Dependency | Required By | Available | Version | Fallback |
|------------|------------|-----------|---------|----------|
| `ext/session` (PHP core) | `SessionHandlerInterface` | ✓ | PHP 8.3 built-in | N/A — cannot be missing |
| `pdo_mysql` | `Database::connection()` | ✓ | Confirmed in vercel-php@0.7.4 manifest | N/A |
| Aiven MySQL 8 `sessions` table | `DatabaseSessionHandler` | ✗ (created by migrate.php) | — | `bin/migrate.php` re-run (D-09) |

**Missing dependencies with no fallback:**
- `sessions` table does not exist until `bin/migrate.php` is re-run against Aiven (operator step D-09). Attempting a request before migration will cause a PDO exception on `write()`.

**Missing dependencies with fallback:**
- None.

---

## Validation Architecture

> `workflow.nyquist_validation = true` in `.planning/config.json` — section required.

### Test Framework

| Property | Value |
|----------|-------|
| Framework | PHPUnit 11.x |
| Config file | `phpunit.xml` (project root) |
| Quick run | `composer test` |
| Full suite | `vendor/bin/phpunit` |

Coverage source is `src/Services/` only (per `phpunit.xml`). `src/Core/` classes are not in the coverage source; this phase adds no `src/Services/` changes. Per REQUIREMENTS.md (Out of Scope): "extended test coverage hors périmètre."

### Phase Requirements → Test Map

| Req ID | Behavior | Test Type | Automated Command | File Exists? |
|--------|----------|-----------|-------------------|-------------|
| SESS-01 | Session persists across separate requests (login then navigate) | Manual E2E smoke test | — | N/A (no live Aiven in CI) |
| SESS-02 | Sessions table exists and rows are written/read correctly | Manual verification after `bin/migrate.php` | `SELECT * FROM sessions LIMIT 5;` on Aiven | N/A |
| SESS-02 | `DatabaseSessionHandler::read()` returns '' for expired/missing id | Unit test | `vendor/bin/phpunit --filter DatabaseSessionHandlerTest` | ❌ Wave 0 gap |
| SESS-03 | Cookie has `Secure`, `HttpOnly`, `SameSite=Lax`, `lifetime=30d` | Manual — browser DevTools or curl -I | `curl -sI https://<vercel-url>/login \| grep -i set-cookie` | N/A |
| SESS-04 | POST with valid CSRF succeeds; POST with missing CSRF returns 419 | Manual E2E | `curl -b <session_cookie> -d '_csrf=invalid' https://<vercel-url>/login` | N/A |

### Wave 0 Gaps

- [ ] `tests/Core/DatabaseSessionHandlerTest.php` — unit tests for `read()`, `write()`, `destroy()`, `gc()` (requires a test database or mock PDO; out of scope per REQUIREMENTS.md, but noted for completeness)

*(The existing test suite (`ProfitCalculatorTest.php`) covers `src/Services/` business logic only. No DB integration tests exist. This is explicitly out of scope per REQUIREMENTS.md.)*

**Practical verification plan (no CI secrets needed):**
1. `php bin/migrate.php` → verify exit 0 and "Migration complete."
2. `SELECT * FROM sessions;` on Aiven before and after login → confirm row appears
3. Browser: login → navigate to /products → verify still authenticated (SESS-01)
4. Browser DevTools → Application → Cookies → verify `RESELLTRACK_SESS` has HttpOnly, Secure, SameSite=Lax, expiry ~30 days (SESS-03)
5. Submit a form → verify no 419 (SESS-04)
6. Login → verify you remain logged in (session_regenerate_id survived, CSRF token intact)

---

## Security Domain

> `security_enforcement` key absent from config.json — treating as enabled.

### Applicable ASVS Categories

| ASVS Category | Applies | Standard Control |
|---------------|---------|-----------------|
| V2 Authentication | Yes | `session_regenerate_id(true)` on login (session fixation prevention — already implemented in `Auth::login()`) |
| V3 Session Management | Yes | `SessionHandlerInterface` + MySQL store; `Secure`+`HttpOnly`+`SameSite=Lax` cookie flags; 30-day TTL |
| V4 Access Control | No | No changes to auth guard |
| V5 Input Validation | No | Session ID is generated by PHP (not user-supplied); no new user input |
| V6 Cryptography | No | Session ID randomness handled by PHP's CSPRNG |

### Known Threat Patterns

| Pattern | STRIDE | Standard Mitigation |
|---------|--------|---------------------|
| Session fixation | Elevation of Privilege | `session_regenerate_id(true)` on login (already in `Auth::login()`) — confirmed to call `destroy(old_id)` with custom handler |
| Session hijacking | Spoofing | `Secure` + `HttpOnly` + `SameSite=Lax` cookie flags (D-05); 30-day TTL |
| Orphan sessions (DB bloat) | Denial of Service (storage) | `gc()` + `expires_at` index; lazy read-time expiry prevents serving expired sessions |
| Direct access to sessions table | Information Disclosure | MySQL user has table-level access only; no session data exposed via application layer |
| CSRF on new store | Tampering | `$_SESSION['_csrf_token']` persists transparently; `Csrf::validate()` unchanged (D-07) |

---

## Assumptions Log

| # | Claim | Section | Risk if Wrong |
|---|-------|---------|---------------|
| A1 | Aiven MySQL is 8.0.19+ and supports row-alias syntax in `INSERT ... ON DUPLICATE KEY UPDATE` | write() SQL | Low — Aiven MySQL 8 is current MySQL 8 GA; 8.0.19 is a very old release. Fallback: use `VALUES(data)` deprecated syntax (still works in MySQL 8) |
| A2 | php-cgi process on vercel-php@0.7.4 runs PHP shutdown functions before terminating | RQ1, RQ5 | Low — confirmed from cgi.ts source (awaits 'close' event = normal process exit = shutdown fires). If wrong: add explicit `session_write_close()` call at end of `public/index.php` as belt-and-suspenders |
| A3 | `PDO::ATTR_EMULATE_PREPARES=false` makes `PARAM_STR` binary-safe for MEDIUMBLOB | RQ4 | Low — standard MySQL binary protocol behavior. If wrong: use `PDO::PARAM_LOB` instead; functionally equivalent for pdo_mysql |

**If this table is empty:** Not empty. Three low-risk assumptions noted.

---

## Open Questions (ALL RESOLVED)

1. **Does vercel-php call PHP shutdown functions?**
   RESOLVED: Yes. `cgi.ts` awaits process `'close'` event — php-cgi exits normally, triggering shutdown functions including `session_write_close`. [VERIFIED: juicyfx/vercel-php source]

2. **Does session_regenerate_id(true) leave orphan rows?**
   RESOLVED: No. `destroy(old_id)` is called synchronously by PHP before the new session ID is assigned. [CITED: PHP session lifecycle documentation]

3. **Is an explicit `register_shutdown_function('session_write_close')` needed?**
   RESOLVED: No. Passing `true` to `session_set_save_handler()` calls `session_register_shutdown()` internally, which does the same thing. Adding an explicit call is redundant but harmless.

4. **Should PARAM_LOB be used instead of PARAM_STR for the data binding?**
   RESOLVED: Use `PARAM_STR`. For `pdo_mysql` with `ATTR_EMULATE_PREPARES=false`, both are equivalent for BLOB columns. `PARAM_STR` is simpler and consistent with the rest of the codebase.

5. **Does session.gc_probability=0 need to be set?**
   RESOLVED: Recommended yes. Without it, PHP calls `gc()` on 1% of requests with the default. `gc()` runs a full-table DELETE — unnecessary overhead given lazy read-time expiry handles correctness. Add to `api/php.ini`.

---

## Sources

### Primary (HIGH confidence)
- `php.net/manual/en/class.sessionhandlerinterface.php` — interface contract, method signatures, return types
- `php.net/manual/en/function.session-set-save-handler.php` — `$register_shutdown` parameter behavior, object destruction warning, ordering requirement
- `php.net/manual/en/function.session-regenerate-id.php` — `destroy()` called with `$delete_old_session=true`
- `php.net/manual/en/session.configuration.php` — `session.gc_probability=0` behavior
- `php.net/manual/en/function.session-gc.php` — explicit `session_gc()` for custom handlers
- `dev.mysql.com/doc/refman/8.0/en/insert-on-duplicate.html` — row-alias syntax (MySQL 8.0.19+), VALUES() deprecation (8.0.20)
- `php.net/manual/en/pdo.lobs.php` — PARAM_LOB vs PARAM_STR for BLOB columns
- `github.com/juicyfx/vercel-php src/launchers/cgi.ts` — php-cgi model, process awaits 'close' before resolving [VERIFIED: GitHub API read]
- Codebase read: `src/Core/Auth.php`, `src/Core/Csrf.php`, `src/Core/Database.php`, `src/Core/Schema.php`, `sql/schema.sql`, `api/php.ini`, `api/index.php`, `vercel.json`

### Secondary (MEDIUM confidence)
- `gist.github.com/franksacco/d6e943c41189f8ee306c182bf8f07654` — PHP session handler lifecycle diagram, sequence of calls during `session_regenerate_id(true)`

### Tertiary (LOW confidence)
- None. All claims verified through primary or secondary sources.

---

## Metadata

**Confidence breakdown:**
- Standard stack: HIGH — no new packages, all components are PHP built-ins or already-present PDO
- Handler method contracts: HIGH — verified against php.net official docs
- vercel-php shutdown behavior: HIGH — confirmed from cgi.ts source code
- session_regenerate_id lifecycle: HIGH — confirmed from lifecycle gist + PHP docs
- MySQL row-alias syntax: HIGH — confirmed from MySQL 8.0 official docs
- MEDIUMBLOB binary safety: HIGH — PDO binary protocol + BLOB column charset-independence

**Research date:** 2026-06-12
**Valid until:** 2026-09-12 (PHP session interface is stable; MySQL 8 syntax is stable; vercel-php@0.7.4 is pinned in vercel.json)
