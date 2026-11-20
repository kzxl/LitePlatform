<?php

declare(strict_types=1);

use App\Controllers\ApiController;
use App\Controllers\HomeController;
use DI\Container;
use Slim\App;

return function (App $app, Container $container): void {
    // 1. Web / SSR / HTMX Routes
    $app->get('/', [HomeController::class, 'index']);
    $app->get('/fragment/metrics', [HomeController::class, 'metrics']);

    // 2. REST API Routes
    $app->get('/api/docs', [ApiController::class, 'docs']);
    $app->post('/api/login', [\App\Controllers\AuthController::class, 'apiLogin']);
    $app->get('/api/status', [ApiController::class, 'status']);
    $app->get('/api/users', [ApiController::class, 'users']);
    // Product REST Endpoints
    $app->get('/api/products', [\App\Controllers\ProductController::class, 'index']);
    $app->get('/api/products/{id}', [\App\Controllers\ProductController::class, 'show']);
    $app->post('/api/products', [\App\Controllers\ProductController::class, 'store']);
    $app->put('/api/products/{id}', [\App\Controllers\ProductController::class, 'update']);
    $app->delete('/api/products/{id}', [\App\Controllers\ProductController::class, 'destroy']);

    // 3. Dev Tools & Studio (Strictly disabled in production or when APP_DEBUG=false)
    $config = $container->has('config') ? (array)$container->get('config') : [];
    $isDebug = (bool)($config['debug'] ?? false);
    $appEnv = (string)($config['env'] ?? 'development');

    if ($isDebug && $appEnv !== 'production') {
        $app->get('/_studio', [\Lite\Framework\Dev\StudioController::class, 'index']);
        $app->post('/_studio/query', [\Lite\Framework\Dev\StudioController::class, 'executeQuery']);
        $app->get('/dev/live-reload', [\Lite\Framework\Dev\DevController::class, 'liveReload']);
    }
};