---
phase: 10-product-url-auto-fill
plan: 02
subsystem: products / import
tags: [import, ssrf, csrf, json-endpoint, vanilla-js, view]
requires:
  - "App\\Services\\ProductImportService::fromUrl() (Plan 10-01)"
  - "App\\Core\\Controller::json()"
  - "App\\Core\\Csrf::validate() / Csrf::field()"
provides:
  - "POST /products/fetch-url → ProductController::fetchUrl() (JSON {ok,...})"
  - "products/form.php URL input + 'Remplir depuis URL' button + data: URI preview"
  - "app.js initUrlAutofill() fetch-populate-preview handler"
affects:
  - "src/Controllers/ProductController.php"
  - "public/index.php"
  - "src/Views/products/form.php"
  - "public/assets/js/app.js"
tech-stack:
  added: []
  patterns:
    - "First JSON-returning controller action — uses the existing Controller::json() helper"
    - "Form-encoded POST (URLSearchParams) so Csrf::validate() reads $_POST['_csrf']"
    - "Static route registered before the parameterized {id} route (first-match-wins shadowing guard)"
    - "Client-side fill-empty (non-destructive) + data: URI image preview (no CSP change)"
key-files:
  created: []
  modified:
    - "src/Controllers/ProductController.php"
    - "public/index.php"
    - "src/Views/products/form.php"
    - "public/assets/js/app.js"
decisions: [D-03, D-05, D-05a, D-06, D-07, T-10-SH]
metrics:
  duration: ~8m
  tasks: 2
  files: 4
  completed: 2026-06-16
---

# Phase 10 Plan 02: Product URL Auto-fill — Endpoint, Route, Form & JS Summary

Wired the user-facing "Remplir depuis URL" flow on top of the Plan 10-01 `ProductImportService`: a thin `fetchUrl()` JSON action, its correctly-ordered route, the form's URL input/button/data-URI preview, and the `initUrlAutofill()` client handler that posts form-encoded, fills only empty fields, and degrades to a French manual-entry message on failure.

## What Was Built

### Task 1 — `fetchUrl()` action + route (commit `85291a5`)
- **`src/Controllers/ProductController.php`**: added `use App\Services\ProductImportService;` (Core → Models → Services order) and `public function fetchUrl(): void`. The action is thin transport — `Csrf::validate()` first line (reads the form-encoded `$_POST['_csrf']`), reads `$url = trim((string) ($_POST['url'] ?? ''))`, returns `{ok:false, message:'Aucune URL fournie.'}` on empty input (`json()` exits, no fallthrough), otherwise delegates to `(new ProductImportService())->fromUrl($url)` and responds via `$this->json(...)`. Auth is already enforced by the constructor. It is the **first controller action in the codebase to call `Controller::json()`**.
- **`public/index.php`**: registered `$router->post('/products/fetch-url', [ProductController::class, 'fetchUrl']);` immediately after `POST /products` (store) and before `GET /products/{id}/edit` and `POST /products/{id}` (update). The router compiles `{id}` to `([^/]+)` with first-match-wins dispatch, so an out-of-order static route would be swallowed by `update()` → "Produit introuvable" (T-10-SH). Verified by line order: `fetch-url` at line 164, `POST /products/{id}` (update) at line 166. The CSP block was left untouched.

### Task 2 — Form affordance + client handler (commit `2d835e9`)
- **`src/Views/products/form.php`**: added an "Importer depuis une URL produit" block (`col-12`) as the first row item inside the existing `<form>` — **not gated on `$isEdit`**, so it renders on create (the primary use case) and edit. Bootstrap input-group with `<input type="url" id="import-url">` + `<button type="button" id="fetch-url-btn">Remplir depuis URL</button>`, a `<div id="import-status" class="form-text">` (with a best-effort hint), and an `<img id="import-preview" class="product-thumb mt-2 d-none" alt="Aperçu">`. Reuses the already-present `Csrf::field()` hidden token (no second token).
- **`public/assets/js/app.js`**: added `function initUrlAutofill()` mirroring the "Taux du jour" fetch idiom (element lookup + early-return guard, async click handler, status feedback, try/catch/finally). On click it posts `application/x-www-form-urlencoded` (`new URLSearchParams({ url, _csrf })`) to `/products/fetch-url` — form-encoded is mandatory or `Csrf::validate()` returns 419. On `ok`, it fills **only empty** fields (`!field.value.trim()` guard): `name`, and `market_price_new` from `converted_eur ?? price`; sets `preview.src` to the `data:` URI and unhides it; shows a success status. On `!ok`/network error it shows the French manual-entry message. Registered in `DOMContentLoaded`. Scraped data is assigned via `.value`/`.src` only — never `innerHTML` (T-10-07).

## Decisions Honored
- **D-03**: scraped price (`converted_eur ?? price`) pre-fills `market_price_new`.
- **D-05 / D-05a**: image rendered as a `data:` URI thumbnail (from the service), preview-only, not persisted, no CSP change.
- **D-06**: non-destructive — only empty `name` / `market_price_new` are filled; pre-typed values untouched.
- **D-07**: URL input + button + JSON endpoint; French failure message; all fields stay editable, no data loss.
- **T-10-SH**: static route ordered before `/products/{id}` (verified by line order).
- **T-10-06**: CSRF enforced server-side (`Csrf::validate()` first line) + form-encoded client body.

## Verification
- `php -l src/Controllers/ProductController.php` → clean.
- `php -l public/index.php` → clean.
- `php -l src/Views/products/form.php` → clean.
- `node --check public/assets/js/app.js` → exit 0.
- Route ordering: `products/fetch-url` (line 164) strictly before `POST /products/{id}` update (line 166).
- Form ids `import-url` / `fetch-url-btn` / `import-preview` present and ungated.
- `initUrlAutofill` both defined (line 636) and registered in `DOMContentLoaded` (line 709); `initStarRating` / `initQuickRate` still present (no regression).
- Handler posts to `/products/fetch-url` with `application/x-www-form-urlencoded` + `URLSearchParams` including `_csrf`; `.value.trim()` fill-empty guards present.
- `vendor/bin/phpunit` → **OK (41 tests, 64 assertions)** — no regression.

## Deviations from Plan
None — plan executed exactly as written.

## Known Stubs
None. The endpoint, route, form affordance, and client handler are fully wired to the live `ProductImportService`. (AliExpress full extraction is documented as best-effort/low-probability in 10-RESEARCH; the contractual default is the French manual-entry message, not a stub.)

## Manual Verification (deferred to live/operator — out of scope for this plan)
- End-to-end browser check: paste a public product URL → fill-empty → data: URI preview → manual-entry message on a blocked page (Success Criteria 1-3). Route resolves to `fetchUrl()` (JSON), not a redirect.

## Self-Check: PASSED
- All 4 modified files present on disk; SUMMARY.md created.
- Task commits `85291a5` and `2d835e9` exist in git history.
