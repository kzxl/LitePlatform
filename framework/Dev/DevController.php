<?php

declare(strict_types=1);

namespace Lite\Framework\Dev;

use DI\Container;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Controller for development live-reload SSE and developer diagnostics.
 */
class DevController
{
    private string $cacheFile;
    private bool $isDebug;

    public function __construct(Container $container)
    {
        $config = $container->get('config');
        $basePath = $config['base_path'] ?? dirname(__DIR__, 2);
        $this->cacheFile = $basePath . '/storage/cache/last_change.txt';
        $this->isDebug = (bool)($config['debug'] ?? true);
    }

    /**
     * GET /dev/live-reload - Server-Sent Events stream for instant browser reload
     */
    public function liveReload(Request $request, Response $response): Response
    {
        if (!$this->isDebug) {
            return $response->withStatus(403);
        }

        $response = $response
            ->withHeader('Content-Type', 'text/event-stream')
            ->withHeader('Cache-Control', 'no-cache')
            ->withHeader('Connection', 'keep-alive')
            ->withHeader('X-Accel-Buffering', 'no');

        $body = $response->getBody();

        $lastMtime = file_exists($this->cacheFile) ? (int)file_get_contents($this->cacheFile) : time();
        $body->write(": connected\n\n");

        // Single check or immediate return to prevent worker locking
        $currentMtime = file_exists($this->cacheFile) ? (int)file_get_contents($this->cacheFile) : time();
        if ($currentMtime > $lastMtime) {
            $body->write("data: reload\n\n");
        } else {
            $body->write("data: ping\n\n");
        }

        return $response;
    }
}
