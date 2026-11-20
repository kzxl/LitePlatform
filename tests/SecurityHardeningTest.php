<?php

declare(strict_types=1);

namespace Tests;

use App\Security\AdminToken;
use App\Security\CsrfGuard;
use App\Security\UploadGuard;
use App\Testing\TestCase;
use InvalidArgumentException;
use RuntimeException;

/**
 * Verifies security hardening: APP_DEBUG boolean parsing, TTL-bound AdminToken,
 * stateless CSRF verification, security headers, and UploadGuard MIME defense.
 */
class SecurityHardeningTest extends TestCase
{
    public function testAppDebugStringFalseParsesAsFalse(): void
    {
        $prev = $_ENV['APP_DEBUG'] ?? null;
        $_ENV['APP_DEBUG'] = 'false';

        try {
            $cfg = require dirname(__DIR__) . '/config/app.php';
            $this->assertFalse($cfg['debug'], 'APP_DEBUG="false" must evaluate to boolean false');
        } finally {
            if ($prev === null) {
                unset($_ENV['APP_DEBUG']);
            } else {
                $_ENV['APP_DEBUG'] = $prev;
            }
        }
    }

    public function testAdminTokenIssuesVerifiesAndRejectsExpiredOrTampered(): void
    {
        $secret = 'a-very-strong-production-secret-key-32chars!';
        $now = 1_700_000_000;

        $token = AdminToken::issue('admin', $secret, ttlSeconds: 300, now: $now);
        $this->assertEquals('admin', AdminToken::verify($token, $secret, now: $now + 100));
        $this->assertNull(AdminToken::verify($token, $secret, now: $now + 301), 'Expired token must fail verification');
        $this->assertNull(AdminToken::verify($token . 'x', $secret, now: $now + 10), 'Tampered token must fail');
        $this->assertNull(AdminToken::verify($token, 'wrong-secret-key-1234567890123456', now: $now + 10));
    }

    public function testAdminTokenRejectsInsecureDefaultSecretInProduction(): void
    {
        $threw = false;
        try {
            AdminToken::assertSecretSafe(AdminToken::DEFAULT_INSECURE_SECRET, 'production');
        } catch (RuntimeException) {
            $threw = true;
        }
        $this->assertTrue($threw, 'Production must fail closed when using default insecure secret');
    }

    public function testCsrfBlocksWebPostWithoutTokenAndAcceptsValidToken(): void
    {
        // 1. POST /admin/login without CSRF token -> 403 Forbidden
        $blocked = $this->request('POST', '/admin/login', [
            'username' => 'admin',
            'password' => 'admin123',
        ]);
        $this->assertStatus($blocked, 403);

        // 2. GET /admin/login issues CSRF cookie + hidden _csrf input
        $loginPage = $this->get('/admin/login');
        $this->assertStatus($loginPage, 200);
        $html = (string)$loginPage->getBody();
        $this->assertTrue(str_contains($html, 'name="_csrf"'), 'Login form must embed hidden _csrf field');

        preg_match('/name="_csrf"\s+value="([^"]+)"/', $html, $matches);
        $token = $matches[1] ?? '';
        $this->assertTrue($token !== '', 'Extracted CSRF token must not be empty');

        // 3. POST /admin/login with matching cookie + _csrf field -> 302 redirect to /admin with TTL cookie
        $req = $this->factory->createServerRequest('POST', '/admin/login')
            ->withCookieParams([CsrfGuard::COOKIE_NAME => $token])
            ->withParsedBody([
                '_csrf'    => $token,
                'username' => 'admin',
                'password' => 'admin123',
            ]);
        $ok = $this->app->handle($req);
        $this->assertStatus($ok, 302);
        $this->assertEquals('/admin', $ok->getHeaderLine('Location'));
        $this->assertTrue(
            str_contains($ok->getHeaderLine('Set-Cookie'), 'lite_admin_auth='),
            'Successful login must set signed admin cookie'
        );
    }

    public function testSecurityHeadersArePresentOnResponses(): void
    {
        $res = $this->get('/');
        $this->assertStatus($res, 200);
        $this->assertEquals('nosniff', $res->getHeaderLine('X-Content-Type-Options'));
        $this->assertEquals('SAMEORIGIN', $res->getHeaderLine('X-Frame-Options'));
        $this->assertEquals('strict-origin-when-cross-origin', $res->getHeaderLine('Referrer-Policy'));
    }

    public function testUploadGuardAcceptsValidPngAndBlocksPhpSpoofing(): void
    {
        $guard = new UploadGuard();
        $tmpDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'lite_upload_test_' . bin2hex(random_bytes(4));
        mkdir($tmpDir, 0777, true);

        // Minimal valid 1x1 transparent PNG binary
        $pngBytes = base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg=='
        );
        $validPng = $tmpDir . DIRECTORY_SEPARATOR . 'sample.bin';
        file_put_contents($validPng, $pngBytes);

        $phpShell = $tmpDir . DIRECTORY_SEPARATOR . 'shell.bin';
        file_put_contents($phpShell, '<?php echo "pwn"; ?>');

        try {
            // Valid PNG passes
            $meta = $guard->validate($validPng, 'hero-banner.png');
            $this->assertEquals('png', $meta['extension']);
            $this->assertEquals('image/png', $meta['mime']);

            // Double extension (.php.png) must be rejected even with real PNG bytes
            $doubleExtRejected = false;
            try {
                $guard->validate($validPng, 'avatar.php.png');
            } catch (InvalidArgumentException) {
                $doubleExtRejected = true;
            }
            $this->assertTrue($doubleExtRejected, 'Double extension .php.png must be rejected');

            // PHP script disguised as .jpg must fail MIME inspection
            $spoofRejected = false;
            try {
                $guard->validate($phpShell, 'photo.jpg');
            } catch (InvalidArgumentException) {
                $spoofRejected = true;
            }
            $this->assertTrue($spoofRejected, 'PHP payload disguised as .jpg must fail finfo MIME check');
        } finally {
            @unlink($validPng);
            @unlink($phpShell);
            @rmdir($tmpDir);
        }
    }
}
