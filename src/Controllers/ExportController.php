<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\Sale;
use App\Services\CsvExporter;
use App\Services\ProfitCalculator;

final class ExportController extends Controller
{
    private CsvExporter $csv;

    public function __construct()
    {
        Auth::require();
        $this->csv = new CsvExporter();
    }

    public function purchases(): void
    {
        $rows = (new Purchase())->allForUser(Auth::id());
        $header = ['Date', 'Produit', 'Quantite', 'Poids unitaire (g)', 'Commande', 'Prix lot', 'Port', 'Douane', 'Devise', 'Taux', 'Cout unitaire EUR', 'Fournisseur', 'URL'];
        $data = [];
        foreach ($rows as $r) {
            $data[] = [
                $r['purchased_at'],
                $r['product_name'],
                (int) $r['quantity'],
                isset($r['weight_grams']) && $r['weight_grams'] !== null ? (int) $r['weight_grams'] : '',
                !empty($r['order_id']) ? '#' . (int) $r['order_id'] : '',
                $this->dec($r['lot_price']),
                $this->dec($r['shipping_cost']),
                $this->dec($r['customs_cost']),
                $r['currency'],
                $this->dec($r['exchange_rate'], 6),
                $this->dec($r['unit_cost_eur'], 4),
                $r['supplier'] ?? '',
                $r['order_url'] ?? '',
            ];
        }
        $this->csv->download('achats.csv', $this->csv->build($header, $data));
    }

    public function sales(): void
    {
        $rows = (new Sale())->allForUser(Auth::id());
        $header = ['Date', 'Produit', 'Quantite', 'Prix vente', 'Emballage', 'Etiquette', 'Boost', 'Autres', 'CUMP fige', 'Marge nette EUR', 'Marge %', 'Plateforme'];
        $data = [];
        foreach ($rows as $r) {
            $pct = ProfitCalculator::marginPercent((float) $r['net_margin_eur'], (float) $r['sale_price']);
            $data[] = [
                $r['sold_at'],
                $r['product_name'],
                (int) $r['quantity'],
                $this->dec($r['sale_price']),
                $this->dec($r['packaging_cost']),
                $this->dec($r['label_cost']),
                $this->dec($r['boost_cost']),
                $this->dec($r['other_cost']),
                $this->dec($r['unit_cost_eur'], 4),
                $this->dec($r['net_margin_eur']),
                $this->dec($pct),
                $r['platform'],
            ];
        }
        $this->csv->download('ventes.csv', $this->csv->build($header, $data));
    }

    public function products(): void
    {
        $userId = Auth::id();
        $products = (new Product())->allForUser($userId);
        $stats = (new Product())->statsForUser($userId);

        $header = ['Produit', 'Categorie', 'Qte achetee', 'Qte vendue', 'Stock', 'CUMP EUR', 'Valeur stock EUR', 'Benefice cumule EUR'];
        $data = [];
        foreach ($products as $p) {
            $s = $stats[(int) $p['id']] ?? ['purchased_qty' => 0, 'total_cost_eur' => 0.0, 'sold_qty' => 0, 'profit_eur' => 0.0];
            $cump = ProfitCalculator::cump([['cost_eur' => $s['total_cost_eur'], 'quantity' => $s['purchased_qty']]]);
            $stock = ProfitCalculator::stock($s['purchased_qty'], $s['sold_qty']);
            $data[] = [
                $p['name'],
                $p['category'] ?? '',
                (int) $s['purchased_qty'],
                (int) $s['sold_qty'],
                (int) $stock,
                $this->dec($cump, 4),
                $this->dec(ProfitCalculator::stockValue($stock, $cump)),
                $this->dec($s['profit_eur']),
            ];
        }
        $this->csv->download('recap_produits.csv', $this->csv->build($header, $data));
    }

    /** Format a number with a comma decimal separator for Excel FR. */
    private function dec(float|string|null $value, int $decimals = 2): string
    {
        return number_format((float) $value, $decimals, ',', '');
    }
}
