# Phase 2: Database and Schema Migration - Context

**Gathered:** 2026-06-12
**Status:** Ready for planning

<domain>
## Phase Boundary

Get ResellTrack talking to a live, externally-managed **Aiven MySQL 8** instance over **TLS** from Vercel Lambdas, and apply the full schema **once** via a one-shot `bin/migrate.php` script — out of the per-request path. After this phase, product/user CRUD persists in the external managed DB (not Docker), and `Database::connection()` no longer runs DDL on every request.

Requirements: **DB-01** (TLS connection to Aiven from Vercel), **DB-02** (schema applied via one-shot `bin/migrate.php`), **DB-03** (`Schema::ensure()` removed from the request path — no cold-start race).

**Out of scope (own phases):** persistent sessions (Phase 3), image storage / R2 (Phase 4), HSTS / `SESSION_SECURE` / production secrets hardening (Phase 5), connection pooling / ProxySQL (deferred to v2).
</domain>

<decisions>
## Implementation Decisions

### Schema source of truth
- **D-01:** **Keep `src/Core/Schema.php`** — do NOT delete it or fold its DDL into `sql/schema.sql`. The schema continues to live in `sql/schema.sql` (base tables) + `Schema.php` (idempotent structural additions).
- **D-02:** `bin/migrate.php` connects to Aiven and invokes the schema setup **exactly once** (apply `sql/schema.sql`, then call `Schema::ensure()` for the additive DDL — exact ordering is a research/planning detail, see Research Questions). It must be safe to re-run (idempotent: `CREATE TABLE IF NOT EXISTS`, `SHOW COLUMNS` guards already present in `Schema.php`).
- **D-03:** **Remove the `Schema::ensure(self::$instance)` call from `Database::connection()`** (`src/Core/Database.php:41`). Runtime connections do connection only — no DDL. This is the core of DB-03.

### CA cert delivery (TLS)
- **D-04:** **Commit the Aiven CA certificate to the repo** (e.g. `certs/aiven-ca.pem`) and point `PDO::MYSQL_ATTR_SSL_CA` at that path in `Database::connection()`. The CA cert is public information, not a secret — committing it is acceptable and matches PROJECT.md ("certificat CA committé").
- **D-05:** **Verify-server-cert ON** — do NOT set `MYSQL_ATTR_SSL_VERIFY_SERVER_CERT = false`. The connection must validate Aiven's certificate against the committed CA.
- **D-06:** Ensure `certs/` (or the cert file) is NOT excluded by `.vercelignore` so the cert ships in the Lambda bundle. (`.vercelignore` currently excludes `/vendor`, `/.git`, `/docker`, `/.planning`, `/tests`, `/sql`, `.env*` — `certs/` is safe, but the planner must confirm and the migrate script needs `sql/` locally even though `sql/` is excluded from the Vercel bundle — that's fine, migrations run locally, not on Vercel.)

### Migration execution + seed data
- **D-07:** `bin/migrate.php` is run **from the operator's local machine, connecting to Aiven over TLS** — Vercel serverless has no shell to run migrations. This is an operator (human) step, like the Phase 1 GitHub→Vercel connection.
- **D-08:** Production gets **schema only — NO demo seed**. `sql/seed.sql` (demo users/products) is for local Docker dev only and must not be loaded into Aiven. Real users self-register.
- **D-09:** Aiven connection credentials (`DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER`, `DB_PASSWORD`) become **Vercel environment variables** (operator step in the Vercel dashboard). They also go in the operator's local `.env` so `bin/migrate.php` can reach Aiven. `.env` stays gitignored.

### Cold-start connection handling
- **D-10:** **Plain TLS connect — defer all hardening.** No connect-timeout tuning, no retry/backoff this phase. Keep the existing friendly 500 error page in `Database::connection()` (`src/Core/Database.php:42-48`). Connection pooling (ProxySQL) is deferred to v2.
- **D-11:** Accept the **Aiven free-tier concurrent-connection ceiling as an unknown risk** (not publicly documented). Do not engineer around it now; revisit only if a real load test hits the limit (mitigation path: ProxySQL, already in Deferred Items).

### Claude's Discretion
- Exact `bin/migrate.php` structure (bootstrap, output format, exit codes), the precise ordering of `sql/schema.sql` vs `Schema::ensure()`, and how the script reuses the app's `Env`/`Database` classes vs a standalone PDO connection — planner/researcher decide.
- The `.env.example` additions for the new TLS/CA variable(s) (e.g. `DB_SSL_CA` path) — naming at Claude's discretion, consistent with existing `DB_*` convention.
</decisions>

<canonical_refs>
## Canonical References

**Downstream agents MUST read these before planning or implementing.**

### Requirements & project decisions
- `.planning/REQUIREMENTS.md` §Database (DB-01, DB-02, DB-03) — the acceptance criteria this phase must satisfy
- `.planning/PROJECT.md` §Key Decisions — Aiven MySQL 8 confirmed, committed CA cert, MySQL-backed sessions (Phase 3), `Schema::ensure()` → one-shot migration rationale
- `.planning/STATE.md` §Blockers/Concerns — Aiven free-tier connection-limit unknown (Phase 2 risk)

### Code to modify / reference
- `src/Core/Database.php` — add TLS (`MYSQL_ATTR_SSL_CA`) options to the DSN/PDO; remove the `Schema::ensure()` call (line 41); reads config via `Env::get`
- `src/Core/Schema.php` — kept; its `ensure()` is invoked once by `bin/migrate.php` instead of per-request
- `src/Core/Env.php` — `.env` loader; how new `DB_SSL_CA` style var is read
- `sql/schema.sql` — canonical base schema (7.4 KB); applied by `bin/migrate.php`
- `sql/seed.sql` — demo seed; Docker dev only, NOT applied to prod (D-08)
- `.env.example` — add the new TLS/CA variable; document Aiven connection vars
- `.vercelignore` — confirm `certs/` not excluded (D-06)

[No external ADRs beyond the .planning docs above.]
</canonical_refs>

<code_context>
## Existing Code Insights

### Reusable Assets
- `Database::connection()` — singleton PDO; the single place TLS options and the removed `Schema::ensure()` live. One edit point.
- `Schema::ensure(PDO)` — already idempotent (CREATE TABLE IF NOT EXISTS, SHOW COLUMNS guards). Reusable as-is from `bin/migrate.php` (D-01/D-02).
- `Env::get(key, default)` — config accessor; new TLS var reads through it. Real env vars take precedence over `.env` (Vercel injection works).

### Established Patterns
- All models acquire the connection via `Database::connection()` in their constructor — they are agnostic to TLS/host changes, so no model edits are needed.
- `declare(strict_types=1);` + `final` classes + PSR-4 `App\` → `src/`. `bin/migrate.php` is a CLI script (not under `App\` HTTP flow) — first file in a new `bin/` directory.

### Integration Points
- Phase 1's `api/index.php` → `public/index.php` boot already loads `Env` and reaches `Database::connection()` on DB-backed routes; today those routes error on Vercel (no DB). This phase makes them work.
- Vercel env vars (operator-set) feed `Env`/`getenv` at runtime — same mechanism Phase 1 deliberately left empty.
</code_context>

<specifics>
## Specific Ideas

- The committed-CA-cert approach is explicitly called out in PROJECT.md as the intended design ("certificat CA committé") — D-04 honors that rather than inventing an env-var-to-/tmp scheme.
- migrate.php as an operator-run local script mirrors the Phase 1 pattern (human runs a step the agent/serverless can't), keeping the workflow consistent.
</specifics>

<deferred>
## Deferred Ideas

- **Connection pooling (ProxySQL)** — only if Aiven's concurrent-connection ceiling is hit under real load. Already in STATE.md Deferred Items (v2/OPS).
- **Health endpoint `/health` (DB status + connection count)** — v2/OPS, already deferred.
- **Graceful 503 page on DB failure** — v2/OPS; this phase keeps the existing friendly 500 (D-10).
- **Preview-deploy DB isolation (`VERCEL_ENV`)** — v2/OPS, deferred.

None of these are Phase 2 blockers.

### Research Questions (for gsd-phase-researcher)
- Exact PDO MySQL SSL option set for Aiven (`MYSQL_ATTR_SSL_CA`, and whether `MYSQL_ATTR_SSL_VERIFY_SERVER_CERT` must be explicitly true) under PHP 8.3 / vercel-php@0.7.4. Confirm the cert file is readable from the Lambda bundle.
- Does `sql/schema.sql` already contain everything `Schema.php::ensure()` adds (orders, market_price_* columns, product_images, currency enum), or has Schema.php drifted ahead? Determine the correct apply order in `bin/migrate.php` so a fresh Aiven DB ends up identical to a fully-migrated Docker DB (no missing tables/columns, no errors on re-run).
- Aiven MySQL 8 default TLS requirements (does Aiven reject non-TLS connections outright?) and the DSN/port specifics from the Aiven console.
</deferred>

---

*Phase: 2-Database and Schema Migration*
*Context gathered: 2026-06-12*
