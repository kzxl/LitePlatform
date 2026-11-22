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
                postHandler: [\Lite\Framework\Admin\CmsAdminController::class, 'saveSettings'],
                permission: 'core.settings.manage'
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

        // Enforce strong password policy when creating/updating User records in Admin
        if (class_exists(\App\Entities\User::class)) {
            $admin->beforeSave(\App\Entities\User::class, static function (array $data, ?object $existing): array {
                $plain = (string)($data['passwordHash'] ?? '');
                if ($existing === null && trim($plain) === '') {
                    return ['passwordHash' => 'Password is required when creating a new user (min 8 characters).'];
                }
                if (trim($plain) !== '' && strlen($plain) < 8) {
                    return ['passwordHash' => 'Password must be at least 8 characters.'];
                }
                return [];
            });
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

            // 1b. Sync baseline Core RBAC permissions once per fresh DB & mount Media Library endpoints
            $rbac = $container->has(\Lite\Framework\Security\RbacManager::class)
                ? $container->get(\Lite\Framework\Security\RbacManager::class)
                : null;
            $audit = $container->has(\Lite\Framework\Security\SecurityAuditLog::class)
                ? $container->get(\Lite\Framework\Security\SecurityAuditLog::class)
                : null;

            if ($rbac !== null && $container->has(\PDO::class)) {
                try {
                    $coreCount = (int)$container->get(\PDO::class)
                        ->query("SELECT COUNT(*) FROM permissions WHERE module_id = 'core'")
                        ->fetchColumn();
                    if ($coreCount < 5) {
                        $rbac->syncModulePermissions(
                            'core',
                            [
                                'core.settings.manage'   => 'Manage site settings & branding',
                                'core.appearance.manage' => 'Manage themes & homepage layout',
                                'core.media.upload'      => 'Upload files to Media Library',
                                'core.media.delete'      => 'Delete files from Media Library',
                                'core.security.view'     => 'Access Security Center, Plugins & RBAC',
                            ],
                            [
                                'manager' => ['core.appearance.manage', 'core.media.upload', 'core.media.delete'],
                                'staff'   => ['core.media.upload'],
                            ]
                        );
                    }
                } catch (\Throwable) {
                    // Ignore when RBAC tables have not been migrated yet (e.g. during /install)
                }
            }

            if ($container->has(\Lite\Framework\Media\MediaManager::class)) {
                $app->get($prefix . '/media/json', [\Lite\Framework\Admin\CmsAdminController::class, 'listMediaJson']);
                $upMedia = $app->post($prefix . '/media/upload', [\Lite\Framework\Admin\CmsAdminController::class, 'uploadMedia']);
                $delMedia = $app->post($prefix . '/media/delete/{id}', [\Lite\Framework\Admin\CmsAdminController::class, 'deleteMedia']);
                if ($rbac !== null) {
                    $upMedia->add(new \Lite\Framework\Middleware\RequirePermissionMiddleware('core.media.upload', $rbac, $audit));
                    $delMedia->add(new \Lite\Framework\Middleware\RequirePermissionMiddleware('core.media.delete', $rbac, $audit));
                }
            }

            // 2. Wire RBAC PermissionGate user resolver & let non-quarantined Admin-aware plugins register
            $admin = $container->get(\LiteAdmin\AdminDashboard::class);

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

            // 3. Module & Tenant Management API (strictly RBAC-protected)
            $listMods = $app->get($prefix . '/api/modules', [\App\Controllers\AdminModuleController::class, 'listModules']);
            $toggleMods = $app->post($prefix . '/api/modules/toggle', [\App\Controllers\AdminModuleController::class, 'toggleTenantModule']);
            if ($rbac !== null) {
                $secGuard = new \Lite\Framework\Middleware\RequirePermissionMiddleware('core.security.view', $rbac, $audit);
                $listMods->add($secGuard);
                $toggleMods->add($secGuard);
            }

            // 4. Attach Admin Auth Guard Middleware (with TTL + token_version revocation + RBAC hydration)
            $app->add(new \Lite\Framework\Middleware\AdminAuthMiddleware($authSecret, $prefix, $env, $rbac, $audit));
        }
    }
}
