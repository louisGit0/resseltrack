<?php
/** @var float $revenue @var float $profit @var int $count @var float $avgMarginEur
 *  @var float $avgMarginPct @var float $stockValue @var int $stockUnits
 *  @var array $topProducts @var array $recentSales @var array $lowStock
 *  @var string $chartJson @var ?string $from @var ?string $to */

$today = date('Y-m-d');
$ranges = [
    '30 jours'  => ['from' => date('Y-m-d', strtotime('-30 days')), 'to' => $today],
    '3 mois'    => ['from' => date('Y-m-d', strtotime('-3 months')), 'to' => $today],
    'Année'     => ['from' => date('Y-01-01'), 'to' => $today],
];
$isAll = ($from === null && $to === null);
?>
<?php
$firstName = explode(' ', trim(\App\Core\Auth::name()))[0] ?: 'vous';
$moisFr = ['', 'janvier', 'février', 'mars', 'avril', 'mai', 'juin', 'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre'];
$todayFr = (int) date('j') . ' ' . $moisFr[(int) date('n')] . ' ' . date('Y');
?>
<div class="page-header d-flex flex-wrap align-items-end justify-content-between gap-3">
    <div>
        <div class="page-eyebrow">Tableau de bord · <?= e($todayFr) ?></div>
        <h1 class="page-title">Bonjour <?= e($firstName) ?> 👋</h1>
        <p class="page-sub">Voici l'état de votre activité d'achat-revente</p>
    </div>
    <div class="d-flex flex-wrap align-items-center gap-2">
        <a href="/dashboard" class="range-chip <?= $isAll ? 'active' : '' ?>">Tout</a>
        <?php foreach ($ranges as $label => $r):
            $active = ($from === $r['from'] && $to === $r['to']); ?>
            <a href="/dashboard?from=<?= e($r['from']) ?>&to=<?= e($r['to']) ?>" class="range-chip <?= $active ? 'active' : '' ?>"><?= e($label) ?></a>
        <?php endforeach; ?>
        <form method="get" action="/dashboard" class="d-flex align-items-center gap-2 ms-1">
            <input type="date" name="from" class="form-control form-control-sm" style="width:148px" value="<?= e($from) ?>">
            <span class="text-muted small">→</span>
            <input type="date" name="to" class="form-control form-control-sm" style="width:148px" value="<?= e($to) ?>">
            <button class="btn btn-sm btn-outline-secondary" type="submit"><i class="bi bi-funnel"></i></button>
        </form>
    </div>
</div>

<div class="row g-3 mb-4">
    <div class="col-6 col-xl-3">
        <div class="card kpi-card kpi-card--green h-100"><div class="card-body d-flex align-items-center gap-3">
            <span class="kpi-icon kpi-green"><i class="bi bi-graph-up-arrow"></i></span>
            <div>
                <div class="kpi-label">Bénéfice net</div>
                <div class="kpi-value <?= $profit >= 0 ? 'profit-positive' : 'profit-negative' ?>"><?= money($profit) ?></div>
            </div>
        </div></div>
    </div>
    <div class="col-6 col-xl-3">
        <div class="card kpi-card kpi-card--indigo h-100"><div class="card-body d-flex align-items-center gap-3">
            <span class="kpi-icon kpi-indigo"><i class="bi bi-cash-stack"></i></span>
            <div>
                <div class="kpi-label">Chiffre d'affaires</div>
                <div class="kpi-value"><?= money($revenue) ?></div>
                <div class="kpi-sub"><?= (int) $count ?> vente<?= $count > 1 ? 's' : '' ?></div>
            </div>
        </div></div>
    </div>
    <div class="col-6 col-xl-3">
        <div class="card kpi-card kpi-card--amber h-100"><div class="card-body d-flex align-items-center gap-3">
            <span class="kpi-icon kpi-amber"><i class="bi bi-percent"></i></span>
            <div>
                <div class="kpi-label">Marge moyenne</div>
                <div class="kpi-value"><?= money($avgMarginEur) ?></div>
                <div class="kpi-sub"><?= pct($avgMarginPct) ?> du CA</div>
            </div>
        </div></div>
    </div>
    <div class="col-6 col-xl-3">
        <div class="card kpi-card kpi-card--rose h-100"><div class="card-body d-flex align-items-center gap-3">
            <span class="kpi-icon kpi-rose"><i class="bi bi-boxes"></i></span>
            <div>
                <div class="kpi-label">Valeur du stock</div>
                <div class="kpi-value"><?= money($stockValue) ?></div>
                <div class="kpi-sub"><?= (int) $stockUnits ?> unité<?= $stockUnits > 1 ? 's' : '' ?> en stock</div>
            </div>
        </div></div>
    </div>
</div>

<div class="row g-3 mb-3">
    <div class="col-xl-8">
        <div class="card h-100">
            <div class="card-header d-flex align-items-center justify-content-between">
                <span><i class="bi bi-bar-chart-line me-2 text-muted"></i>Évolution sur 12 mois</span>
                <span class="text-muted fw-normal" style="font-size:.78rem">CA &amp; bénéfice mensuels</span>
            </div>
            <div class="card-body">
                <div style="position:relative;height:300px;">
                    <canvas id="monthly-chart"></canvas>
                </div>
                <script type="application/json" id="chart-data"><?= $chartJson ?></script>
            </div>
        </div>
    </div>
    <div class="col-xl-4">
        <div class="card h-100">
            <div class="card-header"><i class="bi bi-trophy me-2 text-muted"></i>Top produits par bénéfice</div>
            <?php if (empty($topProducts)): ?>
                <div class="card-body text-muted small">Aucune vente sur la période.</div>
            <?php else:
                $maxProfit = max(array_map(static fn($t) => max($t['profit'], 0.01), $topProducts)); ?>
                <div class="py-1">
                <?php foreach ($topProducts as $i => $tp): ?>
                    <div class="top-product">
                        <span class="rank"><?= $i + 1 ?></span>
                        <div class="flex-grow-1 min-width-0">
                            <div class="d-flex justify-content-between align-items-baseline gap-2">
                                <span class="fw-semibold text-truncate" style="font-size:.85rem"><?= e($tp['name']) ?></span>
                                <span class="fw-bold flex-shrink-0 <?= $tp['profit'] >= 0 ? 'profit-positive' : 'profit-negative' ?>" style="font-size:.85rem"><?= money($tp['profit']) ?></span>
                            </div>
                            <div class="progress">
                                <div class="progress-bar" style="width:<?= max(4, (int) round(max($tp['profit'], 0) / $maxProfit * 100)) ?>%"></div>
                            </div>
                            <div class="text-muted" style="font-size:.72rem"><?= (int) $tp['qty'] ?> unité<?= $tp['qty'] > 1 ? 's' : '' ?> vendue<?= $tp['qty'] > 1 ? 's' : '' ?></div>
                        </div>
                    </div>
                <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<div class="row g-3 mb-3">
    <div class="col-md-6 col-xl-4">
        <div class="card h-100">
            <div class="card-header"><i class="bi bi-pie-chart me-2 text-muted"></i>Bénéfice par catégorie</div>
            <div class="card-body">
                <div style="position:relative;height:210px;">
                    <canvas id="category-chart"></canvas>
                </div>
                <script type="application/json" id="category-data"><?= $categoryJson ?></script>
                <?php if (!empty($catNegative)): ?>
                    <div class="mt-2 pt-2" style="border-top:1px solid var(--rt-border)">
                        <?php foreach ($catNegative as $cn): ?>
                            <div class="d-flex justify-content-between" style="font-size:.78rem">
                                <span class="text-muted"><?= e($cn['category']) ?></span>
                                <span class="profit-negative fw-semibold"><?= money($cn['profit']) ?></span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <div class="col-md-6 col-xl-4">
        <div class="card h-100">
            <div class="card-header"><i class="bi bi-speedometer me-2 text-muted"></i>Performance</div>
            <ul class="list-group list-group-flush">
                <li class="list-group-item d-flex justify-content-between align-items-center py-3">
                    <div>
                        <div class="fw-semibold" style="font-size:.875rem">ROI</div>
                        <div class="text-muted" style="font-size:.75rem">bénéfice / coût des unités vendues</div>
                    </div>
                    <span class="kpi-value <?= $roi >= 0 ? 'profit-positive' : 'profit-negative' ?>" style="font-size:1.15rem"><?= pct($roi) ?></span>
                </li>
                <li class="list-group-item d-flex justify-content-between align-items-center py-3">
                    <div>
                        <div class="fw-semibold" style="font-size:.875rem">Délai moyen de vente</div>
                        <div class="text-muted" style="font-size:.75rem">entre le 1er lot et la vente</div>
                    </div>
                    <span class="kpi-value" style="font-size:1.15rem"><?= $avgDays !== null ? round($avgDays) . ' j' : '—' ?></span>
                </li>
                <li class="list-group-item d-flex justify-content-between align-items-center py-3">
                    <div>
                        <div class="fw-semibold" style="font-size:.875rem">Panier moyen</div>
                        <div class="text-muted" style="font-size:.75rem">CA / nombre de ventes</div>
                    </div>
                    <span class="kpi-value" style="font-size:1.15rem"><?= money($avgBasket) ?></span>
                </li>
            </ul>
        </div>
    </div>
    <div class="col-xl-4">
        <div class="card h-100">
            <div class="card-header"><i class="bi bi-exclamation-triangle me-2 text-muted"></i>Alertes stock</div>
            <?php if (empty($lowStock)): ?>
                <div class="card-body d-flex align-items-center gap-2 text-muted small">
                    <i class="bi bi-check-circle-fill" style="color:var(--rt-success)"></i>
                    Aucun produit en rupture imminente.
                </div>
            <?php else: ?>
                <ul class="list-group list-group-flush">
                <?php foreach ($lowStock as $ls): ?>
                    <li class="list-group-item d-flex justify-content-between align-items-center" style="font-size:.85rem">
                        <span class="fw-semibold"><?= e($ls['name']) ?></span>
                        <?php if ($ls['stock'] <= 0): ?>
                            <span class="badge-soft badge-danger">Épuisé</span>
                        <?php else: ?>
                            <span class="badge-soft badge-warning"><?= (int) $ls['stock'] ?> restant<?= $ls['stock'] > 1 ? 's' : '' ?></span>
                        <?php endif; ?>
                    </li>
                <?php endforeach; ?>
                </ul>
                <div class="card-body pt-2 pb-3">
                    <a href="/purchases/create" class="btn btn-sm btn-outline-primary w-100"><i class="bi bi-bag-plus me-1"></i>Réapprovisionner</a>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<div class="row g-3">
    <div class="col-12">
        <div class="card">
            <div class="card-header d-flex align-items-center justify-content-between">
                <span><i class="bi bi-clock-history me-2 text-muted"></i>Dernières ventes</span>
                <a href="/sales" class="fw-semibold" style="font-size:.8rem">Tout voir <i class="bi bi-arrow-right"></i></a>
            </div>
            <?php if (empty($recentSales)): ?>
                <div class="card-body text-muted small">
                    Aucune vente pour l'instant. <a href="/sales/create">Enregistrer une première vente</a>
                </div>
            <?php else: ?>
            <div class="table-responsive">
                <table class="table table-hover align-middle">
                    <thead><tr>
                        <th>Date</th><th>Produit</th>
                        <th class="text-end">Qté</th>
                        <th class="text-end">Prix</th>
                        <th class="text-end">Marge nette</th>
                    </tr></thead>
                    <tbody>
                    <?php foreach ($recentSales as $s): ?>
                        <tr>
                            <td class="text-nowrap text-muted"><?= dateFr($s['sold_at']) ?></td>
                            <td class="fw-semibold"><?= e($s['product_name']) ?></td>
                            <td class="text-end"><?= (int) $s['quantity'] ?></td>
                            <td class="text-end"><?= money($s['sale_price']) ?></td>
                            <td class="text-end fw-bold <?= (float) $s['net_margin_eur'] >= 0 ? 'profit-positive' : 'profit-negative' ?>">
                                <?= (float) $s['net_margin_eur'] >= 0 ? '+' : '' ?><?= money($s['net_margin_eur']) ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>
