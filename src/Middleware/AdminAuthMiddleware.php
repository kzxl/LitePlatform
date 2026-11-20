<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Security\AdminToken;
use Nyholm\Psr7\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Lightweight stateless cookie-based auth guard for LiteAdmin dashboard.
 * Uses TTL-bound HMAC-SHA256 tokens via App\Security\AdminToken.
 */
class AdminAuthMiddleware implements MiddlewareInterface
{
    public function __construct(
        private string $secret,
        private string $adminPrefix = '/admin',
        private string $env = 'development'
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
            $username = AdminToken::verify((string)$token, $this->secret);
            if ($username !== null) {
                return $handler->handle($request->withAttribute('admin_user', $username));
            }
        }

        // 4. Redirect unauthenticated requests to login form
        return (new Response())
            ->withStatus(302)
            ->withHeader('Location', $this->adminPrefix . '/login');
    }
}
