---
phase: 9
slug: product-ratings
status: draft
nyquist_compliant: true
wave_0_complete: true
created: 2026-06-15
---

# Phase 9 — Validation Strategy

> **Project policy (REQUIREMENTS.md:103):** extended automated tests for controllers/models/Core are out of scope — only `src/Services/ProfitCalculator` is unit-tested. Validation is predominantly **operator/manual verification on the live Vercel URL** (VERIF-01 / Phase 8 style), plus the PHPUnit suite as a regression guard. Research was skipped (Phase 9 mirrors the Phase 8 rating pattern), so this strategy is authored directly.

## Test Infrastructure

| Property | Value |
|----------|-------|
| Framework | PHPUnit 11.x (dev-only) |
| Config | `phpunit.xml` (project root) |
| Run command | `vendor/bin/phpunit` (~2s; ProfitCalculator only) |
| Per-edit syntax check | `php -l <file>` ; `node --check public/assets/js/app.js` |

## Sampling Rate
- After every task commit: `php -l` on touched PHP + `node --check` on app.js + `vendor/bin/phpunit` (must stay green).
- Before verification: full suite green + manual/live verification on the deployed URL (post `bin/migrate.php`).

## Per-Task Verification Map

| Requirement | Behavior | Test Type | Automated Command | Status |
|-------------|----------|-----------|-------------------|--------|
| RATE-01 | products.rating + rating_note columns added idempotently | manual / live (SHOW COLUMNS on Aiven) | `php bin/migrate.php` x2 (idempotent) | ⬜ pending |
| RATE-01 | rating + comment saved via product edit form | manual / live | (manual — no controller test harness by policy) | ⬜ pending |
| RATE-01 | inline quick-rate on detail page (POST /products/{id}/rate) updates rating, ownership + CSRF enforced | manual / live | (manual) | ⬜ pending |
| RATE-01 | list shows rating badge near name; unrated = neutral; detail shows stars + comment | manual / live | (manual) | ⬜ pending |
| regression | ProfitCalculator + existing product flows unchanged | unit | `vendor/bin/phpunit` | ⬜ pending |

## Wave 0 Requirements
- None blocking. No new test infrastructure (project policy). `phpunit.xml` + ProfitCalculator suite already present.

## Manual-Only Verifications

| Behavior | Why Manual | Instructions |
|----------|------------|--------------|
| Rating save via form | No controller test harness | On live URL: edit a product, set 1-5 stars + comment, save → list badge + detail show it |
| Inline quick-rate | Integration (route + JS + DB) | On a product detail page, click a star → rating persists (reload), ownership/CSRF enforced |
| Backward compat | DB state | Existing products show neutral "unrated" placeholder; no data migration; rating NULL by default |
| Idempotent migration | Aiven DDL | `php bin/migrate.php` twice → exit 0 both; `SHOW COLUMNS FROM products LIKE 'rating'` returns one row |

## Validation Sign-Off
- [x] All tasks have automated verify (regression/`php -l`/`node --check`) or documented manual-only with instructions
- [x] Sampling continuity: regression suite every commit; manual gate at phase end
- [x] No watch-mode flags; feedback latency < ~5s (automated)
- [x] `nyquist_compliant: true`

**Approval:** approved 2026-06-15
