<?php
/** @var array $sales @var int $total @var int $units @var float $revenue @var float $margin
 *  @var float $marginPct @var array $products @var string $q @var string $sort @var string $dir
 *  @var int $page @var int $pages @var int $productId @var ?string $from @var ?string $to */
$extra = ['product_id' => $productId ?: null, 'from' => $from, 'to' => $to, 'sort' => $sort];
$hasFilters = $q !== '' || $productId > 0 || $from !== null || $to !== null;

$sortOptions = [
    'date'     => 'Plus récentes d\'abord',
    'margin'   => 'Marge nette (meilleure d\'abord)',
    'price'    => 'Prix de vente',
    'quantity' => 'Quantité',
    'product'  => 'Produit (A → Z)',
];
?>
<div class="page-header d-flex flex-wrap align-items-end justify-content-between gap-3">
    <div>
        <div class="page-eyebrow">Revenus</div>
        <h1 class="page-title">Ventes</h1>
        <p class="page-sub">Chaque vente avec sa marge décomposée — prix, frais, coût d'achat figé</p>
    </div>
    <div class="d-flex gap-2">
        <a href="/export/sales" class="btn btn-outline-secondary btn-sm"><i class="bi bi-download me-1"></i>Export CSV</a>
        <a href="/sales/create" class="btn btn-primary btn-sm"><i class="bi bi-plus-lg me-1"></i>Nouvelle vente</a>
    </div>
</div>

<div class="card mb-3">
    <div class="stat-strip">
        <div class="stat-cell">
            <div class="kpi-label">Ventes</div>
            <div class="stat-num"><?= (int) $total ?></div>
            <div class="kpi-sub"><?= (int) $units ?> unité<?= $units > 1 ? 's' : '' ?> · <?= $hasFilters ? 'filtre actif' : 'au total' ?></div>
        </div>
        <div class="stat-cell">
            <div class="kpi-label">Chiffre d'affaires</div>
            <div class="stat-num"><?= money($revenue) ?></div>
            <div class="kpi-sub">prix de vente cumulés</div>
        </div>
        <div class="stat-cell">
            <div class="kpi-label">Marge nette</div>
            <div class="stat-num <?= $margin >= 0 ? 'profit-positive' : 'profit-negative' ?>"><?= money($margin) ?></div>
            <div class="kpi-sub">après frais et coût d'achat</div>
        </div>
        <div class="stat-cell">
            <div class="kpi-label">Taux de marge</div>
            <div class="stat-num <?= $marginPct >= 0 ? 'profit-positive' : 'profit-negative' ?>"><?= pct($marginPct) ?></div>
            <div class="kpi-sub">marge ÷ CA</div>
        </div>
    </div>
</div>

<form method="get" action="/sales" class="card mb-3 filter-bar">
    <div class="card-body py-3 d-flex flex-wrap align-items-end gap-3">
        <div style="flex:1.8;min-width:170px">
            <label>Recherche</label>
            <div class="input-group input-group-sm">
                <span class="input-group-text"><i class="bi bi-search"></i></span>
                <input type="search" name="q" class="form-control" placeholder="Produit, plateforme…" value="<?= e($q) ?>">
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
                <a href="/sales" class="btn btn-outline-secondary btn-sm">Réinitialiser</a>
            <?php endif; ?>
        </div>
    </div>
</form>

<?php if (empty($sales)): ?>
    <div class="card">
        <div class="empty-state">
            <div class="es-icon"><i class="bi bi-cash-coin"></i></div>
            <?php if ($hasFilters): ?>
                <h3>Aucune vente ne correspond à ces filtres</h3>
                <p>Essayez d'élargir la période ou de réinitialiser les filtres.</p>
                <a href="/sales" class="btn btn-outline-secondary">Réinitialiser les filtres</a>
            <?php else: ?>
                <h3>Aucune vente enregistrée</h3>
                <p>Saisissez vos ventes Vinted avec leurs frais : la marge nette se calcule automatiquement à partir du coût moyen de vos lots.</p>
                <a href="/sales/create" class="btn btn-primary"><i class="bi bi-plus-lg me-1"></i>Enregistrer une vente</a>
            <?php endif; ?>
        </div>
    </div>
<?php else: ?>

<div class="row g-3">
    <?php foreach ($sales as $s):
        $positive = (float) $s['net_margin_eur'] >= 0;
        $fees = [
            'Emballage'   => (float) $s['packaging_cost'],
            'Étiquette'   => (float) $s['label_cost'],
            'Boost'       => (float) $s['boost_cost'],
            'Autres frais'=> (float) $s['other_cost'],
        ];
        $cost = (float) $s['unit_cost_eur'] * (int) $s['quantity'];
    ?>
    <div class="col-md-6 col-xl-4 d-flex">
        <div class="card pcard w-100">
            <div class="card-body d-flex flex-column">

                <!-- Identité -->
                <div class="d-flex align-items-start justify-content-between gap-2">
                    <div class="min-width-0">
                        <a href="/products/<?= (int) $s['product_id'] ?>" class="pcard-name d-block text-truncate"><?= e($s['product_name']) ?></a>
                        <div class="text-muted" style="font-size:.76rem"><i class="bi bi-calendar3 me-1"></i><?= dateFr($s['sold_at']) ?></div>
                    </div>
                    <span class="badge-soft badge-primary flex-shrink-0"><?= e($s['platform']) ?></span>
                </div>

                <div class="d-flex flex-wrap gap-1 mt-2">
                    <span class="badge-soft badge-neutral">× <?= (int) $s['quantity'] ?> unité<?= (int) $s['quantity'] > 1 ? 's' : '' ?></span>
                    <span class="badge-soft badge-neutral">CUMP figé : <?= money($s['unit_cost_eur'], 2) ?></span>
                </div>

                <!-- Marge -->
                <div class="dhero">
                    <span class="dhero-label">Marge nette</span>
                    <span>
                        <span class="dhero-value <?= $positive ? 'profit-positive' : 'profit-negative' ?>">
                            <?= $positive ? '+' : '' ?><?= money($s['net_margin_eur']) ?>
                        </span>
                        <span class="dhero-sub d-block text-end"><?= pct($s['margin_pct']) ?> du prix</span>
                    </span>
                </div>
                <div class="cmp-bar">
                    <div class="cmp-bar-fill <?= $positive ? '' : 'cmp-bar-fill--neg' ?>"
                         style="width:<?= min(100, max(4, abs((float) $s['margin_pct']))) ?>%"></div>
                </div>

                <!-- Décomposition -->
                <div class="dbox">
                    <div class="drow"><span class="dlabel">Prix de vente</span><span class="dval"><?= money($s['sale_price']) ?></span></div>
                    <?php foreach ($fees as $label => $amount): if ($amount > 0): ?>
                        <div class="drow"><span class="dlabel"><?= e($label) ?></span><span class="dval profit-negative">− <?= money($amount) ?></span></div>
                    <?php endif; endforeach; ?>
                    <div class="drow"><span class="dlabel">Coût d'achat (CUMP × <?= (int) $s['quantity'] ?>)</span><span class="dval profit-negative">− <?= money($cost) ?></span></div>
                    <div class="drow drow--total">
                        <span class="dlabel">Reste net</span>
                        <span class="dval <?= $positive ? 'profit-positive' : 'profit-negative' ?>"><?= $positive ? '+' : '' ?><?= money($s['net_margin_eur']) ?></span>
                    </div>
                </div>

                <!-- Pied -->
                <div class="pcard-footer mt-auto d-flex justify-content-end align-items-center gap-1">
                    <a href="/sales/<?= (int) $s['id'] ?>/duplicate" class="btn btn-sm btn-outline-secondary" title="Dupliquer"><i class="bi bi-copy"></i></a>
                    <a href="/sales/<?= (int) $s['id'] ?>/edit" class="btn btn-sm btn-outline-secondary" title="Modifier"><i class="bi bi-pencil"></i></a>
                    <form method="post" action="/sales/<?= (int) $s['id'] ?>/delete" class="d-inline"
                          data-confirm="Supprimer cette vente de <?= (int) $s['quantity'] ?> × <?= e($s['product_name']) ?> ?">
                        <?= \App\Core\Csrf::field() ?>
                        <button class="btn btn-sm btn-outline-danger" type="submit" title="Supprimer"><i class="bi bi-trash"></i></button>
                    </form>
                </div>

            </div>
        </div>
    </div>
    <?php endforeach; ?>
</div>

<?php require __DIR__ . '/../partials/pagination.php'; ?>
<?php endif; ?>
