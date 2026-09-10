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
};