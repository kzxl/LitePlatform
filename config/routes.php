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
    $app->get('/blog', [\App\Controllers\BlogController::class, 'index']);
    $app->get('/blog/{slug}', [\App\Controllers\BlogController::class, 'show']);
    $app->get('/contact', [\App\Controllers\ContactController::class, 'show']);
    $contactSubmit = $app->post('/contact', [\App\Controllers\ContactController::class, 'submit']);
    if ($container->has(\LiteCache\CacheInterface::class)) {
        $contactSubmit->add(new \Lite\Framework\Middleware\RateLimitMiddleware(
            cache: $container->get(\LiteCache\CacheInterface::class),
            maxRequests: 10,
            decaySeconds: 60,
            bucket: 'contact_form'
        ));
    }

    // 2. REST API Routes
    $app->get('/api/docs', [ApiController::class, 'docs']);
    $app->post('/api/login', [\App\Controllers\AuthController::class, 'apiLogin']);
    $app->get('/api/status', [ApiController::class, 'status']);
    $app->get('/api/users', [ApiController::class, 'users']);
    // Product REST Endpoints (Write endpoints require Bearer JWT when api.protect_writes is enabled)
    $config = $container->has('config') ? (array)$container->get('config') : [];
    $protectApiWrites = (bool)($config['api']['protect_writes'] ?? false);
    $jwtMiddleware = ($protectApiWrites && class_exists(\LiteApi\Middleware\JwtAuthMiddleware::class) && $container->has('jwt.secret'))
        ? new \LiteApi\Middleware\JwtAuthMiddleware((string)$container->get('jwt.secret'))
        : null;

    $app->get('/api/products', [\App\Controllers\ProductController::class, 'index']);
    $app->get('/api/products/{id}', [\App\Controllers\ProductController::class, 'show']);

    $storeRoute = $app->post('/api/products', [\App\Controllers\ProductController::class, 'store']);
    $updateRoute = $app->put('/api/products/{id}', [\App\Controllers\ProductController::class, 'update']);
    $deleteRoute = $app->delete('/api/products/{id}', [\App\Controllers\ProductController::class, 'destroy']);

    if ($jwtMiddleware !== null) {
        $storeRoute->add($jwtMiddleware);
        $updateRoute->add($jwtMiddleware);
        $deleteRoute->add($jwtMiddleware);
    }

    // 3. Dev Tools & Studio (Strictly disabled in production or when APP_DEBUG=false)
    $isDebug = (bool)($config['debug'] ?? false);
    $appEnv = (string)($config['env'] ?? 'development');

    if ($isDebug && $appEnv !== 'production') {
        $app->get('/_studio', [\Lite\Framework\Dev\StudioController::class, 'index']);
        $app->post('/_studio/query', [\Lite\Framework\Dev\StudioController::class, 'executeQuery']);
        $app->get('/dev/live-reload', [\Lite\Framework\Dev\DevController::class, 'liveReload']);
    }

    // 4. Dynamic WordPress-style Pages catch-all (/{slug} e.g. /gioi-thieu, /dich-vu) — MUST stay last!
    $app->get('/{slug:[a-z0-9\-]+}', [\App\Controllers\PageController::class, 'show']);
};