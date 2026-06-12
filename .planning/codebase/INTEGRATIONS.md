# External Integrations

**Analysis Date:** 2026-06-12

## APIs & External Services

**Currency / FX Rates:**
- Frankfurter.app (`https://api.frankfurter.app/latest`) — free, open FX rate API; no API key required
  - Server-side client: `src/Services/ExchangeRateService.php`
    - Called via PHP `file_get_contents()` with a 5-second timeout
    - Returns `null` on any network or parse failure (graceful fallback)
    - Used as a fallback; the stored rate is always the one submitted from the form
  - Client-side duplicate: `public/assets/js/app.js`
    - Purchase form "Taux du jour" button calls `https://api.frankfurter.app/latest?from=<CURRENCY>&to=EUR` via `fetch()`
    - Order form "Taux du jour" button does the same
    - CSP `connect-src` explicitly permits `api.frankfurter.app` (set in `public/index.php`)
  - Auth: None (public API)
  - Currencies supported: EUR, USD, CNY (matches the `currency` ENUM in the database schema)

## Data Storage

**Databases:**
- MySQL 8.0 (InnoDB, utf8mb4_unicode_ci)
  - Connection: `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER`, `DB_PASSWORD` (env vars)
  - Client: native PDO singleton — `src/Core/Database.php`
  - No ORM; all queries are raw PDO prepared statements
  - Docker service: `reselltrack_db` (image `mysql:8.0`)
  - Schema initialised by `sql/schema.sql` + `sql/seed.sql` on first container start
  - Runtime migrations applied at boot via `src/Core/Schema.php`

  Tables:
  - `users` — accounts (email + bcrypt password hash)
  - `products` — item catalogue per user
  - `product_images` — gallery photos per product
  - `orders` — supplier purchase orders grouping multiple lines
  - `purchases` — individual purchase lots (linked to optional order)
  - `sales` — sell transactions
  - `login_attempts` — brute-force protection records

**File Storage:**
- Local filesystem only — uploaded product images stored in `public/assets/uploads/`
- Directory is bind-mounted via Docker volume: `./public/assets/uploads:/var/www/html/public/assets/uploads`
- No S3, cloud storage, or CDN for user uploads

**Caching:**
- None — no Redis, Memcached, or application-level cache layer

## Authentication & Identity

**Auth Provider:**
- Custom session-based authentication — `src/Core/Auth.php`
  - Passwords hashed with PHP `password_hash()` (bcrypt)
  - Verified with `password_verify()`
  - Session name: `RESELLTRACK_SESS`
  - Session cookie: `httponly=true`, `samesite=Lax`, `secure` flag controlled by `SESSION_SECURE` env var
  - Session ID regenerated on login (session fixation prevention)
  - CSRF protection: per-session token — `src/Core/Csrf.php`
  - Login brute-force protection: max 5 failures per (email, IP) in 15 minutes — `src/Core/RateLimiter.php`
- No OAuth, SSO, magic links, or third-party identity providers

## Monitoring & Observability

**Error Tracking:**
- None — no Sentry, Bugsnag, or similar service integrated

**Logs:**
- PHP `error_log()` used in `src/Core/Database.php` for connection errors
- Apache access and error logs (standard: `${APACHE_LOG_DIR}/access.log`, `error.log`)
- No structured logging or log aggregation service

## CI/CD & Deployment

**Hosting:**
- No production hosting configured in the repo; Docker Compose is the only deployment definition
- Target: any server with Docker or a plain Apache + PHP 8.3 + MySQL 8.0 stack

**CI Pipeline:**
- None — no GitHub Actions, GitLab CI, or other pipeline files present

## Environment Configuration

**Required env vars (from `.env.example`):**
- `APP_ENV` — `dev` or `prod`
- `APP_PORT` — host port for the web container (default `8080`)
- `SESSION_SECURE` — `1` for HTTPS (adds `Secure` cookie flag)
- `DB_HOST` — MySQL host (default `db` when using Docker Compose)
- `DB_PORT` — MySQL port (default `3306`)
- `DB_NAME` — database name (default `reselltrack`)
- `DB_USER` — database user (default `reselltrack`)
- `DB_PASSWORD` — database password
- `DB_ROOT_PASSWORD` — MySQL root password (Docker only)
- `PMA_PORT` — phpMyAdmin host port (dev only, default `8081`)

**Secrets location:**
- `.env` file at project root (must be created from `.env.example`; not committed)
- Docker Compose `environment:` block for container injection

## Webhooks & Callbacks

**Incoming:**
- None — no webhook endpoints

**Outgoing:**
- None — only outbound HTTP is the Frankfurter.app FX rate fetch

## Frontend CDN Dependencies

Assets loaded from CDNs at runtime (no SRI hashes on Bootstrap; Chart.js loaded without SRI):
- `cdn.jsdelivr.net` — Bootstrap 5.3.3 CSS + JS bundle, Bootstrap Icons 1.11.3, Chart.js 4.4.1
- `fonts.googleapis.com` — Google Fonts stylesheet (Inter, Sora)
- `fonts.gstatic.com` — Google Fonts static assets

CSP allowlist in `public/index.php`:
```
script-src 'self' cdn.jsdelivr.net
style-src  'self' 'unsafe-inline' cdn.jsdelivr.net fonts.googleapis.com
font-src   fonts.gstatic.com cdn.jsdelivr.net
connect-src 'self' api.frankfurter.app
```

---

*Integration audit: 2026-06-12*
