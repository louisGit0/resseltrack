<?php
/** @var array $rows @var int $total @var int $aggUnits @var float $aggValue @var float $aggProfit
 *  @var string[] $categories @var string $q @var string $sort @var string $dir
 *  @var int $page @var int $pages @var int $perPage @var string $cat @var string $status */
$extra = ['cat' => $cat, 'status' => $status, 'sort' => $sort];
$hasFilters = $q !== '' || $cat !== '' || $status !== '';
$isRentabilitySort = str_starts_with($sort, 'rentability');
$rankOffset = ($page - 1) * $perPage;

$sortOptions = [
    'rentability'     => 'Rentabilité vs occasion (meilleure d\'abord)',
    'rentability_new' => 'Rentabilité vs neuf (meilleure d\'abord)',
    'profit'          => 'Bénéfice cumulé',
    'stock'           => 'Stock',
    'value'           => 'Valeur de stock',
    'name'            => 'Nom (A → Z)',
    'cump'            => 'Coût d\'achat (CUMP)',
];
?>
<div class="page-header d-flex flex-wrap align-items-end justify-content-between gap-3">
    <div>
        <div class="page-eyebrow">Catalogue</div>
        <h1 class="page-title">Produits</h1>
        <p class="page-sub">Coût d'achat réel comparé aux prix du marché — classés du plus rentable au moins rentable</p>
    </div>
    <div class="d-flex gap-2">
        <a href="/export/products" class="btn btn-outline-secondary btn-sm"><i class="bi bi-download me-1"></i>Export CSV</a>
        <a href="/products/create" class="btn btn-primary btn-sm"><i class="bi bi-plus-lg me-1"></i>Nouveau produit</a>
    </div>
</div>

<div class="card mb-3">
    <div class="stat-strip">
        <div class="stat-cell">
            <div class="kpi-label">Produits</div>
            <div class="stat-num"><?= (int) $total ?></div>
            <div class="kpi-sub"><?= $hasFilters ? 'filtre actif' : 'au catalogue' ?></div>
        </div>
        <div class="stat-cell">
            <div class="kpi-label">Unités en stock</div>
            <div class="stat-num"><?= (int) $aggUnits ?></div>
            <div class="kpi-sub">toutes références</div>
        </div>
        <div class="stat-cell">
            <div class="kpi-label">Valeur du stock</div>
            <div class="stat-num"><?= money($aggValue) ?></div>
            <div class="kpi-sub">au CUMP</div>
        </div>
        <div class="stat-cell">
            <div class="kpi-label">Bénéfice cumulé</div>
            <div class="stat-num <?= $aggProfit >= 0 ? 'profit-positive' : 'profit-negative' ?>"><?= money($aggProfit) ?></div>
            <div class="kpi-sub">sur les ventes passées</div>
        </div>
    </div>
</div>

<form method="get" action="/products" class="card mb-3 filter-bar">
    <div class="card-body py-3 d-flex flex-wrap align-items-end gap-3">
        <div style="flex:2;min-width:190px">
            <label>Recherche</label>
            <div class="input-group input-group-sm">
                <span class="input-group-text"><i class="bi bi-search"></i></span>
                <input type="search" name="q" class="form-control" placeholder="Nom, catégorie…" value="<?= e($q) ?>">
            </div>
        </div>
        <div style="flex:1.6;min-width:210px">
            <label>Trier par</label>
            <select name="sort" class="form-select form-select-sm">
                <?php foreach ($sortOptions as $key => $label): ?>
                    <option value="<?= e($key) ?>" <?= $sort === $key ? 'selected' : '' ?>><?= e($label) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div style="flex:1;min-width:140px">
            <label>Catégorie</label>
            <select name="cat" class="form-select form-select-sm">
                <option value="">Toutes</option>
                <?php foreach ($categories as $c): ?>
                    <option value="<?= e($c) ?>" <?= $cat === $c ? 'selected' : '' ?>><?= e($c) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div style="flex:1;min-width:130px">
            <label>État du stock</label>
            <select name="status" class="form-select form-select-sm">
                <option value="">Tous</option>
                <option value="in" <?= $status === 'in' ? 'selected' : '' ?>>En stock</option>
                <option value="low" <?= $status === 'low' ? 'selected' : '' ?>>Stock faible</option>
                <option value="out" <?= $status === 'out' ? 'selected' : '' ?>>Épuisé</option>
            </select>
        </div>
        <div class="d-flex gap-2">
            <button class="btn btn-primary btn-sm" type="submit"><i class="bi bi-funnel me-1"></i>Appliquer</button>
            <?php if ($hasFilters): ?>
                <a href="/products" class="btn btn-outline-secondary btn-sm">Réinitialiser</a>
            <?php endif; ?>
        </div>
    </div>
</form>

<?php if (empty($rows)): ?>
    <div class="card">
        <div class="empty-state">
            <div class="es-icon"><i class="bi bi-box-seam"></i></div>
            <?php if ($hasFilters): ?>
                <h3>Aucun produit ne correspond à ces filtres</h3>
                <p>Essayez d'élargir la recherche ou de réinitialiser les filtres.</p>
                <a href="/products" class="btn btn-outline-secondary">Réinitialiser les filtres</a>
            <?php else: ?>
                <h3>Aucun produit pour l'instant</h3>
                <p>Créez votre premier produit, puis enregistrez vos lots d'achat pour suivre stock et rentabilité.</p>
                <a href="/products/create" class="btn btn-primary"><i class="bi bi-plus-lg me-1"></i>Créer un produit</a>
            <?php endif; ?>
        </div>
    </div>
<?php else: ?>

<div class="row g-3">
    <?php foreach ($rows as $i => $r): $p = $r['product']; $pid = (int) $p['id']; ?>
    <div class="col-md-6 col-xl-4 d-flex">
        <div class="card pcard w-100">
            <div class="card-body d-flex flex-column">

                <!-- Identité -->
                <div class="d-flex align-items-start gap-2">
                    <a href="/products/<?= $pid ?>" class="flex-shrink-0">
                        <?php if (!empty($p['image_path'])): ?>
                            <img src="<?= e($p['image_path']) ?>" class="product-thumb" style="width:48px;height:48px" alt="">
                        <?php else: ?>
                            <span class="product-thumb-placeholder" style="width:48px;height:48px"><i class="bi bi-box-seam"></i></span>
                        <?php endif; ?>
                    </a>
                    <div class="min-width-0 flex-grow-1">
                        <a href="/products/<?= $pid ?>" class="pcard-name d-block text-truncate"><?= e($p['name']) ?></a>
                        <div class="d-flex flex-wrap gap-1 mt-1">
                            <?php if (!empty($p['category'])): ?>
                                <span class="badge-soft badge-neutral"><?= e($p['category']) ?></span>
                            <?php endif; ?>
                            <?php if ($r['stock'] <= 0): ?>
                                <span class="badge-soft badge-danger">Épuisé</span>
                            <?php elseif ($r['stock'] <= 2): ?>
                                <span class="badge-soft badge-warning">Stock : <?= (int) $r['stock'] ?></span>
                            <?php else: ?>
                                <span class="badge-soft badge-success">Stock : <?= (int) $r['stock'] ?></span>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php if ($isRentabilitySort): ?>
                        <span class="pcard-rank <?= ($rankOffset + $i) < 3 ? 'pcard-rank--top' : '' ?>">#<?= $rankOffset + $i + 1 ?></span>
                    <?php endif; ?>
                </div>

                <!-- Mon coût -->
                <div class="pcard-cost d-flex justify-content-between align-items-baseline">
                    <span class="kpi-label">Mon coût / unité (CUMP)</span>
                    <span class="pcard-cump"><?= $r['has_cost'] ? money($r['cump'], 2) : '—' ?></span>
                </div>

                <!-- Comparatif marché -->
                <div class="cmp-section">
                    <?php
                    $cmpRows = [
                        ['label' => 'Occasion', 'icon' => 'bi-arrow-repeat', 'price' => $r['market_used'], 'pot' => $r['pot_used'], 'pct' => $r['pct_used']],
                        ['label' => 'Neuf',     'icon' => 'bi-stars',        'price' => $r['market_new'],  'pot' => $r['pot_new'],  'pct' => $r['pct_new']],
                    ];
                    foreach ($cmpRows as $c): ?>
                        <div class="cmp-row">
                            <div class="cmp-head">
                                <span class="cmp-tag"><i class="bi <?= $c['icon'] ?>"></i> <?= $c['label'] ?></span>
                                <?php if ($c['price'] !== null): ?>
                                    <span class="cmp-price"><?= money($c['price']) ?></span>
                                <?php else: ?>
                                    <a href="/products/<?= $pid ?>/edit" class="cmp-missing">prix à renseigner</a>
                                <?php endif; ?>
                            </div>
                            <?php if ($c['pot'] !== null): ?>
                                <div class="cmp-result <?= $c['pot'] >= 0 ? 'profit-positive' : 'profit-negative' ?>">
                                    <?= $c['pot'] >= 0 ? '+' : '' ?><?= money($c['pot']) ?> / unité
                                    <span class="cmp-pct">· marge <?= number_format((float) $c['pct'], 1, ',', ' ') ?> %</span>
                                </div>
                                <div class="cmp-bar">
                                    <div class="cmp-bar-fill <?= $c['pot'] < 0 ? 'cmp-bar-fill--neg' : '' ?>"
                                         style="width:<?= min(100, max(4, abs((float) $c['pct']))) ?>%"></div>
                                </div>
                            <?php elseif ($c['price'] !== null && !$r['has_cost']): ?>
                                <div class="cmp-result text-muted" style="font-weight:500">aucun achat enregistré — marge inconnue</div>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>

                <!-- Lien fournisseur : racheter en un clic -->
                <?php if ($r['reorder'] !== null): ?>
                    <a href="<?= e($r['reorder']['url']) ?>" target="_blank" rel="noopener" class="btn btn-reorder mt-3">
                        <i class="bi bi-arrow-repeat me-1"></i>Racheter<?= $r['reorder']['supplier'] ? ' chez ' . e($r['reorder']['supplier']) : '' ?>
                        <i class="bi bi-box-arrow-up-right ms-1" style="font-size:.68rem"></i>
                    </a>
                <?php endif; ?>

                <!-- Pied -->
                <div class="pcard-footer mt-auto d-flex justify-content-between align-items-center">
                    <div>
                        <div class="kpi-label">Bénéfice réalisé</div>
                        <span class="fw-bold <?= $r['profit'] >= 0 ? 'profit-positive' : 'profit-negative' ?>" style="font-size:.9rem">
                            <?= $r['profit'] >= 0 ? '+' : '' ?><?= money($r['profit']) ?>
                        </span>
                    </div>
                    <div class="d-flex gap-1">
                        <a href="/products/<?= $pid ?>" class="btn btn-sm btn-outline-secondary" title="Fiche détaillée"><i class="bi bi-eye"></i></a>
                        <a href="/products/<?= $pid ?>/edit" class="btn btn-sm btn-outline-secondary" title="Modifier"><i class="bi bi-pencil"></i></a>
                        <form method="post" action="/products/<?= $pid ?>/delete" class="d-inline"
                              data-confirm="Supprimer « <?= e($p['name']) ?> » ainsi que tous ses achats et ventes ?">
                            <?= \App\Core\Csrf::field() ?>
                            <button class="btn btn-sm btn-outline-danger" type="submit" title="Supprimer"><i class="bi bi-trash"></i></button>
                        </form>
                    </div>
                </div>

            </div>
        </div>
    </div>
    <?php endforeach; ?>
</div>

<?php require __DIR__ . '/../partials/pagination.php'; ?>
<?php endif; ?>
