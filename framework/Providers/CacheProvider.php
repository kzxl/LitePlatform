<?php

declare(strict_types=1);

namespace Lite\Framework\Providers;

use Lite\Framework\Core\ServiceProviderInterface;
use Lite\Framework\Middleware\PageCacheMiddleware;
use DI\Container;
use LiteCache\CacheInterface;
use LiteCache\CacheManager;
use PDO;
use Psr\SimpleCache\CacheInterface as Psr16CacheInterface;
use Slim\App;

/**
 * Service provider for LiteCache (FileOpCache, Database, or Memory driver)
 * and optional Shared Hosting full-page HTML caching.
 */
final class CacheProvider implements ServiceProviderInterface
{
    public function register(Container $container, array $config): void
    {
        if (!class_exists(CacheManager::class)) {
            return;
        }

        $cacheConfig = $config['cache'] ?? [];
        $driver = strtolower((string)($cacheConfig['driver'] ?? 'file'));
        $basePath = (string)($config['base_path'] ?? dirname(__DIR__, 2));

        $cache = match ($driver) {
            'memory' => CacheManager::memory(),
            'database' => $container->has(PDO::class)
                ? CacheManager::database($container->get(PDO::class))
                : CacheManager::file($basePath . '/storage/cache/data'),
            default => CacheManager::file($basePath . '/storage/cache/data'),
        };

        CacheManager::setDefault($cache);

        $container->set(CacheInterface::class, $cache);
        $container->set('cache', $cache);
        $container->set(Psr16CacheInterface::class, CacheManager::psr16($cache));

        $pageCacheEnabled = (bool)($cacheConfig['page_cache'] ?? false);
        $pageCacheTtl = (int)($cacheConfig['page_ttl'] ?? 300);

        $pageCache = new PageCacheMiddleware(
            cache: $cache,
            enabled: $pageCacheEnabled,
            ttlSeconds: $pageCacheTtl
        );
        $container->set(PageCacheMiddleware::class, $pageCache);
        $container->set('page_cache', $pageCache);
    }

    public function boot(App $app, Container $container, array $config): void
    {
        if (!$container->has(PageCacheMiddleware::class)) {
            return;
        }

        $pageCacheEnabled = (bool)($config['cache']['page_cache'] ?? false);
        if ($pageCacheEnabled) {
            $app->add($container->get(PageCacheMiddleware::class));
        }
    }
}
