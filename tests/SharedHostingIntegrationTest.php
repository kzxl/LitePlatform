<?php

declare(strict_types=1);

namespace Tests;

use Lite\Framework\Console\MigrationSqlExporter;
use Lite\Framework\Logging\FileLogger;
use Lite\Framework\Middleware\PageCacheMiddleware;
use Lite\Framework\Middleware\TrustedProxyMiddleware;
use Lite\Framework\Security\AdminToken;
use Lite\Framework\Security\CsrfGuard;
use Lite\Framework\Testing\TestCase;
use LiteCache\CacheInterface;
use Nyholm\Psr7\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * End-to-end tests for Sprint 1 production & Shared Hosting scenarios:
 * - Unified CsrfGuard + LiteAdmin CRUD flow (no double-CSRF 403)
 * - Automatic PageCache & Sitemap invalidation on Admin mutation
 * - Branded SEO-safe 404 HTML & RFC 7807 API Problem Details
 * - Daily FileLogger with secret redaction
 * - Cloudflare TrustedProxyMiddleware IP/HTTPS resolution & spoof defense
 * - No-SSH MySQL DDL exporter (migrate:sql) for phpMyAdmin
 */
class SharedHostingIntegrationTest extends TestCase
{
    public function testLiteAdminCreateProductWorksThroughCsrfGuardAndInvalidatesCaches(): void
    {
        $container = $this->app->getContainer();
        $config = (array)$container->get('config');
        $secret = (string)($config['admin']['auth_secret'] ?? $config['security']['csrf_secret']);
        $adminCookie = AdminToken::issue('admin@liteplatform.io', $secret, 3600);

        /** @var CacheInterface $cache */
        $cache = $container->get(CacheInterface::class);
        $cache->tags(['page_cache'])->set('page_html_probe', ['body' => 'stale', 'content_type' => 'text/html'], 300);
        $cache->tags(['sitemap'])->set('seo_sitemap_xml', '<urlset>stale</urlset>', 300);

        try {
            $this->assertNotNull($cache->get('page_html_probe'));

            // 1. GET /admin/product/create with valid admin cookie -> receives CsrfGuard token embedded in form
            $getReq = $this->factory->createServerRequest('GET', '/admin/product/create')
                ->withCookieParams(['lite_admin_auth' => $adminCookie]);
            $createPage = $this->app->handle($getReq);
            $this->assertStatus($createPage, 200);

            $html = (string)$createPage->getBody();
            preg_match('/name="_csrf"\s+value="([^"]+)"/', $html, $matches);
            $csrfToken = $matches[1] ?? '';
            $this->assertNotEmpty($csrfToken, 'LiteAdmin create form must embed CsrfGuard token');

            // 2. POST /admin/product/create with matching CsrfGuard cookie + form _csrf -> 302 (NOT 403!)
            $postReq = $this->factory->createServerRequest('POST', '/admin/product/create')
                ->withCookieParams([
                    'lite_admin_auth'      => $adminCookie,
                    CsrfGuard::COOKIE_NAME => $csrfToken,
                ])
                ->withParsedBody([
                    '_csrf'     => $csrfToken,
                    'name'      => 'Shared Hosting Keyboard ' . bin2hex(random_bytes(3)),
                    'price'     => '49.5',
                    'stock'     => '25',
                    'is_active' => '1',
                ]);
            $storeRes = $this->app->handle($postReq);
            $this->assertStatus($storeRes, 302);
            $this->assertEquals('/admin/product', $storeRes->getHeaderLine('Location'));

            // 3. PageCache and Sitemap caches must be automatically invalidated after Admin mutation
            $this->assertNull($cache->get('page_html_probe'), 'PageCache must be invalidated on Admin create');
            $this->assertNull($cache->get('seo_sitemap_xml'), 'Sitemap cache must be invalidated on Admin create');
        } finally {
            $cache->delete('page_html_probe');
            $cache->delete('seo_sitemap_xml');
        }
    }

    public function testCustom404RendersSeoNoIndexHtmlForWebAndProblemJsonForApi(): void
    {
        $web404 = $this->get('/this-route-does-not-exist-xyz');
        $this->assertStatus($web404, 404);
        $this->assertStringContainsString('text/html', $web404->getHeaderLine('Content-Type'));
        $this->assertEquals('noindex, nofollow', $web404->getHeaderLine('X-Robots-Tag'));
        $this->assertStringContainsString('noindex, nofollow', (string)$web404->getBody());
        $this->assertStringContainsString('Back to Home', (string)$web404->getBody());

        $api404 = $this->get('/api/this-endpoint-does-not-exist');
        $this->assertStatus($api404, 404);
        $this->assertStringContainsString('application/problem+json', $api404->getHeaderLine('Content-Type'));
        $json = json_decode((string)$api404->getBody(), true);
        $this->assertEquals(404, $json['status']);
        $this->assertEquals('Page Not Found', $json['title']);
    }

    public function testFileLoggerWritesDailyFileAndRedactsSecrets(): void
    {
        $tmpDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'lite_log_test_' . bin2hex(random_bytes(4));
        $logger = new FileLogger($tmpDir, retentionDays: 7);

        try {
            $logger->error('Payment webhook failed', [
                'email'         => 'client@example.com',
                'password'      => 'SuperSecretPass123!',
                'authorization' => 'Bearer eyJhbGciOiJIUzI1NiJ9',
                'nested'        => ['jwt_secret' => 'top-secret-key'],
            ]);

            $logFile = $tmpDir . DIRECTORY_SEPARATOR . 'app-' . date('Y-m-d') . '.log';
            $this->assertTrue(is_file($logFile));
            $content = (string)file_get_contents($logFile);

            $this->assertStringContainsString('Payment webhook failed', $content);
            $this->assertStringContainsString('client@example.com', $content);
            $this->assertStringContainsString('[REDACTED]', $content);
            $this->assertStringNotContainsString('SuperSecretPass123!', $content);
            $this->assertStringNotContainsString('eyJhbGciOiJIUzI1NiJ9', $content);
            $this->assertStringNotContainsString('top-secret-key', $content);
        } finally {
            foreach (glob($tmpDir . DIRECTORY_SEPARATOR . '*') ?: [] as $f) {
                @unlink($f);
            }
            @rmdir($tmpDir);
        }
    }

    public function testTrustedProxyMiddlewareResolvesCloudflareIpAndBlocksSpoofing(): void
    {
        $mw = new TrustedProxyMiddleware(['173.245.48.0/20', '127.0.0.1']);
        $capturedIp = null;
        $capturedScheme = null;

        $handler = new class ($capturedIp, $capturedScheme) implements RequestHandlerInterface {
            public function __construct(private ?string &$ip, private ?string &$scheme)
            {
            }

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                $this->ip = (string)$request->getAttribute('client_ip');
                $this->scheme = $request->getUri()->getScheme();
                return new Response(200);
            }
        };

        // 1. Trusted Cloudflare CIDR -> resolves CF-Connecting-IP and upgrades scheme to https
        $trustedReq = $this->factory->createServerRequest('GET', 'http://example.com/', ['REMOTE_ADDR' => '173.245.48.10'])
            ->withHeader('CF-Connecting-IP', '14.161.22.33')
            ->withHeader('X-Forwarded-Proto', 'https');
        $mw->process($trustedReq, $handler);
        $this->assertEquals('14.161.22.33', $capturedIp);
        $this->assertEquals('https', $capturedScheme);

        // 2. Untrusted remote address -> ignores spoofed CF-Connecting-IP & X-Forwarded-Proto
        $untrustedReq = $this->factory->createServerRequest('GET', 'http://example.com/', ['REMOTE_ADDR' => '203.0.113.5'])
            ->withHeader('CF-Connecting-IP', '1.2.3.4')
            ->withHeader('X-Forwarded-Proto', 'https');
        $mw->process($untrustedReq, $handler);
        $this->assertEquals('203.0.113.5', $capturedIp);
        $this->assertEquals('http', $capturedScheme);
    }

    public function testMigrationSqlExporterGeneratesValidMysqlDdl(): void
    {
        $exporter = new MigrationSqlExporter();
        $result = $exporter->export(dirname(__DIR__) . '/database/migrations');

        $this->assertGreaterThanOrEqual(3, count($result['migrations']));
        $this->assertStringContainsString('ENGINE=InnoDB DEFAULT CHARSET=utf8mb4', $result['sql']);
        $this->assertStringContainsString('CREATE TABLE IF NOT EXISTS users', $result['sql']);
        $this->assertStringContainsString('password_hash', $result['sql']);
        $this->assertStringContainsString('CREATE TABLE IF NOT EXISTS products', $result['sql']);
        $this->assertStringContainsString('INSERT IGNORE INTO migrations', $result['sql']);
    }
}
