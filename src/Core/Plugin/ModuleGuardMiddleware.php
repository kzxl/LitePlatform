<?php

declare(strict_types=1);

namespace App\Core\Plugin;

use App\Core\Tenancy\TenantContextInterface;
use Nyholm\Psr7\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * PSR-15 Middleware ensuring that a tenant only accesses routes of enabled modules.
 */
class ModuleGuardMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly string $moduleId,
        private readonly ModuleManagerInterface $moduleManager,
        private readonly TenantContextInterface $tenantContext
    ) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $tenant = $this->tenantContext->getCurrentTenant();
        $tenantId = $tenant?->id;

        $isAllowed = $this->moduleManager->isEnabledForTenant($tenantId, $this->moduleId);
        if (!$isAllowed) {
            $response = new Response(403, ['Content-Type' => 'application/problem+json']);
            $payload = [
                'type' => 'https://liteplatform.kzxl.com/errors/module-disabled',
                'title' => 'Module Access Denied',
                'status' => 403,
                'detail' => sprintf(
                    'Module "%s" is not enabled for tenant "%s" (ID: %s).',
                    $this->moduleId,
                    $tenant?->name ?? 'Unknown',
                    $tenantId ?? 'none'
                ),
            ];
            $response->getBody()->write(json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
            return $response;
        }

        return $handler->handle($request);
    }
}
