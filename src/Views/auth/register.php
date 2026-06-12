<?php /** @var array $errors @var array $old */ ?>
<div class="auth-card">
    <div class="row g-0">
        <div class="col-md-5 d-none d-md-block">
            <div class="auth-brand-panel h-100">
                <span class="brand-icon"><i class="bi bi-box-seam-fill"></i></span>
                <h2>ResellTrack</h2>
                <p class="lead-sm">Créez votre compte et suivez votre rentabilité dès la première vente.</p>

                <div class="auth-feature">
                    <i class="bi bi-1-circle"></i>
                    <span>Créez vos produits et enregistrez vos lots d'achat</span>
                </div>
                <div class="auth-feature">
                    <i class="bi bi-2-circle"></i>
                    <span>Saisissez vos ventes — la marge se calcule toute seule</span>
                </div>
                <div class="auth-feature">
                    <i class="bi bi-3-circle"></i>
                    <span>Suivez bénéfice, stock et top produits sur le tableau de bord</span>
                </div>
            </div>
        </div>
        <div class="col-md-7">
            <div class="auth-form-panel">
                <h1 class="h4 mb-1">Créer un compte</h1>
                <p class="text-muted mb-4" style="font-size:.875rem">Gratuit, en moins d'une minute</p>

                <form method="post" action="/register" novalidate>
                    <?= \App\Core\Csrf::field() ?>
                    <div class="mb-3">
                        <label class="form-label">Nom</label>
                        <input type="text" name="name" class="form-control <?= isset($errors['name']) ? 'is-invalid' : '' ?>" value="<?= old($old, 'name') ?>" placeholder="Votre prénom" required>
                        <?php if (isset($errors['name'])): ?><div class="invalid-feedback"><?= e($errors['name']) ?></div><?php endif; ?>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Email</label>
                        <input type="email" name="email" class="form-control <?= isset($errors['email']) ? 'is-invalid' : '' ?>" value="<?= old($old, 'email') ?>" placeholder="vous@exemple.fr" required>
                        <?php if (isset($errors['email'])): ?><div class="invalid-feedback"><?= e($errors['email']) ?></div><?php endif; ?>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Mot de passe</label>
                        <input type="password" name="password" class="form-control <?= isset($errors['password']) ? 'is-invalid' : '' ?>" placeholder="8 caractères minimum" required>
                        <?php if (isset($errors['password'])): ?><div class="invalid-feedback"><?= e($errors['password']) ?></div><?php endif; ?>
                    </div>
                    <div class="mb-4">
                        <label class="form-label">Confirmer le mot de passe</label>
                        <input type="password" name="password_confirm" class="form-control <?= isset($errors['password_confirm']) ? 'is-invalid' : '' ?>" required>
                        <?php if (isset($errors['password_confirm'])): ?><div class="invalid-feedback"><?= e($errors['password_confirm']) ?></div><?php endif; ?>
                    </div>
                    <button class="btn btn-primary w-100 mb-3" type="submit">
                        Créer mon compte <i class="bi bi-arrow-right ms-1"></i>
                    </button>
                </form>

                <p class="text-center text-muted mb-0" style="font-size:.85rem">
                    Déjà inscrit ? <a href="/login" class="fw-semibold">Se connecter</a>
                </p>
            </div>
        </div>
    </div>
</div>
