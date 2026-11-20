<?php

declare(strict_types=1);

namespace App\Core\Http;

use App\Core\KernelInterface;
use App\Core\Plugin\ModuleGuardMiddleware;
use App\Core\Tenancy\TenantResolverMiddleware;
use App\Middleware\SecurityHeadersMiddleware;
use App\Security\CsrfGuard;
use Slim\App;
use Slim\Factory\AppFactory;

/**
 * Slim 4 Pluggable HTTP Transport Adapter.
 * Bridges the protocol-agnostic Kernel and RouteRegistry to the Slim 4 pipeline.
 */
class SlimHttpAdapter implements HttpEngineInterface
{
    public function buildApp(KernelInterface $kernel, array $providers): App
    {
        $container = $kernel->getContainer();
        $config = $kernel->getConfig();
        $basePath = $kernel->getBasePath();

        // 1. Create Slim 4 App backed by the kernel DI container
        AppFactory::setContainer($container);
        $app = AppFactory::create();

        // 2. Route caching support
        $cacheFile = $basePath . '/storage/cache/routes.cache';
        if (file_exists($cacheFile)) {
            $app->getRouteCollector()->setCacheFile($cacheFile);
        }

        $app->addBodyParsingMiddleware();
        $app->addRoutingMiddleware();

        // 3. Error Middleware
        $isDebug = (bool)($config['debug'] ?? true);
        $app->addErrorMiddleware($isDebug, true, true);

        // 4. Optional Multi-Tenancy Middleware Pipeline (opt-out on single-domain Shared Hosting)
        if ((bool)($config['tenancy']['enabled'] ?? true)) {
            $app->add(new TenantResolverMiddleware($kernel->getTenantContext()));
        }

        // 4b. Security Hardening Pipeline (CSRF Guard + Baseline Security Headers)
        $securityCfg = (array)($config['security'] ?? []);
        $csrfGuard = new CsrfGuard(
            secret: (string)($securityCfg['csrf_secret'] ?? 'csrf-secret-key-for-stateless-tokens'),
            enabled: (bool)($securityCfg['csrf_enabled'] ?? true),
            exemptPrefixes: ['/api/'],
            secureCookie: (bool)($securityCfg['cookie_secure'] ?? false)
        );
        $container->set(CsrfGuard::class, $csrfGuard);
        $app->add($csrfGuard);
        $app->add(new SecurityHeadersMiddleware((bool)($securityCfg['hsts_enabled'] ?? false)));

        // 5. Boot legacy/current Service Providers with Slim App instance
        foreach ($providers as $provider) {
            $provider->boot($app, $container, $config);
        }

        // 6. Mount routes registered through the protocol-agnostic RouteRegistry
        $moduleManager = $kernel->getModuleManager();
        $tenantContext = $kernel->getTenantContext();

        foreach ($kernel->getRouteRegistry()->getRoutes() as $routeDef) {
            $route = $app->map($routeDef['methods'], $routeDef['path'], $routeDef['handler']);

            if ($routeDef['name'] !== null) {
                $route->setName($routeDef['name']);
            }

            // If route belongs to a module, attach ModuleGuardMiddleware
            if ($routeDef['moduleId'] !== null) {
                $route->add(new ModuleGuardMiddleware($routeDef['moduleId'], $moduleManager, $tenantContext));
            }

            // Attach route-specific middlewares
            foreach ($routeDef['middlewares'] as $middleware) {
                if (is_string($middleware) && $container->has($middleware)) {
                    $middleware = $container->get($middleware);
                }
                $route->add($middleware);
            }
        }

        // 7. Load application routes file (config/routes.php) for backward compatibility
        $routesFile = $basePath . '/config/routes.php';
        if (file_exists($routesFile)) {
            $routesFn = require $routesFile;
            if (is_callable($routesFn)) {
                $routesFn($app, $container);
            }
        }

        return $app;
    }
}
