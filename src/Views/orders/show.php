<?php
/** @var array $order @var array $lines @var int $totalWeight @var float $totalEur @var int $totalUnits */
$rate = (float) $order['exchange_rate'];
$isForeign = $order['currency'] !== 'EUR';
// Amounts are stored in the order currency → always reported in EUR here.
$eur = static fn($v) => money((float) $v * $rate);
$orig = static fn($v) => number_format((float) $v, 2, ',', ' ') . ' ' . cur_sym($order['currency']);
?>
<div class="page-header">
    <nav style="font-size:.8rem" class="mb-1">
        <a href="/orders" class="text-muted"><i class="bi bi-arrow-left me-1"></i>Commandes</a>
    </nav>
    <div class="d-flex flex-wrap align-items-center justify-content-between gap-3">
        <div>
            <h1 class="page-title mb-0"><?= e($order['supplier'] ?: 'Commande #' . (int) $order['id']) ?></h1>
            <div class="d-flex align-items-center gap-2 mt-1">
                <span class="text-muted" style="font-size:.85rem"><i class="bi bi-calendar3 me-1"></i><?= dateFr($order['ordered_at']) ?></span>
                <?php if ($isForeign): ?>
                    <span class="badge-soft badge-warning"><?= e($order['currency']) ?> × <?= e(rtrim(rtrim($order['exchange_rate'], '0'), '.')) ?></span>
                <?php else: ?>
                    <span class="badge-soft badge-neutral">EUR</span>
                <?php endif; ?>
                <?php if (!empty($order['order_url'])): ?>
                    <a href="<?= e($order['order_url']) ?>" target="_blank" rel="noopener" style="font-size:.85rem">
                        Voir la commande <i class="bi bi-box-arrow-up-right" style="font-size:.7rem"></i>
                    </a>
                <?php endif; ?>
            </div>
        </div>
        <div class="d-flex gap-2">
            <a href="/orders/<?= (int) $order['id'] ?>/edit" class="btn btn-primary btn-sm"><i class="bi bi-pencil me-1"></i>Modifier</a>
            <form method="post" action="/orders/<?= (int) $order['id'] ?>/delete"
                  data-confirm="Supprimer cette commande et toutes ses lignes d'achat ? Le stock correspondant sera retiré.">
                <?= \App\Core\Csrf::field() ?>
                <button class="btn btn-outline-danger btn-sm" type="submit"><i class="bi bi-trash me-1"></i>Supprimer</button>
            </form>
        </div>
    </div>
</div>

<div class="card mb-3">
    <div class="stat-strip">
        <div class="stat-cell">
            <div class="kpi-label">Articles</div>
            <div class="stat-num"><?= count($lines) ?></div>
            <div class="kpi-sub"><?= (int) $totalUnits ?> unité<?= $totalUnits > 1 ? 's' : '' ?></div>
        </div>
        <div class="stat-cell">
            <div class="kpi-label">Poids total</div>
            <div class="stat-num"><?= $totalWeight > 0 ? number_format($totalWeight, 0, ',', ' ') . ' g' : '—' ?></div>
            <div class="kpi-sub">base de répartition du port</div>
        </div>
        <div class="stat-cell">
            <div class="kpi-label">Port + douane</div>
            <div class="stat-num"><?= $eur((float) $order['shipping_cost'] + (float) $order['customs_cost']) ?></div>
            <div class="kpi-sub">réparti au prorata du poids</div>
        </div>
        <div class="stat-cell">
            <div class="kpi-label">Coût total EUR</div>
            <div class="stat-num"><?= money($totalEur) ?></div>
            <div class="kpi-sub">articles + frais, converti</div>
        </div>
    </div>
</div>

<div class="card">
    <div class="card-header"><i class="bi bi-list-ul me-2 text-muted"></i>Lignes de la commande</div>
    <?php if (empty($lines)): ?>
        <div class="card-body text-muted small">Aucune ligne (commande vide).</div>
    <?php else: ?>
    <div class="table-responsive">
        <table class="table table-hover align-middle">
            <thead>
                <tr>
                    <th>Produit</th>
                    <th class="text-end">Qté</th>
                    <th class="text-end">Poids unit.</th>
                    <th class="text-end">Prix ligne €</th>
                    <th class="text-end">Port réparti €</th>
                    <th class="text-end">Douane €</th>
                    <th class="text-end">Coût unitaire €</th>
                    <th class="text-end">Total ligne €</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($lines as $l): ?>
                <tr>
                    <td>
                        <a href="/products/<?= (int) $l['product_id'] ?>" class="fw-semibold" style="color:inherit"><?= e($l['product_name']) ?></a>
                        <?php if (!empty($l['order_url'])): ?>
                            <a href="<?= e($l['order_url']) ?>" target="_blank" rel="noopener" class="ms-1" title="Lien article">
                                <i class="bi bi-box-arrow-up-right" style="font-size:.7rem"></i>
                            </a>
                        <?php endif; ?>
                        <?php if ($isForeign): ?>
                            <div class="text-muted" style="font-size:.72rem">payé <?= $orig($l['lot_price']) ?></div>
                        <?php endif; ?>
                    </td>
                    <td class="text-end"><span class="badge-soft badge-neutral">× <?= (int) $l['quantity'] ?></span></td>
                    <td class="text-end text-muted"><?= $l['weight_grams'] !== null ? (int) $l['weight_grams'] . ' g' : '—' ?></td>
                    <td class="text-end"><?= $eur($l['lot_price']) ?></td>
                    <td class="text-end text-muted"><?= $eur($l['shipping_cost']) ?></td>
                    <td class="text-end text-muted"><?= $eur($l['customs_cost']) ?></td>
                    <td class="text-end fw-bold"><?= money($l['unit_cost_eur'], 2) ?></td>
                    <td class="text-end"><?= money((float) $l['unit_cost_eur'] * (int) $l['quantity']) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>
