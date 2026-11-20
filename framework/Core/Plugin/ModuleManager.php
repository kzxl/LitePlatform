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

        foreach ($this->modules as $module) {
            $module->registerServices($container);
            $module->registerRoutes($routes);
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
