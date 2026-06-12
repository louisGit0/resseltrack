---
phase: 1
slug: routing-and-front-controller
status: draft
nyquist_compliant: true
wave_0_complete: false
created: 2026-06-12
---

# Phase 1 — Validation Strategy

> Per-phase validation contract for feedback sampling during execution.

---

## Test Infrastructure

| Property | Value |
|----------|-------|
| **Framework** | PHPUnit 11 (existing) |
| **Config file** | `phpunit.xml` (project root) |
| **Quick run command** | `vendor/bin/phpunit` |
| **Full suite command** | `vendor/bin/phpunit --coverage-text` |
| **Estimated runtime** | ~5 seconds |

> Phase 1 adds zero new PHP business logic — only config files (`vercel.json`, `.vercelignore`) and a one-line wrapper (`api/index.php`). The existing PHPUnit suite (`ProfitCalculatorTest`) serves as a regression guard. The phase-specific success criteria (DEPLOY-01/02/03) are verified by deployment smoke checks, which are inherently manual.

---

## Sampling Rate

- **After every task commit:** Run `vendor/bin/phpunit` (regression guard — no break to local PHP logic)
- **After every plan wave:** Run `vendor/bin/phpunit` + manual smoke on the Vercel preview URL
- **Before `/gsd:verify-work`:** All 3 success criteria verified on the live Vercel deployment URL; PHPUnit green
- **Max feedback latency:** 5 seconds (unit) / minutes (deployment smoke)

---

## Per-Task Verification Map

| Task ID | Plan | Wave | Requirement | Threat Ref | Secure Behavior | Test Type | Automated Command | File Exists | Status |
|---------|------|------|-------------|------------|-----------------|-----------|-------------------|-------------|--------|
| 1-01-xx | 01 | 1 | DEPLOY-03 | — | No regression to existing PHP logic | unit | `vendor/bin/phpunit` | ✅ `tests/ProfitCalculatorTest.php` | ⬜ pending |

*Status: ⬜ pending · ✅ green · ❌ red · ⚠️ flaky*

> Most Phase 1 tasks produce configuration, not testable PHP units. Their verification is in the Manual-Only table below. The PHPUnit run is a continuous regression guard across all tasks (no 3 consecutive config tasks should land without re-running it).

---

## Wave 0 Requirements

*Existing infrastructure covers all phase requirements.* No new test files are required — Phase 1 changes are configuration plus a one-line wrapper. `tests/ProfitCalculatorTest.php` covers the only tested unit and serves as the regression guard.

---

## Manual-Only Verifications

| Behavior | Requirement | Why Manual | Test Instructions |
|----------|-------------|------------|-------------------|
| All routes reach the PHP front controller on Vercel | DEPLOY-01 | Requires a live Vercel deployment | `curl -sI https://<vercel-url>/login` → expect HTTP 200 (login HTML) or a redirect to `/login`; load `/products`, `/dashboard` in a browser and confirm PHP-rendered HTML (DB errors are acceptable this phase — env vars come in Phase 2). |
| CSS/JS served by the CDN without invoking PHP | DEPLOY-02 | Response-header inspection on a live deploy | `curl -sI https://<vercel-url>/assets/css/style.css` → expect `content-type: text/css`, an `x-vercel-cache` header, and NO `x-powered-by: PHP`. Repeat for `/assets/js/app.js`. Browser devtools: no function invocation for `.css`/`.js`. |
| Local Docker dev unchanged | DEPLOY-03 | Requires running the local Docker stack | `docker compose up` then load `http://localhost:8080` — app serves through Apache with `public/.htaccess` unchanged; `vendor/bin/phpunit` stays green. |

---

## Validation Sign-Off

- [ ] All tasks have `<automated>` verify (PHPUnit regression) or are config tasks captured in the Manual-Only table
- [ ] Sampling continuity: PHPUnit re-run guards against 3 consecutive untested config tasks
- [ ] Wave 0 covers all MISSING references (none required)
- [ ] No watch-mode flags
- [ ] Feedback latency < 5s (unit)
- [x] `nyquist_compliant: true` set in frontmatter

**Approval:** pending
