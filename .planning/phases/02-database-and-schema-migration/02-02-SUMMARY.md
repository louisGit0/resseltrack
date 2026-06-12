---
phase: 02-database-and-schema-migration
plan: "02"
subsystem: database
tags: [aiven, mysql, tls, migration, vercel, operator, verification]
dependency_graph:
  requires: [tls-pdo-options, bin-migrate, certs-scaffolding]
  provides: [live-aiven-database, schema-applied, db-verified-live]
  affects: [vercel-deployment, all-db-backed-features]
tech_stack:
  added: [aiven-mysql-8.4]
  patterns: [github-vercel-env-vars, one-shot-cli-migration, committed-ca-cert-tls]
key_files:
  created:
    - certs/aiven-ca.pem
  modified: []
decisions:
  - "Aiven MySQL 8.4 service 'resseltrack' provisioned (DigitalOcean Amsterdam, free/hobby plan); DB name defaultdb, user avnadmin, custom port 18971, ssl-mode=REQUIRED"
  - "CA cert committed to certs/aiven-ca.pem (public root cert, D-04); DB creds set as Vercel env vars (Production+Preview, Sensitive) + operator local .env"
  - "bin/migrate.php run locally against Aiven — schema applied (9 statements) + Schema::ensure(); idempotent on 2nd run; NO seed.sql (D-08)"
metrics:
  completed_date: "2026-06-12"
  tasks_completed: 3
  files_created: 1
  files_modified: 0
---

# Phase 2 Plan 02: Aiven Provisioning & Live Verification Summary

**One-liner:** Provisioned a live Aiven MySQL 8.4 instance, committed the CA cert, wired DB credentials into Vercel env vars, ran the one-shot migration, and verified DB-01/02/03 end-to-end — a user registered on the live Vercel site persists in Aiven over TLS.

## Tasks Completed

| Task | Name | Result |
|------|------|--------|
| 1 | Provision Aiven, commit CA cert, set env vars | MySQL 8.4 service live; `certs/aiven-ca.pem` committed (`e63f1ad`); 6 `DB_*` vars set in Vercel (Production+Preview, Sensitive) + local `.env` |
| 2 | Run `bin/migrate.php` against Aiven | Schema applied (9 statements) + `Schema::ensure()`; exit 0; **idempotent** (2nd run exit 0, no errors); 7 tables created in `defaultdb`, all empty (no seed) |
| 3 | Verify DB-01/02/03 + regression on live URL | All PASS — see table below |

## Live Verification Results (deployment dpl_4nNP…, commit e63f1ad)

| Requirement | Status | Evidence |
|-------------|--------|----------|
| DB-01: TLS connection to Aiven from Vercel | PASS | Live `/register` POST succeeded (HTTP 302 → /dashboard) — the Lambda connected to Aiven over TLS with CA verification; no SSL error |
| DB-02: schema applied via one-shot migrate.php | PASS | `php bin/migrate.php` applied schema (9 stmts) + ensure(); 7 tables present in Aiven (`users, products, product_images, orders, purchases, sales, login_attempts`); idempotent |
| DB-03: no runtime DDL | PASS | `Schema::ensure()` removed from `Database::connection()` (02-01); no DDL on the request path |
| Criterion 4: CRUD persists in Aiven (not Docker) | PASS | A user registered on `https://resseltrack-nu.vercel.app/register` landed as `users` #1 in Aiven (verified via direct query); test row then cleaned up |

Aiven service: `resseltrack` (MySQL 8.4.8), host `resseltrack-resseltrack.g.aivencloud.com:18971`, db `defaultdb`.

## Operator Steps Performed

1. Created free Aiven MySQL 8.4 service (after initially provisioning PostgreSQL by mistake — caught and recreated as MySQL).
2. Downloaded the CA cert → `certs/aiven-ca.pem`, committed + pushed.
3. Set `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER`, `DB_PASSWORD`, `DB_SSL_CA` in Vercel (Production + Preview) and local `.env`.
4. Ran `php bin/migrate.php` locally (×2) against Aiven; redeployed on Vercel.

## Deviations / Notes

- **Engine mix-up caught:** the first Aiven service was PostgreSQL 17 — incompatible with the MySQL-only codebase (DSN, `MYSQL_ATTR_SSL_CA`, ENUM/AUTO_INCREMENT schema, `FOR UPDATE`). Flagged before any wiring; recreated as MySQL 8.4. No code impact.
- **Aiven DB name is `defaultdb`** (not `reselltrack`); `DB_NAME` set accordingly. `bin/migrate.php` strips `CREATE DATABASE`/`USE` so the schema applies cleanly into `defaultdb`.

## Open Follow-up (local Docker TLS regression — flagged, not yet actioned)

With `certs/aiven-ca.pem` now committed, `Database::connection()`'s `is_file($certPath)` guard becomes true in local Docker too, so a `docker compose up` against the local MySQL could attempt TLS verification against Aiven's CA and fail. Documented fallback (from 02-RESEARCH / plan): gate the TLS options so they only engage for the remote Aiven host, not the local `db`/localhost host. **Pending a decision on whether local Docker dev is still used.** Does not affect the live site.

## Self-Check: PASSED

| Check | Result |
|-------|--------|
| Aiven MySQL service live | CONFIRMED (8.4.8, Running) |
| migrate.php applied schema + idempotent | CONFIRMED (2 clean runs) |
| 7 tables in Aiven, no seed data | CONFIRMED |
| Live registration persists to Aiven | CONFIRMED (then cleaned up) |
| CA cert committed, .env gitignored (secret safe) | CONFIRMED |
