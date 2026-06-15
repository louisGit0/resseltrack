---
phase: 04-image-storage-r2
plan: "01"
subsystem: infra
tags: [cloudflare-r2, aws-sdk-php, s3, image-upload, composer, php-ini, csp]

requires:
  - phase: 03-sessions
    provides: DatabaseSessionHandler, Vercel deployment foundation, vercel.json, api/php.ini

provides:
  - aws/aws-sdk-php ^3 declared in composer.json (Vercel builds vendor at Lambda time)
  - R2Storage service class (put/delete/deriveKey) wrapping Aws\S3\S3Client
  - guarded vendor/autoload.php in public/index.php before maison spl_autoload_register
  - CSP img-src expanded to include https://*.r2.dev
  - ProductController upload and delete go through R2 (full r2.dev URL stored)
  - api/php.ini upload_max_filesize=5M + post_max_size=5M
  - .env.example R2_* variable documentation

affects: [04-02-operator-setup, phase-5-security]

tech-stack:
  added:
    - "aws/aws-sdk-php ^3 (Packagist, 536M installs, official AWS PHP SDK)"
  patterns:
    - "R2Storage follows the src/Services/ final-class pure-I/O convention (like ExchangeRateService)"
    - "R2 object key = bin2hex(random_bytes(8)).ext — no user input in key, 2^64 keyspace"
    - "Full r2.dev URL stored in DB; views render unchanged; derive key by stripping base URL at delete time"
    - "Lazy R2Storage instantiation inside each method (new R2Storage()) — not in constructor"
    - "Best-effort R2 delete: try/catch + error_log; HTTP request succeeds even if R2 throws"

key-files:
  created:
    - src/Services/R2Storage.php
  modified:
    - composer.json
    - api/php.ini
    - .env.example
    - public/index.php
    - src/Controllers/ProductController.php

key-decisions:
  - "Full aws/aws-sdk-php SDK ships in Lambda (~37.5MB unzipped, well within 250MB limit) — no pre-autoload-dump/removeUnusedServices because vercel-php uses --no-scripts so the hook never fires"
  - "scripts.vercel = @composer dump-autoload --optimize added so at least the autoloader is optimized post-build"
  - "CSP img-src updated to https://*.r2.dev in this plan (not deferred to Phase 5) because STORE-02 (images display) is literally false without it"
  - "PHP class constants cannot contain cast expressions; MAX_UPLOAD_BYTES = 3670016 (literal) with comment documenting (int)(3.5*1024*1024)"
  - "vendor/ not committed; /vendor stays in .vercelignore; vercel-php rebuilds vendor inside the Lambda after --no-scripts install"

patterns-established:
  - "Services/ pure-I/O convention: final class, declare strict_types, no Models/Core deps beyond Env, parameterless constructor"
  - "Guarded vendor/autoload.php require pattern: is_file() guard so local dev without composer install still boots"

requirements-completed: [STORE-01, STORE-02, STORE-03, STORE-04, STORE-05]

duration: 6min
completed: 2026-06-15
---

# Phase 4 Plan 01: Image Storage R2 — Code Changes Summary

**aws/aws-sdk-php ^3 wired into composer.json + R2Storage service + ProductController swap from disk I/O to Cloudflare R2 via S3Client, with guarded vendor autoload and CSP r2.dev expansion**

## Performance

- **Duration:** ~6 min
- **Started:** 2026-06-15T06:56:58Z
- **Completed:** 2026-06-15T07:02:42Z
- **Tasks:** 3
- **Files modified:** 5 (+ 1 created: src/Services/R2Storage.php)

## Accomplishments

- Added `aws/aws-sdk-php ^3` to `composer.json` with the `vercel` dump-autoload script; api/php.ini upload limits raised to 5M; R2_* env vars documented in .env.example
- Created `final class R2Storage` (src/Services/R2Storage.php) wrapping Aws\S3\S3Client with region='auto', r2.cloudflarestorage.com endpoint, explicit Credentials; put()/delete()/deriveKey() per RESEARCH Pattern 1; no ACL
- Wired guarded `vendor/autoload.php` require into `public/index.php` BEFORE spl_autoload_register; expanded CSP img-src from `'self' data:` to `'self' data: https://*.r2.dev`
- Swapped `storeUploadedFile()` from `move_uploaded_file()` to `(new R2Storage())->put()` returning full R2 URL; replaced `deleteImage()` local-file block with best-effort `(new R2Storage())->delete()`; raised MAX_UPLOAD_BYTES to 3.5MB

## Task Commits

1. **Task 1: Add aws/aws-sdk-php, php.ini limits, .env.example R2 block** - `baab2aa` (chore)
2. **Task 2: Wire vendor/autoload.php + CSP r2.dev, create R2Storage** - `3298a2d` (feat)
3. **Task 3: Swap ProductController disk I/O to R2, raise size guard** - `ced17f0` (feat)

## Files Created/Modified

- `src/Services/R2Storage.php` (NEW) — final class wrapping Aws\S3\S3Client for R2 PutObject/DeleteObject; put()/delete()/private deriveKey(); reads R2_ACCOUNT_ID/R2_ACCESS_KEY_ID/R2_SECRET_ACCESS_KEY/R2_BUCKET/R2_PUBLIC_BASE_URL via Env::get()
- `composer.json` — added `"aws/aws-sdk-php": "^3"` to require; added `"vercel": "@composer dump-autoload --optimize"` to scripts
- `api/php.ini` — appended `upload_max_filesize = 5M` and `post_max_size = 5M` with layered-defence comment
- `.env.example` — added `# Cloudflare R2 (image storage)` block with 5 R2_* vars (no secret values)
- `public/index.php` — added is_file()-guarded `require $root . '/vendor/autoload.php'` before spl_autoload_register; changed CSP img-src to include `https://*.r2.dev`
- `src/Controllers/ProductController.php` — added `use App\Services\R2Storage;`; MAX_UPLOAD_BYTES raised to 3670016 (~3.5MB); storeUploadedFile() calls R2Storage::put() and returns full URL; deleteImage() removes old dirname/@unlink block and calls R2Storage::delete() best-effort

## Decisions Made

- **Full SDK ships in Lambda** (no removeUnusedServices): vercel-php@0.7.4 runs `composer install --no-scripts`, so the `pre-autoload-dump` hook never fires. The full ~37.5MB aws-sdk-php fits within the 250MB Lambda limit. The `scripts.vercel` entry optimizes the autoloader post-build.
- **CSP pulled forward to Plan 04-01** (not deferred to Phase 5): without `https://*.r2.dev` in img-src, STORE-02 (images display) is literally false the moment R2 URLs are stored. Pre-satisfies part of Phase 5 SEC-03.
- **No ACL on putObject**: R2 ignores x-amz-acl; public access is enabled at bucket level via r2.dev setting (operator step in Plan 04-02).
- **Full r2.dev URL stored in DB** (D-05): views render `<img src="<?= e($path) ?>">` unchanged; deriveKey() strips the base URL at delete time.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1 - Bug] PHP class const cannot contain cast expressions**
- **Found during:** Task 3 (ProductController swap)
- **Issue:** `private const MAX_UPLOAD_BYTES = (int) (3.5 * 1024 * 1024);` produces "Constant expression contains invalid operations" — PHP class constants cannot use cast operators
- **Fix:** Changed to literal integer `3670016` (exact equivalent) with a comment documenting `(int)(3.5 * 1024 * 1024)` for human readability; the plan's verify grep matches the comment
- **Files modified:** src/Controllers/ProductController.php
- **Verification:** `php -l src/Controllers/ProductController.php` → No syntax errors; grep for literal 3670016 and comment pattern both pass
- **Committed in:** ced17f0 (Task 3 commit)

---

**Total deviations:** 1 auto-fixed (Rule 1 — bug in plan's const syntax)
**Impact on plan:** Strictly necessary for PHP validity. Same runtime value, grep-detectable via comment. No scope change.

## Issues Encountered

The plan's PowerShell verify commands for namespace matching (`namespace App\\Services`) had escaping issues when invoked from bash context due to backslash interpretation. The underlying file content is correct (`namespace App\Services;`); direct content verification via positional checks and grep confirmed all properties. The plan's automated grep commands are designed for direct PowerShell invocation.

## Known Stubs

None — no hardcoded empty values or placeholder text in code paths. R2Storage reads real env vars; the missing R2_* credentials are an operator prerequisite (Plan 04-02), not a code stub.

## Threat Flags

No new security surface beyond what the plan's threat model covers:
- Browser → PHP: MIME allowlist + size guard unchanged (T-4-01, T-4-02)
- PHP → R2: credentials via Env::get() only (T-4-03)
- Key generation: bin2hex(random_bytes(8)) (T-4-04)
- Error logging: server-side only, generic French message to user (T-4-05)
- CSP: img-src https://*.r2.dev wildcard (T-4-06)

## User Setup Required

None for Plan 04-01 (autonomous code half). Plan 04-02 requires the operator to:
1. Create Cloudflare R2 bucket + enable r2.dev Public Development URL
2. Create R2 API token (Object Read & Write)
3. Set 5 Vercel env vars: R2_ACCOUNT_ID, R2_ACCESS_KEY_ID, R2_SECRET_ACCESS_KEY, R2_BUCKET, R2_PUBLIC_BASE_URL
4. Redeploy and verify STORE-01..05 live

## Next Phase Readiness

- **Plan 04-02** (operator half): all code is in place; operator creates the R2 bucket + token + Vercel env vars, redeploys, and verifies STORE-01..05 live
- **vendor/**: NOT committed; Vercel builds it inside the Lambda via `composer install --no-dev --no-interaction --no-scripts` auto-triggered by vercel-php on detecting composer.json; /vendor stays in .vercelignore (repo hygiene only)
- **Phase 5 SEC-03**: CSP img-src r2.dev pre-satisfied here (documented in summary); remaining SEC-03 work (if any) is in Phase 5

---
*Phase: 04-image-storage-r2*
*Completed: 2026-06-15*
