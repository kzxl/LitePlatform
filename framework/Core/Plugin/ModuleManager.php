<?php

declare(strict_types=1);

namespace Lite\Framework\Core\Plugin;

use Lite\Framework\Core\KernelInterface;

/**
 * Module Manager implementation managing module registry and tenant entitlements.
 */
class ModuleManager implements ModuleManagerInterface
{
    /**
     * @var array<string, IPluginModule> Keyed by module ID
     */
    private array $modules = [];

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

    public function bootModules(KernelInterface $kernel): void
    {
        $container = $kernel->getContainer();
        $routes = $kernel->getRouteRegistry();
        $config = $kernel->getConfig();
        $navLinks = [];
        $addedMigrations = false;

        foreach ($this->modules as $module) {
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

                foreach ($module->getNavLinks() as $link) {
                    if (isset($link['label'], $link['href'])) {
                        $navLinks[] = [
                            'label' => (string)$link['label'],
                            'href'  => (string)$link['href'],
                        ];
                    }
                }
            }

            $module->registerServices($container);
            $module->registerRoutes($routes);

            if ($module instanceof WebModuleInterface && $container->has(\Lite\Framework\Seo\SitemapGenerator::class)) {
                /** @var \Lite\Framework\Seo\SitemapGenerator $sitemap */
                $sitemap = $container->get(\Lite\Framework\Seo\SitemapGenerator::class);
                $module->registerSitemap($sitemap, $container);
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
        if ($tenantId === null) {
            // Superadmin has access to all registered modules
            return true;
        }

        // If explicitly disabled, return false
        if (isset($this->tenantEntitlements[$tenantId][$moduleId])) {
            return $this->tenantEntitlements[$tenantId][$moduleId];
        }

        // Default: enabled for tenant if module exists
        return true;
    }
}
