<?php /** @var array $orders */ ?>
<div class="page-header d-flex flex-wrap align-items-end justify-content-between gap-3">
    <div>
        <div class="page-eyebrow">Approvisionnement</div>
        <h1 class="page-title">Commandes</h1>
        <p class="page-sub">Une commande fournisseur = plusieurs articles, frais de port répartis au prorata du poids</p>
    </div>
    <a href="/orders/create" class="btn btn-primary btn-sm"><i class="bi bi-plus-lg me-1"></i>Nouvelle commande</a>
</div>

<?php if (empty($orders)): ?>
    <div class="card">
        <div class="empty-state">
            <div class="es-icon"><i class="bi bi-receipt"></i></div>
            <h3>Aucune commande enregistrée</h3>
            <p>Saisissez une commande Superbuy / AliExpress avec tous ses articles : les frais de port sont
               automatiquement répartis selon le poids, et chaque article alimente vos achats et votre stock.</p>
            <a href="/orders/create" class="btn btn-primary"><i class="bi bi-plus-lg me-1"></i>Créer une commande</a>
        </div>
    </div>
<?php else: ?>

<div class="row g-3">
    <?php foreach ($orders as $o):
        $rate = (float) $o['exchange_rate'];
        $isForeign = $o['currency'] !== 'EUR';
        // Fees are stored in the order currency → report them in EUR.
        $eur = static fn($v) => money((float) $v * $rate);
        $units = (int) $o['units'];
        $avgUnit = $units > 0 ? (float) $o['total_eur'] / $units : 0.0;
        $feesTotal = (float) $o['shipping_cost'] + (float) $o['customs_cost'];
    ?>
    <div class="col-md-6 col-xl-4 d-flex">
        <div class="card pcard w-100">
            <div class="card-body d-flex flex-column">

                <!-- Identité -->
                <div class="d-flex align-items-start justify-content-between gap-2">
                    <div class="min-width-0">
                        <a href="/orders/<?= (int) $o['id'] ?>" class="pcard-name d-block text-truncate">
                            <?= e($o['supplier'] ?: 'Commande #' . (int) $o['id']) ?>
                        </a>
                        <div class="text-muted" style="font-size:.76rem">
                            <i class="bi bi-calendar3 me-1"></i><?= dateFr($o['ordered_at']) ?>
                            <?php if (!empty($o['order_url'])): ?>
                                · <a href="<?= e($o['order_url']) ?>" target="_blank" rel="noopener">voir la commande <i class="bi bi-box-arrow-up-right" style="font-size:.62rem"></i></a>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php if ($isForeign): ?>
                        <span class="badge-soft badge-warning flex-shrink-0"><?= e($o['currency']) ?> × <?= e(rtrim(rtrim($o['exchange_rate'], '0'), '.')) ?></span>
                    <?php else: ?>
                        <span class="badge-soft badge-neutral flex-shrink-0">EUR</span>
                    <?php endif; ?>
                </div>

                <div class="d-flex flex-wrap gap-1 mt-2">
                    <span class="badge-soft badge-primary"><?= (int) $o['line_count'] ?> article<?= (int) $o['line_count'] > 1 ? 's' : '' ?></span>
                    <span class="badge-soft badge-neutral">× <?= $units ?> unité<?= $units > 1 ? 's' : '' ?></span>
                    <?php if ((int) $o['total_weight'] > 0): ?>
                        <span class="badge-soft badge-neutral"><?= number_format((int) $o['total_weight'], 0, ',', ' ') ?> g</span>
                    <?php endif; ?>
                </div>

                <!-- Coût total -->
                <div class="dhero">
                    <span class="dhero-label">Coût total</span>
                    <span>
                        <span class="dhero-value"><?= money($o['total_eur']) ?></span>
                        <?php if ($units > 0): ?>
                            <span class="dhero-sub d-block text-end">soit <?= money($avgUnit, 2) ?> / unité</span>
                        <?php endif; ?>
                    </span>
                </div>

                <!-- Décomposition -->
                <div class="dbox">
                    <div class="drow"><span class="dlabel">Frais de port</span><span class="dval"><?= $eur($o['shipping_cost']) ?></span></div>
                    <?php if ((float) $o['customs_cost'] > 0): ?>
                        <div class="drow"><span class="dlabel">Douane</span><span class="dval"><?= $eur($o['customs_cost']) ?></span></div>
                    <?php endif; ?>
                    <div class="drow">
                        <span class="dlabel">Répartition</span>
                        <span class="dval" style="font-weight:500;color:var(--rt-muted)">
                            <?= (int) $o['total_weight'] > 0 ? 'au prorata du poids' : 'au prorata du prix' ?>
                        </span>
                    </div>
                    <div class="drow drow--total"><span class="dlabel">Port + douane</span><span class="dval"><?= $eur($feesTotal) ?></span></div>
                </div>

                <!-- Pied -->
                <div class="pcard-footer mt-auto d-flex justify-content-between align-items-center gap-2">
                    <div class="d-flex gap-1">
                        <a href="/orders/<?= (int) $o['id'] ?>" class="btn btn-sm btn-outline-primary"><i class="bi bi-eye me-1"></i>Détail</a>
                        <a href="/orders/<?= (int) $o['id'] ?>/edit" class="btn btn-sm btn-outline-secondary" title="Modifier"><i class="bi bi-pencil"></i></a>
                    </div>
                    <form method="post" action="/orders/<?= (int) $o['id'] ?>/delete" class="d-inline"
                          data-confirm="Supprimer cette commande et ses <?= (int) $o['line_count'] ?> ligne(s) d'achat ? Le stock correspondant sera retiré.">
                        <?= \App\Core\Csrf::field() ?>
                        <button class="btn btn-sm btn-outline-danger" type="submit" title="Supprimer"><i class="bi bi-trash"></i></button>
                    </form>
                </div>

            </div>
        </div>
    </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>
