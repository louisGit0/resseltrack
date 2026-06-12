<?php
/** @var array $purchases @var int $total @var int $units @var float $invested @var array $products
 *  @var string $q @var string $sort @var string $dir @var int $page @var int $pages
 *  @var int $productId @var ?string $from @var ?string $to */
$extra = ['product_id' => $productId ?: null, 'from' => $from, 'to' => $to, 'sort' => $sort];
$hasFilters = $q !== '' || $productId > 0 || $from !== null || $to !== null;
$avgUnit = $units > 0 ? $invested / $units : 0.0;

$sortOptions = [
    'date'     => 'Plus récents d\'abord',
    'unit'     => 'Coût unitaire (plus cher d\'abord)',
    'lot'      => 'Prix du lot',
    'quantity' => 'Quantité',
    'product'  => 'Produit (A → Z)',
];
?>
<div class="page-header d-flex flex-wrap align-items-end justify-content-between gap-3">
    <div>
        <div class="page-eyebrow">Approvisionnement</div>
        <h1 class="page-title">Achats</h1>
        <p class="page-sub">Chaque lot avec son coût de revient réel — prix, port réparti, douane et conversion</p>
    </div>
    <div class="d-flex gap-2">
        <a href="/export/purchases" class="btn btn-outline-secondary btn-sm"><i class="bi bi-download me-1"></i>Export CSV</a>
        <a href="/purchases/create" class="btn btn-primary btn-sm"><i class="bi bi-plus-lg me-1"></i>Nouvel achat</a>
    </div>
</div>

<div class="card mb-3">
    <div class="stat-strip">
        <div class="stat-cell">
            <div class="kpi-label">Lots</div>
            <div class="stat-num"><?= (int) $total ?></div>
            <div class="kpi-sub"><?= $hasFilters ? 'filtre actif' : 'enregistrés' ?></div>
        </div>
        <div class="stat-cell">
            <div class="kpi-label">Unités achetées</div>
            <div class="stat-num"><?= (int) $units ?></div>
            <div class="kpi-sub">toutes références</div>
        </div>
        <div class="stat-cell">
            <div class="kpi-label">Total investi</div>
            <div class="stat-num"><?= money($invested) ?></div>
            <div class="kpi-sub">lot + port + douane, en EUR</div>
        </div>
        <div class="stat-cell">
            <div class="kpi-label">Coût moyen / unité</div>
            <div class="stat-num"><?= money($avgUnit, 2) ?></div>
            <div class="kpi-sub">investi ÷ unités</div>
        </div>
    </div>
</div>

<form method="get" action="/purchases" class="card mb-3 filter-bar">
    <div class="card-body py-3 d-flex flex-wrap align-items-end gap-3">
        <div style="flex:1.8;min-width:170px">
            <label>Recherche</label>
            <div class="input-group input-group-sm">
                <span class="input-group-text"><i class="bi bi-search"></i></span>
                <input type="search" name="q" class="form-control" placeholder="Produit, fournisseur…" value="<?= e($q) ?>">
            </div>
        </div>
        <div style="flex:1.4;min-width:190px">
            <label>Trier par</label>
            <select name="sort" class="form-select form-select-sm">
                <?php foreach ($sortOptions as $key => $label): ?>
                    <option value="<?= e($key) ?>" <?= $sort === $key ? 'selected' : '' ?>><?= e($label) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div style="flex:1.3;min-width:160px">
            <label>Produit</label>
            <select name="product_id" class="form-select form-select-sm">
                <option value="">Tous</option>
                <?php foreach ($products as $p): ?>
                    <option value="<?= (int) $p['id'] ?>" <?= $productId === (int) $p['id'] ? 'selected' : '' ?>><?= e($p['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div style="min-width:135px">
            <label>Du</label>
            <input type="date" name="from" class="form-control form-control-sm" value="<?= e($from) ?>">
        </div>
        <div style="min-width:135px">
            <label>Au</label>
            <input type="date" name="to" class="form-control form-control-sm" value="<?= e($to) ?>">
        </div>
        <div class="d-flex gap-2">
            <button class="btn btn-primary btn-sm" type="submit"><i class="bi bi-funnel me-1"></i>Appliquer</button>
            <?php if ($hasFilters): ?>
                <a href="/purchases" class="btn btn-outline-secondary btn-sm">Réinitialiser</a>
            <?php endif; ?>
        </div>
    </div>
</form>

<?php if (empty($purchases)): ?>
    <div class="card">
        <div class="empty-state">
            <div class="es-icon"><i class="bi bi-bag-plus"></i></div>
            <?php if ($hasFilters): ?>
                <h3>Aucun achat ne correspond à ces filtres</h3>
                <p>Essayez d'élargir la période ou de réinitialiser les filtres.</p>
                <a href="/purchases" class="btn btn-outline-secondary">Réinitialiser les filtres</a>
            <?php else: ?>
                <h3>Aucun achat enregistré</h3>
                <p>Enregistrez vos lots (AliExpress ou autre) avec port, douane et devise : le coût unitaire est calculé automatiquement.</p>
                <a href="/purchases/create" class="btn btn-primary"><i class="bi bi-plus-lg me-1"></i>Enregistrer un achat</a>
            <?php endif; ?>
        </div>
    </div>
<?php else: ?>

<div class="row g-3">
    <?php foreach ($purchases as $p):
        $rate = (float) $p['exchange_rate'];
        $isForeign = $p['currency'] !== 'EUR';
        $sym = cur_sym($p['currency']);
        // Original-currency formatting (for the muted note) and EUR conversion.
        $orig = static fn($v) => number_format((float) $v, 2, ',', ' ') . ' ' . $sym;
        $eur = static fn($v) => money((float) $v * $rate);
        $lineTotalEur = (float) $p['unit_cost_eur'] * (int) $p['quantity'];
    ?>
    <div class="col-md-6 col-xl-4 d-flex">
        <div class="card pcard w-100">
            <div class="card-body d-flex flex-column">

                <!-- Identité -->
                <div class="d-flex align-items-start justify-content-between gap-2">
                    <div class="min-width-0">
                        <a href="/products/<?= (int) $p['product_id'] ?>" class="pcard-name d-block text-truncate"><?= e($p['product_name']) ?></a>
                        <div class="text-muted" style="font-size:.76rem"><i class="bi bi-calendar3 me-1"></i><?= dateFr($p['purchased_at']) ?></div>
                    </div>
                    <?php if ($isForeign): ?>
                        <span class="badge-soft badge-warning flex-shrink-0"><?= e($p['currency']) ?> × <?= e(rtrim(rtrim($p['exchange_rate'], '0'), '.')) ?></span>
                    <?php else: ?>
                        <span class="badge-soft badge-neutral flex-shrink-0">EUR</span>
                    <?php endif; ?>
                </div>

                <div class="d-flex flex-wrap gap-1 mt-2">
                    <span class="badge-soft badge-neutral">× <?= (int) $p['quantity'] ?> unité<?= (int) $p['quantity'] > 1 ? 's' : '' ?></span>
                    <?php if (!empty($p['weight_grams'])): ?>
                        <span class="badge-soft badge-neutral"><?= (int) $p['weight_grams'] ?> g / u</span>
                    <?php endif; ?>
                    <?php if (!empty($p['order_id'])): ?>
                        <a href="/orders/<?= (int) $p['order_id'] ?>" class="badge-soft badge-primary text-decoration-none" title="Voir la commande">
                            <i class="bi bi-receipt" style="font-size:.65rem"></i> Cde #<?= (int) $p['order_id'] ?>
                        </a>
                    <?php endif; ?>
                </div>

                <!-- Coût de revient -->
                <div class="dhero">
                    <span class="dhero-label">Coût de revient / unité</span>
                    <span class="dhero-value"><?= money($p['unit_cost_eur'], 2) ?></span>
                </div>

                <!-- Décomposition (en EUR) -->
                <div class="dbox">
                    <div class="drow">
                        <span class="dlabel">Prix du lot<?= $isForeign ? ' <span class="text-muted">(' . $orig($p['lot_price']) . ')</span>' : '' ?></span>
                        <span class="dval"><?= $eur($p['lot_price']) ?></span>
                    </div>
                    <div class="drow"><span class="dlabel">Frais de port<?= !empty($p['order_id']) ? ' (réparti)' : '' ?></span><span class="dval"><?= $eur($p['shipping_cost']) ?></span></div>
                    <?php if ((float) $p['customs_cost'] > 0): ?>
                        <div class="drow"><span class="dlabel">Douane<?= !empty($p['order_id']) ? ' (répartie)' : '' ?></span><span class="dval"><?= $eur($p['customs_cost']) ?></span></div>
                    <?php endif; ?>
                    <div class="drow drow--total"><span class="dlabel">Total du lot en EUR</span><span class="dval"><?= money($lineTotalEur) ?></span></div>
                </div>

                <!-- Pied -->
                <div class="pcard-footer mt-auto d-flex justify-content-between align-items-center gap-2">
                    <div class="min-width-0" style="font-size:.79rem">
                        <?php if (!empty($p['order_url'])): ?>
                            <a href="<?= e($p['order_url']) ?>" target="_blank" rel="noopener" class="text-truncate d-inline-block" style="max-width:150px">
                                <i class="bi bi-shop me-1"></i><?= e($p['supplier'] ?: 'Commande') ?> <i class="bi bi-box-arrow-up-right" style="font-size:.65rem"></i>
                            </a>
                        <?php elseif (!empty($p['supplier'])): ?>
                            <span class="text-muted text-truncate d-inline-block" style="max-width:150px"><i class="bi bi-shop me-1"></i><?= e($p['supplier']) ?></span>
                        <?php endif; ?>
                    </div>
                    <div class="d-flex gap-1 flex-shrink-0">
                        <a href="/purchases/<?= (int) $p['id'] ?>/edit" class="btn btn-sm btn-outline-secondary" title="Modifier"><i class="bi bi-pencil"></i></a>
                        <form method="post" action="/purchases/<?= (int) $p['id'] ?>/delete" class="d-inline"
                              data-confirm="Supprimer cet achat de <?= (int) $p['quantity'] ?> × <?= e($p['product_name']) ?> ?">
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
