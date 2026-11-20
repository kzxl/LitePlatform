<?php

declare(strict_types=1);

namespace Lite\Framework\Middleware;

use Lite\Framework\Install\InstallerService;
use Nyholm\Psr7\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Automatically redirects fresh Shared Hosting deployments (no .env and no storage/installed.lock)
 * to the /install Setup Wizard — mimicking WordPress's famous first-run experience.
 */
final class InstallRedirectMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly string $basePath
    ) {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if (!InstallerService::needsRedirectToInstall($this->basePath)) {
            return $handler->handle($request);
        }

        $path = $request->getUri()->getPath();
        if (
            str_starts_with($path, '/install')
            || str_starts_with($path, '/lang/')
            || str_starts_with($path, '/assets/')
        ) {
            return $handler->handle($request);
        }

        return (new Response(302))->withHeader('Location', '/install');
    }
}
