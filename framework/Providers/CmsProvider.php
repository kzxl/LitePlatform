<?php

declare(strict_types=1);

namespace Lite\Framework\Providers;

use DI\Container;
use Lite\Framework\Cms\SiteSettings;
use Lite\Framework\Core\ServiceProviderInterface;
use Lite\Framework\Media\MediaManager;
use Lite\Framework\Seo\SitemapGenerator;
use LiteCache\CacheInterface;
use LiteView\ViewEngine;
use PDO;
use Slim\App;

/**
 * Service provider for CMS Micro-Kit:
 * - SiteSettings (0-query OpCache-backed site configuration & tracking tags)
 * - MediaManager (UploadGuard + ImageOptimizer WebP + DB ledger)
 * - Dynamic Sitemap registration for published blog posts
 */
final class CmsProvider implements ServiceProviderInterface
{
    public function register(Container $container, array $config): void
    {
        if (!$container->has(PDO::class)) {
            return;
        }

        /** @var PDO $pdo */
        $pdo = $container->get(PDO::class);
        $cache = $container->has(CacheInterface::class) ? $container->get(CacheInterface::class) : null;
        $basePath = (string)($config['base_path'] ?? dirname(__DIR__, 2));

        $settings = new SiteSettings($pdo, $cache);
        $container->set(SiteSettings::class, $settings);
        $container->set('settings', $settings);

        $media = new MediaManager(
            pdo: $pdo,
            publicUploadsDir: $basePath . '/public/uploads',
            publicUrlPrefix: '/uploads'
        );
        $container->set(MediaManager::class, $media);
        $container->set('media', $media);

        if ($container->has(ViewEngine::class)) {
            /** @var ViewEngine $view */
            $view = $container->get(ViewEngine::class);
            $view->share('settings', $settings);
        }

        // Auto-register published posts into /sitemap.xml
        if ($container->has(SitemapGenerator::class)) {
            /** @var SitemapGenerator $sitemap */
            $sitemap = $container->get(SitemapGenerator::class);
            $sitemap->addUrl('/blog', date('Y-m-d'), 'daily', '0.8');
            $sitemap->addProvider(static function () use ($pdo): array {
                try {
                    $stmt = $pdo->query(
                        "SELECT slug, updated_at, published_at FROM posts WHERE status = 'published' ORDER BY published_at DESC LIMIT 5000"
                    );
                    $rows = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
                    $urls = [];
                    foreach ($rows as $row) {
                        $lastmod = (string)($row['updated_at'] ?? $row['published_at'] ?? '');
                        $urls[] = [
                            'loc'        => '/blog/' . $row['slug'],
                            'lastmod'    => $lastmod !== '' ? substr($lastmod, 0, 10) : date('Y-m-d'),
                            'changefreq' => 'weekly',
                            'priority'   => '0.7',
                        ];
                    }
                    return $urls;
                } catch (\Throwable) {
                    return [];
                }
            });
        }
    }

    public function boot(App $app, Container $container, array $config): void
    {
        // CMS services active
    }
}
