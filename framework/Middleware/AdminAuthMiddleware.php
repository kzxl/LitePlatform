<?php

declare(strict_types=1);

namespace Lite\Framework\Middleware;

use Lite\Framework\Security\AdminToken;
use Lite\Framework\Security\RbacManager;
use Lite\Framework\Security\SecurityAuditLog;
use Nyholm\Psr7\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Lightweight stateless cookie-based auth guard for LiteAdmin dashboard.
 * Uses TTL-bound HMAC-SHA256 tokens via AdminToken with optional instant session revocation (token_version)
 * and RBAC context hydration via RbacManager.
 */
class AdminAuthMiddleware implements MiddlewareInterface
{
    public function __construct(
        private string $secret,
        private string $adminPrefix = '/admin',
        private string $env = 'development',
        private ?RbacManager $rbac = null,
        private ?SecurityAuditLog $audit = null
    ) {
        AdminToken::assertSecretSafe($this->secret, $this->env);
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $path = $request->getUri()->getPath();

        // 1. Only intercept paths starting with $adminPrefix
        if (!str_starts_with($path, $this->adminPrefix)) {
            return $handler->handle($request);
        }

        // 2. Bypass login and logout endpoints
        if ($path === $this->adminPrefix . '/login' || $path === $this->adminPrefix . '/logout') {
            return $handler->handle($request);
        }

        // 3. Verify signed, non-expired auth cookie
        $cookies = $request->getCookieParams();
        $token = $cookies['lite_admin_auth'] ?? null;

        if ($token !== null) {
            $payload = AdminToken::verifyPayload((string)$token, $this->secret);
            if ($payload !== null) {
                $username = $payload['username'];

                if ($this->rbac !== null) {
                    $ctx = $this->rbac->resolveUserContext($username);
                    if (!$ctx['is_active'] || $payload['token_version'] !== $ctx['token_version']) {
                        $ip = (string)($request->getAttribute('client_ip') ?? ($request->getServerParams()['REMOTE_ADDR'] ?? ''));
                        $this->audit?->record(
                            eventType: 'auth.session_revoked',
                            severity: 'WARN',
                            actor: $username,
                            actorIp: $ip !== '' ? $ip : null,
                            target: $path,
                            context: [
                                'token_version'    => $payload['token_version'],
                                'expected_version' => $ctx['token_version'],
                                'is_active'        => $ctx['is_active'],
                            ]
                        );
                        return (new Response())
                            ->withStatus(302)
                            ->withHeader('Location', $this->adminPrefix . '/login')
                            ->withHeader('Set-Cookie', 'lite_admin_auth=; Path=/admin; Expires=Thu, 01 Jan 1970 00:00:00 GMT; HttpOnly; SameSite=Lax');
                    }

                    $request = $request->withAttribute('auth_context', $ctx);
                }

                return $handler->handle($request->withAttribute('admin_user', $username));
            }
        }

        // 4. Redirect unauthenticated requests to login form
        return (new Response())
            ->withStatus(302)
            ->withHeader('Location', $this->adminPrefix . '/login');
    }
}
