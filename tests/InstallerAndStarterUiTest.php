<?php

declare(strict_types=1);

namespace Tests;

use Lite\Framework\Install\InstallerService;
use Lite\Framework\Middleware\InstallRedirectMiddleware;
use Lite\Framework\Testing\TestCase;
use Nyholm\Psr7\Response;
use PDO;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Verifies WordPress-style 60-Second Web Setup Wizard (/install)
 * and the Clean Enterprise Starter Website UI.
 */
class InstallerAndStarterUiTest extends TestCase
{
    public function testInstallPageRendersPreflightAndCsrfToken(): void
    {
        $resVi = $this->get('/install?lang=vi');
        $this->assertStatus($resVi, 200);
        $htmlVi = (string)$resVi->getBody();
        $this->assertStringContainsString('Cài đặt nhanh trong 60 giây', $htmlVi);
        $this->assertStringContainsString('name="_csrf"', $htmlVi);
        $this->assertStringContainsString('PHP &gt;= 8.2', $htmlVi);

        $resEn = $this->get('/install?lang=en');
        $this->assertStatus($resEn, 200);
        $this->assertStringContainsString('60-Second Web Setup', (string)$resEn->getBody());
    }

    public function testInstallRedirectMiddlewareRedirectsFreshHostToInstall(): void
    {
        $tmpDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'lite_fresh_host_' . bin2hex(random_bytes(4));
        mkdir($tmpDir . '/storage', 0777, true);

        $okHandler = new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return new Response(200);
            }
        };

        try {
            $mw = new InstallRedirectMiddleware($tmpDir);

            // Fresh deployment (no .env, no installed.lock): '/' redirects to '/install'
            $res = $mw->process($this->factory->createServerRequest('GET', '/'), $okHandler);
            $this->assertStatus($res, 302);
            $this->assertEquals('/install', $res->getHeaderLine('Location'));

            // '/install' itself passes through
            $installRes = $mw->process($this->factory->createServerRequest('GET', '/install'), $okHandler);
            $this->assertStatus($installRes, 200);

            // Once installed.lock exists, '/' passes through normally
            file_put_contents($tmpDir . '/storage/installed.lock', '{}');
            $liveRes = $mw->process($this->factory->createServerRequest('GET', '/'), $okHandler);
            $this->assertStatus($liveRes, 200);
        } finally {
            @unlink($tmpDir . '/storage/installed.lock');
            @rmdir($tmpDir . '/storage');
            @rmdir($tmpDir);
        }
    }

    public function testInstallerServiceRunsFullSetupPreventsEnvInjectionAndLocks(): void
    {
        $tmpDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'lite_install_e2e_' . bin2hex(random_bytes(4));
        mkdir($tmpDir . '/storage', 0777, true);
        mkdir($tmpDir . '/database/migrations', 0777, true);

        // Copy real project migrations into isolated temp sandbox
        foreach (glob(dirname(__DIR__) . '/database/migrations/*.php') ?: [] as $mig) {
            copy($mig, $tmpDir . '/database/migrations/' . basename($mig));
        }

        try {
            // Verify Env Injection sanitization
            $safeEnv = InstallerService::formatEnvValue("My Shop\nAPP_DEBUG=true");
            $this->assertStringNotContainsString("\n", $safeEnv);
            $this->assertStringNotContainsString("\r", $safeEnv);

            $service = new InstallerService();

            // Short password must fail validation
            $bad = $service->install($tmpDir, [
                'db_driver'      => 'sqlite',
                'db_database'    => 'storage/test_install.sqlite',
                'site_name'      => 'Demo Shop',
                'site_url'       => 'https://demoshop.vn',
                'locale'         => 'vi',
                'admin_name'     => 'Founder',
                'admin_email'    => 'founder@demoshop.vn',
                'admin_password' => 'short',
            ]);
            $this->assertFalse($bad['ok']);

            // Full valid installation
            $ok = $service->install($tmpDir, [
                'db_driver'      => 'sqlite',
                'db_database'    => 'storage/test_install.sqlite',
                'site_name'      => "Demo Shop\nAPP_DEBUG=true",
                'site_url'       => 'https://demoshop.vn',
                'site_tagline'   => 'Cửa hàng uy tín',
                'hotline'        => '0909 888 777',
                'locale'         => 'vi',
                'admin_name'     => 'Founder',
                'admin_email'    => 'founder@demoshop.vn',
                'admin_password' => 'StrongPass#2026',
            ]);
            $this->assertTrue($ok['ok'], 'Install failed: ' . implode('; ', $ok['errors']));

            // Verify .env written safely with production hardening & 48-char random secrets
            $envText = (string)file_get_contents($tmpDir . '/.env');
            $this->assertStringContainsString('APP_ENV=production', $envText);
            $this->assertStringContainsString('APP_DEBUG=false', $envText);
            $this->assertStringContainsString('PAGE_CACHE_ENABLED=true', $envText);
            $this->assertMatchesRegularExpression('/^APP_KEY=[0-9a-f]{48}$/m', $envText);
            $this->assertMatchesRegularExpression('/^CSRF_SECRET=[0-9a-f]{48}$/m', $envText);
            $this->assertMatchesRegularExpression('/^JWT_SECRET=[0-9a-f]{48}$/m', $envText);
            $this->assertDoesNotMatchRegularExpression('/^APP_DEBUG=true$/m', $envText, 'Env injection must be blocked');

            // Verify lockfile exists and re-install is permanently rejected
            $this->assertTrue(InstallerService::isInstalled($tmpDir));
            $secondAttempt = $service->install($tmpDir, [
                'db_driver'      => 'sqlite',
                'site_name'      => 'Attacker',
                'site_url'       => 'https://evil.test',
                'admin_email'    => 'evil@evil.test',
                'admin_password' => 'Attacker1234',
            ]);
            $this->assertFalse($secondAttempt['ok']);

            // Verify DB records: hashed admin user, site_settings, and welcome post
            $pdo = new PDO('sqlite:' . $tmpDir . '/storage/test_install.sqlite');
            $user = $pdo->query("SELECT email, role, password_hash FROM users WHERE email = 'founder@demoshop.vn'")->fetch(PDO::FETCH_ASSOC);
            $this->assertIsArray($user);
            $this->assertEquals('admin', $user['role']);
            $this->assertTrue(password_verify('StrongPass#2026', (string)$user['password_hash']));

            $postCount = (int)$pdo->query("SELECT COUNT(*) FROM posts WHERE status = 'published'")->fetchColumn();
            $this->assertEquals(1, $postCount);
        } finally {
            $pdo = null;
            $it = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($tmpDir, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST
            );
            foreach ($it as $item) {
                $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
            }
            @rmdir($tmpDir);
        }
    }

    public function testStarterHomepageRendersEnterpriseWebsiteSections(): void
    {
        $res = $this->get('/?lang=vi');
        $this->assertStatus($res, 200);
        $html = (string)$res->getBody();

        $this->assertStringContainsString('Bài viết mới nhất', $html);
        $this->assertStringContainsString('Sản phẩm &amp; Dịch vụ nổi bật', $html);
        $this->assertStringContainsString('/blog', $html);
        $this->assertStringContainsString('/contact', $html);
        $this->assertStringContainsString('/sitemap.xml', $html);
    }
}
