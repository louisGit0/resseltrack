<?php
declare(strict_types=1);

namespace App\Services;

/**
 * All profitability business rules live here and ONLY here, so they can be
 * unit-tested in isolation. No database access, no I/O — pure functions.
 *
 * Money rounding conventions:
 *   - unit cost / CUMP : 4 decimals (matches DECIMAL(10,4) columns)
 *   - margins / totals : 2 decimals (matches DECIMAL(10,2) columns)
 */
final class ProfitCalculator
{
    public const COST_SCALE = 4;
    public const MONEY_SCALE = 2;

    /**
     * Total cost of a lot expressed in EUR.
     * (lot_price + shipping + customs) * exchange_rate
     */
    public static function lotCostEur(
        float $lotPrice,
        float $shippingCost,
        float $customsCost,
        float $exchangeRate
    ): float {
        $total = ($lotPrice + $shippingCost + $customsCost) * $exchangeRate;
        return round($total, self::MONEY_SCALE);
    }

    /**
     * Unit cost of a purchased lot, in EUR:
     *   (lot_price + shipping + customs) * exchange_rate / quantity
     *
     * @throws \InvalidArgumentException if quantity <= 0
     */
    public static function unitCostEur(
        float $lotPrice,
        float $shippingCost,
        float $customsCost,
        float $exchangeRate,
        int $quantity
    ): float {
        if ($quantity <= 0) {
            throw new \InvalidArgumentException('Quantity must be a positive integer.');
        }
        $unit = (($lotPrice + $shippingCost + $customsCost) * $exchangeRate) / $quantity;
        return round($unit, self::COST_SCALE);
    }

    /**
     * Weighted average cost (CUMP) of a product across several lots.
     *   sum(lot_cost_eur) / sum(quantity_purchased)
     *
     * @param array<int, array{cost_eur: float, quantity: int}> $lots
     */
    public static function cump(array $lots): float
    {
        $totalCost = 0.0;
        $totalQty = 0;
        foreach ($lots as $lot) {
            $totalCost += (float) $lot['cost_eur'];
            $totalQty += (int) $lot['quantity'];
        }
        if ($totalQty <= 0) {
            return 0.0;
        }
        return round($totalCost / $totalQty, self::COST_SCALE);
    }

    /**
     * Net margin of a sale, in EUR:
     *   (sale_price - packaging - label - boost - other) - (CUMP * quantity)
     */
    public static function netMargin(
        float $salePrice,
        float $packaging,
        float $label,
        float $boost,
        float $other,
        float $cump,
        int $quantity
    ): float {
        $netRevenue = $salePrice - $packaging - $label - $boost - $other;
        $cost = $cump * $quantity;
        return round($netRevenue - $cost, self::MONEY_SCALE);
    }

    /**
     * Net margin as a percentage of the sale price.
     * Returns 0.0 when sale_price is 0 to avoid division by zero.
     */
    public static function marginPercent(float $netMargin, float $salePrice): float
    {
        if ($salePrice == 0.0) {
            return 0.0;
        }
        return round(($netMargin / $salePrice) * 100, self::MONEY_SCALE);
    }

    /** Available stock = purchased units - sold units. */
    public static function stock(int $purchasedQty, int $soldQty): int
    {
        return $purchasedQty - $soldQty;
    }

    /** Can we sell `requested` units given `available` in stock? */
    public static function canFulfill(int $requested, int $available): bool
    {
        return $requested > 0 && $requested <= $available;
    }

    /** Value of remaining stock = stock * CUMP. */
    public static function stockValue(int $stock, float $cump): float
    {
        return round($stock * $cump, self::MONEY_SCALE);
    }

    /**
     * Return on investment, in %: profit / invested cost * 100.
     * Returns 0.0 when cost is 0 (nothing invested yet).
     */
    public static function roi(float $profit, float $cost): float
    {
        if ($cost == 0.0) {
            return 0.0;
        }
        return round(($profit / $cost) * 100, self::MONEY_SCALE);
    }

    /**
     * Split a global cost (shipping, customs) across order lines,
     * proportionally to each line's weight.
     *
     * Fallbacks: if no line has a weight, allocate proportionally to line
     * price; if prices are also all zero, split equally.
     * The rounded parts always sum EXACTLY to $amount (residual goes to the
     * last line).
     *
     * @param array<int, array{weight: float, price: float}> $lines
     * @return float[] allocated amount per line, same order as input
     */
    public static function allocateByWeight(array $lines, float $amount): array
    {
        $n = count($lines);
        if ($n === 0) {
            return [];
        }
        if ($amount == 0.0) {
            return array_fill(0, $n, 0.0);
        }

        $weights = array_map(static fn(array $l): float => max(0.0, (float) $l['weight']), $lines);
        $total = array_sum($weights);
        if ($total <= 0) {
            $weights = array_map(static fn(array $l): float => max(0.0, (float) $l['price']), $lines);
            $total = array_sum($weights);
        }
        if ($total <= 0) {
            $weights = array_fill(0, $n, 1.0);
            $total = (float) $n;
        }

        $alloc = [];
        $allocated = 0.0;
        foreach ($weights as $i => $w) {
            if ($i === $n - 1) {
                // Last line absorbs the rounding residual so the sum is exact.
                $alloc[$i] = round($amount - $allocated, self::MONEY_SCALE);
            } else {
                $alloc[$i] = round($amount * $w / $total, self::MONEY_SCALE);
                $allocated += $alloc[$i];
            }
        }
        return $alloc;
    }
}
