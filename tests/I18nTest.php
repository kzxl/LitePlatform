<?php

declare(strict_types=1);

namespace Tests;

use Lite\Framework\I18n\Translator;
use Lite\Framework\Middleware\LocaleMiddleware;
use Lite\Framework\Middleware\PageCacheMiddleware;
use Lite\Framework\Testing\TestCase;
use LiteCache\CacheManager;
use Nyholm\Psr7\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Verifies multi-language UI (i18n):
 * - OpCache PHP catalogs, dot-notation lookup, placeholder replacement, fallback, pluralization
 * - Path traversal rejection on untrusted locale strings
 * - Query param ?lang=, lite_locale cookie, Accept-Language header negotiation
 * - /lang/{locale} switcher & open-redirect protection
 * - HTML lang attribute, hreflang SEO alternates, and locale-isolated PageCache
 */
class I18nTest extends TestCase
{
    public function testTranslatorResolvesKeysPlaceholdersFallbackAndBlocksTraversal(): void
    {
        $t = new Translator(dirname(__DIR__) . '/lang', defaultLocale: 'vi', fallbackLocale: 'en', supportedLocales: ['vi', 'en']);

        $this->assertEquals('Tổng quan', $t->get('nav.overview'));
        $this->assertEquals('Overview', $t->get('nav.overview', locale: 'en'));
        $this->assertEquals(
            'Đăng nhập sai quá nhiều lần. Vui lòng thử lại sau 60 giây.',
            $t->get('auth.throttled', ['seconds' => 60])
        );
        $this->assertEquals('missing.key', $t->get('missing.key'));

        // Path traversal must be rejected silently
        $this->assertFalse($t->isSupported('../../etc/passwd'));
        $t->setLocale('../../etc/passwd');
        $this->assertEquals('vi', $t->getLocale());

        // Accept-Language negotiation
        $this->assertEquals('en', $t->negotiateFromHeader('en-US,en;q=0.9,vi;q=0.8'));
        $this->assertEquals('vi', $t->negotiateFromHeader('fr-FR,fr;q=0.9,vi-VN;q=0.7'));

        // Pluralization helper
        $this->assertEquals('1 item', $t->choice('1 item|:count items', 1));
        $this->assertEquals('5 items', $t->choice('1 item|:count items', 5));
    }

    public function testHomePageSwitchesBetweenVietnameseAndEnglishWithHreflang(): void
    {
        // 1. Explicit ?lang=vi renders Vietnamese UI + persists cookie + hreflang alternates
        $viRes = $this->get('/?lang=vi');
        $this->assertStatus($viRes, 200);
        $this->assertEquals('vi', $viRes->getHeaderLine('Content-Language'));
        $this->assertStringContainsString('lite_locale=vi', $viRes->getHeaderLine('Set-Cookie'));

        $viHtml = (string)$viRes->getBody();
        $this->assertStringContainsString('<html lang="vi">', $viHtml);
        $this->assertStringContainsString('Xây dựng ứng dụng Web trong tích tắc.', $viHtml);
        $this->assertStringContainsString('hreflang="vi"', $viHtml);
        $this->assertStringContainsString('hreflang="en"', $viHtml);
        $this->assertStringContainsString('hreflang="x-default"', $viHtml);

        // 2. Explicit ?lang=en renders English UI
        $enRes = $this->get('/?lang=en');
        $this->assertStatus($enRes, 200);
        $this->assertEquals('en', $enRes->getHeaderLine('Content-Language'));
        $enHtml = (string)$enRes->getBody();
        $this->assertStringContainsString('<html lang="en">', $enHtml);
        $this->assertStringContainsString('Build Any Web App in Milliseconds.', $enHtml);
    }

    public function testLangSwitchRoutePersistsCookieAndBlocksOpenRedirect(): void
    {
        // Safe local redirect
        $ok = $this->get('/lang/en?redirect=/');
        $this->assertStatus($ok, 302);
        $this->assertEquals('/', $ok->getHeaderLine('Location'));
        $this->assertStringContainsString('lite_locale=en', $ok->getHeaderLine('Set-Cookie'));

        // External URL redirect attempt must be sanitized back to '/'
        $evil = $this->get('/lang/vi?redirect=https://evil.example/phish');
        $this->assertStatus($evil, 302);
        $this->assertEquals('/', $evil->getHeaderLine('Location'));

        $protoRelative = $this->get('/lang/vi?redirect=//evil.example/phish');
        $this->assertStatus($protoRelative, 302);
        $this->assertEquals('/', $protoRelative->getHeaderLine('Location'));
    }

    public function testPageCacheIsolatesCachedHtmlByLocale(): void
    {
        $cache = CacheManager::memory();
        $mw = new PageCacheMiddleware($cache, enabled: true, ttlSeconds: 60);

        $handler = new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                $loc = (string)$request->getAttribute('locale', 'vi');
                $res = new Response(200);
                $res->getBody()->write("<html lang=\"{$loc}\">Body {$loc}</html>");
                return $res->withHeader('Content-Type', 'text/html; charset=utf-8');
            }
        };

        $viReq = $this->factory->createServerRequest('GET', 'https://example.com/about')
            ->withAttribute('locale', 'vi');
        $enReq = $this->factory->createServerRequest('GET', 'https://example.com/about')
            ->withAttribute('locale', 'en');

        $firstVi = $mw->process($viReq, $handler);
        $this->assertEquals('MISS', $firstVi->getHeaderLine('X-Page-Cache'));
        $this->assertStringContainsString('Body vi', (string)$firstVi->getBody());

        // English visitor on the exact same URL must NOT receive cached Vietnamese HTML
        $firstEn = $mw->process($enReq, $handler);
        $this->assertEquals('MISS', $firstEn->getHeaderLine('X-Page-Cache'));
        $this->assertStringContainsString('Body en', (string)$firstEn->getBody());

        // Subsequent Vietnamese visitor hits Vietnamese cache
        $secondVi = $mw->process($viReq, $handler);
        $this->assertEquals('HIT', $secondVi->getHeaderLine('X-Page-Cache'));
        $this->assertStringContainsString('Body vi', (string)$secondVi->getBody());
    }
}
