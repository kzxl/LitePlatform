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
    /** @var list<string> Explicit mass-assignment allowlist */
    private const FILLABLE = ['name', 'price', 'stock', 'is_active'];

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
        $name = trim((string)($body['name'] ?? ''));
        if ($name === '') {
            return ApiResponse::error($response, 'Product name is required', 422);
        }

        $price = (float)($body['price'] ?? 0);
        $stock = (int)($body['stock'] ?? 0);
        if ($price < 0 || $stock < 0) {
            return ApiResponse::error($response, 'Price and stock must be non-negative', 422);
        }

        $item = new Product();
        $this->hydrateAllowed($item, $body);

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
        if (array_key_exists('price', $body) && (float)$body['price'] < 0) {
            return ApiResponse::error($response, 'Price must be non-negative', 422);
        }
        if (array_key_exists('stock', $body) && (int)$body['stock'] < 0) {
            return ApiResponse::error($response, 'Stock must be non-negative', 422);
        }

        $this->hydrateAllowed($item, $body);

        $this->em->flush();
        return ApiResponse::ok($response, $item);
    }

    /**
     * Hydrate only explicitly allowed fields onto Product entity.
     *
     * @param array<string, mixed> $body
     */
    private function hydrateAllowed(Product $item, array $body): void
    {
        foreach (self::FILLABLE as $field) {
            if (!array_key_exists($field, $body)) {
                continue;
            }
            $val = $body[$field];
            $item->{$field} = match ($field) {
                'name'      => trim((string)$val),
                'price'     => (float)$val,
                'stock'     => (int)$val,
                'is_active' => filter_var($val, FILTER_VALIDATE_BOOLEAN),
                default     => $val,
            };
        }
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