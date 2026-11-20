<?php

declare(strict_types=1);

namespace Lite\Framework\Middleware;

use Nyholm\Psr7\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Handles 301/302 URL redirects before routing so slug changes preserve SEO ranking.
 * Supports static map ['/old-slug' => '/new-slug'] or ['/old' => ['to' => '/new', 'status' => 301]].
 */
final class RedirectMiddleware implements MiddlewareInterface
{
    /**
     * @param array<string, string|array{to: string, status?: int}> $redirects
     */
    public function __construct(
        private readonly array $redirects = []
    ) {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if ($this->redirects === []) {
            return $handler->handle($request);
        }

        $method = strtoupper($request->getMethod());
        if (!in_array($method, ['GET', 'HEAD'], true)) {
            return $handler->handle($request);
        }

        $path = '/' . ltrim($request->getUri()->getPath(), '/');
        $normalized = $path !== '/' ? rtrim($path, '/') : '/';

        $rule = $this->redirects[$path] ?? $this->redirects[$normalized] ?? null;
        if ($rule === null) {
            return $handler->handle($request);
        }

        if (is_array($rule)) {
            $target = (string)($rule['to'] ?? '/');
            $status = (int)($rule['status'] ?? 301);
        } else {
            $target = (string)$rule;
            $status = 301;
        }

        return (new Response($status))
            ->withHeader('Location', $target)
            ->withHeader('Cache-Control', $status === 301 ? 'public, max-age=86400' : 'no-store');
    }
}
