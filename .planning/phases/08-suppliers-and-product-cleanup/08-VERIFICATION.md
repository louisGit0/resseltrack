---
phase: 08-suppliers-and-product-cleanup
status: passed
requirements: [SUP-01, SUP-02, OPS-06]
verified_on: https://resseltrack-nu.vercel.app
date: 2026-06-15
---

# Phase 8 — Verification (SUP-01, SUP-02, OPS-06)

**Verdict: PASS.** All three requirements verified end-to-end on the live Vercel URL after applying the schema to Aiven. Verification data was created live then fully removed (test user deleted → CASCADE); production DB left pristine.

## Goal-backward check

Phase goal: *users can manage a personal supplier list (rated), link orders to suppliers from a dropdown, and product deletion no longer leaves orphaned images on Cloudinary.* — **met.**

| Requirement | Result | Live evidence |
|-------------|--------|---------------|
| SUP-01 — Suppliers CRUD (name, URL, rating 1-5, comment), per-user | PASS | Create/delete worked; `/suppliers` 200 with "Fournisseurs" nav; empty-name create rejected (302 back, no row); all queries `:uid`-scoped (DB-confirmed) |
| SUP-02 — orders optionally link a supplier, backward compatible | PASS | Selected supplier → `supplier_id=1` + `supplier='Fournisseur Test P8'` (dual-write D-02); "Autre" → id null + free text; no-supplier accepted; deleting linked supplier → `supplier_id NULL`, name kept (ON DELETE SET NULL, D-08), order preserved |
| OPS-06 — product delete purges Cloudinary cover + gallery | PASS | Product with Cloudinary cover + gallery deleted → DB product + image rows gone; **cover HEAD → 404** (purged with invalidate); best-effort, non-blocking |

## Schema (Task 1)
`php bin/migrate.php` against Aiven: 1st run exit 0, **2nd run exit 0 (idempotent)**. `orders.supplier_id` nullable int unsigned + `fk_orders_supplier ON DELETE SET NULL`; `suppliers` table + `fk_suppliers_user`. Applied BEFORE deploy (additive/back-compat → zero-downtime). One deviation fixed: a `;` in a schema.sql comment broke migrate.php's splitter (commit `6c8dcd7`).

## Method
Authenticated live curl E2E (cookie jar + `_csrf` extraction) against the public alias, DB assertions via the app's own Aiven connection, Cloudinary HEAD check for the purge. Deploy `dpl_5Dh83iWc8fNUkSLtFE3ceTcA1V9b` (commit `6c8dcd7`) READY in production. No `Base table / Unknown column / SQLSTATE` errors in the suppliers/supplier_id paths. All verification rows removed afterward.

## Not separately exercised (low-risk, covered by design)
Supplier *edit* (rating change/clear-to-unrated) and explicit cross-account isolation — same `validate()` + `Supplier::find($id, Auth::id())` ownership path proven by the create/delete/validation checks and `:uid` scoping. Optional browser confirmation only.

**Phase 8 complete — SUP-01, SUP-02, OPS-06 shipped and verified in production.**
