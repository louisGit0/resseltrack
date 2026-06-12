<?php
/** @var array|null $purchase @var array $products @var array $errors @var array $old */
$isEdit = $purchase !== null;
$action = $isEdit ? '/purchases/' . (int) $purchase['id'] : '/purchases';
$val = static fn(string $k, $def = '') => $old[$k] ?? ($purchase[$k] ?? $def);
$cur = $val('currency', 'EUR');
$sym = cur_sym($cur);
?>
<div class="page-header">
    <nav style="font-size:.8rem" class="mb-1">
        <a href="/purchases" class="text-muted"><i class="bi bi-arrow-left me-1"></i>Achats</a>
    </nav>
    <h1 class="page-title"><?= $isEdit ? 'Modifier l\'achat' : 'Nouvel achat' ?></h1>
    <p class="page-sub">Un achat = un lot. Le coût unitaire EUR est calculé puis figé à l'enregistrement.</p>
</div>

<?php if (empty($products)): ?>
    <div class="card">
        <div class="empty-state">
            <div class="es-icon"><i class="bi bi-box-seam"></i></div>
            <h3>Créez d'abord un produit</h3>
            <p>Un achat doit être rattaché à un produit de votre catalogue.</p>
            <a href="/products/create" class="btn btn-primary"><i class="bi bi-plus-lg me-1"></i>Créer un produit</a>
        </div>
    </div>
<?php else: ?>
<div class="row justify-content-center">
    <div class="col-xl-9">
        <form method="post" action="<?= e($action) ?>" id="purchase-form" novalidate>
            <?= \App\Core\Csrf::field() ?>
            <div class="card mb-3">
                <div class="card-body p-4">
                    <div class="form-section-title">Lot</div>
                    <div class="row g-3 mb-4">
                        <div class="col-md-6">
                            <label class="form-label">Produit *</label>
                            <select name="product_id" id="product_id" class="form-select <?= isset($errors['product_id']) ? 'is-invalid' : '' ?>" required>
                                <option value="">— choisir —</option>
                                <?php foreach ($products as $p): ?>
                                    <option value="<?= (int) $p['id'] ?>" <?= (int) $val('product_id') === (int) $p['id'] ? 'selected' : '' ?>>
                                        <?= e($p['name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <?php if (isset($errors['product_id'])): ?><div class="invalid-feedback"><?= e($errors['product_id']) ?></div><?php endif; ?>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Quantité *</label>
                            <input type="number" min="1" step="1" name="quantity" id="quantity"
                                   class="form-control <?= isset($errors['quantity']) ? 'is-invalid' : '' ?>" value="<?= e($val('quantity')) ?>" required>
                            <?php if (isset($errors['quantity'])): ?><div class="invalid-feedback"><?= e($errors['quantity']) ?></div><?php endif; ?>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Date d'achat *</label>
                            <input type="date" name="purchased_at" class="form-control <?= isset($errors['purchased_at']) ? 'is-invalid' : '' ?>"
                                   value="<?= e($val('purchased_at', date('Y-m-d'))) ?>" required>
                            <?php if (isset($errors['purchased_at'])): ?><div class="invalid-feedback"><?= e($errors['purchased_at']) ?></div><?php endif; ?>
                        </div>
                    </div>

                    <div class="form-section-title">Coûts &amp; devise</div>
                    <div class="row g-3 mb-4">
                        <div class="col-md-3">
                            <label class="form-label">Prix du lot</label>
                            <div class="input-group">
                                <input type="number" min="0" step="0.01" name="lot_price" id="lot_price" class="form-control" value="<?= e($val('lot_price', '0')) ?>">
                                <span class="input-group-text currency-suffix"><?= e($sym) ?></span>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Frais de port</label>
                            <div class="input-group">
                                <input type="number" min="0" step="0.01" name="shipping_cost" id="shipping_cost" class="form-control" value="<?= e($val('shipping_cost', '0')) ?>">
                                <span class="input-group-text currency-suffix"><?= e($sym) ?></span>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Douane</label>
                            <div class="input-group">
                                <input type="number" min="0" step="0.01" name="customs_cost" id="customs_cost" class="form-control" value="<?= e($val('customs_cost', '0')) ?>">
                                <span class="input-group-text currency-suffix"><?= e($sym) ?></span>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Devise</label>
                            <select name="currency" id="currency" class="form-select">
                                <option value="EUR" <?= $cur === 'EUR' ? 'selected' : '' ?>>EUR (€)</option>
                                <option value="USD" <?= $cur === 'USD' ? 'selected' : '' ?>>USD ($)</option>
                                <option value="CNY" <?= $cur === 'CNY' ? 'selected' : '' ?>>CNY / Yuan (¥)</option>
                            </select>
                        </div>
                        <div class="col-md-6 d-none" id="rate-wrapper">
                            <label class="form-label">Taux de change <span id="rate-from"><?= e($cur === 'EUR' ? 'devise' : $cur) ?></span> → EUR</label>
                            <div class="input-group">
                                <input type="number" min="0" step="0.000001" name="exchange_rate" id="exchange_rate"
                                       class="form-control <?= isset($errors['exchange_rate']) ? 'is-invalid' : '' ?>" value="<?= e($val('exchange_rate', '')) ?>" placeholder="0.92">
                                <button type="button" class="btn btn-outline-primary" id="fetch-rate">
                                    <i class="bi bi-arrow-repeat me-1"></i>Taux du jour
                                </button>
                            </div>
                            <div class="form-text" id="rate-status">Le taux saisi est figé avec l'achat.</div>
                            <?php if (isset($errors['exchange_rate'])): ?><div class="text-danger" style="font-size:.8rem"><?= e($errors['exchange_rate']) ?></div><?php endif; ?>
                        </div>
                    </div>

                    <div class="form-section-title">Traçabilité</div>
                    <div class="row g-3">
                        <div class="col-md-5">
                            <label class="form-label">Fournisseur</label>
                            <input type="text" name="supplier" class="form-control" value="<?= e($val('supplier')) ?>" placeholder="AliExpress – nom de la boutique">
                        </div>
                        <div class="col-md-7">
                            <label class="form-label">URL de commande</label>
                            <input type="url" name="order_url" class="form-control" value="<?= e($val('order_url')) ?>" placeholder="https://…">
                        </div>
                    </div>
                </div>
            </div>

            <div class="preview-panel d-flex align-items-center justify-content-between flex-wrap gap-2 mb-3">
                <div>
                    <div class="pv-label">Coût unitaire estimé</div>
                    <div class="pv-value text-muted" id="unit-cost-output">—</div>
                </div>
                <div class="pv-note text-end">
                    <i class="bi bi-shield-check me-1"></i>Recalculé côté serveur à l'enregistrement<br>(le serveur fait foi)
                </div>
            </div>

            <div class="d-flex gap-2">
                <button class="btn btn-primary" type="submit">
                    <i class="bi bi-check-lg me-1"></i><?= $isEdit ? 'Enregistrer les modifications' : 'Créer l\'achat' ?>
                </button>
                <a href="/purchases" class="btn btn-outline-secondary">Annuler</a>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>
