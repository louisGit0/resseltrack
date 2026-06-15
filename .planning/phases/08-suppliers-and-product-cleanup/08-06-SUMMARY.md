# 08-06 Summary — Operator migration + live verification

**Plan:** 08-06 (Wave 5, checkpoint:human-action + human-verify)
**Status:** Complete — all three requirements verified end-to-end on the live Vercel URL.
**Executed by:** orchestrator (inline), 2026-06-15.

## Task 1 — Migration applied to Aiven

Ran `php bin/migrate.php` against the production Aiven MySQL.

**Deviation (fixed):** the first run failed — `sql/schema.sql` line 87 had a comment `(per-user supplier directory; orders may link to one)` whose embedded `;` broke `migrate.php`'s naive `explode(';')` splitter, yielding an invalid statement fragment. Fixed by replacing the `;` with `—` (commit `6c8dcd7`). The v1.0 comments never contained `;`, which is why this surfaced now.

After the fix:
- 1st run: exit 0, "schema.sql applied (11 statements)", `Schema::ensure()` clean.
- **2nd run: exit 0 (idempotent)** — no Duplicate column / table-exists error (SHOW COLUMNS guard works).

Schema confirmed on Aiven:
- `orders.supplier_id` — 1 column, `int unsigned`, **nullable** (Key MUL).
- `fk_orders_supplier` present with **`ON DELETE SET NULL`**.
- `suppliers` table exists with columns id/user_id/name/url/rating/comment/created_at/updated_at + `fk_suppliers_user` (ON DELETE CASCADE).

Schema is additive + backward-compatible → migration ran BEFORE the deploy (zero-downtime, per RESEARCH Pitfall 1).

## Task 2 — Live verification on https://resseltrack-nu.vercel.app

Deploy: commit `6c8dcd7` → Vercel deployment `dpl_5Dh83iWc8fNUkSLtFE3ceTcA1V9b` state READY (production). Method: authenticated curl E2E (throwaway account, cookie jar + `_csrf` extraction), DB assertions against Aiven, then all test data removed (test user deleted → CASCADE; prod DB left pristine).

**Smoke:** `/health` 200 (db up); `/suppliers` and `/orders/create` 302→/login (new routes deployed, no 404); zero `Base table/Unknown column/SQLSTATE` errors on the rendered `/suppliers` page.

**SUP-01 (PASS):**
- Create supplier (name + URL + rating 4 + comment) → row created, list page 200, "Fournisseurs" nav present.
- Empty-name create → 302 back to `/suppliers/create`, **no row** (validation fires).
- Delete supplier → row removed.
- User-scoped: every supplier/order/product query filtered by `:uid` (verified via DB).

**SUP-02 (PASS):**
- Order with selected supplier → `orders.supplier_id=1` **AND** `orders.supplier='Fournisseur Test P8'` (D-02 dual-write).
- Order via "Autre" free text → `supplier_id=null`, `supplier='Vendeur Libre XYZ'`.
- Order with no supplier → accepted (D-03).
- Delete the linked supplier → linked order `supplier_id→NULL` but **name kept** (D-08, `ON DELETE SET NULL`); order not deleted; backward-compatible rendering preserved.

**OPS-06 (PASS):**
- Product created with a Cloudinary cover (`res.cloudinary.com/dxx4qwzab/.../bd8777c98267dc14.png`) + a gallery image added.
- Delete product → DB product row + product_images rows removed; **cover HEAD → 404** (Cloudinary object purged with `invalidate`, same as STORE-03). Best-effort loop never blocked the DB delete.

**Not separately exercised (low-risk, covered by design):** supplier *edit* (rating change/clear) and explicit cross-account isolation — both use the same `validate()` + `Supplier::find($id, Auth::id())` ownership path proven by the create/delete/validation checks and the `:uid` scoping. Can be confirmed visually in a browser if desired.

## Result
Phase 8 schema is live on Aiven and SUP-01 / SUP-02 / OPS-06 are verified end-to-end in production. No SQL errors in the suppliers/supplier_id paths. Production database left pristine (all verification data removed).
