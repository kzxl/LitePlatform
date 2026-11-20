<?php

declare(strict_types=1);

namespace Lite\Framework\Core\Routing;

/**
 * Protocol-Agnostic In-Memory Route Registry.
 */
class RouteRegistry implements RouteRegistryInterface
{
    /**
     * @var list<array{
     *     methods: list<string>,
     *     path: string,
     *     handler: mixed,
     *     middlewares: list<string|object>,
     *     name: ?string,
     *     moduleId: ?string
     * }>
     */
    private array $routes = [];

    public function add(
        string|array $methods,
        string $path,
        mixed $handler,
        array $middlewares = [],
        ?string $name = null,
        ?string $moduleId = null
    ): void {
        $methodList = array_map('strtoupper', (array)$methods);
        $this->routes[] = [
            'methods' => $methodList,
            'path' => '/' . ltrim($path, '/'),
            'handler' => $handler,
            'middlewares' => $middlewares,
            'name' => $name,
            'moduleId' => $moduleId,
        ];
    }

    public function get(string $path, mixed $handler, array $middlewares = [], ?string $name = null, ?string $moduleId = null): void
    {
        $this->add(['GET'], $path, $handler, $middlewares, $name, $moduleId);
    }

    public function post(string $path, mixed $handler, array $middlewares = [], ?string $name = null, ?string $moduleId = null): void
    {
        $this->add(['POST'], $path, $handler, $middlewares, $name, $moduleId);
    }

    public function put(string $path, mixed $handler, array $middlewares = [], ?string $name = null, ?string $moduleId = null): void
    {
        $this->add(['PUT'], $path, $handler, $middlewares, $name, $moduleId);
    }

    public function delete(string $path, mixed $handler, array $middlewares = [], ?string $name = null, ?string $moduleId = null): void
    {
        $this->add(['DELETE'], $path, $handler, $middlewares, $name, $moduleId);
    }

    public function getRoutes(): array
    {
        return $this->routes;
    }
}
