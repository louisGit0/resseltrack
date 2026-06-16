# Phase 10: Product URL Auto-fill (IMPORT-01) - Research

**Researched:** 2026-06-16
**Domain:** Server-side best-effort HTML scraping of a user-supplied public product URL (curl + DOMDocument), with a hard SSRF security boundary, currency→EUR conversion, and a JSON endpoint feeding minimal vanilla JS.
**Confidence:** HIGH for the security pattern, the endpoint wiring, and the Vercel/runtime facts; MEDIUM-LOW for AliExpress extraction success (the page is anti-bot + JS-rendered — failure is the expected common case).

<user_constraints>
## User Constraints (from CONTEXT.md)

### Locked Decisions
- **D-01:** AliExpress-specific parsing first, with a **generic Open Graph fallback** (`og:title`, `og:image`, `og:price:amount` / `product:price:amount` + `og:price:currency`) for any other public `http(s)` URL. Best-effort: if neither yields data, return a "remplissage indisponible" signal.
- **D-02 (SSRF guard — mandatory):** the user-supplied URL is fetched server-side, so it MUST be constrained: accept only `http`/`https`; reject URLs resolving to private/loopback/link-local/metadata IP ranges (127/8, 10/8, 172.16/12, 192.168/16, 169.254/16, ::1, fc00::/7, etc.); cap redirects and follow them through the same guard; short curl timeout (~5-8s) like `ExchangeRateService`. Headline security control of the phase.
- **D-03:** scraped price pre-fills **`market_price_new`** (prix constaté neuf), editable before save.
- **D-04:** convert to EUR when a currency is detected (`og:price:currency`, symbol, or AliExpress markup → USD/CNY) via the existing `ExchangeRateService`. Best-effort: if currency can't be reliably detected or FX fails, pre-fill the **raw numeric value** and flag the user to verify (never silently mislabel).
- **D-05:** image is **preview only** — scrape returns an image URL shown as a thumbnail, NOT auto-persisted. **No CSP change**, no Cloudinary upload-by-URL, external URL not stored as the product image.
- **D-06:** **Non-destructive** — auto-fill populates only **empty** form fields. Anything the user already typed is left untouched (implemented client-side).
- **D-07:** URL text input + "Remplir depuis URL" button in `products/form.php`. Button calls `POST /products/fetch-url` → `ProductController::fetchUrl()` returns JSON `{ok, name?, price?, currency?, converted_eur?, image_url?, message?}`. Minimal JS in `app.js` fills empty fields (D-06), shows the image preview (D-05); on failure shows a French message like "Remplissage automatique indisponible — veuillez saisir manuellement". All fields stay editable; no data loss.

### Claude's Discretion
- Exact AliExpress selectors / JSON-LD vs meta-tag vs embedded-JSON extraction strategy (best-effort; research recommends below).
- Whether `fetchUrl()` is a JSON endpoint vs a partial — **JSON is locked (D-07)**.
- Exact SSRF IP-range check implementation (research provides the canonical shape) — must satisfy D-02.
- Loading/disabled state of the button while fetching.

### Deferred Ideas (OUT OF SCOPE)
- Additional site-specific parsers beyond AliExpress (Amazon, Vinted public listings) — OG fallback covers them best-effort; dedicated parsers are future work.
- Cloudinary upload-by-URL for the scraped image — deliberately deferred (D-05 chose preview-only).
- Scraping private authenticated order pages — explicitly OUT OF SCOPE (infeasible).
</user_constraints>

<phase_requirements>
## Phase Requirements

| ID | Description | Research Support |
|----|-------------|------------------|
| IMPORT-01 | Coller une URL de produit public → scrape serveur best-effort (curl + parsing HTML) pré-remplit titre + prix + image, avec repli manuel si le site bloque ; site par site (cible initiale AliExpress). Scraping de pages de commande privées hors périmètre. | `ProductImportService` (SSRF-guarded curl + DOMDocument OG/JSON extraction + FX convert), `ProductController::fetchUrl()` JSON action, `POST /products/fetch-url` route, `form.php` input/button/preview, `app.js` fetch+fill-empty. All extraction paths documented below; "blocked → French manual-entry message" is the contractual default behaviour, not an error path. |
</phase_requirements>

## Summary

This phase adds **one server-side action that fetches an arbitrary user-supplied URL** — which is, by definition, an SSRF vector. The single most important deliverable is a correct SSRF guard (D-02). Everything else (HTML parsing, FX conversion, JSON shape, JS population) is mechanical and mirrors existing, proven patterns in the codebase (`ExchangeRateService` for curl, `Controller::json()`, `Csrf::validate()`, the `app.js` frankfurter fetch idiom).

The hard truth about AliExpress (verified, 2026): **AliExpress product pages are 100% client-side rendered with zero JSON-LD; a plain server `curl` GET returns a near-empty HTML shell** behind Akamai/Cloudflare-class anti-bot (TLS + browser fingerprinting). Real product data is served only through the signed-token MTOP API requiring browser session cookies — out of reach for a stateless server curl and explicitly out of scope. Therefore the realistic best-effort extraction for ANY page (AliExpress included) is: try any embedded JSON blob present in the returned shell (`window.runParams` / `__INIT_DATA__`-style), then **Open Graph `<meta>` tags** (often still served to crawlers for link unfurling), then `<title>`. The plan MUST treat "blocked / JS-only / no usable markup" as the **expected common outcome** and degrade to the French manual-entry message — this is the contract (Success Criterion #2), not a bug.

**Primary recommendation:** Build `ProductImportService::fromUrl(string $url): array` as a `final` service mirroring `ExchangeRateService` — SSRF-guard the URL (resolve host, validate every IP, pin curl to the validated IP, follow redirects manually re-running the guard), fetch with a 5s timeout and a realistic browser User-Agent + `Accept-Language`, parse via DOMDocument/DOMXPath (embedded-JSON → OG meta → `<title>`), best-effort FX-convert via `ExchangeRateService::latest()`, and always return a typed array — never throw into the request path. The controller validates CSRF, reads `$_POST['url']`, delegates, and returns `Controller::json()`. The JS posts **form-encoded** (so `$_POST['_csrf']` is populated for `Csrf::validate()`), fills only empty fields, and renders the image as a **`data:` URI** (the one way to show a preview without a CSP change — see Open Question #1).

## Architectural Responsibility Map

| Capability | Primary Tier | Secondary Tier | Rationale |
|------------|-------------|----------------|-----------|
| URL input + "Remplir depuis URL" button + image preview | Browser / View (`form.php`) | — | Presentation; no logic |
| Fetch trigger, fill-empty population, French failure message | Browser (`app.js`) | — | Client-side per D-06/D-07; mirrors existing `app.js` fetch idiom |
| CSRF validation, request transport, JSON response | API / Controller (`ProductController::fetchUrl`) | — | Thin controller, delegates; matches existing convention |
| **SSRF guard** (scheme/host/IP validation, redirect re-guard) | API / Service (`ProductImportService`) | — | **Must be server-side**; never trust the client. The security boundary lives here, not in the controller or JS |
| HTTP fetch (curl), HTML parse (DOMDocument), extraction | Service (`ProductImportService`) | — | Services own I/O (CONVENTIONS); mirrors `ExchangeRateService` |
| Currency detection + EUR conversion | Service (`ProductImportService`) | External API (`ExchangeRateService` → frankfurter.dev) | Reuses proven FX service (D-04) |
| Image preview rendering | Browser (`<img>` with `data:` URI) | Service (fetches+base64s image through the SAME SSRF guard) | `data:` is the only img-src token allowed without a CSP change (D-05) |

## Standard Stack

### Core (all PHP built-ins — NO new Composer packages, per project constraint)
| "Library" | Version | Purpose | Why Standard |
|-----------|---------|---------|--------------|
| `ext-curl` | bundled (PHP 8.3) | Outbound HTTP fetch of the target URL + (optional) image bytes | Already used by `ExchangeRateService` in prod on Vercel `[VERIFIED: codebase ExchangeRateService.php]`; bundled by vercel-php `[CITED: github.com/vercel-community/php]` |
| `ext-dom` (`DOMDocument`, `DOMXPath`) | bundled | Parse possibly-malformed HTML; XPath-query `<meta>`/`<title>` | Bundled by vercel-php `[CITED: github.com/vercel-community/php]`; standard HTML parsing |
| `ext-libxml` | bundled | Underlies DOMDocument; `libxml_use_internal_errors()` to swallow malformed-HTML warnings | Bundled `[CITED: github.com/vercel-community/php]` |
| `ext-mbstring` | bundled | `mb_convert_encoding` encoding hint for `loadHTML`; `mb_strtolower` | Bundled `[CITED: github.com/vercel-community/php]`; already used (`mb_strtolower` in `ProductController`) |
| `ext-filter` (`filter_var`) | bundled | IP validation: `FILTER_VALIDATE_IP` + `FILTER_FLAG_NO_PRIV_RANGE \| FILTER_FLAG_NO_RES_RANGE` | Core SSRF primitive `[CITED: php.net/manual/en/function.filter-var.php]` |
| `ext-json` | bundled | Decode embedded JSON blobs; `json_encode` the response | Already used app-wide |

### Supporting (existing app components to reuse)
| Component | Purpose | When to Use |
|-----------|---------|-------------|
| `App\Services\ExchangeRateService::latest($from, 'EUR')` | FX rate for D-04 conversion | After a currency is detected (USD/CNY/etc.); returns `?float`, `null` on failure → fall back to raw value + warn |
| `App\Core\Controller::json(mixed $payload, int $status=200)` | JSON response (sets header, `json_encode` with `JSON_UNESCAPED_UNICODE`, `exit`) | The whole `fetchUrl()` response |
| `App\Core\Csrf::validate()` | 419 on bad/expired token; reads **`$_POST['_csrf']`** | First line of `fetchUrl()` |
| `App\Core\Auth::require()` | Already called in `ProductController::__construct` | Endpoint is auth-gated for free (reduces SSRF abuse surface) |

### Alternatives Considered
| Instead of | Could Use | Tradeoff |
|------------|-----------|----------|
| Hand-rolled SSRF guard | `fin1te/safecurl` / `j0k3r/safecurl` (the reference SSRF curl libraries) | **PRECLUDED** by the "no new Composer packages" constraint. Mirror SafeCurl's *approach* by hand (it's the canonical design): resolve → validate all IPs → disable FOLLOWLOCATION → manual redirect re-guard → restrict protocols. `[CITED: github.com/j0k3r/safecurl]` |
| DOMDocument | Headless browser (Puppeteer/Playwright) to render AliExpress JS | Not possible on PHP Vercel serverless; out of scope; also defeated by anti-bot. |
| `og:` meta parse | AliExpress MTOP API | Requires signed token + session cookies + browser fingerprint — infeasible stateless server-side, and adjacent to the out-of-scope "private page" boundary. Do not attempt. `[VERIFIED: WebSearch — alterlab.io, scrapfly.io]` |

**Installation:** None. `composer.json` is unchanged. No `php.ini` change. No `vercel.json` change (other than the optional `maxDuration` note in Pitfalls).

## Package Legitimacy Audit

**Not applicable.** This phase installs **zero** external packages — all functionality uses bundled PHP extensions (curl, dom, libxml, mbstring, filter, json). The "no new Composer packages" constraint is a locked tech-stack rule (CONTEXT + CLAUDE.md). slopcheck/registry verification is moot because nothing is installed.

## Architecture Patterns

### System Architecture Diagram

```
[products/form.php]                                  Browser
  URL input + "Remplir depuis URL" button
        |  click
        v
[app.js fetchFromUrl()]
  POST (application/x-www-form-urlencoded)
  body: url=<user URL> & _csrf=<hidden field value>
        |  fetch('/products/fetch-url')   (CSP connect-src 'self' — OK)
        v
====================== server (Vercel Lambda) ======================
[Router]  POST /products/fetch-url  (MUST be registered BEFORE POST /products/{id})
        v
[ProductController::fetchUrl()]
  1. Csrf::validate()            <- reads $_POST['_csrf']
  2. $url = trim($_POST['url'])
  3. delegate -> ProductImportService::fromUrl($url)
  4. return Controller::json($result)
        v
[ProductImportService::fromUrl()]
  a. SSRF GUARD ----------------------------------------------------+
     parse_url -> scheme in {http,https}? host present?             |
     resolve host -> A + AAAA records                               |
     EVERY resolved IP must pass:                                   |
       filter_var(IP, FILTER_VALIDATE_IP,                           |
                  NO_PRIV_RANGE | NO_RES_RANGE)                     |
       + explicit deny: 169.254.169.254 (metadata), link-local,     |
         ::1, fc00::/7, fe80::/10, ::ffff:0:0/96 (mapped v4)        |
     reject on any failure  --> ok:false, message (FR)              |
  b. curl GET: CURLOPT_RESOLVE pinned to validated IP,              |
     FOLLOWLOCATION off, realistic UA + Accept-Language,            |
     TIMEOUT 5 / CONNECTTIMEOUT 3, PROTOCOLS http/https only        |
  c. on 3xx: read Location, GOTO (a) for new host, cap N hops ------+
  d. non-200 / empty / blocked  --> ok:false, message (FR)
  e. parse (best-effort, in order):
        embedded JSON blob (window.runParams/__INIT_DATA__)
        -> Open Graph <meta property="og:*">
        -> <title>
  f. detect currency (og:price:currency | symbol | markup)
     -> ExchangeRateService::latest(cur,'EUR') -> converted_eur
        (FX null  --> raw value + needs-verify warning)
  g. (optional) fetch og:image bytes THROUGH THE SAME GUARD,
     size-cap, base64 -> data: URI  (preview without CSP change)
        v
  return {ok, name?, price?, currency?, converted_eur?, image_url?, message?}
====================================================================
        ^
        |  JSON
[app.js]  fill ONLY empty fields (name, market_price_new),
          render image preview (<img src=data:...>),
          on !ok or network error -> French manual-entry message
```

### Recommended Project Structure (additions only)
```
src/
├── Services/
│   └── ProductImportService.php   # NEW — final class; SSRF guard + curl + DOM parse + FX. Mirror ExchangeRateService.
├── Controllers/
│   └── ProductController.php       # +fetchUrl() action (JSON)
└── Views/products/
    └── form.php                    # + URL input, button, preview block (in the create branch / always)
public/
├── index.php                       # + POST /products/fetch-url (BEFORE POST /products/{id})
└── assets/js/app.js                # + initUrlAutofill() wired into DOMContentLoaded
```

### Pattern 1: Service mirrors `ExchangeRateService` (best-effort, never throws)
**What:** `final class ProductImportService` with `declare(strict_types=1)`. Public `fromUrl(string $url): array` returns the JSON-shaped array. Logs failures with `error_log`, returns `['ok' => false, 'message' => '…']` on every failure mode — never throws into the request path.
**When:** Always — this is the contract per CONVENTIONS ("services return typed best-effort results, log failures, never throw into the request path").

### Pattern 2: SSRF-safe fetch with IP pinning + manual redirect re-guard
**What:** Resolve the host yourself, validate every resolved IP, then **pin curl to the validated IP via `CURLOPT_RESOLVE`** (curl will not re-resolve → closes the DNS-rebinding TOCTOU window). Disable `CURLOPT_FOLLOWLOCATION`; on a 3xx, read `Location`, re-run the full guard on the new host, loop with a hop cap (e.g. 3). Restrict `CURLOPT_PROTOCOLS`/`CURLOPT_REDIR_PROTOCOLS` to HTTP+HTTPS. See Code Examples. `[CITED: php.watch/articles/php-curl-security-hardening]` `[CITED: github.com/j0k3r/safecurl]`

### Pattern 3: Robust malformed-HTML parsing with DOMDocument
**What:** `libxml_use_internal_errors(true)` to swallow warnings; prepend an encoding hint so `loadHTML` doesn't mojibake UTF-8; query with DOMXPath. See Code Examples.

### Pattern 4: Thin controller, form-encoded POST for CSRF compatibility
**What:** `Csrf::validate()` reads **only `$_POST['_csrf']`** (verified in `Csrf.php`). Therefore the JS MUST send `application/x-www-form-urlencoded` (a JSON request body leaves `$_POST` empty → guaranteed 419). Controller reads `$_POST['url']`. `fetchUrl()` may be declared parameterless (`public function fetchUrl(): void`) — the router passes `$params` as an extra arg which PHP ignores, exactly like `store()`.

### Anti-Patterns to Avoid
- **Validating the hostname string instead of the resolved IP** — `filter_var($host, FILTER_VALIDATE_IP)` returns false for domain names, silently skipping the check. This is the real-world Lychee CVE pattern. `[CITED: github.com/LycheeOrg/Lychee GHSA-5245-4p8c-jwff]` Always resolve, then validate IPs.
- **Letting curl re-resolve / follow redirects itself** — `CURLOPT_FOLLOWLOCATION` re-resolves DNS internally and follows `Location` to internal hosts, defeating the guard. Disable it; follow manually. `[CITED: php.watch]`
- **Sending a JSON request body** — breaks `Csrf::validate()` (reads `$_POST`). Use form-encoded.
- **Rendering the remote image URL as `<img src=https://ae01.alicdn.com/...>`** — blocked by the current CSP `img-src 'self' data: https://res.cloudinary.com`; the preview would silently break. Use a `data:` URI (Open Question #1).
- **Treating a blocked/empty AliExpress response as a hard error (500)** — it's the expected case; must return `ok:false` + French message with all fields editable.
- **Trusting `og:image` for a server-side fetch without re-guarding** — the scraped image URL is attacker-influenceable; if you fetch it for the `data:` URI, run it through the identical SSRF guard.

## Don't Hand-Roll

| Problem | Don't Build | Use Instead | Why |
|---------|-------------|-------------|-----|
| HTML parsing / meta extraction | Regex soup over raw HTML | `DOMDocument` + `DOMXPath` (with `libxml_use_internal_errors`) | Malformed HTML, encodings, attribute ordering — DOM handles it. Regex is a known footgun. |
| FX conversion | New currency API call | `ExchangeRateService::latest()` | Already proven on Vercel; consistent rates with the purchase form. |
| JSON response | Manual `echo json_encode` | `Controller::json()` | Sets headers + `JSON_UNESCAPED_UNICODE` + `exit` consistently. |
| CSRF | New token scheme | `Csrf::validate()` / `Csrf::field()` | Timing-safe, session-backed, already in the form. |
| SSRF guard | *(forced exception)* | **Hand-roll, mirroring `fin1te/safecurl`'s design** | Normally you'd use an SSRF library, but the no-new-packages constraint forbids it. SafeCurl is the canonical reference to copy conceptually (resolve→validate-all-IPs→pin→manual-redirect→protocol-allowlist). Do NOT invent a novel scheme; replicate the well-known one. `[CITED: github.com/j0k3r/safecurl]` |

**Key insight:** The only thing genuinely hand-rolled here is the SSRF guard, and that's only because the project bans the library that would normally do it. Treat the guard's design as *copied*, not *invented* — deviating from the established resolve/validate/pin/re-guard shape is where SSRF bugs come from.

## Common Pitfalls

### Pitfall 1: Route shadowing — `/products/fetch-url` swallowed by `/products/{id}`
**What goes wrong:** `{id}` compiles to `([^/]+)` (verified in `Router.php` line 29), NOT numeric-only. With first-match-wins dispatch, `POST /products/{id}` (update) matches `POST /products/fetch-url`, routing to `update(['id' => 'fetch-url'])` → `(int)'fetch-url'` = 0 → "Produit introuvable".
**Why:** Generic segment regex + ordered matching.
**How to avoid:** Register `$router->post('/products/fetch-url', [ProductController::class, 'fetchUrl']);` **immediately after `POST /products` (store) and BEFORE `POST /products/{id}` (update)** in `public/index.php`. This mirrors the existing GET `/products/create` before `/products/{id}` ordering rule.
**Warning signs:** Endpoint returns a redirect/flash instead of JSON. `[VERIFIED: codebase Router.php + index.php]`

### Pitfall 2: CSRF 419 from a JSON request body
**What goes wrong:** Posting `JSON.stringify({url})` with `Content-Type: application/json` leaves `$_POST` empty; `Csrf::validate()` reads `$_POST['_csrf']` → always 419.
**How to avoid:** Send `application/x-www-form-urlencoded` (`new URLSearchParams({url, _csrf})`). Read the token from the existing hidden field `input[name="_csrf"]` rendered by `Csrf::field()` in the form.
**Warning signs:** "Jeton CSRF invalide" text response. `[VERIFIED: codebase Csrf.php]`

### Pitfall 3: DNS rebinding / curl re-resolution (the SSRF TOCTOU)
**What goes wrong:** You resolve+validate the host, then hand the *hostname* URL to curl; curl re-resolves and a fast-rebinding DNS record (or a redirect) points it at `169.254.169.254` or `127.0.0.1`.
**How to avoid:** Pin curl to the validated IP via `CURLOPT_RESOLVE` (keeps Host header/SNI valid for TLS), disable `CURLOPT_FOLLOWLOCATION`, follow redirects manually re-running the guard, allowlist protocols. `[CITED: github.com/LycheeOrg GHSA-5245-4p8c-jwff; php.watch]`
**Warning signs:** Guard passes but internal endpoints are reachable.

### Pitfall 4: CSP blocks the image preview (D-05 tension)
**What goes wrong:** `img-src 'self' data: https://res.cloudinary.com` — an external CDN image URL won't render; D-05 forbids changing the CSP.
**How to avoid:** Server fetches the image (through the SAME SSRF guard, size-capped), base64-encodes it, returns a `data:` URI; JS sets `img.src = dataUri` (allowed by the `data:` token). See Open Question #1.
**Warning signs:** Broken-image icon in the preview; CSP violation in console.

### Pitfall 5: AliExpress returns a JS shell / anti-bot challenge (the norm, not the exception)
**What goes wrong:** Plain curl gets an empty HTML shell or a Cloudflare/captcha page; no title/price/image in the body.
**How to avoid:** Send a realistic browser `User-Agent` and `Accept-Language: fr-FR,fr;q=0.9,en;q=0.8` (improves the odds the page serves OG meta for link unfurling), try embedded-JSON → OG → `<title>` in order, and on no usable data return `ok:false` + the French manual-entry message. **Do not** add retry storms or attempt the MTOP API. Document in the plan that AliExpress full extraction is low-probability and OG/`<title>` is the realistic ceiling. `[VERIFIED: WebSearch — scrapfly.io, alterlab.io, automatio.ai]`

### Pitfall 6: Function timeout budget on Vercel
**What goes wrong:** Default function duration is **5s on Hobby / 10s otherwise**; a 5s page fetch + an additional image fetch + base64 could brush a 5s limit. `[CITED: vercel.com/docs/functions/limitations]`
**How to avoid:** Mirror `ExchangeRateService`'s 5s page timeout; make the optional image fetch tight (~3s) and best-effort; if both are kept, consider setting `maxDuration` (≈15s) for the function in `vercel.json`/the function config as headroom. The app already runs a 5s outbound curl in prod successfully. `[VERIFIED: codebase ExchangeRateService.php]`

## Code Examples

> Illustrative shapes (not final code) — they encode the verified patterns the planner should turn into tasks.

### SSRF guard + safe fetch (the headline deliverable)
```php
// Source pattern: php.watch curl hardening + fin1te/safecurl design + filter_var manual
private const BLOCKED_V4 = ['169.254.169.254']; // cloud metadata (AWS/GCP); link-local also caught below

/** @return array{0: ?string, 1: ?string} [validated-IP, error-FR] */
private function resolveAndValidate(string $host): array
{
    // IPv4 (A) + IPv6 (AAAA)
    $ips = gethostbynamel($host) ?: [];
    foreach (@dns_get_record($host, DNS_AAAA) ?: [] as $r) {
        if (!empty($r['ipv6'])) { $ips[] = $r['ipv6']; }
    }
    if ($ips === []) { return [null, 'Hôte introuvable.']; }

    foreach ($ips as $ip) {
        // Reject private + reserved (covers 10/8, 172.16/12, 192.168/16, 127/8,
        // 169.254/16, ::1, fc00::/7, fe80::/10, etc.)
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            return [null, 'URL non autorisée (adresse interne).'];
        }
        // Belt-and-braces explicit denials (metadata + IPv4-mapped IPv6 bypass)
        if (in_array($ip, self::BLOCKED_V4, true) || str_starts_with(strtolower($ip), '::ffff:')) {
            return [null, 'URL non autorisée (adresse interne).'];
        }
    }
    return [$ips[0], null]; // pin curl to the first validated IP
}

private function fetch(string $url, int $hops = 3): ?string
{
    $parts = parse_url($url);
    $scheme = strtolower($parts['scheme'] ?? '');
    if (!in_array($scheme, ['http', 'https'], true) || empty($parts['host'])) {
        return null;
    }
    [$ip, $err] = $this->resolveAndValidate($parts['host']);
    if ($ip === null) { error_log('ProductImport SSRF reject: ' . $err); return null; }

    $port = $parts['port'] ?? ($scheme === 'https' ? 443 : 80);
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,                 // we follow manually
        CURLOPT_TIMEOUT        => 5,
        CURLOPT_CONNECTTIMEOUT => 3,
        CURLOPT_PROTOCOLS      => CURLPROTO_HTTP | CURLPROTO_HTTPS,
        CURLOPT_RESOLVE        => ["{$parts['host']}:{$port}:{$ip}"], // pin → no re-resolution
        CURLOPT_USERAGENT      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0 Safari/537.36',
        CURLOPT_HTTPHEADER     => ['Accept-Language: fr-FR,fr;q=0.9,en;q=0.8'],
        CURLOPT_MAXFILESIZE    => 2_000_000,             // cap body size
    ]);
    $body   = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);

    if (in_array($status, [301, 302, 303, 307, 308], true) && $hops > 0) {
        $loc = curl_getinfo($ch, CURLINFO_REDIRECT_URL);
        curl_close($ch);
        return $loc ? $this->fetch($loc, $hops - 1) : null; // re-guards the new host
    }
    curl_close($ch);
    return ($body !== false && $status === 200 && $body !== '') ? (string) $body : null;
}
```

### Robust OG / title extraction with DOMDocument
```php
// Source pattern: php.net DOMDocument::loadHTML + DOMXPath
private function parse(string $html): array
{
    libxml_use_internal_errors(true);
    $doc = new \DOMDocument();
    // encoding hint avoids UTF-8 mojibake
    $doc->loadHTML('<?xml encoding="UTF-8">' . $html);
    libxml_clear_errors();
    $xp = new \DOMXPath($doc);

    $meta = static function (string $prop) use ($xp): ?string {
        $n = $xp->query("//meta[@property='{$prop}' or @name='{$prop}']/@content")->item(0);
        $v = $n?->nodeValue;
        return ($v !== null && trim($v) !== '') ? trim($v) : null;
    };

    $name  = $meta('og:title');
    if ($name === null) {
        $t = $xp->query('//title')->item(0)?->nodeValue;
        $name = ($t !== null && trim($t) !== '') ? trim($t) : null;
    }
    $price = $meta('og:price:amount') ?? $meta('product:price:amount');
    $cur   = $meta('og:price:currency') ?? $meta('product:price:currency');
    $image = $meta('og:image');
    // (Best-effort: also try a JSON-LD <script type="application/ld+json"> Product block,
    //  and any embedded window.runParams/__INIT_DATA__ JSON blob, before giving up.)

    return ['name' => $name, 'price' => $price, 'currency' => $cur, 'image_url' => $image];
}
```

### Currency → EUR (D-04, best-effort, no silent mislabel)
```php
// $rawPrice e.g. "39.99"; $cur e.g. "USD" (from og:price:currency) — or symbol-derived.
$converted = null; $needsVerify = false;
if ($price !== null && $cur !== null) {
    $rate = (new \App\Services\ExchangeRateService())->latest($cur, 'EUR'); // ?float
    if ($rate !== null) {
        $converted = round((float) str_replace(',', '.', $price) * $rate, 2);
    } else {
        $needsVerify = true; // FX failed → keep raw, warn the user
    }
} elseif ($price !== null) {
    $needsVerify = true;     // currency unknown → keep raw, warn
}
// message: e.g. "Prix détecté en {cur} — vérifiez le montant converti." when $needsVerify
```

### Controller action
```php
public function fetchUrl(): void   // router passes $params; PHP ignores the extra arg (like store())
{
    Csrf::validate();                                  // reads $_POST['_csrf']
    $url = trim((string) ($_POST['url'] ?? ''));
    if ($url === '') {
        $this->json(['ok' => false, 'message' => 'Aucune URL fournie.'], 200);
    }
    $this->json((new \App\Services\ProductImportService())->fromUrl($url));
}
```

### app.js (form-encoded POST, fill-empty, data: preview, French failure)
```js
// Mirrors the existing frankfurter fetch idiom in app.js.
function initUrlAutofill() {
    const btn = document.getElementById('fetch-url-btn');
    if (!btn) return;
    const urlIn = document.getElementById('import-url');
    const csrf  = document.querySelector('input[name="_csrf"]')?.value || '';
    const status = document.getElementById('import-status');
    const nameIn = document.querySelector('input[name="name"]');
    const priceIn = document.querySelector('input[name="market_price_new"]');
    const preview = document.getElementById('import-preview');

    btn.addEventListener('click', async function () {
        const url = (urlIn.value || '').trim();
        if (!url) return;
        btn.disabled = true; status.textContent = 'Récupération…';
        try {
            const res = await fetch('/products/fetch-url', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: new URLSearchParams({ url, _csrf: csrf }),
            });
            const d = await res.json();
            if (!d || !d.ok) {
                status.textContent = (d && d.message) || 'Remplissage automatique indisponible — veuillez saisir manuellement.';
            } else {
                if (d.name && nameIn && !nameIn.value.trim()) nameIn.value = d.name;        // fill-empty (D-06)
                const val = d.converted_eur ?? d.price;
                if (val != null && priceIn && !priceIn.value.trim()) priceIn.value = val;    // fill-empty (D-06)
                if (d.image_url && preview) { preview.src = d.image_url; preview.classList.remove('d-none'); } // data: URI
                status.textContent = d.message || 'Champs pré-remplis — vérifiez avant d’enregistrer.';
            }
        } catch (e) {
            status.textContent = 'Erreur réseau — veuillez saisir manuellement.';
        } finally {
            btn.disabled = false;
        }
    });
}
```

## State of the Art

| Old Approach | Current Approach (2026) | When Changed | Impact |
|--------------|-------------------------|--------------|--------|
| AliExpress product HTML had SSR content / parseable markup | **100% client-side rendered, zero JSON-LD, data only via signed MTOP API** behind Akamai/Cloudflare anti-bot | Progressive over 2023-2026 | A plain server curl yields an empty shell most of the time → OG/`<title>` is the realistic ceiling; full extraction is low-probability `[VERIFIED: scrapfly.io 2026 update, alterlab.io 2026]` |
| `frankfurter.app` FX endpoint | `api.frankfurter.dev/v1/latest` | (already migrated in codebase) | `ExchangeRateService` + `app.js` already use `.dev`; reuse as-is `[VERIFIED: codebase]` |
| Naive SSRF guard (validate hostname string) | Resolve → validate all IPs → pin curl IP → manual redirect re-guard | Ongoing (DNS-rebinding CVEs) | The only correct shape; validating the hostname string is the documented Lychee CVE bug `[CITED: GHSA-5245-4p8c-jwff]` |

**Deprecated/outdated:**
- Attempting to scrape AliExpress product data from the page body without a headless browser: effectively dead for real product fields. OG/title only.

## Assumptions Log

| # | Claim | Section | Risk if Wrong |
|---|-------|---------|---------------|
| A1 | AliExpress still serves *some* `og:title`/`og:image` to crawler-style UAs for link unfurling | Pitfall 5 / Summary | If it serves nothing at all to server curl, AliExpress auto-fill yields only the manual-entry message — still spec-compliant (Success Criterion #2), just lower value. No functional break. |
| A2 | `data:` URI image preview is the intended reconciliation of D-05 ("show preview" + "no CSP change") | Open Question #1 | If the user prefers a plain link (no rendered thumbnail) or accepts a CSP change, the image-handling task changes. Needs confirmation. |
| A3 | Vercel function duration on this project's plan allows a ~5s curl (proven) and an optional ~3s image fetch | Pitfall 6 | If on the 5s Hobby limit and both fetches run, risk of 504; mitigated by tight budgets or `maxDuration`. |
| A4 | `gethostbynamel` (IPv4) + `dns_get_record(...DNS_AAAA)` (IPv6) is sufficient host resolution for the guard | Code Examples | Edge: CDNs with many rotating IPs; the guard validates all returned and pins the first — acceptable for best-effort. |

## Open Questions

1. **Image preview vs. CSP (D-05 reconciliation) — needs a planning decision.**
   - What we know: D-05 says "show a preview thumbnail" AND "no CSP change". Current CSP `img-src 'self' data: https://res.cloudinary.com` blocks any external CDN image URL.
   - What's unclear: how to render a preview without violating CSP.
   - **Recommendation:** Server fetches the `og:image` **through the same SSRF guard**, size-caps it, base64-encodes it, returns a `data:` URI in `image_url`; JS sets `img.src` to it (allowed by the `data:` token). This satisfies all of D-05 (preview shown, no CSP change, external URL never persisted). Alternative if rejected: show the URL as a plain text link (no `<img>`). Confirm with the user/planner.

2. **AliExpress realistic success rate.**
   - What we know: full extraction is low-probability (anti-bot + JS-only).
   - Recommendation: scope the AliExpress parser as "try embedded JSON → OG → title, expect frequent fallback to the manual-entry message." Verify Success Criterion #1 against a *currently-parseable* AliExpress URL at verification time (it may yield only the name); treat the OG path as the dependable one. Do NOT gate phase success on reliably extracting AliExpress price/image.

## Environment Availability

| Dependency | Required By | Available | Version | Fallback |
|------------|------------|-----------|---------|----------|
| `ext-curl` | Outbound fetch | ✓ | bundled (vercel-php) | — `[CITED: vercel-community/php]` |
| `ext-dom` (`DOMDocument`/`DOMXPath`) | HTML parsing | ✓ | bundled | Regex OG extraction (defensive only) `[CITED: vercel-community/php]` |
| `ext-libxml` | underlies DOM | ✓ | bundled | — |
| `ext-mbstring` | encoding hint | ✓ | bundled | — |
| `ext-filter` (`filter_var`) | IP validation | ✓ | bundled | — |
| `ext-json` | decode/encode | ✓ | bundled | — |
| `ExchangeRateService` (frankfurter.dev) | D-04 FX | ✓ | existing service | raw value + verify warning (built-in) |

**Missing dependencies with no fallback:** None.
**Missing dependencies with fallback:** None blocking — `DOMDocument` is bundled by vercel-php; defensive regex fallback only if a future runtime drops `ext-dom` (unnecessary today).

## Validation Architecture

> `workflow.nyquist_validation: true` — section included.

### Test Framework
| Property | Value |
|----------|-------|
| Framework | PHPUnit 11.x (`require-dev`) `[VERIFIED: CLAUDE.md / composer.json]` |
| Config file | `phpunit.xml` (root); coverage source = `src/Services/` only |
| Quick run command | `vendor/bin/phpunit --filter ProductImportServiceTest` |
| Full suite command | `vendor/bin/phpunit` |

**Key constraint:** the project's test convention covers **`src/Services/` only** (pure business logic). `ProductImportService` is a service, so its **pure** logic is unit-testable, but its I/O (curl, DNS) is not — design the service so the SSRF/IP-validation and parsing/currency logic are **pure methods** testable without network (pass HTML strings / IP strings in). The controller (`fetchUrl`) and `app.js` are transport/UI — verified manually per existing project practice (controllers/JS are not in the coverage scope).

### Phase Requirements → Test Map
| Req ID | Behavior | Test Type | Automated Command | File Exists? |
|--------|----------|-----------|-------------------|-------------|
| IMPORT-01 | SSRF guard rejects private/loopback/link-local/metadata IPs and accepts public ones | unit (pure IP-validation method) | `vendor/bin/phpunit --filter ssrf` | ❌ Wave 0 |
| IMPORT-01 | OG/title extraction from a known HTML string yields name/price/currency/image | unit (pure parse method on fixture HTML) | `vendor/bin/phpunit --filter parse` | ❌ Wave 0 |
| IMPORT-01 | Currency→EUR conversion: detected currency converts; unknown/failed FX → raw value + needs-verify flag | unit (pure convert method; inject a rate or stub) | `vendor/bin/phpunit --filter currency` | ❌ Wave 0 |
| IMPORT-01 | Blocked/empty/non-200 fetch → `ok:false` + French message (no throw) | unit (parse on empty/garbage string returns ok:false) | `vendor/bin/phpunit --filter blocked` | ❌ Wave 0 |
| IMPORT-01 | Route `/products/fetch-url` resolves to `fetchUrl()` not `update()` | manual / smoke | curl the live endpoint post-deploy; confirm JSON not redirect | manual |
| IMPORT-01 | End-to-end: paste URL → fill-empty → preview → manual-entry message on failure | manual (browser) | follow Success Criteria 1-3 | manual |

### Sampling Rate
- **Per task commit:** `vendor/bin/phpunit --filter ProductImportServiceTest`
- **Per wave merge:** `vendor/bin/phpunit`
- **Phase gate:** full suite green before `/gsd:verify-work`; plus the manual route + browser checks above.

### Wave 0 Gaps
- [ ] `tests/Services/ProductImportServiceTest.php` — covers IMPORT-01 pure logic (IP validation, parse, currency, blocked).
- [ ] HTML fixtures (inline strings or `tests/fixtures/`): a well-formed OG page, a JS-shell/empty page, a malformed-HTML page.
- [ ] Design note: extract pure methods (`isPublicIp(string $ip): bool`, `parse(string $html): array`, `convert(?string $price, ?string $cur, ?float $rate): array`) so they're testable without network — the curl I/O method stays thin and is exercised manually.

## Security Domain

> `security_enforcement` absent in config → treated as enabled.

### Applicable ASVS Categories

| ASVS Category | Applies | Standard Control |
|---------------|---------|-----------------|
| V5 Input Validation | **yes** | Validate URL scheme (`http`/`https` only) + host present; cap body size; trim/length-limit the URL input. |
| V5 / V10 SSRF | **yes (headline)** | Resolve host → validate all IPs (`FILTER_FLAG_NO_PRIV_RANGE\|NO_RES_RANGE` + explicit metadata/link-local/IPv4-mapped denials) → pin curl IP (`CURLOPT_RESOLVE`) → disable `FOLLOWLOCATION`, manual redirect re-guard → `CURLOPT_PROTOCOLS` http/https only. The same guard applies to the optional image fetch. |
| V4 Access Control | yes | `Auth::require()` in the controller constructor already gates the endpoint to logged-in users (reduces anonymous SSRF abuse). User-scoped data unaffected (no DB write). |
| V13 / CSRF | yes | `Csrf::validate()` on the POST (form-encoded body). |
| V14 / Output | yes | JSON via `Controller::json()` (`JSON_UNESCAPED_UNICODE`); on the view side, any echoed import data uses `e()`. Image rendered as `data:` URI (no external `<img src>`), preserving the existing CSP. |
| V6 Cryptography | no | No new crypto. |

### Known Threat Patterns for {PHP server-side URL fetch}

| Pattern | STRIDE | Standard Mitigation |
|---------|--------|---------------------|
| SSRF to cloud metadata (`169.254.169.254`) | Information Disclosure / EoP | IP allowlist-by-exclusion + explicit metadata denial + IP pinning |
| DNS rebinding (TOCTOU) | Tampering | Resolve+validate, then pin curl to the validated IP via `CURLOPT_RESOLVE`; don't let curl re-resolve |
| Redirect-based SSRF (302 → internal host / non-HTTP scheme) | Information Disclosure | Disable `FOLLOWLOCATION`; manual redirect with per-hop re-guard; `CURLOPT_REDIR_PROTOCOLS` http/https |
| IPv4-mapped IPv6 bypass (`::ffff:127.0.0.1`) | Tampering | Explicit `::ffff:` prefix denial in the IP check |
| Attacker-controlled `og:image` → server fetch to internal IP | Information Disclosure | Run the image URL through the identical SSRF guard before any server-side fetch |
| CSRF on the state-triggering POST | Spoofing | `Csrf::validate()` (form-encoded `_csrf`) |
| Resource exhaustion (huge response / slow loris) | DoS | `CURLOPT_TIMEOUT` 5s, `CURLOPT_CONNECTTIMEOUT` 3s, `CURLOPT_MAXFILESIZE` ~2 MB, redirect hop cap |
| Reflected/stored XSS via scraped title in the form | Tampering | Title flows into a form `value` populated by JS (`.value =`, not innerHTML) and is `e()`-escaped if ever echoed server-side |

## Sources

### Primary (HIGH confidence)
- Codebase (verified directly): `ExchangeRateService.php` (curl pattern, frankfurter.dev/v1 endpoint, `latest()` signature), `Controller.php` (`json()`), `Csrf.php` (reads `$_POST['_csrf']`), `Router.php` (`{id}`→`([^/]+)`, first-match dispatch, `$controller->$action($params)`), `index.php` (route ordering, CSP `img-src 'self' data: res.cloudinary.com`, `connect-src 'self' api.frankfurter.dev`), `ProductController.php`, `form.php`, `app.js`.
- [vercel-community/php](https://github.com/vercel-community/php) + WebSearch extension list — confirms `dom`, `libxml`, `mbstring`, `curl`, `filter`, `json` bundled.
- [PHP: filter_var manual](https://www.php.net/manual/en/function.filter-var.php) — `FILTER_FLAG_NO_PRIV_RANGE` / `FILTER_FLAG_NO_RES_RANGE`.
- [PHP cURL Security Hardening — PHP.Watch](https://php.watch/articles/php-curl-security-hardening) — `CURLOPT_PROTOCOLS`, FOLLOWLOCATION/redirect SSRF.
- [Vercel Functions Limits](https://vercel.com/docs/functions/limitations) — default duration / maxDuration / 504.

### Secondary (MEDIUM confidence)
- [Lychee SSRF advisory GHSA-5245-4p8c-jwff](https://github.com/LycheeOrg/Lychee/security/advisories/GHSA-5245-4p8c-jwff) — validating hostname-string instead of resolved IP is the canonical bug; DNS rebinding.
- [fin1te/safecurl](https://github.com/j0k3r/safecurl) — reference SSRF-safe curl design to mirror by hand.
- [How to Scrape AliExpress 2026 — Scrapfly](https://scrapfly.io/blog/posts/how-to-scrape-aliexpress) / [AlterLab 2026](https://alterlab.io/blog/how-to-scrape-aliexpress-complete-guide-for-2026) / [Automatio](https://automatio.ai/how-to-scrape/aliexpress) — AliExpress is JS-rendered, zero JSON-LD, MTOP signed API, anti-bot.

### Tertiary (LOW confidence)
- General community SSRF-bypass notes (DNS rebinding writeups) — corroborate the guard shape; not load-bearing beyond the primary/secondary sources above.

## Metadata

**Confidence breakdown:**
- SSRF guard pattern: HIGH — cross-verified (php.watch, filter_var manual, Lychee CVE, SafeCurl); the resolve/validate/pin/re-guard shape is canonical.
- Endpoint/route/CSRF wiring: HIGH — verified directly against the codebase (Router regex, Csrf source, json() helper, route ordering).
- Runtime/Vercel facts (bundled exts, timeout): HIGH — vercel-php extension list + Vercel docs; outbound curl already proven in prod.
- DOMDocument OG extraction: HIGH — stable, standard PHP idiom.
- AliExpress extraction success: LOW — anti-bot + JS-only; best-effort ceiling is OG/title. Flagged as expected-failure-tolerant (Success Criterion #2).
- Image-preview-vs-CSP reconciliation: MEDIUM — `data:` URI recommendation is sound but is a planning decision needing confirmation (Open Question #1).

**Research date:** 2026-06-16
**Valid until:** ~2026-07-16 for the PHP/SSRF/runtime facts (stable). AliExpress page behaviour is volatile — re-verify the specific extraction path at execution/verification time (treat the documented low success rate as the working assumption).
