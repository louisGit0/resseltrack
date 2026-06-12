<?php
/** @var array|null $product @var array $errors @var array $old */
$isEdit = $product !== null;
$action = $isEdit ? '/products/' . (int) $product['id'] : '/products';
$val = static fn(string $k, $def = '') => $old[$k] ?? ($product[$k] ?? $def);
?>
<div class="page-header">
    <nav style="font-size:.8rem" class="mb-1">
        <a href="/products" class="text-muted"><i class="bi bi-arrow-left me-1"></i>Produits</a>
    </nav>
    <h1 class="page-title"><?= $isEdit ? 'Modifier le produit' : 'Nouveau produit' ?></h1>
    <p class="page-sub">Le produit regroupe vos lots d'achat et vos ventes pour calculer stock et CUMP.</p>
</div>

<div class="row justify-content-center">
    <div class="col-xl-7 col-lg-9">
        <form method="post" action="<?= e($action) ?>" enctype="multipart/form-data" novalidate>
            <?= \App\Core\Csrf::field() ?>
            <div class="card mb-3">
                <div class="card-body p-4">
                    <div class="row g-3">
                        <div class="col-md-7">
                            <label class="form-label">Nom *</label>
                            <input type="text" name="name" class="form-control <?= isset($errors['name']) ? 'is-invalid' : '' ?>"
                                   value="<?= e($val('name')) ?>" placeholder="Ex. : Coque iPhone 15 silicone" required autofocus>
                            <?php if (isset($errors['name'])): ?><div class="invalid-feedback"><?= e($errors['name']) ?></div><?php endif; ?>
                        </div>
                        <div class="col-md-5">
                            <label class="form-label">Catégorie</label>
                            <input type="text" name="category" list="categories-list" class="form-control" value="<?= e($val('category')) ?>" placeholder="Choisir ou saisir…">
                            <datalist id="categories-list">
                                <?php foreach (($categories ?? []) as $c): ?><option value="<?= e($c) ?>"></option><?php endforeach; ?>
                            </datalist>
                        </div>
                        <div class="col-12">
                            <label class="form-label">Description</label>
                            <textarea name="description" class="form-control" rows="3" placeholder="Notes internes, références, variantes…"><?= e($val('description')) ?></textarea>
                        </div>
                        <div class="col-12">
                            <label class="form-label">Image de couverture</label>
                            <input type="file" name="image" class="form-control" accept="image/*">
                            <div class="form-text">La galerie multi-photos se gère depuis la fiche du produit (section « Photos »).</div>
                            <?php if ($isEdit && !empty($product['image_path'])): ?>
                                <div class="mt-2 d-flex align-items-center gap-2">
                                    <img src="<?= e($product['image_path']) ?>" class="product-thumb" alt="">
                                    <span class="text-muted" style="font-size:.8rem">Image actuelle — laisser vide pour la conserver</span>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="form-section-title mt-4">Prix du marché (comparatif)</div>
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">Prix constaté neuf</label>
                            <div class="input-group">
                                <input type="number" min="0" step="0.01" name="market_price_new"
                                       class="form-control <?= isset($errors['market_price_new']) ? 'is-invalid' : '' ?>"
                                       value="<?= e($val('market_price_new')) ?>" placeholder="—">
                                <span class="input-group-text">€</span>
                            </div>
                            <?php if (isset($errors['market_price_new'])): ?><div class="text-danger" style="font-size:.8rem"><?= e($errors['market_price_new']) ?></div><?php endif; ?>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Prix constaté en revente (Vinted &amp; co)</label>
                            <div class="input-group">
                                <input type="number" min="0" step="0.01" name="market_price_used"
                                       class="form-control <?= isset($errors['market_price_used']) ? 'is-invalid' : '' ?>"
                                       value="<?= e($val('market_price_used')) ?>" placeholder="—">
                                <span class="input-group-text">€</span>
                            </div>
                            <?php if (isset($errors['market_price_used'])): ?><div class="text-danger" style="font-size:.8rem"><?= e($errors['market_price_used']) ?></div><?php endif; ?>
                        </div>
                        <div class="col-12">
                            <div class="form-text">
                                Sert au calcul de la <strong>marge potentielle</strong> (prix marché − coût d'achat CUMP),
                                affichée dans la liste et la fiche produit.
                            </div>
                            <?php if ($isEdit): $qname = urlencode($product['name']); ?>
                                <div class="d-flex flex-wrap gap-2 mt-2">
                                    <span class="text-muted" style="font-size:.78rem">Vérifier les prix :</span>
                                    <a class="badge-soft badge-primary text-decoration-none" target="_blank" rel="noopener" href="https://www.vinted.fr/catalog?search_text=<?= $qname ?>">Vinted</a>
                                    <a class="badge-soft badge-neutral text-decoration-none" target="_blank" rel="noopener" href="https://www.leboncoin.fr/recherche?text=<?= $qname ?>">Leboncoin</a>
                                    <a class="badge-soft badge-neutral text-decoration-none" target="_blank" rel="noopener" href="https://www.ebay.fr/sch/i.html?_nkw=<?= $qname ?>">eBay</a>
                                    <a class="badge-soft badge-neutral text-decoration-none" target="_blank" rel="noopener" href="https://www.amazon.fr/s?k=<?= $qname ?>">Amazon</a>
                                    <a class="badge-soft badge-neutral text-decoration-none" target="_blank" rel="noopener" href="https://www.google.com/search?tbm=shop&q=<?= $qname ?>">Google Shopping</a>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>

            <div class="d-flex gap-2">
                <button class="btn btn-primary" type="submit">
                    <i class="bi bi-check-lg me-1"></i><?= $isEdit ? 'Enregistrer les modifications' : 'Créer le produit' ?>
                </button>
                <a href="/products" class="btn btn-outline-secondary">Annuler</a>
            </div>
        </form>
    </div>
</div>
