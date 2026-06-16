# Phase 10: Product URL Auto-fill - Context

**Gathered:** 2026-06-16
**Status:** Ready for planning

<domain>
## Phase Boundary

Pasting a **public product URL** into the product form and clicking "Remplir depuis URL" triggers a **server-side best-effort scrape** (curl + HTML parsing) that pre-fills title, price, and an image preview. The form stays fully usable by manual entry when scraping fails or is blocked. This is **IMPORT-01**.

New: `src/Services/ProductImportService.php` (curl + parsing, modeled on `ExchangeRateService`), a `ProductController::fetchUrl()` action returning JSON, and a `POST /products/fetch-url` route. No schema change. No new packages (curl + DOMDocument are built-in).

**Out of scope (explicit):** scraping **private/authenticated order pages** (AliExpress/Vinted logged-in order history) — infeasible without the user's session + anti-bot, decided earlier. Only public product pages, best-effort.

</domain>

<decisions>
## Implementation Decisions

### Target sites
- **D-01:** **AliExpress-specific parsing first** (initial target), with a **generic Open Graph fallback** (`og:title`, `og:image`, `og:price:amount` / `product:price:amount` + `og:price:currency`) for any other public `http(s)` URL. Best-effort: if neither yields data, return a "remplissage indisponible" signal.
- **D-02 (SSRF guard — mandatory):** the user-supplied URL is fetched server-side, so it MUST be constrained: accept only `http`/`https` schemes; reject URLs that resolve to private/loopback/link-local/metadata IP ranges (127/8, 10/8, 172.16/12, 192.168/16, 169.254/16, ::1, etc.); cap redirects and follow them through the same guard; short curl timeout (~5-8s) like `ExchangeRateService`. This is the headline security control for the phase.

### Price mapping
- **D-03:** The scraped price pre-fills **`market_price_new`** (prix constaté neuf), editable before save.
- **D-04:** **Currency conversion to EUR when a currency is detected** (e.g. `og:price:currency`, a symbol, or AliExpress markup → USD/CNY) using the existing `ExchangeRateService`. Best-effort: if the currency cannot be reliably detected or the FX call fails, pre-fill the **raw numeric value** and flag to the user that the currency should be verified (never silently mislabel). Mirrors the Phase 6 "no silent wrong value" principle.

### Image handling
- **D-05:** **Preview only** — the scrape returns an image; the form shows it as a **preview thumbnail**, but it is NOT auto-persisted. The user uploads the image manually via the existing cover `image` file field if they want it. **No Cloudinary upload-by-URL, no orphaned assets.**
  - **D-05a (resolved from RESEARCH Open Question #1):** to show a preview WITHOUT a CSP change (current `img-src` is `'self' data: res.cloudinary.com` — it would block an external AliExpress CDN URL), the server fetches `og:image` **through the same SSRF guard (D-02)**, size-caps it (reject if too large, e.g. > ~1.5 MB), and returns it as a **base64 `data:` URI** in the JSON (`image_url` is a `data:` URI, allowed by the existing `data:` in `img-src`). Best-effort: if the image fetch fails or is too large, simply return no preview — never an error, never a CSP violation. No CSP edit.

### Overwrite behavior
- **D-06:** **Non-destructive** — auto-fill only populates **empty** form fields. Anything the user already typed (name/price) is left untouched. Implemented client-side in the populate step.

### Trigger / UX
- **D-07:** A URL text input + "Remplir depuis URL" button in `products/form.php`. The button calls `POST /products/fetch-url` (JSON body or form-encoded URL + CSRF) → `ProductController::fetchUrl()` returns JSON `{ok, name?, price?, currency?, converted_eur?, image_url?, message?}`. Minimal JS in `app.js` calls it, fills empty fields (D-06), shows the image preview (D-05), and on failure shows a French message like "Remplissage automatique indisponible — veuillez saisir manuellement". All fields remain editable; no data loss.

### Claude's Discretion
- Exact AliExpress selectors / JSON-LD vs meta-tag extraction strategy (best-effort; planner/research decides the most robust parse path).
- Whether `fetchUrl()` is a JSON endpoint returning meta vs a partial — JSON is the locked shape (D-07).
- Exact SSRF IP-range checks implementation (resolve host, compare against private ranges) — must satisfy D-02.
- Loading/disabled state of the button while fetching.

</decisions>

<canonical_refs>
## Canonical References

**Downstream agents MUST read these before planning or implementing.**

### Phase scope & requirements
- `.planning/ROADMAP.md` §"Phase 10: Product URL Auto-fill" — goal, success criteria, new files, route-ordering note
- `.planning/REQUIREMENTS.md` — IMPORT-01 (v2.0 section); note the explicit Out-of-Scope (private order-page scraping)

### Reuse / mirror
- `src/Services/ExchangeRateService.php` — the curl-with-timeout + `error_log` + null-on-failure pattern to mirror for `ProductImportService`; also the FX `latest()` call for D-04 currency conversion
- `src/Controllers/ProductController.php` — controller conventions; `fetchUrl()` returns JSON via the base `Controller::json()` helper; `validate()`/`store()` unaffected (auto-fill is client-side population before submit)
- `src/Views/products/form.php` — where the URL input + "Remplir depuis URL" button + image preview go (near the name/market-price fields)
- `public/assets/js/app.js` — where the fetch-and-populate JS goes (mirror the existing fetch usage: the "Taux du jour" FX button calls api.frankfurter.dev; same pattern for calling /products/fetch-url)
- `public/index.php` — register `POST /products/fetch-url` BEFORE the bare parameterized `/products/{id}` POST (it is a static segment, but keep it grouped with the static product routes per the route-ordering rule)
- `src/Core/Controller.php` — `json()` response helper for the endpoint
- `src/Core/Csrf.php` — the fetch endpoint is a POST → `Csrf::validate()` (token sent from the JS)

No external specs/ADRs — requirements fully captured in the decisions above.

</canonical_refs>

<code_context>
## Existing Code Insights

### Reusable Assets
- **`ExchangeRateService`** — the proven serverless-safe curl pattern (5s timeout, `!== 200` → null, `error_log` on failure). `ProductImportService` mirrors it; D-04 reuses its `latest()` for currency conversion.
- **Front-end fetch precedent** — `app.js` already calls `api.frankfurter.dev` for the "Taux du jour" button; the /products/fetch-url call follows the same fetch + populate idiom.
- **`Controller::json()`** — JSON response helper for `fetchUrl()`.
- **DOMDocument / libxml** — built-in PHP HTML parsing (no new package); JSON-LD or meta-tag extraction.

### Established Patterns
- Services do I/O via curl, return typed best-effort results, log failures, never throw into the request path; `final` classes, `declare(strict_types=1)`.
- Route ordering: static segments before bare parameterized; `/products/fetch-url` groups with `/products/create`.
- POST endpoints validate CSRF; controllers stay thin and delegate parsing to the service.

### Integration Points
- **`POST /products/fetch-url`** → `ProductController::fetchUrl()` → `ProductImportService::fromUrl($url)` (SSRF-guarded fetch + parse + optional FX convert) → JSON.
- **`products/form.php`** URL input + button + preview; **`app.js`** fetch + fill-empty + preview + failure message.
- **No DB / schema / model changes**; no CSP changes (image is preview-only).

</code_context>

<specifics>
## Specific Ideas

- Best-effort is the contract: a blocked/non-200/captcha/JS-only/unrecognised page must degrade gracefully to a clear French "saisie manuelle" message with all fields editable and zero data loss — never a hard error or a half-broken form.
- Security first: the server fetching a user-supplied URL is an SSRF vector — the private-IP/scheme guard (D-02) is the most important non-functional requirement of this phase.
- Don't silently mislabel currency: if conversion isn't trustworthy, pre-fill the raw number and tell the user (Phase 6 reliability principle).

</specifics>

<deferred>
## Deferred Ideas

- Additional site-specific parsers beyond AliExpress (Amazon, Vinted public listings) — the OG fallback covers them best-effort; dedicated parsers are future work.
- Cloudinary upload-by-URL for the scraped image (auto-owning the image) — deliberately deferred (D-05 chose preview-only to avoid CSP/orphan complexity).
- Scraping private authenticated order pages — explicitly OUT OF SCOPE (infeasible).

None block Phase 10.

</deferred>

---

*Phase: 10-product-url-auto-fill*
*Context gathered: 2026-06-16*
