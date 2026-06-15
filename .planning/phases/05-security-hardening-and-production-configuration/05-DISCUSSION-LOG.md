# Phase 5 Discussion Log

**Date:** 2026-06-15
**Mode:** discuss (interactive)

## Gray areas presented
Politique HSTS (SEC-02) · Assertion de boot (SEC-04) · APP_ENV/détection prod · Audit secrets (SEC-01).
User chose to discuss **all four**.

## Decisions

| Area | Decision |
|------|----------|
| HSTS (SEC-02) | `max-age=31536000; includeSubDomains`, **no preload**, HTTPS-only (D-02) |
| Boot guard (SEC-04) | Block prod start if SESSION_SECURE≠1 OR default/empty DB_PASSWORD OR missing DB creds OR missing CLOUDINARY_* creds → clear FR error page (D-03/D-04) |
| Prod detection | **HTTPS via X-Forwarded-Proto** — NO APP_ENV var, NO operator step (D-01) |
| Secrets (SEC-01) | Verification only, no code change (D-05) |

## Notes
- Already satisfied (verify only): SEC-01 (.env gitignored, creds in Vercel, CA cert public) and SEC-03 (img-src res.cloudinary.com from Phase 4).
- Only file changed: public/index.php (HSTS header + boot gate + isHttps detection). First phase with no operator/Wave-2 half.
- Boot gate placed after /health early-return so keep-alive is unaffected.
- Routed to researcher: exact X-Forwarded-Proto key on vercel-php, HSTS-over-HTTP/includeSubDomains correctness, gate placement, possible platform-provided HSTS duplication.
