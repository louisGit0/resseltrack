<?php
/** @var string $content @var string $title @var array $flash @var string $authName @var bool $authCheck */
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/';

$isActive = static function (string $prefix) use ($path): string {
    if ($prefix === '/dashboard') {
        return ($path === '/' || str_starts_with($path, '/dashboard')) ? 'active' : '';
    }
    return str_starts_with($path, $prefix) ? 'active' : '';
};

// Navigation rendered twice (desktop sidebar + mobile offcanvas).
$navHtml = static function () use ($isActive, $authName): string {
    ob_start(); ?>
    <a class="sidebar-brand" href="/dashboard">
        <span class="brand-icon"><i class="bi bi-box-seam-fill"></i></span>
        ResellTrack
    </a>

    <div class="sidebar-section">Pilotage</div>
    <nav class="nav flex-column">
        <a class="nav-link <?= $isActive('/dashboard') ?>" href="/dashboard"><i class="bi bi-speedometer2"></i> Tableau de bord</a>
    </nav>

    <div class="sidebar-section">Activité</div>
    <nav class="nav flex-column">
        <a class="nav-link <?= $isActive('/products') ?>" href="/products"><i class="bi bi-box-seam"></i> Produits</a>
        <a class="nav-link <?= $isActive('/orders') ?>" href="/orders"><i class="bi bi-receipt"></i> Commandes</a>
        <a class="nav-link <?= $isActive('/purchases') ?>" href="/purchases"><i class="bi bi-bag-plus"></i> Achats</a>
        <a class="nav-link <?= $isActive('/sales') ?>" href="/sales"><i class="bi bi-cash-coin"></i> Ventes</a>
    </nav>

    <div class="sidebar-section">Exports CSV</div>
    <nav class="nav flex-column">
        <a class="nav-link" href="/export/sales"><i class="bi bi-download"></i> Ventes</a>
        <a class="nav-link" href="/export/purchases"><i class="bi bi-download"></i> Achats</a>
        <a class="nav-link" href="/export/products"><i class="bi bi-download"></i> Récap produits</a>
    </nav>

    <div class="sidebar-user">
        <a href="/profile" class="d-flex align-items-center gap-2 text-decoration-none" title="Mon compte">
            <span class="avatar"><?= e(mb_strtoupper(mb_substr($authName, 0, 1))) ?></span>
            <span>
                <span class="user-name d-block"><?= e($authName) ?></span>
                <span class="user-sub">Mon compte</span>
            </span>
        </a>
        <form method="post" action="/logout" class="ms-auto">
            <?= \App\Core\Csrf::field() ?>
            <button class="btn-logout" type="submit" title="Déconnexion"><i class="bi bi-box-arrow-right"></i></button>
        </form>
    </div>
    <?php return (string) ob_get_clean();
};
?>
<!doctype html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($title) ?> — ResellTrack</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Sora:wght@600;700;800&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" crossorigin="anonymous">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="/assets/css/style.css" rel="stylesheet">
</head>
<body>
<?php if ($authCheck): ?>
<div class="app-shell">
    <aside class="sidebar d-none d-lg-flex"><?= $navHtml() ?></aside>

    <div class="offcanvas offcanvas-start sidebar-offcanvas d-lg-none" tabindex="-1" id="mobileSidebar">
        <div class="d-flex justify-content-end">
            <button type="button" class="btn-close" data-bs-dismiss="offcanvas"></button>
        </div>
        <div class="d-flex flex-column flex-grow-1"><?= $navHtml() ?></div>
    </div>

    <div class="app-main">
        <header class="topbar d-lg-none">
            <button class="btn btn-outline-secondary btn-sm" type="button" data-bs-toggle="offcanvas" data-bs-target="#mobileSidebar">
                <i class="bi bi-list"></i>
            </button>
            <span class="brand-sm">📦 ResellTrack</span>
        </header>

        <?php if ($flash !== []): ?>
        <div class="toast-stack">
            <?php foreach ($flash as $f): ?>
                <div class="alert alert-<?= e($f['type']) ?> alert-dismissible fade show" role="alert" data-autodismiss>
                    <?= e($f['message']) ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <main class="app-content">
            <?= $content /* chaque vue échappe déjà ses champs */ ?>
        </main>

        <footer class="app-footer">ResellTrack · suivi d'achat-revente · <?= date('Y') ?></footer>
    </div>
</div>
<?php else: ?>
<main class="auth-wrap">
    <?php if ($flash !== []): ?>
    <div class="toast-stack">
        <?php foreach ($flash as $f): ?>
            <div class="alert alert-<?= e($f['type']) ?> alert-dismissible fade show" role="alert" data-autodismiss>
                <?= e($f['message']) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
    <?= $content ?>
</main>
<?php endif; ?>

<!-- Modale de confirmation générique (formulaires portant data-confirm) -->
<div class="modal fade" id="confirm-modal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-sm">
        <div class="modal-content" style="border-radius:16px;border:0">
            <div class="modal-body text-center p-4">
                <div class="es-icon mx-auto mb-3" style="width:52px;height:52px;background:var(--rt-danger-soft);color:#b91c1c;border-radius:14px;display:grid;place-items:center;font-size:1.4rem">
                    <i class="bi bi-exclamation-triangle"></i>
                </div>
                <p class="mb-0 fw-semibold" id="confirm-modal-message" style="font-size:.92rem"></p>
            </div>
            <div class="modal-footer border-0 pt-0 justify-content-center gap-2">
                <button type="button" class="btn btn-outline-secondary btn-sm px-3" data-bs-dismiss="modal">Annuler</button>
                <button type="button" class="btn btn-danger btn-sm px-3" id="confirm-modal-ok">Supprimer</button>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js" crossorigin="anonymous"></script>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
<script src="/assets/js/app.js"></script>
</body>
</html>
