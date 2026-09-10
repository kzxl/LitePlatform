<?php

declare(strict_types=1);

namespace App\Providers;

use App\Core\ServiceProviderInterface;
use DI\Container;
use Slim\App;

/**
 * Service provider for LiteAdmin auto-CRUD dashboard.
 */
class AdminProvider implements ServiceProviderInterface
{
    public function register(Container $container, array $config): void
    {
        if (!class_exists(\LiteAdmin\AdminDashboard::class) || !$container->has(\LiteORM\EntityManager::class)) {
            return;
        }

        $em = $container->get(\LiteORM\EntityManager::class);
        $audit = $container->has('audit') ? $container->get('audit') : null;
        $prefix = $config['admin']['prefix'] ?? '/admin';

        $admin = new \LiteAdmin\AdminDashboard($em, $audit, $prefix);

        // Auto-register configured entities
        foreach ($config['admin']['entities'] ?? [] as $entityClass) {
            if (class_exists($entityClass)) {
                $admin->register($entityClass);
            }
        }

        $container->set(\LiteAdmin\AdminDashboard::class, $admin);
        $container->set('admin', $admin);
    }

    public function boot(App $app, Container $container, array $config): void
    {
        if ($container->has(\LiteAdmin\AdminDashboard::class)) {
            $prefix = $config['admin']['prefix'] ?? '/admin';
            $authSecret = $config['admin']['auth_secret'] ?? ($_ENV['CSRF_SECRET'] ?? 'lite-admin-default-secret-key-32!');

            // 1. Mount Auth Routes
            $app->get($prefix . '/login', [\App\Controllers\AuthController::class, 'showLogin']);
            $app->post($prefix . '/login', [\App\Controllers\AuthController::class, 'processLogin']);
            $app->get($prefix . '/logout', [\App\Controllers\AuthController::class, 'logout']);

            // 2. Mount Dashboard Routes
            $admin = $container->get(\LiteAdmin\AdminDashboard::class);
            $admin->registerRoutes($app);

            // 3. Attach Admin Auth Guard Middleware
            $app->add(new \App\Middleware\AdminAuthMiddleware($authSecret, $prefix));
        }
    }
}
