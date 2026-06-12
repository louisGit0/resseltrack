<?php /** @var array $errors @var array $old */ ?>
<div class="auth-card">
    <div class="row g-0">
        <div class="col-md-5 d-none d-md-block">
            <div class="auth-brand-panel h-100">
                <span class="brand-icon"><i class="bi bi-box-seam-fill"></i></span>
                <h2>ResellTrack</h2>
                <p class="lead-sm">Pilotez votre activité d'achat-revente comme un pro.</p>

                <div class="auth-feature">
                    <i class="bi bi-currency-exchange"></i>
                    <span>Achats en lots EUR/USD avec port, douane et taux de change figé</span>
                </div>
                <div class="auth-feature">
                    <i class="bi bi-graph-up-arrow"></i>
                    <span>Marge nette calculée à chaque vente, CUMP automatique</span>
                </div>
                <div class="auth-feature">
                    <i class="bi bi-boxes"></i>
                    <span>Stock suivi en temps réel, valeur d'inventaire et alertes</span>
                </div>
                <div class="auth-feature">
                    <i class="bi bi-filetype-csv"></i>
                    <span>Exports CSV compatibles Excel pour votre comptabilité</span>
                </div>
            </div>
        </div>
        <div class="col-md-7">
            <div class="auth-form-panel">
                <h1 class="h4 mb-1">Bon retour 👋</h1>
                <p class="text-muted mb-4" style="font-size:.875rem">Connectez-vous à votre espace</p>

                <?php if (!empty($errors['email'])): ?>
                    <div class="alert alert-danger py-2"><?= e($errors['email']) ?></div>
                <?php endif; ?>

                <form method="post" action="/login" novalidate>
                    <?= \App\Core\Csrf::field() ?>
                    <div class="mb-3">
                        <label class="form-label">Email</label>
                        <input type="email" name="email" class="form-control" value="<?= old($old, 'email') ?>" placeholder="vous@exemple.fr" required autofocus>
                    </div>
                    <div class="mb-4">
                        <label class="form-label">Mot de passe</label>
                        <input type="password" name="password" class="form-control" placeholder="••••••••" required>
                    </div>
                    <button class="btn btn-primary w-100 mb-3" type="submit">
                        Se connecter <i class="bi bi-arrow-right ms-1"></i>
                    </button>
                </form>

                <p class="text-center text-muted mb-3" style="font-size:.85rem">
                    Pas encore de compte ? <a href="/register" class="fw-semibold">Créer un compte</a>
                </p>

                <div class="demo-hint text-center">
                    <i class="bi bi-magic me-1"></i>Compte démo : <code>demo@test.fr</code> / <code>Demo1234!</code>
                </div>
            </div>
        </div>
    </div>
</div>
