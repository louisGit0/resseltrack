<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Csrf;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\Purchase;
use App\Models\Sale;
use App\Services\ProfitCalculator;
use App\Services\CloudinaryStorage;
use App\Services\ProductImportService;

final class ProductController extends Controller
{
    private Product $products;

    public function __construct()
    {
        Auth::require();
        $this->products = new Product();
    }

    private const PER_PAGE = 15;

    public function index(): void
    {
        $userId = Auth::id();
        $q = trim((string) ($_GET['q'] ?? ''));
        // Default ordering: profitability vs second-hand market, best first.
        $sort = (string) ($_GET['sort'] ?? 'rentability');
        $allowedSorts = ['rentability', 'rentability_new', 'profit', 'stock', 'value', 'name', 'cump'];
        if (!in_array($sort, $allowedSorts, true)) {
            $sort = 'rentability';
        }
        // Direction is implied by the metric (A→Z and cheapest-cost ascending).
        $dir = in_array($sort, ['name', 'cump'], true) ? 'asc' : 'desc';
        $page = max(1, (int) ($_GET['page'] ?? 1));
        $cat = trim((string) ($_GET['cat'] ?? ''));
        $status = (string) ($_GET['status'] ?? '');
        if (!in_array($status, ['in', 'low', 'out'], true)) {
            $status = '';
        }

        $products = $this->products->searchForUser($userId, $q);
        $stats = $this->products->statsForUser($userId);
        // Latest supplier link per product — surfaced as a "reorder" button.
        $reorderLinks = (new Purchase())->latestOrderUrls($userId);

        $rows = [];
        foreach ($products as $p) {
            $s = $stats[(int) $p['id']] ?? ['purchased_qty' => 0, 'total_cost_eur' => 0.0, 'sold_qty' => 0, 'profit_eur' => 0.0];
            $cump = ProfitCalculator::cump([
                ['cost_eur' => $s['total_cost_eur'], 'quantity' => $s['purchased_qty']],
            ]);
            $stock = ProfitCalculator::stock($s['purchased_qty'], $s['sold_qty']);

            // Potential margins per unit = market price - CUMP, with the
            // share of the market price as % (only meaningful once at least
            // one lot has been purchased).
            $marketNew = isset($p['market_price_new']) && $p['market_price_new'] !== null
                ? (float) $p['market_price_new'] : null;
            $marketUsed = isset($p['market_price_used']) && $p['market_price_used'] !== null
                ? (float) $p['market_price_used'] : null;
            $hasCost = $s['purchased_qty'] > 0;

            $potNew = ($marketNew !== null && $hasCost) ? round($marketNew - $cump, 2) : null;
            $pctNew = ($potNew !== null && $marketNew > 0) ? round($potNew / $marketNew * 100, 1) : null;
            $potUsed = ($marketUsed !== null && $hasCost) ? round($marketUsed - $cump, 2) : null;
            $pctUsed = ($potUsed !== null && $marketUsed > 0) ? round($potUsed / $marketUsed * 100, 1) : null;

            $rows[] = [
                'product'     => $p,
                'stock'       => $stock,
                'cump'        => $cump,
                'has_cost'    => $hasCost,
                'stock_value' => ProfitCalculator::stockValue($stock, $cump),
                'profit'      => $s['profit_eur'],
                'market_new'  => $marketNew,
                'market_used' => $marketUsed,
                'pot_new'     => $potNew,
                'pct_new'     => $pctNew,
                'pot_used'    => $potUsed,
                'pct_used'    => $pctUsed,
                'reorder'     => $reorderLinks[(int) $p['id']] ?? null,
            ];
        }

        // Category & stock-status filters (computed values -> filtered in PHP).
        if ($cat !== '') {
            $rows = array_values(array_filter($rows, static fn(array $r) => (string) ($r['product']['category'] ?? '') === $cat));
        }
        if ($status !== '') {
            $rows = array_values(array_filter($rows, static function (array $r) use ($status): bool {
                return match ($status) {
                    'in'  => $r['stock'] > 2,
                    'low' => $r['stock'] > 0 && $r['stock'] <= 2,
                    'out' => $r['stock'] <= 0,
                };
            }));
        }

        // Aggregates over the whole filtered set (before pagination).
        $aggUnits = 0;
        $aggValue = 0.0;
        $aggProfit = 0.0;
        foreach ($rows as $r) {
            $aggUnits += max(0, $r['stock']);
            $aggValue += max(0.0, $r['stock_value']);
            $aggProfit += $r['profit'];
        }

        // Sort in PHP: these metrics are computed, not SQL columns.
        // Products without market data sink to the bottom of profitability sorts.
        $keys = [
            'rentability'     => static fn(array $r) => $r['pct_used'] ?? -INF,
            'rentability_new' => static fn(array $r) => $r['pct_new'] ?? -INF,
            'profit'          => static fn(array $r) => $r['profit'],
            'stock'           => static fn(array $r) => $r['stock'],
            'value'           => static fn(array $r) => $r['stock_value'],
            'name'            => static fn(array $r) => mb_strtolower($r['product']['name']),
            'cump'            => static fn(array $r) => $r['cump'],
        ];
        $keyFn = $keys[$sort];
        usort($rows, static fn(array $a, array $b) => $keyFn($a) <=> $keyFn($b));
        if ($dir === 'desc') {
            $rows = array_reverse($rows);
        }

        $total = count($rows);
        $pages = max(1, (int) ceil($total / self::PER_PAGE));
        $page = min($page, $pages);
        $rows = array_slice($rows, ($page - 1) * self::PER_PAGE, self::PER_PAGE);

        $this->view('products/index', [
            'rows'       => $rows,
            'total'      => $total,
            'aggUnits'   => $aggUnits,
            'aggValue'   => round($aggValue, 2),
            'aggProfit'  => round($aggProfit, 2),
            'categories' => $this->products->categoriesForUser($userId),
            'q'          => $q,
            'sort'       => $sort,
            'dir'        => $dir,
            'page'       => $page,
            'pages'      => $pages,
            'perPage'    => self::PER_PAGE,
            'cat'        => $cat,
            'status'     => $status,
        ], 'Produits');
    }

    /** Product detail page: KPIs + purchase lots + sales history. */
    public function show(array $params): void
    {
        $userId = Auth::id();
        $product = $this->products->find((int) $params['id'], $userId);
        if ($product === null) {
            $this->flash('danger', 'Produit introuvable.');
            $this->redirect('/products');
        }
        $pid = (int) $product['id'];

        $purchaseModel = new Purchase();
        $saleModel = new Sale();

        $purchases = $purchaseModel->allForProduct($userId, $pid);
        $sales = $saleModel->allForProduct($userId, $pid);

        // Aggregates from the frozen values.
        $purchasedQty = 0;
        $totalCost = 0.0;
        foreach ($purchases as $pu) {
            $purchasedQty += (int) $pu['quantity'];
            $totalCost += (float) $pu['unit_cost_eur'] * (int) $pu['quantity'];
        }

        $soldQty = 0;
        $profit = 0.0;
        $soldCost = 0.0; // cost of sold units, at the CUMP frozen per sale
        foreach ($sales as &$s) {
            $soldQty += (int) $s['quantity'];
            $profit += (float) $s['net_margin_eur'];
            $soldCost += (float) $s['unit_cost_eur'] * (int) $s['quantity'];
            $s['margin_pct'] = ProfitCalculator::marginPercent((float) $s['net_margin_eur'], (float) $s['sale_price']);
        }
        unset($s);

        $cump = ProfitCalculator::cump([['cost_eur' => $totalCost, 'quantity' => $purchasedQty]]);
        $stock = ProfitCalculator::stock($purchasedQty, $soldQty);

        // Latest supplier link of this product, for the "reorder" button.
        $reorder = null;
        foreach ($purchases as $pu) { // newest first
            if (!empty($pu['order_url'])) {
                $reorder = [
                    'url'          => (string) $pu['order_url'],
                    'supplier'     => $pu['supplier'] !== null ? (string) $pu['supplier'] : null,
                    'purchased_at' => (string) $pu['purchased_at'],
                ];
                break;
            }
        }

        // Average days between the first lot and each sale.
        $avgDays = null;
        $firstPurchase = $purchaseModel->firstPurchaseDate($userId, $pid);
        if ($firstPurchase !== null && $sales !== []) {
            $start = new \DateTimeImmutable($firstPurchase);
            $totalDays = 0;
            foreach ($sales as $s2) {
                $totalDays += max(0, (int) $start->diff(new \DateTimeImmutable((string) $s2['sold_at']))->days);
            }
            $avgDays = (int) round($totalDays / count($sales));
        }

        $this->view('products/show', [
            'product'      => $product,
            'reorder'      => $reorder,
            'images'       => (new ProductImage())->allForProduct($userId, $pid),
            'maxPhotos'    => self::MAX_GALLERY_PHOTOS,
            'purchases'    => $purchases,
            'sales'        => $sales,
            'stock'        => $stock,
            'cump'         => $cump,
            'stockValue'   => ProfitCalculator::stockValue($stock, $cump),
            'profit'       => round($profit, 2),
            'roi'          => ProfitCalculator::roi($profit, $soldCost),
            'avgDays'      => $avgDays,
            'purchasedQty' => $purchasedQty,
            'soldQty'      => $soldQty,
        ], $product['name']);
    }

    public function create(): void
    {
        [$errors, $old] = $this->pullErrors();
        $this->view('products/form', [
            'product'    => null,
            'errors'     => $errors,
            'old'        => $old,
            'categories' => $this->products->categorySuggestions(Auth::id()),
        ], 'Nouveau produit');
    }

    public function store(): void
    {
        Csrf::validate();
        $data = $this->validate();
        if ($data === null) {
            $this->redirect('/products/create');
        }
        $data['image_path'] = $this->handleUpload();
        $this->products->create(Auth::id(), $data);
        $this->flash('success', 'Produit créé.');
        $this->redirect('/products');
    }

    public function edit(array $params): void
    {
        $product = $this->products->find((int) $params['id'], Auth::id());
        if ($product === null) {
            $this->flash('danger', 'Produit introuvable.');
            $this->redirect('/products');
        }
        [$errors, $old] = $this->pullErrors();
        $this->view('products/form', [
            'product'    => $product,
            'errors'     => $errors,
            'old'        => $old,
            'categories' => $this->products->categorySuggestions(Auth::id()),
        ], 'Modifier le produit');
    }

    public function update(array $params): void
    {
        Csrf::validate();
        $id = (int) $params['id'];
        if ($this->products->find($id, Auth::id()) === null) {
            $this->flash('danger', 'Produit introuvable.');
            $this->redirect('/products');
        }
        $data = $this->validate();
        if ($data === null) {
            $this->redirect('/products/' . $id . '/edit');
        }
        $data['image_path'] = $this->handleUpload(); // null keeps the existing image
        $this->products->update($id, Auth::id(), $data);
        $this->flash('success', 'Produit mis à jour.');
        $this->redirect('/products');
    }

    public function destroy(array $params): void
    {
        Csrf::validate();
        $userId = Auth::id();
        $pid = (int) $params['id'];

        // 1) Collect every Cloudinary path BEFORE the cascading delete wipes
        //    product_images. The cover (products.image_path) is usually also a
        //    gallery row, so dedupe to avoid a redundant destroy call.
        $paths = [];
        $product = $this->products->find($pid, $userId);
        if ($product !== null && !empty($product['image_path'])) {
            $paths[] = (string) $product['image_path']; // cover
        }
        foreach ((new ProductImage())->allForProduct($userId, $pid) as $img) {
            $paths[] = (string) $img['path']; // gallery
        }
        $paths = array_values(array_unique(array_filter($paths)));

        // 2) DB delete (FK CASCADE removes product_images, purchases, sales).
        $this->products->delete($pid, $userId);

        // 3) Best-effort Cloudinary purge — never blocks the DB delete (D-11).
        //    Mirrors the deleteImage() try/catch; no-ops on legacy
        //    /assets/uploads/... paths via CloudinaryStorage::derivePublicId().
        $storage = new CloudinaryStorage();
        foreach ($paths as $path) {
            try {
                $storage->delete($path);
            } catch (\Throwable $e) {
                error_log('Cloudinary delete failed for path ' . $path . ': ' . $e->getMessage());
                // best-effort: continue, the DB rows are already gone
            }
        }

        $this->flash('success', 'Produit supprimé (avec ses achats et ventes liés).');
        $this->redirect('/products');
    }

    /** Upload several photos at once into the product gallery. */
    public function uploadImages(array $params): void
    {
        Csrf::validate();
        $userId = Auth::id();
        $pid = (int) $params['id'];
        if ($this->products->find($pid, $userId) === null) {
            $this->flash('danger', 'Produit introuvable.');
            $this->redirect('/products');
        }

        $files = $_FILES['images'] ?? null;
        if ($files === null || !is_array($files['name'] ?? null) || $files['name'] === ['']) {
            $this->flash('warning', 'Aucune photo sélectionnée.');
            $this->redirect('/products/' . $pid);
        }

        $imageModel = new ProductImage();
        $existing = $imageModel->countForProduct($userId, $pid);
        $saved = 0;
        $skipped = [];

        foreach ($files['name'] as $i => $name) {
            if ((string) $name === '' || (int) $files['error'][$i] !== UPLOAD_ERR_OK) {
                continue;
            }
            if ($existing + $saved >= self::MAX_GALLERY_PHOTOS) {
                $skipped[] = 'limite de ' . self::MAX_GALLERY_PHOTOS . ' photos atteinte';
                break;
            }
            [$path, $error] = $this->storeUploadedFile((string) $files['tmp_name'][$i], (int) $files['size'][$i]);
            if ($path === null) {
                $skipped[] = e($name) . ' (' . $error . ')';
                continue;
            }
            $imageModel->create($userId, $pid, $path);
            $saved++;
        }

        // First photo of a coverless product becomes the cover automatically.
        $product = $this->products->find($pid, $userId);
        if ($saved > 0 && empty($product['image_path'])) {
            $first = $imageModel->allForProduct($userId, $pid)[0] ?? null;
            if ($first !== null) {
                $this->products->setCover($pid, $userId, $first['path']);
            }
        }

        if ($saved > 0) {
            $this->flash('success', $saved . ' photo' . ($saved > 1 ? 's' : '') . ' ajoutée' . ($saved > 1 ? 's' : '') . '.');
        }
        if ($skipped !== []) {
            $this->flash('warning', 'Ignoré : ' . implode(' · ', $skipped));
        }
        $this->redirect('/products/' . $pid);
    }

    /** Remove one gallery photo (and its file); clears the cover if it was used. */
    public function deleteImage(array $params): void
    {
        Csrf::validate();
        $userId = Auth::id();
        $pid = (int) $params['id'];
        $imageModel = new ProductImage();
        $image = $imageModel->find((int) $params['imageId'], $userId);

        if ($image === null || (int) $image['product_id'] !== $pid) {
            $this->flash('danger', 'Photo introuvable.');
            $this->redirect('/products/' . $pid);
        }

        $imageModel->delete((int) $image['id'], $userId);

        try {
            (new CloudinaryStorage())->delete((string) $image['path']);
        } catch (\Throwable $e) {
            error_log('Cloudinary delete failed for path ' . $image['path'] . ': ' . $e->getMessage());
            // best-effort: continue, the DB row is already gone
        }

        $product = $this->products->find($pid, $userId);
        if ($product !== null && $product['image_path'] === $image['path']) {
            $this->products->setCover($pid, $userId, null);
        }

        $this->flash('success', 'Photo supprimée.');
        $this->redirect('/products/' . $pid);
    }

    /** Use a gallery photo as the cover shown in the lists. */
    public function setCoverImage(array $params): void
    {
        Csrf::validate();
        $userId = Auth::id();
        $pid = (int) $params['id'];
        $image = (new ProductImage())->find((int) $params['imageId'], $userId);

        if ($image === null || (int) $image['product_id'] !== $pid) {
            $this->flash('danger', 'Photo introuvable.');
            $this->redirect('/products/' . $pid);
        }

        $this->products->setCover($pid, $userId, (string) $image['path']);
        $this->flash('success', 'Photo de couverture mise à jour.');
        $this->redirect('/products/' . $pid);
    }

    /** Quick inline rate from the product detail page (D-03): CSRF + ownership + rating-only update. */
    public function rate(array $params): void
    {
        Csrf::validate();
        $userId = Auth::id();
        $pid = (int) $params['id'];
        if ($this->products->find($pid, $userId) === null) { // ownership / IDOR guard
            $this->flash('danger', 'Produit introuvable.');
            $this->redirect('/products');
        }
        $raw = trim((string) ($_POST['rating'] ?? ''));
        $rating = $raw === '' ? null : (int) $raw; // '' → clear to NULL
        if ($rating !== null && ($rating < 1 || $rating > 5)) {
            $this->flash('danger', 'La note doit être comprise entre 1 et 5.');
            $this->redirect('/products/' . $pid);
        }
        $this->products->setRating($pid, $userId, $rating); // rating-only (D-02: comment edited via the form)
        $this->flash('success', $rating === null ? 'Note retirée.' : 'Note enregistrée.');
        $this->redirect('/products/' . $pid);
    }

    /**
     * Best-effort "Remplir depuis URL" JSON action (D-07, IMPORT-01).
     *
     * Thin transport: validate the form-encoded CSRF token, read the posted URL,
     * delegate the SSRF-guarded fetch/parse/convert to ProductImportService, and
     * return its typed result via Controller::json(). Auth is already enforced by
     * the constructor. The router passes $params as an ignored extra arg (like
     * store()), so this stays parameterless.
     */
    public function fetchUrl(): void
    {
        Csrf::validate(); // reads form-encoded $_POST['_csrf'] (a JSON body → 419 by design)
        $url = trim((string) ($_POST['url'] ?? ''));
        if ($url === '') {
            $this->json(['ok' => false, 'message' => 'Aucune URL fournie.']); // json() exits — no fallthrough
        }
        $this->json((new ProductImportService())->fromUrl($url));
    }

    /** @return array<string,mixed>|null */
    private function validate(): ?array
    {
        $name = trim((string) ($_POST['name'] ?? ''));
        $category = trim((string) ($_POST['category'] ?? ''));
        $description = trim((string) ($_POST['description'] ?? ''));
        $rawNew = trim((string) ($_POST['market_price_new'] ?? ''));
        $rawUsed = trim((string) ($_POST['market_price_used'] ?? ''));
        $marketNew = $rawNew !== '' ? (float) str_replace(',', '.', $rawNew) : null;
        $marketUsed = $rawUsed !== '' ? (float) str_replace(',', '.', $rawUsed) : null;
        $ratingNote = trim((string) ($_POST['rating_note'] ?? ''));
        $rawRating = trim((string) ($_POST['rating'] ?? ''));
        $rating = $rawRating === '' ? null : (int) $rawRating;

        $errors = [];
        if ($name === '') {
            $errors['name'] = 'Le nom du produit est requis.';
        }
        if ($marketNew !== null && $marketNew < 0) {
            $errors['market_price_new'] = 'Le prix doit être positif ou nul.';
        }
        if ($marketUsed !== null && $marketUsed < 0) {
            $errors['market_price_used'] = 'Le prix doit être positif ou nul.';
        }
        if ($rating !== null && ($rating < 1 || $rating > 5)) {
            $errors['rating'] = 'La note doit être comprise entre 1 et 5.';
        }

        if ($errors !== []) {
            $this->flashErrors($errors, [
                'name' => $name, 'category' => $category, 'description' => $description,
                'market_price_new' => $rawNew, 'market_price_used' => $rawUsed,
                'rating' => $rawRating, 'rating_note' => $ratingNote,
            ]);
            return null;
        }

        return [
            'name'              => $name,
            'category'          => $category,
            'description'       => $description,
            'market_price_new'  => $marketNew,
            'market_price_used' => $marketUsed,
            'rating'            => $rating,
            'rating_note'       => $ratingNote,
        ];
    }

    private const MAX_UPLOAD_BYTES = 3670016; // (int)(3.5 * 1024 * 1024) ~3,5 Mo (STORE-05; PHP class-const cannot use cast expressions)
    private const MAX_GALLERY_PHOTOS = 10;

    /** Cover upload from the product form. Returns web path or null. */
    private function handleUpload(): ?string
    {
        if (empty($_FILES['image']['name']) || ($_FILES['image']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            return null;
        }
        [$path, $error] = $this->storeUploadedFile(
            (string) $_FILES['image']['tmp_name'],
            (int) ($_FILES['image']['size'] ?? 0)
        );
        if ($error !== null) {
            $this->flash('warning', 'Image ignorée : ' . $error);
        }
        return $path;
    }

    /**
     * Validate (size + real MIME) and store one uploaded file on Cloudinary.
     * @return array{0: ?string, 1: ?string} [public Cloudinary URL, error message]
     */
    private function storeUploadedFile(string $tmp, int $size): array
    {
        if ($size > self::MAX_UPLOAD_BYTES) {
            return [null, 'elle dépasse la taille maximale autorisée (~3,5 Mo).'];
        }
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime = finfo_file($finfo, $tmp);
        finfo_close($finfo);

        $allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif'];
        if (!isset($allowed[$mime])) {
            return [null, 'format non supporté (JPEG, PNG, WebP ou GIF attendu).'];
        }
        // Cloudinary derives the format from the file; public_id carries no extension.
        $publicId = bin2hex(random_bytes(8));
        try {
            $url = (new CloudinaryStorage())->upload($tmp, $publicId);
        } catch (\Throwable $e) {
            error_log('Cloudinary upload failed: ' . $e->getMessage());
            return [null, 'échec de l\'enregistrement du fichier.'];
        }
        return [$url, null];
    }
}
