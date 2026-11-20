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
 * PSR-15 Fixed-Window Rate Limiter backed by LiteCache (works on Shared Hosting without Redis).
 * Respects TrustedProxyMiddleware's `client_ip` attribute and returns 429 + Retry-After when exceeded.
 */
final class RateLimitMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly CacheInterface $cache,
        private readonly int $maxRequests = 60,
        private readonly int $decaySeconds = 60,
        private readonly string $bucket = 'default'
    ) {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $clientIp = (string)($request->getAttribute('client_ip') ?? ($request->getServerParams()['REMOTE_ADDR'] ?? '127.0.0.1'));
        $key = 'rate_limit_' . hash('sha256', $this->bucket . '|' . $clientIp);
        $now = time();

        $state = $this->cache->get($key);
        if (!is_array($state) || !isset($state['count'], $state['reset_at']) || (int)$state['reset_at'] <= $now) {
            $count = 1;
            $resetAt = $now + $this->decaySeconds;
        } else {
            $count = (int)$state['count'] + 1;
            $resetAt = (int)$state['reset_at'];
        }

        $ttl = max(1, $resetAt - $now);
        $this->cache->set($key, ['count' => $count, 'reset_at' => $resetAt], $ttl);

        $remaining = max(0, $this->maxRequests - $count);

        if ($count > $this->maxRequests) {
            $response = new Response(429);
            $response->getBody()->write((string)json_encode([
                'status'      => 429,
                'title'       => 'Too Many Requests',
                'detail'      => "Rate limit exceeded. Try again in {$ttl} seconds.",
                'retry_after' => $ttl,
            ], JSON_UNESCAPED_UNICODE));

            return $response
                ->withHeader('Content-Type', 'application/problem+json; charset=utf-8')
                ->withHeader('X-RateLimit-Limit', (string)$this->maxRequests)
                ->withHeader('X-RateLimit-Remaining', '0')
                ->withHeader('Retry-After', (string)$ttl);
        }

        return $handler->handle($request)
            ->withHeader('X-RateLimit-Limit', (string)$this->maxRequests)
            ->withHeader('X-RateLimit-Remaining', (string)$remaining);
    }
}
