<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Http;

use App\Modules\Inventory\Application\GetStockSummaryUseCase;
use Nyholm\Psr7\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Thin HTTP Controller adapter for the Inventory Module.
 */
class InventoryController
{
    public function __construct(
        private readonly GetStockSummaryUseCase $useCase
    ) {}

    public function summary(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $filters = $request->getQueryParams();
        $data = $this->useCase->execute($filters);

        $response->getBody()->write(json_encode([
            'status' => 'success',
            'data' => $data,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        return $response->withHeader('Content-Type', 'application/json');
    }
}
