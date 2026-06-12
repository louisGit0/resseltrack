# Stack Research

**Domain:** PHP 8.3 serverless deployment on Vercel (existing MySQL-backed MVC app)
**Researched:** 2026-06-12
**Confidence:** HIGH for items 1, 4, 5 (verified against official docs / GitHub repo); MEDIUM for items 2, 3 (verified against official docs but free-tier limits not fully disclosed)

---

## 1. PHP Runtime on Vercel

### Recommendation: `vercel-php@0.7.4`

**Confidence:** HIGH — verified against the official [vercel-community/php CHANGELOG](https://github.com/vercel-community/php/blob/master/CHANGELOG.md) on 2026-06-12.

The only community-maintained PHP runtime for Vercel is `vercel-community/php` (npm: `vercel-php`). It runs PHP as a Vercel Serverless Function via Node.js 22 and bundles PHP with 60+ extensions compiled for Amazon Linux 2.

| Runtime Version | PHP Version | Status |
|-----------------|-------------|--------|
| `vercel-php@0.7.4` | **PHP 8.3.x** | **Use this** (matches dev environment) |
| `vercel-php@0.8.0` | PHP 8.4.x | Do not upgrade yet — untested with this codebase |
| `vercel-php@0.9.0` | PHP 8.5.x | Do not upgrade yet |

**Why 0.7.4 and not the latest:** The app was built and tested against PHP 8.3 on Apache (Docker image `php:8.3-apache`). Matching the runtime version eliminates any PHP 8.4/8.5 deprecation surprises. Upgrade to 0.8.0+ only after the serverless deployment is stable.

**Confirmed extensions available** (relevant to this app):
- `pdo`, `pdo_mysql`, `mysqlnd` — required for `Database::connection()`
- `curl` — required for `ExchangeRateService` (`file_get_contents` also works via PHP's HTTP wrapper)
- `openssl` — required for TLS connections to managed DB
- `session` — required for `Auth::start()`
- `json`, `mbstring`, `hash`, `filter` — standard, all present

### vercel.json

The PHP file must live under `/api/`. All HTTP requests are rewritten to the single front controller.
Static assets (CSS, JS) under `public/assets/` are served as static files by Vercel before the PHP catch-all.

```json
{
  "functions": {
    "api/index.php": {
      "runtime": "vercel-php@0.7.4",
      "memory": 1024,
      "maxDuration": 30
    }
  },
  "routes": [
    {
      "src": "/assets/(.*)",
      "dest": "/public/assets/$1",
      "headers": { "Cache-Control": "public, max-age=31536000, immutable" }
    },
    {
      "src": "/(.*)",
      "dest": "/api/index.php"
    }
  ]
}
```

**Why `memory: 1024`:** The default (512 MB) is sufficient for most requests, but image upload handling and Composer autoload benefit from headroom.

**Why `maxDuration: 30`:** The default is 10 s. The `ExchangeRateService` makes an outbound HTTP call with a 5 s timeout; leave buffer for slow networks without hitting Vercel's 30 s cap.

### Project structure change required

The existing front controller lives at `public/index.php`. Vercel PHP functions must live in `/api/`. The migration is:

```
api/
  index.php       ← thin wrapper or relocated front controller
  php.ini         ← custom PHP settings (see below)
public/
  assets/
    css/
    js/
    (no uploads/ — migrate to Cloudflare R2)
src/
  ...
vercel.json
.vercelignore
```

`api/index.php` must adjust the autoloader and path constants since `__DIR__` changes from `public/` to `api/`. The simplest migration: copy `public/index.php` to `api/index.php` and update any `__DIR__` relative paths to point one level up (`__DIR__ . '/../src/'` etc.).

### api/php.ini

```ini
; Ensure $_ENV is populated from OS-level variables (Vercel injects env vars at the OS level).
; getenv() works without this setting; this just enables $_ENV superglobal access too.
variables_order = EGPCS

; Increase for upload handling + Composer autoload
memory_limit = 256M

; Disable functions not needed in serverless
disable_functions = exec,passthru,shell_exec,system,proc_open,popen

; Vercel runtime is always behind HTTPS, but PHP doesn't know that
; This is handled in index.php via $_SERVER['HTTPS'] detection
```

### Composer install behavior

Add `/vendor` to `.vercelignore`. Vercel runs `composer install --no-dev --optimize-autoloader` automatically during the build step when it detects `composer.json`. The vendor directory is included in the Lambda bundle.

```
# .vercelignore
/vendor
/.git
/docker
/docker-compose.yml
/Dockerfile
```

### Environment variables → PHP

Vercel injects project environment variables as **OS-level environment variables** into the function process. `getenv('DB_HOST')` returns the correct value. The existing `Env::get()` uses `getenv()` as its primary lookup — no code change needed.

**Caveat:** `$_ENV` superglobal may be empty if PHP's `variables_order` does not include `E`. The `api/php.ini` above sets `variables_order = EGPCS` to fix this. The app's `Env.php` only uses `getenv()`, not `$_ENV` directly, so this is belt-and-suspenders.

---

## 2. Managed MySQL Database

### Recommendation: Aiven for MySQL

**Confidence:** MEDIUM — free tier confirmed available as of March 2026 per official Aiven docs; specific concurrent connection limits for free tier not publicly disclosed.

| Provider | Engine | Free Tier | PHP PDO | TLS | MySQL Compatibility | Verdict |
|----------|--------|-----------|---------|-----|---------------------|---------|
| **Aiven for MySQL** | **MySQL 8.0** | 1 GB storage, 1 GB RAM, free forever | Standard DSN | Custom CA cert (bundled) | **Identical to dev** | **Recommended** |
| TiDB Cloud Starter | TiDB (MySQL-compatible) | 5 GB + 50M RU/month | Standard DSN | System CA bundle (simpler) | Wire-compatible but not MySQL | Alternative |
| PlanetScale | Vitess/MySQL | **None** (min $39/mo since April 2024) | Standard DSN | System CA | Wire-compatible | Eliminated |

**Why Aiven and not TiDB Cloud:**

The app uses `START TRANSACTION; SELECT ... FOR UPDATE` in `SaleModel::create()` for concurrent stock management. TiDB's default transaction mode is **optimistic** (conflict detection at commit, not at lock time). MySQL 8's default is **pessimistic** (`FOR UPDATE` blocks). For single-user or low-traffic personal use this may not matter in practice, but architecturally it is incorrect to run MySQL pessimistic locking patterns against a TiDB optimistic default. Aiven runs actual MySQL 8.0 InnoDB — the exact engine the app was designed and tested against, with no behavioral differences.

Aiven free tier is confirmed free indefinitely (no credit card required, no trial countdown). 1 GB storage is ample for a personal resell tracker (hundreds of products, thousands of sales rows).

### PDO DSN and connection options

Aiven for MySQL requires TLS. Their services use a **private CA certificate** (not a public CA), so you must:

1. Download the CA cert from the Aiven Console (project → service → Overview → "CA Certificate") and commit it to the repo as `ssl/ca-aiven.pem` (it is a public certificate, safe to commit).
2. Reference it in the PDO options.

**DSN format:**
```php
$dsn = sprintf(
    'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
    getenv('DB_HOST'),
    getenv('DB_PORT'),
    getenv('DB_NAME')
);

$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
    PDO::MYSQL_ATTR_SSL_CA       => __DIR__ . '/../ssl/ca-aiven.pem',
    PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT => true,
];
```

**Env vars to add to Vercel project settings:**

| Variable | Value source |
|----------|-------------|
| `DB_HOST` | Aiven service → Connection info → Host |
| `DB_PORT` | Aiven service → Connection info → Port (typically a high port like 17417) |
| `DB_NAME` | `defaultdb` (Aiven default) or rename |
| `DB_USER` | Aiven service → Connection info → User |
| `DB_PASSWORD` | Aiven service → Connection info → Password |

**Connection-per-invocation concern:** Each Vercel PHP function invocation opens one PDO connection and closes it at process exit. There is no connection pooling between invocations (PHP has no shared memory between Lambda invocations). For a low-traffic personal app this is fine. If traffic grows, add a connection pooler (e.g. PgBouncer / ProxySQL) in front of Aiven — but that is not needed at this stage.

### Schema migration: remove Schema::ensure() from request path

`Database::connection()` currently calls `Schema::ensure()` on the first connection, which runs `SHOW COLUMNS` + `ALTER TABLE` DDL per request. This must be extracted to a one-shot migration before deploying to Vercel:

1. Run `php artisan`-equivalent one-shot: `php sql/schema.sql` against the Aiven DB via `mysql` CLI or Aiven web console before going live.
2. Remove or gate `Schema::ensure()` call with an env var (`SKIP_SCHEMA_ENSURE=1` in Vercel).

This is a deployment concern, not a stack change, but it is a prerequisite for Vercel to work correctly.

---

## 3. PHP Session Store

### Recommendation: Custom `SessionHandlerInterface` backed by MySQL (same Aiven DB)

**Confidence:** MEDIUM — pattern is well-established; requires writing ~50 lines of PHP.

PHP's default file-based session handler writes to the local filesystem, which is ephemeral on Vercel (each invocation may run on a different container). Sessions would not persist between requests.

**Use MySQL for sessions** because:
- The MySQL connection is already established per request
- No additional service or secret to configure
- Consistent with the project's explicit decision (`.planning/PROJECT.md`)
- Implementation is ~50 lines of PHP against one new table

Do NOT use Upstash Redis unless MySQL session performance becomes a bottleneck (unlikely for this workload).

### Sessions table DDL

Add to `sql/schema.sql` (and run as part of the one-shot migration):

```sql
CREATE TABLE IF NOT EXISTS `sessions` (
    `id`            VARCHAR(128)  NOT NULL,
    `data`          MEDIUMBLOB    NOT NULL,
    `last_activity` INT UNSIGNED  NOT NULL,
    PRIMARY KEY (`id`),
    KEY `idx_last_activity` (`last_activity`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

### Session handler class (new file: `src/Core/DatabaseSessionHandler.php`)

```php
<?php
declare(strict_types=1);
namespace App\Core;

class DatabaseSessionHandler implements \SessionHandlerInterface
{
    private \PDO $pdo;

    public function __construct() {
        $this->pdo = Database::connection();
    }

    public function open(string $path, string $name): bool { return true; }
    public function close(): bool { return true; }

    public function read(string $id): string|false {
        $stmt = $this->pdo->prepare(
            'SELECT data FROM sessions WHERE id = :id AND last_activity > :exp'
        );
        $stmt->execute([':id' => $id, ':exp' => time() - (int)ini_get('session.gc_maxlifetime')]);
        $row = $stmt->fetch();
        return $row ? $row['data'] : '';
    }

    public function write(string $id, string $data): bool {
        $stmt = $this->pdo->prepare(
            'REPLACE INTO sessions (id, data, last_activity) VALUES (:id, :data, :ts)'
        );
        return $stmt->execute([':id' => $id, ':data' => $data, ':ts' => time()]);
    }

    public function destroy(string $id): bool {
        $stmt = $this->pdo->prepare('DELETE FROM sessions WHERE id = :id');
        return $stmt->execute([':id' => $id]);
    }

    public function gc(int $maxlifetime): int|false {
        $stmt = $this->pdo->prepare('DELETE FROM sessions WHERE last_activity < :exp');
        $stmt->execute([':exp' => time() - $maxlifetime]);
        return $stmt->rowCount();
    }
}
```

### Wiring in `api/index.php` (before `Auth::start()`)

```php
// Register DB session handler before session_start() (which Auth::start() calls internally)
$handler = new App\Core\DatabaseSessionHandler();
session_set_save_handler($handler, true);
```

**Note:** `session_set_save_handler()` must be called **before** `session_start()`. `Auth::start()` calls `session_start()`, so register the handler before `Auth::start()` is called.

**Garbage collection note:** Vercel functions have no cron. PHP's built-in probabilistic GC (`session.gc_probability / session.gc_divisor`) will run GC on some fraction of requests, which is sufficient for this workload. Set `session.gc_probability = 1` and `session.gc_divisor = 100` in `api/php.ini` for 1% GC probability per request.

---

## 4. Object Storage for Image Uploads

### Recommendation: Cloudflare R2 + `aws/aws-sdk-php` v3

**Confidence:** HIGH — official Cloudflare documentation provides PHP example; aws-sdk-php is the official PHP SDK for S3-compatible APIs.

The existing `public/assets/uploads/` local disk storage cannot be used on Vercel (ephemeral filesystem). Images must be stored in object storage.

| Provider | PHP Support | Free Tier | Egress Fees | Public URLs | Verdict |
|----------|-------------|-----------|-------------|-------------|---------|
| **Cloudflare R2** | **aws-sdk-php v3 (S3-compatible)** | 10 GB + 1M writes + 10M reads/month, **permanent** | **Zero** | Yes (r2.dev subdomain or custom domain) | **Recommended** |
| Vercel Blob | **JS SDK only** (no PHP SDK) | 5 GB + 100 GB transfer/month, Hobby plan, soft limit | Yes (Blob Data Transfer) | Yes (public buckets) | Eliminated — no PHP SDK |
| Amazon S3 | aws-sdk-php v3 | 5 GB for 12 months (expires) | Yes | Yes | Not recommended for free |

**Why R2 over Vercel Blob:** Vercel Blob has no PHP SDK. Using it from PHP requires either calling an undocumented internal HTTP API via cURL (fragile, unsupported) or writing a Node.js proxy function. Cloudflare R2 officially supports `aws-sdk-php` v3 via its S3-compatible API. R2's free tier is also more generous and permanent (no 12-month expiry). Zero egress fees eliminate cost concerns for serving images.

### Composer dependency

```bash
composer require aws/aws-sdk-php:^3
```

### R2 Client setup (`src/Services/R2Storage.php`)

```php
<?php
declare(strict_types=1);
namespace App\Services;

use Aws\S3\S3Client;
use Aws\Credentials\Credentials;

final class R2Storage
{
    private S3Client $client;
    private string $bucket;
    private string $publicBase;

    public function __construct() {
        $this->bucket = \App\Core\Env::get('R2_BUCKET');
        $this->publicBase = \App\Core\Env::get('R2_PUBLIC_BASE_URL'); // e.g. https://pub-xxx.r2.dev
        $this->client = new S3Client([
            'region'      => 'auto',
            'endpoint'    => 'https://' . \App\Core\Env::get('R2_ACCOUNT_ID') . '.r2.cloudflarestorage.com',
            'version'     => 'latest',
            'credentials' => new Credentials(
                \App\Core\Env::get('R2_ACCESS_KEY_ID'),
                \App\Core\Env::get('R2_SECRET_ACCESS_KEY')
            ),
        ]);
    }

    /**
     * Upload a file stream and return the public URL.
     * @param resource $stream
     */
    public function upload(string $key, $stream, string $mimeType): string {
        $this->client->putObject([
            'Bucket'      => $this->bucket,
            'Key'         => $key,
            'Body'        => $stream,
            'ContentType' => $mimeType,
        ]);
        return $this->publicBase . '/' . $key;
    }

    public function delete(string $key): void {
        $this->client->deleteObject([
            'Bucket' => $this->bucket,
            'Key'    => $key,
        ]);
    }
}
```

### New env vars to add to Vercel project settings

| Variable | Where to find |
|----------|--------------|
| `R2_ACCOUNT_ID` | Cloudflare dashboard → R2 → Account ID |
| `R2_ACCESS_KEY_ID` | Cloudflare dashboard → R2 → Manage API tokens → Create API token |
| `R2_SECRET_ACCESS_KEY` | Same token creation |
| `R2_BUCKET` | Bucket name you create (e.g. `reselltrack`) |
| `R2_PUBLIC_BASE_URL` | Enable public access on bucket → copy r2.dev URL (e.g. `https://pub-abc123.r2.dev`) |

### Bucket setup

1. Create a Cloudflare account (free, no credit card required for R2 within free limits).
2. In R2, create a bucket named `reselltrack`.
3. Enable "Allow public access" to get the `*.r2.dev` public URL.
4. Create an API token with "Object Read & Write" permission scoped to that bucket only.

### Impact on existing code

`ProductController` and `ProductImage` model currently write to `public/assets/uploads/{filename}` and store the relative path in the DB. Migration plan:
- New uploads: replace `storeUploadedFile()` with `R2Storage::upload()`, store full URL (or just the R2 key) in DB.
- Existing local images: either re-upload to R2 during schema migration, or serve from the DB-stored path (works in dev, breaks on Vercel).

---

## 5. Secrets / Environment Variables on Vercel

### Recommendation: Vercel Dashboard Environment Variables (no code change needed)

**Confidence:** HIGH — verified against Vercel's official environment variables documentation.

Vercel injects project environment variables as real OS-level environment variables into the function process. PHP's `getenv()` reads them correctly. The existing `Env::get()` implementation requires **no changes**.

### How to configure

Add all secrets via **Vercel Dashboard → Project → Settings → Environment Variables** (or `vercel env add`). Select the target environments (Production, Preview, Development).

**Complete list of env vars for this project:**

| Variable | Example Value | Notes |
|----------|---------------|-------|
| `APP_ENV` | `prod` | Used in routing/debug guards |
| `SESSION_SECURE` | `1` | Must be 1 on Vercel (always HTTPS) |
| `DB_HOST` | `mysql-xyz.aivencloud.com` | Aiven host |
| `DB_PORT` | `17417` | Aiven port |
| `DB_NAME` | `defaultdb` | Aiven default DB name |
| `DB_USER` | `avnadmin` | Aiven user |
| `DB_PASSWORD` | `...` | Aiven password (mark as Secret in Vercel) |
| `R2_ACCOUNT_ID` | `abc123def456` | Cloudflare account ID |
| `R2_ACCESS_KEY_ID` | `...` | R2 API token ID |
| `R2_SECRET_ACCESS_KEY` | `...` | R2 API token secret (mark as Secret) |
| `R2_BUCKET` | `reselltrack` | R2 bucket name |
| `R2_PUBLIC_BASE_URL` | `https://pub-xxx.r2.dev` | Public base URL for uploaded images |

**Remove from env (Docker-only):**
- `APP_PORT` — not meaningful on Vercel
- `PMA_PORT` — phpMyAdmin, dev only
- `DB_ROOT_PASSWORD` — Docker-only

### Vercel Sensitive values

Mark `DB_PASSWORD`, `R2_SECRET_ACCESS_KEY`, and `R2_ACCESS_KEY_ID` as "Sensitive" in the Vercel dashboard. Sensitive variables are encrypted at rest and not exposed in build logs.

### Local development with Vercel env vars

```bash
vercel env pull .env.local   # pulls production vars to local .env.local
```

Keep `.env.local` in `.gitignore`. The existing `.env` + `Env::load()` flow continues to work in Docker local dev.

---

## Recommended Stack Summary

### Core Technologies

| Technology | Version | Purpose | Why Recommended |
|------------|---------|---------|-----------------|
| vercel-php (vercel-community/php) | 0.7.4 | PHP 8.3 serverless runtime on Vercel | Only community PHP runtime; actively maintained; pdo_mysql + openssl + session confirmed |
| Aiven for MySQL | MySQL 8.0 | Managed database | Actual MySQL 8 (not wire-compatible fork); 1 GB free forever; no credit card required |
| Cloudflare R2 | S3-compatible API | Object storage for images | Official PHP SDK (aws-sdk-php v3); 10 GB free permanent; zero egress fees |

### Supporting Libraries

| Library | Version | Purpose | When to Use |
|---------|---------|---------|-------------|
| aws/aws-sdk-php | ^3 | Cloudflare R2 upload/delete | Add at deployment milestone; replaces local disk upload |

### Development Tools

| Tool | Purpose | Notes |
|------|---------|-------|
| Vercel CLI (`vercel`) | Local dev with Vercel env vars | `vercel env pull` syncs secrets to `.env.local` |
| Aiven Console | DB management, CA cert download | One-time setup; export CA cert to `ssl/ca-aiven.pem` |
| Cloudflare Dashboard | R2 bucket + API token management | One-time setup per environment |

---

## Installation

```bash
# Add Cloudflare R2 PHP SDK
composer require aws/aws-sdk-php:^3

# Add .vercelignore
echo "/vendor" >> .vercelignore
echo "/.git" >> .vercelignore
echo "/docker" >> .vercelignore
echo "/docker-compose.yml" >> .vercelignore
echo "/Dockerfile" >> .vercelignore

# Add Vercel secrets (run once per environment)
vercel env add DB_HOST production
vercel env add DB_PORT production
vercel env add DB_NAME production
vercel env add DB_USER production
vercel env add DB_PASSWORD production
vercel env add SESSION_SECURE production
vercel env add APP_ENV production
vercel env add R2_ACCOUNT_ID production
vercel env add R2_ACCESS_KEY_ID production
vercel env add R2_SECRET_ACCESS_KEY production
vercel env add R2_BUCKET production
vercel env add R2_PUBLIC_BASE_URL production
```

---

## Alternatives Considered

| Recommended | Alternative | When to Use Alternative |
|-------------|-------------|-------------------------|
| Aiven for MySQL | TiDB Cloud Starter | If you need >1 GB storage on free tier and can tolerate MySQL dialect differences (verify `FOR UPDATE` pessimistic locking works as expected) |
| Cloudflare R2 + aws-sdk-php | Vercel Blob | Only if the app is rewritten in JavaScript/TypeScript — Blob has no PHP SDK |
| MySQL-backed sessions | Upstash Redis sessions | If session write latency becomes noticeable (>50ms per request), or if the app scales to many concurrent users |
| vercel-php@0.7.4 | vercel-php@0.8.0 | After verifying the codebase is PHP 8.4 compatible (no deprecation warnings) |

---

## What NOT to Use

| Avoid | Why | Use Instead |
|-------|-----|-------------|
| PlanetScale | No free tier since April 2024; minimum $39/month | Aiven for MySQL (free forever, actual MySQL 8) |
| Vercel Blob for PHP | No PHP SDK; would require undocumented HTTP API via cURL or a Node.js proxy | Cloudflare R2 + aws-sdk-php v3 |
| Local filesystem for sessions | Ephemeral on Vercel — sessions lost between invocations | MySQL-backed `DatabaseSessionHandler` |
| Local filesystem for uploads | Ephemeral on Vercel — files lost between invocations | Cloudflare R2 |
| PHP file-based .env in production | .env file not deployed to Vercel (and must not be) | Vercel project environment variables via dashboard or CLI |
| vercel-php@0.8.0 or 0.9.0 | PHP 8.4/8.5 — untested with this codebase, unnecessary version jump | vercel-php@0.7.4 (PHP 8.3, matches dev) |

---

## Version Compatibility

| Package | Compatible With | Notes |
|---------|-----------------|-------|
| vercel-php@0.7.4 | PHP 8.3.x | Confirmed by CHANGELOG; built for Node.js 22; Amazon Linux 2 runtime |
| aws/aws-sdk-php ^3 | PHP ^8.1 | Supports PHP 8.3; no conflicts with existing deps |
| Aiven MySQL 8.0 | PDO mysql driver, utf8mb4_unicode_ci | Full InnoDB + FOR UPDATE + SHOW COLUMNS compatibility |

---

## Sources

- [vercel-community/php GitHub repo](https://github.com/vercel-community/php) — version-to-PHP mapping, supported extensions list, vercel.json patterns (HIGH confidence)
- [vercel-community/php CHANGELOG](https://github.com/vercel-community/php/blob/master/CHANGELOG.md) — confirmed 0.7.4 = PHP 8.3, release dates (HIGH confidence)
- [Aiven for MySQL free tier docs](https://aiven.io/docs/products/mysql/concepts/mysql-free-tier) — free tier specs; last updated March 2026 (HIGH confidence)
- [Aiven MySQL PHP connection guide](https://aiven.io/docs/products/mysql/howto/connect-with-php) — PDO DSN format and TLS CA cert requirement (HIGH confidence)
- [Cloudflare R2 aws-sdk-php example](https://developers.cloudflare.com/r2/examples/aws/aws-sdk-php/) — S3Client constructor, upload pattern (HIGH confidence)
- [Cloudflare R2 pricing](https://developers.cloudflare.com/r2/pricing/) — free tier: 10 GB + 1M writes + 10M reads/month (HIGH confidence)
- [Cloudflare R2 public buckets](https://developers.cloudflare.com/r2/buckets/public-buckets/) — r2.dev public URL model (HIGH confidence)
- [Vercel environment variables docs](https://vercel.com/docs/environment-variables) — injection as OS env vars, getenv() access (HIGH confidence)
- [Vercel Blob pricing](https://vercel.com/docs/vercel-blob/usage-and-pricing) — Hobby plan free tier: 5 GB + 100 GB transfer (HIGH confidence)
- [PlanetScale hobby plan deprecation](https://planetscale.com/docs/plans/hobby-plan-deprecation-faq) — confirmed no free tier since April 2024 (HIGH confidence)
- [vercel-community/php issue #567](https://github.com/vercel-community/php/issues/567) — $\_ENV vs getenv() behavior; getenv() is the reliable path (MEDIUM confidence — issue open, workaround via php.ini)

---

*Stack research for: PHP 8.3 + MySQL 8 app deployed on Vercel serverless*
*Researched: 2026-06-12*
