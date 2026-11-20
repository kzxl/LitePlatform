<?php

declare(strict_types=1);

namespace App\Providers;

use App\Core\ServiceProviderInterface;
use App\Middleware\RedirectMiddleware;
use App\Seo\SchemaBuilder;
use App\Seo\SeoMeta;
use App\Seo\SitemapGenerator;
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
        $redirects = (array)($seoCfg['redirects'] ?? []);
        if ($redirects !== []) {
            $app->add(new RedirectMiddleware($redirects));
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
