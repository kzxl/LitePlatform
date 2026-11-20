<?php

declare(strict_types=1);

namespace Lite\Framework\Core\Tenancy;

/**
 * Contract for managing the active tenant execution context.
 */
interface TenantContextInterface
{
    /**
     * Get the currently active tenant in the execution context.
     */
    public function getCurrentTenant(): ?Tenant;

    /**
     * Set the currently active tenant.
     */
    public function setCurrentTenant(?Tenant $tenant): void;

    /**
     * Get the current tenant ID, or null if no tenant is scoped (e.g. system superadmin).
     */
    public function getCurrentTenantId(): ?int;

    /**
     * Determine if a specific module is enabled for the active tenant.
     */
    public function isModuleEnabled(string $moduleId): bool;

    /**
     * Register a tenant into the in-memory/cache registry.
     */
    public function registerTenant(Tenant $tenant): void;

    /**
     * Find a tenant by ID, code, or domain/subdomain.
     */
    public function findTenant(string|int $identifier): ?Tenant;

    /**
     * Set enabled modules for a specific tenant.
     *
     * @param int $tenantId
     * @param list<string> $moduleIds
     */
    public function setTenantModules(int $tenantId, array $moduleIds): void;
}
