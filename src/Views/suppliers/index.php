<?php /** @var array $suppliers */ ?>
<div class="page-header d-flex flex-wrap align-items-end justify-content-between gap-3">
    <div>
        <div class="page-eyebrow">Carnet</div>
        <h1 class="page-title">Fournisseurs</h1>
        <p class="page-sub">Vos fournisseurs et le nombre de commandes liées à chacun</p>
    </div>
    <a href="/suppliers/create" class="btn btn-primary btn-sm"><i class="bi bi-plus-lg me-1"></i>Nouveau fournisseur</a>
</div>

<?php if (empty($suppliers)): ?>
    <div class="card">
        <div class="empty-state">
            <div class="es-icon"><i class="bi bi-truck"></i></div>
            <h3>Aucun fournisseur pour l'instant</h3>
            <p>Ajoutez vos fournisseurs (boutiques, agents, places de marché) pour les retrouver et les lier à vos commandes.</p>
            <a href="/suppliers/create" class="btn btn-primary"><i class="bi bi-plus-lg me-1"></i>Ajouter un fournisseur</a>
        </div>
    </div>
<?php else: ?>

<div class="card">
    <div class="table-responsive">
        <table class="table align-middle mb-0">
            <thead>
                <tr>
                    <th>Nom</th>
                    <th>Lien</th>
                    <th style="width:130px">Note</th>
                    <th>Commentaire</th>
                    <th class="text-center" style="width:110px">Commandes</th>
                    <th class="text-end" style="width:110px"></th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($suppliers as $s): $sid = (int) $s['id']; ?>
                <tr>
                    <td class="fw-semibold"><?= e($s['name']) ?></td>
                    <td>
                        <?php if (!empty($s['url'])): ?>
                            <a href="<?= e($s['url']) ?>" target="_blank" rel="noopener"><?= e($s['url']) ?> <i class="bi bi-box-arrow-up-right" style="font-size:.7rem"></i></a>
                        <?php else: ?>
                            <span class="text-muted">—</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ($s['rating'] === null): ?>
                            <span class="text-muted">—</span>
                        <?php else: $rating = (int) $s['rating']; ?>
                            <span class="supplier-stars" title="<?= $rating ?>/5" aria-label="<?= $rating ?> sur 5">
                                <?php for ($i = 1; $i <= 5; $i++): ?>
                                    <i class="bi <?= $i <= $rating ? 'bi-star-fill' : 'bi-star' ?>"></i>
                                <?php endfor; ?>
                            </span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if (!empty($s['comment'])): ?>
                            <span class="text-muted"><?= e($s['comment']) ?></span>
                        <?php else: ?>
                            <span class="text-muted">—</span>
                        <?php endif; ?>
                    </td>
                    <td class="text-center"><span class="badge-soft badge-neutral"><?= (int) $s['orders_count'] ?></span></td>
                    <td class="text-end">
                        <div class="d-flex gap-1 justify-content-end">
                            <a href="/suppliers/<?= $sid ?>/edit" class="btn btn-sm btn-outline-secondary" title="Modifier"><i class="bi bi-pencil"></i></a>
                            <form method="post" action="/suppliers/<?= $sid ?>/delete" class="d-inline"
                                  data-confirm="Supprimer « <?= e($s['name']) ?> » ? Les commandes liées conserveront leur nom de fournisseur.">
                                <?= \App\Core\Csrf::field() ?>
                                <button class="btn btn-sm btn-outline-danger" type="submit" title="Supprimer"><i class="bi bi-trash"></i></button>
                            </form>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>
