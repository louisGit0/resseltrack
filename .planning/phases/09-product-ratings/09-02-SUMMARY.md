# 09-02 Summary — Product model + controller (rating)

**Plan:** 09-02 (Wave 1, autonomous)
**Status:** Complete — 2/2 tasks.
**Note:** Task 2 was implemented by the executor but left uncommitted (it ended on a scope justification instead of the commit/SUMMARY step); the orchestrator finalized it (commit `6c29e22` + this SUMMARY + tracking).

## Commits
- `5ac8215` — feat(09-02): persist rating + rating_note in Product create/update + add setRating()
- `6c29e22` — feat(09-02): product rating validation + quick-rate rate() action (CSRF + ownership)

## What shipped
- **`src/Models/Product.php`** — `rating` + `rating_note` added to `create()` and **both** `update()` SQL branches (clauses + binds). New single-column **`setRating($id, $userId, $rating)`** (modeled on `setCover()`), `:uid`-scoped, that updates ONLY `rating` — it cannot clobber `rating_note` (the comment is edited via the full form). `grep -c "rating = :rating, rating_note = :rating_note"` = 2 (both branches).
- **`src/Controllers/ProductController.php`**:
  - `validate()` now reads `rating` (`'' → null`, else int) and `rating_note` (trimmed), validates 1-5-or-null, preserves both as old-input on error, and returns them in the data array (mirrors `SupplierController::validate()`).
  - New **`rate()`** action: `Csrf::validate()` first line; `Product::find($pid, Auth::id()) === null → flash + redirect` (IDOR guard); rating cast to int, range-checked 1-5 (or null to clear); calls `setRating()`; redirects to `/products/{id}`. `rating_note` field name is distinct from `description`.

## Verification
- `php -l` clean on both files; `vendor/bin/phpunit` OK (27 tests, 30 assertions) — ProfitCalculator regression green.
- Source asserts: `setRating` defined (1), `rate()` defined (1), both update branches carry the rating columns (2), `rating_note` distinct from description.

## Notes
- Route `POST /products/{id}/rate` is intentionally NOT registered here — that + the views land in plans 09-03/09-04. `rate()` exists but is not yet routable until 09-04.
- RATE-01 stays In Progress (storage + backend landed 09-01/09-02; UI in 09-03/09-04; live verify in 09-05).
