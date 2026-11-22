<?php

declare(strict_types=1);

namespace Lite\Framework\Middleware;

use Lite\Framework\Security\RbacManager;
use Lite\Framework\Security\SecurityAuditLog;
use Nyholm\Psr7\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Route-level RBAC enforcement middleware.
 * Verifies the authenticated actor possesses the required permission code via RbacManager,
 * returning HTTP 403 and logging a `rbac.denied` security audit event on denial.
 */
final class RequirePermissionMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly string $permission,
        private readonly RbacManager $rbac,
        private readonly ?SecurityAuditLog $audit = null,
        private readonly string $moduleId = 'core'
    ) {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $actor = (string)($request->getAttribute('admin_user') ?? $request->getAttribute('auth_user') ?? '');
        if ($actor !== '' && $this->rbac->userHasPermission($actor, $this->permission)) {
            return $handler->handle($request);
        }

        $ip = (string)($request->getAttribute('client_ip') ?? ($request->getServerParams()['REMOTE_ADDR'] ?? ''));
        $this->audit?->record(
            eventType: 'rbac.denied',
            severity: 'WARN',
            actor: $actor !== '' ? $actor : 'anonymous',
            actorIp: $ip !== '' ? $ip : null,
            moduleId: $this->moduleId,
            target: $request->getUri()->getPath(),
            context: ['required_permission' => $this->permission]
        );

        $response = new Response(403, ['Content-Type' => 'text/html; charset=UTF-8']);
        $response->getBody()->write(
            '<h1>403 Forbidden</h1><p>Missing required permission: <code>'
            . htmlspecialchars($this->permission, ENT_QUOTES, 'UTF-8')
            . '</code>.</p>'
        );
        return $response;
    }
}
