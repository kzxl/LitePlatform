<?php

declare(strict_types=1);

namespace Lite\Framework\Middleware;

use Lite\Framework\Security\SecurityAuditLog;
use LiteCache\CacheInterface;
use Nyholm\Psr7\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Lightweight Shared-Hosting Abuse Shield backed by LiteCache.
 * Accumulates violation points per IP (401/403/429 responses) and temporarily auto-bans
 * abusive clients without touching the database.
 */
final class AbuseShieldMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly CacheInterface $cache,
        private readonly int $threshold = 15,
        private readonly int $windowSeconds = 300,
        private readonly int $banSeconds = 900,
        private readonly ?SecurityAuditLog $audit = null
    ) {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $ip = (string)($request->getAttribute('client_ip') ?? ($request->getServerParams()['REMOTE_ADDR'] ?? '127.0.0.1'));
        $banKey = 'abuse_ban_' . md5($ip);

        $bannedUntil = (int)$this->cache->get($banKey, 0);
        $now = time();
        if ($bannedUntil > $now) {
            $retryAfter = max(1, $bannedUntil - $now);
            $res = new Response(429, [
                'Content-Type' => 'application/problem+json',
                'Retry-After'  => (string)$retryAfter,
            ]);
            $res->getBody()->write(json_encode([
                'title'       => 'Temporarily Blocked',
                'status'      => 429,
                'detail'      => 'Too many security violations from this IP.',
                'retry_after' => $retryAfter,
            ], JSON_THROW_ON_ERROR));
            return $res;
        }

        $response = $handler->handle($request);
        $status = $response->getStatusCode();

        $points = match ($status) {
            401 => 3,
            403 => 3,
            429 => 2,
            default => 0,
        };

        if ($points > 0) {
            $scoreKey = 'abuse_score_' . md5($ip);
            $score = ((int)$this->cache->get($scoreKey, 0)) + $points;
            $this->cache->set($scoreKey, $score, $this->windowSeconds);

            if ($score >= $this->threshold) {
                $this->cache->set($banKey, $now + $this->banSeconds, $this->banSeconds);
                $this->audit?->record(
                    eventType: 'waf.ip_auto_banned',
                    severity: 'CRITICAL',
                    actor: 'anonymous',
                    actorIp: $ip,
                    moduleId: 'core',
                    target: $request->getUri()->getPath(),
                    context: ['score' => $score, 'ban_seconds' => $this->banSeconds]
                );
            }
        }

        return $response;
    }
}
