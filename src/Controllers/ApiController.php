<?php

declare(strict_types=1);

namespace App\Controllers;

use DI\Container;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Controller demonstrating JSON REST API responses with LiteApi.
 */
class ApiController
{
    public function __construct(private Container $container)
    {
    }

    /**
     * Return JSON system status & active modules.
     */
    public function status(Request $request, Response $response): Response
    {
        $data = [
            'status'      => 'healthy',
            'framework'   => 'LitePlatform Skeleton',
            'php_version' => PHP_VERSION,
            'memory_mb'   => round(memory_get_usage(true) / 1024 / 1024, 2),
            'timestamp'   => time(),
            'modules'     => [
                'orm'      => class_exists(\LiteORM\EntityManager::class),
                'view'     => class_exists(\LiteView\ViewEngine::class),
                'admin'    => class_exists(\LiteAdmin\AdminDashboard::class),
                'api'      => class_exists(\LiteApi\Security\Jwt::class),
                'queue'    => class_exists(\LiteQueue\QueueManager::class),
                'validate' => class_exists(\LiteValidate\Validator::class),
                'export'   => class_exists(\LiteExport\Exporter::class),
                'audit'    => class_exists(\LiteAudit\AuditManager::class),
                'inertia'  => class_exists(\LiteInertia\Inertia::class),
                'util'     => class_exists(\LiteUtil\Number\NumberToWords::class),
            ],
        ];

        if (class_exists(\LiteApi\Http\ApiResponse::class)) {
            return \LiteApi\Http\ApiResponse::ok($response, $data);
        }

        $response->getBody()->write(json_encode($data, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
        return $response->withHeader('Content-Type', 'application/json');
    }

    /**
     * Demo users list endpoint with LiteORM query or fallback.
     */
    public function users(Request $request, Response $response): Response
    {
        $users = [];

        if ($this->container->has(\LiteORM\EntityManager::class)) {
            /** @var \LiteORM\EntityManager $em */
            $em = $this->container->get(\LiteORM\EntityManager::class);
            try {
                $users = $em->query(\App\Entities\User::class)
                    ->orderBy('id', 'ASC')
                    ->limit(20)
                    ->get();
            } catch (\Throwable $e) {
                // Table may not exist yet in fresh install
                $users = [
                    ['id' => 1, 'name' => 'Demo Admin', 'email' => 'admin@example.com', 'role' => 'admin'],
                    ['id' => 2, 'name' => 'Demo Member', 'email' => 'user@example.com', 'role' => 'user'],
                ];
            }
        } else {
            $users = [
                ['id' => 1, 'name' => 'Demo User', 'email' => 'user@example.com', 'role' => 'user'],
            ];
        }

        if (class_exists(\LiteApi\Http\ApiResponse::class)) {
            return \LiteApi\Http\ApiResponse::ok($response, $users, ['count' => count($users)]);
        }

        $response->getBody()->write(json_encode(['success' => true, 'data' => $users], JSON_THROW_ON_ERROR));
        return $response->withHeader('Content-Type', 'application/json');
    }
}
