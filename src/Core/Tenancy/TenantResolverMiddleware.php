<?php

declare(strict_types=1);

namespace App\Core\Tenancy;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * PSR-15 Middleware resolving tenant from Headers, Subdomain, or Query parameters.
 */
class TenantResolverMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly TenantContextInterface $tenantContext
    ) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $tenant = null;

        // 1. Resolve from Headers: X-Tenant-ID or X-Company-Code
        $headerId = $request->getHeaderLine('X-Tenant-ID');
        if ($headerId !== '') {
            $tenant = $this->tenantContext->findTenant($headerId);
        }

        if ($tenant === null) {
            $headerCode = $request->getHeaderLine('X-Company-Code');
            if ($headerCode !== '') {
                $tenant = $this->tenantContext->findTenant($headerCode);
            }
        }

        // 2. Resolve from Query Params (useful for testing/development)
        if ($tenant === null) {
            $queryParams = $request->getQueryParams();
            if (!empty($queryParams['tenant_id'])) {
                $tenant = $this->tenantContext->findTenant((string)$queryParams['tenant_id']);
            } elseif (!empty($queryParams['company'])) {
                $tenant = $this->tenantContext->findTenant((string)$queryParams['company']);
            }
        }

        // 3. Resolve from Host Header / Subdomain (e.g. "acme.app.local")
        if ($tenant === null) {
            $host = $request->getUri()->getHost();
            if ($host !== '') {
                // Check direct host match first
                $tenant = $this->tenantContext->findTenant($host);

                if ($tenant === null && str_contains($host, '.')) {
                    $subdomain = explode('.', $host)[0];
                    if ($subdomain !== 'www' && $subdomain !== 'api' && $subdomain !== 'admin') {
                        $tenant = $this->tenantContext->findTenant($subdomain);
                    }
                }
            }
        }

        // Activate tenant in context if resolved
        if ($tenant !== null) {
            $this->tenantContext->setCurrentTenant($tenant);
            $request = $request->withAttribute('tenant', $tenant);
        }

        $response = $handler->handle($request);

        // Echo resolved tenant in response header for auditability
        if ($tenant !== null) {
            $response = $response->withHeader('X-Resolved-Tenant-ID', (string)$tenant->id);
        }

        return $response;
    }
}
