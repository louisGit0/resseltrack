# Testing Patterns

**Analysis Date:** 2026-06-12

## Test Framework

**Runner:**
- PHPUnit 11.x (`composer.json` line 9: `"phpunit/phpunit": "^11.0"`)
- Config: `phpunit.xml` at project root
- Bootstrap: `vendor/autoload.php`
- Colors enabled; `failOnWarning="true"` (warnings fail the suite)

**Assertion Library:**
- PHPUnit built-in assertions (`assertSame`, `assertTrue`, `assertFalse`, `expectException`)

**Run Commands:**
```bash
composer test              # runs phpunit (defined in composer.json scripts)
vendor/bin/phpunit         # direct invocation
vendor/bin/phpunit --coverage-text  # coverage report (requires Xdebug or pcov)
```

## Test File Organization

**Location:**
- All tests live in `tests/` at the project root
- Currently a flat directory (no subdirectories)

**Naming:**
- `{ClassName}Test.php` pattern — example: `ProfitCalculatorTest.php`
- Test class names match: `class ProfitCalculatorTest extends TestCase`
- Namespace: `Tests` (PSR-4 mapped in `composer.json`)

**Current structure:**
```
tests/
└── ProfitCalculatorTest.php    # 184 lines, 22 test methods
```

## Coverage Configuration

**Source coverage scope (phpunit.xml lines 10-14):**
```xml
<source>
    <include>
        <directory suffix=".php">src/Services</directory>
    </include>
</source>
```
Coverage tracking is scoped to `src/Services/` only. Controllers, Models, Core, and helpers are **explicitly excluded** from the coverage report. This is by design: only the pure-PHP service layer (`ProfitCalculator`, `CsvExporter`, `ExchangeRateService`) is considered unit-testable in isolation.

## What Is Tested

**`tests/ProfitCalculatorTest.php` — 22 tests across 8 groups:**

| Group | Methods Tested | Test Count |
|-------|---------------|-----------|
| Unit cost (EUR) | `unitCostEur()` | 4 |
| CUMP (weighted average) | `cump()` | 3 |
| Net margin | `netMargin()`, `marginPercent()` | 4 |
| Stock | `stock()`, `canFulfill()` | 4 |
| Stock value | `stockValue()` | 1 |
| ROI | `roi()` | 3 |
| Shipping allocation | `allocateByWeight()` | 4 |
| Exception cases | `unitCostEur()` with invalid arg | 1 |

**What is NOT tested:**
- Controllers (`src/Controllers/`) — no HTTP test layer exists
- Models (`src/Models/`) — no database integration tests
- Core classes (`Router`, `Auth`, `Csrf`, `Database`, `RateLimiter`) — no tests
- View helpers (`src/helpers.php`) — no tests
- `CsvExporter` — not tested despite being in the service layer
- `ExchangeRateService` — not tested (external HTTP call)

## Test Structure

**Suite organization (`phpunit.xml`):**
```xml
<testsuites>
    <testsuite name="ResellTrack unit tests">
        <directory>tests</directory>
    </testsuite>
</testsuites>
```
Single suite, scans the `tests/` directory recursively.

**Class pattern:**
```php
declare(strict_types=1);

namespace Tests;

use App\Services\ProfitCalculator;
use PHPUnit\Framework\TestCase;

final class ProfitCalculatorTest extends TestCase
{
    // methods grouped by feature with comment banners
}
```

**Test method naming:**
- Pattern: `test{WhatItDoes}()` — descriptive, sentence-like
- Examples:
  - `testUnitCostInEuroNoConversion()`
  - `testCumpAcrossMultipleLots()`
  - `testNetMarginCanBeNegative()`
  - `testAllocateByWeightSumIsExactDespiteRounding()`
  - `testRoiZeroCostIsZero()`

**Group sections** delimited by comment banners:
```php
// ---- Unit cost (EUR) --------------------------------------------------
// ---- CUMP (weighted average) -----------------------------------------
// ---- Net margin -------------------------------------------------------
// ---- Stock ------------------------------------------------------------
```

## Test Patterns

**Typical assertion style — direct value comparison with `assertSame`:**
```php
public function testUnitCostInEuroNoConversion(): void
{
    // (30 + 5 + 0) * 1 / 20 = 1.75
    $this->assertSame(1.75, ProfitCalculator::unitCostEur(30.00, 5.00, 0.00, 1.0, 20));
}
```

- `assertSame` is preferred over `assertEquals` (strict type checking)
- Inline comment above each test showing the expected arithmetic
- No Arrange/Act/Assert separation — tests are intentionally compact since methods are pure functions

**Exception testing:**
```php
public function testUnitCostRejectsNonPositiveQuantity(): void
{
    $this->expectException(\InvalidArgumentException::class);
    ProfitCalculator::unitCostEur(10.0, 0.0, 0.0, 1.0, 0);
}
```

**Edge case coverage:**
- Zero inputs (`testCumpEmptyLotsIsZero`, `testRoiZeroCostIsZero`, `testMarginPercentZeroSalePriceIsZero`)
- Negative results (`testNetMarginCanBeNegative`, `testRoiCanBeNegative`)
- Rounding precision (`testUnitCostRoundsToFourDecimals`, `testAllocateByWeightSumIsExactDespiteRounding`)
- Fallback behavior (`testAllocateFallsBackToPriceWhenNoWeights`, `testAllocateFallsBackToEqualSplitWhenNoWeightsNorPrices`)
- Boundary checks (`testCanFulfillWhenStockSufficient`, `testCannotFulfillWhenStockInsufficient`)

## Mocking

**No mocking framework used.** All tested code (`ProfitCalculator`) is a stateless final class of pure static methods. No dependencies, no I/O, no mocking needed.

## Fixtures and Factories

**No fixtures or factories.** Test data is inline per test method.

Multi-lot data uses inline arrays:
```php
$lots = [
    ['cost_eur' => 35.00, 'quantity' => 20],
    ['cost_eur' => 22.00, 'quantity' => 10],
];
```

Multi-line data for allocation tests:
```php
$lines = [
    ['weight' => 100.0, 'price' => 5.0],
    ['weight' => 300.0, 'price' => 5.0],
];
```

## Database Handling in Tests

**No database interaction in tests.** The PHPUnit bootstrap is `vendor/autoload.php` only — no database seeding, no test database configuration. Integration tests that require the database do not exist.

Controllers and models that depend on `Database::connection()` are not tested. This is an explicit gap.

## Coverage

**Requirements:** Not enforced via `<coverage>` or `<minimum>` directives in `phpunit.xml`

**Actual coverage:** `ProfitCalculator` is fully covered (all methods, all branches including edge cases). Coverage for the rest of the codebase is 0% by scope exclusion.

**View coverage:**
```bash
vendor/bin/phpunit --coverage-text
```
(Requires Xdebug or pcov driver.)

## Test Types

**Unit Tests:**
- Scope: `ProfitCalculator` pure business logic only
- No framework, session, database, or HTTP required
- Fast: all 22 tests run in milliseconds

**Integration Tests:**
- None present

**E2E Tests:**
- None present

## Where to Add New Tests

**New service methods:** Add to the existing `tests/ProfitCalculatorTest.php` following the existing section-banner pattern, or create `tests/{ServiceName}Test.php` for new services.

**New controller/model tests:** Would require either:
1. A database integration test setup (test database, fixtures, teardown)
2. Refactoring models to accept a PDO mock in the constructor instead of calling `Database::connection()` directly

**Recommended test file location for new services:**
- `tests/{ClassName}Test.php`

**Adding tests for `CsvExporter`** (no I/O — just string building) would be straightforward with the same pattern used for `ProfitCalculator`.

---

*Testing analysis: 2026-06-12*
