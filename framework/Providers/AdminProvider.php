<?php

declare(strict_types=1);

namespace Lite\Framework\Providers;

use Lite\Framework\Core\ServiceProviderInterface;
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

        // Unify CSRF: when outer CsrfGuard middleware is active, disable LiteAdmin's duplicate check
        // (AdminDashboard::resolveCsrfToken automatically embeds CsrfGuard's token in all CRUD forms).
        if ((bool)($config['security']['csrf_enabled'] ?? true)) {
            $admin->setCsrfEnabled(false);
        }

        // Auto-invalidate Full-Page HTML Cache, Sitemap XML & DB Redirects on any Admin create/update/delete
        $admin->onMutation(static function () use ($container): void {
            if ($container->has(\Lite\Framework\Middleware\PageCacheMiddleware::class)) {
                $container->get(\Lite\Framework\Middleware\PageCacheMiddleware::class)->invalidateAll();
            }
            if ($container->has(\Lite\Framework\Seo\SitemapGenerator::class)) {
                $container->get(\Lite\Framework\Seo\SitemapGenerator::class)->invalidateCache();
            }
            if ($container->has(\LiteCache\CacheInterface::class)) {
                $container->get(\LiteCache\CacheInterface::class)->tags(['seo_redirects'])->flushTags();
            }
        });

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
            $env = (string)($config['env'] ?? 'development');
            $authSecret = (string)(
                $config['admin']['auth_secret']
                ?? $config['security']['csrf_secret']
                ?? $_ENV['APP_KEY']
                ?? \Lite\Framework\Security\AdminToken::DEFAULT_INSECURE_SECRET
            );

            // 1. Mount Auth Routes
            $app->get($prefix . '/login', [\App\Controllers\AuthController::class, 'showLogin']);
            $app->post($prefix . '/login', [\App\Controllers\AuthController::class, 'processLogin']);
            $app->get($prefix . '/logout', [\App\Controllers\AuthController::class, 'logout']);

            // 2. Mount Dashboard Routes
            $admin = $container->get(\LiteAdmin\AdminDashboard::class);
            $admin->registerRoutes($app);

            // 3. Module & Tenant Management API
            $app->get($prefix . '/api/modules', [\App\Controllers\AdminModuleController::class, 'listModules']);
            $app->post($prefix . '/api/modules/toggle', [\App\Controllers\AdminModuleController::class, 'toggleTenantModule']);

            // 4. Attach Admin Auth Guard Middleware (with TTL verification)
            $app->add(new \Lite\Framework\Middleware\AdminAuthMiddleware($authSecret, $prefix, $env));
        }
    }
}
