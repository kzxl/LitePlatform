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
    $app->get('/api/status', [ApiController::class, 'status']);
    $app->get('/api/users', [ApiController::class, 'users']);
};
