# Phase 10: Product URL Auto-fill - Pattern Map

**Mapped:** 2026-06-16
**Files analyzed:** 6 (2 new, 4 modified)
**Analogs found:** 5 strong / 6 (1 file — the SSRF guard portion of the new service — has no codebase analog; use RESEARCH excerpts)

> Scope: IMPORT-01 only. No private/authenticated order-page scraping. No new Composer packages (curl + DOMDocument + filter_var are bundled).

## File Classification

| New/Modified File | Role | Data Flow | Closest Analog | Match Quality |
|-------------------|------|-----------|----------------|---------------|
| `src/Services/ProductImportService.php` | service | request-response (outbound HTTP fetch + transform) | `src/Services/ExchangeRateService.php` | role+flow match (curl I/O); **SSRF guard = no analog → RESEARCH** |
| `tests/ProductImportServiceTest.php` | test | n/a (pure unit) | `tests/ProfitCalculatorTest.php` + `tests/ExchangeRateServiceTest.php` | exact (test structure) |
| `src/Controllers/ProductController.php` (+`fetchUrl()`) | controller | request-response (JSON) | `ProductController::rate()` (thin POST shape) + `Controller::json()` | role-match; **first JSON action — no existing `json()` caller** |
| `public/index.php` (+route) | route registration | request-response | existing `/products/*` block (lines 159-170) | exact |
| `src/Views/products/form.php` (+input/button/preview) | view | request-response (UI affordance) | "Vérifier les prix" block (74-89) + cover `<img>` (43-48) + market input (54-61) | role-match |
| `public/assets/js/app.js` (+`initUrlAutofill()`) | hook (client) | request-response (fetch) | `initPurchaseForm()` "Taux du jour" fetch (65-85) | flow-match (GET→POST differs) |

---

## Pattern Assignments

### `src/Services/ProductImportService.php` (service, request-response)

**Analog:** `src/Services/ExchangeRateService.php` — mirror the class shape, curl options, status/empty checks, `error_log` + null-on-failure. **The SSRF guard, redirect re-guard, and DOMDocument parse have NO codebase analog** — copy them from `10-RESEARCH.md` Code Examples (lines 232-293 SSRF/fetch, 296-326 parse, 329-344 convert).

**Class skeleton + conventions** (`ExchangeRateService.php` lines 1-23):
```php
<?php
declare(strict_types=1);

namespace App\Services;

/** <file-level PHPDoc: purpose + best-effort contract + "never throws into request path"> */
final class ProductImportService
{
    // const ENDPOINT-style config goes here (e.g. timeouts, max bytes, blocked IPs)
```
Conventions to carry: `final class`, `declare(strict_types=1)`, namespace `App\Services`, file-level PHPDoc, `private const UPPER_SNAKE` for config (matches `ExchangeRateService::ENDPOINT` line 17).

**Curl I/O pattern to mirror** (`ExchangeRateService.php` lines 32-64) — this is the thin network method; replicate the `curl_init` → `curl_setopt_array` → `curl_exec` → status/empty guard → `error_log` → `return null` flow, **adding** the SSRF options from RESEARCH:
```php
$ch  = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 5,
    CURLOPT_CONNECTTIMEOUT => 5,
]);
$body = curl_exec($ch);

if ($body === false) {
    error_log('ExchangeRateService: curl error for ' . $from . '->' . $to . ': ' . curl_error($ch));
    curl_close($ch);
    return null;
}
$status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
curl_close($ch);
if ($status !== 200) {
    error_log('ExchangeRateService: HTTP ' . $status . ' for ' . $from . '->' . $to);
    return null;
}
```
**Net-new vs analog (from `10-RESEARCH.md`):** add `CURLOPT_FOLLOWLOCATION => false`, `CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS`, `CURLOPT_RESOLVE => ["host:port:validatedIP"]` (IP pinning), `CURLOPT_MAXFILESIZE`, a browser `CURLOPT_USERAGENT` + `Accept-Language`, and the manual redirect re-guard loop. See RESEARCH lines 260-293.

**FX reuse for D-04** (`10-RESEARCH.md` lines 329-344; calls existing `ExchangeRateService::latest()` signature at `ExchangeRateService.php` line 23 `public function latest(string $from, string $to = 'EUR'): ?float`):
```php
$rate = (new \App\Services\ExchangeRateService())->latest($cur, 'EUR'); // ?float; null → keep raw + needs-verify
```

**Pure methods to expose (load-bearing — VALIDATION requires network-free testability):** `isPublicIp(string $ip): bool`, `parse(string $html): array`, `convert(?float $price, ?string $currency, ?float $rate): array`. Keep the curl/DNS method thin and separate (exercised manually). See `10-VALIDATION.md` line 23.

---

### `tests/ProductImportServiceTest.php` (test, pure unit)

> **PATH CORRECTION (load-bearing):** the spec named `tests/Services/ProductImportServiceTest.php`, but the repo convention is **flat `tests/` with namespace `Tests\`** (verified: `tests/ProfitCalculatorTest.php`, `tests/ExchangeRateServiceTest.php`). `phpunit.xml` line 9 scans `<directory>tests</directory>` (recursive, so a subdir works mechanically) and composer maps `Tests\ → tests/`. **Match the established convention: write `tests/ProductImportServiceTest.php` with `namespace Tests;`** — not a `Services/` subdir.

**Analog (structure):** `tests/ProfitCalculatorTest.php` (pure-logic unit tests). **Analog (sibling service / "why I/O isn't tested" note):** `tests/ExchangeRateServiceTest.php`.

**Header + conventions** (`ProfitCalculatorTest.php` lines 1-13) — note: **PHPUnit 11 but NO attributes, NO data providers**; the repo uses the `test*` method-name convention + `extends TestCase` + `assertSame`:
```php
<?php
declare(strict_types=1);

namespace Tests;

use App\Services\ProductImportService;
use PHPUnit\Framework\TestCase;

final class ProductImportServiceTest extends TestCase
{
```

**Test-method pattern (AAA, inline-comment expected value, `assertSame`)** (`ProfitCalculatorTest.php` lines 15-37):
```php
public function testUnitCostInEuroNoConversion(): void
{
    // (30 + 5 + 0) * 1 / 20 = 1.75
    $this->assertSame(1.75, ProfitCalculator::unitCostEur(30.00, 5.00, 0.00, 1.0, 20));
}

public function testUnitCostRejectsNonPositiveQuantity(): void
{
    $this->expectException(\InvalidArgumentException::class);
    ProfitCalculator::unitCostEur(10.0, 0.0, 0.0, 1.0, 0);
}
```

**Instance-method + "I/O paths verified live" note pattern** (`ExchangeRateServiceTest.php` lines 9-34) — mirror the docblock explaining curl/DNS is verified by smoke tests, and instantiate the service for pure-method calls:
```php
/**
 * Unit tests for ... pure methods (no network I/O).
 * The curl/DNS failure paths are verified by post-deploy smoke tests
 * (documented in 10-VALIDATION.md) ...
 */
...
$service = new ProductImportService();
$this->assertTrue($service->isPublicIp('8.8.8.8'));
```

**Coverage to write (from `10-VALIDATION.md` lines 34-40):** `isPublicIp` rejects 127/8, 10/8, 172.16/12, 192.168/16, 169.254.169.254, ::1, fc00::/7 and accepts public; `parse` extracts name/price/currency/image from fixture HTML; `parse` on empty/garbage → `ok:false`/empty (no throw); `convert` converts with injected rate, and flags needs-verify when currency/rate is null.

---

### `src/Controllers/ProductController.php` — new `fetchUrl()` (controller, JSON request-response)

> **No existing controller calls `Controller::json()`** (grep over `src/Controllers` returned zero hits). `fetchUrl()` is the first JSON-returning action. Use the `json()` helper signature directly and the `rate()` action as the thin-POST template.

**`Controller::json()` helper** (`src/Core/Controller.php` lines 46-52) — the response primitive (sets header, `JSON_UNESCAPED_UNICODE`, `exit`):
```php
protected function json(mixed $payload, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}
```

**Thin-POST action template** (`ProductController::rate()` lines 442-460) — copy the shape: `Csrf::validate()` first line, read `$_POST`, delegate, respond. (`rate()` redirects; `fetchUrl()` returns `$this->json(...)` instead.):
```php
public function rate(array $params): void
{
    Csrf::validate();
    $userId = Auth::id();
    ...
    $raw = trim((string) ($_POST['rating'] ?? ''));
    ...
}
```

**Target shape** (`10-RESEARCH.md` lines 347-356) — parameterless action (router passes `$params`, PHP ignores extra arg exactly like `store()` at line 248):
```php
public function fetchUrl(): void
{
    Csrf::validate();                                  // reads $_POST['_csrf']
    $url = trim((string) ($_POST['url'] ?? ''));
    if ($url === '') {
        $this->json(['ok' => false, 'message' => 'Aucune URL fournie.'], 200);
    }
    $this->json((new \App\Services\ProductImportService())->fromUrl($url));
}
```
Already imported in the file: `Csrf` (line 8), `Controller` (base, line 7). Add `use App\Services\ProductImportService;` next to the other service imports (lines 13-14) per the import-ordering convention (Core → Models → Services).

**Auth note:** `Auth::require()` is already called in `__construct()` (line 22) — the endpoint is auth-gated for free, reducing anonymous SSRF abuse surface.

---

### `public/index.php` — register `POST /products/fetch-url` (route registration)

**Analog:** the existing `/products/*` block (lines 159-170) and its ordering comment (line 159).

**Load-bearing constraint — route ordering:** `{id}` compiles to `([^/]+)` (`Router.php` lines 29-32) and dispatch is first-match-wins (`Router.php` lines 57-71). `POST /products/{id}` (update, line 165) would otherwise swallow `POST /products/fetch-url`. Register the new static route **after `POST /products` (store, line 163) and BEFORE `POST /products/{id}` (update, line 165)**:
```php
// Products — /products/create MUST stay before /products/{id} (match order)
$router->get('/products', [ProductController::class, 'index']);
$router->get('/products/create', [ProductController::class, 'create']);
$router->get('/products/{id}', [ProductController::class, 'show']);
$router->post('/products', [ProductController::class, 'store']);
$router->post('/products/fetch-url', [ProductController::class, 'fetchUrl']); // <-- ADD HERE (static, before {id})
$router->get('/products/{id}/edit', [ProductController::class, 'edit']);
$router->post('/products/{id}', [ProductController::class, 'update']);
```
(Mirror the existing inline ordering comment style at line 159 / line 161.)

**No CSP change needed:** `connect-src 'self'` (line 137) already allows the same-origin `fetch`; `img-src ... data: ...` (line 136) already allows the `data:` URI preview. Do **not** edit the CSP block (lines 131-138).

---

### `src/Views/products/form.php` — URL input + button + preview (view)

**Analogs in-file:**
- **Affordance row** ("Vérifier les prix" badges) — `form.php` lines 74-89 — the place/style for the import affordance (sits under the price section). Note it is currently gated by `$isEdit`; the import block should render on **create too** (it's the primary use case), so place it near the top (after the name field, lines 22-27) and do **not** gate it on `$isEdit`.
- **Market-price input (target field `market_price_new`)** — `form.php` lines 54-61 — input-group with `€` suffix; the JS fills this field. Note `name="market_price_new"` (line 57) is the JS selector target (D-03).
- **Cover image thumbnail `<img>`** — `form.php` lines 43-48 — copy the `<img class="product-thumb">` pattern for the preview placeholder:
```php
<img src="<?= e($product['image_path']) ?>" class="product-thumb" alt="">
```
- **CSRF field already present** — `form.php` line 18 `<?= \App\Core\Csrf::field() ?>` renders the hidden `input[name="_csrf"]` the JS reads. The import block lives **inside this same `<form>`** (opened line 17) so the token is in scope. Do not add a second token.
- **Name field (fill-empty target)** — lines 24-26 `name="name"` is the second JS target (D-06).

**Markup to add** (new — uses Bootstrap input-group like lines 56-61, IDs consumed by `initUrlAutofill()`):
```php
<div class="col-12">
    <label class="form-label">Importer depuis une URL produit</label>
    <div class="input-group">
        <input type="url" id="import-url" class="form-control" placeholder="https://… (page produit publique)">
        <button type="button" id="fetch-url-btn" class="btn btn-outline-secondary">Remplir depuis URL</button>
    </div>
    <div id="import-status" class="form-text"></div>
    <img id="import-preview" class="product-thumb mt-2 d-none" alt="Aperçu">
</div>
```
(Use `e()` for any echoed scraped data per the escaping convention; the JS sets `.value`/`.src` directly, never innerHTML.)

---

### `public/assets/js/app.js` — `initUrlAutofill()` (client hook, fetch)

**Analog:** `initPurchaseForm()` "Taux du jour" fetch button — `app.js` lines 65-85. Mirror the **structure**: element lookup with early-return guard, `async` click handler, status-text feedback, `try/catch` with a French network-error message.

**Element-lookup + guard idiom** (`app.js` lines 22-34):
```js
const form = document.getElementById('purchase-form');
if (!form) return;
...
const rateBtn = document.getElementById('fetch-rate');
const rateStatus = document.getElementById('rate-status');
```

**Fetch + status + try/catch idiom** (`app.js` lines 65-84):
```js
rateBtn.addEventListener('click', async function () {
    ...
    rateStatus.textContent = 'Chargement…';
    try {
        const res = await fetch('https://api.frankfurter.dev/v1/latest?from=' + from + '&to=EUR');
        const data = await res.json();
        ...
    } catch (e) {
        rateStatus.textContent = 'Erreur réseau, saisissez le taux manuellement.';
    }
});
```

**Net-new vs analog (from `10-RESEARCH.md` lines 360-398):** the existing fetch is a 3rd-party **GET with no body/CSRF**; the new one is a same-origin **POST, `application/x-www-form-urlencoded`, with `_csrf`** (required because `Csrf::validate()` reads `$_POST['_csrf']` — see Shared Patterns). Body via `new URLSearchParams({ url, _csrf })`. Also add: fill-ONLY-empty logic (D-06: `if (... && !field.value.trim())`), `data:` URI preview (`preview.src = d.image_url`), and the French failure message `'Remplissage automatique indisponible — veuillez saisir manuellement.'`.

**Wiring** — register in the `DOMContentLoaded` block (`app.js` lines 652-664) alongside the other `init*()` calls:
```js
document.addEventListener('DOMContentLoaded', function () {
    initPurchaseForm();
    ...
    initUrlAutofill();   // <-- ADD
});
```

---

## Shared Patterns

### CSRF (form-encoded `_csrf` — load-bearing)
**Source:** `src/Core/Csrf.php` lines 37-45 (`validate()`) + line 39 `$token = $_POST['_csrf'] ?? null;`
**Apply to:** the new `fetchUrl()` action (server) and `initUrlAutofill()` (client).
```php
public static function validate(): void
{
    $token = $_POST['_csrf'] ?? null;       // reads $_POST ONLY
    if (!self::isValid($token)) {
        http_response_code(419);
        echo 'Jeton CSRF invalide ou expiré. Veuillez recharger la page.';
        exit;
    }
}
```
**Consequence:** the JS MUST send `application/x-www-form-urlencoded` (NOT a JSON body) or `$_POST` is empty → guaranteed 419. Read the token from the existing `input[name="_csrf"]` (rendered by `Csrf::field()` in `form.php` line 18).

### Best-effort service contract (never throw into the request path)
**Source:** `src/Services/ExchangeRateService.php` (returns `?float`, `error_log` + `return null` on every failure) + CONVENTIONS ("Services return typed best-effort results, log failures, never throw").
**Apply to:** all of `ProductImportService` — every failure mode (bad scheme, SSRF reject, non-200, empty, unparseable, FX fail) returns a typed array (`['ok' => false, 'message' => '…']`), logs with `error_log`, never throws. A blocked/empty AliExpress page is the **expected** outcome, not an error.

### JSON response envelope
**Source:** `src/Core/Controller.php` lines 46-52 (`json()`), shape locked by D-07.
**Apply to:** `fetchUrl()` response: `{ok, name?, price?, currency?, converted_eur?, image_url?, message?}` (`10-RESEARCH.md` line 140).

### SSRF guard (NO codebase analog — copy the canonical design)
**Source:** `10-RESEARCH.md` lines 232-293 (resolve → validate every IP via `filter_var(..., FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)` + explicit metadata/`::ffff:` denial → pin curl via `CURLOPT_RESOLVE` → `FOLLOWLOCATION` off → manual redirect re-guard → protocol allowlist).
**Apply to:** `ProductImportService` page fetch **and** the optional `og:image` fetch (the image URL is attacker-influenceable — re-guard it). This is the headline security control; the same guard runs on both fetches.

---

## No Analog Found

| Sub-component | Role | Reason | Use Instead |
|---------------|------|--------|-------------|
| SSRF guard (resolve/validate/pin/re-guard) | service (security) | No server-side user-supplied-URL fetch exists in the codebase; `ExchangeRateService` hits a fixed trusted endpoint with no guard | `10-RESEARCH.md` Code Examples lines 232-293 (mirrors `fin1te/safecurl` design) |
| DOMDocument / DOMXPath OG-meta parse | service | No HTML parsing anywhere in the codebase today | `10-RESEARCH.md` lines 296-326 |
| Controller action returning JSON | controller | No controller currently calls `Controller::json()` (`fetchUrl()` is the first) | `Controller::json()` signature (Controller.php:46-52) + `rate()` thin-POST shape (ProductController.php:442-460) |

---

## Metadata

**Analog search scope:** `src/Services/`, `src/Core/`, `src/Controllers/`, `src/Views/products/`, `public/index.php`, `public/assets/js/app.js`, `tests/`, `phpunit.xml`
**Files scanned:** 11 read in full + 2 targeted greps (`json(` callers, `app.js` fetch idioms)
**Pattern extraction date:** 2026-06-16
