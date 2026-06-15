---
phase: 04-image-storage-r2
plan: "02"
subsystem: storage
tags: [cloudinary, images, upload, verification, operator, pivot]
dependency_graph:
  requires: [cloudinary-storage-service, csp-img-src, upload-size-guard]
  provides: [live-image-storage, store-verified-live]
  affects: [product-images, all-product-pages]
tech_stack:
  added: [cloudinary]
  removed: [aws-sdk-php, cloudflare-r2]
  patterns: [signed-rest-upload-curl, cloudinary-cdn-delivery]
key_files:
  created: []
  modified: []
decisions:
  - "Provider pivot R2 -> Cloudinary (R2/Supabase needed a credit card or hit the Supabase free 2-project quota); Cloudinary free, no card"
  - "CloudinaryStorage uses signed REST API via curl (no SDK); aws/aws-sdk-php dropped, composer.lock back to dev-only"
  - "delete() uses invalidate=true so the CDN copy is purged (STORE-03 HEAD -> 404), not just the storage object"
metrics:
  completed_date: "2026-06-15"
  tasks_completed: 2
  files_created: 0
  files_modified: 0
---

# Phase 4 Plan 02: Cloudinary Setup & Live Verification Summary

**One-liner:** After pivoting image storage from Cloudflare R2 to Cloudinary (both R2 and Supabase required a card or hit a free quota), the operator created a free Cloudinary account and set the 3 `CLOUDINARY_*` Vercel env vars; STORE-01..05 then verified end-to-end on the live site.

## Provider pivot (why this differs from the original R2 plan)
- Cloudflare R2 free tier requires a **credit card** to activate → user declined.
- Supabase Storage (S3-compatible) was the next pick, but the org had hit the **free 2-project quota** and the user preferred not to mix ResellTrack storage into an existing app's project.
- **Cloudinary** chosen: free, no card, independent account, public delivery URLs by default. Code pivoted (see 04-CONTEXT AMENDMENT 2026-06-15): `CloudinaryStorage` (signed REST via curl), `aws/aws-sdk-php` dropped, CSP `img-src https://res.cloudinary.com`, env vars `CLOUDINARY_CLOUD_NAME/API_KEY/API_SECRET`.

## Tasks Completed

| Task | Name | Result |
|------|------|--------|
| 1 | Operator: Cloudinary account + 3 Vercel env vars + redeploy | Done — cloud `dxx4qwzab`; build green; /health 200 |
| 2 | Verify STORE-01..05 on the live URL | All PASS — see table |

## Live Verification Results (https://resseltrack-nu.vercel.app, commit 97ac286)

| Requirement | Status | Evidence |
|-------------|--------|----------|
| STORE-01: upload → storage + URL in DB | PASS | Product cover upload stored `https://res.cloudinary.com/dxx4qwzab/image/upload/v…/<id>.png`; HEAD 200 |
| STORE-02: image displays from CDN URL | PASS | `curl -sI` on the stored URL → 200, `content-type: image/png`; CSP `img-src` allows res.cloudinary.com (no violation) |
| STORE-03: delete removes the object | PASS | Gallery image deleted → with `invalidate=true`, HEAD on the URL → 404 within ~15s |
| STORE-04: existing local-path records | PASS | Production DB had no `/assets/uploads/` records (documented fallback; prod was empty) |
| STORE-05: ~3.5 MB size guard + clear message | PASS | 4 MB upload → flash "Image ignorée : elle dépasse la taille maximale autorisée (~3,5 Mo)." (no blank page) |

## Deviations / Fixes during execution
- **composer.lock** was missing → the first R2 build failed (`composer install` from lock, aws-sdk-php absent). Generated it; later reverted to dev-only when aws-sdk-php was dropped for Cloudinary.
- **Cloudinary delete** initially left the CDN copy (HEAD still 200). Fixed with `invalidate=true` (signed) → HEAD 404.
- Aiven free-tier DB had powered off mid-phase (inactivity); operator powered it back on; a `/health` + GitHub Actions keep-alive (every 10 min) was added to prevent recurrence.

## Cleanup
Test users/products/images and sessions truncated; production DB pristine (0 rows). A handful of tiny 1×1 test PNGs may remain in the Cloudinary media library (harmless, free tier).

## Known follow-up (flagged, out of scope)
`ProductController::destroy()` (deleting a whole product) does not purge the product's Cloudinary cover/gallery objects — only `deleteImage()` (single gallery photo) does. Deleting a product can orphan its Cloudinary images. Tracked as a follow-up.

## Self-Check: PASSED
| Check | Result |
|-------|--------|
| Live upload stores Cloudinary URL | CONFIRMED |
| Image serves from res.cloudinary.com | CONFIRMED |
| Delete purges object (HEAD 404) | CONFIRMED |
| >3.5 MB shows FR message | CONFIRMED |
| No card / no quota provider | CONFIRMED (Cloudinary) |
| Test data cleaned up | CONFIRMED |
