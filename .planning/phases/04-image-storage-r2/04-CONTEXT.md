# Phase 4: Image Storage on Cloudflare R2 - Context

**Gathered:** 2026-06-12
**Status:** Ready for planning

<domain>
## Phase Boundary

Move product image **upload** and **delete** off the ephemeral serverless filesystem onto **Cloudflare R2** (S3-compatible) via `aws/aws-sdk-php`. Uploads write to R2 and store the public R2 URL in the DB; deletes remove the R2 object; the size guard is raised to ~3.5 MB with a clear French error.

Requirements: **STORE-01** (upload → R2 + URL in DB), **STORE-02** (images display from R2 URL in prod), **STORE-03** (delete removes the R2 object), **STORE-04** (existing local-path records migrated or documented fallback), **STORE-05** (~3.5 MB size guard with clear message, under Vercel's 4.5 MB limit).

**Out of scope (own phases / deferred):** HSTS / CSP img-src for R2 domain (Phase 5 — SEC-03), custom R2 domain (v2), image resizing/thumbnails (not in scope), gallery UX redesign.
</domain>

<decisions>
## Implementation Decisions

### SDK & dependency bundling
- **D-01:** Add **`aws/aws-sdk-php` ^3** to `composer.json` `require` (S3 client; R2 is S3-compatible). Locked engine: Cloudflare R2, NOT Vercel Blob (PROJECT.md).
- **D-02:** **`vendor/` is NOT committed to git.** Dependencies are installed at **build time** on Vercel. RESEARCH must confirm the exact mechanism for `vercel-php@0.7.4` (auto `composer install` on detecting `composer.json`, OR an explicit `"buildCommand": "composer install --no-dev --optimize-autoloader"` in `vercel.json`) and confirm that `/vendor` in `.vercelignore` does not break the built vendor in the Lambda (the source upload excludes vendor; the build regenerates it). Keep the repo clean — no hundreds of committed SDK files.
- **D-03:** The maison autoloader stays for `App\`. `public/index.php` must additionally `require` `vendor/autoload.php` **guarded by `is_file()`** (so local Docker without vendor and the serverless runtime both work) BEFORE app classes that use the SDK are hit. Confirm load order with the existing `spl_autoload_register`.

### Storage service
- **D-04:** New `final class R2Storage` in `src/Services/` wrapping `Aws\S3\S3Client` configured for R2: endpoint `https://<R2_ACCOUNT_ID>.r2.cloudflarestorage.com`, `region => 'auto'`, credentials from env. Methods: `put(string $tmpPath, string $key, string $contentType): string` (returns the full public URL), `delete(string $keyOrUrl): void` (best-effort). Reads config via `Env`: `R2_ACCOUNT_ID`, `R2_ACCESS_KEY_ID`, `R2_SECRET_ACCESS_KEY`, `R2_BUCKET`, `R2_PUBLIC_BASE_URL`. Pure I/O service, no Models/Core deps (matches `src/Services/` convention).

### URL & object keys
- **D-05:** **Store the full public R2 URL** (`R2_PUBLIC_BASE_URL + '/' + key`) in `products.image_path` and `product_images.path`. Views render `<img src="<?= e($path) ?>">` as-is → **ZERO view changes**. Object **key is flat** `random.ext` (reuse the existing `bin2hex(random_bytes(8)) . '.' . ext` naming). To delete, derive the key from the stored URL (strip the base).

### Upload/delete wiring
- **D-06:** In `ProductController`: `storeUploadedFile()` replaces `move_uploaded_file()` → `R2Storage::put()` and returns the full R2 URL (keep the MIME allowlist jpeg/png/webp/gif and the `[url|null, error|null]` tuple shape). `deleteImage()` replaces `@unlink()` → `R2Storage::delete()` (best-effort, log on failure, do NOT fail the request). `uploadImages()` (gallery) and `handleUpload()` (cover) go through the same `R2Storage`. The `public/assets/uploads/` path is abandoned for new uploads.

### Size guard & PHP limits (STORE-05)
- **D-07:** Raise `ProductController::MAX_UPLOAD_BYTES` from 2 MB to **3.5 MB** (`(int) (3.5 * 1024 * 1024)`), keep the clear French over-limit message. Set `upload_max_filesize = 5M` and `post_max_size = 5M` in `api/php.ini` so PHP does not silently empty `$_FILES` before the app-level guard fires. Document that uploads **> 4.5 MB** are rejected by Vercel with a raw 413 before PHP runs (out of PHP scope, acceptable).

### Existing images & orphans (STORE-04)
- **D-08:** Production DB is **empty (0 products)** → no migration needed. Documented fallback: local Docker seed images (`/assets/uploads/...`) remain local-only and are not used in prod. Orphan handling is **best-effort**: if an R2 delete fails (object already gone, network), log and continue — no distributed transaction; if a DB write fails after an R2 put, the orphaned object is acceptable (logged).

### Operator steps (autonomous:false)
- **D-09:** Create a **Cloudflare R2 bucket**, enable **public access** (r2.dev dev URL), create an **R2 API token** (Access Key ID + Secret). Set Vercel env vars `R2_ACCOUNT_ID`, `R2_ACCESS_KEY_ID`, `R2_SECRET_ACCESS_KEY`, `R2_BUCKET`, `R2_PUBLIC_BASE_URL` (the r2.dev base). Add the same to `.env.example` (no secrets) + local `.env`.

### UI note
- **D-10:** The only user-facing UI change is the existing flash error for over-limit uploads (STORE-05) — no new components. A full `/gsd:ui-phase` is unnecessary; the planner should keep the current French flash mechanism with a clear size message.
</decisions>

<canonical_refs>
## Canonical References

### Requirements & decisions
- `.planning/REQUIREMENTS.md` §Stockage (STORE-01..05)
- `.planning/PROJECT.md` §Key Decisions — "Cloudflare R2 + aws/aws-sdk-php v3 (NOT Vercel Blob)"
- `.planning/STATE.md` §Blockers — Phase 4 existing-images note (now moot: prod empty)
- `.planning/phases/02-database-and-schema-migration/02-CONTEXT.md` — operator env-var + Vercel rhythm reused for R2 creds

### Code to modify / reference
- `src/Controllers/ProductController.php` — `storeUploadedFile()` (line ~469), `handleUpload()` (~450), `uploadImages()` (~303), `deleteImage()` (~360), `MAX_UPLOAD_BYTES` (~446)
- `src/Models/ProductImage.php` — `create(path)` / `delete()` (path stored as-is; now an R2 URL)
- `src/Views/products/show.php`, `index.php` — render `image_path`/`path` directly (no change needed, D-05)
- `public/index.php` — add guarded `vendor/autoload.php` require (D-03)
- `composer.json` — add `aws/aws-sdk-php` (D-01)
- `api/php.ini` — `upload_max_filesize` + `post_max_size` (D-07)
- `vercel.json` — possible `buildCommand` (D-02, pending research)
- `.vercelignore`, `.env.example` — vendor exclusion + R2 vars
</canonical_refs>

<code_context>
## Existing Code Insights

### Reusable Assets
- `storeUploadedFile()` already returns a `[path|null, error|null]` tuple and owns the MIME allowlist + naming — swap the destination from disk to R2, keep the contract.
- `ProductImage::create($userId, $productId, $path)` stores an opaque `path` string — works unchanged with a full R2 URL.
- Views render the stored path directly in `<img src>` — storing the full URL means no template edits (D-05).
- `Env::get()` config pattern (used by Database/Session) — R2Storage reads its config the same way.

### Established Patterns
- `src/Services/` = pure I/O/business logic, no Core/Models deps (ProfitCalculator, ExchangeRateService, CsvExporter). `R2Storage` fits here.
- `final` classes, `declare(strict_types=1)`, PSR-4 `App\Services\`.

### Integration Points
- This is the FIRST third-party Composer runtime dependency — it forces the vendor/autoload.php integration (D-03) and the build-time install (D-02), which are net-new to the deploy pipeline established in Phases 1–3.
- R2 creds become Vercel env vars (same operator pattern as Aiven DB_* and SESSION_SECURE).
</code_context>

<specifics>
## Specific Ideas
- Reuse the Phase 2/3 operator rhythm: autonomous code (SDK wiring, R2Storage, controller swap, guards) → operator (create R2 bucket + creds, set Vercel env vars) → live verify (upload displays from r2.dev, delete returns 404, >3.5 MB shows the FR message).
- Storing the full URL keeps the blast radius minimal (no view/helper changes), trading a little flexibility (custom domain later = re-write stored URLs) which is acceptable and deferred.
</specifics>

<deferred>
## Deferred Ideas
- Custom R2 domain (v2; r2.dev dev URL is fine now) — already in STATE Deferred Items.
- Image resizing / thumbnails / WebP conversion — not in scope.
- Migration script for pre-existing local images — unnecessary (prod empty); only revisit if real local-path rows ever exist in prod.
- Strict transactional orphan cleanup — deferred; best-effort + log this phase.

### Research Questions (for gsd-phase-researcher)
- Does `vercel-php@0.7.4` auto-run `composer install` when `composer.json` is present, or is an explicit `vercel.json` `buildCommand` required? Does `/vendor` in `.vercelignore` interfere with the built vendor being available in the Lambda? (Pin the exact, working approach.)
- Exact `Aws\S3\S3Client` config for Cloudflare R2: endpoint, `region => 'auto'`, `use_path_style_endpoint`, signature version, and `PutObject` params (`ContentType`, ACL — R2 ignores ACLs; public access is via the bucket's r2.dev setting, not per-object ACL).
- R2 public access: how `R2_PUBLIC_BASE_URL` (r2.dev) is obtained and the resulting object URL shape; whether public-dev-URL must be explicitly enabled.
- aws-sdk-php footprint in a 256 MB Lambda (memory_limit) — confirm it loads; consider `aws/aws-sdk-php` vs a slimmed install. maxDuration 30 OK for puts.
- `vendor/autoload.php` + maison `spl_autoload_register` coexistence and load order in `public/index.php`.
- Deriving the object key from the stored full URL for deletes (strip `R2_PUBLIC_BASE_URL`).
</deferred>

---

*Phase: 4-Image Storage on Cloudflare R2*
*Context gathered: 2026-06-12*
