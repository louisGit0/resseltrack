<?php
declare(strict_types=1);

namespace Tests;

use App\Services\ProfitCalculator;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the only place business rules live: ProfitCalculator.
 */
final class ProfitCalculatorTest extends TestCase
{
    // ---- Unit cost (EUR) --------------------------------------------------
    public function testUnitCostInEuroNoConversion(): void
    {
        // (30 + 5 + 0) * 1 / 20 = 1.75
        $this->assertSame(1.75, ProfitCalculator::unitCostEur(30.00, 5.00, 0.00, 1.0, 20));
    }

    public function testUnitCostInUsdWithRateShippingAndCustoms(): void
    {
        // (40 + 8 + 5) * 0.92 / 10 = 4.876
        $this->assertSame(4.876, ProfitCalculator::unitCostEur(40.00, 8.00, 5.00, 0.92, 10));
    }

    public function testUnitCostRoundsToFourDecimals(): void
    {
        // (10 + 0 + 0) * 1 / 3 = 3.3333...
        $this->assertSame(3.3333, ProfitCalculator::unitCostEur(10.00, 0.0, 0.0, 1.0, 3));
    }

    public function testUnitCostRejectsNonPositiveQuantity(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        ProfitCalculator::unitCostEur(10.0, 0.0, 0.0, 1.0, 0);
    }

    // ---- CUMP (weighted average) -----------------------------------------
    public function testCumpAcrossMultipleLots(): void
    {
        // Lot A: 20 units, total 35 EUR ; Lot B: 10 units, total 22 EUR
        // (35 + 22) / (20 + 10) = 57 / 30 = 1.9
        $lots = [
            ['cost_eur' => 35.00, 'quantity' => 20],
            ['cost_eur' => 22.00, 'quantity' => 10],
        ];
        $this->assertSame(1.9, ProfitCalculator::cump($lots));
    }

    public function testCumpSingleLot(): void
    {
        $lots = [['cost_eur' => 48.76, 'quantity' => 10]];
        $this->assertSame(4.876, ProfitCalculator::cump($lots));
    }

    public function testCumpEmptyLotsIsZero(): void
    {
        $this->assertSame(0.0, ProfitCalculator::cump([]));
    }

    // ---- Net margin -------------------------------------------------------
    public function testNetMarginWithAllFees(): void
    {
        // (15 - 0.50 - 0.99 - 0 - 0) - (1.9 * 2) = 13.51 - 3.80 = 9.71
        $this->assertSame(9.71, ProfitCalculator::netMargin(15.00, 0.50, 0.99, 0.00, 0.00, 1.9, 2));
    }

    public function testNetMarginCanBeNegative(): void
    {
        // (5 - 1 - 1 - 1 - 0) - (1.9 * 2) = 2 - 3.8 = -1.8
        $this->assertSame(-1.8, ProfitCalculator::netMargin(5.00, 1.00, 1.00, 1.00, 0.00, 1.9, 2));
    }

    public function testMarginPercent(): void
    {
        // 9.71 / 15.00 * 100 = 64.733... -> 64.73
        $this->assertSame(64.73, ProfitCalculator::marginPercent(9.71, 15.00));
    }

    public function testMarginPercentZeroSalePriceIsZero(): void
    {
        $this->assertSame(0.0, ProfitCalculator::marginPercent(5.0, 0.0));
    }

    // ---- Stock ------------------------------------------------------------
    public function testStockIsPurchasedMinusSold(): void
    {
        $this->assertSame(26, ProfitCalculator::stock(30, 4));
    }

    public function testCanFulfillWhenStockSufficient(): void
    {
        $this->assertTrue(ProfitCalculator::canFulfill(3, 7));
        $this->assertTrue(ProfitCalculator::canFulfill(7, 7));
    }

    public function testCannotFulfillWhenStockInsufficient(): void
    {
        $this->assertFalse(ProfitCalculator::canFulfill(8, 7));
    }

    public function testCannotFulfillWithNonPositiveQuantity(): void
    {
        $this->assertFalse(ProfitCalculator::canFulfill(0, 7));
    }

    // ---- Stock value ------------------------------------------------------
    public function testStockValue(): void
    {
        // 26 * 1.9 = 49.4
        $this->assertSame(49.4, ProfitCalculator::stockValue(26, 1.9));
    }

    // ---- ROI ----------------------------------------------------------------
    public function testRoi(): void
    {
        // 48.35 / 38.45 * 100 = 125.7477... -> 125.75
        $this->assertSame(125.75, ProfitCalculator::roi(48.35, 38.45));
    }

    public function testRoiCanBeNegative(): void
    {
        // -5 / 20 * 100 = -25
        $this->assertSame(-25.0, ProfitCalculator::roi(-5.0, 20.0));
    }

    public function testRoiZeroCostIsZero(): void
    {
        $this->assertSame(0.0, ProfitCalculator::roi(10.0, 0.0));
    }

    // ---- Shipping allocation (orders) --------------------------------------
    public function testAllocateByWeightProportional(): void
    {
        // 100 g vs 300 g -> 25 % / 75 % of 10 EUR
        $lines = [
            ['weight' => 100.0, 'price' => 5.0],
            ['weight' => 300.0, 'price' => 5.0],
        ];
        $this->assertSame([2.5, 7.5], ProfitCalculator::allocateByWeight($lines, 10.0));
    }

    public function testAllocateByWeightSumIsExactDespiteRounding(): void
    {
        // 3 equal weights on 10 EUR -> 3.33 + 3.33 + 3.34 = 10.00 exactly
        $lines = [
            ['weight' => 1.0, 'price' => 0.0],
            ['weight' => 1.0, 'price' => 0.0],
            ['weight' => 1.0, 'price' => 0.0],
        ];
        $alloc = ProfitCalculator::allocateByWeight($lines, 10.0);
        $this->assertSame([3.33, 3.33, 3.34], $alloc);
        $this->assertSame(10.0, round(array_sum($alloc), 2));
    }

    public function testAllocateFallsBackToPriceWhenNoWeights(): void
    {
        // No weights -> allocate on prices 20/80
        $lines = [
            ['weight' => 0.0, 'price' => 10.0],
            ['weight' => 0.0, 'price' => 40.0],
        ];
        $this->assertSame([2.0, 8.0], ProfitCalculator::allocateByWeight($lines, 10.0));
    }

    public function testAllocateFallsBackToEqualSplitWhenNoWeightsNorPrices(): void
    {
        $lines = [
            ['weight' => 0.0, 'price' => 0.0],
            ['weight' => 0.0, 'price' => 0.0],
        ];
        $this->assertSame([5.0, 5.0], ProfitCalculator::allocateByWeight($lines, 10.0));
    }

    public function testAllocateZeroAmountAndEmptyLines(): void
    {
        $this->assertSame([0.0, 0.0], ProfitCalculator::allocateByWeight(
            [['weight' => 1.0, 'price' => 0.0], ['weight' => 2.0, 'price' => 0.0]],
            0.0
        ));
        $this->assertSame([], ProfitCalculator::allocateByWeight([], 10.0));
    }
}
