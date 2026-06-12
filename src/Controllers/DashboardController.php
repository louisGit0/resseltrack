<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Models\Product;
use App\Models\Sale;
use App\Services\ProfitCalculator;

final class DashboardController extends Controller
{
    public function __construct()
    {
        Auth::require();
    }

    public function index(): void
    {
        $userId = Auth::id();
        $saleModel = new Sale();
        $productModel = new Product();

        // Period filter (both optional).
        $from = $this->validDateOrNull($_GET['from'] ?? null);
        $to = $this->validDateOrNull($_GET['to'] ?? null);

        $summary = $saleModel->summary($userId, $from, $to);
        $revenue = $summary['revenue'];
        $profit = $summary['profit'];
        $count = $summary['count'];

        $avgMarginEur = $count > 0 ? round($profit / $count, 2) : 0.0;
        $avgMarginPct = ProfitCalculator::marginPercent($profit, $revenue);

        // Advanced stats (period-aware).
        $roi = ProfitCalculator::roi($profit, $summary['cost']);
        $avgDays = $saleModel->avgDaysToSell($userId, $from, $to);
        $avgBasket = $count > 0 ? round($revenue / $count, 2) : 0.0;

        // Profit by category: positive slices feed the doughnut, negative
        // ones are listed separately under the chart.
        $byCategory = $saleModel->profitByCategory($userId, $from, $to);
        $catPositive = array_values(array_filter($byCategory, static fn($c) => $c['profit'] > 0));
        $catNegative = array_values(array_filter($byCategory, static fn($c) => $c['profit'] < 0));
        $categoryChart = [
            'labels' => array_map(static fn($c) => $c['category'], $catPositive),
            'values' => array_map(static fn($c) => round($c['profit'], 2), $catPositive),
        ];

        // Stock value is "now" (not period-dependent): sum(stock * CUMP).
        // Also collect low-stock products (<= 2 units left but had purchases).
        $stockValue = 0.0;
        $stockUnits = 0;
        $lowStock = [];
        $stats = $productModel->statsForUser($userId);
        $productNames = [];
        foreach ($productModel->allForUser($userId) as $p) {
            $productNames[(int) $p['id']] = $p['name'];
        }
        foreach ($stats as $pid => $s) {
            $cump = ProfitCalculator::cump([
                ['cost_eur' => $s['total_cost_eur'], 'quantity' => $s['purchased_qty']],
            ]);
            $stock = ProfitCalculator::stock($s['purchased_qty'], $s['sold_qty']);
            if ($stock > 0) {
                $stockUnits += $stock;
                $stockValue += ProfitCalculator::stockValue($stock, $cump);
            }
            if ($s['purchased_qty'] > 0 && $stock <= 2 && isset($productNames[$pid])) {
                $lowStock[] = ['name' => $productNames[$pid], 'stock' => $stock];
            }
        }

        $topProducts = $saleModel->topProductsByProfit($userId, $from, $to, 5);

        // 12-month chart (independent of the period filter).
        $monthly = $saleModel->monthlySeries($userId, 12);
        $chart = [
            'labels'  => array_map(static fn($m) => $m['ym'], $monthly),
            'revenue' => array_map(static fn($m) => round($m['revenue'], 2), $monthly),
            'profit'  => array_map(static fn($m) => round($m['profit'], 2), $monthly),
        ];

        $this->view('dashboard/index', [
            'revenue'       => $revenue,
            'profit'        => $profit,
            'count'         => $count,
            'avgMarginEur'  => $avgMarginEur,
            'avgMarginPct'  => $avgMarginPct,
            'stockValue'    => round($stockValue, 2),
            'stockUnits'    => $stockUnits,
            'topProducts'   => $topProducts,
            'recentSales'   => $saleModel->recentForUser($userId, 6),
            'lowStock'      => $lowStock,
            'roi'           => $roi,
            'avgDays'       => $avgDays,
            'avgBasket'     => $avgBasket,
            'catNegative'   => $catNegative,
            'chartJson'     => json_encode($chart, JSON_UNESCAPED_UNICODE),
            'categoryJson'  => json_encode($categoryChart, JSON_UNESCAPED_UNICODE),
            'from'          => $from,
            'to'            => $to,
        ], 'Tableau de bord');
    }

    private function validDateOrNull(?string $date): ?string
    {
        if ($date === null || $date === '') {
            return null;
        }
        $d = \DateTimeImmutable::createFromFormat('Y-m-d', $date);
        return ($d !== false && $d->format('Y-m-d') === $date) ? $date : null;
    }
}
