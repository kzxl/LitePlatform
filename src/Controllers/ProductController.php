<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Entities\Product;
use DI\Container;
use LiteApi\Http\ApiResponse;
use LiteORM\EntityManager;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Controller providing REST API CRUD operations for Product.
 */
class ProductController
{
    private ?EntityManager $em;

    public function __construct(private Container $container)
    {
        $this->em = $container->has(EntityManager::class) ? $container->get(EntityManager::class) : null;
    }

    /**
     * GET /api/products - List records with pagination
     */
    public function index(Request $request, Response $response): Response
    {
        if (!$this->em) {
            return ApiResponse::error($response, 'ORM not configured', 500);
        }

        $params = $request->getQueryParams();
        $page = max(1, (int)($params['page'] ?? 1));
        $limit = min(100, max(1, (int)($params['limit'] ?? 20)));

        $items = $this->em->query(Product::class)
            ->orderBy('id', 'DESC')
            ->limit($limit)
            ->offset(($page - 1) * $limit)
            ->get();

        return ApiResponse::ok($response, $items, ['page' => $page, 'limit' => $limit]);
    }

    /**
     * GET /api/products/{id} - Get single record
     */
    public function show(Request $request, Response $response, array $args): Response
    {
        if (!$this->em) {
            return ApiResponse::error($response, 'ORM not configured', 500);
        }

        $id = (int)$args['id'];
        $item = $this->em->find(Product::class, $id);

        if (!$item) {
            return ApiResponse::error($response, 'Product not found', 404);
        }

        return ApiResponse::ok($response, $item);
    }

    /**
     * POST /api/products - Create new record
     */
    public function store(Request $request, Response $response): Response
    {
        if (!$this->em) {
            return ApiResponse::error($response, 'ORM not configured', 500);
        }

        $body = (array)$request->getParsedBody();
        $item = new Product();

        foreach ($body as $key => $val) {
            if (property_exists($item, $key) && $key !== 'id') {
                $ref = new \ReflectionProperty($item, $key);
                $type = $ref->getType();
                if ($type instanceof \ReflectionNamedType) {
                    $val = match ($type->getName()) {
                        'bool' => (bool)$val,
                        'int' => (int)$val,
                        'float' => (float)$val,
                        'string' => (string)$val,
                        default => $val,
                    };
                }
                $item->{$key} = $val;
            }
        }

        $this->em->persist($item);
        $this->em->flush();

        return ApiResponse::created($response, $item);
    }

    /**
     * PUT/PATCH /api/products/{id} - Update record
     */
    public function update(Request $request, Response $response, array $args): Response
    {
        if (!$this->em) {
            return ApiResponse::error($response, 'ORM not configured', 500);
        }

        $id = (int)$args['id'];
        $item = $this->em->find(Product::class, $id);

        if (!$item) {
            return ApiResponse::error($response, 'Product not found', 404);
        }

        $body = (array)$request->getParsedBody();
        foreach ($body as $key => $val) {
            if (property_exists($item, $key) && $key !== 'id') {
                $ref = new \ReflectionProperty($item, $key);
                $type = $ref->getType();
                if ($type instanceof \ReflectionNamedType) {
                    $val = match ($type->getName()) {
                        'bool' => (bool)$val,
                        'int' => (int)$val,
                        'float' => (float)$val,
                        'string' => (string)$val,
                        default => $val,
                    };
                }
                $item->{$key} = $val;
            }
        }

        $this->em->flush();
        return ApiResponse::ok($response, $item);
    }

    /**
     * DELETE /api/products/{id} - Delete record
     */
    public function destroy(Request $request, Response $response, array $args): Response
    {
        if (!$this->em) {
            return ApiResponse::error($response, 'ORM not configured', 500);
        }

        $id = (int)$args['id'];
        $item = $this->em->find(Product::class, $id);

        if (!$item) {
            return ApiResponse::error($response, 'Product not found', 404);
        }

        $this->em->remove($item);
        $this->em->flush();

        return ApiResponse::ok($response, ['message' => 'Deleted successfully']);
    }
}