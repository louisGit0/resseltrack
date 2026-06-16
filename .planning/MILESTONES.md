# Milestones

## v2.0 Suppliers, ratings & auto-fill (Shipped: 2026-06-16)

**Phases completed:** 3 phases (8-10), 14 plans, 18 tasks — all verified live on https://resseltrack-nu.vercel.app

**Delivered:** Supplier directory + order linking, product ratings, and best-effort URL auto-fill, layered on the live v1.0 Vercel deployment.

**Key accomplishments:**

- **Fournisseurs (SUP-01/SUP-02):** per-user Supplier CRUD (model with linked-orders COUNT, CSRF/ownership-guarded controller, 6 routes, nav entry, table + star widget); orders link to a saved supplier via a dropdown + "Autre" free-text fallback with id+name dual-write — fully backward compatible (`ON DELETE SET NULL` keeps the name on supplier delete).
- **Cloudinary cleanup (OPS-06):** `ProductController::destroy()` collects cover + gallery paths before the cascading DB delete, dedupes, and purges each from Cloudinary best-effort (logged, never blocks).
- **Product ratings (RATE-01):** 1-5 rating + comment on products via the edit form AND one-click quick-rate on the detail page (`POST /products/{id}/rate` + `setRating()` that never clobbers the comment); list badge + detail display; reuses the `initStarRating()` widget.
- **URL auto-fill (IMPORT-01):** SSRF-guarded server-side product-page importer (resolve → validate every IP → `CURLOPT_RESOLVE` pin → manual redirect re-guard → protocol allowlist) with AliExpress + Open-Graph/JSON-LD parsing, best-effort EUR conversion, and a `data:`-URI image preview (no CSP change). Covered by 14 network-free unit tests.

**Verification:** every phase verified end-to-end on the live URL (08/09/10-VERIFICATION.md, all `status: passed`); SSRF guard proven live (internal IPs rejected) + by unit tests. All test data removed after each verification — production DB pristine. Schema changes applied idempotently to Aiven via `bin/migrate.php`.

**Tech notes:** no new Composer packages; curl + DOMDocument built-ins. One recurring lesson: `bin/migrate.php` splits `sql/schema.sql` on `;`, so comments must stay semicolon-free (fixed in Phase 8).

---
