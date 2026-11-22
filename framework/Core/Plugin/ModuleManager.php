<?php

declare(strict_types=1);

namespace Lite\Framework\Core\Plugin;

use Lite\Framework\Core\KernelInterface;
use Lite\Framework\Mail\TelegramNotifier;
use Lite\Framework\Security\RbacManager;
use Lite\Framework\Security\SecretVault;
use Lite\Framework\Security\SecurityAuditLog;
use Lite\Framework\Security\SsrfGuard;
use LiteCache\CacheInterface;
use PDO;
use Throwable;

/**
 * Sovereign Module Manager enforcing LES v1 Plugin Contracts, Capability Isolation,
 * RBAC Permission Auto-Discovery, and Boot Circuit-Breaker Quarantine.
 */
class ModuleManager implements ModuleManagerInterface
{
    /**
     * @var array<string, IPluginModule> Keyed by module ID
     */
    private array $modules = [];

    /**
     * @var array<string, string> Quarantined moduleId => error reason
     */
    private array $quarantined = [];

    /**
     * @var array<string, PluginContext> Scoped contexts for HardenedPluginInterface modules
     */
    private array $contexts = [];

    /**
     * @var array<int, array<string, bool>> Tenant ID => [moduleId => bool]
     */
    private array $tenantEntitlements = [];

    public function registerModule(IPluginModule $module): void
    {
        $this->modules[$module->getId()] = $module;
    }

    public function getModules(): array
    {
        return $this->modules;
    }

    public function getModule(string $moduleId): ?IPluginModule
    {
        return $this->modules[$moduleId] ?? null;
    }

    public function hasModule(string $moduleId): bool
    {
        return isset($this->modules[$moduleId]);
    }

    public function isQuarantined(string $moduleId): bool
    {
        return isset($this->quarantined[$moduleId]);
    }

    /**
     * @return array<string, string>
     */
    public function getQuarantinedModules(): array
    {
        return $this->quarantined;
    }

    public function getPluginContext(string $moduleId): ?PluginContext
    {
        return $this->contexts[$moduleId] ?? null;
    }

    public function bootModules(KernelInterface $kernel): void
    {
        $container = $kernel->getContainer();
        $routes = $kernel->getRouteRegistry();
        $config = $kernel->getConfig();

        // 1. Bootstrap shared core security & hook infrastructure (explicit singletons; avoid PHP-DI autowire has() trap)
        $hooks = new HookBus();
        $container->set(HookBus::class, $hooks);

        $masterSecret = (string)(
            $config['security']['csrf_secret']
            ?? $_ENV['APP_KEY']
            ?? 'lite-platform-dev-secret-key-32!'
        );
        if (strlen($masterSecret) < 16) {
            $masterSecret = str_pad($masterSecret, 32, '0');
        }

        $vault = new SecretVault($masterSecret);
        $container->set(SecretVault::class, $vault);

        $ssrf = new SsrfGuard();
        $container->set(SsrfGuard::class, $ssrf);

        $audit = null;
        $rbac = null;
        if ($container->has(PDO::class)) {
            /** @var PDO $pdo */
            $pdo = $container->get(PDO::class);
            $tg = $container->has(TelegramNotifier::class) ? $container->get(TelegramNotifier::class) : null;
            $cache = $container->has(CacheInterface::class) ? $container->get(CacheInterface::class) : null;

            $audit = new SecurityAuditLog($pdo, $masterSecret, $tg);
            $container->set(SecurityAuditLog::class, $audit);

            $rbac = new RbacManager($pdo, $cache);
            $container->set(RbacManager::class, $rbac);

            $hooks->setErrorReporter(static function (string $hook, string $moduleId, Throwable $e) use ($audit): void {
                try {
                    $audit->record(
                        eventType: 'plugin.hook_error',
                        severity: 'WARN',
                        actor: 'plugin:' . $moduleId,
                        moduleId: $moduleId,
                        target: $hook,
                        context: ['error' => $e->getMessage()]
                    );
                } catch (Throwable) {
                }
            });
        }

        $navLinks = [];
        $addedMigrations = false;

        foreach ($this->modules as $moduleId => $module) {
            // Skip modules explicitly disabled or quarantined in DB registry
            if ($this->isDisabledInRegistry($container, $moduleId)) {
                $this->quarantined[$moduleId] = 'Disabled or quarantined in plugin_registry.';
                continue;
            }

            try {
                // Verify dependencies for HardenedPluginInterface
                if ($module instanceof HardenedPluginInterface) {
                    $manifest = $module->manifest();
                    foreach ($manifest->dependencies as $depId => $constraint) {
                        if (!$this->hasModule($depId) || $this->isQuarantined($depId)) {
                            throw new \RuntimeException("Missing or quarantined dependency '{$depId}' ({$constraint}).");
                        }
                    }
                }

                // Register migrations & views first so services/templates can resolve them
                if ($module instanceof WebModuleInterface) {
                    $migPath = $module->getMigrationsPath();
                    if ($migPath !== null && is_dir($migPath) && $container->has(\Lite\Framework\Core\MigrationManager::class)) {
                        /** @var \Lite\Framework\Core\MigrationManager $migrator */
                        $migrator = $container->get(\Lite\Framework\Core\MigrationManager::class);
                        $migrator->addMigrationPath($migPath);
                        $addedMigrations = true;
                    }

                    $ns = $module->getViewNamespace();
                    $viewsPath = $module->getViewsPath();
                    if ($ns !== null && $viewsPath !== null && is_dir($viewsPath) && $container->has(\LiteView\ViewEngine::class)) {
                        /** @var \LiteView\ViewEngine $view */
                        $view = $container->get(\LiteView\ViewEngine::class);
                        $view->addNamespace($ns, $viewsPath);
                    }
                }

                // Boot plugin via scoped PluginContext (HardenedPluginInterface) or legacy registerServices()
                if ($module instanceof HardenedPluginInterface) {
                    $context = new PluginContext($module->manifest(), $container, $hooks, $vault, $audit, $ssrf);
                    $this->contexts[$moduleId] = $context;
                    $module->bootPlugin($context);
                } else {
                    $module->registerServices($container);
                }

                // Register routes and validate no reserved Core route collision
                $routeCountBefore = count($routes->getRoutes());
                $module->registerRoutes($routes);
                if ($module instanceof HardenedPluginInterface) {
                    $allRoutes = $routes->getRoutes();
                    for ($i = $routeCountBefore; $i < count($allRoutes); $i++) {
                        PluginManifest::assertRouteAllowed((string)$allRoutes[$i]['path']);
                    }
                }

                // Sync declared RBAC permissions & default grants
                if ($module instanceof SecurityAwareModuleInterface && $rbac !== null) {
                    try {
                        $rbac->syncModulePermissions(
                            $moduleId,
                            $module->getPermissions(),
                            $module->getDefaultRoleGrants()
                        );
                    } catch (Throwable) {
                        // Tables may not be migrated yet in fresh DB; will sync on next boot after migrate
                    }
                }

                // Attach sitemap & nav links only after successful boot
                if ($module instanceof WebModuleInterface) {
                    if ($container->has(\Lite\Framework\Seo\SitemapGenerator::class)) {
                        /** @var \Lite\Framework\Seo\SitemapGenerator $sitemap */
                        $sitemap = $container->get(\Lite\Framework\Seo\SitemapGenerator::class);
                        $module->registerSitemap($sitemap, $container);
                    }

                    foreach ($module->getNavLinks() as $link) {
                        if (isset($link['label'], $link['href'])) {
                            $navLinks[] = [
                                'label' => (string)$link['label'],
                                'href'  => (string)$link['href'],
                            ];
                        }
                    }
                }
            } catch (Throwable $e) {
                // Fault Isolation Circuit Breaker: quarantine failing plugin without crashing Core!
                $this->quarantined[$moduleId] = $e->getMessage();
                $this->persistQuarantine($container, $moduleId, $module->getVersion(), $e->getMessage());
                if ($audit !== null) {
                    try {
                        $audit->record(
                            eventType: 'plugin.boot_quarantined',
                            severity: 'CRITICAL',
                            actor: 'kernel',
                            moduleId: $moduleId,
                            target: $module::class,
                            context: ['error' => $e->getMessage()]
                        );
                    } catch (Throwable) {
                    }
                }
            }
        }

        if ($addedMigrations && (bool)($config['debug'] ?? true) && $container->has(\Lite\Framework\Core\MigrationManager::class)) {
            /** @var \Lite\Framework\Core\MigrationManager $migrator */
            $migrator = $container->get(\Lite\Framework\Core\MigrationManager::class);
            $migrator->migrate();
        }

        if ($container->has(\LiteView\ViewEngine::class)) {
            /** @var \LiteView\ViewEngine $view */
            $view = $container->get(\LiteView\ViewEngine::class);
            $view->share('moduleNavLinks', $navLinks);
        }
    }

    public function enableForTenant(int $tenantId, string $moduleId): void
    {
        $this->tenantEntitlements[$tenantId][$moduleId] = true;
    }

    public function disableForTenant(int $tenantId, string $moduleId): void
    {
        $this->tenantEntitlements[$tenantId][$moduleId] = false;
    }

    public function isEnabledForTenant(?int $tenantId, string $moduleId): bool
    {
        if ($this->isQuarantined($moduleId)) {
            return false;
        }

        if ($tenantId === null) {
            return true;
        }

        if (isset($this->tenantEntitlements[$tenantId][$moduleId])) {
            return $this->tenantEntitlements[$tenantId][$moduleId];
        }

        return true;
    }

    private function isDisabledInRegistry(\DI\Container $container, string $moduleId): bool
    {
        if (!$container->has(PDO::class)) {
            return false;
        }
        try {
            /** @var PDO $pdo */
            $pdo = $container->get(PDO::class);
            $stmt = $pdo->prepare('SELECT state FROM plugin_registry WHERE plugin_id = :id LIMIT 1');
            $stmt->execute([':id' => $moduleId]);
            $state = $stmt->fetchColumn();
            return is_string($state) && in_array(strtolower($state), ['disabled', 'quarantined'], true);
        } catch (Throwable) {
            return false;
        }
    }

    private function persistQuarantine(\DI\Container $container, string $moduleId, string $version, string $error): void
    {
        if (!$container->has(PDO::class)) {
            return;
        }
        try {
            /** @var PDO $pdo */
            $pdo = $container->get(PDO::class);
            $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
            $now = date('Y-m-d H:i:s');
            if ($driver === 'mysql') {
                $sql = "INSERT INTO plugin_registry (plugin_id, version, state, failure_count, last_error, updated_at)
                        VALUES (:id, :v, 'quarantined', 1, :err, :u)
                        ON DUPLICATE KEY UPDATE state = 'quarantined', failure_count = failure_count + 1, last_error = VALUES(last_error), updated_at = VALUES(updated_at)";
            } else {
                $sql = "INSERT INTO plugin_registry (plugin_id, version, state, failure_count, last_error, updated_at)
                        VALUES (:id, :v, 'quarantined', 1, :err, :u)
                        ON CONFLICT(plugin_id) DO UPDATE SET state = 'quarantined', failure_count = failure_count + 1, last_error = excluded.last_error, updated_at = excluded.updated_at";
            }
            $pdo->prepare($sql)->execute([
                ':id'  => $moduleId,
                ':v'   => $version,
                ':err' => mb_substr($error, 0, 500),
                ':u'   => $now,
            ]);
        } catch (Throwable) {
        }
    }
}
