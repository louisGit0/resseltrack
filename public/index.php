<?php
declare(strict_types=1);

/**
 * Single front controller for ResellTrack.
 * Boots the environment, autoloads classes, starts the session and dispatches.
 */

use App\Core\Auth;
use App\Core\Env;
use App\Core\Router;
use App\Controllers\AuthController;
use App\Controllers\OrderController;
use App\Controllers\ProductController;
use App\Controllers\ProfileController;
use App\Controllers\PurchaseController;
use App\Controllers\SaleController;
use App\Controllers\DashboardController;
use App\Controllers\ExportController;

$root = dirname(__DIR__);

// Composer vendor autoload — absent in local dev without `composer install`, present on Vercel Lambda
if (is_file($root . '/vendor/autoload.php')) {
    require $root . '/vendor/autoload.php';
}

// ---- PSR-4-ish autoloader: App\Foo\Bar -> src/Foo/Bar.php -----------------
spl_autoload_register(static function (string $class) use ($root): void {
    $prefix = 'App\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $relative = substr($class, strlen($prefix));
    $file = $root . '/src/' . str_replace('\\', '/', $relative) . '.php';
    if (is_file($file)) {
        require $file;
    }
});

require $root . '/src/helpers.php';

// ---- Environment & session ------------------------------------------------
Env::load($root . '/.env');

// ---- Health check / keep-alive --------------------------------------------
// Handled BEFORE Auth::start() so it neither starts a session (no anon rows)
// nor requires auth. The SELECT 1 forces a real MySQL connection, which keeps
// the Aiven free-tier service from powering off due to inactivity. Pinged on a
// schedule by .github/workflows/keepalive.yml.
if (parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) === '/health') {
    header('Content-Type: application/json');
    try {
        \App\Core\Database::connection()->query('SELECT 1');
        echo '{"status":"ok","db":"up"}';
    } catch (\Throwable $e) {
        http_response_code(503);
        error_log('Health check DB failure: ' . $e->getMessage());
        echo '{"status":"error","db":"down"}';
    }
    exit;
}

// ---- HTTPS detection (D-01) -----------------------------------------------
// Primary: Vercel's proxy sets x-forwarded-proto=https on all production HTTPS
// requests. PHP maps it to $_SERVER['HTTP_X_FORWARDED_PROTO'].
// Absent on local Docker plain-HTTP (no proxy layer).
//
// Fallback: $_SERVER['HTTPS'] is hardcoded 'On' in vercel-php CGI for all
// Vercel requests regardless of actual TLS (cgi.ts source). On local Docker
// Apache it is absent for plain-HTTP. Safe as secondary signal.
$isHttps = (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https')
         || (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== '' && strtolower($_SERVER['HTTPS']) !== 'off');

// ---- Production boot safety gate (SEC-04) ----------------------------------
// Runs only on HTTPS (i.e. Vercel/production). Never fires on local HTTP dev.
// Placed AFTER the /health early-return (keep-alive is always exempt — D-04)
// and BEFORE Auth::start() so a misconfigured SESSION_SECURE never reaches the
// session/cookie layer.
if ($isHttps) {
    $bootErrors = [];
    if (Env::get('SESSION_SECURE', '0') !== '1') {
        $bootErrors[] = 'SESSION_SECURE not set to 1';
    }
    $dbPassword = Env::get('DB_PASSWORD', '');
    if ($dbPassword === '' || $dbPassword === 'reselltrack') {
        $bootErrors[] = 'DB_PASSWORD is empty or uses dev default';
    }
    foreach (['DB_HOST', 'DB_NAME', 'DB_USER'] as $key) {
        if (Env::get($key, '') === '') {
            $bootErrors[] = $key . ' is empty';
        }
    }
    foreach (['CLOUDINARY_CLOUD_NAME', 'CLOUDINARY_API_KEY', 'CLOUDINARY_API_SECRET'] as $key) {
        if (Env::get($key, '') === '') {
            $bootErrors[] = $key . ' is empty';
        }
    }
    if ($bootErrors !== []) {
        http_response_code(500);
        header('Content-Type: text/html; charset=utf-8'); // Security-headers block has not run yet
        foreach ($bootErrors as $reason) {
            error_log('Boot gate: ' . $reason);
        }
        echo '<!DOCTYPE html><html lang="fr"><head><meta charset="utf-8">'
           . '<title>Erreur de configuration</title></head><body>'
           . '<h1>Service temporairement indisponible</h1>'
           . '<p>Une erreur de configuration empêche le démarrage de l\'application. '
           . 'Veuillez contacter l\'administrateur.</p>'
           . '</body></html>';
        exit;
    }
}

Auth::start();

// ---- Security headers -------------------------------------------------------
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: strict-origin-when-cross-origin');
if ($isHttps) {
    // Vercel's edge already emits a stronger platform-level Strict-Transport-Security header
    // (max-age=63072000; includeSubDomains — 2 years, confirmed live curl 2026-06-15).
    // This app-level header (1 year, includeSubDomains) is intentional defense-in-depth:
    // it documents the app's TLS posture in code and protects it if the hosting platform changes.
    // RFC 6797 §8.1: browsers process only the first STS header — no conflict, no harm.
    // Not submitted to HSTS-submit lists (we don't control the vercel.app apex domain).
    header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
}
header(
    "Content-Security-Policy: default-src 'self'; "
    . "script-src 'self' cdn.jsdelivr.net; "
    . "style-src 'self' 'unsafe-inline' cdn.jsdelivr.net fonts.googleapis.com; "
    . "font-src fonts.gstatic.com cdn.jsdelivr.net; "
    . "img-src 'self' data: https://res.cloudinary.com; " // Cloudinary image delivery (SEC-03)
    . "connect-src 'self' api.frankfurter.dev"
);

// ---- Routes ---------------------------------------------------------------
$router = new Router();

// Auth
$router->get('/login', [AuthController::class, 'showLogin']);
$router->post('/login', [AuthController::class, 'login']);
$router->get('/register', [AuthController::class, 'showRegister']);
$router->post('/register', [AuthController::class, 'register']);
$router->post('/logout', [AuthController::class, 'logout']);

// Dashboard
$router->get('/', [DashboardController::class, 'index']);
$router->get('/dashboard', [DashboardController::class, 'index']);

// Profile
$router->get('/profile', [ProfileController::class, 'index']);
$router->post('/profile', [ProfileController::class, 'update']);
$router->post('/profile/password', [ProfileController::class, 'updatePassword']);

// Products — /products/create MUST stay before /products/{id} (match order)
$router->get('/products', [ProductController::class, 'index']);
$router->get('/products/create', [ProductController::class, 'create']);
$router->get('/products/{id}', [ProductController::class, 'show']);
$router->post('/products', [ProductController::class, 'store']);
$router->get('/products/{id}/edit', [ProductController::class, 'edit']);
$router->post('/products/{id}', [ProductController::class, 'update']);
$router->post('/products/{id}/delete', [ProductController::class, 'destroy']);
$router->post('/products/{id}/images', [ProductController::class, 'uploadImages']);
$router->post('/products/{id}/images/{imageId}/delete', [ProductController::class, 'deleteImage']);
$router->post('/products/{id}/images/{imageId}/cover', [ProductController::class, 'setCoverImage']);

// Orders — /orders/create MUST stay before /orders/{id} (match order)
$router->get('/orders', [OrderController::class, 'index']);
$router->get('/orders/create', [OrderController::class, 'create']);
$router->get('/orders/{id}', [OrderController::class, 'show']);
$router->get('/orders/{id}/edit', [OrderController::class, 'edit']);
$router->post('/orders', [OrderController::class, 'store']);
$router->post('/orders/{id}', [OrderController::class, 'update']);
$router->post('/orders/{id}/delete', [OrderController::class, 'destroy']);

// Purchases
$router->get('/purchases', [PurchaseController::class, 'index']);
$router->get('/purchases/create', [PurchaseController::class, 'create']);
$router->post('/purchases', [PurchaseController::class, 'store']);
$router->get('/purchases/{id}/edit', [PurchaseController::class, 'edit']);
$router->post('/purchases/{id}', [PurchaseController::class, 'update']);
$router->post('/purchases/{id}/delete', [PurchaseController::class, 'destroy']);

// Sales
$router->get('/sales', [SaleController::class, 'index']);
$router->get('/sales/create', [SaleController::class, 'create']);
$router->get('/sales/{id}/duplicate', [SaleController::class, 'duplicate']);
$router->post('/sales', [SaleController::class, 'store']);
$router->get('/sales/{id}/edit', [SaleController::class, 'edit']);
$router->post('/sales/{id}', [SaleController::class, 'update']);
$router->post('/sales/{id}/delete', [SaleController::class, 'destroy']);

// CSV export
$router->get('/export/purchases', [ExportController::class, 'purchases']);
$router->get('/export/sales', [ExportController::class, 'sales']);
$router->get('/export/products', [ExportController::class, 'products']);

$router->dispatch($_SERVER['REQUEST_METHOD'], $_SERVER['REQUEST_URI']);
