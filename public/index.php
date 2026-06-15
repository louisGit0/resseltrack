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

Auth::start();

// ---- Security headers -------------------------------------------------------
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: strict-origin-when-cross-origin');
header(
    "Content-Security-Policy: default-src 'self'; "
    . "script-src 'self' cdn.jsdelivr.net; "
    . "style-src 'self' 'unsafe-inline' cdn.jsdelivr.net fonts.googleapis.com; "
    . "font-src fonts.gstatic.com cdn.jsdelivr.net; "
    . "img-src 'self' data: https://*.r2.dev; " // pre-satisfies Phase 5 SEC-03 for STORE-02
    . "connect-src 'self' api.frankfurter.app"
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
