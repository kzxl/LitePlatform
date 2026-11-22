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

        // Register built-in CMS Custom Pages (Settings & Media Library)
        if ($container->has(\Lite\Framework\Cms\SiteSettings::class)) {
            $admin->registerPage(
                slug: 'settings',
                title: 'Site Settings',
                group: 'Configuration',
                getHandler: [\Lite\Framework\Admin\CmsAdminController::class, 'showSettings'],
                postHandler: [\Lite\Framework\Admin\CmsAdminController::class, 'saveSettings']
            );
        }
        if ($container->has(\Lite\Framework\Media\MediaManager::class)) {
            $admin->registerPage(
                slug: 'media',
                title: 'Media Library',
                group: 'Content',
                getHandler: [\Lite\Framework\Admin\CmsAdminController::class, 'showMedia']
            );
        }

        // Register built-in Appearance & Layout Studio (/admin/appearance) & Account/2FA (/admin/profile)
        $admin->registerPage(
            slug: 'profile',
            title: 'My Account & 2FA',
            group: 'Configuration',
            getHandler: [\Lite\Framework\Admin\AccountAdminController::class, 'show'],
            postHandler: [\Lite\Framework\Admin\AccountAdminController::class, 'handlePost']
        );
        $admin->registerPage(
            slug: 'appearance',
            title: 'Appearance & Layout',
            group: 'Configuration',
            getHandler: [\Lite\Framework\Admin\AppearanceAdminController::class, 'show'],
            postHandler: [\Lite\Framework\Admin\AppearanceAdminController::class, 'save'],
            permission: 'core.appearance.manage'
        );

        // Register built-in Security & Plugin Governance Center (/admin/security) & RBAC Role Matrix (/admin/roles)
        $admin->registerPage(
            slug: 'security',
            title: 'Security & Plugins',
            group: 'Configuration',
            getHandler: [\Lite\Framework\Admin\SecurityAdminController::class, 'show'],
            postHandler: [\Lite\Framework\Admin\SecurityAdminController::class, 'handleAction'],
            permission: 'core.security.view'
        );
        $admin->registerPage(
            slug: 'roles',
            title: 'RBAC Roles',
            group: 'Configuration',
            getHandler: [\Lite\Framework\Admin\SecurityAdminController::class, 'showRoles'],
            postHandler: [\Lite\Framework\Admin\SecurityAdminController::class, 'saveRoles'],
            permission: 'core.security.view'
        );

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

            // 1. Mount Auth & Account Recovery Routes
            $app->get($prefix . '/login', [\App\Controllers\AuthController::class, 'showLogin']);
            $app->post($prefix . '/login', [\App\Controllers\AuthController::class, 'processLogin']);
            $app->get($prefix . '/logout', [\App\Controllers\AuthController::class, 'logout']);
            $app->get($prefix . '/forgot-password', [\App\Controllers\AuthController::class, 'showForgotPassword']);
            $app->post($prefix . '/forgot-password', [\App\Controllers\AuthController::class, 'sendResetLink']);
            $app->get($prefix . '/reset-password', [\App\Controllers\AuthController::class, 'showResetPassword']);
            $app->post($prefix . '/reset-password', [\App\Controllers\AuthController::class, 'processResetPassword']);

            // 1b. Mount Media Library AJAX & Delete endpoints BEFORE /{slug} resource catch-all
            if ($container->has(\Lite\Framework\Media\MediaManager::class)) {
                $app->get($prefix . '/media/json', [\Lite\Framework\Admin\CmsAdminController::class, 'listMediaJson']);
                $app->post($prefix . '/media/upload', [\Lite\Framework\Admin\CmsAdminController::class, 'uploadMedia']);
                $app->post($prefix . '/media/delete/{id}', [\Lite\Framework\Admin\CmsAdminController::class, 'deleteMedia']);
            }

            // 2. Wire RBAC PermissionGate user resolver & let non-quarantined Admin-aware plugins register
            $admin = $container->get(\LiteAdmin\AdminDashboard::class);
            $rbac = $container->has(\Lite\Framework\Security\RbacManager::class)
                ? $container->get(\Lite\Framework\Security\RbacManager::class)
                : null;
            $audit = $container->has(\Lite\Framework\Security\SecurityAuditLog::class)
                ? $container->get(\Lite\Framework\Security\SecurityAuditLog::class)
                : null;

            if ($rbac !== null) {
                $admin->setUserResolver(static function (\Psr\Http\Message\ServerRequestInterface $request) use ($rbac): array {
                    $preloaded = $request->getAttribute('auth_context');
                    if (is_array($preloaded)) {
                        return $preloaded;
                    }
                    $username = (string)$request->getAttribute('admin_user', '');
                    return $username !== '' ? $rbac->resolveUserContext($username) : ['roles' => [], 'permissions' => []];
                });
            }

            if ($container->has(\Lite\Framework\Core\Plugin\ModuleManagerInterface::class)) {
                /** @var \Lite\Framework\Core\Plugin\ModuleManagerInterface $moduleManager */
                $moduleManager = $container->get(\Lite\Framework\Core\Plugin\ModuleManagerInterface::class);
                foreach ($moduleManager->getModules() as $moduleId => $module) {
                    if ($moduleManager instanceof \Lite\Framework\Core\Plugin\ModuleManager && $moduleManager->isQuarantined($moduleId)) {
                        continue;
                    }
                    if ($module instanceof \Lite\Framework\Core\Plugin\AdminAwareModuleInterface) {
                        $module->registerAdmin($admin, $container);
                    }
                }
            }

            // 2b. Mount Dashboard Routes (including custom pages, custom actions & resource CRUD)
            $admin->registerRoutes($app);

            // 3. Module & Tenant Management API
            $app->get($prefix . '/api/modules', [\App\Controllers\AdminModuleController::class, 'listModules']);
            $app->post($prefix . '/api/modules/toggle', [\App\Controllers\AdminModuleController::class, 'toggleTenantModule']);

            // 4. Attach Admin Auth Guard Middleware (with TTL + token_version revocation + RBAC hydration)
            $app->add(new \Lite\Framework\Middleware\AdminAuthMiddleware($authSecret, $prefix, $env, $rbac, $audit));
        }
    }
}
