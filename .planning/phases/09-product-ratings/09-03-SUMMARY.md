# Phase 9 Plan 03: Product rating form section + list badge Summary

**Plan:** 09-03 (Wave 2, autonomous, depends_on 09-01, 09-02)
**Status:** Complete — 2/2 tasks.
**One-liner:** Wired the two product display/edit surfaces that reuse the Phase 8 star widgets unchanged — a "Note du produit" section in the product form (clickable stars `name=rating` + `rating_note` comment textarea) and a read-only star badge next to the product name in the list (rated products only, comment hidden).

## Commits
- `3800b35` — feat(09-03): add Note du produit section to product form
- `fbb64a3` — feat(09-03): add read-only rating badge next to product name in list

## What shipped

### `src/Views/products/form.php`
- New `form-section-title` block **"Note du produit"** inserted after the market-price `row g-3`, still inside the same card-body.
- Star widget copied verbatim from `suppliers/form.php`: `<div class="star-rating" data-star-rating>` + hidden `<input name="rating" value="<?= e($val('rating')) ?>">` + 5 `.star-btn` buttons + `.star-clear[data-star-clear]`. Auto-wired by the existing `initStarRating()` on `[data-star-rating]` — **no new JS**.
- `$errors['rating']` inline error + French help text.
- **Commentaire** `<textarea name="rating_note" ...><?= e($val('rating_note')) ?></textarea>` — distinct from the existing `name="description"` (verified: `name="description"` count stays at 1, no `name="comment"` introduced).
- Both fields pre-fill in edit mode via the existing `$val()` helper; persisted by the existing `store()`/`update()` → `validate()` from plan 09-02 (D-02 entry point 1).

### `src/Views/products/index.php`
- A small `<span class="supplier-stars" ... style="font-size:.8rem">` added immediately after the product-name link, gated on `$p['rating'] !== null`. Five `bi-star-fill` / `bi-star` icons; numeric output cast via `(int)`.
- Unrated products render nothing (neutral, D-04). The comment (`rating_note`) is intentionally **not** rendered in the list (`grep -c rating_note` = 0, D-04).
- Reuses the existing `.supplier-stars` CSS verbatim — **no new CSS**, no new card/table column.

## Verification
- `php -l` clean on both views ("No syntax errors detected").
- Acceptance asserts (form): `name="rating"` =1, `name="rating_note"` =1, `data-star-rating` =1, `"Note du produit"` =1, `e($val('rating_note'))` =1, `name="description"` =1 (no duplicate), no `name="comment"`.
- Acceptance asserts (list): `supplier-stars` =1, `$p['rating'] !== null` =1, `rating_note` =0.
- Live edit/save + list-badge appearance is verified by the operator in plan 09-05.

## Deviations from Plan
None — plan executed exactly as written.

## Threat surface
- T-09-04 (stored XSS) mitigated as planned: `rating_note` rendered via `e($val('rating_note'))` in the form; list rating rendered via `(int)` cast — no raw user text reaches the DOM.
- T-09-05 (CSRF) unchanged: the Note fields sit inside the existing product form which already carries `Csrf::field()`; no new endpoint added by this plan.
- No new security surface introduced.

## Notes
- This plan touches **views only**. The backend (model/controller/validate/rate) landed in 09-01/09-02; the detail-page interactive quick-rate + route registration land in 09-04; live verify in 09-05.
- RATE-01 remains In Progress at the end of this plan (UI half done: form + list shipped here, detail page in 09-04).

## Self-Check: PASSED
- FOUND: src/Views/products/form.php (committed 3800b35)
- FOUND: src/Views/products/index.php (committed fbb64a3)
- FOUND: commit 3800b35
- FOUND: commit fbb64a3
