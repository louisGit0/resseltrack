<?php /** @var array $user @var array $errors @var array $old */ ?>
<div class="page-header">
    <div class="page-eyebrow">Paramètres</div>
    <h1 class="page-title">Mon compte</h1>
    <p class="page-sub">Gérez vos informations personnelles et votre mot de passe</p>
</div>

<div class="row g-3 justify-content-center">
    <div class="col-lg-6">
        <div class="card h-100">
            <div class="card-header"><i class="bi bi-person me-2 text-muted"></i>Informations</div>
            <div class="card-body p-4">
                <form method="post" action="/profile" novalidate>
                    <?= \App\Core\Csrf::field() ?>
                    <div class="mb-3">
                        <label class="form-label">Nom</label>
                        <input type="text" name="name" class="form-control <?= isset($errors['name']) ? 'is-invalid' : '' ?>"
                               value="<?= e($old['name'] ?? $user['name']) ?>" required>
                        <?php if (isset($errors['name'])): ?><div class="invalid-feedback"><?= e($errors['name']) ?></div><?php endif; ?>
                    </div>
                    <div class="mb-4">
                        <label class="form-label">Email</label>
                        <input type="email" name="email" class="form-control <?= isset($errors['email']) ? 'is-invalid' : '' ?>"
                               value="<?= e($old['email'] ?? $user['email']) ?>" required>
                        <?php if (isset($errors['email'])): ?><div class="invalid-feedback"><?= e($errors['email']) ?></div><?php endif; ?>
                    </div>
                    <button class="btn btn-primary" type="submit"><i class="bi bi-check-lg me-1"></i>Enregistrer</button>
                </form>
            </div>
        </div>
    </div>

    <div class="col-lg-6">
        <div class="card h-100">
            <div class="card-header"><i class="bi bi-shield-lock me-2 text-muted"></i>Mot de passe</div>
            <div class="card-body p-4">
                <form method="post" action="/profile/password" novalidate>
                    <?= \App\Core\Csrf::field() ?>
                    <div class="mb-3">
                        <label class="form-label">Mot de passe actuel</label>
                        <input type="password" name="current_password" class="form-control <?= isset($errors['current_password']) ? 'is-invalid' : '' ?>" required>
                        <?php if (isset($errors['current_password'])): ?><div class="invalid-feedback"><?= e($errors['current_password']) ?></div><?php endif; ?>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Nouveau mot de passe</label>
                        <input type="password" name="new_password" class="form-control <?= isset($errors['new_password']) ? 'is-invalid' : '' ?>" required>
                        <?php if (isset($errors['new_password'])): ?><div class="invalid-feedback"><?= e($errors['new_password']) ?></div><?php endif; ?>
                        <div class="form-text">8 caractères minimum.</div>
                    </div>
                    <div class="mb-4">
                        <label class="form-label">Confirmer le nouveau mot de passe</label>
                        <input type="password" name="new_password_confirm" class="form-control <?= isset($errors['new_password_confirm']) ? 'is-invalid' : '' ?>" required>
                        <?php if (isset($errors['new_password_confirm'])): ?><div class="invalid-feedback"><?= e($errors['new_password_confirm']) ?></div><?php endif; ?>
                    </div>
                    <button class="btn btn-primary" type="submit"><i class="bi bi-key me-1"></i>Changer le mot de passe</button>
                </form>
            </div>
        </div>
    </div>

    <div class="col-12">
        <div class="card">
            <div class="card-body d-flex align-items-center justify-content-between flex-wrap gap-2 px-4">
                <div>
                    <div class="fw-semibold" style="font-size:.9rem">Membre depuis</div>
                    <div class="text-muted" style="font-size:.83rem"><?= dateFr(substr((string) $user['created_at'], 0, 10)) ?></div>
                </div>
                <form method="post" action="/logout">
                    <?= \App\Core\Csrf::field() ?>
                    <button class="btn btn-outline-danger btn-sm" type="submit"><i class="bi bi-box-arrow-right me-1"></i>Déconnexion</button>
                </form>
            </div>
        </div>
    </div>
</div>
