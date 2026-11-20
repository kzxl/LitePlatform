<?php

declare(strict_types=1);

namespace Lite\Framework\Middleware;

use LiteCache\CacheInterface;
use Nyholm\Psr7\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Full-page HTML response cache backed by LiteCache for Shared Hosting high-throughput delivery.
 * Automatically bypasses authenticated users, admin/API/dev routes, HTMX partials, and query strings.
 */
final class PageCacheMiddleware implements MiddlewareInterface
{
    /**
     * @param list<string> $bypassPrefixes
     * @param list<string> $authCookies
     */
    public function __construct(
        private readonly CacheInterface $cache,
        private readonly bool $enabled = true,
        private readonly int $ttlSeconds = 300,
        private readonly array $bypassPrefixes = ['/admin', '/api', '/_studio', '/dev'],
        private readonly array $authCookies = ['lite_admin_auth', 'PHPSESSID']
    ) {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if (!$this->enabled || !$this->isCacheableRequest($request)) {
            return $handler->handle($request);
        }

        $path = $request->getUri()->getPath();
        $host = $request->getUri()->getHost() ?: 'default';
        $cookies = $request->getCookieParams();
        $locale = (string)($request->getAttribute('locale') ?? $cookies[LocaleMiddleware::COOKIE_NAME] ?? 'default');
        $cacheKey = 'page_html_' . hash('sha256', $host . '|' . $locale . '|' . $path);

        $cached = $this->cache->get($cacheKey);
        if (is_array($cached) && isset($cached['body'], $cached['content_type'])) {
            $response = new Response(200);
            $response->getBody()->write((string)$cached['body']);

            return $response
                ->withHeader('Content-Type', (string)$cached['content_type'])
                ->withHeader('X-Page-Cache', 'HIT');
        }

        $response = $handler->handle($request);

        if ($this->isCacheableResponse($response)) {
            $body = (string)$response->getBody();
            $contentType = $response->getHeaderLine('Content-Type') ?: 'text/html; charset=utf-8';
            $routeTag = 'route_' . trim(str_replace('/', '_', $path), '_');
            if ($routeTag === 'route_') {
                $routeTag = 'route_home';
            }

            $this->cache->tags(['page_cache', $routeTag])->set($cacheKey, [
                'body'         => $body,
                'content_type' => $contentType,
                'cached_at'    => time(),
            ], $this->ttlSeconds);

            return $response->withHeader('X-Page-Cache', 'MISS');
        }

        return $response->withHeader('X-Page-Cache', 'BYPASS');
    }

    public function invalidateAll(): void
    {
        $this->cache->tags(['page_cache'])->flushTags();
    }

    public function invalidateTag(string $tag): void
    {
        $this->cache->tags([$tag])->flushTags();
    }

    private function isCacheableRequest(ServerRequestInterface $request): bool
    {
        if (strtoupper($request->getMethod()) !== 'GET') {
            return false;
        }

        if ($request->getUri()->getQuery() !== '') {
            return false;
        }

        if ($request->getHeaderLine('HX-Request') === 'true') {
            return false;
        }

        $path = $request->getUri()->getPath();
        foreach ($this->bypassPrefixes as $prefix) {
            if (str_starts_with($path, $prefix)) {
                return false;
            }
        }

        $cookies = $request->getCookieParams();
        foreach ($this->authCookies as $cookieName) {
            if (!empty($cookies[$cookieName])) {
                return false;
            }
        }

        return true;
    }

    private function isCacheableResponse(ResponseInterface $response): bool
    {
        if ($response->getStatusCode() !== 200) {
            return false;
        }

        $contentType = strtolower($response->getHeaderLine('Content-Type'));
        if (!str_contains($contentType, 'text/html')) {
            return false;
        }

        $cacheControl = strtolower($response->getHeaderLine('Cache-Control'));
        if (str_contains($cacheControl, 'no-store') || str_contains($cacheControl, 'private')) {
            return false;
        }

        return true;
    }
}
