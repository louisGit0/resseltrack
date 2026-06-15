# Phase 7: Production Verification - Context

**Gathered:** 2026-06-15
**Status:** Ready for execution (verification phase — no new code)

<domain>
## Phase Boundary

Final phase: confirm **every existing feature works on the live Vercel URL** (https://resseltrack-nu.vercel.app) under real production conditions. This is verification only — no implementation. Requirement: **VERIF-01** (auth, products + image, multi-currency purchases, sales with stock guard, orders, dashboard, CSV export, profile all verified live).

This phase closes milestone **v1.0** (the app is fully operational on Vercel for real users).
</domain>

<decisions>
## Verification Decisions
- **D-01 (anti-oversell, FOR UPDATE):** verify by a **single-request oversell attempt** (sell more than available stock → must be rejected with a visible FR message, no stock deducted) PLUS **code confirmation** that `SaleController::store()` wraps `validate(lock: true)` + insert in `beginTransaction`/`commit`/`rollBack` and `Sale.php:346` appends `FOR UPDATE`. The true parallel-race test is NOT run (unreliable in serverless; the lock+transaction logic is confirmed by code + the single-request rejection).
- **D-02 (sweep depth):** **spot-check the not-yet-covered flows** and reference prior live evidence for those already proven in earlier phases:
  - Already verified live (reference): registration/login/logout (P1/P3), session persistence + cookie flags (P3), image upload→Cloudinary display + delete→404 (P4), USD purchase cost from a rate (P6), HSTS/CSP/boot-gate (P5).
  - To verify now (uncovered): **USD purchase with port + customs** (shipping_cost + customs_cost allocated into unit_cost_eur, not just a bare rate), **sale** (stock deduction + oversell rejection), **order** create, **dashboard** renders with data + charts, **CSV export** (UTF-8 BOM + records), **profile** update.
- **D-03 (test data):** create a full end-to-end dataset (account → product w/ image → USD lot purchase w/ port+customs → sale → order), verify, then **TRUNCATE all tables** (prod returns pristine — no demo data for real users), as in every prior phase.
- **D-04 (deliverable):** produce **`07-VERIFICATION.md`** mapping each VERIF-01 feature → PASS/FAIL + live evidence; it closes VERIF-01 and serves as the v1.0 milestone sign-off.

## Process note
This is a verification sweep with zero code changes, so the discuss→research→plan→check→execute pipeline is collapsed: the orchestrator runs the live E2E directly (as it did for each prior phase's live verification) and writes 07-VERIFICATION.md. No researcher/planner/checker subagents.
</decisions>

<canonical_refs>
## Canonical References
- `.planning/REQUIREMENTS.md` §Vérification (VERIF-01) + all prior requirement sign-offs (DEPLOY/DB/SESS/STORE/SEC/PERF — all marked Done)
- `.planning/ROADMAP.md` Phase 7 success criteria (registration; product+image displays; USD batch purchase w/ port+customs ≠ 0.00; sale deducts stock + FOR UPDATE prevents oversell + appears on dashboard; CSV is valid UTF-8 BOM)
- `src/Controllers/SaleController.php` (store() transaction + FOR UPDATE via validate(lock:true)); `src/Models/Sale.php:346` (FOR UPDATE)
- `src/Services/CsvExporter.php` (BOM `\xEF\xBB\xBF`, Content-Disposition); `src/Services/ProfitCalculator.php` (unitCostEur port/customs allocation)
- Live URL: https://resseltrack-nu.vercel.app
</canonical_refs>

<code_context>
## Existing Code Insights
- Whole app is in place and prior phases verified incrementally; Phase 7 is the consolidated proof.
- Sale store() already locks (FOR UPDATE) + transacts → oversell returns null → rollback → redirect to /sales/create.
- CsvExporter prepends the UTF-8 BOM and streams as attachment.
</code_context>

<specifics>
## Specific Ideas
- Reuse the curl-based E2E approach used in P3/P4/P6 verifications (cookie jar, CSRF extraction, multipart upload, MinGW cygpath for file paths).
</specifics>

<deferred>
## Deferred Ideas
- Automated E2E suite (Playwright/etc.) — out of scope; manual/curl verification only.
- Load testing (Aiven connection ceiling) — deferred (v2/OPS).

No research questions — verification phase.
</deferred>

---

*Phase: 7-Production Verification*
*Context gathered: 2026-06-15*
