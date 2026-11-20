<?php

declare(strict_types=1);

namespace Lite\Framework\Core\Tenancy;

/**
 * In-memory TenantContext tracking current tenant and active module entitlements.
 */
class TenantContext implements TenantContextInterface
{
    private ?Tenant $currentTenant = null;

    /**
     * @var array<int, Tenant> Keyed by tenant ID
     */
    private array $tenantsById = [];

    /**
     * @var array<string, Tenant> Keyed by code or domain/subdomain
     */
    private array $tenantsByLookup = [];

    /**
     * @var array<int, array<string, bool>> Tenant ID => [moduleId => true]
     */
    private array $tenantModules = [];

    public function getCurrentTenant(): ?Tenant
    {
        return $this->currentTenant;
    }

    public function setCurrentTenant(?Tenant $tenant): void
    {
        $this->currentTenant = $tenant;
    }

    public function getCurrentTenantId(): ?int
    {
        return $this->currentTenant?->id;
    }

    public function isModuleEnabled(string $moduleId): bool
    {
        if ($this->currentTenant === null) {
            // Global/superadmin scope has access to all modules
            return true;
        }

        $tenantId = $this->currentTenant->id;
        if (!isset($this->tenantModules[$tenantId])) {
            // Default to true if not explicitly restricted
            return true;
        }

        return !empty($this->tenantModules[$tenantId][$moduleId]);
    }

    public function registerTenant(Tenant $tenant): void
    {
        $this->tenantsById[$tenant->id] = $tenant;
        $this->tenantsByLookup[strtolower($tenant->code)] = $tenant;

        if ($tenant->domain) {
            $this->tenantsByLookup[strtolower($tenant->domain)] = $tenant;
        }
        if ($tenant->subdomain) {
            $this->tenantsByLookup[strtolower($tenant->subdomain)] = $tenant;
        }
    }

    public function findTenant(string|int $identifier): ?Tenant
    {
        if (is_int($identifier)) {
            return $this->tenantsById[$identifier] ?? null;
        }

        if (is_numeric($identifier)) {
            $id = (int)$identifier;
            if (isset($this->tenantsById[$id])) {
                return $this->tenantsById[$id];
            }
        }

        $key = strtolower(trim((string)$identifier));
        return $this->tenantsByLookup[$key] ?? null;
    }

    public function setTenantModules(int $tenantId, array $moduleIds): void
    {
        $this->tenantModules[$tenantId] = [];
        foreach ($moduleIds as $id) {
            $this->tenantModules[$tenantId][$id] = true;
        }
    }
}
