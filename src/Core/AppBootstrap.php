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

        $kernel = Kernel::create($basePath);
        self::$container = $kernel->getContainer();
        self::$app = $kernel->createHttpApp();

        return self::$app;
    }

    /**
     * Compile routes into a static cache file for extreme dispatch performance.
     */
    public static function compileRouteCache(string $basePath): string
    {
        self::reset();
        $cacheDir = $basePath . '/storage/cache';
        if (!is_dir($cacheDir)) {
            mkdir($cacheDir, 0777, true);
        }
        $cacheFile = $cacheDir . '/routes.cache';
        if (file_exists($cacheFile)) {
            unlink($cacheFile);
        }

        $app = self::boot($basePath);
        $app->getRouteCollector()->setCacheFile($cacheFile);
        $app->getRouteResolver()->computeRoutingResults('/', 'GET');

        return $cacheFile;
    }

    /**
     * Clear the compiled route cache file.
     */
    public static function clearRouteCache(string $basePath): bool
    {
        $cacheFile = $basePath . '/storage/cache/routes.cache';
        if (file_exists($cacheFile)) {
            return unlink($cacheFile);
        }
        return false;
    }

    /**
     * Reset kernel singleton instances.
     */
    public static function reset(): void
    {
        self::$app = null;
        self::$container = null;
        Kernel::reset();
    }

    /**
     * Get the active Kernel instance.
     */
    public static function getKernel(): ?KernelInterface
    {
        return Kernel::getInstance();
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
