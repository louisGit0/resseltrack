# Phase 2 Validation Strategy

> `workflow.nyquist_validation` — key absent from .planning/config.json; treat as enabled.
> Extracted from 02-RESEARCH.md "Validation Architecture" as the phase's standalone validation artifact.

## Test Framework

| Property | Value |
|----------|-------|
| Framework | PHPUnit 11.x (`vendor/bin/phpunit`) |
| Config file | `phpunit.xml` (project root) |
| Quick run | `composer test` |
| Full suite | `vendor/bin/phpunit` |

## Phase Requirements → Test Map

| Req ID | Behavior | Test Type | Automated/Manual Command | Notes |
|--------|----------|-----------|--------------------------|-------|
| DB-01 | PDO connects to Aiven over TLS | Manual smoke test | `php bin/migrate.php` (exit 0 = TLS connected); live route on Vercel renders with no SSL error in function logs | No unit-testable assertion without a live Aiven service; manual verification (02-02 Task 3) |
| DB-02 | Schema applied via migrate.php | Manual smoke test | `php bin/migrate.php` then confirm all 7 tables exist in Aiven; re-run exits 0 (idempotent) | Verify tables present after run; CRUD persists in Aiven not Docker (02-02 Task 2/3) |
| DB-03 | `Schema::ensure()` not called in `connection()` | Unit / code review (CI-feasible) | `grep -n 'Schema::ensure' src/Core/Database.php` → no match | Verify absence; automatable lint check (02-01 Task 1 verify) |

**Existing test suite:** Only `src/Services/ProfitCalculator.php` is covered. No DB integration tests exist. This phase does NOT add DB integration tests (per REQUIREMENTS.md Out of Scope: "extended test coverage hors périmètre").

## Wave 0 Gaps

- No automated test for DB-01/DB-02 — both require a live Aiven service, not feasible in CI without secrets. Verified manually via `bin/migrate.php` + live-URL smoke checks in 02-02 Task 3.
- DB-03 is fully verifiable by grep on `src/Core/Database.php` — no PHPUnit test needed.

## Regression Guard

- Local Docker dev must remain functional: `is_file($certPath)` gates the SSL options so Docker MySQL (no committed cert in its connection path) still connects. Confirmed by a local-Docker regression check in 02-02 Task 3.
- No existing application code (models, services, views) changes — only `Database::connection()` is modified and `bin/migrate.php` is added. Standing regression command once `vendor/` is installed: `vendor/bin/phpunit`.
