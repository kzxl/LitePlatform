<?php

declare(strict_types=1);

namespace Tests;

use App\Entities\Product;
use InvalidArgumentException;
use Lite\Framework\Console\ReleaseBuilder;
use Lite\Framework\Security\AdminToken;
use Lite\Framework\Security\CsrfGuard;
use Lite\Framework\Security\LoginThrottle;
use Lite\Framework\Security\UploadGuard;
use Lite\Framework\Testing\TestCase;
use LiteCache\CacheManager;
use LiteCache\Drivers\DatabaseDriver;
use LiteORM\EntityManager;
use PDO;
use RuntimeException;

/**
 * Comprehensive security audit test suite covering:
 * - APP_DEBUG boolean parsing & production fail-closed secrets
 * - Database password_verify() auth + LoginThrottle brute-force defense (429)
 * - Stateless CSRF verification, TTL-bound AdminToken, security headers
 * - UploadGuard MIME/double-extension defense
 * - LiteORM QueryBuilder SQL identifier/operator injection defense
 * - LiteCache safe unserialize (allowed_classes => false) & table name validation
 * - ProductController validation & mass-assignment defense
 * - ReleaseBuilder exclusion of framework/Dev & inactive packages
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

        $token = AdminToken::issue('admin@liteplatform.io', $secret, ttlSeconds: 300, now: $now);
        $this->assertEquals('admin@liteplatform.io', AdminToken::verify($token, $secret, now: $now + 100));
        $this->assertNull(AdminToken::verify($token, $secret, now: $now + 301), 'Expired token must fail verification');
        $this->assertNull(AdminToken::verify($token . 'x', $secret, now: $now + 10), 'Tampered token must fail');
        $this->assertNull(AdminToken::verify($token, 'wrong-secret-key-1234567890123456', now: $now + 10));
    }

    public function testAdminTokenRejectsInsecureDefaultSecretInProduction(): void
    {
        $this->expectException(RuntimeException::class);
        AdminToken::assertSecretSafe(AdminToken::DEFAULT_INSECURE_SECRET, 'production');
    }

    public function testCsrfBlocksWebPostAndDatabaseAuthVerifiesHashedPassword(): void
    {
        // 1. POST /admin/login without CSRF token -> 403 Forbidden
        $blocked = $this->request('POST', '/admin/login', [
            'username' => 'admin@liteplatform.io',
            'password' => 'admin123',
        ]);
        $this->assertStatus($blocked, 403);

        // 2. GET /admin/login issues CSRF cookie + hidden _csrf input (and never prefills password)
        $loginPage = $this->get('/admin/login');
        $this->assertStatus($loginPage, 200);
        $html = (string)$loginPage->getBody();
        $this->assertStringContainsString('name="_csrf"', $html);
        $this->assertStringNotContainsString('value="admin123"', $html);

        preg_match('/name="_csrf"\s+value="([^"]+)"/', $html, $matches);
        $token = $matches[1] ?? '';
        $this->assertNotEmpty($token);

        // 3. Wrong password against DB hash -> 401
        $wrongReq = $this->factory->createServerRequest('POST', '/admin/login')
            ->withCookieParams([CsrfGuard::COOKIE_NAME => $token])
            ->withParsedBody([
                '_csrf'    => $token,
                'username' => 'admin@liteplatform.io',
                'password' => 'wrong-password',
            ]);
        $wrongRes = $this->app->handle($wrongReq);
        $this->assertStatus($wrongRes, 401);

        // 4. Valid DB credentials + CSRF token -> 302 redirect to /admin with TTL cookie
        $okReq = $this->factory->createServerRequest('POST', '/admin/login')
            ->withCookieParams([CsrfGuard::COOKIE_NAME => $token])
            ->withParsedBody([
                '_csrf'    => $token,
                'username' => 'admin@liteplatform.io',
                'password' => 'admin123',
            ]);
        $ok = $this->app->handle($okReq);
        $this->assertStatus($ok, 302);
        $this->assertEquals('/admin', $ok->getHeaderLine('Location'));
        $this->assertStringContainsString('lite_admin_auth=', $ok->getHeaderLine('Set-Cookie'));
    }

    public function testLoginThrottleLocksOutAfterMaxFailedAttempts(): void
    {
        $throttle = new LoginThrottle(CacheManager::memory(), maxAttempts: 3, decaySeconds: 60);
        $key = '10.0.0.1|admin@liteplatform.io';

        $this->assertFalse($throttle->tooManyAttempts($key));
        $throttle->hit($key);
        $throttle->hit($key);
        $this->assertFalse($throttle->tooManyAttempts($key));
        $throttle->hit($key);
        $this->assertTrue($throttle->tooManyAttempts($key));
        $this->assertGreaterThan(0, $throttle->availableIn($key));

        $throttle->clear($key);
        $this->assertFalse($throttle->tooManyAttempts($key));
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

        $pngBytes = base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg=='
        );
        $validPng = $tmpDir . DIRECTORY_SEPARATOR . 'sample.bin';
        file_put_contents($validPng, $pngBytes);

        $phpShell = $tmpDir . DIRECTORY_SEPARATOR . 'shell.bin';
        file_put_contents($phpShell, '<?php echo "pwn"; ?>');

        try {
            $meta = $guard->validate($validPng, 'hero-banner.png');
            $this->assertEquals('png', $meta['extension']);
            $this->assertEquals('image/png', $meta['mime']);

            $doubleExtRejected = false;
            try {
                $guard->validate($validPng, 'avatar.php.png');
            } catch (InvalidArgumentException) {
                $doubleExtRejected = true;
            }
            $this->assertTrue($doubleExtRejected);

            $spoofRejected = false;
            try {
                $guard->validate($phpShell, 'photo.jpg');
            } catch (InvalidArgumentException) {
                $spoofRejected = true;
            }
            $this->assertTrue($spoofRejected);
        } finally {
            @unlink($validPng);
            @unlink($phpShell);
            @rmdir($tmpDir);
        }
    }

    public function testOrmQueryBuilderRejectsSqlIdentifierAndOperatorInjection(): void
    {
        /** @var EntityManager $em */
        $em = $this->app->getContainer()->get(EntityManager::class);

        $orderByBlocked = false;
        try {
            $em->query(Product::class)->orderBy('id; DROP TABLE users;--', 'DESC');
        } catch (InvalidArgumentException) {
            $orderByBlocked = true;
        }
        $this->assertTrue($orderByBlocked, 'orderBy must reject injected SQL identifiers');

        $operatorBlocked = false;
        try {
            $em->query(Product::class)->where('id', '= 1 OR 1=1 --', 1);
        } catch (InvalidArgumentException) {
            $operatorBlocked = true;
        }
        $this->assertTrue($operatorBlocked, 'where must reject injected SQL operators');
    }

    public function testLiteCacheBlocksUnsafeObjectDeserializationAndBadTableName(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $badTableRejected = false;
        try {
            new DatabaseDriver($pdo, 'lite_cache; DROP TABLE users;--');
        } catch (InvalidArgumentException) {
            $badTableRejected = true;
        }
        $this->assertTrue($badTableRejected);

        // Verify unserialize uses allowed_classes => false (prevents POP gadget instantiation)
        $driver = new DatabaseDriver($pdo);
        $obj = new \stdClass();
        $obj->secret = 'probe';
        $driver->set('obj_key', $obj, 60);
        $restored = $driver->get('obj_key');
        $this->assertInstanceOf(\__PHP_Incomplete_Class::class, $restored);
    }

    public function testProductControllerValidatesInput(): void
    {
        $badName = $this->postJson('/api/products', ['name' => '   ', 'price' => 10, 'stock' => 1]);
        $this->assertStatus($badName, 422);

        $badPrice = $this->postJson('/api/products', ['name' => 'Keyboard', 'price' => -50, 'stock' => 1]);
        $this->assertStatus($badPrice, 422);
    }

    public function testReleaseBuilderExcludesDevToolsAndTests(): void
    {
        $builder = new ReleaseBuilder();
        $result = $builder->build(dirname(__DIR__));

        $stage = $result['stage_dir'];
        $this->assertFalse(is_dir($stage . '/framework/Dev'), 'framework/Dev must never ship in production release');
        $this->assertFalse(is_dir($stage . '/framework/Testing'), 'framework/Testing must not ship');
        $this->assertFalse(is_dir($stage . '/tests'), 'tests/ must not ship');
        $this->assertTrue(is_file($stage . '/public/.htaccess'), 'public/.htaccess must ship');
        $this->assertFalse(in_array('LiteSocket', $result['packages'], true), 'Inactive LiteSocket must not be bundled');
    }
}
