<?php
/** @var array|null $sale @var array $products @var array $errors @var array $old */
$isEdit = $sale !== null;
$action = $isEdit ? '/sales/' . (int) $sale['id'] : '/sales';
$val = static fn(string $k, $def = '') => $old[$k] ?? ($sale[$k] ?? $def);
?>
<div class="page-header">
    <nav style="font-size:.8rem" class="mb-1">
        <a href="/sales" class="text-muted"><i class="bi bi-arrow-left me-1"></i>Ventes</a>
    </nav>
    <h1 class="page-title"><?= $isEdit ? 'Modifier la vente' : 'Nouvelle vente' ?></h1>
    <p class="page-sub">Le CUMP et la marge sont figés à l'enregistrement : un achat ultérieur ne modifie jamais les marges passées.</p>
</div>

<?php if (empty($products)): ?>
    <div class="card">
        <div class="empty-state">
            <div class="es-icon"><i class="bi bi-box-seam"></i></div>
            <h3>Créez d'abord un produit</h3>
            <p>Une vente doit être rattachée à un produit ayant du stock (donc au moins un achat).</p>
            <a href="/products/create" class="btn btn-primary"><i class="bi bi-plus-lg me-1"></i>Créer un produit</a>
        </div>
    </div>
<?php else: ?>
<div class="row justify-content-center">
    <div class="col-xl-9">
        <form method="post" action="<?= e($action) ?>" id="sale-form" novalidate>
            <?= \App\Core\Csrf::field() ?>
            <div class="card mb-3">
                <div class="card-body p-4">
                    <div class="form-section-title">Vente</div>
                    <div class="row g-3 mb-4">
                        <div class="col-md-6">
                            <label class="form-label">Produit *</label>
                            <select name="product_id" id="product_id" class="form-select <?= isset($errors['product_id']) ? 'is-invalid' : '' ?>" required>
                                <option value="">— choisir —</option>
                                <?php foreach ($products as $p): ?>
                                    <option value="<?= (int) $p['id'] ?>"
                                            data-cump="<?= e(number_format((float) $p['cump'], 4, '.', '')) ?>"
                                            data-stock="<?= (int) $p['stock'] ?>"
                                            <?= (int) $val('product_id') === (int) $p['id'] ? 'selected' : '' ?>>
                                        <?= e($p['name']) ?> — stock : <?= (int) $p['stock'] ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <?php if (isset($errors['product_id'])): ?><div class="invalid-feedback"><?= e($errors['product_id']) ?></div><?php endif; ?>
                            <div class="form-text" id="stock-output"></div>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Quantité *</label>
                            <input type="number" min="1" step="1" name="quantity" id="quantity"
                                   class="form-control <?= isset($errors['quantity']) ? 'is-invalid' : '' ?>" value="<?= e($val('quantity')) ?>" required>
                            <?php if (isset($errors['quantity'])): ?><div class="invalid-feedback"><?= e($errors['quantity']) ?></div><?php endif; ?>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Date de vente *</label>
                            <input type="date" name="sold_at" class="form-control <?= isset($errors['sold_at']) ? 'is-invalid' : '' ?>"
                                   value="<?= e($val('sold_at', date('Y-m-d'))) ?>" required>
                            <?php if (isset($errors['sold_at'])): ?><div class="invalid-feedback"><?= e($errors['sold_at']) ?></div><?php endif; ?>
                        </div>
                    </div>

                    <div class="form-section-title">Prix &amp; frais</div>
                    <div class="row g-3">
                        <div class="col-md-3">
                            <label class="form-label">Prix de vente</label>
                            <div class="input-group">
                                <input type="number" min="0" step="0.01" name="sale_price" id="sale_price" class="form-control" value="<?= e($val('sale_price', '0')) ?>">
                                <span class="input-group-text">€</span>
                            </div>
                        </div>
                        <div class="col-6 col-md-2">
                            <label class="form-label">Emballage</label>
                            <div class="input-group">
                                <input type="number" min="0" step="0.01" name="packaging_cost" id="packaging_cost" class="form-control" value="<?= e($val('packaging_cost', '0')) ?>">
                                <span class="input-group-text">€</span>
                            </div>
                        </div>
                        <div class="col-6 col-md-2">
                            <label class="form-label">Étiquette</label>
                            <div class="input-group">
                                <input type="number" min="0" step="0.01" name="label_cost" id="label_cost" class="form-control" value="<?= e($val('label_cost', '0')) ?>">
                                <span class="input-group-text">€</span>
                            </div>
                        </div>
                        <div class="col-6 col-md-2">
                            <label class="form-label">Boost</label>
                            <div class="input-group">
                                <input type="number" min="0" step="0.01" name="boost_cost" id="boost_cost" class="form-control" value="<?= e($val('boost_cost', '0')) ?>">
                                <span class="input-group-text">€</span>
                            </div>
                        </div>
                        <div class="col-6 col-md-3">
                            <label class="form-label">Autres frais</label>
                            <div class="input-group">
                                <input type="number" min="0" step="0.01" name="other_cost" id="other_cost" class="form-control" value="<?= e($val('other_cost', '0')) ?>">
                                <span class="input-group-text">€</span>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Plateforme</label>
                            <input type="text" name="platform" list="platforms" class="form-control" value="<?= e($val('platform', 'Vinted')) ?>" placeholder="Vinted">
                            <datalist id="platforms">
                                <option value="Vinted"></option>
                                <option value="Leboncoin"></option>
                                <option value="eBay"></option>
                                <option value="Depop"></option>
                                <option value="Etsy"></option>
                                <option value="Facebook Marketplace"></option>
                            </datalist>
                        </div>
                    </div>
                </div>
            </div>

            <div class="preview-panel d-flex align-items-center justify-content-between flex-wrap gap-2 mb-3">
                <div>
                    <div class="pv-label">Marge nette prévisionnelle</div>
                    <div class="pv-value" id="margin-output">—</div>
                    <span class="pv-note" id="margin-pct-output"></span>
                </div>
                <div class="pv-note text-end">
                    <i class="bi bi-shield-check me-1"></i>CUMP &amp; marge figés à l'enregistrement<br>(le serveur fait foi)
                </div>
            </div>

            <div class="d-flex gap-2">
                <button class="btn btn-primary" type="submit">
                    <i class="bi bi-check-lg me-1"></i><?= $isEdit ? 'Enregistrer les modifications' : 'Créer la vente' ?>
                </button>
                <a href="/sales" class="btn btn-outline-secondary">Annuler</a>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>
