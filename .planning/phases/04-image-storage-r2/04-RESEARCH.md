# Phase 4: Image Storage on Cloudflare R2 — Research

**Researched:** 2026-06-12
**Domain:** Cloudflare R2 (S3-compatible object storage) + aws/aws-sdk-php v3 + vercel-php build pipeline
**Confidence:** HIGH (all six specific research questions resolved from primary sources)

---

<user_constraints>
## User Constraints (from CONTEXT.md)

### Locked Decisions

- **D-01:** Add `aws/aws-sdk-php ^3` to `composer.json` `require`. Engine is Cloudflare R2, NOT Vercel Blob.
- **D-02:** `vendor/` NOT committed. Installed at build time on Vercel. Research must confirm the exact mechanism (see Open Questions — all RESOLVED below).
- **D-03:** Maison autoloader stays for `App\`. `public/index.php` must additionally `require vendor/autoload.php` guarded by `is_file()`, BEFORE app classes that use the SDK.
- **D-04:** New `final class R2Storage` in `src/Services/` wrapping `Aws\S3\S3Client`. Methods: `put(string $tmpPath, string $key, string $contentType): string`, `delete(string $keyOrUrl): void`. Config via `Env`: `R2_ACCOUNT_ID`, `R2_ACCESS_KEY_ID`, `R2_SECRET_ACCESS_KEY`, `R2_BUCKET`, `R2_PUBLIC_BASE_URL`.
- **D-05:** Store full public R2 URL in `products.image_path` / `product_images.path`. Flat key `random.ext`. Derive key from URL for deletes. Zero view changes.
- **D-06:** `storeUploadedFile()` replaces `move_uploaded_file()` → `R2Storage::put()`. `deleteImage()` replaces `@unlink()` → `R2Storage::delete()` (best-effort). Same `[url|null, error|null]` tuple contract.
- **D-07:** `MAX_UPLOAD_BYTES` 2 MB → 3.5 MB. `api/php.ini`: `upload_max_filesize = 5M` + `post_max_size = 5M`. >4.5 MB = Vercel raw 413 (out of PHP scope).
- **D-08:** Prod DB empty → no migration needed. Best-effort orphan handling: log and continue on delete failure or post-put DB failure.
- **D-09 (operator):** Create R2 bucket + enable r2.dev public URL + create API token. Set 5 Vercel env vars. Autonomous: false.

### Claude's Discretion

None specified.

### Deferred Ideas (OUT OF SCOPE)

- Custom R2 domain (v2)
- Image resizing / thumbnails / WebP conversion
- Migration script for pre-existing local images (prod empty, not needed)
- Strict transactional orphan cleanup
- HSTS / CSP img-src for R2 domain (Phase 5 — SEC-03)
</user_constraints>

---

<phase_requirements>
## Phase Requirements

| ID | Description | Research Support |
|----|-------------|-----------------|
| STORE-01 | Upload d'une image produit écrit sur Cloudflare R2 + URL publique en base | R2Storage::put() → S3Client PutObject → returns R2_PUBLIC_BASE_URL + '/' + key; stored in DB |
| STORE-02 | Images uploadées s'affichent en production depuis leur URL R2 | Full URL stored in path column; `<img src="<?= e($path) ?>">` renders directly; zero view changes |
| STORE-03 | Suppression d'une image produit retire l'objet correspondant sur R2 | R2Storage::delete() → S3Client DeleteObject; key derived by stripping R2_PUBLIC_BASE_URL from stored URL |
| STORE-04 | Images existantes (chemins disque locaux) migrées ou stratégie de repli documentée | Prod DB empty: no migration needed. Local-path records only exist in local Docker seed; documented fallback sufficient |
| STORE-05 | Garde de taille (~3,5 Mo) empêche uploads dépassant la limite Vercel (4,5 Mo) | MAX_UPLOAD_BYTES raised to (int)(3.5*1024*1024); api/php.ini upload_max_filesize=5M + post_max_size=5M |
</phase_requirements>

---

## Summary

Phase 4 moves product image upload and delete from the ephemeral Vercel filesystem to Cloudflare R2 using `aws/aws-sdk-php` v3. R2 exposes an S3-compatible API; the official PHP client works with a single custom `endpoint` and `region => 'auto'` — no special driver needed.

The two highest-risk unknowns were the vercel-php build mechanism for Composer and the correct S3Client config for R2. Both are fully resolved. The vercel-php@0.7.4 runtime auto-detects `composer.json` and runs `composer install --no-dev --no-interaction --no-scripts` without any `buildCommand` in `vercel.json`. The `.vercelignore /vendor` entry correctly prevents uploading the local vendor directory; the build then regenerates it and bundles it into the Lambda via a glob that explicitly ignores `.vercelignore`. The resulting Lambda is well within Vercel's 250 MB uncompressed limit.

The code changes are confined to three files: `composer.json` (add dep + slimming config), `api/php.ini` (add upload limits), `public/index.php` (add guarded vendor require), and `src/Controllers/ProductController.php` (swap move_uploaded_file → R2Storage::put, @unlink → R2Storage::delete). One new file: `src/Services/R2Storage.php`.

**Primary recommendation:** Proceed with `aws/aws-sdk-php ^3` as-is using the `removeUnusedServices` composer script (enabled via the `scripts.vercel` workaround for the `--no-scripts` build flag) to keep the Lambda lean. If that adds complexity, the full ~37.5 MB vendor/aws is acceptable within the 250 MB limit.

---

## Architectural Responsibility Map

| Capability | Primary Tier | Secondary Tier | Rationale |
|------------|-------------|----------------|-----------|
| Image upload (binary receive) | API / PHP function | — | PHP receives multipart/form-data via $_FILES; Lambda tmp filesystem available during request |
| Image upload (object store) | External service (Cloudflare R2) | — | Persistent storage off-Lambda; S3-compatible API via aws-sdk-php |
| Image display | CDN / Static (r2.dev) | Browser | Stored URL served directly from r2.dev; PHP is not in the display path |
| Image delete (object removal) | API / PHP function | External service (R2) | PHP calls S3 DeleteObject; best-effort, no transaction |
| Size guard | API / PHP function | — | App-level check before SDK call; php.ini guards against silent $_FILES truncation |
| URL storage | Database / Storage | — | Full r2.dev URL in products.image_path / product_images.path columns |
| Key derivation for delete | API / PHP function | — | Strip R2_PUBLIC_BASE_URL prefix from stored URL at delete time |

---

## Standard Stack

### Core

| Library | Version | Purpose | Why Standard |
|---------|---------|---------|--------------|
| `aws/aws-sdk-php` | `^3` (current: 3.384.8) | S3-compatible client for R2 PutObject / DeleteObject | Official AWS SDK; 536M+ Packagist installs; R2 is S3-compatible; Cloudflare R2 docs use this as the reference PHP client |

[VERIFIED: Packagist] — `aws/aws-sdk-php` 3.384.8, released 2026-06-11, 536M total installs, GitHub: aws/aws-sdk-php.

### Supporting

| Library | Version | Purpose | When to Use |
|---------|---------|---------|-------------|
| `Aws\Credentials\Credentials` | bundled with SDK | Inject R2 access key + secret into S3Client | Always; constructor injection, never implicit credential chain (no EC2 metadata on Vercel) |
| `Aws\S3\S3Client` | bundled | S3 API adapter for R2 | The single surface used in R2Storage |

### Alternatives Considered

| Instead of | Could Use | Tradeoff |
|------------|-----------|----------|
| `aws/aws-sdk-php` | Hand-rolled Guzzle + AWS Signature V4 | Re-implementing SigV4 signing is error-prone. Don't hand-roll. |
| `aws/aws-sdk-php` | `cloudflare/r2` (if existed) | No official Cloudflare PHP SDK for R2; only S3-compatible SDKs are documented |

**Installation:**
```bash
composer require aws/aws-sdk-php:^3
```

**With S3-only slimming (optional but recommended — see Pitfall 5):**
```json
"scripts": {
    "pre-autoload-dump": "Aws\\Script\\Composer\\Composer::removeUnusedServices",
    "vercel": "@composer dump-autoload --optimize"
},
"extra": {
    "aws/aws-sdk-php": ["S3"]
}
```

**Version verification:**
```
composer show aws/aws-sdk-php → 3.384.8 (confirmed 2026-06-12)
```

---

## Package Legitimacy Audit

| Package | Registry | Age | Downloads | Source Repo | slopcheck | Disposition |
|---------|----------|-----|-----------|-------------|-----------|-------------|
| `aws/aws-sdk-php` | Packagist | ~12 yrs | 536M total | github.com/aws/aws-sdk-php | [OK] | Approved |

**Packages removed due to slopcheck [SLOP] verdict:** none

**Packages flagged as suspicious [SUS]:** none

*slopcheck confirmed [OK] on Packagist (2026-06-12). Packagist registry verified via `packagist.org/packages/aws/aws-sdk-php`. This is the official AWS-maintained PHP SDK, consistent with Cloudflare's own documentation pointing to it as the reference PHP client for R2.*

---

## Architecture Patterns

### System Architecture Diagram

```
HTTP POST multipart/form-data
        │
        ▼
[api/index.php → public/index.php]
        │ Router dispatch
        ▼
[ProductController::storeUploadedFile()]
        │ MIME check + size guard (≤3.5MB)
        │
        ├─── FAIL ──→ return [null, $errorMessage]
        │
        ▼
[R2Storage::put($tmpPath, $key, $contentType)]
        │ Aws\S3\S3Client::putObject
        │ endpoint: https://<ACCOUNT_ID>.r2.cloudflarestorage.com
        │ Bucket: R2_BUCKET, Key: $key, SourceFile: $tmpPath
        ▼
[Cloudflare R2 bucket]
        │ returns void (SDK)
        ▼
return R2_PUBLIC_BASE_URL + '/' + $key   ← full r2.dev URL
        │
        ▼
[DB: products.image_path / product_images.path = full URL]
        │
        ▼
[View: <img src="<?= e($path) ?>">]  ← r2.dev served directly, PHP not involved


HTTP POST /products/{id}/images/{imageId}/delete
        │
        ▼
[ProductController::deleteImage()]
        │ imageModel->delete() — DB row gone first
        ▼
[R2Storage::delete($storedUrl)]
        │ derive key: ltrim(substr($url, strlen($baseUrl)), '/')
        │ Aws\S3\S3Client::deleteObject
        ▼
[Cloudflare R2]  ← best-effort; exceptions caught + logged
```

### Recommended Project Structure

```
src/
├── Services/
│   ├── R2Storage.php        # NEW — wraps Aws\S3\S3Client for R2
│   ├── ProfitCalculator.php # existing
│   ├── ExchangeRateService.php # existing
│   └── CsvExporter.php      # existing
├── Controllers/
│   └── ProductController.php # MODIFIED — storeUploadedFile + deleteImage
public/
└── index.php                 # MODIFIED — add guarded vendor/autoload.php require
api/
└── php.ini                   # MODIFIED — add upload_max_filesize, post_max_size
composer.json                 # MODIFIED — add aws/aws-sdk-php
```

### Pattern 1: R2Storage Service Class

**What:** Pure I/O service (no Models/Core deps) wrapping `Aws\S3\S3Client`. `final`, `declare(strict_types=1)`, reads config via `Env::get()`. Matches project's existing `src/Services/` conventions (ProfitCalculator, ExchangeRateService, CsvExporter).

**When to use:** Injected by ProductController where disk I/O used to be. Tests mock or stub this class.

```php
<?php
// Source: official Cloudflare R2 aws-sdk-php docs + aws-sdk-php docs.aws.amazon.com
declare(strict_types=1);

namespace App\Services;

use App\Core\Env;
use Aws\Credentials\Credentials;
use Aws\S3\S3Client;
use Aws\Exception\AwsException;

final class R2Storage
{
    private S3Client $client;
    private string $bucket;
    private string $publicBaseUrl;

    public function __construct()
    {
        $accountId = (string) Env::get('R2_ACCOUNT_ID');
        $this->bucket = (string) Env::get('R2_BUCKET');
        $this->publicBaseUrl = rtrim((string) Env::get('R2_PUBLIC_BASE_URL'), '/');

        $this->client = new S3Client([
            'version'     => 'latest',
            'region'      => 'auto',
            'endpoint'    => 'https://' . $accountId . '.r2.cloudflarestorage.com',
            'credentials' => new Credentials(
                (string) Env::get('R2_ACCESS_KEY_ID'),
                (string) Env::get('R2_SECRET_ACCESS_KEY')
            ),
        ]);
    }

    /**
     * Upload a local tmp file to R2 and return its full public URL.
     * @throws \RuntimeException on SDK failure
     */
    public function put(string $tmpPath, string $key, string $contentType): string
    {
        $this->client->putObject([
            'Bucket'      => $this->bucket,
            'Key'         => $key,
            'SourceFile'  => $tmpPath,
            'ContentType' => $contentType,
            // No ACL: R2 ignores x-amz-acl; public access is via the bucket r2.dev setting
        ]);
        return $this->publicBaseUrl . '/' . $key;
    }

    /**
     * Delete an R2 object by its stored URL or bare key. Best-effort — callers catch exceptions.
     */
    public function delete(string $keyOrUrl): void
    {
        $key = $this->deriveKey($keyOrUrl);
        $this->client->deleteObject([
            'Bucket' => $this->bucket,
            'Key'    => $key,
        ]);
    }

    private function deriveKey(string $keyOrUrl): string
    {
        // If it starts with the public base URL, strip it to get the bare key
        if (str_starts_with($keyOrUrl, $this->publicBaseUrl)) {
            return ltrim(substr($keyOrUrl, strlen($this->publicBaseUrl)), '/');
        }
        // Already a bare key (future-proof)
        return ltrim($keyOrUrl, '/');
    }
}
```

### Pattern 2: Guarded vendor/autoload.php in public/index.php

**What:** Load Composer's autoloader (required for `Aws\*` classes) BEFORE the project's spl_autoload_register. Guard with `is_file()` so local dev without `composer install` and Lambda (vendor present) both work.

**When to use:** Single change at the top of `public/index.php`, immediately after `$root = dirname(__DIR__)`.

```php
// Source: vercel-php official php-composer example + D-03 decision
$root = dirname(__DIR__);

// Composer vendor autoload — absent in local dev without `composer install`, present on Vercel Lambda
if (is_file($root . '/vendor/autoload.php')) {
    require $root . '/vendor/autoload.php';
}

// PSR-4 autoloader for App\ namespace (existing, unchanged)
spl_autoload_register(static function (string $class) use ($root): void {
    $prefix = 'App\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $relative = substr($class, strlen($prefix));
    $file = $root . '/src/' . str_replace('\\', '/', $relative) . '.php';
    if (is_file($file)) {
        require $file;
    }
});
```

**Rationale for ordering:** Composer's autoloader handles `Aws\*`, `GuzzleHttp\*`, etc. The spl_autoload_register handles `App\*`. Both can coexist with either order since they handle completely non-overlapping namespaces. Convention: Composer first.

### Pattern 3: storeUploadedFile() R2 Swap

**What:** Replace `move_uploaded_file()` → `R2Storage::put()`. Keep the same `[?string, ?string]` return tuple. Keep MIME allowlist and key generation unchanged. Return the full R2 URL instead of a local web path.

```php
// Source: D-06 + existing code at ProductController.php:469
private const MAX_UPLOAD_BYTES = (int) (3.5 * 1024 * 1024); // STORE-05: raised from 2 MB

private function storeUploadedFile(string $tmp, int $size): array
{
    if ($size > self::MAX_UPLOAD_BYTES) {
        return [null, 'elle dépasse la taille maximale autorisée (~3,5 Mo).'];
    }
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime  = finfo_file($finfo, $tmp);
    finfo_close($finfo);

    $allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif'];
    if (!isset($allowed[$mime])) {
        return [null, 'format non supporté (JPEG, PNG, WebP ou GIF attendu).'];
    }

    $key = bin2hex(random_bytes(8)) . '.' . $allowed[$mime];
    try {
        $url = (new R2Storage())->put($tmp, $key, $mime);
    } catch (\Throwable $e) {
        error_log('R2 put failed: ' . $e->getMessage());
        return [null, 'échec de l\'enregistrement du fichier.'];
    }
    return [$url, null];
}
```

### Pattern 4: deleteImage() R2 Swap

**What:** Replace `is_file()` + `@unlink()` → `R2Storage::delete()` (best-effort). DB row is deleted first (existing behaviour), then R2 object is removed. Exceptions are caught and logged; the HTTP request succeeds regardless.

```php
// Source: D-06 + D-08 + existing code at ProductController.php:375
$imageModel->delete((int) $image['id'], $userId);

try {
    (new R2Storage())->delete((string) $image['path']);
} catch (\Throwable $e) {
    error_log('R2 delete failed for path ' . $image['path'] . ': ' . $e->getMessage());
    // best-effort: continue, the DB row is already gone
}
```

### Anti-Patterns to Avoid

- **Hard-coding credentials in R2Storage:** Always read from `Env::get()`. Same pattern as Database, Session.
- **Storing only the object key in the DB:** D-05 locks in storing the full URL. Store the complete r2.dev URL — views render it directly, zero changes.
- **Using ACL on PutObject:** R2 ignores `x-amz-acl`. Passing it causes no error but wastes a param. Omit it. Public access comes from the bucket's r2.dev setting, not per-object ACL.
- **Constructing the delete key from the object key column:** With D-05, the stored value is the full URL. Derive the key by stripping the base URL prefix at delete time.
- **Using `@unlink()` with an R2 URL:** `is_file('https://pub-xxx.r2.dev/abc.jpg')` will always return false. The old local-file deletion code must be removed entirely, not just guarded.
- **Instantiating R2Storage eagerly in the controller constructor:** Construct it lazily inside the methods that need it (same as ExchangeRateService pattern). Avoids SDK bootstrap cost on every request.

---

## Don't Hand-Roll

| Problem | Don't Build | Use Instead | Why |
|---------|-------------|-------------|-----|
| AWS Signature V4 signing | Custom Guzzle + HMAC-SHA256 headers | `Aws\S3\S3Client` | SigV4 is complex (canonical request, signed headers, security tokens). AWS SDK handles it correctly. |
| Multipart upload reassembly | Manual chunking + concatenation | `S3Client::putObject` with `SourceFile` | SDK handles single-part for files < 5 GB automatically. |
| Presigned URL generation | Manual URL construction | `S3Client::createPresignedRequest` (if needed later) | Already in SDK; not needed for this phase but available. |
| R2 S3 API compatibility shim | Custom adapter | None needed; use `Aws\S3\S3Client` directly | R2 implements the S3 API natively; no shim needed. |

**Key insight:** The hard part is AWS Signature V4. It is exactly one correct implementation (`aws/aws-sdk-php`) versus infinite ways to get it wrong.

---

## Open Questions

All six specific research questions from the context are RESOLVED:

1. **vercel-php@0.7.4 composer install mechanism** `[RESOLVED — HIGH confidence]`
   - What we found: `src/index.ts` of vercel-community/php confirms auto-detection of `composer.json` and runs `composer install --no-dev --no-interaction --no-scripts --ignore-platform-reqs --no-progress`. No explicit `buildCommand` needed.
   - The `.vercelignore /vendor` only blocks the local vendor from being uploaded. The `harvestedFiles` glob runs AFTER `runComposerInstall(workPath)` inside the build step and explicitly ignores `.vercelignore` — so the freshly-built `vendor/` IS included in the Lambda. No interference.
   - **Critical nuance:** `--no-scripts` means `pre-autoload-dump` events don't fire. The `removeUnusedServices` optimization WILL NOT run automatically. Workaround: add a `scripts.vercel` entry to trigger `composer dump-autoload --optimize` after `runComposerInstall`; or accept the full 37.5 MB sdk (within the 250 MB Lambda limit).
   - Source: `gh api repos/vercel-community/php/contents/src/index.ts` (direct source inspection) + `gh api repos/juicyfx/vercel-examples/contents/php-composer` (official example confirming no buildCommand).

2. **Exact `Aws\S3\S3Client` config for Cloudflare R2** `[RESOLVED — HIGH confidence]`
   - Config: `version => 'latest'`, `region => 'auto'`, `endpoint => 'https://<ACCOUNT_ID>.r2.cloudflarestorage.com'`, `credentials => new Credentials($key, $secret)`. No `use_path_style_endpoint` needed per official Cloudflare docs.
   - PutObject: `Bucket`, `Key`, `SourceFile` (OR `Body` + `Body` stream), `ContentType`. **No ACL param** (R2 ignores `x-amz-acl`; docs explicitly state it is unsupported).
   - DeleteObject: `Bucket`, `Key`.
   - Source: `developers.cloudflare.com/r2/examples/aws/aws-sdk-php/` (official Cloudflare R2 docs).

3. **R2 public access: how R2_PUBLIC_BASE_URL is obtained** `[RESOLVED — HIGH confidence]`
   - Enable via bucket Settings → Public Development URL → Enable → type "allow".
   - URL format: `https://pub-<32-hex-chars>.r2.dev` (base). Object URL: `https://pub-xxx.r2.dev/<key>`.
   - The base URL is shown in the bucket dashboard under "Public Bucket URL" after enabling.
   - Rate-limited dev URL: not cached at edge, not for production CDN scale. Acceptable for this phase; custom domain is deferred to v2.
   - Source: `developers.cloudflare.com/r2/buckets/public-buckets/`.

4. **aws-sdk-php footprint in the Vercel Lambda** `[RESOLVED — MEDIUM confidence]`
   - Full `vendor/aws` folder: ~37.5 MB unzipped (per community measurements).
   - Vercel Lambda limit: 250 MB unzipped including all layers.
   - PHP runtime layer (vercel-php bundles) is ~50–90 MB.
   - Total (PHP runtime ~80 MB + aws-sdk-php ~37.5 MB + app ~2 MB = ~120 MB) is well within 250 MB.
   - `--no-scripts` prevents automatic `removeUnusedServices` slimming.
   - **Recommendation:** Accept full SDK for simplicity. Add `scripts.vercel` → `@composer dump-autoload --optimize` to at least keep the autoloader lean. If size warnings appear at deploy, add the `removeUnusedServices` workaround.
   - Source: `github.com/aws/aws-sdk-php/discussions/2420` + `vercel.com/docs/functions/limitations`.

5. **vendor/autoload.php + maison spl_autoload_register coexistence** `[RESOLVED — HIGH confidence]`
   - Add `if (is_file($root . '/vendor/autoload.php')) { require $root . '/vendor/autoload.php'; }` in `public/index.php` BEFORE the existing `spl_autoload_register`.
   - No namespace conflict: Composer handles `Aws\*`, `GuzzleHttp\*` etc.; the project's spl_autoload_register handles `App\*` exclusively (it early-returns if the class name does not start with `App\`).
   - The `is_file()` guard: local dev without composer install → vendor/autoload.php absent → Aws\* classes never referenced → app boots normally. Vercel Lambda → vendor/autoload.php present → loads Composer autoloader → Aws\* resolves.
   - Source: Inspection of `public/index.php` + vercel-php official php-composer example.

6. **Deriving the object key from the stored full URL** `[RESOLVED — HIGH confidence]`
   - `$key = ltrim(substr($url, strlen($publicBaseUrl)), '/');`
   - Example: `"https://pub-abc.r2.dev/a1b2c3d4.jpg"` with base `"https://pub-abc.r2.dev"` → `"a1b2c3d4.jpg"`.
   - R2Storage::deriveKey() handles this internally; callers pass the stored path as-is.
   - Source: Logical derivation from D-05 URL structure.

---

## Common Pitfalls

### Pitfall 1: `.vercelignore /vendor` — Misreading Its Scope

**What goes wrong:** Developer adds `/vendor` to `.vercelignore`, assumes the Lambda won't have `vendor/` and that SDK classes won't be available.

**Why it happens:** `.vercelignore` controls what Vercel uploads from the local machine as *source files*. The `harvestedFiles` glob inside the vercel-php build step explicitly ignores `.vercelignore` and runs against `workPath` (after `runComposerInstall` has already built `vendor/`). The built vendor is always bundled into the Lambda.

**How to avoid:** Keep `/vendor` in `.vercelignore` (correct for repo hygiene). Trust the runtime. Source: `src/index.ts` lines showing `harvestedFiles` glob ignores `.vercelignore`.

**Warning signs:** If vendor is somehow missing in the Lambda, it means `composer.json` was absent from the build input or the build step failed silently. Check Vercel build logs for `🐘 Installing Composer dependencies`.

---

### Pitfall 2: `--no-scripts` blocks removeUnusedServices

**What goes wrong:** Developer adds `pre-autoload-dump` → `removeUnusedServices` to `composer.json` expecting the SDK to be slimmed to S3 only, but the full 37.5 MB SDK is deployed.

**Why it happens:** `runComposerInstall` in vercel-php passes `--no-scripts`, which suppresses ALL Composer script events including `pre-autoload-dump`. The service removal script never fires.

**How to avoid:** Either (a) accept the full SDK (37.5 MB — within the 250 MB limit); or (b) add `"vercel": "@composer dump-autoload --optimize"` to `composer.json scripts`. The `runComposerScripts` step in vercel-php runs `composer run vercel` AFTER `runComposerInstall`, so the `vercel` script fires with scripts enabled. For true S3-only slimming, you'd need `removeUnusedServices` to run before dump — achievable via a more complex `vercel` script, but not strictly necessary for this phase.

**Warning signs:** Vercel build shows full vendor/aws with thousands of files. Monitor with `🐘 Installing Composer dependencies` in build output.

---

### Pitfall 3: R2 ACL Errors or Silent Failures

**What goes wrong:** Developer passes `'ACL' => 'public-read'` on `putObject()`. The call may succeed (R2 ignores the param) but the object's accessibility depends entirely on the bucket's r2.dev setting, not the ACL. Or, future SDK versions may fail on unsupported params.

**Why it happens:** S3 muscle memory from AWS. R2 does not support `x-amz-acl`.

**How to avoid:** Omit ACL entirely from the `putObject` call. Public access is enabled once at the bucket level via the r2.dev setting (operator step D-09). Source: R2 S3 API compatibility docs.

**Warning signs:** Runtime warning `Warning: ...ACL...` or unexpected 403 responses.

---

### Pitfall 4: deleteImage() Old Local-File Code Still Running

**What goes wrong:** The old `$file = dirname(__DIR__, 2) . '/public' . $image['path']` code is still present after the swap. When `$image['path']` is an R2 URL like `https://pub-xxx.r2.dev/abc.jpg`, the concatenation produces a nonsensical local path; `is_file()` returns false (good — no crash), but the R2 delete also never runs.

**Why it happens:** Partial refactor — the URL storage is wired but the old unlink code is not removed.

**How to avoid:** Remove the entire `$file = ... ; if (is_file($file)) { @unlink($file); }` block. Replace with the R2Storage::delete() call (wrapped in try/catch). Review the full `deleteImage()` method for any other string operations on `$image['path']` that assume a local path.

**Warning signs:** Images remain visible in R2 dashboard after "delete" in the UI. Orphan objects accumulate.

---

### Pitfall 5: PHP `$_FILES` Silently Empty for Files Over php.ini Limit

**What goes wrong:** User uploads a 3.2 MB image (within the new 3.5 MB app guard). PHP silently sets `$_FILES['image']` to empty because `upload_max_filesize` is still at the old value (default 2M). The app guard never fires; user sees "Aucune photo sélectionnée." instead of a meaningful error.

**Why it happens:** PHP truncates / empties `$_FILES` when a file exceeds `upload_max_filesize` OR when the total POST body exceeds `post_max_size`. The app-level guard in `storeUploadedFile()` reads `$_FILES['size']` which is 0 or missing.

**How to avoid:** Add to `api/php.ini`:
```ini
upload_max_filesize = 5M
post_max_size = 5M
```
This gives headroom above the app's 3.5 MB guard and below Vercel's 4.5 MB raw 413. The layered defence is: `api/php.ini` (5M) → app guard (3.5 MB) → Vercel HTTP limit (4.5 MB, raw 413).

**Warning signs:** `$_FILES['image']['error']` returns `UPLOAD_ERR_INI_SIZE` (1) or `$_FILES` is empty for valid-looking uploads.

---

### Pitfall 6: S3Client Virtual-Hosted Style URL Resolution

**What goes wrong:** `S3Client` by default constructs requests using virtual-hosted style: `https://<bucket>.<ACCOUNT_ID>.r2.cloudflarestorage.com/key`. If Cloudflare's DNS does not resolve `<bucket>.<ACCOUNT_ID>.r2.cloudflarestorage.com`, PutObject fails with a connection or SSL error.

**Why it happens:** AWS SDK defaults to virtual-hosted style for performance (fewer hops). The Cloudflare official PHP example does NOT include `use_path_style_endpoint`, suggesting R2's DNS handles virtual-hosted style. This is confirmed to work in practice.

**How to avoid:** Follow the official Cloudflare config (no `use_path_style_endpoint`). If you encounter DNS resolution errors, add `'use_path_style_endpoint' => true` to the S3Client options as a fallback — this switches to `https://<ACCOUNT_ID>.r2.cloudflarestorage.com/<bucket>/key`.

**Warning signs:** `Aws\Exception\AwsException` with `cURL error 6: Could not resolve host` or `SSL peer certificate` errors on `PutObject`.

---

## Code Examples

### S3Client instantiation for R2

```php
// Source: developers.cloudflare.com/r2/examples/aws/aws-sdk-php/
use Aws\Credentials\Credentials;
use Aws\S3\S3Client;

$client = new S3Client([
    'version'     => 'latest',
    'region'      => 'auto',   // Required by SDK but unused by R2
    'endpoint'    => 'https://' . Env::get('R2_ACCOUNT_ID') . '.r2.cloudflarestorage.com',
    'credentials' => new Credentials(
        Env::get('R2_ACCESS_KEY_ID'),
        Env::get('R2_SECRET_ACCESS_KEY')
    ),
]);
```

### PutObject — upload a tmp file to R2

```php
// Source: developers.cloudflare.com/r2/examples/aws/aws-sdk-php/ + docs.aws.amazon.com/aws-sdk-php/v3
$client->putObject([
    'Bucket'      => Env::get('R2_BUCKET'),
    'Key'         => $key,          // flat key: e.g., "a1b2c3d4.jpg"
    'SourceFile'  => $tmpPath,      // absolute path to PHP tmp file
    'ContentType' => $contentType,  // e.g., "image/jpeg"
    // NO 'ACL' param — R2 does not support x-amz-acl
]);
```

### DeleteObject — remove an object from R2

```php
// Source: aws-sdk-php docs + R2 S3 API compatibility docs
$client->deleteObject([
    'Bucket' => Env::get('R2_BUCKET'),
    'Key'    => $key,  // bare key derived from stored URL
]);
```

### Key derivation from stored URL

```php
// Source: logical derivation from D-05 URL structure
// Stored: "https://pub-abc123.r2.dev/a1b2c3d4.jpg"
// Base:   "https://pub-abc123.r2.dev"  (R2_PUBLIC_BASE_URL, no trailing slash)
$key = ltrim(substr($storedUrl, strlen($publicBaseUrl)), '/');
// Result: "a1b2c3d4.jpg"
```

### composer.json additions

```json
// Source: github.com/aws/aws-sdk-php/discussions/2420 (removeUnusedServices)
{
    "require": {
        "php": ">=8.3",
        "ext-pdo": "*",
        "aws/aws-sdk-php": "^3"
    },
    "scripts": {
        "pre-autoload-dump": "Aws\\Script\\Composer\\Composer::removeUnusedServices",
        "vercel": "@composer dump-autoload --optimize",
        "test": "phpunit"
    },
    "extra": {
        "aws/aws-sdk-php": ["S3"]
    }
}
```

**Note on `--no-scripts`:** `pre-autoload-dump` fires during local `composer install` but NOT during vercel-php's build (which uses `--no-scripts`). The `scripts.vercel` key fires AFTER build via `runComposerScripts`. For the Lambda, either accept full SDK or use a more complex `vercel` script to re-trigger slimming.

### api/php.ini additions

```ini
; STORE-05: Allow files up to 5M so php.ini limit > app guard (3.5MB) < Vercel raw limit (4.5MB)
upload_max_filesize = 5M
post_max_size = 5M
```

---

## State of the Art

| Old Approach | Current Approach | When Changed | Impact |
|--------------|------------------|--------------|--------|
| `move_uploaded_file()` to disk | `S3Client::putObject()` to R2 | Phase 4 | Persistent storage; works on serverless |
| Local `/assets/uploads/` web path stored in DB | Full r2.dev URL stored in DB | Phase 4 | Views render `<img src="...">` directly; no changes |
| `@unlink()` for image delete | `S3Client::deleteObject()` best-effort | Phase 4 | Object removed from R2; no local file interaction |
| `upload_max_filesize` at PHP default (2M) | `5M` in api/php.ini | Phase 4 | App guard at 3.5 MB is authoritative; php.ini no longer silently truncates |

**Deprecated/outdated:**
- `public/assets/uploads/` directory: no longer used for new uploads in production. Still used by local Docker seed data.
- 2 MB guard in `MAX_UPLOAD_BYTES`: replaced by 3.5 MB (STORE-05).

---

## Assumptions Log

| # | Claim | Section | Risk if Wrong |
|---|-------|---------|---------------|
| A1 | Full aws/aws-sdk-php vendor/aws is ~37.5 MB unzipped | Standard Stack, Pitfall 2 | If larger, may approach Lambda size limits; run `du -sh vendor/aws` after local install to verify |
| A2 | Vercel PHP runtime layer is ~50–90 MB, giving ~120 MB total well under 250 MB | Open Questions #4 | If runtime is larger, size margin shrinks; check Vercel build output for function size warning |
| A3 | R2's DNS handles virtual-hosted style (`<bucket>.<account_id>.r2.cloudflarestorage.com`) | Pitfall 6 | If not, add `use_path_style_endpoint => true` to S3Client; easy 1-line fix |

---

## Environment Availability

| Dependency | Required By | Available | Version | Fallback |
|------------|------------|-----------|---------|----------|
| PHP 8.3 | Runtime | ✓ | 8.3.30 | — |
| Composer | Local install / CI | ✓ (in Docker; vercel-php auto-runs on Lambda) | — | vercel-php build step |
| Cloudflare R2 bucket | STORE-01..03 | ✗ (operator step D-09) | — | No fallback; must be created before deploying |
| R2 API token | STORE-01..03 | ✗ (operator step D-09) | — | No fallback; must be created before deploying |
| Vercel env vars (5x R2_*) | STORE-01..05 | ✗ (operator step D-09) | — | Local `.env` for Docker dev |

**Missing dependencies with no fallback:**
- Cloudflare R2 bucket + r2.dev public URL + API token (D-09) — must be created by the operator before the automated code changes can be verified end-to-end. Code changes are deployable without them; verifying STORE-01..03 requires them.

**Missing dependencies with fallback:**
- `vendor/` on local machine: `composer install` locally for development; vercel-php builds it on Lambda automatically.

---

## Validation Architecture

### Test Framework

| Property | Value |
|----------|-------|
| Framework | PHPUnit 11.x |
| Config file | `phpunit.xml` (project root) |
| Quick run command | `vendor/bin/phpunit tests/Services/R2StorageTest.php` |
| Full suite command | `vendor/bin/phpunit` |

### Phase Requirements → Test Map

| Req ID | Behavior | Test Type | Automated Command | File Exists? |
|--------|----------|-----------|-------------------|-------------|
| STORE-01 | `R2Storage::put()` calls `S3Client::putObject()` with correct Bucket/Key/ContentType and returns full URL | unit | `vendor/bin/phpunit tests/Services/R2StorageTest.php::testPutReturnsPublicUrl` | ❌ Wave 0 |
| STORE-01 | `storeUploadedFile()` returns R2 URL (not local path) on success | unit | `vendor/bin/phpunit tests/Controllers/ProductControllerUploadTest.php` | ❌ Wave 0 |
| STORE-02 | Stored path is a full https:// URL (not `/assets/uploads/...`) | unit (assertion on return value) | Covered by STORE-01 test | — |
| STORE-03 | `R2Storage::delete()` calls `S3Client::deleteObject()` with correct Bucket/Key | unit | `vendor/bin/phpunit tests/Services/R2StorageTest.php::testDeleteCallsDeleteObject` | ❌ Wave 0 |
| STORE-03 | Key is correctly derived from stored URL | unit | `vendor/bin/phpunit tests/Services/R2StorageTest.php::testDeriveKeyFromUrl` | ❌ Wave 0 |
| STORE-04 | Documented fallback (no migration needed) | manual | N/A — operator confirms prod DB empty | — |
| STORE-05 | Files > 3.5 MB return error; files ≤ 3.5 MB proceed | unit | `vendor/bin/phpunit tests/Services/R2StorageTest.php::testSizeGuard` | ❌ Wave 0 |
| STORE-05 | MAX_UPLOAD_BYTES constant equals (int)(3.5 * 1024 * 1024) | unit | Covered by above | — |

**Note:** R2Storage tests should use PHPUnit mocking to mock `S3Client` (inject via constructor or use a test double). Since the project has no DI container, the simplest approach is to make `R2Storage` accept an optional `S3Client` parameter in its constructor for testing, defaulting to constructing one from `Env`.

### Sampling Rate

- **Per task commit:** `vendor/bin/phpunit tests/Services/R2StorageTest.php`
- **Per wave merge:** `vendor/bin/phpunit`
- **Phase gate:** Full suite green + manual smoke test (upload + display + delete on Vercel preview URL) before `/gsd:verify-work`

### Wave 0 Gaps

- [ ] `tests/Services/R2StorageTest.php` — covers STORE-01 (put), STORE-03 (delete + key derivation), STORE-05 (size guard indirectly)
- [ ] R2Storage constructor needs optional `S3Client $client = null` injection point for testability
- [ ] `phpunit.xml` coverage config: add `src/Services/R2Storage.php` to coverage source

*(Existing PHPUnit 11.x setup and `phpunit.xml` at project root are sufficient — no new framework install needed.)*

---

## Security Domain

### Applicable ASVS Categories

| ASVS Category | Applies | Standard Control |
|---------------|---------|-----------------|
| V2 Authentication | no | — |
| V3 Session Management | no | — |
| V4 Access Control | yes (indirect) | R2 bucket access controlled by API token (D-09 operator step); no user can directly call R2 API |
| V5 Input Validation | yes | MIME allowlist in `storeUploadedFile()`; size guard (STORE-05); `finfo_file()` for real MIME detection, not just extension |
| V6 Cryptography | no | AWS SigV4 handled by SDK; no hand-rolled crypto |
| V7 Error / Logging | yes | `error_log()` on delete failure; don't leak R2 error details to the user |
| V8 Data Protection | yes | R2 credentials only in environment variables, never in code or DB |

### Known Threat Patterns

| Pattern | STRIDE | Standard Mitigation |
|---------|--------|---------------------|
| MIME-type spoofing (upload .php as .jpg) | Tampering | `finfo_file(FILEINFO_MIME_TYPE)` checks real bytes, not extension — already in storeUploadedFile |
| Credential leak via committed `.env` or `composer.json` | Information Disclosure | Credentials in Vercel env vars only; `.env` in `.gitignore`; `.env.example` has no secrets |
| Oversized upload causing Lambda OOM or 413 | DoS | Layered guard: `upload_max_filesize=5M` (php.ini) → app `MAX_UPLOAD_BYTES=3.5MB` → Vercel 4.5MB raw 413 |
| Path traversal on key generation | Tampering | Key is `bin2hex(random_bytes(8)) . '.' . $ext` (allowlisted extension); no user input in key |
| R2 object enumeration via predictable keys | Information Disclosure | 16 hex chars = 2^64 key space; enumeration infeasible |

**CSP note:** Adding `r2.dev` to `img-src` is deferred to Phase 5 (SEC-03). Current CSP has `img-src 'self' data:` — images from r2.dev will be blocked by CSP in prod until Phase 5. **The planner should note this dependency: STORE-02 (images display) requires Phase 5 SEC-03 CSP update, or the two must be coordinated.**

---

## Sources

### Primary (HIGH confidence)

- `github.com/vercel-community/php` — `src/index.ts` (build mechanism, `runComposerInstall` source); `src/utils.ts` (`runComposerInstall` flags including `--no-scripts`, `runComposerScripts`)
- `github.com/juicyfx/vercel-examples/tree/master/php-composer` — Official php-composer example (no buildCommand in vercel.json, `.vercelignore /vendor`)
- `developers.cloudflare.com/r2/examples/aws/aws-sdk-php/` — Exact S3Client config for R2
- `developers.cloudflare.com/r2/api/s3/api/` — R2 S3 API compatibility (ACL not supported)
- `developers.cloudflare.com/r2/buckets/public-buckets/` — r2.dev public URL enable steps and URL format
- `packagist.org/packages/aws/aws-sdk-php` — Package legitimacy: 3.384.8, 536M downloads
- `vercel.com/docs/functions/limitations` — 250 MB uncompressed Lambda size limit confirmed

### Secondary (MEDIUM confidence)

- `github.com/aws/aws-sdk-php/discussions/2420` — removeUnusedServices SDK slimming (37.5 MB → 5.4 MB); `--no-scripts` interaction noted
- `github.com/vercel-community/php/issues/441` — Historical 50 MB limit issue (superseded by 250 MB; kept for context)
- `masteringlaravel.io/daily/2023-10-03-make-aws-sdk-much-smaller` — removeUnusedServices configuration example

### Tertiary (LOW confidence / ASSUMED)

- PHP runtime layer size estimate (~50–90 MB): interpolated from vercel-php README "~90mb memory" at runtime; actual deployment size not confirmed. Marked `[ASSUMED]`.

---

## Metadata

**Confidence breakdown:**

| Area | Level | Reason |
|------|-------|--------|
| vercel-php build mechanism | HIGH | Confirmed from TypeScript source of the runtime itself |
| S3Client config for R2 | HIGH | From official Cloudflare R2 documentation |
| R2 public URL format | HIGH | From official Cloudflare R2 public-buckets documentation |
| aws-sdk-php footprint | MEDIUM | Community measurements for sdk size; Lambda size limit from official Vercel docs; runtime layer size estimated |
| autoloader coexistence | HIGH | From direct code inspection of public/index.php and established PHP autoloader behaviour |

**Research date:** 2026-06-12
**Valid until:** 2026-07-12 (vercel-php runtime changes; Cloudflare R2 API changes are rare)

---

## Project Constraints (from CLAUDE.md)

Directives the planner must verify compliance with:

- All PHP files must open with `declare(strict_types=1);` — R2Storage.php must include this.
- All new classes must be `final` — R2Storage.php must be `final class R2Storage`.
- Methods use camelCase verbs, constants use `UPPER_SNAKE_CASE` — `put()`, `delete()`, `deriveKey()`, `MAX_UPLOAD_BYTES`.
- All class references via explicit `use` statements — no FQCN inline.
- `src/Services/` = pure I/O, no Models/Core deps — R2Storage reads config via `Env::get()` only; no DB, no Auth.
- No secrets committed — R2 credentials via env vars only; `.env.example` gets R2_* keys but no values.
- `error_log()` for server-side errors, not exposed to user.
- `MAX_UPLOAD_BYTES` is a `private const` on `ProductController` (as per existing pattern).
- The `storeUploadedFile()` return tuple `[?string, ?string]` shape must not change (used by `handleUpload()` and `uploadImages()`).
