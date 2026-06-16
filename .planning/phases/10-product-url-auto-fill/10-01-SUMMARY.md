---
phase: 10-product-url-auto-fill
plan: 01
subsystem: api
tags: [ssrf, curl, domdocument, open-graph, json-ld, currency, php, phpunit]

# Dependency graph
requires:
  - phase: 06-performance-and-reliability
    provides: ExchangeRateService (curl FX with 5s timeout, ?float, null-on-failure) reused for EUR conversion
provides:
  - ProductImportService — SSRF-guarded server-side product-page fetch
  - isPublicIp(string): bool — per-IP SSRF allow/deny (pure, network-free)
  - parse(string): array — JSON-LD/Open-Graph/<title> extraction (pure)
  - convert(?float,?string,?float): array — best-effort EUR conversion with needs_verify (pure)
  - fromUrl(string): array — best-effort orchestrator returning the JSON-shaped result
  - Blocking Wave-0 unit test for the three pure methods
affects: [10-02 fetchUrl JSON action + route + form/app.js, 10-03 deploy + live verification]

# Tech tracking
tech-stack:
  added: []  # zero Composer packages — bundled ext-curl/dom/libxml/mbstring/filter/json only
  patterns:
    - "Hand-rolled SSRF guard mirroring fin1te/safecurl: resolve -> validate every IP -> pin curl via CURLOPT_RESOLVE -> FOLLOWLOCATION off + manual per-hop re-guard -> protocol allowlist"
    - "Pure network-free service methods (isPublicIp/parse/convert) extracted so the security/logic layer is unit-testable; thin curl/DNS I/O kept separate and live-verified"
    - "Attacker-influenceable og:image fetched through the SAME guard, size-capped, base64 data: URI (no CSP change)"

key-files:
  created:
    - src/Services/ProductImportService.php
    - tests/ProductImportServiceTest.php
  modified: []

key-decisions:
  - "isPublicIp denies via FILTER_FLAG_NO_PRIV_RANGE|NO_RES_RANGE PLUS an explicit 169.254.169.254 const and a ::ffff: IPv4-mapped-IPv6 prefix denial the filter flags miss"
  - "fetch() pins curl to the validated IP via CURLOPT_RESOLVE and follows redirects manually re-running the full guard per hop (closes the DNS-rebinding TOCTOU)"
  - "convert() never silently mislabels: unknown currency OR null FX rate keeps the raw value with needs_verify=true (D-04)"
  - "parse() tries embedded JSON-LD Product first, then Open Graph meta, then <title>; empty/garbage returns all-null with no throw (D-01)"
  - "og:image preview reuses the same SSRF guard, caps at ~1.5MB, returns a base64 data: URI — no CSP edit (D-05a)"
  - "Used assertSame exclusively (including true/false/null) with test* method names, no PHPUnit attributes/data providers — repo convention + failOnWarning safety"

patterns-established:
  - "SSRF guard shape: resolve+validate-all-IPs+pin+manual-redirect-re-guard+protocol-allowlist (reuse for any future user-supplied-URL fetch)"
  - "Best-effort service contract: every failure mode returns a typed array (ok:false + French message) and error_log, never throws into the request path"

requirements-completed: [IMPORT-01]

# Metrics
duration: ~10min
completed: 2026-06-16
---

# Phase 10 Plan 01: ProductImportService Summary

**SSRF-guarded server-side product-page importer (resolve/validate/pin/re-guard/allowlist) with Open-Graph/JSON-LD/<title> parsing and best-effort EUR conversion, plus a green network-free Wave-0 unit test.**

## Performance

- **Duration:** ~10 min
- **Started:** 2026-06-16
- **Completed:** 2026-06-16
- **Tasks:** 2
- **Files modified:** 2 (both created)

## Accomplishments
- `ProductImportService` (`final`, `declare(strict_types=1)`, namespace `App\Services`) implementing the headline security control of the phase — a hand-rolled SSRF guard copied conceptually from `fin1te/safecurl` (resolve host A+AAAA → validate every IP with `isPublicIp` → pin curl to the validated IP via `CURLOPT_RESOLVE` → `CURLOPT_FOLLOWLOCATION` off with a manual per-hop re-guard → `CURLOPT_PROTOCOLS` http/https only → timeouts + body/image size caps + redirect hop cap).
- Three pure, network-free methods (`isPublicIp`, `parse`, `convert`) so the security/logic layer is unit-tested without any network call; the thin curl/DNS I/O (`fetch`, `fetchImageDataUri`, `resolveAndValidate`) stays separate for live verification.
- `parse()` does JSON-LD `Product` extraction first, then Open Graph `<meta>`, then `<title>` fallback, swallowing malformed-HTML warnings via `libxml_use_internal_errors(true)`.
- `convert()` reuses `ExchangeRateService::latest()` and never silently mislabels — unknown currency or failed FX keeps the raw value with `needs_verify=true`.
- Optional `og:image` preview fetched through the SAME guard, size-capped (~1.5 MB) and returned as a base64 `data:` URI (no CSP change).
- Blocking Wave-0 test (`tests/ProductImportServiceTest.php`, 14 tests / 34 assertions) covering SSRF allow/deny, OG/title parse, garbage/empty parse, and currency conversion.

## Task Commits

Each task was committed atomically:

1. **Task 1: Implement ProductImportService (SSRF guard + parse + convert + fromUrl)** - `fed1dc1` (feat)
2. **Task 2: Write the blocking Wave-0 unit test for the pure methods** - `ee34aa3` (test)

_Note: Task 1 carries `tdd="true"`; the blocking test landed as Task 2 per the plan's explicit task ordering. Both the filtered (`--filter ProductImportServiceTest`) and full suites are green._

## Files Created/Modified
- `src/Services/ProductImportService.php` - SSRF-guarded fetch + DOMDocument/JSON-LD parse + EUR convert + `fromUrl` orchestration; `isPublicIp`/`parse`/`convert` are pure and unit-testable.
- `tests/ProductImportServiceTest.php` - Network-free coverage of the SSRF IP guard, HTML extraction, and currency conversion (flat `tests/`, `namespace Tests;`, `assertSame` only).

## Decisions Made
- Set `CURLOPT_REDIR_PROTOCOLS` alongside `CURLOPT_PROTOCOLS` for defence-in-depth even though `FOLLOWLOCATION` is disabled (redirects are followed manually). Belt-and-braces, no behavioral cost.
- After-the-fact `strlen()` body/image cap in addition to `CURLOPT_MAXFILESIZE` to catch chunked responses where libcurl cannot pre-check `Content-Length`.
- `fetchImageDataUri` only emits a `data:` URI when the response `Content-Type` starts with `image/`, preventing a non-image payload from being smuggled into the preview.

## Deviations from Plan

None - plan executed exactly as written. (The JSON-LD-first / OG-fallback / title-last parse order, the SSRF option set, the metadata + `::ffff:` denials, the `data:`-URI image, and the no-silent-mislabel convert were all specified by the plan and implemented as described. No bugs, missing critical functionality, or blocking issues required auto-fix.)

## Issues Encountered
None. Both the filtered suite (14 tests, 34 assertions) and the full suite (41 tests, 64 assertions) passed first run with zero warnings under `failOnWarning="true"`.

## Known Stubs
None. All methods are fully wired: `isPublicIp`/`parse`/`convert` return real computed values; `fetch`/`fetchImageDataUri` perform real guarded I/O. No placeholder/empty-value returns flow to a UI.

## User Setup Required
None - no external service configuration required. Zero Composer packages added; all functionality uses bundled PHP extensions.

## Next Phase Readiness
- `ProductImportService::fromUrl()` is ready for Plan 10-02 to wire into `ProductController::fetchUrl()` (JSON action), the ordered `POST /products/fetch-url` route, the `form.php` input/button/preview, and the `app.js` fetch-populate-preview.
- The curl/DNS I/O paths (SSRF guard end-to-end, redirect re-guard, image fetch) are NOT unit-tested by design — Plan 10-03 must live-verify: a public URL is fetched and an obviously-internal URL (e.g. `http://169.254.169.254/`) is rejected with a clean French message.

## Self-Check: PASSED
- FOUND: src/Services/ProductImportService.php
- FOUND: tests/ProductImportServiceTest.php
- FOUND commit: fed1dc1 (Task 1)
- FOUND commit: ee34aa3 (Task 2)

---
*Phase: 10-product-url-auto-fill*
*Completed: 2026-06-16*
