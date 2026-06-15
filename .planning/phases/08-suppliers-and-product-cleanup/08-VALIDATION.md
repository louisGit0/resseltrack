---
phase: 8
slug: suppliers-and-product-cleanup
status: draft
nyquist_compliant: true
wave_0_complete: true
created: 2026-06-15
---

# Phase 8 — Validation Strategy

> Per-phase validation contract for feedback sampling during execution.
> **Project policy (REQUIREMENTS.md:103):** extended automated tests for controllers/models/Core are explicitly out of scope — only `src/Services/ProfitCalculator` is unit-tested. Validation for this phase is therefore predominantly **operator/manual verification on the live Vercel URL**, mirroring how Phases 1–7 were verified (VERIF-01 style), plus the existing PHPUnit suite as a regression guard.

---

## Test Infrastructure

| Property | Value |
|----------|-------|
| **Framework** | PHPUnit 11.x (dev-only) |
| **Config file** | `phpunit.xml` (project root) |
| **Quick run command** | `vendor/bin/phpunit` |
| **Full suite command** | `vendor/bin/phpunit` |
| **Estimated runtime** | ~2 seconds (only `ProfitCalculator` tests exist) |

Coverage source is `src/Services/` only. This phase introduces almost no pure logic; the new code is controller/model/view (untested by project convention).

---

## Sampling Rate

- **After every task commit:** Run `vendor/bin/phpunit` — the `ProfitCalculator` suite must stay green (proves no regression to untouched fee/CUMP logic).
- **After every plan wave:** Run `vendor/bin/phpunit` + manual smoke of the affected route locally.
- **Before verification:** Full suite green + manual verification on the live Vercel URL (post `bin/migrate.php`).
- **Max feedback latency:** ~2 seconds (automated); manual gate at phase end.

---

## Per-Task Verification Map

| Task ID | Plan | Wave | Requirement | Threat Ref | Secure Behavior | Test Type | Automated Command | File Exists | Status |
|---------|------|------|-------------|------------|-----------------|-----------|-------------------|-------------|--------|
| (TBD by planner) | — | — | SUP-01 | IDOR on supplier id | Supplier CRUD scoped to `Auth::id()`; `find($id,$uid)` returns null cross-user | manual / live | (manual — no model/controller harness by policy) | n/a | ⬜ pending |
| (TBD by planner) | — | — | SUP-02 | IDOR on posted `supplier_id` | `supplier_id` re-checked via `Supplier::find($id, Auth::id())`; null → "Autre" free-text fallback; legacy orders render unchanged | manual / live | (manual) | n/a | ⬜ pending |
| (TBD by planner) | — | — | OPS-06 | — | Product delete purges cover+gallery from Cloudinary (deduped), best-effort; failure logged, never blocks DB delete | manual (Cloudinary HEAD 404 / console) | (manual) | n/a | ⬜ pending |
| regression | — | all | — | — | Existing `ProfitCalculator` behavior unchanged | unit | `vendor/bin/phpunit` | ✅ | ⬜ pending |

*Status: ⬜ pending · ✅ green · ❌ red · ⚠️ flaky*

---

## Wave 0 Requirements

- None blocking. No new test infrastructure required — project policy descopes controller/model tests; `phpunit.xml` + the `ProfitCalculator` suite already exist.

*Optional (flagged, not required):* a `Supplier`-resolution unit test would require refactoring `OrderController::parseInput()` to make the id→name resolution pure — out of step with the current `$_POST`-in-controller convention. Do not build unless explicitly requested.

---

## Manual-Only Verifications

| Behavior | Requirement | Why Manual | Test Instructions |
|----------|-------------|------------|-------------------|
| Supplier CRUD (create/edit/delete, user-scoped) | SUP-01 | No model/controller test harness (project policy) | On live URL: create a supplier (name+URL+stars+comment), edit it, delete it; confirm a second account cannot see/edit it |
| Order ↔ supplier link + backward compat | SUP-02 | Integration across order form + DB | Create an order selecting a saved supplier → confirm `supplier_id` + name stored; create one with "Autre" free text; confirm a legacy order (no `supplier_id`) still displays its supplier name |
| Supplier delete unlinks orders | SUP-02 | DB FK behavior on Aiven | Delete a supplier linked to an order → order keeps its free-text name, `supplier_id` becomes NULL (ON DELETE SET NULL) |
| Product delete purges Cloudinary | OPS-06 | External CDN state | Delete a product with a cover + gallery images → confirm objects gone via Cloudinary console / HEAD 404; verify DB rows removed even if a purge call fails (check error_log) |

---

## Validation Sign-Off

- [x] All tasks have automated verify (regression suite) or are documented manual-only with instructions
- [x] Sampling continuity: regression suite runs every commit; manual gate at phase end
- [x] Wave 0 covers all MISSING references (none required)
- [x] No watch-mode flags
- [x] Feedback latency < ~5s (automated)
- [x] `nyquist_compliant: true` set in frontmatter

**Approval:** approved 2026-06-15
