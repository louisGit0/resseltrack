<?php
/** @var ?array $order @var array $lines @var array $errors @var array $old
 *  @var string[] $productNames @var string[] $categories @var array $suppliers */
$isEdit = $order !== null;
$action = $isEdit ? '/orders/' . (int) $order['id'] : '/orders';
$val = static fn(string $k, $def = '') => $old[$k] ?? ($order[$k] ?? $def);
$cur = $val('currency', 'EUR');
$sym = cur_sym($cur);
// Selected supplier id (string, '' = none/"Autre"). Old input wins, then edit-mode order.
$selSupplierId = (string) $val('supplier_id', '');

// Line rows: old input (after a validation error) wins, then the existing
// order lines (edit mode), then one empty row.
$oldLines = [];
if (!empty($old['line_product']) && is_array($old['line_product'])) {
    foreach ($old['line_product'] as $i => $name) {
        $oldLines[] = [
            'product'  => (string) $name,
            'category' => (string) ($old['line_category'][$i] ?? ''),
            'url'      => (string) ($old['line_url'][$i] ?? ''),
            'qty'      => (string) ($old['line_qty'][$i] ?? ''),
            'price'    => (string) ($old['line_price'][$i] ?? ''),
            'weight'   => (string) ($old['line_weight'][$i] ?? ''),
        ];
    }
} elseif ($isEdit && $lines !== []) {
    foreach ($lines as $l) {
        $qty = max(1, (int) $l['quantity']);
        $oldLines[] = [
            'product'  => (string) $l['product_name'],
            'category' => (string) ($l['product_category'] ?? ''),
            'url'      => (string) ($l['order_url'] ?? ''),
            'qty'      => (string) $qty,
            // lot_price stores the LINE TOTAL — back to unit price for the form.
            'price'    => (string) round((float) $l['lot_price'] / $qty, 2),
            'weight'   => $l['weight_grams'] !== null ? (string) (int) $l['weight_grams'] : '',
        ];
    }
}
if ($oldLines === []) {
    $oldLines[] = ['product' => '', 'category' => '', 'url' => '', 'qty' => '', 'price' => '', 'weight' => ''];
}

$lineErrors = array_filter($errors, static fn($k) => str_starts_with($k, 'line') || str_starts_with($k, 'stock'), ARRAY_FILTER_USE_KEY);
?>
<div class="page-header">
    <nav style="font-size:.8rem" class="mb-1">
        <a href="<?= $isEdit ? '/orders/' . (int) $order['id'] : '/orders' ?>" class="text-muted"><i class="bi bi-arrow-left me-1"></i><?= $isEdit ? 'Détail de la commande' : 'Commandes' ?></a>
    </nav>
    <h1 class="page-title"><?= $isEdit ? 'Modifier la commande' : 'Nouvelle commande' ?></h1>
    <p class="page-sub"><?= $isEdit
        ? 'À l\'enregistrement, la répartition des frais et les coûts unitaires sont recalculés et le stock est ajusté. Les marges des ventes passées restent figées.'
        : 'Saisissez tous les articles de la commande : les frais de port et la douane sont répartis automatiquement au prorata du poids, et chaque article devient une ligne d\'achat (stock + CUMP).' ?></p>
</div>

<?php if (!empty($lineErrors)): ?>
    <div class="alert alert-danger">
        <ul class="mb-0 ps-3">
            <?php foreach ($lineErrors as $msg): ?><li><?= e($msg) ?></li><?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>

<form method="post" action="<?= e($action) ?>" id="order-form" novalidate>
    <?= \App\Core\Csrf::field() ?>

    <div class="card mb-3">
        <div class="card-body p-4">
            <div class="form-section-title">Commande</div>
            <div class="row g-3">
                <div class="col-md-4">
                    <label class="form-label">Fournisseur</label>
                    <select name="supplier_id" id="o-supplier" class="form-select">
                        <option value="">Autre / saisie libre…</option>
                        <?php foreach ($suppliers as $s): ?>
                            <option value="<?= (int) $s['id'] ?>" <?= $selSupplierId === (string) $s['id'] ? 'selected' : '' ?>><?= e($s['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <div id="o-supplier-free" class="mt-2 <?= $selSupplierId !== '' ? 'd-none' : '' ?>">
                        <input type="text" name="supplier" class="form-control" value="<?= e($val('supplier')) ?>" placeholder="Superbuy, AliExpress…">
                    </div>
                </div>
                <div class="col-md-5">
                    <label class="form-label">URL de la commande</label>
                    <input type="url" name="order_url" class="form-control" value="<?= e($val('order_url')) ?>" placeholder="https://…">
                </div>
                <div class="col-md-3">
                    <label class="form-label">Date *</label>
                    <input type="date" name="ordered_at" class="form-control <?= isset($errors['ordered_at']) ? 'is-invalid' : '' ?>"
                           value="<?= e($val('ordered_at', date('Y-m-d'))) ?>" required>
                    <?php if (isset($errors['ordered_at'])): ?><div class="invalid-feedback"><?= e($errors['ordered_at']) ?></div><?php endif; ?>
                </div>

                <div class="col-md-3">
                    <label class="form-label">Devise</label>
                    <select name="currency" id="o-currency" class="form-select">
                        <option value="EUR" <?= $cur === 'EUR' ? 'selected' : '' ?>>EUR (€)</option>
                        <option value="USD" <?= $cur === 'USD' ? 'selected' : '' ?>>USD ($)</option>
                        <option value="CNY" <?= $cur === 'CNY' ? 'selected' : '' ?>>CNY / Yuan (¥)</option>
                    </select>
                </div>
                <div class="col-md-4 d-none" id="o-rate-wrapper">
                    <label class="form-label">Taux <span id="o-rate-from"><?= e($cur === 'EUR' ? 'devise' : $cur) ?></span> → EUR</label>
                    <div class="input-group">
                        <input type="number" min="0" step="0.000001" name="exchange_rate" id="o-rate"
                               class="form-control <?= isset($errors['exchange_rate']) ? 'is-invalid' : '' ?>" value="<?= e($val('exchange_rate', '')) ?>" placeholder="0.92">
                        <button type="button" class="btn btn-outline-primary" id="o-fetch-rate"><i class="bi bi-arrow-repeat me-1"></i>Taux du jour</button>
                    </div>
                    <div class="form-text" id="o-rate-status">Figé avec la commande.</div>
                    <?php if (isset($errors['exchange_rate'])): ?><div class="text-danger" style="font-size:.8rem"><?= e($errors['exchange_rate']) ?></div><?php endif; ?>
                </div>
                <div class="col-md-2 col-6">
                    <label class="form-label">Frais de port</label>
                    <div class="input-group">
                        <input type="number" min="0" step="0.01" name="shipping_cost" id="o-shipping" class="form-control" value="<?= e($val('shipping_cost', '0')) ?>">
                        <span class="input-group-text o-currency-suffix"><?= e($sym) ?></span>
                    </div>
                </div>
                <div class="col-md-2 col-6">
                    <label class="form-label">Douane</label>
                    <div class="input-group">
                        <input type="number" min="0" step="0.01" name="customs_cost" id="o-customs" class="form-control" value="<?= e($val('customs_cost', '0')) ?>">
                        <span class="input-group-text o-currency-suffix"><?= e($sym) ?></span>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="card mb-3">
        <div class="card-header d-flex align-items-center justify-content-between">
            <span><i class="bi bi-list-ul me-2 text-muted"></i>Articles de la commande</span>
            <button type="button" class="btn btn-sm btn-outline-primary" id="add-line"><i class="bi bi-plus-lg me-1"></i>Ajouter un article</button>
        </div>
        <div class="table-responsive">
            <table class="table align-middle mb-0" id="order-lines-table">
                <thead>
                    <tr>
                        <th style="min-width:200px">Produit * &amp; catégorie</th>
                        <th style="min-width:160px">Lien article</th>
                        <th style="width:90px" class="text-end">Qté *</th>
                        <th style="width:130px" class="text-end">Prix unitaire (<span class="o-currency-symbol"><?= e($sym) ?></span>)</th>
                        <th style="width:120px" class="text-end">Poids unit. (g)</th>
                        <th style="width:170px" class="text-end">Port réparti → coût unit.</th>
                        <th style="width:44px"></th>
                    </tr>
                </thead>
                <tbody id="order-lines">
                <?php foreach ($oldLines as $l): ?>
                    <tr class="order-line">
                        <td>
                            <input type="text" name="line_product[]" list="products-list" class="form-control form-control-sm" placeholder="Nom du produit (existant ou nouveau)" value="<?= e($l['product']) ?>">
                            <input type="text" name="line_category[]" list="categories-list" class="form-control form-control-sm mt-1" placeholder="Catégorie" value="<?= e($l['category']) ?>">
                        </td>
                        <td><input type="url" name="line_url[]" class="form-control form-control-sm" placeholder="https://…" value="<?= e($l['url']) ?>"></td>
                        <td><input type="number" name="line_qty[]" min="1" step="1" class="form-control form-control-sm text-end line-qty" value="<?= e($l['qty']) ?>"></td>
                        <td><input type="number" name="line_price[]" min="0" step="0.01" class="form-control form-control-sm text-end line-price" value="<?= e($l['price']) ?>"></td>
                        <td><input type="number" name="line_weight[]" min="0" step="1" class="form-control form-control-sm text-end line-weight" value="<?= e($l['weight']) ?>"></td>
                        <td class="text-end">
                            <span class="line-alloc text-muted d-block" style="font-size:.74rem">—</span>
                            <span class="line-unit fw-bold" style="font-size:.85rem">—</span>
                        </td>
                        <td class="text-center">
                            <button type="button" class="btn btn-sm btn-outline-danger remove-line" title="Retirer"><i class="bi bi-x-lg"></i></button>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
                <tfoot>
                    <tr style="background:#fafbfe">
                        <td colspan="2" class="text-muted" style="font-size:.8rem">
                            <i class="bi bi-info-circle me-1"></i>Produit inconnu = créé automatiquement dans votre catalogue.
                        </td>
                        <td class="text-end fw-bold" id="o-total-units">—</td>
                        <td class="text-end fw-bold" id="o-total-price">—</td>
                        <td class="text-end fw-bold" id="o-total-weight">—</td>
                        <td class="text-end fw-bold profit-positive" id="o-total-eur">—</td>
                        <td></td>
                    </tr>
                </tfoot>
            </table>
        </div>
    </div>

    <datalist id="products-list">
        <?php foreach ($productNames as $n): ?><option value="<?= e($n) ?>"></option><?php endforeach; ?>
    </datalist>
    <datalist id="categories-list">
        <?php foreach ($categories as $c): ?><option value="<?= e($c) ?>"></option><?php endforeach; ?>
    </datalist>

    <template id="line-template">
        <tr class="order-line">
            <td>
                <input type="text" name="line_product[]" list="products-list" class="form-control form-control-sm" placeholder="Nom du produit (existant ou nouveau)">
                <input type="text" name="line_category[]" list="categories-list" class="form-control form-control-sm mt-1" placeholder="Catégorie">
            </td>
            <td><input type="url" name="line_url[]" class="form-control form-control-sm" placeholder="https://…"></td>
            <td><input type="number" name="line_qty[]" min="1" step="1" class="form-control form-control-sm text-end line-qty"></td>
            <td><input type="number" name="line_price[]" min="0" step="0.01" class="form-control form-control-sm text-end line-price"></td>
            <td><input type="number" name="line_weight[]" min="0" step="1" class="form-control form-control-sm text-end line-weight"></td>
            <td class="text-end">
                <span class="line-alloc text-muted d-block" style="font-size:.74rem">—</span>
                <span class="line-unit fw-bold" style="font-size:.85rem">—</span>
            </td>
            <td class="text-center">
                <button type="button" class="btn btn-sm btn-outline-danger remove-line" title="Retirer"><i class="bi bi-x-lg"></i></button>
            </td>
        </tr>
    </template>

    <div class="preview-panel d-flex align-items-center justify-content-between flex-wrap gap-2 mb-3">
        <div>
            <div class="pv-label">Coût total de la commande (articles + port + douane)</div>
            <div class="pv-value" id="o-grand-total">—</div>
        </div>
        <div class="pv-note text-end">
            <i class="bi bi-shield-check me-1"></i>Répartition et coûts unitaires recalculés côté serveur<br>(le serveur fait foi)
        </div>
    </div>

    <div class="d-flex gap-2">
        <button class="btn btn-primary" type="submit">
            <i class="bi bi-check-lg me-1"></i><?= $isEdit ? 'Enregistrer les modifications' : 'Enregistrer la commande' ?>
        </button>
        <a href="<?= $isEdit ? '/orders/' . (int) $order['id'] : '/orders' ?>" class="btn btn-outline-secondary">Annuler</a>
    </div>
</form>
