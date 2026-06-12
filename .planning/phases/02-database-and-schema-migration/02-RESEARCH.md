# Phase 2: Database and Schema Migration — Research

**Researched:** 2026-06-12
**Domain:** PHP PDO MySQL TLS (Aiven), schema migration CLI script, vercel-php Lambda filesystem
**Confidence:** HIGH (PDO constants — PHP docs + vercel-php manifest), HIGH (schema diff — direct code read), MEDIUM (Aiven enforcement — official Aiven docs), MEDIUM (Lambda cert path — inferred from working Phase 1 pattern)

---

<user_constraints>
## User Constraints (from CONTEXT.md)

### Locked Decisions
- **D-01:** Keep `src/Core/Schema.php` — do NOT delete or fold its DDL into `sql/schema.sql`.
- **D-02:** `bin/migrate.php` connects to Aiven and invokes the schema setup exactly once — apply `sql/schema.sql`, then call `Schema::ensure()`. Safe to re-run (idempotent).
- **D-03:** Remove `Schema::ensure(self::$instance)` call from `Database::connection()` (line 41). Runtime connections do connection only — no DDL.
- **D-04:** Commit Aiven CA cert to `certs/aiven-ca.pem`. Point `PDO::MYSQL_ATTR_SSL_CA` at that path.
- **D-05:** `MYSQL_ATTR_SSL_VERIFY_SERVER_CERT = true` — do NOT disable verification.
- **D-06:** Confirm `certs/` is NOT excluded by `.vercelignore` so the cert ships in the Lambda bundle.
- **D-07:** `bin/migrate.php` runs from the operator's local machine — Vercel serverless has no shell.
- **D-08:** Production gets schema only — NO `sql/seed.sql` loaded into Aiven.
- **D-09:** Aiven credentials become Vercel env vars (dashboard step) and also go in operator's local `.env`.
- **D-10:** Plain TLS connect — no timeout tuning, no retry/backoff. Keep existing friendly 500.
- **D-11:** Accept Aiven free-tier connection ceiling as unknown risk. Do not engineer around it now.

### Claude's Discretion
- Exact `bin/migrate.php` structure (bootstrap, output, exit codes).
- Precise ordering of `sql/schema.sql` vs `Schema::ensure()`.
- How the script reuses app classes vs standalone PDO.
- `.env.example` additions for the new TLS/CA variable — naming consistent with `DB_*` convention.

### Deferred Ideas (OUT OF SCOPE)
- Connection pooling (ProxySQL).
- Health endpoint `/health`.
- Graceful 503 page on DB failure.
- Preview-deploy DB isolation.
</user_constraints>

---

<phase_requirements>
## Phase Requirements

| ID | Description | Research Support |
|----|-------------|------------------|
| DB-01 | App connects to Aiven MySQL 8 over TLS (CA cert) from Vercel Lambda functions | PDO SSL constants verified; vercel-php@0.7.4 includes `pdo_mysql` + `openssl`; cert path derivable from `__FILE__`; `certs/` not in `.vercelignore` |
| DB-02 | Full schema applied via `bin/migrate.php` one-shot, outside request path | schema.sql + Schema::ensure() order confirmed; SQL splitting approach for skipping CREATE DATABASE/USE; autoloader bootstrap pattern documented |
| DB-03 | `Database::connection()` no longer runs DDL at runtime — `Schema::ensure()` removed from request path | Single call at line 41 to remove; all models unaffected (they only call `connection()`, never `ensure()` directly) |
</phase_requirements>

---

## Summary

Phase 2 has two coupled changes: (1) add TLS options to `Database::connection()` so Vercel Lambdas can reach Aiven MySQL over encrypted connections, and (2) extract the schema-setup DDL into a standalone `bin/migrate.php` that an operator runs once against the live database.

**PDO SSL:** The correct approach is `PDO::MYSQL_ATTR_SSL_CA` pointing to the committed CA cert path, plus `PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT = true`. The `sslmode=verify-ca` DSN syntax shown in some Aiven docs is PostgreSQL-specific — it is **not valid** for `pdo_mysql`. Both `pdo_mysql` and `openssl` are confirmed in the vercel-php@0.7.4 extension manifest, so SSL connections from the Lambda are fully supported. The cert path must be absolute; it is best derived from `__FILE__` at runtime so it works identically on the developer's machine and inside the Lambda container.

**Schema diff:** `sql/schema.sql` is **already complete** — it already contains every table and column that `Schema.php::ensure()` adds (orders, product_images, market_price columns, CNY enum, purchases order_id/weight_grams columns, FKs). On a fresh Aiven DB, running `schema.sql` produces the full target schema; calling `Schema::ensure()` afterwards is a sequence of no-ops. The correct `bin/migrate.php` order is: schema.sql first, then `Schema::ensure()`. Reversing the order would fail because `Schema::ensure()` creates FKs against `users` and `products` tables that must already exist.

**Aiven specifics:** TLS is enforced by Aiven (plaintext connections are rejected; `require_secure_transport=ON` per Aiven's docs). The CA cert is downloaded from the Aiven Console service Overview tab. The connection port and hostname are read from the service URI shown in the Aiven Console (port may differ from 3306). The database name is what the operator created in Aiven (default: `defaultdb`) — they must configure `DB_NAME` to match.

**Primary recommendation:** Use `PDO::MYSQL_ATTR_SSL_CA` + `PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT => true` in `$options` array passed to `new PDO()`. Derive the cert path using `Env::get('DB_SSL_CA', 'certs/aiven-ca.pem')` resolved against project root via `dirname(__FILE__, 3)`. Bootstrap `bin/migrate.php` with an inline `spl_autoload_register` (same pattern as `public/index.php`), call `Database::connection()`, run schema.sql statements with CREATE DATABASE/USE lines stripped, then call `Schema::ensure()`.

---

## Architectural Responsibility Map

| Capability | Primary Tier | Secondary Tier | Rationale |
|------------|-------------|----------------|-----------|
| TLS connection config | API / Backend (`src/Core/Database.php`) | — | PDO options are a backend connection-layer concern; no frontend involvement |
| CA cert storage | Repo (committed file `certs/`) | Vercel Lambda filesystem (bundled) | Committed cert is public info; Lambda filesystem makes it available at runtime |
| Schema DDL application | Operator CLI (`bin/migrate.php`) | — | Serverless has no shell; migrations run once from operator's machine |
| Runtime schema guard (`Schema::ensure`) | Operator CLI only after D-03 | — | Removed from request path; DB-03 is specifically about this |
| Credential injection | Vercel env vars (prod) + `.env` file (local) | `src/Core/Env.php` reads both | Existing Env pattern; no new mechanism needed |

---

## Standard Stack

### Core (no new packages needed — this phase is config + new script)

| Component | Version | Purpose | Notes |
|-----------|---------|---------|-------|
| `ext-pdo` + `pdo_mysql` | PHP 8.3 built-in | MySQL PDO driver | Confirmed in vercel-php@0.7.4 manifest |
| `openssl` extension | PHP 8.3 built-in | TLS support for PDO MySQL | Confirmed in vercel-php@0.7.4 manifest |
| `PDO::MYSQL_ATTR_SSL_CA` | PHP 7.0+ | CA cert path option | Standard PDO MySQL SSL option |
| `PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT` | PHP 7.0.18+ | Enable cert verification | Default true when SSL_CA provided; must be explicit per D-05 |

No new Composer packages. No new npm packages. Phase 2 is pure PHP + config.

### Package Legitimacy Audit

> No external packages are installed in this phase. Section not applicable.

---

## Architecture Patterns

### System Architecture Diagram

```
Operator workstation
  bin/migrate.php ──[TLS + CA cert]──► Aiven MySQL 8
      │                                  │
      ├─ spl_autoload_register            ├─ Creates all tables from schema.sql
      ├─ Env::load(.env)                  └─ Schema::ensure() → no-ops
      ├─ Database::connection()
      │    └─ new PDO(dsn, user, pass, [SSL_CA, VERIFY=true])
      ├─ exec(schema.sql statements, skipping CREATE DATABASE / USE)
      └─ Schema::ensure($pdo)

Vercel Lambda (per-request)
  Database::connection()
      └─ new PDO(dsn, user, pass, [SSL_CA, VERIFY=true])
              ▲
              └─ Schema::ensure() call REMOVED (D-03)
```

### Recommended Project Structure Changes

```
reselltrack/
├── bin/
│   └── migrate.php          # NEW — one-shot operator CLI script
├── certs/
│   └── aiven-ca.pem         # NEW — Aiven CA cert (committed; public info)
├── src/Core/
│   └── Database.php         # MODIFY — add TLS PDO options; remove Schema::ensure() call
├── .env.example             # MODIFY — add DB_SSL_CA variable
└── .vercelignore            # VERIFY — certs/ not excluded (currently safe)
```

### Pattern 1: PDO SSL Options for Aiven MySQL

**What:** Add `MYSQL_ATTR_SSL_CA` and `MYSQL_ATTR_SSL_VERIFY_SERVER_CERT` to the existing `$options` array in `Database::connection()`.

**When to use:** Whenever the application creates a PDO connection to Aiven MySQL.

**Correct approach (PDO constants — pdo_mysql specific):**
```php
// Source: https://www.php.net/manual/en/ref.pdo-mysql.php [VERIFIED: PHP docs]
// In src/Core/Database.php — inside connection() before new PDO(...)

$relCert = Env::get('DB_SSL_CA', 'certs/aiven-ca.pem');
// Resolve relative paths against project root derived from __FILE__
// dirname(__FILE__, 3): src/Core/Database.php -> src/Core -> src -> project root
$certPath = str_starts_with($relCert, '/')
    ? $relCert
    : dirname(__FILE__, 3) . '/' . $relCert;

$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
    PDO::MYSQL_ATTR_SSL_CA                 => $certPath,
    PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT => true,
];
```

**IMPORTANT — common mistake to avoid:** `sslmode=verify-ca;sslrootcert=...` in the DSN string is the **PostgreSQL** connection option syntax. It is **not** a valid PDO MySQL DSN parameter. Aiven's PHP documentation example appears to use this format, but PHP PDO MySQL requires the `MYSQL_ATTR_*` constants in the options array.

### Pattern 2: bin/migrate.php Bootstrap

**What:** Inline the same `spl_autoload_register` from `public/index.php`. Do NOT require `public/index.php` — that starts sessions, sets HTTP headers, and dispatches routes.

```php
<?php
declare(strict_types=1);

// Source: mirrors public/index.php bootstrap pattern [VERIFIED: codebase read]
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only.');
}

$root = dirname(__DIR__); // bin/ -> project root

spl_autoload_register(static function (string $class) use ($root): void {
    $prefix = 'App\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $relative = substr($class, strlen($prefix));
    $file = $root . '/src/' . str_replace('\\', '/', $relative) . '.php';
    if (is_file($file)) {
        require $file;
    }
});

require $root . '/src/helpers.php';

use App\Core\Env;
use App\Core\Database;
use App\Core\Schema;

Env::load($root . '/.env');
```

### Pattern 3: Executing schema.sql in migrate.php

**What:** Read schema.sql, skip the `CREATE DATABASE IF NOT EXISTS` and `USE` statements (Aiven pre-creates the database; operator may not have CREATE DATABASE privileges), then execute each remaining DDL statement.

```php
// Source: direct analysis of sql/schema.sql content [VERIFIED: codebase read]
$sqlFile = $root . '/sql/schema.sql';
$sql = file_get_contents($sqlFile);

// Split on semicolons; filter out empty and CREATE DATABASE / USE statements.
// Comments (-- lines) embedded in CREATE TABLE blocks are valid MySQL and execute fine.
$statements = array_filter(
    array_map('trim', explode(';', $sql)),
    static fn(string $s): bool => $s !== ''
        && !preg_match('/^\s*(CREATE\s+DATABASE\b|USE\s+\w)/i', $s)
);

foreach ($statements as $stmt) {
    try {
        $pdo->exec($stmt);
    } catch (\PDOException $e) {
        echo 'ERROR: ' . $e->getMessage() . "\n";
        echo "Statement:\n{$stmt}\n";
        exit(1);
    }
}
```

### Anti-Patterns to Avoid

- **Putting `sslmode=verify-ca` in the DSN string:** This is PostgreSQL syntax. MySQL PDO ignores unknown DSN parameters silently, so the connection would succeed but without TLS. Use `MYSQL_ATTR_SSL_CA` and `MYSQL_ATTR_SSL_VERIFY_SERVER_CERT` in the `$options` array.
- **Setting SSL options after `new PDO()`:** SSL options must be in the `$options` parameter of the constructor. `PDO::setAttribute()` cannot enable SSL after the connection exists.
- **Using `PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT => false`:** This disables cert verification, invalidating the TLS security guarantee. D-05 explicitly requires `true`.
- **Requiring `public/index.php` from migrate.php:** The front controller starts `Auth::start()` (which calls `session_start()`), sets HTTP response headers, and calls `$router->dispatch()`. Calling this from CLI produces PHP warnings for headers and runs the router with empty `$_SERVER['REQUEST_METHOD']` / `$_SERVER['REQUEST_URI']`.
- **Executing schema.sql as a single `$pdo->exec()` call with multiple statements:** PDO MySQL with `ATTR_EMULATE_PREPARES => false` does not support multi-statement queries via `exec()`. Must split and execute individually.
- **Running sql/seed.sql on Aiven:** D-08 explicitly prohibits this. seed.sql hardcodes a demo user and fake purchase data — running it in production would pollute the live database.

---

## Don't Hand-Roll

| Problem | Don't Build | Use Instead | Why |
|---------|-------------|-------------|-----|
| TLS cert verification | Custom SSL handshake code | `PDO::MYSQL_ATTR_SSL_CA` + `MYSQL_ATTR_SSL_VERIFY_SERVER_CERT` | PDO/mysqlnd handles TLS negotiation natively; openssl extension is already available |
| SQL file execution with comment stripping | Custom SQL parser | `explode(';', $sql)` with `array_filter` + `trim` | schema.sql has no semicolons inside string literals; simple split is sufficient and correct |
| DB connection bootstrap in CLI | Duplicate connection logic | Reuse `App\Core\Database::connection()` | The singleton already handles DSN construction, Env::get() reads, error handling |

**Key insight:** `pdo_mysql` + `openssl` in vercel-php@0.7.4 is the full TLS stack. No additional libraries, no custom OpenSSL wrappers.

---

## Schema.sql vs Schema.php Reconciliation

**Result: sql/schema.sql is FULLY UP TO DATE with all Schema.php additions.**

| Schema.php operation | Present in sql/schema.sql? | On fresh Aiven DB outcome |
|---------------------|---------------------------|--------------------------|
| `ensureCurrencyEnum()` — add CNY to `orders.currency` | YES: `ENUM('EUR','USD','CNY')` on line 73 | no-op (CNY already in enum) |
| `ensureCurrencyEnum()` — add CNY to `purchases.currency` | YES: `ENUM('EUR','USD','CNY')` on line 99 | no-op (CNY already in enum) |
| `CREATE TABLE IF NOT EXISTS orders` | YES: full orders table on lines 68-84 | no-op (table already exists) |
| `ALTER TABLE products ADD market_price_new` | YES: column on line 35 | SHOW COLUMNS returns row → ALTER skipped |
| `ALTER TABLE products ADD market_price_used` | YES: column on line 36 | SHOW COLUMNS returns row → ALTER skipped |
| `CREATE TABLE IF NOT EXISTS product_images` | YES: full table on lines 48-61 | no-op (table already exists) |
| `ALTER TABLE purchases ADD order_id` | YES: column on line 93 | SHOW COLUMNS returns row → ALTER skipped |
| `ALTER TABLE purchases ADD weight_grams` | YES: column on line 95 | SHOW COLUMNS returns row → ALTER skipped |
| `ALTER TABLE purchases ADD KEY idx_purchases_order` | YES: KEY on line 110 | no-op (index already exists) |
| `ALTER TABLE purchases ADD CONSTRAINT fk_purchases_order` | YES: FK on lines 115-116 | no-op (FK already exists) |

**Conclusion:** `Schema::ensure()` is a **complete no-op** on any Aiven DB freshly built from `sql/schema.sql`. Its purpose in `bin/migrate.php` is forward-compatibility insurance — if Schema.php ever drifts ahead of schema.sql again in a future phase, migrate.php re-run will still be safe.

**Mandatory order: schema.sql FIRST, Schema::ensure() SECOND.**

Reason: `Schema::ensure()` creates FKs that reference `users`, `products`, `purchases` tables. Those tables must already exist. If `Schema::ensure()` ran on an empty DB, the `CREATE TABLE orders` statement would fail on `FOREIGN KEY ... REFERENCES users (id)` because `users` doesn't exist yet.

**Re-run safety:** Idempotent end-to-end.
- schema.sql: all `CREATE TABLE IF NOT EXISTS` → safe on re-run
- Schema.php: `SHOW COLUMNS` guards on ALTER TABLE operations → safe on re-run
- `ensureCurrencyEnum()`: checks if CNY already in enum before ALTERing → safe on re-run

---

## Common Pitfalls

### Pitfall 1: sslmode DSN Parameter (PostgreSQL Syntax in MySQL Context)

**What goes wrong:** Connection appears to succeed but is NOT encrypted. Aiven may reject it with Error 3159 (ER_SECURE_TRANSPORT_REQUIRED).

**Why it happens:** The Aiven PHP docs use `sslmode=verify-ca;sslrootcert=...` in the DSN string. This is the PostgreSQL connection string format. PHP PDO MySQL (`pdo_mysql`) ignores unknown DSN parameters — the connection falls back to plaintext.

**How to avoid:** Use `PDO::MYSQL_ATTR_SSL_CA` and `PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT` in the `$options` array of `new PDO(...)`. [VERIFIED: php.net/manual/en/ref.pdo-mysql.php]

**Warning signs:** Connection succeeds locally (Docker has no TLS requirement) but fails on Aiven with "Connections using insecure transport are prohibited".

### Pitfall 2: Relative Path for CA Cert

**What goes wrong:** `PDO::MYSQL_ATTR_SSL_CA => 'certs/aiven-ca.pem'` fails with "SSL: unable to get certificate from 'certs/aiven-ca.pem'".

**Why it happens:** PDO's underlying MySQL client expects an absolute filesystem path for the CA cert. Relative paths are resolved against the current working directory at runtime, which differs between CLI runs (operator's shell cwd) and Lambda invocations (Vercel Lambda cwd is not the project root).

**How to avoid:** Always resolve relative cert paths to absolute: use `dirname(__FILE__, 3) . '/' . $relPath` in `Database.php` so the path is anchored to the file's location regardless of cwd.

**Warning signs:** `SSL: unable to get certificate from ...` in error logs, or PDOException with SSL-related message.

### Pitfall 3: CREATE DATABASE Privilege on Aiven

**What goes wrong:** `bin/migrate.php` fails immediately with "Access denied for user ... to database 'reselltrack'".

**Why it happens:** Aiven pre-creates the database on service provisioning. The Aiven MySQL user typically does NOT have global `CREATE DATABASE` privileges. The `CREATE DATABASE IF NOT EXISTS reselltrack; USE reselltrack;` lines at the top of `sql/schema.sql` will cause a PDOException.

**How to avoid:** Strip those two lines (or any line matching `/^\s*(CREATE\s+DATABASE\b|USE\s+\w)/i`) before executing schema.sql statements. The DSN already specifies the database name, so `USE` is unnecessary.

**Warning signs:** First `$pdo->exec()` call fails with access-denied error; the statement is `CREATE DATABASE IF NOT EXISTS reselltrack`.

### Pitfall 4: DB_NAME Mismatch with Aiven

**What goes wrong:** `Database::connection()` fails to connect — PDO throws "Unknown database 'reselltrack'".

**Why it happens:** Aiven creates a database named `defaultdb` by default when you provision a MySQL service. If the operator doesn't explicitly create a database named `reselltrack` in Aiven, the DSN (`dbname=reselltrack`) will not match.

**How to avoid:** Operator must either: (a) create a database named `reselltrack` in Aiven via their console/CLI, or (b) set `DB_NAME=defaultdb` in their `.env` and as Vercel env var.

**Warning signs:** PDOException "Unknown database 'reselltrack'" or "Access denied for user ... to database 'reselltrack'".

### Pitfall 5: Seed Data in Production

**What goes wrong:** Demo user `demo@test.fr` and fake products/purchases/sales appear in the production database.

**Why it happens:** `bin/migrate.php` accidentally runs `sql/seed.sql` in addition to `sql/schema.sql`.

**How to avoid:** `bin/migrate.php` must explicitly only load `sql/schema.sql`. D-08 is absolute: no seed in prod.

**Warning signs:** Production database has a user `demo@test.fr` after migration.

### Pitfall 6: Schema::ensure() Before schema.sql

**What goes wrong:** `Schema::ensure()` fails with "Can't create table ... (errno: 150 - Foreign key constraint is incorrectly formed)" on the `orders` table creation, because `users` table doesn't exist yet.

**Why it happens:** Schema::ensure() creates `orders` with `FOREIGN KEY ... REFERENCES users (id)`. If schema.sql hasn't run yet, `users` doesn't exist.

**How to avoid:** Always run schema.sql first. The `orders` creation in Schema::ensure() uses `CREATE TABLE IF NOT EXISTS`, but the FK definition still fails if the referenced table is absent.

---

## Code Examples

### Complete Database.php TLS Modification

```php
// Source: Database.php + PHP docs PDO MySQL SSL [VERIFIED: php.net, codebase read]
// In Database::connection(), replace lines 31-41:

$dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', $host, $port, $name);

// Resolve DB_SSL_CA — relative to project root, absolute path required by PDO
$relCert = Env::get('DB_SSL_CA', 'certs/aiven-ca.pem');
// dirname(__FILE__, 3): src/Core/Database.php -> src/Core -> src -> project root
$certPath = str_starts_with($relCert, '/')
    ? $relCert
    : dirname(__FILE__, 3) . '/' . $relCert;

$options = [
    PDO::ATTR_ERRMODE                      => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE           => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES             => false,
    PDO::MYSQL_ATTR_SSL_CA                 => $certPath,
    PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT => true,
];

try {
    self::$instance = new PDO($dsn, $user, $pass, $options);
    // Schema::ensure(self::$instance); <-- REMOVE THIS LINE (D-03)
} catch (PDOException $e) {
    // ... existing error handling unchanged (D-10)
}
```

### .env.example Addition

```dotenv
# TLS — Aiven CA certificate (path relative to project root, or absolute)
DB_SSL_CA=certs/aiven-ca.pem
```

This env var is optional on Vercel (cert is at the hardcoded default path). It is useful locally if the operator stores the cert elsewhere.

### Complete bin/migrate.php

```php
<?php
declare(strict_types=1);

/**
 * One-shot schema migration for Aiven MySQL.
 * Operator runs: php bin/migrate.php
 * Requires .env (or shell env) with DB_HOST/PORT/NAME/USER/PASSWORD for Aiven.
 * Must NOT be run on Vercel (serverless has no shell — see D-07).
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only.' . PHP_EOL);
}

$root = dirname(__DIR__); // bin/ -> project root

// PSR-4 autoloader: App\ -> src/ (mirrors public/index.php exactly)
spl_autoload_register(static function (string $class) use ($root): void {
    $prefix = 'App\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $relative = substr($class, strlen($prefix));
    $file = $root . '/src/' . str_replace('\\', '/', $relative) . '.php';
    if (is_file($file)) {
        require $file;
    }
});

require $root . '/src/helpers.php';

use App\Core\Env;
use App\Core\Database;
use App\Core\Schema;

Env::load($root . '/.env');

echo 'Connecting to Aiven MySQL (' . Env::get('DB_HOST', '?') . ')...' . PHP_EOL;

// Database::connection() handles TLS options + friendly error + exit on failure.
// After D-03: connection() no longer calls Schema::ensure(). This script owns that.
$pdo = Database::connection();

echo 'Connected.' . PHP_EOL;
echo 'Applying sql/schema.sql...' . PHP_EOL;

$sqlFile = $root . '/sql/schema.sql';
if (!is_file($sqlFile)) {
    echo 'ERROR: sql/schema.sql not found at ' . $sqlFile . PHP_EOL;
    exit(1);
}

$sql = (string) file_get_contents($sqlFile);

// Split by semicolon; skip CREATE DATABASE and USE statements.
// Aiven pre-creates the database; operator may not have CREATE DATABASE privilege.
// The DSN already specifies the database name, so USE is redundant.
$statements = array_filter(
    array_map('trim', explode(';', $sql)),
    static fn(string $s): bool => $s !== ''
        && !preg_match('/^\s*(CREATE\s+DATABASE\b|USE\s+\w)/i', $s)
);

$count = 0;
foreach ($statements as $stmt) {
    try {
        $pdo->exec($stmt);
        $count++;
    } catch (\PDOException $e) {
        echo 'ERROR executing statement: ' . $e->getMessage() . PHP_EOL;
        echo 'Statement:' . PHP_EOL . $stmt . PHP_EOL;
        exit(1);
    }
}

echo "schema.sql applied ({$count} statements)." . PHP_EOL;
echo 'Applying Schema::ensure() (idempotent structural additions)...' . PHP_EOL;

try {
    Schema::ensure($pdo);
} catch (\Throwable $e) {
    echo 'ERROR in Schema::ensure(): ' . $e->getMessage() . PHP_EOL;
    exit(1);
}

echo 'Migration complete. Database is ready.' . PHP_EOL;
exit(0);
```

---

## Runtime State Inventory

> This is a greenfield Aiven database — no existing data to migrate. The local Docker volume is dev-only. Omit data migration concern.

| Category | Items Found | Action Required |
|----------|-------------|-----------------|
| Stored data | None in Aiven (not yet provisioned) | None — fresh DB, schema.sql creates all tables |
| Live service config | Aiven service must be provisioned by operator before Phase 2 runs | Operator step: create Aiven MySQL service, create/note database name, download CA cert |
| OS-registered state | None | None |
| Secrets/env vars | DB_HOST, DB_PORT, DB_NAME, DB_USER, DB_PASSWORD must be added to Vercel env vars and operator's local .env | Operator step in Vercel dashboard + local .env |
| Build artifacts | None | None |

**Local Docker dev:** Unaffected. Docker Compose still works — `DB_SSL_CA` env var is absent in Docker's environment, so Database.php uses the default cert path. On local Docker (no TLS), the cert file exists but MySQL ignores it (Docker MySQL doesn't enforce TLS). No breaking change.

Wait — actually, there IS a concern: if `DB_SSL_CA` defaults to `certs/aiven-ca.pem` and local Docker MySQL doesn't have a `certs/aiven-ca.pem` file, PDO will fail to connect locally with "SSL: unable to get certificate from ...".

**Resolution:** `PDO::MYSQL_ATTR_SSL_CA` only forces TLS use; if the file doesn't exist, the connection fails. Two options for the planner:
1. Read `DB_SSL_CA` only if set — don't add SSL options when the var is absent/empty (D-10 is "plain TLS connect" meaning Aiven enforces TLS on its end, not that we skip the cert options locally).
2. Commit the cert to the repo (D-04) and also add it to the Docker dev stack (Docker's MySQL won't enforce verification, so the cert is just present but irrelevant).
3. Guard SSL options with `if ($certPath !== null && is_file($certPath))` — skip SSL options when cert absent (local Docker dev), apply when cert present (Aiven prod).

**Option 3 is recommended** as it preserves local Docker dev without requiring a cert file in Docker, while ensuring Aiven (where the cert is present) always uses TLS. The planner should evaluate against D-05 (verify-server-cert ON) — option 3 means local Docker runs without TLS, which is acceptable since Docker is local dev only, not a production concern.

---

## Environment Availability

| Dependency | Required By | Available | Version | Fallback |
|------------|------------|-----------|---------|----------|
| PHP 8.3 CLI | `bin/migrate.php` (operator's machine) | Assumed | 8.3+ | Operator must install PHP 8.3 locally |
| `pdo_mysql` extension (local) | `bin/migrate.php` | Assumed | PHP 8.3 built-in | Install php8.3-mysql on operator's machine |
| `openssl` extension (local) | TLS in `bin/migrate.php` | Assumed | PHP 8.3 built-in | Install php8.3-openssl |
| `pdo_mysql` + `openssl` (Lambda) | `Database::connection()` on Vercel | CONFIRMED | vercel-php@0.7.4 manifest | N/A — confirmed present |
| Aiven MySQL service | All DB operations | Not yet provisioned | — | Operator must provision before running migrate.php |
| `certs/aiven-ca.pem` | `PDO::MYSQL_ATTR_SSL_CA` | Not yet committed | — | Operator downloads from Aiven Console, commits |

**Missing dependencies with no fallback (blocking):**
- Aiven MySQL service not yet provisioned — operator must provision it first
- `certs/aiven-ca.pem` not yet committed — operator must download from Aiven and commit it

**Missing dependencies with fallback:**
- PHP 8.3 CLI on operator machine — if absent, migrate.php cannot run locally. But this is a dev environment setup concern, not a code concern.

---

## Aiven Connection Reference

> These are the details the operator needs from the Aiven Console to populate `.env` and Vercel env vars.

| Parameter | Env Var | Where to Find | Notes |
|-----------|---------|---------------|-------|
| Host | `DB_HOST` | Aiven Console > Service > Overview > Connection URI | Format: `mysql-xxx.aivencloud.com` |
| Port | `DB_PORT` | Aiven Console > Service > Overview > Connection URI | May not be 3306; check actual service URI |
| Database name | `DB_NAME` | Aiven Console > Databases tab, or `defaultdb` | Must match exactly; Aiven default is `defaultdb` |
| Username | `DB_USER` | Aiven Console > Users tab | Aiven-created user (e.g., `avnadmin`) |
| Password | `DB_PASSWORD` | Aiven Console > Users tab | Reset password there if unknown |
| CA cert | `DB_SSL_CA` | Aiven Console > Overview > Download CA cert | Save as `certs/aiven-ca.pem` and commit |

**TLS enforcement:** Aiven MySQL sets `require_secure_transport = ON`. Plaintext connections fail with MySQL Error 3159. [CITED: aiven.io/docs/platform/concepts/tls-ssl-certificates]

---

## Validation Architecture

> `workflow.nyquist_validation` — key absent from .planning/config.json; treat as enabled.

### Test Framework

| Property | Value |
|----------|-------|
| Framework | PHPUnit 11.x (`vendor/bin/phpunit`) |
| Config file | `phpunit.xml` (project root) |
| Quick run | `composer test` |
| Full suite | `vendor/bin/phpunit` |

### Phase Requirements → Test Map

| Req ID | Behavior | Test Type | Automated Command | Notes |
|--------|----------|-----------|-------------------|-------|
| DB-01 | PDO connects to Aiven over TLS | Manual smoke test | `php bin/migrate.php` (exit 0 = TLS connected) | No unit-testable assertion without a live Aiven service; manual verification |
| DB-02 | Schema applied via migrate.php | Manual smoke test | `php bin/migrate.php` then check tables | Verify all 8 tables exist in Aiven after run |
| DB-03 | Schema::ensure() not called in connection() | Unit / code review | `grep -n 'Schema::ensure' src/Core/Database.php` → no match | Verify absence; can be a CI lint check |

**Existing test suite:** Only `src/Services/ProfitCalculator.php` is covered. No DB integration tests exist. This phase does not add DB integration tests (per REQUIREMENTS.md Out of Scope: "extended test coverage hors périmètre").

### Wave 0 Gaps

- No automated test for DB-01/DB-02 (require live Aiven service — not feasible in CI without secrets)
- DB-03 can be verified by grep; no PHPUnit test needed

---

## Security Domain

> `security_enforcement` not set to false; treating as enabled.

### Applicable ASVS Categories

| ASVS Category | Applies | Standard Control |
|---------------|---------|-----------------|
| V2 Authentication | No | DB credentials handled via env vars (existing pattern) |
| V3 Session Management | No | Phase 2 scope is DB connection only; sessions are Phase 3 |
| V4 Access Control | No | DB user scoped to single database on Aiven |
| V5 Input Validation | No | No new user-facing input in this phase |
| V6 Cryptography | Yes | TLS with CA cert verification; `PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT = true` |
| V9 Communication | Yes | All DB traffic over TLS (Aiven enforces; cert verification required by D-05) |

### Known Threat Patterns for This Phase

| Pattern | STRIDE | Standard Mitigation |
|---------|--------|---------------------|
| Man-in-the-middle on DB connection | Spoofing / Info Disclosure | `MYSQL_ATTR_SSL_VERIFY_SERVER_CERT = true` — validates Aiven's cert against committed CA |
| CA cert in .vercelignore (cert not in Lambda) | Denial of Service (broken deploy) | Confirm `certs/` NOT in `.vercelignore` — verified safe |
| Committed secrets (DB credentials) | Info Disclosure | DB creds stay in `.env` (gitignored) and Vercel env vars. Only the CA cert (public info) is committed |
| seed.sql loaded to production | Integrity / Info Disclosure | `bin/migrate.php` must explicitly only load schema.sql (D-08) |

**Security note:** Committing `certs/aiven-ca.pem` is explicitly approved by D-04. The CA cert is a **public-key certificate** (public information), not a secret. Committing it is the standard approach for client certificate pinning to a known CA. [CITED: .planning/02-CONTEXT.md D-04 rationale]

---

## Open Questions (RESOLVED)

1. **Aiven database name: `reselltrack` vs `defaultdb`?**
   - What we know: Aiven creates `defaultdb` by default. The codebase expects `DB_NAME=reselltrack`.
   - What's unclear: Does the operator plan to create a `reselltrack` database explicitly in Aiven, or use `defaultdb`?
   - RESOLVED: Operator sets `DB_NAME` (local `.env` + Vercel env vars) to match the actual Aiven database name. Captured as an explicit operator instruction in 02-02 Task 1.

2. **Local Docker dev: cert file absent → PDO::MYSQL_ATTR_SSL_CA fails?**
   - What we know: If SSL options are always applied and `certs/aiven-ca.pem` doesn't exist locally (before the operator commits it), local Docker dev breaks.
   - What's unclear: Is the cert committed before or during this phase? (It must be committed as part of D-04.)
   - RESOLVED: SSL options are gated on `is_file($certPath)` in `Database::connection()` so local Docker dev (no cert) keeps connecting plaintext. Implemented in 02-01 Task 1.

3. **Aiven port: is it 3306 or a custom high port?**
   - What we know: Aiven MySQL services sometimes use a custom TCP port (not 3306).
   - What's unclear: Not determinable until the operator provisions the service.
   - RESOLVED: Operator copies the exact port from the Aiven Console service URI (do not assume 3306). Captured as an operator instruction in 02-02 Task 1.

4. **Does bin/ need to be in .vercelignore?**
   - What we know: `bin/` is currently not in `.vercelignore`, so `bin/migrate.php` ships in the Lambda bundle (unused there). It has a CLI guard (`PHP_SAPI !== 'cli'`).
   - What's unclear: Whether the operator prefers to exclude it.
   - RESOLVED: Mandatory `PHP_SAPI !== 'cli'` guard in `bin/migrate.php` prevents web invocation (02-01 Task 2). Excluding `bin/` from `.vercelignore` is cosmetic only and intentionally not done.

---

## Assumptions Log

| # | Claim | Section | Risk if Wrong |
|---|-------|---------|---------------|
| A1 | Aiven MySQL sets `require_secure_transport = ON` (plaintext rejected) | Aiven Connection Reference | Low risk: if wrong, plaintext fallback would "work" but insecurely. Aiven docs confirm TLS is always enforced. |
| A2 | Aiven MySQL user does NOT have `CREATE DATABASE` privilege | Pitfall 3, Pattern 3 | If wrong: skip-regex in schema.sql execution is harmless (IF NOT EXISTS makes it a no-op). No negative impact. |
| A3 | Local Docker dev MySQL does NOT enforce TLS (ignores SSL_CA option) | Runtime State Inventory | Low risk: Docker Compose uses `mysql:8.0` image; default config has no `require_secure_transport`. Confirmed by standard MySQL Docker image behavior. |
| A4 | Lambda filesystem preserves project structure; `__FILE__`-based path works | PDO cert path in Database.php | Medium risk: if Lambda uses a different layout, cert path computation breaks. **Mitigated** by Phase 1 confirming that `require dirname(__DIR__) . '/public/index.php'` works in the Lambda (same relative path pattern). |
| A5 | Aiven default database name is `defaultdb` | Aiven Connection Reference, Open Questions | Medium risk: if operator created the service with `reselltrack` as database name already, this is a non-issue. Operator must verify. |

**If this table is empty:** All claims in this research were verified or cited — no user confirmation needed. (It is not empty; see above.)

---

## Sources

### Primary (HIGH confidence)
- `php.net/manual/en/ref.pdo-mysql.php` — PDO MySQL constants (`MYSQL_ATTR_SSL_CA`, `MYSQL_ATTR_SSL_VERIFY_SERVER_CERT`), SSL cannot be set after connection
- vercel-php@0.7.4 GitHub manifest — confirms `pdo_mysql` and `openssl` in extension list, PHP 8.3
- `sql/schema.sql` (codebase read) — confirmed all tables/columns present
- `src/Core/Schema.php` (codebase read) — confirmed all operations are no-ops on a DB built from schema.sql
- `src/Core/Database.php` (codebase read) — confirmed single Schema::ensure() call at line 41
- `public/index.php` (codebase read) — autoloader pattern to mirror in bin/migrate.php
- `.vercelignore` (codebase read) — confirmed `certs/` not excluded

### Secondary (MEDIUM confidence)
- `aiven.io/docs/platform/concepts/tls-ssl-certificates` — "All traffic to Aiven services is always protected by TLS"
- `aiven.io/docs/products/mysql/howto/connect-with-php` — Aiven PHP connection example (note: DSN `sslmode` syntax shown is PostgreSQL-specific and incorrect for pdo_mysql; PDO constants are the correct approach)
- WebSearch results confirming `require_secure_transport=ON` is standard for Aiven MySQL

### Tertiary (LOW confidence — flagged [ASSUMED])
- Aiven default database name `defaultdb` — from training knowledge, not directly verified via Aiven console

---

## Metadata

**Confidence breakdown:**
- PDO SSL constants: HIGH — verified against php.net official docs
- vercel-php@0.7.4 extension list: HIGH — confirmed from published extension manifest
- schema.sql vs Schema.php diff: HIGH — direct code read, line-by-line comparison
- Aiven TLS enforcement: MEDIUM — confirmed by Aiven platform docs (TLS always required)
- Lambda cert path pattern: MEDIUM — inferred from working Phase 1 pattern (`dirname(__DIR__)` in api/index.php works)
- Aiven default DB name: LOW — training knowledge, not console-verified

**Research date:** 2026-06-12
**Valid until:** 2026-07-12 (PDO constants are stable; vercel-php version locked at 0.7.4)
