<?php

declare(strict_types=1);

namespace App\Controllers;

use Lite\Framework\Core\Plugin\ModuleManagerInterface;
use Lite\Framework\Core\Tenancy\TenantContextInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Admin Controller for Dynamic Module and Tenant Management.
 */
class AdminModuleController
{
    public function __construct(
        private readonly ModuleManagerInterface $moduleManager,
        private readonly TenantContextInterface $tenantContext
    ) {}

    /**
     * Return list of all installed modules and their manifests.
     */
    public function listModules(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $modules = [];
        foreach ($this->moduleManager->getModules() as $id => $module) {
            $modules[] = [
                'id' => $id,
                'name' => $module->getName(),
                'version' => $module->getVersion(),
                'manifest' => $module->getManifest(),
            ];
        }

        $response->getBody()->write(json_encode([
            'status' => 'success',
            'data' => [
                'total' => count($modules),
                'modules' => $modules,
            ],
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        return $response->withHeader('Content-Type', 'application/json');
    }

    /**
     * Toggle module activation for a tenant.
     */
    public function toggleTenantModule(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $body = (array)$request->getParsedBody();
        $tenantId = (int)($body['tenant_id'] ?? 0);
        $moduleId = (string)($body['module_id'] ?? '');
        $enable = (bool)($body['enable'] ?? true);

        if ($tenantId <= 0 || empty($moduleId)) {
            $response->getBody()->write(json_encode([
                'status' => 'error',
                'message' => 'tenant_id and module_id are required',
            ], JSON_THROW_ON_ERROR));
            return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
        }

        if ($enable) {
            $this->moduleManager->enableForTenant($tenantId, $moduleId);
        } else {
            $this->moduleManager->disableForTenant($tenantId, $moduleId);
        }

        $response->getBody()->write(json_encode([
            'status' => 'success',
            'message' => sprintf(
                'Module "%s" has been %s for tenant %d',
                $moduleId,
                $enable ? 'enabled' : 'disabled',
                $tenantId
            ),
            'data' => [
                'tenant_id' => $tenantId,
                'module_id' => $moduleId,
                'is_enabled' => $this->moduleManager->isEnabledForTenant($tenantId, $moduleId),
            ],
        ], JSON_THROW_ON_ERROR));

        return $response->withHeader('Content-Type', 'application/json');
    }
}
