<?php

declare(strict_types=1);

namespace Tests;

use Lite\Framework\Media\ImageOptimizer;
use Lite\Framework\Middleware\PageCacheMiddleware;
use Lite\Framework\Middleware\RedirectMiddleware;
use Lite\Framework\Seo\SchemaBuilder;
use Lite\Framework\Seo\SeoMeta;
use Lite\Framework\Seo\SitemapGenerator;
use Lite\Framework\Testing\TestCase;
use LiteCache\CacheManager;
use Nyholm\Psr7\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Verifies SEO toolkit (SeoMeta, SchemaBuilder, Sitemap, Robots, 301 Redirect),
 * LiteCache PageCacheMiddleware, and ImageOptimizer utilities.
 */
class SeoAndCacheTest extends TestCase
{
    public function testHomePageRendersEscapedSeoMetaAndJsonLd(): void
    {
        $res = $this->get('/');
        $this->assertStatus($res, 200);

        $html = (string)$res->getBody();
        $this->assertTrue(str_contains($html, '<meta name="description"'), 'Home page must render meta description');
        $this->assertTrue(str_contains($html, '<link rel="canonical"'), 'Home page must render canonical link');
        $this->assertTrue(str_contains($html, 'property="og:title"'), 'Home page must render OpenGraph tags');
        $this->assertTrue(str_contains($html, 'application/ld+json'), 'Home page must render JSON-LD schema');
    }

    public function testSeoMetaEscapesXssAndTruncatesDescription(): void
    {
        $meta = (new SeoMeta(siteName: 'ShopVN'))
            ->withTitle('Áo thun <script>alert(1)</script>')
            ->withDescription(str_repeat('Sản phẩm chất lượng cao ', 15))
            ->withCanonical('https://shop.vn/ao-thun');

        $tags = $meta->renderHeadTags();

        $this->assertFalse(str_contains($tags, '<script>alert(1)</script>'), 'SEO tags must HTML-escape title');
        $this->assertTrue(str_contains($tags, '&lt;script&gt;'), 'Escaped title entities expected');
        $this->assertTrue(mb_strlen($meta->getDescription()) <= 160, 'Meta description must be capped at 160 chars');
        $this->assertTrue(str_contains($tags, '<link rel="canonical" href="https://shop.vn/ao-thun">'));
    }

    public function testSchemaBuilderOutputsValidProductAndBreadcrumbJsonLd(): void
    {
        $product = SchemaBuilder::product(
            name: 'Bàn phím cơ',
            url: 'https://shop.vn/ban-phim-co',
            price: 1250000,
            currency: 'vnd',
            inStock: true,
            sku: 'KB-001'
        );

        $this->assertEquals('Product', $product['@type']);
        $this->assertEquals('VND', $product['offers']['priceCurrency']);
        $this->assertEquals('https://schema.org/InStock', $product['offers']['availability']);

        $crumbs = SchemaBuilder::breadcrumb([
            ['name' => 'Trang chủ', 'url' => 'https://shop.vn/'],
            ['name' => 'Phụ kiện', 'url' => 'https://shop.vn/phu-kien'],
        ]);
        $this->assertEquals('BreadcrumbList', $crumbs['@type']);
        $this->assertEquals(2, count($crumbs['itemListElement']));
        $this->assertEquals(2, $crumbs['itemListElement'][1]['position']);
    }

    public function testRobotsTxtBlocksIndexingOutsideProduction(): void
    {
        $res = $this->get('/robots.txt');
        $this->assertStatus($res, 200);
        $this->assertTrue(str_contains($res->getHeaderLine('Content-Type'), 'text/plain'));
        $body = (string)$res->getBody();
        $this->assertTrue(str_contains($body, 'Disallow: /'), 'Non-production robots.txt must disallow crawlers');
    }

    public function testSitemapXmlReturnsValidXml(): void
    {
        $res = $this->get('/sitemap.xml');
        $this->assertStatus($res, 200);
        $this->assertTrue(str_contains($res->getHeaderLine('Content-Type'), 'application/xml'));

        $xml = (string)$res->getBody();
        $this->assertTrue(str_contains($xml, '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'));
        $this->assertTrue(str_contains($xml, '<loc>'));

        // Test dynamic provider & deduplication
        $cache = CacheManager::memory();
        $gen = (new SitemapGenerator('https://example.com', $cache))
            ->addUrl('/')
            ->addProvider(fn(): array => [
                ['loc' => '/san-pham/ban-phim', 'lastmod' => '2026-11-20', 'priority' => '0.9'],
                ['loc' => '/', 'priority' => '1.0'], // duplicate must be deduplicated
            ]);

        $built = $gen->renderXml();
        $this->assertTrue(str_contains($built, '<loc>https://example.com/san-pham/ban-phim</loc>'));
        $this->assertEquals(1, substr_count($built, '<loc>https://example.com/</loc>'));
    }

    public function testRedirectMiddlewareReturns301ForLegacySlug(): void
    {
        $mw = new RedirectMiddleware([
            '/old-product' => '/san-pham/new-product',
            '/promo'       => ['to' => '/khuyen-mai', 'status' => 302],
        ]);

        $fallback = new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return new Response(200);
            }
        };

        $res301 = $mw->process($this->factory->createServerRequest('GET', '/old-product'), $fallback);
        $this->assertStatus($res301, 301);
        $this->assertEquals('/san-pham/new-product', $res301->getHeaderLine('Location'));

        $res302 = $mw->process($this->factory->createServerRequest('GET', '/promo'), $fallback);
        $this->assertStatus($res302, 302);
        $this->assertEquals('/khuyen-mai', $res302->getHeaderLine('Location'));

        $pass = $mw->process($this->factory->createServerRequest('GET', '/other'), $fallback);
        $this->assertStatus($pass, 200);
    }

    public function testPageCacheMiddlewareCachesGuestHtmlAndBypassesAuthenticatedUsers(): void
    {
        $cache = CacheManager::memory();
        $mw = new PageCacheMiddleware($cache, enabled: true, ttlSeconds: 60);

        $hits = 0;
        $handler = new class ($hits) implements RequestHandlerInterface {
            public function __construct(private int &$hits)
            {
            }

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                $this->hits++;
                $res = new Response(200);
                $res->getBody()->write("<html><body>Render #{$this->hits}</body></html>");
                return $res->withHeader('Content-Type', 'text/html; charset=utf-8');
            }
        };

        // 1st guest GET -> MISS
        $req = $this->factory->createServerRequest('GET', 'https://example.com/bai-viet');
        $first = $mw->process($req, $handler);
        $this->assertEquals('MISS', $first->getHeaderLine('X-Page-Cache'));
        $this->assertEquals(1, $hits);

        // 2nd guest GET -> HIT (handler not re-executed)
        $second = $mw->process($req, $handler);
        $this->assertEquals('HIT', $second->getHeaderLine('X-Page-Cache'));
        $this->assertEquals(1, $hits);
        $this->assertTrue(str_contains((string)$second->getBody(), 'Render #1'));

        // Authenticated admin cookie -> bypasses cache
        $authReq = $req->withCookieParams(['lite_admin_auth' => 'signed-token']);
        $authRes = $mw->process($authReq, $handler);
        $this->assertEquals('', $authRes->getHeaderLine('X-Page-Cache'));
        $this->assertEquals(2, $hits);

        // Invalidate all page cache -> next guest GET is MISS again
        $mw->invalidateAll();
        $afterPurge = $mw->process($req, $handler);
        $this->assertEquals('MISS', $afterPurge->getHeaderLine('X-Page-Cache'));
        $this->assertEquals(3, $hits);
    }

    public function testImageOptimizerRendersSeoImgTagAndHandlesFallback(): void
    {
        $tag = ImageOptimizer::renderImgTag('/uploads/hero.webp', 'Ảnh "chính"', 800, 450, lazy: true, class: 'rounded');
        $this->assertTrue(str_contains($tag, 'width="800" height="450"'));
        $this->assertTrue(str_contains($tag, 'loading="lazy"'));
        $this->assertTrue(str_contains($tag, 'alt="Ảnh &quot;chính&quot;"'));

        $tmpDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'lite_img_test_' . bin2hex(random_bytes(4));
        mkdir($tmpDir, 0777, true);
        $src = $tmpDir . DIRECTORY_SEPARATOR . 'in.png';
        $pngBytes = base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg=='
        );
        file_put_contents($src, $pngBytes);

        try {
            $optimizer = new ImageOptimizer();
            $out = $optimizer->optimize($src, $tmpDir . DIRECTORY_SEPARATOR . 'out', maxWidth: 800);
            $this->assertEquals(1, $out['width']);
            $this->assertEquals(1, $out['height']);
            $this->assertTrue(is_file($out['path']));
            @unlink($out['path']);
        } finally {
            @unlink($src);
            @rmdir($tmpDir);
        }
    }
}
