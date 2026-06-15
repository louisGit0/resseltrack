---
phase: 07-production-verification
status: passed
requirement: VERIF-01
verified_on: https://resseltrack-nu.vercel.app
date: 2026-06-15
---

# Phase 7 — Production Verification (VERIF-01)

**Verdict: PASS.** Every existing feature works on the live Vercel URL under real production conditions. Milestone **v1.0** is met. A full end-to-end dataset (account → product+image → USD lot purchase with port+customs → sale → order → profile change) was exercised live, then all test data was truncated (production DB left pristine).

## Feature-by-feature results

| # | Feature (VERIF-01) | Result | Live evidence |
|---|--------------------|--------|---------------|
| 1 | Registration + login | PASS | `POST /register` → 302 `/dashboard`; authenticated session used for the whole sweep (also verified P1/P3) |
| 2 | Logout invalidates session | PASS (ref P3) | Verified in Phase 3: `POST /logout` → 302 `/login`, protected route then redirects to `/login` |
| 3 | Product create + image | PASS | `POST /products` (multipart cover) → 302; product page renders `<img>` from `https://res.cloudinary.com/...` (also P4: display 200 + delete→404) |
| 4 | Batch purchase USD with port + customs | PASS | lot 100 USD, shipping 20, customs 10, rate 0.9, qty 10 → stored `unit_cost_eur = 11.7000` = ((100+20+10)·0.9)/10 — port+customs allocated, never 0.00 |
| 5 | Sale deducts stock | PASS | Sale qty 3 on a 10-unit product → DB stock = 7 (purchased 10 − sold 3) |
| 6 | Concurrent stock guard (oversell) | PASS | Sale qty 100 (> available 7) → 302 `/sales/create` (rejected, visible error), no sale row created, stock unchanged. Code-confirmed: `SaleController::store()` wraps `validate(lock:true)` + insert in `beginTransaction`/`commit`/`rollBack`; `Sale.php:346` appends `FOR UPDATE` |
| 7 | Sale appears on dashboard | PASS | `GET /dashboard` → HTTP 200 renders with the seeded sale/purchase data + charts |
| 8 | Order create (multi-line, multi-currency) | PASS | `POST /orders` with one line (USD, shipping 15, customs 5, qty 5) → 302 `/orders/1`; order listed. (Empty-order correctly rejected: "au moins un article".) |
| 9 | CSV export | PASS | `GET /export/purchases` → `Content-Type: text/csv; charset=utf-8`, `Content-Disposition: attachment; filename="achats.csv"`, first 3 bytes `ef bb bf` (UTF-8 BOM), header + record rows present |
| 10 | Profile update | PASS | `POST /profile` (name change, real email) → 302; DB user name changed "Verif Final" → "Profil Verifie OK" |
| 11 | Multi-currency FX reliability | PASS | USD purchase with no submitted rate → server fallback fetched a live rate from frankfurter.dev → correct EUR cost (P6); API-unreachable path blocks instead of writing 0.00 |

## Cross-cutting (verified in earlier phases, still live)
- Routing + static CDN assets (DEPLOY-01/02/03, P1)
- Aiven MySQL over TLS + one-shot migration (DB-01/02/03, P2)
- Persistent MySQL sessions + Secure/HttpOnly/SameSite cookie + CSRF (SESS-01..04, P3)
- HSTS + production boot-safety gate + secrets audit (SEC-01..04, P5)
- `/health` + GitHub Actions keep-alive (prevents Aiven idle power-off)

## Method
Live curl E2E (cookie jar + CSRF extraction + multipart upload), DB assertions via the app's own `Database::connection()` against Aiven, code review for the FOR UPDATE lock. No new code in this phase. Test data created then `TRUNCATE`d — production DB has 0 rows.

## Known follow-ups (out of scope, tracked — not v1.0 blockers)
- `ProductController::destroy()` does not purge a deleted product's Cloudinary images (orphans) — spawned task.
- `OrderController::persistLines()` has the same FX-rate handling as purchases but no server-side fallback (documented in Phase 6, D-06).
- Deferred v2/OPS + v2/HARD items (ProxySQL, custom domain, CSRF rotation, rate limiting) remain deferred.

**VERIF-01 closed. Milestone v1.0 complete — ResellTrack is fully operational on Vercel for real users.**
