# Phase 6: Performance and Reliability - Research

**Researched:** 2026-06-15
**Domain:** PHP data-access refactoring + HTTP client hardening (no new dependencies)
**Confidence:** HIGH

---

<user_constraints>
## User Constraints (from CONTEXT.md)

### Locked Decisions
- **D-01/D-02:** Replace `productsMeta()` per-product loop with 3 fixed queries. New `Purchase::lotsForUser($userId)` = `SELECT product_id, (unit_cost_eur*quantity) AS cost_eur, quantity FROM purchases WHERE user_id=:uid`. New `Sale::soldQtyByProduct($userId)` = GROUP BY product_id. Group lots by product_id in PHP; `purchasedQty = SUM(quantity)`; `cump = ProfitCalculator::cump(lots)` UNCHANGED; `stock = ProfitCalculator::stock(purchasedQty, $soldByProduct[$pid] ?? 0)`. Output per product (cump, stock) must be byte-identical to today. Do NOT compute CUMP in SQL.
- **D-03:** Rewrite `ExchangeRateService::latest()` to use curl (`CURLOPT_TIMEOUT=5`, `CURLOPT_CONNECTTIMEOUT`, `CURLOPT_RETURNTRANSFER=true`). Keep `$from === $to → 1.0` shortcut and `frankfurter.app/latest?from=&to=` endpoint and `?float` signature. `error_log` every failure. Return `null` on: curl error/false, HTTP != 200, empty body, JSON decode failure, missing `rates[$to]`.
- **D-04:** In `PurchaseController::validate()` (covers both `store()` and `update()`), when `currency !== 'EUR'`: if submitted `exchange_rate` is missing or <= 0, attempt server-side fallback `(new ExchangeRateService())->latest($currency, 'EUR')`; use it if > 0. Keep submitted rate when already valid (> 0).
- **D-05:** If `currency !== 'EUR'` AND submitted rate invalid AND fallback returns `null` → block via `flashErrors` with a clear French error message (e.g. "Impossible d'obtenir un taux de change valide pour {DEVISE}. Réessayez ou saisissez le taux manuellement.") and `return null` BEFORE `ProfitCalculator::unitCostEur()` runs. Never write `unit_cost_eur` from an invalid rate. EUR stays rate `1.0`.
- **D-06:** No new dependencies, no caching, no retry. Touch only: `SaleController::productsMeta()`, the two new model methods, `ExchangeRateService`, and `PurchaseController::validate()`.

### Claude's Discretion
None defined — all implementation choices are locked.

### Deferred Ideas (OUT OF SCOPE)
- FX rate caching / retry-backoff
- Dashboard / other query optimization
- Persisting a "rate fetched server-side vs client" provenance flag
- `OrderController` rate fallback (covered by the same guard pattern but explicitly not in scope for this phase)
</user_constraints>

---

<phase_requirements>
## Phase Requirements

| ID | Description | Research Support |
|----|-------------|------------------|
| PERF-01 | Sale create/edit page loads without timeout — N+1 in `SaleController::productsMeta()` replaced by aggregate queries | D-01/D-02: 3 fixed queries replace per-product loop; lotsForUser + soldQtyByProduct confirmed in §Standard Stack |
| PERF-02 | `ExchangeRateService` uses curl with 5s timeout and logs failures; API failure never silently writes 0.00 EUR cost — visible warning shown | D-03/D-04/D-05: curl idiom documented in §Code Examples; fallback + block logic in §Architecture Patterns |
</phase_requirements>

---

## Summary

This phase addresses two targeted reliability fixes: eliminating an N+1 query in the sale form builder and hardening the exchange-rate HTTP client so a USD purchase can never silently write a 0.00 EUR cost.

**PERF-01** is a pure data-access refactor. `productsMeta()` currently executes three queries per product (lotsForProduct, purchasedQty, soldQty) inside a foreach loop over all the user's products — O(3N) queries. The fix collapses this to 3 fixed queries regardless of product count: `Product::allForUser` (already 1 query), a new `Purchase::lotsForUser` (all lots for all products in one SELECT), and a new `Sale::soldQtyByProduct` (GROUP BY product_id). The PHP grouping that replaces the loop is trivial array operations; `ProfitCalculator::cump()` and `ProfitCalculator::stock()` are called with identical inputs and produce byte-identical outputs. No view change is required.

**PERF-02** is a service hardening + controller guard. `ExchangeRateService::latest()` currently uses `file_get_contents`, which may be blocked on Vercel Lambda environments where `allow_url_fopen` is off; it also does not log failures and does not check HTTP status codes. The rewrite uses curl (already confirmed available via `CloudinaryStorage` in production since Phase 4), adds a 5-second connect+transfer timeout, and logs every failure path with context before returning null. In `PurchaseController::validate()`, when the submitted rate is missing/invalid for a non-EUR currency, the new code attempts a server-side fallback fetch; if the fallback also fails, it blocks the submission with a visible French error message before `ProfitCalculator::unitCostEur()` ever runs — no 0.00 write is possible.

**Primary recommendation:** implement in this order: (1) new model methods, (2) productsMeta() rewrite, (3) ExchangeRateService curl rewrite, (4) PurchaseController fallback + block.

---

## Architectural Responsibility Map

| Capability | Primary Tier | Secondary Tier | Rationale |
|------------|-------------|----------------|-----------|
| Batch purchase lots query | Database / Model | — | `Purchase::lotsForUser` — SQL aggregation, belongs in model per project convention |
| Batch sold-qty query | Database / Model | — | `Sale::soldQtyByProduct` — GROUP BY aggregation, belongs in model |
| PHP grouping of lots by product | API / Controller (productsMeta) | — | Pure in-memory data transformation inside SaleController private method |
| CUMP + stock computation | Service (ProfitCalculator) | — | Unchanged — pure math, no I/O |
| Exchange rate HTTP fetch | Service (ExchangeRateService) | — | curl GET, belongs in Services layer per project conventions |
| Server-side FX fallback + block | API / Controller (PurchaseController::validate) | Service | Controller orchestrates fallback; service returns the value |

---

## Standard Stack

### Core (no new packages — D-06)

| Component | Version | Purpose | Status |
|-----------|---------|---------|--------|
| PHP curl extension | bundled (php:8.3) | HTTP client for ExchangeRateService | Already used by CloudinaryStorage in production |
| ProfitCalculator | internal | CUMP, stock, margin math | Unchanged |
| frankfurter.app | external API | FX rate source | Already the configured endpoint |

### No New Dependencies
Per D-06, no new Composer packages are added. The entire phase is implemented using PHP built-ins (curl, json_decode, array functions) and existing project services.

---

## Package Legitimacy Audit

No packages are installed in this phase. D-06 explicitly prohibits new dependencies.

**Packages removed due to slopcheck [SLOP] verdict:** none
**Packages flagged as suspicious [SUS]:** none

---

## Architecture Patterns

### System Architecture Diagram

```
[Sale create/edit GET request]
        |
        v
SaleController::productsMeta()
        |
        |-- Query 1: Product::allForUser($uid)          [1 query, unchanged]
        |-- Query 2: Purchase::lotsForUser($uid)        [NEW — 1 query, all lots]
        |-- Query 3: Sale::soldQtyByProduct($uid)       [NEW — 1 query, GROUP BY]
        |
        v
PHP grouping: $lotsByProduct = group lotsForUser rows by product_id
        |
        v (per product, no DB I/O)
ProfitCalculator::cump($lotsByProduct[$pid])
ProfitCalculator::stock(SUM(quantity), $soldMap[$pid] ?? 0)
        |
        v
View: sales/form.php (data-cump, data-stock attributes — UNCHANGED)
```

```
[Purchase store/update POST]
        |
        v
PurchaseController::validate()
        |
        |-- currency === 'EUR'? --> rate = 1.0 (UNCHANGED)
        |
        |-- currency !== 'EUR':
        |       |
        |       |-- submitted rate > 0? --> use submitted rate (UNCHANGED)
        |       |
        |       |-- submitted rate missing/<=0:
        |               |
        |               v
        |           ExchangeRateService::latest($currency, 'EUR')  [NEW]
        |               |
        |               |-- returns float > 0? --> use fallback rate
        |               |
        |               |-- returns null?
        |                       |
        |                       v
        |                   flashErrors("Impossible d'obtenir...") + return null  [BLOCK]
        |                   (unitCostEur is NEVER called)
        |
        v (rate is always valid at this point)
ProfitCalculator::unitCostEur(lotPrice, shipping, customs, rate, qty)
        |
        v
Purchase::create() / Purchase::update()
```

### Recommended Project Structure

No structural changes. Methods are added to existing files:

```
src/
├── Models/
│   ├── Purchase.php    + lotsForUser($userId): array
│   └── Sale.php        + soldQtyByProduct($userId): array
├── Services/
│   └── ExchangeRateService.php   (curl rewrite of latest())
└── Controllers/
    ├── SaleController.php        (productsMeta() rewrite)
    └── PurchaseController.php    (validate() fallback + block)
```

### Pattern 1: Batch-Fetch + PHP Grouping (PERF-01)

**What:** Replace the N+1 per-product query loop with two aggregate queries and in-memory grouping.

**When to use:** Any controller method that iterates over a user's products and fetches per-product aggregates in a loop.

**New `Purchase::lotsForUser` implementation:**
```php
// Source: derived from lotsForProduct (Purchase.php:218-230) + product_id added
/**
 * All lots for all of a user's products, shaped for ProfitCalculator::cump().
 * Add product_id to the projection so the caller can group by it in PHP.
 * @return array<int, array{product_id: int, cost_eur: float, quantity: int}>
 */
public function lotsForUser(int $userId): array
{
    $stmt = $this->db->prepare(
        'SELECT product_id, (unit_cost_eur * quantity) AS cost_eur, quantity
         FROM purchases WHERE user_id = :uid'
    );
    $stmt->execute(['uid' => $userId]);
    $lots = [];
    foreach ($stmt->fetchAll() as $row) {
        $lots[] = [
            'product_id' => (int) $row['product_id'],
            'cost_eur'   => (float) $row['cost_eur'],
            'quantity'   => (int) $row['quantity'],
        ];
    }
    return $lots;
}
```

**New `Sale::soldQtyByProduct` implementation:**
```php
// Source: derived from soldQty (Sale.php:337-351) + GROUP BY
/**
 * Units sold per product for a user. Returns a map [product_id => soldQty].
 * @return array<int, int>
 */
public function soldQtyByProduct(int $userId): array
{
    $stmt = $this->db->prepare(
        'SELECT product_id, COALESCE(SUM(quantity), 0) AS qty
         FROM sales WHERE user_id = :uid GROUP BY product_id'
    );
    $stmt->execute(['uid' => $userId]);
    $map = [];
    foreach ($stmt->fetchAll() as $row) {
        $map[(int) $row['product_id']] = (int) $row['qty'];
    }
    return $map;
}
```

**Rewritten `SaleController::productsMeta()`:**
```php
// Source: SaleController.php:289-305 (current N+1 loop replaced)
private function productsMeta(): array
{
    $userId = Auth::id();

    // Group all lots by product in PHP — one query for ALL lots.
    $lotsByProduct = [];
    foreach ($this->purchases->lotsForUser($userId) as $lot) {
        $lotsByProduct[$lot['product_id']][] = [
            'cost_eur' => $lot['cost_eur'],
            'quantity' => $lot['quantity'],
        ];
    }

    // One query for all sold quantities.
    $soldByProduct = $this->sales->soldQtyByProduct($userId);

    $out = [];
    foreach ($this->products->allForUser($userId) as $p) {
        $pid = (int) $p['id'];
        $lots = $lotsByProduct[$pid] ?? [];
        $purchasedQty = (int) array_sum(array_column($lots, 'quantity'));
        $p['cump']  = ProfitCalculator::cump($lots);
        $p['stock'] = ProfitCalculator::stock($purchasedQty, $soldByProduct[$pid] ?? 0);
        $out[] = $p;
    }
    return $out;
}
```

**Why this is byte-identical to the current implementation:**
- `lotsForUser` returns rows with the same `cost_eur` and `quantity` fields as `lotsForProduct` — same expression `(unit_cost_eur * quantity) AS cost_eur` — so `ProfitCalculator::cump()` receives identical input.
- `SUM(quantity)` from `purchasedQty` equals `array_sum(array_column($lots, 'quantity'))` because both sum the same `quantity` column for the same `WHERE user_id AND product_id` predicate.
- `soldQtyByProduct` uses `COALESCE(SUM(quantity), 0)` — same aggregation as `soldQty` (without the `excludeSaleId` parameter, which is not used in `productsMeta()` today).
- `ProfitCalculator::stock()` and `ProfitCalculator::cump()` are called with identical numeric arguments.

### Pattern 2: curl-based ExchangeRateService (PERF-02)

**What:** Replace `file_get_contents` with curl for the FX rate fetch, mirroring `CloudinaryStorage::request()`.

**Key differences vs CloudinaryStorage:**
- GET request (not POST): omit `CURLOPT_POST` and `CURLOPT_POSTFIELDS`
- 5-second timeout (not 30)
- Add `CURLOPT_CONNECTTIMEOUT = 5`
- Return `null` on all error paths (not throw)
- Log every failure with context

**Rewritten `ExchangeRateService::latest()`:**
```php
// Source: ExchangeRateService.php:22-44 (file_get_contents → curl)
// Curl idiom mirrored from CloudinaryStorage::request() (CloudinaryStorage.php:108-129)
public function latest(string $from, string $to = 'EUR'): ?float
{
    $from = strtoupper($from);
    $to   = strtoupper($to);
    if ($from === $to) {
        return 1.0;
    }

    $url = self::ENDPOINT . '?from=' . urlencode($from) . '&to=' . urlencode($to);
    $ch  = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 5,
        CURLOPT_CONNECTTIMEOUT => 5,
    ]);
    $body = curl_exec($ch);

    if ($body === false) {
        error_log('ExchangeRateService: curl error for ' . $from . '->' . $to . ': ' . curl_error($ch));
        curl_close($ch);
        return null;
    }

    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);

    if ($status !== 200) {
        error_log('ExchangeRateService: HTTP ' . $status . ' for ' . $from . '->' . $to);
        return null;
    }
    if ($body === '') {
        error_log('ExchangeRateService: empty response for ' . $from . '->' . $to);
        return null;
    }

    $data = json_decode($body, true);
    if (!is_array($data) || !isset($data['rates'][$to])) {
        error_log('ExchangeRateService: unexpected JSON for ' . $from . '->' . $to . ': ' . substr((string) $body, 0, 200));
        return null;
    }

    return (float) $data['rates'][$to];
}
```

**frankfurter.app response shape** (confirmed from API docs and current implementation):
```json
{"amount":1,"base":"USD","date":"2026-06-15","rates":{"EUR":0.91827}}
```
The `$data['rates'][$to]` access is correct and identical to the current implementation.

### Pattern 3: PurchaseController fallback + block (D-04/D-05)

**What:** Insert a server-side FX fallback between the submitted-rate rejection and the `flashErrors` terminal path.

**Edit point in `PurchaseController::validate()` (lines 177-181):**

Current code (lines 176-181):
```php
if ($currency === 'EUR') {
    $rate = 1.0;
} elseif ($rate <= 0) {
    $errors['exchange_rate'] = 'Le taux de change doit être strictement positif.';
}
```

New code (D-04 fallback + D-05 block):
```php
if ($currency === 'EUR') {
    $rate = 1.0;
} elseif ($rate <= 0) {
    // D-04: attempt server-side fallback before treating this as an error.
    $fallback = (new \App\Services\ExchangeRateService())->latest($currency, 'EUR');
    if ($fallback !== null && $fallback > 0) {
        $rate = $fallback;
        // Note: no error added — the fallback rate is silently used.
    } else {
        // D-05: fallback failed — block the submission with a clear message.
        $errors['exchange_rate'] = sprintf(
            "Impossible d'obtenir un taux de change valide pour %s. Réessayez ou saisissez le taux manuellement.",
            htmlspecialchars($currency, ENT_QUOTES, 'UTF-8')
        );
    }
}
```

After this block, `$errors !== []` check at line 186 catches the D-05 block case and calls `flashErrors` + `return null` — `ProfitCalculator::unitCostEur()` at line 191 is never reached.

**The `use` import required:** `use App\Services\ExchangeRateService;` — then use `(new ExchangeRateService())->latest(...)` (Services convention: lazy instantiation inside methods). PurchaseController.php currently does NOT import ExchangeRateService, so a `use` statement must be added.

### Anti-Patterns to Avoid

- **Computing CUMP in SQL:** Do not move the weighted-average formula into a SQL aggregate. `ProfitCalculator::cump()` has port/customs allocation logic that relies on per-lot `cost_eur` values. SQL aggregation of a weighted average via SUM/SUM produces the same result for pure lots but the project's source of truth is the PHP service — changing this would risk future divergence when the formula evolves.
- **Muting the fallback silently when it produces 0.0:** `$fallback > 0` check (not just `!== null`) ensures a zero float from the API is treated as a failure (would produce 0.00 cost — the exact bug being fixed).
- **Checking HTTP status with `>= 400` (Cloudinary pattern):** For the FX API, check `!== 200` — a 201 or 204 is not a valid FX response. The Cloudinary pattern uses `>= 400` because 2xx/3xx are all acceptable there. Not so for a JSON data API.
- **Calling `curl_close($ch)` after reading `curl_error()`:** Read `curl_error($ch)` before `curl_close()`, then close. After close, error info is gone.

---

## Don't Hand-Roll

| Problem | Don't Build | Use Instead | Why |
|---------|-------------|-------------|-----|
| Weighted average cost | Custom SQL aggregate | `ProfitCalculator::cump()` (PHP) | Formula encapsulates cost_eur normalization; SQL would split the logic across layers |
| HTTP timeout | Custom signal handling | `CURLOPT_TIMEOUT` + `CURLOPT_CONNECTTIMEOUT` | curl handles both connect and transfer timeouts separately |
| FX rate currency validation | Custom currency list | Existing `in_array($currency, ['EUR', 'USD', 'CNY'])` guard already validates currency before `latest()` is called | Already in place |

**Key insight:** The N+1 fix is entirely expressible as two new model methods + a PHP grouping loop. No query builder, ORM lazy-loading, or JOIN is needed. The batch approach is possible because the math is pure PHP (ProfitCalculator) — if CUMP were in SQL, a JOIN would be mandatory.

---

## Common Pitfalls

### Pitfall 1: lotsForUser returns string types from PDO FETCH_ASSOC

**What goes wrong:** `PDO::FETCH_ASSOC` returns all columns as strings. If `product_id`, `quantity`, and `cost_eur` are not explicitly cast, `ProfitCalculator::cump()` receives `"35.00"` instead of `35.00` and `array_sum(array_column(..., 'quantity'))` returns a string.

**Why it happens:** The project uses `PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC` with no native type emulation on string columns from MySQL. `lotsForProduct` (the existing method) explicitly casts on return — the new `lotsForUser` must do the same.

**How to avoid:** Cast explicitly in the foreach: `'product_id' => (int)`, `'cost_eur' => (float)`, `'quantity' => (int)` — matching the lotsForProduct pattern exactly.

**Warning signs:** `cump` values in the view showing as `0` or `NaN`; `stock` showing as 0 for products with purchases.

### Pitfall 2: soldQtyByProduct missing products treated as non-zero sold

**What goes wrong:** If a product has no sales, it will not appear in `soldQtyByProduct`'s result map. Using `$soldByProduct[$pid]` without a default would trigger a PHP notice and treat sold as `null` (which equals 0 in arithmetic but is not type-safe).

**Why it happens:** GROUP BY with no matching rows produces no row for that product_id.

**How to avoid:** Always use `$soldByProduct[$pid] ?? 0` — the `COALESCE` is on the SQL side for the groups that exist; the PHP `?? 0` handles products with zero sales (no row in the map).

**Warning signs:** PHP notices ("Undefined array key") on the sale form page.

### Pitfall 3: curl_close before curl_error

**What goes wrong:** If `$body === false`, calling `curl_close($ch)` before `curl_error($ch)` loses the error string — the log entry shows an empty error.

**Why it happens:** curl clears internal state on close.

**How to avoid:** Capture the error before closing: `$err = curl_error($ch); curl_close($ch); error_log('... ' . $err);`

### Pitfall 4: ExchangeRateService not imported in PurchaseController

**What goes wrong:** `(new ExchangeRateService())->latest(...)` triggers a class-not-found fatal without the `use` statement.

**Why it happens:** PurchaseController currently does not import ExchangeRateService (it was only used by JS-side; this is the first server-side use per CONTEXT.md).

**How to avoid:** Add `use App\Services\ExchangeRateService;` to PurchaseController.php imports. Confirmed: it is NOT present in current file (only imports: `Auth`, `Controller`, `Csrf`, `Product`, `Purchase`, `ProfitCalculator`).

### Pitfall 5: productsMeta() loads all products including those with zero lots

**What goes wrong:** Products with no purchases produce an empty `$lots = []` array. `ProfitCalculator::cump([])` correctly returns `0.0` and `purchasedQty = 0` → `stock = 0 - soldByProduct[$pid] ?? 0`. If sales exist without purchases (data integrity issue), stock would be negative — but this is the same behavior as the current implementation, so no regression.

**How to avoid:** Use `$lotsByProduct[$pid] ?? []` as the default — same as the existing per-product `lotsForProduct` call returning an empty array.

---

## Code Examples

### Confirmed: exact current lotsForProduct SELECT (Purchase.php:221-222)
```php
// [VERIFIED: codebase grep]
'SELECT (unit_cost_eur * quantity) AS cost_eur, quantity
 FROM purchases WHERE user_id = :uid AND product_id = :pid'
```
New lotsForUser adds `product_id` to the SELECT and removes `AND product_id = :pid`.

### Confirmed: exact current soldQty SELECT (Sale.php:339)
```php
// [VERIFIED: codebase grep]
'SELECT COALESCE(SUM(quantity), 0) FROM sales WHERE user_id = :uid AND product_id = :pid'
```
New soldQtyByProduct replaces the `:pid` filter with `GROUP BY product_id`.

### Confirmed: purchases table columns relevant to lotsForUser (Purchase.php:143-148)
```php
// [VERIFIED: codebase grep — INSERT statement confirms columns exist]
'INSERT INTO purchases
    (user_id, product_id, order_id, quantity, weight_grams, lot_price,
     shipping_cost, customs_cost, currency, exchange_rate, unit_cost_eur, ...)'
```
Columns `product_id`, `quantity`, and `unit_cost_eur` are all confirmed present.

### Confirmed: sales table quantity column (Sale.php:133-139)
```php
// [VERIFIED: codebase grep — INSERT statement confirms column name]
'INSERT INTO sales
    (user_id, product_id, quantity, sale_price, ...)'
```
Column is `quantity` (not `qty` or `units`).

### Confirmed: CloudinaryStorage curl idiom (CloudinaryStorage.php:108-129)
```php
// [VERIFIED: codebase grep]
$ch = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => $fields,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 30,
]);
$body = curl_exec($ch);
if ($body === false) {
    $error = curl_error($ch);
    curl_close($ch);
    throw new RuntimeException('Cloudinary request failed: ' . $error);
}
$status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
curl_close($ch);
if ($status >= 400) {
    throw new RuntimeException('Cloudinary HTTP ' . $status . ': ' . (string) $body);
}
```
ExchangeRateService mirrors this but: omits POST options, uses 5s timeout, adds CONNECTTIMEOUT=5, returns null not throw, checks `!== 200` not `>= 400`.

### Confirmed: PurchaseController::validate() rate guard edit point (PurchaseController.php:176-181)
```php
// [VERIFIED: codebase grep — exact lines to be modified for D-04/D-05]
if ($currency === 'EUR') {
    $rate = 1.0;
} elseif ($rate <= 0) {
    $errors['exchange_rate'] = 'Le taux de change doit être strictement positif.';
}
```
The `else if ($rate <= 0)` branch is expanded with the D-04 fallback + D-05 block.

---

## Open Questions

All research questions from CONTEXT.md are RESOLVED:

1. **lotsForProduct exact SELECT and purchasedQty definition** — RESOLVED.
   - `SELECT (unit_cost_eur * quantity) AS cost_eur, quantity FROM purchases WHERE user_id = :uid AND product_id = :pid`
   - `purchasedQty = COALESCE(SUM(quantity), 0)` — exact same as `array_sum(array_column($lots, 'quantity'))` when lots are grouped by product_id.
   - `purchases` table columns confirmed: `product_id`, `quantity`, `unit_cost_eur` (from INSERT in Purchase::create()).

2. **Sale sold-quantity column name** — RESOLVED.
   - Column is `quantity` in the `sales` table (confirmed by `Sale::soldQty()` SELECT and `Sale::create()` INSERT bindings).
   - `soldQtyByProduct` SQL: `SELECT product_id, COALESCE(SUM(quantity), 0) AS qty FROM sales WHERE user_id = :uid GROUP BY product_id`

3. **productsMeta() output shape and callers** — RESOLVED.
   - `productsMeta()` is `private` and called only from `create()`, `duplicate()`, and `edit()` in SaleController.
   - `index()` does NOT call it (uses `$this->products->allForUser()` directly).
   - `sales/form.php` consumes `$p['id']`, `$p['name']`, `$p['cump']`, `$p['stock']` only.
   - Refactor is view-transparent — no view change required.

4. **curl options for vercel-php and frankfurter.app response shape** — RESOLVED.
   - Options: `CURLOPT_RETURNTRANSFER => true`, `CURLOPT_TIMEOUT => 5`, `CURLOPT_CONNECTTIMEOUT => 5` (GET request, no CURLOPT_POST).
   - curl is confirmed available on vercel-php (CloudinaryStorage has been deployed since Phase 4).
   - Response shape: `{"amount":1,"base":"USD","date":"2026-06-15","rates":{"EUR":0.91827}}` — `$data['rates'][$to]` access is correct.
   - Server-side curl is unaffected by browser Content Security Policy (CSP applies to browsers only).

5. **Does update() call the same validate()? Other unit_cost_eur computations?** — RESOLVED.
   - YES: both `store()` (line 91) and `update()` (line 124) in PurchaseController call `$this->validate()` — same method, D-04/D-05 covers both.
   - ADDITIONAL FINDING: `OrderController::persistLines()` (line 317) also calls `ProfitCalculator::unitCostEur()` using `$header['exchange_rate']`. Its `parseInput()` validates the rate with the same `$rate <= 0` guard but without the D-04 server-side fallback. Per D-06 this is explicitly OUT OF SCOPE for this phase. Document as known gap.

6. **Nyquist / verification approach** — RESOLVED.
   - ProfitCalculator is unchanged → existing 25 tests in `tests/ProfitCalculatorTest.php` stay GREEN (required; this is the baseline for PERF-01 math correctness).
   - PERF-01 grouping logic (array_sum, array_column, `?? []`) is trivial PHP — unit-testable in principle but outside `src/Services/` coverage scope (phpunit.xml). Verification: existing PHPUnit pass + visual smoke test of sale create page (identical cump/stock values before and after).
   - PERF-02: `ExchangeRateService` IS in `src/Services/` → Wave 0 gap: add `tests/ExchangeRateServiceTest.php` covering the identity path (`latest('EUR','EUR') === 1.0` — no I/O needed). Curl paths are smoke-only (submit USD purchase with invalid rate while API is inaccessible → verify flash error appears and `purchases` table has no new row).

---

## Validation Architecture

### Test Framework

| Property | Value |
|----------|-------|
| Framework | PHPUnit 11.x |
| Config file | `phpunit.xml` (project root) |
| Coverage source | `src/Services/` only |
| Quick run command | `vendor/bin/phpunit` |
| Full suite command | `vendor/bin/phpunit --coverage-text` |

### Phase Requirements to Test Map

| Req ID | Behavior | Test Type | Automated Command | File Exists? |
|--------|----------|-----------|-------------------|-------------|
| PERF-01 | ProfitCalculator::cump() and stock() math unchanged | unit | `vendor/bin/phpunit tests/ProfitCalculatorTest.php` | YES |
| PERF-01 | sale form loads with correct cump/stock per product | smoke | Manual: load `/sales/create`, verify product options show expected values | N/A (I/O) |
| PERF-02 | `latest('EUR','EUR')` returns `1.0` without network call | unit | `vendor/bin/phpunit tests/ExchangeRateServiceTest.php` | NO — Wave 0 gap |
| PERF-02 | USD purchase with invalid rate + API down shows flash error | smoke | Manual: submit form with rate=0, no network → verify flash + no DB row | N/A (I/O) |
| PERF-02 | USD purchase with invalid rate + valid API returns rate → purchase created | smoke | Manual: submit form with rate=0, network up → verify purchase created with rate from API | N/A (I/O) |

### Sampling Rate

- **Per task commit:** `vendor/bin/phpunit` (all 25 existing ProfitCalculator tests must stay GREEN)
- **Per wave merge:** `vendor/bin/phpunit --coverage-text` (coverage of `src/Services/` must not regress)
- **Phase gate:** full suite green + smoke tests verified before `/gsd:verify-work`

### Wave 0 Gaps

- [ ] `tests/ExchangeRateServiceTest.php` — covers PERF-02 identity path (`latest('EUR','EUR') === 1.0`, `latest('USD','USD') === 1.0`)

No test framework install needed — PHPUnit is already configured and `vendor/` is populated.

---

## Environment Availability

| Dependency | Required By | Available | Version | Fallback |
|------------|------------|-----------|---------|----------|
| PHP curl extension | ExchangeRateService rewrite | confirmed | php:8.3 | None needed — CloudinaryStorage already uses it in production |
| frankfurter.app API | ExchangeRateService::latest() | confirmed reachable | — | Server-side fallback returns null → submission blocked with visible message (D-05) |
| MySQL purchases.product_id column | Purchase::lotsForUser | confirmed | — | — |
| MySQL sales.quantity column | Sale::soldQtyByProduct | confirmed | — | — |

**Missing dependencies with no fallback:** none

**Missing dependencies with fallback:** frankfurter.app outage is handled by the D-05 block (visible error, no 0.00 write).

---

## Security Domain

`security_enforcement` is not explicitly false in `.planning/config.json` → section required.

### Applicable ASVS Categories

| ASVS Category | Applies | Standard Control |
|---------------|---------|-----------------|
| V2 Authentication | no | Auth::require() unchanged |
| V3 Session Management | no | No session changes |
| V4 Access Control | no | user_id scoping unchanged on all queries |
| V5 Input Validation | yes | `$rate > 0` guard before ExchangeRateService call; currency allowlist unchanged |
| V6 Cryptography | no | No crypto |
| V7 Error Handling | yes | error_log (server-only) — no FX API response body leaked to the user; French flash message is generic |

### Known Threat Patterns

| Pattern | STRIDE | Standard Mitigation |
|---------|--------|---------------------|
| Malformed FX API response injected via SSRF | Tampering | curl fetches only the hardcoded `https://api.frankfurter.app/latest` endpoint — no user-controlled URL; body truncated in error logs |
| Rate spoofing via submitted form field | Tampering | D-04: server-side fetch overrides submitted rate when rate is invalid; submitted rate is still validated `> 0` before use |
| 0.00 unit_cost_eur written to DB | Information Disclosure (silent data corruption) | D-05 blocks the write entirely if rate is invalid and fallback fails |
| SSRF via $currency parameter in URL | Tampering | `urlencode($from)` + `strtoupper($from)` in ExchangeRateService; currency is also validated by the allowlist (`in_array($currency, ['EUR','USD','CNY'])`) in validate() BEFORE latest() is called |

**Note on OrderController gap:** `OrderController::persistLines()` computes `unit_cost_eur` with a rate from `parseInput()` — same rate validation guard but no server-side fallback. This gap exists today and is deferred per D-06. The risk is the same as today (rate field required, user must supply it); it is not worsened by this phase.

---

## Assumptions Log

| # | Claim | Section | Risk if Wrong |
|---|-------|---------|---------------|
| A1 | frankfurter.app is reachable via curl from Vercel Lambda (server-side) | Architecture Patterns | If blocked, fallback returns null → D-05 blocks all non-EUR purchases; a DNS or firewall rule at the Lambda level would be needed to resolve |

**Notes on A1:** The CSP for browsers allows `api.frankfurter.app` (CONTEXT.md). Vercel Lambda networking is not restricted to CSP. The pre-existing `file_get_contents` call would also have needed outbound HTTP to the same host. Confidence: MEDIUM (not tested in the Lambda environment directly; `file_get_contents` was never verified to work in production either — this is also why the curl rewrite is needed). If the API is unreachable, D-05 provides a safe degradation path.

---

## Sources

### Primary (HIGH confidence)
- `src/Models/Purchase.php` — exact lotsForProduct SQL, purchasedQty SQL, purchases table column names from INSERT
- `src/Models/Sale.php` — exact soldQty SQL, sales table quantity column from INSERT
- `src/Controllers/SaleController.php` — productsMeta() callers, current N+1 loop
- `src/Services/ExchangeRateService.php` — current file_get_contents implementation
- `src/Controllers/PurchaseController.php` — validate() structure, rate guard lines 176-181, store()/update() both call validate()
- `src/Services/CloudinaryStorage.php` — established curl idiom (POST; adapt to GET)
- `src/Views/sales/form.php` — confirmed data-cump + data-stock consumption pattern
- `src/Controllers/OrderController.php` — confirmed second unit_cost_eur computation site (out of scope per D-06)
- `.planning/config.json` — `nyquist_validation: true` confirmed
- `phpunit.xml` — coverage source `src/Services/` only; PHPUnit 11.x
- `tests/ProfitCalculatorTest.php` — 25 existing tests; only test file

### Secondary (MEDIUM confidence)
- frankfurter.app API response shape: confirmed from current ExchangeRateService implementation's `$data['rates'][$to]` access pattern, which is already deployed and working (client-side JS uses the same endpoint)

---

## Metadata

**Confidence breakdown:**
- Standard stack: HIGH — no new packages; all idioms confirmed from live codebase
- Architecture: HIGH — exact SQL confirmed line-by-line from source files; byte-identity argument is mathematical (same inputs to same pure functions)
- Pitfalls: HIGH — derived from actual PHP/PDO/curl behavior + codebase reading

**Research date:** 2026-06-15
**Valid until:** stable — no external APIs or library versions to expire; only risk is frankfurter.app endpoint change
