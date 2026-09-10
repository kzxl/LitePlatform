<?php

declare(strict_types=1);

namespace App\Core;

use DI\ContainerBuilder;
use DI\Container;
use Slim\App;
use Slim\Factory\AppFactory;

/**
 * Main application bootstrapper.
 * Initializes DI Container, Service Providers, Middleware, and Routes.
 */
class AppBootstrap
{
    private static ?App $app = null;
    private static ?Container $container = null;

    /**
     * Bootstrap the Slim 4 application.
     *
     * @param string $basePath Base path of the application
     * @return App
     */
    public static function boot(string $basePath): App
    {
        if (self::$app !== null) {
            return self::$app;
        }

        // 1. Load environment variables
        self::loadEnv($basePath . '/.env');

        // 2. Load configurations
        $appConfig = require $basePath . '/config/app.php';
        $dbConfig = require $basePath . '/config/database.php';
        $config = array_merge($appConfig, ['database' => $dbConfig, 'base_path' => $basePath]);

        // 3. Initialize PHP-DI Container
        $containerBuilder = new ContainerBuilder();
        $container = $containerBuilder->build();
        $container->set('config', $config);
        self::$container = $container;

        // 4. Create Slim 4 App
        AppFactory::setContainer($container);
        $app = AppFactory::create();
        $app->addBodyParsingMiddleware();
        $app->addRoutingMiddleware();

        // 5. Error Middleware
        $isDebug = (bool)($config['debug'] ?? true);
        $app->addErrorMiddleware($isDebug, true, true);

        // 6. Register & Boot Service Providers
        $providers = [];
        foreach ($config['providers'] ?? [] as $providerClass) {
            if (class_exists($providerClass) && is_subclass_of($providerClass, ServiceProviderInterface::class)) {
                $provider = new $providerClass();
                $provider->register($container, $config);
                $providers[] = $provider;
            }
        }

        foreach ($providers as $provider) {
            $provider->boot($app, $container, $config);
        }

        // 7. Load application routes
        $routesFile = $basePath . '/config/routes.php';
        if (file_exists($routesFile)) {
            $routesFn = require $routesFile;
            if (is_callable($routesFn)) {
                $routesFn($app, $container);
            }
        }

        self::$app = $app;
        return $app;
    }

    /**
     * Get the active DI container instance.
     */
    public static function getContainer(): ?Container
    {
        return self::$container;
    }

    /**
     * Minimalist zero-dependency .env file parser.
     */
    public static function loadEnv(string $envPath): void
    {
        if (!file_exists($envPath)) {
            return;
        }

        $lines = file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false) {
            return;
        }

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            if (str_contains($line, '=')) {
                [$key, $value] = explode('=', $line, 2);
                $key = trim($key);
                $value = trim($value, " \t\n\r\0\x0B\"'");
                if (!array_key_exists($key, $_ENV)) {
                    $_ENV[$key] = $value;
                    putenv("{$key}={$value}");
                }
            }
        }
    }
}
