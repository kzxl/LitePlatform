<?php

declare(strict_types=1);

namespace Lite\Framework\Core\Routing;

/**
 * Protocol-Agnostic Route Registry Contract.
 * Allows modules and providers to register routes as pure metadata,
 * completely independent of Slim 4 or any HTTP transport framework.
 */
interface RouteRegistryInterface
{
    /**
     * Add a route definition.
     *
     * @param string|list<string> $methods HTTP method(s) (GET, POST, PUT, DELETE, etc.)
     * @param string $path URL path pattern (e.g. "/api/inventory/stock-in")
     * @param mixed $handler Controller callable, class string, or action
     * @param list<string|object> $middlewares Middleware list
     * @param string|null $name Route identifier name
     * @param string|null $moduleId Owning module identifier
     */
    public function add(
        string|array $methods,
        string $path,
        mixed $handler,
        array $middlewares = [],
        ?string $name = null,
        ?string $moduleId = null
    ): void;

    /**
     * Register a GET route shortcut.
     */
    public function get(string $path, mixed $handler, array $middlewares = [], ?string $name = null, ?string $moduleId = null): void;

    /**
     * Register a POST route shortcut.
     */
    public function post(string $path, mixed $handler, array $middlewares = [], ?string $name = null, ?string $moduleId = null): void;

    /**
     * Register a PUT route shortcut.
     */
    public function put(string $path, mixed $handler, array $middlewares = [], ?string $name = null, ?string $moduleId = null): void;

    /**
     * Register a DELETE route shortcut.
     */
    public function delete(string $path, mixed $handler, array $middlewares = [], ?string $name = null, ?string $moduleId = null): void;

    /**
     * Get all registered routes.
     *
     * @return list<array{
     *     methods: list<string>,
     *     path: string,
     *     handler: mixed,
     *     middlewares: list<string|object>,
     *     name: ?string,
     *     moduleId: ?string
     * }>
     */
    public function getRoutes(): array;
}
