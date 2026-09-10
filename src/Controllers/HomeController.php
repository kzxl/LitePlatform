<?php

declare(strict_types=1);

namespace App\Controllers;

use DI\Container;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Controller demonstrating SSR, HTMX fragment swaps, and system metrics.
 */
class HomeController
{
    public function __construct(private Container $container)
    {
    }

    /**
     * Render the main dashboard welcome view.
     */
    public function index(Request $request, Response $response): Response
    {
        if (!$this->container->has(\LiteView\ViewEngine::class)) {
            $response->getBody()->write('<h1>LitePlatform</h1><p>ViewEngine is disabled or not installed.</p>');
            return $response->withHeader('Content-Type', 'text/html');
        }

        /** @var \LiteView\ViewEngine $view */
        $view = $this->container->get(\LiteView\ViewEngine::class);

        $data = $this->getDashboardData();

        // Check for HTMX partial swap request
        if ($request->getHeaderLine('HX-Target') === 'metrics-container') {
            $html = $view->renderFragment('home', 'metrics', $data);
            $response->getBody()->write($html);
            return $response->withHeader('Content-Type', 'text/html; charset=utf-8');
        }

        $html = $view->render('home', $data);
        $response->getBody()->write($html);
        return $response->withHeader('Content-Type', 'text/html; charset=utf-8');
    }

    /**
     * Direct HTMX fragment endpoint for live metrics refresh.
     */
    public function metrics(Request $request, Response $response): Response
    {
        /** @var \LiteView\ViewEngine $view */
        $view = $this->container->get(\LiteView\ViewEngine::class);
        $data = $this->getDashboardData();

        $html = $view->renderFragment('home', 'metrics', $data);
        $response->getBody()->write($html);
        return $response->withHeader('Content-Type', 'text/html; charset=utf-8');
    }

    /**
     * Gather system health and active Lite component status.
     *
     * @return array<string, mixed>
     */
    private function getDashboardData(): array
    {
        $components = [
            'LiteORM'      => class_exists(\LiteORM\EntityManager::class),
            'LiteView'     => class_exists(\LiteView\ViewEngine::class),
            'LiteAdmin'    => class_exists(\LiteAdmin\AdminDashboard::class),
            'LiteApi'      => class_exists(\LiteApi\Security\Jwt::class),
            'LiteQueue'    => class_exists(\LiteQueue\QueueManager::class),
            'LiteValidate' => class_exists(\LiteValidate\Validator::class),
            'LiteExport'   => class_exists(\LiteExport\Exporter::class),
            'LiteAudit'    => class_exists(\LiteAudit\AuditManager::class),
            'LiteInertia'  => class_exists(\LiteInertia\Inertia::class),
            'LiteUtil'     => class_exists(\LiteUtil\Number\NumberToWords::class),
        ];

        return [
            'title'        => 'LitePlatform — Sovereign Web Skeleton',
            'phpVersion'   => PHP_VERSION,
            'memoryUsage'  => round(memory_get_usage(true) / 1024 / 1024, 2) . ' MB',
            'memoryPeak'   => round(memory_get_peak_usage(true) / 1024 / 1024, 2) . ' MB',
            'timestamp'    => date('Y-m-d H:i:s'),
            'components'   => $components,
        ];
    }
}
