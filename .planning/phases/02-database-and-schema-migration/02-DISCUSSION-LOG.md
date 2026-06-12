# Phase 2 Discussion Log

**Date:** 2026-06-12
**Mode:** discuss (interactive)

## Gray areas presented
Schema source of truth · CA cert delivery · How migrate runs + seed · Cold-start connection.
User chose to discuss **all four**.

## Decisions

| Area | Question | Decision |
|------|----------|----------|
| Schema | Single source vs keep Schema.php | **Keep Schema.php**; `bin/migrate.php` calls `Schema::ensure()` once; remove it from `Database::connection()` (D-01/02/03) |
| CA cert | Commit file vs env→/tmp | **Commit `certs/aiven-ca.pem`**, `MYSQL_ATTR_SSL_CA` → path, verify-server-cert ON (D-04/05/06) |
| Migrate+seed | Run location & prod seed | **Local → Aiven over TLS**; schema only, **no demo seed** in prod (D-07/08/09) |
| Cold start | Harden now vs defer | **Plain connect, defer all hardening**; accept Aiven connection-limit risk; defer ProxySQL (D-10/11) |

## Notes
- Already-locked (not re-asked): Aiven MySQL 8, TLS, keep MySQL (no Postgres/TiDB), one-shot `bin/migrate.php`.
- Open items routed to the researcher (not the user): exact PDO SSL option set for Aiven under vercel-php@0.7.4; whether `sql/schema.sql` already covers everything `Schema.php` adds; Aiven TLS/DSN specifics.
