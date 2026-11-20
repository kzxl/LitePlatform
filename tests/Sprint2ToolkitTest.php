<?php

declare(strict_types=1);

namespace Tests;

use InvalidArgumentException;
use Lite\Framework\Mail\Mailer;
use Lite\Framework\Mail\TelegramNotifier;
use Lite\Framework\Middleware\RateLimitMiddleware;
use Lite\Framework\Security\HoneypotGuard;
use Lite\Framework\Security\HtmlSanitizer;
use Lite\Framework\Testing\TestCase;
use LiteCache\CacheManager;
use LiteView\ViewEngine;
use Nyholm\Psr7\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Verifies Sprint 2 core web toolkit:
 * - HtmlSanitizer Stored XSS defense for Rich Text content + @sanitize directive
 * - HoneypotGuard zero-CAPTCHA bot defense + @honeypot directive
 * - RateLimitMiddleware per-IP throttling (429 + Retry-After)
 * - Zero-dependency Mailer (array/log/Resend/Brevo HTTP API + CRLF header injection defense)
 * - TelegramNotifier lead/alert webhook
 */
class Sprint2ToolkitTest extends TestCase
{
    public function testHtmlSanitizerStripsXssAndPreservesSafeVietnameseRichText(): void
    {
        $sanitizer = new HtmlSanitizer();

        $dirty = '<h2>Giới thiệu sản phẩm</h2>'
            . '<p onclick="alert(1)">Mô tả <strong>nổi bật</strong> với <a href="https://shop.vn" target="_blank">liên kết</a> '
            . 'và <a href="javascript:alert(2)">link độc</a>.</p>'
            . '<img src="https://shop.vn/a.webp" alt="Ảnh" width="800" height="450" loading="lazy" onerror="steal()">'
            . '<script>document.cookie="pwn"</script>'
            . '<iframe src="https://evil.test"></iframe>'
            . '<svg onload="alert(3)"></svg>';

        $clean = $sanitizer->sanitize($dirty);

        $this->assertStringContainsString('<h2>Giới thiệu sản phẩm</h2>', $clean);
        $this->assertStringContainsString('<strong>nổi bật</strong>', $clean);
        $this->assertStringContainsString('rel="noopener noreferrer"', $clean);
        $this->assertStringContainsString('width="800" height="450"', $clean);

        $this->assertStringNotContainsString('<script', $clean);
        $this->assertStringNotContainsString('<iframe', $clean);
        $this->assertStringNotContainsString('<svg', $clean);
        $this->assertStringNotContainsString('onclick=', $clean);
        $this->assertStringNotContainsString('onerror=', $clean);
        $this->assertStringNotContainsString('javascript:', $clean);
        $this->assertStringNotContainsString('document.cookie', $clean);
    }

    public function testHoneypotGuardBlocksBotsAndAcceptsHumans(): void
    {
        $hp = new HoneypotGuard('test-honeypot-secret-key-32-chars!', minSeconds: 2, maxSeconds: 3600);
        $now = 1_700_000_000;

        $fieldsHtml = $hp->renderFields($now);
        $this->assertStringContainsString('name="_hp_website"', $fieldsHtml);
        $this->assertStringContainsString('name="_hp_ts"', $fieldsHtml);

        preg_match('/name="_hp_ts"\s+value="([^"]+)"/', $fieldsHtml, $m);
        $signedTs = $m[1] ?? '';
        $this->assertNotEmpty($signedTs);

        // 1. Bot fills trap field -> rejected
        $this->assertFalse($hp->verify([
            HoneypotGuard::TRAP_FIELD => 'https://spam-bot.biz',
            HoneypotGuard::TIME_FIELD => $signedTs,
        ], $now + 10));

        // 2. Bot submits instantaneously (0 seconds elapsed < 2s) -> rejected
        $this->assertFalse($hp->verify([
            HoneypotGuard::TRAP_FIELD => '',
            HoneypotGuard::TIME_FIELD => $signedTs,
        ], $now + 1));

        // 3. Tampered timestamp signature -> rejected
        $this->assertFalse($hp->verify([
            HoneypotGuard::TRAP_FIELD => '',
            HoneypotGuard::TIME_FIELD => ($now - 100) . '.forgedsig',
        ], $now));

        // 4. Normal human submission after 5 seconds -> accepted
        $this->assertTrue($hp->verify([
            HoneypotGuard::TRAP_FIELD => '',
            HoneypotGuard::TIME_FIELD => $signedTs,
        ], $now + 5));
    }

    public function testRateLimitMiddlewareEnforcesLimitAndIsolatesByClientIp(): void
    {
        $mw = new RateLimitMiddleware(CacheManager::memory(), maxRequests: 2, decaySeconds: 60, bucket: 'contact');
        $okHandler = new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return new Response(200);
            }
        };

        $ipA = $this->factory->createServerRequest('POST', '/contact')->withAttribute('client_ip', '10.0.0.10');
        $ipB = $this->factory->createServerRequest('POST', '/contact')->withAttribute('client_ip', '10.0.0.20');

        $r1 = $mw->process($ipA, $okHandler);
        $this->assertStatus($r1, 200);
        $this->assertEquals('1', $r1->getHeaderLine('X-RateLimit-Remaining'));

        $r2 = $mw->process($ipA, $okHandler);
        $this->assertStatus($r2, 200);
        $this->assertEquals('0', $r2->getHeaderLine('X-RateLimit-Remaining'));

        // 3rd request from IP A -> 429 Too Many Requests with Retry-After
        $r3 = $mw->process($ipA, $okHandler);
        $this->assertStatus($r3, 429);
        $this->assertNotEmpty($r3->getHeaderLine('Retry-After'));

        // IP B is completely unaffected
        $rB = $mw->process($ipB, $okHandler);
        $this->assertStatus($rB, 200);
    }

    public function testMailerArrayDriverHttpApiDriversAndCrlfInjectionDefense(): void
    {
        // 1. Array driver + CRLF header injection stripping
        $arrayMailer = new Mailer([
            'driver'       => 'array',
            'from_address' => 'sales@shop.vn',
            'from_name'    => 'ShopVN',
        ]);
        $this->assertTrue($arrayMailer->send(
            'customer@example.com',
            "Đơn hàng mới\r\nBcc: attacker@evil.com",
            '<p>Cảm ơn bạn đã đặt hàng!</p>'
        ));

        $sent = $arrayMailer->getSentMessages();
        $this->assertCount(1, $sent);
        $this->assertStringNotContainsString("\r", $sent[0]['subject']);
        $this->assertStringNotContainsString("\n", $sent[0]['subject']);

        // Invalid email address rejected
        $invalidRejected = false;
        try {
            $arrayMailer->send("bad@addr.com\r\nBcc: x@y.com", 'Test', '<p>Hi</p>');
        } catch (InvalidArgumentException) {
            $invalidRejected = true;
        }
        $this->assertTrue($invalidRejected);

        // 2. HTTP Resend driver payload verification
        $capturedUrl = null;
        $capturedHeaders = [];
        $capturedPayload = null;

        $httpMailer = (new Mailer([
            'driver'        => 'http',
            'http_provider' => 'resend',
            'api_key'       => 're_test_12345',
            'from_address'  => 'noreply@shop.vn',
            'from_name'     => 'ShopVN',
        ]))->setHttpTransport(function (string $url, array $headers, string $body) use (&$capturedUrl, &$capturedHeaders, &$capturedPayload): array {
            $capturedUrl = $url;
            $capturedHeaders = $headers;
            $capturedPayload = json_decode($body, true);
            return ['status' => 200, 'body' => '{"id":"email_123"}'];
        });

        $this->assertTrue($httpMailer->send('lead@example.com', 'Báo giá mới', '<b>Xin chào</b>', replyTo: 'sale@shop.vn'));
        $this->assertEquals('https://api.resend.com/emails', $capturedUrl);
        $this->assertEquals('Bearer re_test_12345', $capturedHeaders['Authorization']);
        $this->assertEquals(['lead@example.com'], $capturedPayload['to']);
        $this->assertEquals('sale@shop.vn', $capturedPayload['reply_to']);
    }

    public function testTelegramNotifierDispatchesAndNoOpsWhenUnconfigured(): void
    {
        $unconfigured = new TelegramNotifier();
        $this->assertFalse($unconfigured->isConfigured());
        $this->assertFalse($unconfigured->send('Hello'));

        $sentUrl = null;
        $sentPayload = null;
        $tg = (new TelegramNotifier('bot123:secret', '-100999'))
            ->setTransport(function (string $url, array $payload) use (&$sentUrl, &$sentPayload): bool {
                $sentUrl = $url;
                $sentPayload = $payload;
                return true;
            });

        $this->assertTrue($tg->send('<b>Đơn hàng #101</b>: 1.500.000đ'));
        $this->assertStringContainsString('/botbot123:secret/sendMessage', (string)$sentUrl);
        $this->assertEquals('-100999', $sentPayload['chat_id']);
    }

    public function testViewDirectivesCompileSanitizeAndHoneypot(): void
    {
        /** @var ViewEngine $view */
        $view = $this->app->getContainer()->get(ViewEngine::class);
        $compiled = $view->getCompiler()->compile('<form>@honeypot<div>@sanitize($body)</div></form>');

        $this->assertStringContainsString('$honeypot->renderFields()', $compiled);
        $this->assertStringContainsString('$sanitizer->sanitize', $compiled);
    }
}
