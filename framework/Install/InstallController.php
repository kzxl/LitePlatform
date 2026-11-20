<?php

declare(strict_types=1);

namespace Lite\Framework\Install;

use DI\Container;
use LiteView\ViewEngine;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Exception\HttpNotFoundException;

/**
 * WordPress-style First-Run Web Installer Controller (/install).
 * Automatically returns 404 Not Found once storage/installed.lock exists or in production with .env.
 */
final class InstallController
{
    public function __construct(
        private readonly Container $container,
        private readonly InstallerService $installer = new InstallerService()
    ) {
    }

    public function show(Request $request, Response $response): Response
    {
        $basePath = $this->resolveBasePath();
        if (InstallerService::isInstalled($basePath)) {
            throw new HttpNotFoundException($request);
        }

        return $this->renderWizard($request, $response);
    }

    public function testDb(Request $request, Response $response): Response
    {
        $basePath = $this->resolveBasePath();
        if (InstallerService::isInstalled($basePath)) {
            throw new HttpNotFoundException($request);
        }

        $body = (array)$request->getParsedBody();
        $result = $this->installer->testDatabaseConnection($body, $basePath);

        $response->getBody()->write((string)json_encode($result, JSON_UNESCAPED_UNICODE));
        return $response
            ->withStatus($result['ok'] ? 200 : 422)
            ->withHeader('Content-Type', 'application/json; charset=utf-8');
    }

    public function run(Request $request, Response $response): Response
    {
        $basePath = $this->resolveBasePath();
        if (InstallerService::isInstalled($basePath)) {
            throw new HttpNotFoundException($request);
        }

        $body = (array)$request->getParsedBody();
        $result = $this->installer->install($basePath, $body);

        if (!$result['ok']) {
            return $this->renderWizard(
                $request,
                $response->withStatus(422),
                errors: $result['errors'],
                old: $body
            );
        }

        return $this->renderWizard(
            $request,
            $response,
            completed: true,
            old: $body
        );
    }

    /**
     * @param list<string> $errors
     * @param array<string, mixed> $old
     */
    private function renderWizard(
        Request $request,
        Response $response,
        array $errors = [],
        array $old = [],
        bool $completed = false
    ): Response {
        $basePath = $this->resolveBasePath();
        $preflight = $this->installer->checkRequirements($basePath);
        $uri = $request->getUri();
        $scheme = $uri->getScheme() ?: 'http';
        $host = $uri->getHost() ?: 'localhost';
        $port = $uri->getPort();
        $detectedUrl = $scheme . '://' . $host . ($port && !in_array($port, [80, 443], true) ? ':' . $port : '');

        $locale = (string)($request->getAttribute('locale') ?? ($request->getQueryParams()['lang'] ?? 'vi'));
        if (!in_array($locale, ['vi', 'en'], true)) {
            $locale = 'vi';
        }

        /** @var ViewEngine $view */
        $view = $this->container->get(ViewEngine::class);

        return $view->renderResponse($response, 'install', [
            'locale'      => $locale,
            'preflight'   => $preflight,
            'detectedUrl' => $detectedUrl,
            'errors'      => $errors,
            'old'         => $old,
            'completed'   => $completed,
            'csrfToken'   => (string)$request->getAttribute('csrf_token', ''),
        ]);
    }

    private function resolveBasePath(): string
    {
        $config = $this->container->has('config') ? (array)$this->container->get('config') : [];
        return (string)($config['base_path'] ?? dirname(__DIR__, 2));
    }
}
