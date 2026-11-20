<?php

declare(strict_types=1);

namespace Lite\Framework\Providers;

use Lite\Framework\Core\ServiceProviderInterface;
use Lite\Framework\Middleware\RedirectMiddleware;
use Lite\Framework\Seo\SchemaBuilder;
use Lite\Framework\Seo\SeoMeta;
use Lite\Framework\Seo\SitemapGenerator;
use DI\Container;
use LiteCache\CacheInterface;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\App;

/**
 * Service provider for built-in SEO toolkit:
 * - Default SeoMeta + Organization/WebSite JSON-LD shared with LiteView
 * - Dynamic /sitemap.xml (LiteCache-backed) and environment-aware /robots.txt
 * - 301/302 RedirectMiddleware for slug preservation
 */
final class SeoProvider implements ServiceProviderInterface
{
    public function register(Container $container, array $config): void
    {
        $seoCfg = $config['seo'] ?? [];
        $appName = (string)($seoCfg['site_name'] ?? $config['name'] ?? 'LitePlatform');
        $appUrl = rtrim((string)($config['url'] ?? 'http://localhost:8000'), '/');
        $isProduction = (($config['env'] ?? 'development') === 'production');

        $meta = new SeoMeta(
            title: $appName,
            description: (string)($seoCfg['default_description'] ?? ''),
            siteName: $appName,
            canonical: $appUrl . '/',
            robots: $isProduction ? 'index, follow' : 'noindex, nofollow',
            ogType: 'website',
            ogImage: $seoCfg['default_og_image'] ?? null,
            twitterCard: 'summary_large_image',
            twitterHandle: $seoCfg['twitter_handle'] ?? null,
            locale: (string)($seoCfg['locale'] ?? 'vi_VN')
        );

        $meta = $meta
            ->addSchema(SchemaBuilder::organization(
                name: $appName,
                url: $appUrl,
                logo: $seoCfg['logo'] ?? null,
                sameAs: (array)($seoCfg['same_as'] ?? [])
            ))
            ->addSchema(SchemaBuilder::website($appName, $appUrl));

        $container->set(SeoMeta::class, $meta);
        $container->set('seo', $meta);

        $cache = $container->has(CacheInterface::class) ? $container->get(CacheInterface::class) : null;
        $sitemap = new SitemapGenerator(
            baseUrl: $appUrl,
            cache: $cache,
            cacheTtl: (int)($seoCfg['sitemap_ttl'] ?? 3600)
        );
        $sitemap->addUrl('/', date('Y-m-d'), 'daily', '1.0');

        $container->set(SitemapGenerator::class, $sitemap);
        $container->set('sitemap', $sitemap);

        if ($container->has(\LiteView\ViewEngine::class)) {
            /** @var \LiteView\ViewEngine $view */
            $view = $container->get(\LiteView\ViewEngine::class);
            $view->share('seo', $meta);
        }
    }

    public function boot(App $app, Container $container, array $config): void
    {
        $seoCfg = $config['seo'] ?? [];
        $staticRedirects = (array)($seoCfg['redirects'] ?? []);

        // Lazy LiteCache-backed DB redirect loader (0 query/request after warmup)
        $dbResolver = null;
        if ($container->has(\PDO::class)) {
            $pdo = $container->get(\PDO::class);
            $cache = $container->has(CacheInterface::class) ? $container->get(CacheInterface::class) : null;
            $dbResolver = static function () use ($pdo, $cache): array {
                $loader = static function () use ($pdo): array {
                    try {
                        $stmt = $pdo->query('SELECT source_path, target_url, status_code FROM redirects WHERE is_active = 1');
                        $rows = $stmt ? $stmt->fetchAll(\PDO::FETCH_ASSOC) : [];
                        $map = [];
                        foreach ($rows as $row) {
                            $src = '/' . ltrim((string)$row['source_path'], '/');
                            $map[$src] = [
                                'to'     => (string)$row['target_url'],
                                'status' => (int)($row['status_code'] ?? 301),
                            ];
                        }
                        return $map;
                    } catch (\Throwable) {
                        return [];
                    }
                };

                if ($cache !== null) {
                    $cached = $cache->tags(['seo', 'seo_redirects'])->remember('seo_db_redirects', 1800, $loader);
                    return is_array($cached) ? $cached : [];
                }

                return $loader();
            };
        }

        if ($staticRedirects !== [] || $dbResolver !== null) {
            $app->add(new RedirectMiddleware($staticRedirects, $dbResolver));
        }

        // Dynamic /sitemap.xml
        $app->get('/sitemap.xml', function (Request $request, Response $response) use ($container): Response {
            /** @var SitemapGenerator $sitemap */
            $sitemap = $container->get(SitemapGenerator::class);
            $xml = $sitemap->renderXml();
            $response->getBody()->write($xml);

            return $response
                ->withHeader('Content-Type', 'application/xml; charset=utf-8')
                ->withHeader('Cache-Control', 'public, max-age=3600');
        });

        // Environment-aware /robots.txt (blocks crawlers outside production)
        $app->get('/robots.txt', function (Request $request, Response $response) use ($config): Response {
            $env = (string)($config['env'] ?? 'development');
            $appUrl = rtrim((string)($config['url'] ?? 'http://localhost:8000'), '/');

            if ($env !== 'production') {
                $txt = "User-agent: *\nDisallow: /\n";
            } else {
                $txt = "User-agent: *\n"
                    . "Allow: /\n"
                    . "Disallow: /admin/\n"
                    . "Disallow: /api/\n"
                    . "Disallow: /_studio/\n"
                    . "\nSitemap: {$appUrl}/sitemap.xml\n";
            }

            $response->getBody()->write($txt);

            return $response
                ->withHeader('Content-Type', 'text/plain; charset=utf-8')
                ->withHeader('Cache-Control', 'public, max-age=3600');
        });
    }
}
