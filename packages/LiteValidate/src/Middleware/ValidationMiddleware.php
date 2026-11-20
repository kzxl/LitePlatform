<?php

declare(strict_types=1);

namespace LiteValidate\Middleware;

use LiteApi\Http\ApiResponse;
use LiteValidate\Validator;
use Psr\Http\Message\{ResponseFactoryInterface, ResponseInterface, ServerRequestInterface};
use Psr\Http\Server\{MiddlewareInterface, RequestHandlerInterface};

/**
 * PSR-15 Validation Middleware for Slim 4.
 * Intercepts incoming request body, validates and hydrates against a DTO class,
 * injects the typed DTO into request attributes, or returns 422 Unprocessable Entity on failure.
 */
class ValidationMiddleware implements MiddlewareInterface
{
    /**
     * @param class-string $dtoClass
     * @param ResponseFactoryInterface|null $responseFactory
     * @param string $attributeName Attribute name to store the hydrated DTO (default: 'dto')
     */
    public function __construct(
        private readonly string $dtoClass,
        private readonly ?ResponseFactoryInterface $responseFactory = null,
        private readonly string $attributeName = 'dto',
    ) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $body = (array) ($request->getParsedBody() ?? []);

        $result = Validator::validate($body, $this->dtoClass);

        if ($result->isFailed()) {
            if (class_exists(ApiResponse::class) && $this->responseFactory) {
                $response = $this->responseFactory->createResponse(422);
                return ApiResponse::error(
                    response: $response,
                    message: 'The given data was invalid.',
                    status: 422,
                    errorCode: 'VALIDATION_FAILED',
                    details: $result->getErrors(),
                );
            }

            // Standalone fallback if LiteApi is not injected
            if ($this->responseFactory) {
                $response = $this->responseFactory->createResponse(422)
                    ->withHeader('Content-Type', 'application/json; charset=utf-8');
                $response->getBody()->write((string) json_encode([
                    'success' => false,
                    'error' => [
                        'code' => 422,
                        'message' => 'The given data was invalid.',
                        'type' => 'VALIDATION_FAILED',
                        'details' => $result->getErrors(),
                    ],
                ]));
                return $response;
            }

            throw $result->toException();
        }

        $requestWithDto = $request->withAttribute($this->attributeName, $result->validated());
        return $handler->handle($requestWithDto);
    }
}
