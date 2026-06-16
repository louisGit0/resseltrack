---
phase: 10
slug: product-url-auto-fill
status: draft
nyquist_compliant: true
wave_0_complete: false
created: 2026-06-16
---

# Phase 10 — Validation Strategy

> Unlike Phases 8-9 (CRUD/UI, manual-verified per project policy), Phase 10's core logic — the **SSRF IP guard, HTML parsing, and currency conversion** — is **pure logic in `src/Services/`**, which IS the project's tested coverage layer (`phpunit.xml` covers `src/Services/` only). It is also **security-critical** (SSRF). So this phase carries **real unit tests** for `ProductImportService`'s pure methods. The controller (`fetchUrl`) + `app.js` stay transport/UI → verified live, per existing practice.

## Test Infrastructure

| Property | Value |
|----------|-------|
| Framework | PHPUnit 11.x (dev-only) |
| Config | `phpunit.xml` (root); coverage source = `src/Services/` |
| Quick run | `vendor/bin/phpunit --filter ProductImportServiceTest` |
| Full suite | `vendor/bin/phpunit` (~2s today; +ProductImportService tests) |

**Design constraint (load-bearing):** `ProductImportService` MUST expose **pure, network-free methods** so they are unit-testable — e.g. `isPublicIp(string $ip): bool`, `parse(string $html): array`, `convert(?float $price, ?string $currency, ?float $rate): array`. The curl/DNS I/O method stays thin and is exercised manually live.

## Wave 0 Requirements (BLOCKING — must exist before the logic is "done")
- [ ] `tests/ProductImportServiceTest.php` (FLAT `tests/`, `namespace Tests;` — repo convention, mirrors `tests/ExchangeRateServiceTest.php`) covering IMPORT-01 pure logic.
- [ ] HTML fixtures (inline strings or `tests/fixtures/`): a well-formed OG page, a JS-shell/empty page, a malformed-HTML page.
- [ ] Pure methods extracted (`isPublicIp`, `parse`, `convert`) so tests run without network.

## Per-Requirement Test Map

| Behavior | Test Type | Automated Command | Status |
|----------|-----------|-------------------|--------|
| SSRF guard rejects private/loopback/link-local/metadata IPs (127/8, 10/8, 172.16/12, 192.168/16, 169.254.169.254, ::1, fc00::/7) and accepts public IPs | unit (pure `isPublicIp`) | `vendor/bin/phpunit --filter ProductImportServiceTest` | ⬜ |
| OG/JSON-LD/title extraction from fixture HTML → name/price/currency/image | unit (pure `parse`) | same | ⬜ |
| Currency→EUR: detected currency converts with injected rate; unknown/failed FX → raw value + needs-verify flag (no silent mislabel) | unit (pure `convert`) | same | ⬜ |
| Blocked/empty/non-200/garbage HTML → ok:false + French message, no throw | unit (`parse` on empty/garbage) | same | ⬜ |
| Route `/products/fetch-url` resolves to `fetchUrl()` not `update()` (ordering) | manual / live smoke | curl live endpoint → JSON not 302 | ⬜ |
| E2E: paste URL → fill-empty → preview (data: URI) → manual-entry message on failure | manual / live | Success Criteria 1-3 | ⬜ |
| Regression: ProfitCalculator + existing suite unchanged | unit | `vendor/bin/phpunit` | ⬜ |

## Sampling Rate
- Per task commit: `vendor/bin/phpunit --filter ProductImportServiceTest`
- Per wave: full `vendor/bin/phpunit`
- Phase gate: full suite green + live route smoke + browser E2E (no deploy needed for the unit tests).

## Manual-Only Verifications
| Behavior | Why Manual | Instructions |
|----------|------------|--------------|
| Route ordering / endpoint wiring | HTTP transport | `curl -X POST .../products/fetch-url` (with cookie + `_csrf`) returns JSON, not a 302 redirect to update |
| SSRF guard end-to-end | needs real network | (covered by unit `isPublicIp`; live, just confirm a public URL is fetched and an obviously-internal URL like `http://169.254.169.254/` is rejected with a clean message) |
| Best-effort UX | browser | paste an AliExpress URL → fields fill if parseable, else French "saisie manuelle indisponible"; all fields editable; non-destructive (pre-typed name kept) |

## Validation Sign-Off
- [x] Security-critical pure logic (SSRF guard) has dedicated unit tests (NOT manual-only)
- [x] Wave 0 test file + fixtures are a blocking prerequisite
- [x] Sampling continuity: filtered suite per commit, full suite per wave
- [x] No watch-mode flags; unit feedback < ~5s
- [x] `nyquist_compliant: true`

**Approval:** approved 2026-06-16
