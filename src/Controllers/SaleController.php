<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Csrf;
use App\Core\Database;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\Sale;
use App\Services\ProfitCalculator;

final class SaleController extends Controller
{
    private Sale $sales;
    private Purchase $purchases;
    private Product $products;

    public function __construct()
    {
        Auth::require();
        $this->sales = new Sale();
        $this->purchases = new Purchase();
        $this->products = new Product();
    }

    private const PER_PAGE = 15;

    public function index(): void
    {
        $q = trim((string) ($_GET['q'] ?? ''));
        $sort = (string) ($_GET['sort'] ?? 'date');
        if (!in_array($sort, ['date', 'product', 'quantity', 'price', 'margin'], true)) {
            $sort = 'date';
        }
        // Direction implied by the metric (A→Z for product, biggest/newest first otherwise).
        $dir = $sort === 'product' ? 'asc' : 'desc';
        $page = max(1, (int) ($_GET['page'] ?? 1));
        $productId = (int) ($_GET['product_id'] ?? 0);
        $from = $this->dateOrNull((string) ($_GET['from'] ?? ''));
        $to = $this->dateOrNull((string) ($_GET['to'] ?? ''));

        $result = $this->sales->searchForUser(Auth::id(), [
            'q'          => $q,
            'sort'       => $sort,
            'dir'        => $dir,
            'product_id' => $productId ?: null,
            'from'       => $from,
            'to'         => $to,
        ], $page, self::PER_PAGE);
        $pages = max(1, (int) ceil($result['total'] / self::PER_PAGE));

        $sales = $result['rows'];
        // Decorate each row with its margin %.
        foreach ($sales as &$s) {
            $s['margin_pct'] = ProfitCalculator::marginPercent((float) $s['net_margin_eur'], (float) $s['sale_price']);
        }
        unset($s);

        $this->view('sales/index', [
            'sales'     => $sales,
            'total'     => $result['total'],
            'units'     => $result['units'],
            'revenue'   => $result['revenue'],
            'margin'    => $result['margin'],
            'marginPct' => ProfitCalculator::marginPercent($result['margin'], $result['revenue']),
            'products'  => $this->products->allForUser(Auth::id()),
            'q'         => $q,
            'sort'      => $sort,
            'dir'       => $dir,
            'page'      => min($page, $pages),
            'pages'     => $pages,
            'productId' => $productId,
            'from'      => $from,
            'to'        => $to,
        ], 'Ventes');
    }

    private function dateOrNull(string $date): ?string
    {
        return $date !== '' && $this->isValidDate($date) ? $date : null;
    }

    public function create(): void
    {
        [$errors, $old] = $this->pullErrors();
        // Pre-select the product when coming from a product detail page.
        if (empty($old['product_id']) && isset($_GET['product_id'])) {
            $old['product_id'] = (int) $_GET['product_id'];
        }
        $this->view('sales/form', [
            'sale'      => null,
            'products'  => $this->productsMeta(),
            'errors'    => $errors,
            'old'       => $old,
        ], 'Nouvelle vente');
    }

    public function store(): void
    {
        Csrf::validate();

        // Stock check + insert inside one transaction (rows locked with
        // FOR UPDATE) so two concurrent sales cannot oversell the stock.
        $db = Database::connection();
        $db->beginTransaction();
        try {
            $data = $this->validate(null, lock: true);
            if ($data === null) {
                $db->rollBack();
                $this->redirect('/sales/create');
            }
            $this->sales->create(Auth::id(), $data);
            $db->commit();
        } catch (\Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }
        $this->flash('success', 'Vente enregistrée.');
        $this->redirect('/sales');
    }

    /** Pre-fill the create form with an existing sale's values (date = today). */
    public function duplicate(array $params): void
    {
        $sale = $this->sales->find((int) $params['id'], Auth::id());
        if ($sale === null) {
            $this->flash('danger', 'Vente introuvable.');
            $this->redirect('/sales');
        }

        $old = [
            'product_id'     => $sale['product_id'],
            'quantity'       => $sale['quantity'],
            'sale_price'     => $sale['sale_price'],
            'packaging_cost' => $sale['packaging_cost'],
            'label_cost'     => $sale['label_cost'],
            'boost_cost'     => $sale['boost_cost'],
            'other_cost'     => $sale['other_cost'],
            'platform'       => $sale['platform'],
            'sold_at'        => date('Y-m-d'),
        ];

        $this->flash('info', 'Vente dupliquée : vérifiez les valeurs puis enregistrez.');
        $this->view('sales/form', [
            'sale'     => null,
            'products' => $this->productsMeta(),
            'errors'   => [],
            'old'      => $old,
        ], 'Nouvelle vente');
    }

    public function edit(array $params): void
    {
        $sale = $this->sales->find((int) $params['id'], Auth::id());
        if ($sale === null) {
            $this->flash('danger', 'Vente introuvable.');
            $this->redirect('/sales');
        }
        [$errors, $old] = $this->pullErrors();
        $this->view('sales/form', [
            'sale'     => $sale,
            'products' => $this->productsMeta(),
            'errors'   => $errors,
            'old'      => $old,
        ], 'Modifier la vente');
    }

    public function update(array $params): void
    {
        Csrf::validate();
        $id = (int) $params['id'];
        if ($this->sales->find($id, Auth::id()) === null) {
            $this->flash('danger', 'Vente introuvable.');
            $this->redirect('/sales');
        }
        $db = Database::connection();
        $db->beginTransaction();
        try {
            $data = $this->validate($id, lock: true);
            if ($data === null) {
                $db->rollBack();
                $this->redirect('/sales/' . $id . '/edit');
            }
            $this->sales->update($id, Auth::id(), $data);
            $db->commit();
        } catch (\Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }
        $this->flash('success', 'Vente mise à jour.');
        $this->redirect('/sales');
    }

    public function destroy(array $params): void
    {
        Csrf::validate();
        $this->sales->delete((int) $params['id'], Auth::id());
        $this->flash('success', 'Vente supprimée.');
        $this->redirect('/sales');
    }

    /**
     * Validate + freeze CUMP and net margin. The server is authoritative.
     * @param int|null $excludeSaleId current sale id when editing (for stock math)
     * @param bool $lock lock the underlying rows (must run inside a transaction)
     * @return array<string,mixed>|null
     */
    private function validate(?int $excludeSaleId, bool $lock = false): ?array
    {
        $userId = Auth::id();
        $productId = (int) ($_POST['product_id'] ?? 0);
        $quantity = (int) ($_POST['quantity'] ?? 0);
        $salePrice = (float) str_replace(',', '.', (string) ($_POST['sale_price'] ?? '0'));
        $packaging = (float) str_replace(',', '.', (string) ($_POST['packaging_cost'] ?? '0'));
        $label = (float) str_replace(',', '.', (string) ($_POST['label_cost'] ?? '0'));
        $boost = (float) str_replace(',', '.', (string) ($_POST['boost_cost'] ?? '0'));
        $other = (float) str_replace(',', '.', (string) ($_POST['other_cost'] ?? '0'));
        $platform = trim((string) ($_POST['platform'] ?? 'Vinted'));
        $soldAt = (string) ($_POST['sold_at'] ?? '');

        $errors = [];

        $productOk = $productId > 0 && $this->products->find($productId, $userId) !== null;
        if (!$productOk) {
            $errors['product_id'] = 'Veuillez choisir un produit valide.';
        }
        if ($quantity <= 0) {
            $errors['quantity'] = 'La quantité doit être un entier strictement positif.';
        }
        foreach (['sale_price' => $salePrice, 'packaging_cost' => $packaging, 'label_cost' => $label, 'boost_cost' => $boost, 'other_cost' => $other] as $k => $v) {
            if ($v < 0) {
                $errors[$k] = 'Le montant doit être positif ou nul.';
            }
        }
        if (!$this->isValidDate($soldAt)) {
            $errors['sold_at'] = 'La date de vente est invalide.';
        }

        // Stock guard (server-side, authoritative).
        if ($productOk && $quantity > 0) {
            $purchased = $this->purchases->purchasedQty($userId, $productId, $lock);
            $sold = $this->sales->soldQty($userId, $productId, $excludeSaleId, $lock);
            $available = ProfitCalculator::stock($purchased, $sold);
            if (!ProfitCalculator::canFulfill($quantity, $available)) {
                $errors['quantity'] = sprintf(
                    'Stock insuffisant : %d disponible(s), %d demandé(s).',
                    $available,
                    $quantity
                );
            }
        }

        if ($errors !== []) {
            $this->flashErrors($errors, $_POST);
            return null;
        }

        // Freeze CUMP at sale time from current lots.
        $cump = ProfitCalculator::cump($this->purchases->lotsForProduct($userId, $productId));
        $netMargin = ProfitCalculator::netMargin($salePrice, $packaging, $label, $boost, $other, $cump, $quantity);

        return [
            'product_id'     => $productId,
            'quantity'       => $quantity,
            'sale_price'     => $salePrice,
            'packaging_cost' => $packaging,
            'label_cost'     => $label,
            'boost_cost'     => $boost,
            'other_cost'     => $other,
            'unit_cost_eur'  => $cump,
            'net_margin_eur' => $netMargin,
            'platform'       => $platform,
            'sold_at'        => $soldAt,
        ];
    }

    /**
     * Products augmented with current CUMP and available stock,
     * used to populate data-* attributes for the live margin preview.
     * @return array<int, array<string,mixed>>
     */
    private function productsMeta(): array
    {
        $userId = Auth::id();
        $out = [];
        foreach ($this->products->allForUser($userId) as $p) {
            $pid = (int) $p['id'];
            $cump = ProfitCalculator::cump($this->purchases->lotsForProduct($userId, $pid));
            $stock = ProfitCalculator::stock(
                $this->purchases->purchasedQty($userId, $pid),
                $this->sales->soldQty($userId, $pid)
            );
            $p['cump'] = $cump;
            $p['stock'] = $stock;
            $out[] = $p;
        }
        return $out;
    }

    private function isValidDate(string $date): bool
    {
        $d = \DateTimeImmutable::createFromFormat('Y-m-d', $date);
        return $d !== false && $d->format('Y-m-d') === $date;
    }
}
