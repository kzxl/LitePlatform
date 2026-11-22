<?php

declare(strict_types=1);

namespace Lite\Framework\Cron;

use DI\Container;
use Lite\Framework\Security\SecurityAuditLog;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Shared-Hosting Web Cron HTTP Endpoint (`GET /cron/run?token=...`).
 * Verified via constant-time `hash_equals` against CRON_SECRET (or APP_KEY-derived HMAC).
 */
final class CronController
{
    public function __construct(private readonly Container $container)
    {
    }

    public function run(Request $request, Response $response): Response
    {
        $config = $this->container->has('config') ? (array)$this->container->get('config') : [];
        $expected = CronRunner::resolveExpectedToken($config);

        $query = $request->getQueryParams();
        $provided = trim((string)($query['token'] ?? $request->getHeaderLine('X-Cron-Token')));

        if ($provided === '' || !hash_equals($expected, $provided)) {
            if ($this->container->has(SecurityAuditLog::class)) {
                $ip = (string)($request->getAttribute('client_ip') ?? ($request->getServerParams()['REMOTE_ADDR'] ?? ''));
                $this->container->get(SecurityAuditLog::class)->record(
                    eventType: 'cron.unauthorized',
                    severity: 'WARN',
                    actor: 'anonymous',
                    actorIp: $ip !== '' ? $ip : null,
                    target: '/cron/run'
                );
            }

            $response->getBody()->write(json_encode(['ok' => false, 'error' => 'Invalid cron token'], JSON_THROW_ON_ERROR));
            return $response->withStatus(403)->withHeader('Content-Type', 'application/json');
        }

        $forceBackup = ((string)($query['force_backup'] ?? '0')) === '1';
        $result = (new CronRunner($this->container))->run($forceBackup);

        $response->getBody()->write(json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
        return $response->withStatus(200)->withHeader('Content-Type', 'application/json');
    }
}
