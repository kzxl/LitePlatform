<?php

declare(strict_types=1);

namespace Tests;

use App\Modules\CatalogExtension\CatalogExtensionModule;
use App\Modules\CrmExtension\CrmExtensionModule;
use App\Modules\OrderExtension\OrderExtensionModule;
use App\Modules\OrderExtension\Services\AvailabilityService;
use App\Modules\ReportExtension\ReportExtensionModule;
use App\Modules\ReportExtension\Services\RevenueReportService;
use Lite\Framework\Console\MigrationSqlExporter;
use Lite\Framework\Core\Plugin\Capability;
use Lite\Framework\Core\Plugin\HookBus;
use Lite\Framework\Core\Plugin\ModuleManagerInterface;
use Lite\Framework\Core\Plugin\PluginContext;
use Lite\Framework\Core\Plugin\PluginLockManager;
use Lite\Framework\Core\Plugin\PluginManifest;
use Lite\Framework\Core\Plugin\PluginStaticScanner;
use Lite\Framework\Generators\PluginGenerator;
use Lite\Framework\Security\AdminToken;
use Lite\Framework\Security\CsrfGuard;
use Lite\Framework\Security\HoneypotGuard;
use Lite\Framework\Security\RbacManager;
use Lite\Framework\Security\SecretVault;
use Lite\Framework\Security\SecurityAuditLog;
use Lite\Framework\Security\SsrfGuard;
use Lite\Framework\Security\TotpAuthenticator;
use Lite\Framework\Seo\SitemapGenerator;
use Lite\Framework\Testing\TestCase;
use LiteView\ViewEngine;
use PDO;

/**
 * End-to-end verification of:
 * 1. LES v1 Plugin Sandbox, Capability Isolation, Theme Override Layer, Lockfile Integrity, and PluginGenerator
 * 2. Deep Security Engine (Dynamic RBAC UI, Instant Session Revocation, TOTP 2FA, SecretVault AEAD, Audit Chain, SSRF)
 * 3. Composable 4-Extension Suite (Catalog -> CRM via HookBus -> Orders -> Reports via DAG Dependencies)
 */
class PluginSecurityAndRetailTest extends TestCase
{
    public function testPluginContextEnforcesCapabilitySandboxAndProtectsCoreDi(): void
    {
        $container = $this->app->getContainer();
        $manifest = new PluginManifest(
            id: 'com.test.sandbox-probe',
            name: 'Sandbox Probe',
            version: '1.0.0',
            description: 'Least-privilege probe',
            capabilities: [Capability::DB_READ, Capability::CACHE_SCOPED]
        );
        $ctx = new PluginContext($manifest, $container, new HookBus(), $container->get(SecretVault::class), null, new SsrfGuard());

        $blockedOverride = false;
        try {
            $ctx->registerService(PDO::class, static fn() => null);
        } catch (\RuntimeException $e) {
            $blockedOverride = str_contains($e->getMessage(), 'protected Core service');
        }
        $this->assertTrue($blockedOverride);

        $blockedConfig = false;
        try {
            $ctx->getService('config');
        } catch (\RuntimeException $e) {
            $blockedConfig = str_contains($e->getMessage(), 'raw Core secret');
        }
        $this->assertTrue($blockedConfig);
        $this->assertFalse($ctx->hasService('jwt.secret'));

        $deniedCap = false;
        try {
            $ctx->assertOutboundUrlSafe('https://93.184.216.34');
        } catch (\RuntimeException $e) {
            $deniedCap = str_contains($e->getMessage(), 'Capability denied');
        }
        $this->assertTrue($deniedCap);

        $blockedRoute = false;
        try {
            new PluginManifest(
                id: 'com.test.bad-route',
                name: 'Bad Route',
                version: '1.0.0',
                description: 'Tries to shadow login',
                routePrefixes: ['/admin/login']
            );
        } catch (\InvalidArgumentException $e) {
            $blockedRoute = str_contains($e->getMessage(), 'reserved Core path');
        }
        $this->assertTrue($blockedRoute);
    }

    public function testThemeOverrideLayerOverridesCoreAndPluginViewsNonDestructively(): void
    {
        $tmpRoot = sys_get_temp_dir() . '/lite_theme_test_' . uniqid();
        $viewsDir = $tmpRoot . '/templates';
        $pluginDir = $tmpRoot . '/plugin_views';
        $themeDir = $tmpRoot . '/themes/custom';
        $cacheDir = $tmpRoot . '/cache';

        mkdir($viewsDir, 0777, true);
        mkdir($pluginDir, 0777, true);
        mkdir($themeDir . '/modules/catalog', 0777, true);
        mkdir($cacheDir, 0777, true);

        try {
            file_put_contents($viewsDir . '/hello.lite.php', 'CORE_DEFAULT');
            file_put_contents($viewsDir . '/fallback.lite.php', 'CORE_FALLBACK');
            file_put_contents($pluginDir . '/index.lite.php', 'PLUGIN_DEFAULT');
            file_put_contents($themeDir . '/hello.lite.php', 'THEME_CORE_OVERRIDE');
            file_put_contents($themeDir . '/modules/catalog/index.lite.php', 'THEME_PLUGIN_OVERRIDE');

            $engine = new ViewEngine($viewsDir, $cacheDir);
            $engine->addNamespace('catalog', $pluginDir);

            // Without theme: default templates
            $this->assertEquals('CORE_DEFAULT', trim($engine->render('hello')));
            $this->assertEquals('PLUGIN_DEFAULT', trim($engine->render('catalog::index')));

            // With Child-Theme active: overrides win, un-overridden views fall back seamlessly
            $engine->setThemePath($themeDir);
            $this->assertEquals('THEME_CORE_OVERRIDE', trim($engine->render('hello')));
            $this->assertEquals('THEME_PLUGIN_OVERRIDE', trim($engine->render('catalog::index')));
            $this->assertEquals('CORE_FALLBACK', trim($engine->render('fallback')));
        } finally {
            $this->deleteDir($tmpRoot);
        }
    }

    public function testPluginGeneratorAndLockfileIntegrityVerification(): void
    {
        $tmpBase = sys_get_temp_dir() . '/lite_plug_gen_' . uniqid();
        mkdir($tmpBase . '/app/Modules', 0777, true);

        try {
            $gen = new PluginGenerator($tmpBase);
            $res = $gen->generate('BookingExtension');
            $this->assertEquals('com.liteplatform.booking-extension', $res['module_id']);
            $this->assertFileExists($res['module_dir'] . '/BookingExtensionModule.php');

            // Generated plugin must pass LES v1 static security scanner with 0 criticals
            $scanner = new PluginStaticScanner();
            $scan = $scanner->scanDirectory($res['module_dir']);
            $this->assertTrue($scan['ok']);

            // Lockfile verification: passes when clean, flags TAMPERED after modifying a file
            $lockMgr = new PluginLockManager($tmpBase, $scanner);
            $lockMgr->generateLock();
            $verified = $lockMgr->verifyLock();
            $this->assertTrue($verified['ok']);
            $this->assertEquals('VERIFIED', $verified['entries']['BookingExtension']['status']);

            file_put_contents($res['module_dir'] . '/BookingExtensionModule.php', "\n// tampered\n", FILE_APPEND);
            $tampered = $lockMgr->verifyLock();
            $this->assertFalse($tampered['ok']);
            $this->assertEquals('TAMPERED', $tampered['entries']['BookingExtension']['status']);
        } finally {
            $this->deleteDir($tmpBase);
        }
    }

    public function testHookBusSecretVaultTotpSsrfAuditChainAndStaticScanner(): void
    {
        // 1. HookBus Fault Isolation
        $bus = new HookBus();
        $ranHealthy = false;
        $bus->addAction('test.ev', static function (): void {
            throw new \RuntimeException('Crash');
        }, 5, 'com.bad.mod');
        $bus->addAction('test.ev', static function () use (&$ranHealthy): void {
            $ranHealthy = true;
        }, 10, 'com.good.mod');
        $bus->doAction('test.ev');
        $this->assertTrue($ranHealthy);
        $this->assertCount(1, $bus->getRecordedErrors());

        // 2. SecretVault AEAD & Scope Binding
        $vault = new SecretVault('super-strong-master-secret-32-bytes!');
        $cipher = $vault->encrypt('sk_live_secret_999', 'com.vendor.pay', 'api_key');
        $this->assertEquals('sk_live_secret_999', $vault->decrypt($cipher, 'com.vendor.pay', 'api_key'));
        $scopeRejected = false;
        try {
            $vault->decrypt($cipher, 'com.other.plugin', 'api_key');
        } catch (\RuntimeException) {
            $scopeRejected = true;
        }
        $this->assertTrue($scopeRejected);

        // 3. TOTP 2FA RFC 6238
        $totp = new TotpAuthenticator();
        $secret = $totp->generateSecret();
        $ts = 1795350000;
        $this->assertTrue($totp->verify($secret, $totp->getCode($secret, $ts), 1, $ts));
        $this->assertFalse($totp->verify($secret, '000000', 0, $ts + 3600));

        // 4. SSRF Guard
        $ssrf = new SsrfGuard();
        $this->assertTrue($ssrf->isUrlSafe('https://93.184.216.34/webhook'));
        $this->assertFalse($ssrf->isUrlSafe('http://127.0.0.1/admin'));
        $this->assertFalse($ssrf->isUrlSafe('http://169.254.169.254/latest/meta-data/'));
        $this->assertFalse($ssrf->isUrlSafe('http://192.168.1.10/internal'));

        // 5. Static Scanner flags dangerous constructs & passes all 4 Composable Extensions
        $scanner = new PluginStaticScanner();
        $bad = $scanner->scanSource('<?php eval($_POST["x"]); shell_exec("id"); unserialize($data);');
        $this->assertCount(3, $bad['critical']);
        foreach (['CatalogExtension', 'CrmExtension', 'OrderExtension', 'ReportExtension'] as $modDir) {
            $scan = $scanner->scanDirectory(dirname(__DIR__) . '/app/Modules/' . $modDir);
            $this->assertTrue($scan['ok'], "{$modDir} must pass static audit cleanly.");
        }

        // 6. HMAC Audit Chain Tamper Detection
        $memPdo = new PDO('sqlite::memory:');
        $memPdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $memPdo->exec('CREATE TABLE security_audit_logs (id INTEGER PRIMARY KEY AUTOINCREMENT, occurred_at VARCHAR(32) NOT NULL, severity VARCHAR(16) NOT NULL, event_type VARCHAR(80) NOT NULL, actor VARCHAR(160) NOT NULL, actor_ip VARCHAR(64) NULL, module_id VARCHAR(120) NOT NULL, target VARCHAR(255) NULL, context_json TEXT NULL, prev_hash VARCHAR(64) NOT NULL, entry_hash VARCHAR(64) NOT NULL)');
        $audit = new SecurityAuditLog($memPdo, 'audit-chain-secret-key-32-chars!');
        $id1 = $audit->record('auth.login_success', 'INFO', 'admin@local', '127.0.0.1', 'core', '/admin');
        $audit->record('reports.exported', 'INFO', 'admin@local', '127.0.0.1', ReportExtensionModule::MODULE_ID, '/admin/reports/export');
        $this->assertTrue($audit->verifyChain()['ok']);
        $memPdo->prepare("UPDATE security_audit_logs SET actor = 'tampered' WHERE id = :id")->execute([':id' => $id1]);
        $broken = $audit->verifyChain();
        $this->assertFalse($broken['ok']);
        $this->assertEquals($id1, $broken['broken_at_id']);
    }

    public function testRbacMatrixUiPermissionEnforcementAndInstantSessionRevocation(): void
    {
        $container = $this->app->getContainer();
        /** @var PDO $pdo */
        $pdo = $container->get(PDO::class);
        /** @var RbacManager $rbac */
        $rbac = $container->get(RbacManager::class);

        $suffix = substr(uniqid(), -6);
        $staffEmail = "staff-{$suffix}@test.local";
        $staffId = 0;

        try {
            $pdo->prepare("INSERT INTO users (name, email, role, password_hash, token_version, is_active) VALUES (:n, :e, 'staff', :h, 1, 1)")
                ->execute([':n' => 'Staff Probe', ':e' => $staffEmail, ':h' => password_hash('Secret123!', PASSWORD_BCRYPT)]);
            $staffId = (int)$pdo->lastInsertId();
            $rbac->assignRole($staffId, 'staff');

            $staffCookie = $this->makeAdminCookie($staffEmail, tokenVersion: 1);

            // Staff CAN access Orders (orders.read) & Customers (crm.customers.read)
            $this->assertStatus(
                $this->app->handle($this->factory->createServerRequest('GET', '/admin/order')->withCookieParams(['lite_admin_auth' => $staffCookie])),
                200
            );

            // Staff CANNOT access Reports, Export, Security Center, or RBAC Roles (403 Forbidden!)
            foreach (['/admin/reports', '/admin/reports/export', '/admin/security', '/admin/roles'] as $deniedPath) {
                $this->assertStatus(
                    $this->app->handle($this->factory->createServerRequest('GET', $deniedPath)->withCookieParams(['lite_admin_auth' => $staffCookie])),
                    403
                );
            }

            // Instant Session Revocation
            $this->assertEquals(2, $rbac->revokeUserSessions($staffId));
            $revokedRes = $this->app->handle($this->factory->createServerRequest('GET', '/admin/order')->withCookieParams(['lite_admin_auth' => $staffCookie]));
            $this->assertStatus($revokedRes, 302);
            $this->assertEquals('/admin/login', $revokedRes->getHeaderLine('Location'));

            // Admin CAN access Security Center & RBAC Role Matrix
            $adminCookie = $this->makeAdminCookie();
            $secRes = $this->app->handle($this->factory->createServerRequest('GET', '/admin/security')->withCookieParams(['lite_admin_auth' => $adminCookie]));
            $this->assertStatus($secRes, 200);
            $secHtml = (string)$secRes->getBody();
            $this->assertStringContainsString(CatalogExtensionModule::MODULE_ID, $secHtml);
            $this->assertStringContainsString(ReportExtensionModule::MODULE_ID, $secHtml);

            $rolesRes = $this->app->handle($this->factory->createServerRequest('GET', '/admin/roles')->withCookieParams(['lite_admin_auth' => $adminCookie]));
            $this->assertStatus($rolesRes, 200);
            $this->assertStringContainsString('reports.export', (string)$rolesRes->getBody());
        } finally {
            if ($staffId > 0) {
                $pdo->prepare('DELETE FROM user_roles WHERE user_id = :id')->execute([':id' => $staffId]);
                $pdo->prepare('DELETE FROM users WHERE id = :id')->execute([':id' => $staffId]);
                $rbac->invalidateCache();
            }
        }
    }

    public function testComposableFourExtensionSuiteEndToEnd(): void
    {
        $container = $this->app->getContainer();
        /** @var PDO $pdo */
        $pdo = $container->get(PDO::class);
        /** @var AvailabilityService $availability */
        $availability = $container->get(AvailabilityService::class);
        /** @var RevenueReportService $reports */
        $reports = $container->get(RevenueReportService::class);
        /** @var ModuleManagerInterface $mm */
        $mm = $container->get(ModuleManagerInterface::class);

        // Verify all 4 composable extensions booted cleanly
        $this->assertTrue($mm->hasModule(CatalogExtensionModule::MODULE_ID));
        $this->assertTrue($mm->hasModule(CrmExtensionModule::MODULE_ID));
        $this->assertTrue($mm->hasModule(OrderExtensionModule::MODULE_ID));
        $this->assertTrue($mm->hasModule(ReportExtensionModule::MODULE_ID));

        $suffix = substr(uniqid(), -6);
        $sku = 'CMP-' . $suffix;
        $slug = 'probe-composable-' . $suffix;
        $phone = '0955' . random_int(100000, 999999);
        $itemId = 0;
        $customerId = 0;
        $orderIds = [];

        try {
            $attrsJson = json_encode([['key' => 'Chất liệu', 'value' => 'Lụa cao cấp']], JSON_UNESCAPED_UNICODE);
            $pdo->prepare("INSERT INTO retail_items (sku, name, slug, listing_type, price, deposit_default, variant_summary, attributes_json, description, status) VALUES (:sku, :name, :slug, 'both', 500000, 400000, 'S, M, L', :attrs, :desc, 'active')")
                ->execute([':sku' => $sku, ':name' => 'Composable Item ' . $suffix, ':slug' => $slug, ':attrs' => $attrsJson, ':desc' => '<p>Mô tả chuẩn</p><script>alert(1)</script>']);
            $itemId = (int)$pdo->lastInsertId();

            // 1. CatalogExtension public showcase + Product Schema + XSS sanitization + Sitemap
            $detailRes = $this->app->handle($this->factory->createServerRequest('GET', '/catalog/' . $slug));
            $this->assertStatus($detailRes, 200);
            $detailHtml = (string)$detailRes->getBody();
            $this->assertStringContainsString('"@type":"Product"', $detailHtml);
            $this->assertStringContainsString('Lụa cao cấp', $detailHtml);
            $this->assertStringNotContainsString('<script>alert(1)</script>', $detailHtml);

            /** @var SitemapGenerator $sitemap */
            $sitemap = $container->get(SitemapGenerator::class);
            $this->assertStringContainsString('/catalog/' . $slug, $sitemap->renderXml(useCache: false));

            // 2. CatalogExtension inquiry fires `catalog.inquiry.submitted` -> CrmExtension auto-upserts customer!
            preg_match('/name="_csrf"\s+value="([^"]+)"/', $detailHtml, $csrfM);
            $csrf = $csrfM[1] ?? '';
            $postRes = $this->app->handle(
                $this->factory->createServerRequest('POST', '/catalog/inquiry')
                    ->withCookieParams([CsrfGuard::COOKIE_NAME => $csrf])
                    ->withParsedBody(array_merge($this->buildValidHoneypotPayload($container), [
                        '_csrf'         => $csrf,
                        'return_slug'   => $slug,
                        'selected_skus' => $sku,
                        'name'          => 'Phạm Quỳnh Anh',
                        'phone'         => $phone,
                        'target_date'   => '2027-07-10',
                    ]))
            );
            $this->assertStatus($postRes, 200);
            $this->assertStringContainsString('Đã gửi yêu cầu thành công', (string)$postRes->getBody());

            $cStmt = $pdo->prepare('SELECT id FROM retail_customers WHERE phone = :p');
            $cStmt->execute([':p' => $phone]);
            $customerId = (int)$cStmt->fetchColumn();
            $this->assertGreaterThan(0, $customerId, 'CrmExtension HookBus listener should have auto-created customer.');

            // 3. OrderExtension availability guard + ReportExtension accounting & Excel export
            $ins = $pdo->prepare("INSERT INTO retail_orders (order_code, order_type, customer_id, item_id, start_date, end_date, subtotal, discount, extra_fees, deposit_held, deposit_refunded, status) VALUES (:oc, :ot, :cid, :iid, :sd, :ed, :sub, :disc, :ext, :dh, :dr, :st)");
            $ins->execute([':oc' => 'ORD-R-' . $suffix, ':ot' => 'rental', ':cid' => $customerId, ':iid' => $itemId, ':sd' => '2027-09-10', ':ed' => '2027-09-12', ':sub' => 600000, ':disc' => 50000, ':ext' => 30000, ':dh' => 500000, ':dr' => 0, ':st' => 'active']);
            $orderIds[] = (int)$pdo->lastInsertId();
            $ins->execute([':oc' => 'ORD-S-' . $suffix, ':ot' => 'sale', ':cid' => $customerId, ':iid' => $itemId, ':sd' => '2027-09-15', ':ed' => null, ':sub' => 900000, ':disc' => 0, ':ext' => 0, ':dh' => 0, ':dr' => 0, ':st' => 'completed']);
            $orderIds[] = (int)$pdo->lastInsertId();
            $ins->execute([':oc' => 'ORD-C-' . $suffix, ':ot' => 'rental', ':cid' => $customerId, ':iid' => $itemId, ':sd' => '2027-09-22', ':ed' => '2027-09-24', ':sub' => 700000, ':disc' => 0, ':ext' => 0, ':dh' => 400000, ':dr' => 0, ':st' => 'cancelled']);
            $orderIds[] = (int)$pdo->lastInsertId();

            $this->assertFalse($availability->isAvailable($itemId, '2027-09-11', '2027-09-13'));
            $this->assertTrue($availability->isAvailable($itemId, '2027-09-13', '2027-09-14'));

            $rep = $reports->buildReport('2027-09-01', '2027-09-30', today: '2027-09-20');
            $this->assertEquals(580000.0, $rep['kpis']['rental_revenue']);
            $this->assertEquals(900000.0, $rep['kpis']['sale_revenue']);
            $this->assertEquals(1480000.0, $rep['kpis']['net_revenue']);
            $this->assertEquals(500000.0, $rep['kpis']['deposit_held_period']);

            $adminCookie = $this->makeAdminCookie();
            $repRes = $this->app->handle($this->factory->createServerRequest('GET', '/admin/reports')->withQueryParams(['from' => '2027-09-01', 'to' => '2027-09-30'])->withCookieParams(['lite_admin_auth' => $adminCookie]));
            $this->assertStatus($repRes, 200);
            $this->assertStringContainsString('1.480.000', (string)$repRes->getBody());

            $xlsxRes = $this->app->handle($this->factory->createServerRequest('GET', '/admin/reports/export')->withQueryParams(['from' => '2027-09-01', 'to' => '2027-09-30'])->withCookieParams(['lite_admin_auth' => $adminCookie]));
            $this->assertStatus($xlsxRes, 200);
            $this->assertStringStartsWith("PK\x03\x04", (string)$xlsxRes->getBody());

            $baseDir = dirname(__DIR__);
            $sqlBundle = (new MigrationSqlExporter())->export([
                $baseDir . '/database/migrations',
                $baseDir . '/app/Modules/CatalogExtension/migrations',
                $baseDir . '/app/Modules/CrmExtension/migrations',
                $baseDir . '/app/Modules/OrderExtension/migrations',
            ]);
            $this->assertStringContainsString('CREATE TABLE IF NOT EXISTS retail_items', $sqlBundle['sql']);
            $this->assertStringContainsString('CREATE TABLE IF NOT EXISTS retail_customers', $sqlBundle['sql']);
            $this->assertStringContainsString('CREATE TABLE IF NOT EXISTS retail_orders', $sqlBundle['sql']);
        } finally {
            foreach ($orderIds as $oid) {
                $pdo->prepare('DELETE FROM retail_orders WHERE id = :id')->execute([':id' => $oid]);
            }
            $pdo->prepare('DELETE FROM contact_leads WHERE phone = :p')->execute([':p' => $phone]);
            if ($customerId > 0) {
                $pdo->prepare('DELETE FROM retail_customers WHERE id = :id')->execute([':id' => $customerId]);
            }
            if ($itemId > 0) {
                $pdo->prepare('DELETE FROM retail_items WHERE id = :id')->execute([':id' => $itemId]);
            }
            if ($container->has(SitemapGenerator::class)) {
                $container->get(SitemapGenerator::class)->invalidateCache();
            }
        }
    }

    private function makeAdminCookie(string $username = 'admin', int $tokenVersion = 1): string
    {
        $config = (array)$this->app->getContainer()->get('config');
        $secret = (string)($config['admin']['auth_secret'] ?? $config['security']['csrf_secret'] ?? AdminToken::DEFAULT_INSECURE_SECRET);
        return AdminToken::issue($username, $secret, 3600, tokenVersion: $tokenVersion);
    }

    /** @return array<string, string> */
    private function buildValidHoneypotPayload(object $container): array
    {
        if (!$container->has(HoneypotGuard::class)) {
            return [];
        }
        $fieldsHtml = $container->get(HoneypotGuard::class)->renderFields(time() - 5);
        preg_match('/name="_hp_ts"\s+value="([^"]+)"/', $fieldsHtml, $m);
        return [HoneypotGuard::TRAP_FIELD => '', HoneypotGuard::TIME_FIELD => $m[1] ?? ''];
    }

    private function deleteDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $iter = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iter as $item) {
            $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }
        @rmdir($dir);
    }
}
