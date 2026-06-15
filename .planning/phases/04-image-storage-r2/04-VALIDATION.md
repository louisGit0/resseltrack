# Phase 4 Validation Strategy

> `workflow.nyquist_validation` — treated as enabled. Extracted from 04-RESEARCH.md "Validation Architecture" as the phase's standalone validation artifact.

## Test Framework

| Property | Value |
|----------|-------|
| Framework | PHPUnit 11.x (`vendor/bin/phpunit`) |
| Config file | `phpunit.xml` (project root) |
| Coverage source | `src/Services/` (per REQUIREMENTS.md — business logic only) |

## Phase Requirements → Verification Map

| Req ID | Behavior | Wave 1 (code, automatable) | Wave 2 (live) |
|--------|----------|----------------------------|---------------|
| STORE-01 | Upload writes to R2 + stores r2.dev URL | `php -l` + grep: `R2Storage::put()` issues `putObject(Bucket/Key/SourceFile/ContentType)`, returns `R2_PUBLIC_BASE_URL.'/'.key`; `storeUploadedFile()` returns that URL | Live upload → stored URL is `pub-<hash>.r2.dev/...`; HEAD on it = 200 |
| STORE-02 | Image displays from r2.dev in prod | grep: CSP `img-src` includes `https://*.r2.dev`; full URL stored, rendered by existing views unchanged | Live product page renders image; no CSP violation; `curl -sI` image = 200 |
| STORE-03 | Delete removes the R2 object | grep: `deleteImage()` calls `R2Storage::delete()` best-effort; `deriveKey()` strips base URL; old `@unlink`/`is_file` block GONE | Live delete → HEAD on prior URL = 404 |
| STORE-04 | Existing local-path records migrated or fallback documented | N/A (prod DB empty — documented fallback, D-08) | Operator confirms no `/assets/uploads/` rows in prod DB |
| STORE-05 | ~3.5 MB size guard with clear message | grep: `MAX_UPLOAD_BYTES = (int)(3.5*1024*1024)` + FR message; `api/php.ini` `upload_max_filesize=5M`/`post_max_size=5M` | Live 3.5–4.5 MB upload → clear French message (no blank page) |

## Wave 0 Gaps (deferred unit tests — documented, not silent)

- `tests/Services/R2StorageTest.php` (put / delete / deriveKey / size guard) is **NOT written this phase**.
- **Why deferred:** D-04 locks a **parameterless** `R2Storage` constructor (reads `Env` directly, no `S3Client` injection point), and REQUIREMENTS.md scopes extended controller/Service-integration tests OUT ("seul `ProfitCalculator` reste testé"; coverage source = `src/Services/` business logic, and `R2Storage` is pure external I/O, not business logic). Mocking `Aws\S3\S3Client` would require adding an injection seam that the locked decision deliberately avoids.
- **Precedent:** mirrors Plan 03-01 (DatabaseSessionHandler — same I/O-not-business-logic rationale). Wave 1 automated gates are `php -l` lint + structural presence/absence greps; end-to-end STORE-01..05 behavior is proven **live against R2** in Wave 2 (Plan 04-02).
- If unit coverage is later desired, the cheapest path is an optional `?S3Client $client = null` constructor param (test-only injection) — explicitly out of scope now.

## Regression Guard

- `vendor/bin/phpunit` (ProfitCalculator suite) stays green — no Services business logic altered; only new I/O service + controller swap.
- Existing pages (dashboard, products list/show) still render — the stored path is just a different URL string, rendered by unchanged views.
