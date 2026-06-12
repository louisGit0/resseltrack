<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Csrf;
use App\Models\Product;
use App\Models\Purchase;
use App\Services\ProfitCalculator;

final class PurchaseController extends Controller
{
    private Purchase $purchases;
    private Product $products;

    public function __construct()
    {
        Auth::require();
        $this->purchases = new Purchase();
        $this->products = new Product();
    }

    private const PER_PAGE = 15;

    public function index(): void
    {
        $q = trim((string) ($_GET['q'] ?? ''));
        $sort = (string) ($_GET['sort'] ?? 'date');
        if (!in_array($sort, ['date', 'product', 'quantity', 'lot', 'unit'], true)) {
            $sort = 'date';
        }
        // Direction implied by the metric (A→Z for product, biggest/newest first otherwise).
        $dir = $sort === 'product' ? 'asc' : 'desc';
        $page = max(1, (int) ($_GET['page'] ?? 1));
        $productId = (int) ($_GET['product_id'] ?? 0);
        $from = $this->dateOrNull((string) ($_GET['from'] ?? ''));
        $to = $this->dateOrNull((string) ($_GET['to'] ?? ''));

        $result = $this->purchases->searchForUser(Auth::id(), [
            'q'          => $q,
            'sort'       => $sort,
            'dir'        => $dir,
            'product_id' => $productId ?: null,
            'from'       => $from,
            'to'         => $to,
        ], $page, self::PER_PAGE);
        $pages = max(1, (int) ceil($result['total'] / self::PER_PAGE));

        $this->view('purchases/index', [
            'purchases' => $result['rows'],
            'total'     => $result['total'],
            'units'     => $result['units'],
            'invested'  => $result['invested'],
            'products'  => $this->products->allForUser(Auth::id()),
            'q'         => $q,
            'sort'      => $sort,
            'dir'       => $dir,
            'page'      => min($page, $pages),
            'pages'     => $pages,
            'productId' => $productId,
            'from'      => $from,
            'to'        => $to,
        ], 'Achats');
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
        $this->view('purchases/form', [
            'purchase'   => null,
            'products'   => $this->products->allForUser(Auth::id()),
            'errors'     => $errors,
            'old'        => $old,
        ], 'Nouvel achat');
    }

    public function store(): void
    {
        Csrf::validate();
        $data = $this->validate();
        if ($data === null) {
            $this->redirect('/purchases/create');
        }
        $this->purchases->create(Auth::id(), $data);
        $this->flash('success', 'Achat enregistré.');
        $this->redirect('/purchases');
    }

    public function edit(array $params): void
    {
        $purchase = $this->purchases->find((int) $params['id'], Auth::id());
        if ($purchase === null) {
            $this->flash('danger', 'Achat introuvable.');
            $this->redirect('/purchases');
        }
        [$errors, $old] = $this->pullErrors();
        $this->view('purchases/form', [
            'purchase' => $purchase,
            'products' => $this->products->allForUser(Auth::id()),
            'errors'   => $errors,
            'old'      => $old,
        ], 'Modifier l\'achat');
    }

    public function update(array $params): void
    {
        Csrf::validate();
        $id = (int) $params['id'];
        if ($this->purchases->find($id, Auth::id()) === null) {
            $this->flash('danger', 'Achat introuvable.');
            $this->redirect('/purchases');
        }
        $data = $this->validate();
        if ($data === null) {
            $this->redirect('/purchases/' . $id . '/edit');
        }
        $this->purchases->update($id, Auth::id(), $data);
        $this->flash('success', 'Achat mis à jour.');
        $this->redirect('/purchases');
    }

    public function destroy(array $params): void
    {
        Csrf::validate();
        $this->purchases->delete((int) $params['id'], Auth::id());
        $this->flash('success', 'Achat supprimé.');
        $this->redirect('/purchases');
    }

    /**
     * Server-side validation. The server is authoritative: it recomputes
     * unit_cost_eur from the submitted values regardless of any JS preview.
     * @return array<string,mixed>|null
     */
    private function validate(): ?array
    {
        $userId = Auth::id();
        $productId = (int) ($_POST['product_id'] ?? 0);
        $quantity = (int) ($_POST['quantity'] ?? 0);
        $lotPrice = (float) str_replace(',', '.', (string) ($_POST['lot_price'] ?? '0'));
        $shipping = (float) str_replace(',', '.', (string) ($_POST['shipping_cost'] ?? '0'));
        $customs = (float) str_replace(',', '.', (string) ($_POST['customs_cost'] ?? '0'));
        $currency = (string) ($_POST['currency'] ?? 'EUR');
        $rate = (float) str_replace(',', '.', (string) ($_POST['exchange_rate'] ?? '1'));
        $supplier = trim((string) ($_POST['supplier'] ?? ''));
        $orderUrl = trim((string) ($_POST['order_url'] ?? ''));
        $purchasedAt = (string) ($_POST['purchased_at'] ?? '');

        $errors = [];

        if ($productId <= 0 || $this->products->find($productId, $userId) === null) {
            $errors['product_id'] = 'Veuillez choisir un produit valide.';
        }
        if ($quantity <= 0) {
            $errors['quantity'] = 'La quantité doit être un entier strictement positif.';
        }
        foreach (['lot_price' => $lotPrice, 'shipping_cost' => $shipping, 'customs_cost' => $customs] as $k => $v) {
            if ($v < 0) {
                $errors[$k] = 'Le montant doit être positif ou nul.';
            }
        }
        if (!in_array($currency, ['EUR', 'USD', 'CNY'], true)) {
            $errors['currency'] = 'Devise invalide.';
        }
        // EUR is always rate 1; USD requires a strictly positive rate.
        if ($currency === 'EUR') {
            $rate = 1.0;
        } elseif ($rate <= 0) {
            $errors['exchange_rate'] = 'Le taux de change doit être strictement positif.';
        }
        if (!$this->isValidDate($purchasedAt)) {
            $errors['purchased_at'] = "La date d'achat est invalide.";
        }

        if ($errors !== []) {
            $this->flashErrors($errors, $_POST);
            return null;
        }

        $unitCost = ProfitCalculator::unitCostEur($lotPrice, $shipping, $customs, $rate, $quantity);

        return [
            'product_id'    => $productId,
            'quantity'      => $quantity,
            'lot_price'     => $lotPrice,
            'shipping_cost' => $shipping,
            'customs_cost'  => $customs,
            'currency'      => $currency,
            'exchange_rate' => $rate,
            'unit_cost_eur' => $unitCost,
            'supplier'      => $supplier,
            'order_url'     => $orderUrl,
            'purchased_at'  => $purchasedAt,
        ];
    }

    private function isValidDate(string $date): bool
    {
        $d = \DateTimeImmutable::createFromFormat('Y-m-d', $date);
        return $d !== false && $d->format('Y-m-d') === $date;
    }
}
