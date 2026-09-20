<?php

declare(strict_types=1);

namespace App\Core\Plugin;

use App\Core\KernelInterface;

/**
 * Contract for managing the lifecycle, discovery, and tenant access of modules.
 */
interface ModuleManagerInterface
{
    /**
     * Register a module instance.
     */
    public function registerModule(IPluginModule $module): void;

    /**
     * Get all registered modules.
     *
     * @return array<string, IPluginModule> Keyed by module ID
     */
    public function getModules(): array;

    /**
     * Get a specific module by ID.
     */
    public function getModule(string $moduleId): ?IPluginModule;

    /**
     * Check if a module is registered.
     */
    public function hasModule(string $moduleId): bool;

    /**
     * Boot all registered modules with the given kernel.
     */
    public function bootModules(KernelInterface $kernel): void;

    /**
     * Enable a module for a specific tenant.
     */
    public function enableForTenant(int $tenantId, string $moduleId): void;

    /**
     * Disable a module for a specific tenant.
     */
    public function disableForTenant(int $tenantId, string $moduleId): void;

    /**
     * Check if a module is active for a given tenant.
     */
    public function isEnabledForTenant(?int $tenantId, string $moduleId): bool;
}
