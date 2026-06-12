---
phase: 02-database-and-schema-migration
plan: "01"
subsystem: database
tags: [tls, pdo, aiven, migration, schema, cert-guard]
dependency_graph:
  requires: []
  provides: [cert-guarded-tls-connection, migrate-cli-script, certs-scaffolding]
  affects: [src/Core/Database.php, bin/migrate.php, certs/]
tech_stack:
  added: []
  patterns: [is_file-cert-guard, dirname(__FILE__)-absolute-path, PHP_SAPI-cli-guard, schema-sql-statement-filter]
key_files:
  created:
    - bin/migrate.php
    - certs/.gitkeep
    - certs/README.md
  modified:
    - src/Core/Database.php
    - .env.example
decisions:
  - "SSL options gated on is_file($certPath) so local Docker dev (no cert) connects without TLS while Aiven (cert present) always uses TLS"
  - "Cert path resolved via dirname(__FILE__,3) so it is absolute and cwd-independent on both Lambda and CLI"
  - "Schema::ensure() removed from Database::connection() (DB-03) — all DDL now lives exclusively in bin/migrate.php"
  - "bin/migrate.php inlines spl_autoload_register rather than requiring public/index.php to avoid session_start/headers/router side-effects"
metrics:
  duration: 2m
  completed: "2026-06-12T13:49:42Z"
  tasks_completed: 2
  files_changed: 5
---

# Phase 2 Plan 1: TLS Database Connection and Migration Script Summary

Added cert-guarded PDO TLS options to Database::connection(), removed the per-request Schema::ensure() DDL call, and created the bin/migrate.php one-shot CLI migration script with certs/ scaffolding.

## Tasks Completed

| # | Task | Commit | Files |
|---|------|--------|-------|
| 1 | Add cert-guarded TLS options to Database::connection() and remove Schema::ensure() | b8b207b | src/Core/Database.php, .env.example |
| 2 | Create bin/migrate.php and certs/ scaffolding | 4df89c7 | bin/migrate.php, certs/.gitkeep, certs/README.md |

## What Was Built

### src/Core/Database.php

Added cert path resolution before the `$options` array:

```php
$relCert  = Env::get('DB_SSL_CA', 'certs/aiven-ca.pem');
$certPath = str_starts_with($relCert, '/')
    ? $relCert
    : dirname(__FILE__, 3) . '/' . $relCert;
```

Added `is_file` guard around the two new SSL options:

```php
if (is_file($certPath)) {
    $options[PDO::MYSQL_ATTR_SSL_CA]                 = $certPath;
    $options[PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT] = true;
}
```

Removed `Schema::ensure(self::$instance);` (DB-03). The existing `catch (PDOException $e)` friendly-500 block is byte-for-byte unchanged (D-10).

### .env.example

Added under the `# Database` section:
- `DB_SSL_CA=certs/aiven-ca.pem` with a one-line comment
- A commented documentation block for the Aiven production variables (`DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER`, `DB_PASSWORD`) — no real values committed

### bin/migrate.php

New file (75 lines). Structure:
1. `declare(strict_types=1)` + `PHP_SAPI !== 'cli'` guard (returns 403, exits)
2. `$root = dirname(__DIR__)` then inline `spl_autoload_register` mirroring `public/index.php`
3. `require $root . '/src/helpers.php'`
4. `use` statements for `Env`, `Database`, `Schema`; `Env::load($root . '/.env')`
5. `Database::connection()` to obtain PDO handle (reuses TLS options from Task 1)
6. `file_get_contents('sql/schema.sql')` split on `;`, `CREATE DATABASE`/`USE` stripped, each statement executed via `$pdo->exec()`
7. `Schema::ensure($pdo)` called after schema.sql (mandatory order — FK references require tables to exist first)
8. `exit(0)` on success, `exit(1)` on any `PDOException` or `Throwable`

### certs/

- `certs/.gitkeep` — empty file to track the directory before the operator commits `aiven-ca.pem`
- `certs/README.md` — operator instructions: download from Aiven Console > Service > Overview > Download CA cert, save as `certs/aiven-ca.pem`, commit; explains that the cert is public info; warns not to add `certs/` to `.vercelignore`

## Verification Results

All automated checks passed:

| Check | Result |
|-------|--------|
| `php -l src/Core/Database.php` | No syntax errors detected |
| `MYSQL_ATTR_SSL_CA` present in Database.php | SSL_CA-present |
| `is_file` cert guard present | cert-guard-present |
| `Schema::ensure` absent from Database.php | no-runtime-ensure |
| `VERIFY_SERVER_CERT => false` absent | verify-on |
| `DB_SSL_CA` in .env.example | env-documented |
| `php -l bin/migrate.php` | No syntax errors detected |
| No `seed` reference in bin/migrate.php | no-seed |
| `PHP_SAPI` guard + `Schema::ensure` call present | guarded+ensure |
| `certs/` NOT excluded by .vercelignore | certs-not-excluded |
| `certs/.gitkeep` and `certs/README.md` present | scaffolding-present |

## Deviations from Plan

None - plan executed exactly as written. The RESEARCH.md patterns were used verbatim.

## Threat Model Coverage

| Threat ID | Status |
|-----------|--------|
| T-2-01 (MITM/Spoofing) | Mitigated — MYSQL_ATTR_SSL_VERIFY_SERVER_CERT => true; no verify=false anywhere |
| T-2-02 (Secrets in source) | Mitigated — .env.example has no real values; only the CA cert placeholder |
| T-2-03 (seed.sql in production) | Mitigated — bin/migrate.php only reads schema.sql; no seed reference |
| T-2-04 (bin/migrate.php over HTTP) | Mitigated — PHP_SAPI !== 'cli' guard returns 403 before any DB access |

## Known Stubs

None. This plan produces pure infrastructure changes — no UI components, no placeholder data.

## Self-Check: PASSED

- `src/Core/Database.php` — FOUND
- `.env.example` — FOUND (contains DB_SSL_CA)
- `bin/migrate.php` — FOUND
- `certs/.gitkeep` — FOUND
- `certs/README.md` — FOUND
- Task 1 commit b8b207b — FOUND
- Task 2 commit 4df89c7 — FOUND
