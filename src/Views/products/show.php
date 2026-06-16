<?php
/** @var array $product @var array $purchases @var array $sales
 *  @var int $stock @var float $cump @var float $stockValue @var float $profit
 *  @var float $roi @var ?int $avgDays @var int $purchasedQty @var int $soldQty */
$pid = (int) $product['id'];
?>
<div class="page-header">
    <nav style="font-size:.8rem" class="mb-2">
        <a href="/products" class="text-muted"><i class="bi bi-arrow-left me-1"></i>Produits</a>
    </nav>
    <div class="d-flex flex-wrap align-items-center justify-content-between gap-3">
        <div class="d-flex align-items-center gap-3">
            <?php if (!empty($product['image_path'])): ?>
                <img src="<?= e($product['image_path']) ?>" alt="" style="width:64px;height:64px;object-fit:cover;border-radius:14px;border:1px solid var(--rt-border)">
            <?php else: ?>
                <span class="product-thumb-placeholder" style="width:64px;height:64px;font-size:1.5rem"><i class="bi bi-box-seam"></i></span>
            <?php endif; ?>
            <div>
                <h1 class="page-title mb-0"><?= e($product['name']) ?></h1>
                <div class="d-flex align-items-center gap-2 mt-1">
                    <?php if (!empty($product['category'])): ?>
                        <span class="badge-soft badge-neutral"><?= e($product['category']) ?></span>
                    <?php endif; ?>
                    <?php if ($stock <= 0): ?>
                        <span class="badge-soft badge-danger">Épuisé</span>
                    <?php elseif ($stock <= 2): ?>
                        <span class="badge-soft badge-warning">Stock faible</span>
                    <?php else: ?>
                        <span class="badge-soft badge-success">En stock</span>
                    <?php endif; ?>
                </div>
                <?php $rating = $product['rating'] !== null ? (int) $product['rating'] : 0; ?>
                <form method="post" action="/products/<?= $pid ?>/rate" id="quick-rate-form" class="d-inline-flex align-items-center gap-1 mt-1">
                    <?= \App\Core\Csrf::field() ?>
                    <div class="star-rating" data-star-rating data-star-submit>
                        <input type="hidden" name="rating" value="<?= $rating ?: '' ?>">
                        <?php for ($i = 1; $i <= 5; $i++): ?>
                            <button type="submit" class="star-btn" data-value="<?= $i ?>" aria-label="Noter <?= $i ?>/5"><i class="bi <?= $i <= $rating ? 'bi-star-fill' : 'bi-star' ?>"></i></button>
                        <?php endfor; ?>
                        <button type="submit" class="star-clear btn btn-sm btn-link text-muted px-1" data-star-clear-submit aria-label="Retirer la note">Effacer</button>
                    </div>
                    <?php if ($rating === 0): ?><span class="text-muted" style="font-size:.78rem">noter</span><?php endif; ?>
                </form>
            </div>
        </div>
        <div class="d-flex flex-wrap gap-2">
            <?php if (($reorder ?? null) !== null): ?>
                <a href="<?= e($reorder['url']) ?>" target="_blank" rel="noopener" class="btn btn-reorder btn-sm">
                    <i class="bi bi-arrow-repeat me-1"></i>Racheter<?= $reorder['supplier'] ? ' chez ' . e($reorder['supplier']) : '' ?>
                    <i class="bi bi-box-arrow-up-right ms-1" style="font-size:.68rem"></i>
                </a>
            <?php endif; ?>
            <a href="/purchases/create?product_id=<?= $pid ?>" class="btn btn-outline-secondary btn-sm"><i class="bi bi-bag-plus me-1"></i>Nouvel achat</a>
            <a href="/sales/create?product_id=<?= $pid ?>" class="btn btn-outline-secondary btn-sm"><i class="bi bi-cash-coin me-1"></i>Nouvelle vente</a>
            <a href="/products/<?= $pid ?>/edit" class="btn btn-primary btn-sm"><i class="bi bi-pencil me-1"></i>Modifier</a>
        </div>
    </div>
    <?php if (!empty($product['description'])): ?>
        <p class="page-sub mt-2 mb-0" style="max-width:640px"><?= e($product['description']) ?></p>
    <?php endif; ?>
    <?php if (!empty($product['rating_note'])): ?>
        <p class="page-sub mt-2 mb-0" style="max-width:640px"><i class="bi bi-chat-left-quote me-1 text-muted"></i><?= e($product['rating_note']) ?></p>
    <?php endif; ?>
</div>

<div class="row g-3 mb-4">
    <div class="col-6 col-md-4 col-xl-2">
        <div class="card kpi-card h-100"><div class="card-body py-3">
            <div class="kpi-label">Stock</div>
            <div class="kpi-value"><?= (int) $stock ?></div>
            <div class="kpi-sub"><?= (int) $purchasedQty ?> achetés · <?= (int) $soldQty ?> vendus</div>
        </div></div>
    </div>
    <div class="col-6 col-md-4 col-xl-2">
        <div class="card kpi-card h-100"><div class="card-body py-3">
            <div class="kpi-label">CUMP</div>
            <div class="kpi-value"><?= money($cump, 2) ?></div>
            <div class="kpi-sub">coût moyen / unité</div>
        </div></div>
    </div>
    <div class="col-6 col-md-4 col-xl-2">
        <div class="card kpi-card h-100"><div class="card-body py-3">
            <div class="kpi-label">Valeur stock</div>
            <div class="kpi-value"><?= money($stockValue) ?></div>
            <div class="kpi-sub">stock × CUMP</div>
        </div></div>
    </div>
    <div class="col-6 col-md-4 col-xl-2">
        <div class="card kpi-card h-100"><div class="card-body py-3">
            <div class="kpi-label">Bénéfice</div>
            <div class="kpi-value <?= $profit >= 0 ? 'profit-positive' : 'profit-negative' ?>"><?= money($profit) ?></div>
            <div class="kpi-sub">cumulé</div>
        </div></div>
    </div>
    <div class="col-6 col-md-4 col-xl-2">
        <div class="card kpi-card h-100"><div class="card-body py-3">
            <div class="kpi-label">ROI</div>
            <div class="kpi-value <?= $roi >= 0 ? 'profit-positive' : 'profit-negative' ?>"><?= pct($roi) ?></div>
            <div class="kpi-sub">sur unités vendues</div>
        </div></div>
    </div>
    <div class="col-6 col-md-4 col-xl-2">
        <div class="card kpi-card h-100"><div class="card-body py-3">
            <div class="kpi-label">Délai de vente</div>
            <div class="kpi-value"><?= $avgDays !== null ? $avgDays . ' j' : '—' ?></div>
            <div class="kpi-sub">moyen depuis le 1er lot</div>
        </div></div>
    </div>
</div>

<?php
$marketNew = isset($product['market_price_new']) && $product['market_price_new'] !== null ? (float) $product['market_price_new'] : null;
$marketUsed = isset($product['market_price_used']) && $product['market_price_used'] !== null ? (float) $product['market_price_used'] : null;
$hasCost = $purchasedQty > 0;
$qname = urlencode($product['name']);
$mkRow = static function (?float $price, float $cump, bool $hasCost): array {
    if ($price === null) {
        return ['—', null, null];
    }
    if (!$hasCost) {
        return [money($price), null, null];
    }
    $pot = round($price - $cump, 2);
    $pct = $price > 0 ? round($pot / $price * 100, 1) : 0.0;
    return [money($price), $pot, $pct];
};
[$newTxt, $newPot, $newPct] = $mkRow($marketNew, $cump, $hasCost);
[$usedTxt, $usedPot, $usedPct] = $mkRow($marketUsed, $cump, $hasCost);
?>
<div class="card mb-3">
    <div class="card-header d-flex flex-wrap align-items-center justify-content-between gap-2">
        <span><i class="bi bi-graph-up me-2 text-muted"></i>Comparatif marché</span>
        <div class="d-flex flex-wrap gap-2 align-items-center">
            <span class="text-muted fw-normal" style="font-size:.75rem">Vérifier :</span>
            <a class="badge-soft badge-primary text-decoration-none" target="_blank" rel="noopener" href="https://www.vinted.fr/catalog?search_text=<?= $qname ?>">Vinted</a>
            <a class="badge-soft badge-neutral text-decoration-none" target="_blank" rel="noopener" href="https://www.leboncoin.fr/recherche?text=<?= $qname ?>">Leboncoin</a>
            <a class="badge-soft badge-neutral text-decoration-none" target="_blank" rel="noopener" href="https://www.ebay.fr/sch/i.html?_nkw=<?= $qname ?>">eBay</a>
            <a class="badge-soft badge-neutral text-decoration-none" target="_blank" rel="noopener" href="https://www.amazon.fr/s?k=<?= $qname ?>">Amazon</a>
            <a class="badge-soft badge-neutral text-decoration-none" target="_blank" rel="noopener" href="https://www.google.com/search?tbm=shop&q=<?= $qname ?>">Google Shopping</a>
        </div>
    </div>
    <?php if ($marketNew === null && $marketUsed === null): ?>
        <div class="card-body d-flex flex-wrap align-items-center justify-content-between gap-2">
            <span class="text-muted" style="font-size:.85rem">
                Aucun prix de marché renseigné. Utilisez les liens ci-dessus pour vérifier les prix,
                puis enregistrez-les pour voir votre marge potentielle.
            </span>
            <a href="/products/<?= $pid ?>/edit" class="btn btn-sm btn-outline-primary"><i class="bi bi-pencil me-1"></i>Renseigner les prix</a>
        </div>
    <?php else: ?>
        <div class="stat-strip" style="grid-template-columns:repeat(3,1fr)">
            <div class="stat-cell">
                <div class="kpi-label">Mon coût (CUMP)</div>
                <div class="stat-num"><?= $hasCost ? money($cump, 2) : '—' ?></div>
                <div class="kpi-sub">prix payé + frais répartis</div>
            </div>
            <div class="stat-cell">
                <div class="kpi-label">Prix neuf constaté</div>
                <div class="stat-num"><?= $newTxt ?></div>
                <?php if ($newPot !== null): ?>
                    <div class="kpi-sub">
                        marge potentielle :
                        <span class="fw-bold <?= $newPot >= 0 ? 'profit-positive' : 'profit-negative' ?>">
                            <?= $newPot >= 0 ? '+' : '' ?><?= money($newPot) ?> / u (<?= number_format($newPct, 1, ',', ' ') ?> %)
                        </span>
                    </div>
                <?php endif; ?>
            </div>
            <div class="stat-cell">
                <div class="kpi-label">Prix revente constaté</div>
                <div class="stat-num"><?= $usedTxt ?></div>
                <?php if ($usedPot !== null): ?>
                    <div class="kpi-sub">
                        marge potentielle :
                        <span class="fw-bold <?= $usedPot >= 0 ? 'profit-positive' : 'profit-negative' ?>">
                            <?= $usedPot >= 0 ? '+' : '' ?><?= money($usedPot) ?> / u (<?= number_format($usedPct, 1, ',', ' ') ?> %)
                        </span>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    <?php endif; ?>
</div>

<div class="card mb-3">
    <div class="card-header d-flex flex-wrap align-items-center justify-content-between gap-2">
        <span><i class="bi bi-images me-2 text-muted"></i>Photos (<?= count($images) ?>/<?= (int) $maxPhotos ?>)</span>
        <form method="post" action="/products/<?= $pid ?>/images" enctype="multipart/form-data" class="d-flex align-items-center gap-2">
            <?= \App\Core\Csrf::field() ?>
            <input type="file" name="images[]" accept="image/*" multiple class="form-control form-control-sm" style="max-width:280px" required>
            <button class="btn btn-sm btn-primary" type="submit"><i class="bi bi-upload me-1"></i>Ajouter</button>
        </form>
    </div>
    <div class="card-body">
        <?php if (empty($images)): ?>
            <div class="text-muted d-flex align-items-center gap-2" style="font-size:.85rem">
                <i class="bi bi-image" style="font-size:1.2rem"></i>
                Aucune photo pour ce produit. Ajoutez-en plusieurs d'un coup (JPEG, PNG, WebP ou GIF, 2 Mo max chacune).
            </div>
        <?php else: ?>
            <div class="photo-grid">
                <?php foreach ($images as $img): $isCover = $product['image_path'] === $img['path']; ?>
                    <div class="photo-item <?= $isCover ? 'photo-item--cover' : '' ?>">
                        <img src="<?= e($img['path']) ?>" alt="" class="gallery-thumb" data-full="<?= e($img['path']) ?>">
                        <?php if ($isCover): ?>
                            <span class="photo-cover-badge"><i class="bi bi-star-fill"></i> Couverture</span>
                        <?php endif; ?>
                        <div class="photo-actions">
                            <?php if (!$isCover): ?>
                                <form method="post" action="/products/<?= $pid ?>/images/<?= (int) $img['id'] ?>/cover">
                                    <?= \App\Core\Csrf::field() ?>
                                    <button class="photo-btn" type="submit" title="Définir comme couverture"><i class="bi bi-star"></i></button>
                                </form>
                            <?php endif; ?>
                            <form method="post" action="/products/<?= $pid ?>/images/<?= (int) $img['id'] ?>/delete"
                                  data-confirm="Supprimer cette photo ?">
                                <?= \App\Core\Csrf::field() ?>
                                <button class="photo-btn photo-btn--danger" type="submit" title="Supprimer"><i class="bi bi-trash"></i></button>
                            </form>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- Visionneuse plein écran -->
<div class="modal fade" id="photo-modal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content bg-transparent border-0 shadow-none">
            <img src="" alt="" id="photo-modal-img" class="w-100 rounded-4" style="max-height:82vh;object-fit:contain;cursor:zoom-out" data-bs-dismiss="modal">
        </div>
    </div>
</div>

<div class="row g-3">
    <div class="col-xl-6">
        <div class="card h-100">
            <div class="card-header d-flex align-items-center justify-content-between">
                <span><i class="bi bi-bag-plus me-2 text-muted"></i>Lots d'achat (<?= count($purchases) ?>)</span>
                <a href="/purchases/create?product_id=<?= $pid ?>" class="fw-semibold" style="font-size:.8rem">+ Ajouter</a>
            </div>
            <?php if (empty($purchases)): ?>
                <div class="card-body text-muted small">Aucun lot enregistré pour ce produit.</div>
            <?php else: ?>
            <div class="table-responsive">
                <table class="table table-hover align-middle">
                    <thead><tr>
                        <th>Date</th>
                        <th class="text-end">Qté</th>
                        <th class="text-end">Coût total</th>
                        <th>Devise</th>
                        <th class="text-end">Coût unitaire</th>
                    </tr></thead>
                    <tbody>
                    <?php foreach ($purchases as $pu):
                        $sym = $pu['currency'] === 'USD' ? '$' : '€';
                        $lotTotal = (float) $pu['lot_price'] + (float) $pu['shipping_cost'] + (float) $pu['customs_cost'];
                    ?>
                        <tr>
                            <td class="text-nowrap text-muted"><?= dateFr($pu['purchased_at']) ?></td>
                            <td class="text-end"><span class="badge-soft badge-neutral">× <?= (int) $pu['quantity'] ?></span></td>
                            <td class="text-end"><?= number_format($lotTotal, 2, ',', ' ') . ' ' . $sym ?></td>
                            <td>
                                <?php if ($pu['currency'] === 'USD'): ?>
                                    <span class="badge-soft badge-warning">USD</span>
                                <?php else: ?>
                                    <span class="badge-soft badge-neutral">EUR</span>
                                <?php endif; ?>
                            </td>
                            <td class="text-end fw-bold"><?= money($pu['unit_cost_eur'], 2) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <div class="col-xl-6">
        <div class="card h-100">
            <div class="card-header d-flex align-items-center justify-content-between">
                <span><i class="bi bi-cash-coin me-2 text-muted"></i>Ventes (<?= count($sales) ?>)</span>
                <a href="/sales/create?product_id=<?= $pid ?>" class="fw-semibold" style="font-size:.8rem">+ Ajouter</a>
            </div>
            <?php if (empty($sales)): ?>
                <div class="card-body text-muted small">Aucune vente enregistrée pour ce produit.</div>
            <?php else: ?>
            <div class="table-responsive">
                <table class="table table-hover align-middle">
                    <thead><tr>
                        <th>Date</th>
                        <th class="text-end">Qté</th>
                        <th class="text-end">Prix</th>
                        <th class="text-end">Marge nette</th>
                        <th>Plateforme</th>
                    </tr></thead>
                    <tbody>
                    <?php foreach ($sales as $s): $pos = (float) $s['net_margin_eur'] >= 0; ?>
                        <tr>
                            <td class="text-nowrap text-muted"><?= dateFr($s['sold_at']) ?></td>
                            <td class="text-end"><span class="badge-soft badge-neutral">× <?= (int) $s['quantity'] ?></span></td>
                            <td class="text-end"><?= money($s['sale_price']) ?></td>
                            <td class="text-end">
                                <span class="fw-bold <?= $pos ? 'profit-positive' : 'profit-negative' ?>"><?= $pos ? '+' : '' ?><?= money($s['net_margin_eur']) ?></span>
                                <div class="text-muted" style="font-size:.72rem"><?= pct($s['margin_pct']) ?></div>
                            </td>
                            <td><span class="badge-soft badge-primary"><?= e($s['platform']) ?></span></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>
