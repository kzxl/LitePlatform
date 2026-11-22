<?php

declare(strict_types=1);

namespace Lite\Framework\Core\Plugin;

/**
 * Optional contract for plugins declaring granular RBAC permissions.
 * ModuleManager automatically syncs declared permissions and default role grants into RbacManager on boot.
 */
interface SecurityAwareModuleInterface
{
    /**
     * Declare all granular permission codes owned by this plugin.
     *
     * @return array<string, string> permissionCode => human label (e.g. ['retail.reports.export' => 'Export financial reports'])
     */
    public function getPermissions(): array;

    /**
     * Default role-to-permission grants seeded idempotently on install/boot.
     *
     * @return array<string, list<string>> roleSlug => list<permissionCode>
     */
    public function getDefaultRoleGrants(): array;
}
