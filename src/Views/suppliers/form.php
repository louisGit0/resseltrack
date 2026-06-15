<?php
/** @var array|null $supplier @var array $errors @var array $old */
$isEdit = $supplier !== null;
$action = $isEdit ? '/suppliers/' . (int) $supplier['id'] : '/suppliers';
$val = static fn(string $k, $def = '') => $old[$k] ?? ($supplier[$k] ?? $def);
?>
<div class="page-header">
    <nav style="font-size:.8rem" class="mb-1">
        <a href="/suppliers" class="text-muted"><i class="bi bi-arrow-left me-1"></i>Fournisseurs</a>
    </nav>
    <h1 class="page-title"><?= $isEdit ? 'Modifier le fournisseur' : 'Nouveau fournisseur' ?></h1>
    <p class="page-sub">Boutique, agent ou place de marché — réutilisable dans vos commandes.</p>
</div>

<div class="row justify-content-center">
    <div class="col-xl-7 col-lg-9">
        <form method="post" action="<?= e($action) ?>" id="supplier-form" novalidate>
            <?= \App\Core\Csrf::field() ?>
            <div class="card mb-3">
                <div class="card-body p-4">
                    <div class="row g-3">
                        <div class="col-md-7">
                            <label class="form-label">Nom *</label>
                            <input type="text" name="name" class="form-control <?= isset($errors['name']) ? 'is-invalid' : '' ?>"
                                   value="<?= e($val('name')) ?>" placeholder="Ex. : Superbuy, AliExpress…" required autofocus>
                            <?php if (isset($errors['name'])): ?><div class="invalid-feedback"><?= e($errors['name']) ?></div><?php endif; ?>
                        </div>
                        <div class="col-md-5">
                            <label class="form-label">URL</label>
                            <input type="url" name="url" class="form-control" value="<?= e($val('url')) ?>" placeholder="https://…">
                        </div>
                        <div class="col-12">
                            <label class="form-label d-block">Note</label>
                            <div class="star-rating" data-star-rating>
                                <input type="hidden" name="rating" value="<?= e($val('rating')) ?>">
                                <?php for ($i = 1; $i <= 5; $i++): ?>
                                    <button type="button" class="star-btn" data-value="<?= $i ?>" aria-label="<?= $i ?> étoile<?= $i > 1 ? 's' : '' ?>"><i class="bi bi-star"></i></button>
                                <?php endfor; ?>
                                <button type="button" class="star-clear btn btn-sm btn-link text-muted px-1" data-star-clear>Effacer</button>
                            </div>
                            <?php if (isset($errors['rating'])): ?><div class="text-danger" style="font-size:.8rem"><?= e($errors['rating']) ?></div><?php endif; ?>
                            <div class="form-text">Optionnel — cliquez sur une étoile pour noter de 1 à 5, « Effacer » pour retirer la note.</div>
                        </div>
                        <div class="col-12">
                            <label class="form-label">Commentaire</label>
                            <textarea name="comment" class="form-control" rows="3" placeholder="Notes internes : qualité, délais, contact…"><?= e($val('comment')) ?></textarea>
                        </div>
                    </div>
                </div>
            </div>

            <div class="d-flex gap-2">
                <button class="btn btn-primary" type="submit"><i class="bi bi-check-lg me-1"></i><?= $isEdit ? 'Enregistrer' : 'Créer le fournisseur' ?></button>
                <a href="/suppliers" class="btn btn-outline-secondary">Annuler</a>
            </div>
        </form>
    </div>
</div>
