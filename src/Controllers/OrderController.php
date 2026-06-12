<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Csrf;
use App\Core\Database;
use App\Models\Order;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\Sale;
use App\Services\ProfitCalculator;

/**
 * A supplier order (Superbuy, AliExpress…) contains several articles.
 * Global shipping & customs are redistributed across the lines
 * proportionally to weight, then each line is stored as a regular
 * purchase (frozen unit cost) feeding stock and CUMP.
 *
 * Editing an order re-runs the allocation and recomputes the unit costs
 * (lines are replaced). Stock is adjusted; past sales margins stay frozen.
 */
final class OrderController extends Controller
{
    private Order $orders;
    private Product $products;
    private Purchase $purchases;

    public function __construct()
    {
        Auth::require();
        $this->orders = new Order();
        $this->products = new Product();
        $this->purchases = new Purchase();
    }

    public function index(): void
    {
        $this->view('orders/index', [
            'orders' => $this->orders->allForUser(Auth::id()),
        ], 'Commandes');
    }

    public function create(): void
    {
        [$errors, $old] = $this->pullErrors();
        $this->view('orders/form', [
            'order'        => null,
            'lines'        => [],
            'errors'       => $errors,
            'old'          => $old,
            'productNames' => array_column($this->products->allForUser(Auth::id()), 'name'),
            'categories'   => $this->products->categorySuggestions(Auth::id()),
        ], 'Nouvelle commande');
    }

    public function edit(array $params): void
    {
        $userId = Auth::id();
        $order = $this->orders->find((int) $params['id'], $userId);
        if ($order === null) {
            $this->flash('danger', 'Commande introuvable.');
            $this->redirect('/orders');
        }
        [$errors, $old] = $this->pullErrors();
        $this->view('orders/form', [
            'order'        => $order,
            'lines'        => $this->orders->lines((int) $order['id'], $userId),
            'errors'       => $errors,
            'old'          => $old,
            'productNames' => array_column($this->products->allForUser($userId), 'name'),
            'categories'   => $this->products->categorySuggestions($userId),
        ], 'Modifier la commande');
    }

    public function show(array $params): void
    {
        $userId = Auth::id();
        $order = $this->orders->find((int) $params['id'], $userId);
        if ($order === null) {
            $this->flash('danger', 'Commande introuvable.');
            $this->redirect('/orders');
        }

        $lines = $this->orders->lines((int) $order['id'], $userId);
        $totalWeight = 0;
        $totalEur = 0.0;
        $totalUnits = 0;
        foreach ($lines as $l) {
            $totalWeight += (int) ($l['weight_grams'] ?? 0) * (int) $l['quantity'];
            $totalEur += (float) $l['unit_cost_eur'] * (int) $l['quantity'];
            $totalUnits += (int) $l['quantity'];
        }

        $this->view('orders/show', [
            'order'       => $order,
            'lines'       => $lines,
            'totalWeight' => $totalWeight,
            'totalEur'    => round($totalEur, 2),
            'totalUnits'  => $totalUnits,
        ], 'Commande du ' . $order['ordered_at']);
    }

    public function store(): void
    {
        Csrf::validate();
        $userId = Auth::id();

        [$header, $lines, $errors] = $this->parseInput();
        if ($errors !== []) {
            $this->flashErrors($errors, $_POST);
            $this->redirect('/orders/create');
        }

        $db = Database::connection();
        $db->beginTransaction();
        try {
            $orderId = $this->orders->create($userId, $header);
            $newProducts = $this->persistLines($userId, $orderId, $header, $lines);
            $db->commit();
        } catch (\Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }

        $this->flash('success', 'Commande enregistrée : ' . count($lines) . ' article(s), frais répartis au prorata du poids.');
        $this->notifyNewProducts($newProducts);
        $this->redirect('/orders/' . $orderId);
    }

    /** Re-save an order: header updated, lines replaced, costs re-frozen. */
    public function update(array $params): void
    {
        Csrf::validate();
        $userId = Auth::id();
        $orderId = (int) $params['id'];

        if ($this->orders->find($orderId, $userId) === null) {
            $this->flash('danger', 'Commande introuvable.');
            $this->redirect('/orders');
        }

        [$header, $lines, $errors] = $this->parseInput();

        // Repercussion guard: refuse if the new quantities would leave a
        // product with fewer purchased units than already sold.
        foreach ($this->stockConflicts($userId, $orderId, $lines) as $i => $msg) {
            $errors['stock_' . $i] = $msg;
        }

        if ($errors !== []) {
            $this->flashErrors($errors, $_POST);
            $this->redirect('/orders/' . $orderId . '/edit');
        }

        $db = Database::connection();
        $db->beginTransaction();
        try {
            $this->orders->update($orderId, $userId, $header);
            $this->purchases->deleteByOrder($orderId, $userId);
            $newProducts = $this->persistLines($userId, $orderId, $header, $lines);
            $db->commit();
        } catch (\Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }

        $this->flash('success', 'Commande mise à jour : frais re-répartis, coûts unitaires et stock recalculés. Les marges des ventes passées restent figées.');
        $this->notifyNewProducts($newProducts);
        $this->redirect('/orders/' . $orderId);
    }

    public function destroy(array $params): void
    {
        Csrf::validate();
        $this->orders->delete((int) $params['id'], Auth::id());
        $this->flash('success', 'Commande supprimée (avec ses lignes d\'achat).');
        $this->redirect('/orders');
    }

    // ------------------------------------------------------------------
    // Shared parsing / persistence
    // ------------------------------------------------------------------

    /**
     * Parse + validate the order form (header + lines).
     * @return array{0: array<string,mixed>, 1: array<int,array<string,mixed>>, 2: array<string,string>}
     */
    private function parseInput(): array
    {
        $supplier = trim((string) ($_POST['supplier'] ?? ''));
        $orderUrl = trim((string) ($_POST['order_url'] ?? ''));
        $orderedAt = (string) ($_POST['ordered_at'] ?? '');
        $currency = (string) ($_POST['currency'] ?? 'EUR');
        $rate = (float) str_replace(',', '.', (string) ($_POST['exchange_rate'] ?? '1'));
        $shipping = (float) str_replace(',', '.', (string) ($_POST['shipping_cost'] ?? '0'));
        $customs = (float) str_replace(',', '.', (string) ($_POST['customs_cost'] ?? '0'));

        $errors = [];
        if (!$this->isValidDate($orderedAt)) {
            $errors['ordered_at'] = 'La date de commande est invalide.';
        }
        if (!in_array($currency, ['EUR', 'USD', 'CNY'], true)) {
            $errors['currency'] = 'Devise invalide.';
        }
        if ($currency === 'EUR') {
            $rate = 1.0;
        } elseif ($rate <= 0) {
            $errors['exchange_rate'] = 'Le taux de change doit être strictement positif.';
        }
        if ($shipping < 0) {
            $errors['shipping_cost'] = 'Les frais de port doivent être positifs ou nuls.';
        }
        if ($customs < 0) {
            $errors['customs_cost'] = 'La douane doit être positive ou nulle.';
        }

        $names = (array) ($_POST['line_product'] ?? []);
        $cats = (array) ($_POST['line_category'] ?? []);
        $urls = (array) ($_POST['line_url'] ?? []);
        $qtys = (array) ($_POST['line_qty'] ?? []);
        $prices = (array) ($_POST['line_price'] ?? []);
        $weights = (array) ($_POST['line_weight'] ?? []);

        $lines = [];
        foreach ($names as $i => $rawName) {
            $name = trim((string) $rawName);
            $category = trim((string) ($cats[$i] ?? ''));
            $url = trim((string) ($urls[$i] ?? ''));
            $qty = (int) ($qtys[$i] ?? 0);
            $price = (float) str_replace(',', '.', (string) ($prices[$i] ?? '0'));
            $weight = (int) ($weights[$i] ?? 0);

            // Skip fully empty rows (the form always renders at least one).
            if ($name === '' && $category === '' && $url === '' && $qty === 0 && $price == 0.0 && $weight === 0) {
                continue;
            }

            $lineNo = count($lines) + 1;
            if ($name === '') {
                $errors["line_{$i}"] = "Article {$lineNo} : le nom du produit est requis.";
            }
            if ($qty <= 0) {
                $errors["line_{$i}_qty"] = "Article {$lineNo} : la quantité doit être un entier strictement positif.";
            }
            if ($price < 0) {
                $errors["line_{$i}_price"] = "Article {$lineNo} : le prix unitaire doit être positif ou nul.";
            }
            if ($weight < 0) {
                $errors["line_{$i}_weight"] = "Article {$lineNo} : le poids doit être positif ou nul.";
            }

            $qty = max(1, $qty);
            $lines[] = [
                'name'       => $name,
                'category'   => $category,
                'url'        => $url,
                'qty'        => $qty,
                'unit_price' => $price,                       // price PER UNIT (form input)
                'line_total' => round($price * $qty, 2),      // total paid for the line
                'weight'     => max(0, $weight),              // grams per unit
            ];
        }

        if ($lines === []) {
            $errors['lines'] = 'Ajoutez au moins un article à la commande.';
        }

        $header = [
            'supplier'      => $supplier,
            'order_url'     => $orderUrl,
            'currency'      => $currency,
            'exchange_rate' => $rate,
            'shipping_cost' => $shipping,
            'customs_cost'  => $customs,
            'ordered_at'    => $orderedAt,
        ];

        return [$header, $lines, $errors];
    }

    /**
     * Allocate fees and write the purchase lines (find-or-create products).
     * Must run inside a transaction. Returns the names of created products.
     * @return string[]
     */
    private function persistLines(int $userId, int $orderId, array $header, array $lines): array
    {
        $allocBasis = array_map(static fn(array $l): array => [
            'weight' => (float) ($l['weight'] * $l['qty']),
            'price'  => $l['line_total'],
        ], $lines);
        $allocShip = ProfitCalculator::allocateByWeight($allocBasis, (float) $header['shipping_cost']);
        $allocCustoms = ProfitCalculator::allocateByWeight($allocBasis, (float) $header['customs_cost']);

        $newProducts = [];
        foreach ($lines as $i => $l) {
            $product = $this->products->findByName($userId, $l['name']);
            if ($product !== null) {
                $productId = (int) $product['id'];
                $this->products->setCategoryIfEmpty($productId, $userId, $l['category']);
            } else {
                $productId = $this->products->create($userId, [
                    'name' => $l['name'], 'category' => $l['category'], 'description' => '', 'image_path' => null,
                ]);
                $newProducts[] = $l['name'];
            }

            // The purchase line stores the LINE TOTAL (lot price), so the
            // standard formula applies: (lot + fees) × rate ÷ qty.
            $unitCost = ProfitCalculator::unitCostEur(
                $l['line_total'], $allocShip[$i], $allocCustoms[$i], (float) $header['exchange_rate'], $l['qty']
            );

            $this->purchases->create($userId, [
                'product_id'    => $productId,
                'order_id'      => $orderId,
                'quantity'      => $l['qty'],
                'weight_grams'  => $l['weight'] ?: null,
                'lot_price'     => $l['line_total'],
                'shipping_cost' => $allocShip[$i],
                'customs_cost'  => $allocCustoms[$i],
                'currency'      => $header['currency'],
                'exchange_rate' => $header['exchange_rate'],
                'unit_cost_eur' => $unitCost,
                'supplier'      => $header['supplier'],
                'order_url'     => $l['url'] ?: $header['order_url'],
                'purchased_at'  => $header['ordered_at'],
            ]);
        }

        return $newProducts;
    }

    /**
     * Editing repercussion check: with old order lines removed and new ones
     * applied, every product must keep purchased >= sold.
     * @return string[] human-readable conflicts
     */
    private function stockConflicts(int $userId, int $orderId, array $lines): array
    {
        $oldQty = [];
        $names = [];
        foreach ($this->orders->lines($orderId, $userId) as $l) {
            $pid = (int) $l['product_id'];
            $oldQty[$pid] = ($oldQty[$pid] ?? 0) + (int) $l['quantity'];
            $names[$pid] = (string) $l['product_name'];
        }

        $newQty = [];
        foreach ($lines as $l) {
            $product = $this->products->findByName($userId, $l['name']);
            if ($product === null) {
                continue; // brand-new product: nothing sold yet, no conflict
            }
            $pid = (int) $product['id'];
            $newQty[$pid] = ($newQty[$pid] ?? 0) + (int) $l['qty'];
            $names[$pid] = (string) $product['name'];
        }

        $saleModel = new Sale();
        $conflicts = [];
        foreach (array_unique(array_merge(array_keys($oldQty), array_keys($newQty))) as $pid) {
            $final = $this->purchases->purchasedQty($userId, $pid)
                - ($oldQty[$pid] ?? 0)
                + ($newQty[$pid] ?? 0);
            $sold = $saleModel->soldQty($userId, $pid);
            if ($final < $sold) {
                $conflicts[] = sprintf(
                    '« %s » : impossible de descendre à %d unité(s) achetée(s), %d déjà vendue(s). Ajustez la quantité ou supprimez les ventes concernées.',
                    $names[$pid],
                    $final,
                    $sold
                );
            }
        }
        return $conflicts;
    }

    private function notifyNewProducts(array $newProducts): void
    {
        if ($newProducts !== []) {
            $this->flash('info', count($newProducts) . ' nouveau(x) produit(s) créé(s) : ' . implode(', ', $newProducts)
                . '. Renseignez leurs prix marché (fiche produit) pour voir la marge potentielle.');
        }
    }

    private function isValidDate(string $date): bool
    {
        $d = \DateTimeImmutable::createFromFormat('Y-m-d', $date);
        return $d !== false && $d->format('Y-m-d') === $date;
    }
}
