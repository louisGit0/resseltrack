# Technology Stack

**Analysis Date:** 2026-06-12

## Languages

**Primary:**
- PHP 8.3 — all server-side logic under `src/` and `public/index.php`

**Secondary:**
- JavaScript (ES2020, vanilla, no transpile step) — `public/assets/js/app.js`
- SQL (MySQL 8 dialect) — `sql/schema.sql`, `sql/seed.sql`
- CSS — `public/assets/css/style.css` (custom styles layered on top of Bootstrap)

## Runtime

**Environment:**
- PHP 8.3 on Apache 2.4 (mod_rewrite enabled)
- Base image: `php:8.3-apache` — defined in `Dockerfile`

**Package Manager:**
- Composer (PHP) — `composer.json`
- Lockfile: `composer.lock` (auto-generated, not shown in source listing but expected at root)
- No Node/npm — zero JS build step; CDN assets only

## Frameworks

**Core:**
- No Laravel, Symfony, or any PHP framework — fully custom MVC built in `src/Core/`
  - `src/Core/Router.php` — regex-based front-controller router
  - `src/Core/Controller.php` — base controller with view rendering, redirects, flash, JSON helpers
  - `src/Core/Database.php` — PDO singleton (MySQL via `pdo_mysql` extension)
  - `src/Core/Auth.php` — session-based authentication
  - `src/Core/Csrf.php` — per-session CSRF token
  - `src/Core/RateLimiter.php` — login brute-force protection (MySQL-backed)
  - `src/Core/Schema.php` — lightweight runtime migrations
  - `src/Core/Env.php` — minimal `.env` file loader (env var wins over file value)

**Frontend (CDN, no local build):**
- Bootstrap 5.3.3 — CSS framework + JS bundle (`cdn.jsdelivr.net`)
- Bootstrap Icons 1.11.3 — icon font (`cdn.jsdelivr.net`)
- Chart.js 4.4.1 — dashboard charts (`cdn.jsdelivr.net`)
- Google Fonts — Inter + Sora (`fonts.googleapis.com`)

**Testing:**
- PHPUnit 11.x — `require-dev` in `composer.json`
- Config: `phpunit.xml` at project root
- Coverage source: `src/Services/` only (unit tests for business logic)

**Build/Dev:**
- Docker Compose — `docker-compose.yml` (three services: `app`, `db`, `phpmyadmin`)
- Apache `.htaccess` — `public/.htaccess` (single front controller rewrite)
- Apache virtual host config — `docker/apache.conf`

## Key Dependencies

**Critical:**
- `ext-pdo` (`*`) — only Composer extension dependency; MySQL PDO extension installed via `docker-php-ext-install pdo_mysql` in Dockerfile
- `phpunit/phpunit` (`^11.0`) — test framework (dev only)

**Infrastructure:**
- No ORM, no query builder, no migration framework — raw PDO with prepared statements throughout
- No Composer autoloader beyond PSR-4 — `App\` namespace mapped to `src/`, `Tests\` to `tests/`

## Configuration

**Environment:**
- Runtime config via `.env` file (loaded by `src/Core/Env.php`) or real environment variables
- Docker Compose injects environment variables directly; they take precedence over `.env`
- Example: `.env.example` at project root
- Key variables: `APP_ENV`, `APP_PORT`, `SESSION_SECURE`, `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER`, `DB_PASSWORD`, `DB_ROOT_PASSWORD`, `PMA_PORT`
- No secrets committed; `.env` excluded from version control

**Build:**
- `Dockerfile` — single-stage, copies app into `php:8.3-apache` image
- `docker-compose.yml` — dev stack definition
- `docker/apache.conf` — Apache virtual host (document root: `/var/www/html/public`)
- `public/.htaccess` — URL rewriting to `index.php`

## Platform Requirements

**Development:**
- Docker + Docker Compose (recommended: `docker compose up`)
- PHP 8.3+ with Composer (for running tests directly outside Docker)
- MySQL 8.0 (provided by Docker service `db`)
- phpMyAdmin available at `APP_PORT+1` (dev only)

**Production:**
- PHP 8.3 + Apache with `mod_rewrite` and `pdo_mysql`
- MySQL 8.0
- Writable path: `public/assets/uploads/` (product images)
- Set `SESSION_SECURE=1` when serving over HTTPS

---

*Stack analysis: 2026-06-12*
