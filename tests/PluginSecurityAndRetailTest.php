<?php

declare(strict_types=1);

namespace Tests;

use App\Modules\RetailExtension\RetailExtensionModule;
use App\Modules\RetailExtension\Services\AvailabilityService;
use App\Modules\RetailExtension\Services\RevenueReportService;
use Lite\Framework\Console\MigrationSqlExporter;
use Lite\Framework\Core\Plugin\Capability;
use Lite\Framework\Core\Plugin\HookBus;
use Lite\Framework\Core\Plugin\ModuleManager;
use Lite\Framework\Core\Plugin\ModuleManagerInterface;
use Lite\Framework\Core\Plugin\PluginContext;
use Lite\Framework\Core\Plugin\PluginManifest;
use Lite\Framework\Core\Plugin\PluginStaticScanner;
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
use PDO;

/**
 * End-to-end verification of:
 * 1. LES v1 Plugin Extension Standard (Capability Sandbox, PluginContext, HookBus, Static Scanner)
 * 2. Deep Security Layer (Dynamic RBAC, Instant Session Revocation, TOTP 2FA, SecretVault AEAD,
 *    HMAC-Chained SecurityAuditLog, SSRF Guard, CSP Headers)
 * 3. Domain-agnostic RetailExtension (Rent & Sale catalog, dynamic attributes, overlap guard, accounting reports)
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
        $ctx = new PluginContext(
            $manifest,
            $container,
            new HookBus(),
            $container->get(SecretVault::class),
            null,
            new SsrfGuard()
        );

        // 1. Overwriting protected Core DI service must fail
        $blockedOverride = false;
        try {
            $ctx->registerService(PDO::class, static fn() => null);
        } catch (\RuntimeException $e) {
            $blockedOverride = str_contains($e->getMessage(), 'protected Core service');
        }
        $this->assertTrue($blockedOverride);

        // 2. Reading raw Core config / jwt.secret must fail
        $blockedConfig = false;
        try {
            $ctx->getService('config');
        } catch (\RuntimeException $e) {
            $blockedConfig = str_contains($e->getMessage(), 'raw Core secret');
        }
        $this->assertTrue($blockedConfig);
        $this->assertFalse($ctx->hasService('jwt.secret'));

        // 3. Undeclared capability (HTTP_OUTBOUND) must be denied
        $deniedCap = false;
        try {
            $ctx->assertOutboundUrlSafe('https://93.184.216.34');
        } catch (\RuntimeException $e) {
            $deniedCap = str_contains($e->getMessage(), 'Capability denied');
        }
        $this->assertTrue($deniedCap);

        // 4. Reserved Core route prefix in manifest must be rejected
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

    public function testHookBusIsolatesPluginFailuresWithoutCrashingHost(): void
    {
        $bus = new HookBus();
        $ranHealthy = false;

        $bus->addAction('retail.test.event', static function (): void {
            throw new \RuntimeException('Simulated plugin crash');
        }, priority: 5, moduleId: 'com.bad.plugin');

        $bus->addAction('retail.test.event', static function () use (&$ranHealthy): void {
            $ranHealthy = true;
        }, priority: 10, moduleId: 'com.good.plugin');

        $bus->doAction('retail.test.event');
        $this->assertTrue($ranHealthy);
        $this->assertCount(1, $bus->getRecordedErrors());
        $this->assertEquals('com.bad.plugin', $bus->getRecordedErrors()[0]['module_id']);
    }

    public function testSecretVaultAeadEncryptionRejectsTamperingAndCrossPluginScope(): void
    {
        $vault = new SecretVault('super-strong-master-secret-32-bytes!');
        $cipher = $vault->encrypt('sk_live_payment_secret_999', 'com.vendor.payments', 'api_key');

        $this->assertTrue($vault->isEncryptedEnvelope($cipher));
        $this->assertEquals('sk_live_payment_secret_999', $vault->decrypt($cipher, 'com.vendor.payments', 'api_key'));

        // Wrong plugin scope (AD mismatch) must fail decryption
        $wrongScopeFailed = false;
        try {
            $vault->decrypt($cipher, 'com.attacker.plugin', 'api_key');
        } catch (\RuntimeException) {
            $wrongScopeFailed = true;
        }
        $this->assertTrue($wrongScopeFailed);

        // Tampered ciphertext must fail authentication tag
        $tampered = substr($cipher, 0, -4) . 'AAAA';
        $tamperedFailed = false;
        try {
            $vault->decrypt($tampered, 'com.vendor.payments', 'api_key');
        } catch (\RuntimeException) {
            $tamperedFailed = true;
        }
        $this->assertTrue($tamperedFailed);
    }

    public function testTotp2faSsrfGuardAndStaticPluginScanner(): void
    {
        // 1. Zero-dep RFC 6238 TOTP 2FA
        $totp = new TotpAuthenticator();
        $secret = $totp->generateSecret();
        $now = 1795350000;
        $code = $totp->getCode($secret, $now);
        $this->assertMatchesRegularExpression('/^\d{6}$/', $code);
        $this->assertTrue($totp->verify($secret, $code, window: 1, timestamp: $now));
        $this->assertFalse($totp->verify($secret, '000000', window: 0, timestamp: $now + 3600));
        $this->assertStringStartsWith('otpauth://totp/', $totp->provisioningUri('LitePlatform', 'admin@site.com', $secret));

        // 2. SSRF Guard blocks internal/metadata IPs & allows public IP
        $ssrf = new SsrfGuard();
        $this->assertTrue($ssrf->isUrlSafe('https://93.184.216.34/webhook'));
        $this->assertFalse($ssrf->isUrlSafe('http://127.0.0.1/admin'));
        $this->assertFalse($ssrf->isUrlSafe('http://169.254.169.254/latest/meta-data/'));
        $this->assertFalse($ssrf->isUrlSafe('http://192.168.1.10/internal'));
        $this->assertFalse($ssrf->isUrlSafe('http://localhost:8000/'));
        $this->assertFalse($ssrf->isUrlSafe('file:///etc/passwd'));

        // 3. Static Tokenizer Scanner flags malicious code & passes RetailExtension cleanly
        $scanner = new PluginStaticScanner();
        $bad = $scanner->scanSource('<?php eval($_POST["x"]); shell_exec("id"); unserialize($data); $k = $_ENV["APP_KEY"];');
        $this->assertCount(3, $bad['critical']);
        $this->assertNotEmpty($bad['warnings']);

        $retailDir = dirname(__DIR__) . '/app/Modules/RetailExtension';
        $scanRes = $scanner->scanDirectory($retailDir);
        $this->assertTrue($scanRes['ok'], 'RetailExtension must pass LES v1 static security scan with 0 criticals.');
        $this->assertGreaterThan(5, $scanRes['files_scanned']);
    }

    public function testSecurityAuditChainDetectsTampering(): void
    {
        $memPdo = new PDO('sqlite::memory:');
        $memPdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $memPdo->exec('
            CREATE TABLE security_audit_logs (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                occurred_at VARCHAR(32) NOT NULL,
                severity VARCHAR(16) NOT NULL,
                event_type VARCHAR(80) NOT NULL,
                actor VARCHAR(160) NOT NULL,
                actor_ip VARCHAR(64) NULL,
                module_id VARCHAR(120) NOT NULL,
                target VARCHAR(255) NULL,
                context_json TEXT NULL,
                prev_hash VARCHAR(64) NOT NULL,
                entry_hash VARCHAR(64) NOT NULL
            )
        ');

        $audit = new SecurityAuditLog($memPdo, 'audit-chain-secret-key-32-chars!');
        $id1 = $audit->record('auth.login_success', 'INFO', 'admin@local', '127.0.0.1', 'core', '/admin');
        $audit->record('retail.report_exported', 'INFO', 'admin@local', '127.0.0.1', RetailExtensionModule::MODULE_ID, '/admin/retail-reports/export');

        $ok = $audit->verifyChain();
        $this->assertTrue($ok['ok']);
        $this->assertEquals(2, $ok['checked']);

        // Simulate DB attacker altering actor on row #1
        $memPdo->prepare("UPDATE security_audit_logs SET actor = 'ghost' WHERE id = :id")->execute([':id' => $id1]);
        $tampered = $audit->verifyChain();
        $this->assertFalse($tampered['ok']);
        $this->assertEquals($id1, $tampered['broken_at_id']);
    }

    public function testRbacPermissionEnforcementAndInstantSessionRevocation(): void
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
            $pdo->prepare("
                INSERT INTO users (name, email, role, password_hash, token_version, is_active)
                VALUES (:n, :e, 'staff', :h, 1, 1)
            ")->execute([
                ':n' => 'Staff Probe',
                ':e' => $staffEmail,
                ':h' => password_hash('Secret123!', PASSWORD_BCRYPT),
            ]);
            $staffId = (int)$pdo->lastInsertId();
            $rbac->assignRole($staffId, 'staff');

            $staffCookie = $this->makeAdminCookie($staffEmail, tokenVersion: 1);

            // Staff CAN view Orders (granted retail.orders.read)
            $ordersRes = $this->app->handle(
                $this->factory->createServerRequest('GET', '/admin/retail-order')
                    ->withCookieParams(['lite_admin_auth' => $staffCookie])
            );
            $this->assertStatus($ordersRes, 200);

            // Staff CANNOT view Financial Reports or Export Excel or Security Center (403 Forbidden!)
            $reportRes = $this->app->handle(
                $this->factory->createServerRequest('GET', '/admin/retail-reports')
                    ->withCookieParams(['lite_admin_auth' => $staffCookie])
            );
            $this->assertStatus($reportRes, 403);

            $exportRes = $this->app->handle(
                $this->factory->createServerRequest('GET', '/admin/retail-reports/export')
                    ->withCookieParams(['lite_admin_auth' => $staffCookie])
            );
            $this->assertStatus($exportRes, 403);

            $secRes = $this->app->handle(
                $this->factory->createServerRequest('GET', '/admin/security')
                    ->withCookieParams(['lite_admin_auth' => $staffCookie])
            );
            $this->assertStatus($secRes, 403);

            // Instant Session Revocation: bump token_version -> old cookie immediately redirected to /admin/login (302)
            $newVer = $rbac->revokeUserSessions($staffId);
            $this->assertEquals(2, $newVer);

            $revokedRes = $this->app->handle(
                $this->factory->createServerRequest('GET', '/admin/retail-order')
                    ->withCookieParams(['lite_admin_auth' => $staffCookie])
            );
            $this->assertStatus($revokedRes, 302);
            $this->assertEquals('/admin/login', $revokedRes->getHeaderLine('Location'));

            // Admin CAN access Security & Plugin Governance Center
            $adminSecRes = $this->app->handle(
                $this->factory->createServerRequest('GET', '/admin/security')
                    ->withCookieParams(['lite_admin_auth' => $this->makeAdminCookie()])
            );
            $this->assertStatus($adminSecRes, 200);
            $this->assertStringContainsString(RetailExtensionModule::MODULE_ID, (string)$adminSecRes->getBody());
            $this->assertStringContainsString('LES v1 Sandboxed', (string)$adminSecRes->getBody());
        } finally {
            if ($staffId > 0) {
                $pdo->prepare('DELETE FROM user_roles WHERE user_id = :id')->execute([':id' => $staffId]);
                $pdo->prepare('DELETE FROM users WHERE id = :id')->execute([':id' => $staffId]);
                $rbac->invalidateCache();
            }
        }
    }

    public function testRetailExtensionCatalogAvailabilityRevenueAndInquiryEndToEnd(): void
    {
        $container = $this->app->getContainer();
        /** @var PDO $pdo */
        $pdo = $container->get(PDO::class);
        /** @var AvailabilityService $availability */
        $availability = $container->get(AvailabilityService::class);
        /** @var RevenueReportService $reports */
        $reports = $container->get(RevenueReportService::class);
        /** @var HookBus $hooks */
        $hooks = $container->get(HookBus::class);
        /** @var ModuleManagerInterface $mm */
        $mm = $container->get(ModuleManagerInterface::class);

        $this->assertTrue($mm->hasModule(RetailExtensionModule::MODULE_ID));

        $suffix = substr(uniqid(), -6);
        $sku = 'RT-' . $suffix;
        $slug = 'probe-retail-item-' . $suffix;
        $phone = '0966' . random_int(100000, 999999);
        $itemId = 0;
        $customerId = 0;
        $orderIds = [];
        $hookFired = false;

        $hooks->addAction('retail.inquiry.submitted', static function (array $payload) use (&$hookFired, $sku): void {
            if (($payload['selected_skus'] ?? '') === $sku) {
                $hookFired = true;
            }
        });

        try {
            // 1. Insert generic item with dynamic attributes & XSS probe in description
            $attrsJson = json_encode([
                ['key' => 'Chất liệu', 'value' => 'Lụa / Vải cao cấp'],
                ['key' => 'Phân loại', 'value' => 'S, M, L'],
            ], JSON_UNESCAPED_UNICODE);

            $pdo->prepare("
                INSERT INTO retail_items (sku, name, slug, listing_type, price, deposit_default, variant_summary, attributes_json, description, status)
                VALUES (:sku, :name, :slug, 'both', 500000, 400000, 'S, M, L', :attrs, :desc, 'active')
            ")->execute([
                ':sku'   => $sku,
                ':name'  => 'Universal Retail Item ' . $suffix,
                ':slug'  => $slug,
                ':attrs' => $attrsJson,
                ':desc'  => '<p>Mô tả chuẩn</p><script>alert(1)</script>',
            ]);
            $itemId = (int)$pdo->lastInsertId();

            // 2. Public Catalog, Detail & Sitemap
            $listRes = $this->app->handle(
                $this->factory->createServerRequest('GET', '/catalog')->withQueryParams(['q' => $sku])
            );
            $this->assertStatus($listRes, 200);
            $this->assertStringContainsString($sku, (string)$listRes->getBody());
            $this->assertNotEmpty($listRes->getHeaderLine('Content-Security-Policy'));

            $detailRes = $this->app->handle($this->factory->createServerRequest('GET', '/catalog/' . $slug));
            $this->assertStatus($detailRes, 200);
            $detailHtml = (string)$detailRes->getBody();
            $this->assertStringContainsString('"@type":"Product"', $detailHtml);
            $this->assertStringContainsString('Lụa / Vải cao cấp', $detailHtml);
            $this->assertStringNotContainsString('<script>alert(1)</script>', $detailHtml);

            /** @var SitemapGenerator $sitemap */
            $sitemap = $container->get(SitemapGenerator::class);
            $xml = $sitemap->renderXml(useCache: false);
            $this->assertStringContainsString('/catalog/' . $slug, $xml);

            // 3. Submit fast inquiry (CSRF + Honeypot -> upserts customer, saves lead, fires HookBus)
            preg_match('/name="_csrf"\s+value="([^"]+)"/', $detailHtml, $csrfM);
            $csrf = $csrfM[1] ?? '';
            $this->assertNotEmpty($csrf);

            $hpPayload = $this->buildValidHoneypotPayload($container);
            $postRes = $this->app->handle(
                $this->factory->createServerRequest('POST', '/catalog/inquiry')
                    ->withCookieParams([CsrfGuard::COOKIE_NAME => $csrf])
                    ->withParsedBody(array_merge($hpPayload, [
                        '_csrf'         => $csrf,
                        'return_slug'   => $slug,
                        'selected_skus' => $sku,
                        'name'          => 'Nguyễn Bảo Ngọc',
                        'phone'         => $phone,
                        'target_date'   => '2027-07-10',
                        'notes'         => 'Cần tư vấn gấp',
                    ]))
            );
            $this->assertStatus($postRes, 200);
            $this->assertStringContainsString('Đã gửi yêu cầu thành công', (string)$postRes->getBody());
            $this->assertTrue($hookFired, 'HookBus retail.inquiry.submitted should have fired.');

            $cStmt = $pdo->prepare('SELECT id FROM retail_customers WHERE phone = :p');
            $cStmt->execute([':p' => $phone]);
            $customerId = (int)$cStmt->fetchColumn();
            $this->assertGreaterThan(0, $customerId);

            // 4. Rental overlap guard + unified Rent/Sale accounting report
            $ins = $pdo->prepare("
                INSERT INTO retail_orders (
                    order_code, order_type, customer_id, item_id, start_date, end_date,
                    subtotal, discount, extra_fees, deposit_held, deposit_refunded, status
                ) VALUES (
                    :oc, :ot, :cid, :iid, :sd, :ed,
                    :sub, :disc, :ext, :dh, :dr, :st
                )
            ");

            // Rental order (active, overdue vs 2027-08-20): 600k - 50k + 30k = 580k; deposit 500k held
            $ins->execute([
                ':oc' => 'ORD-R1-' . $suffix, ':ot' => 'rental', ':cid' => $customerId, ':iid' => $itemId,
                ':sd' => '2027-08-10', ':ed' => '2027-08-12',
                ':sub' => 600000, ':disc' => 50000, ':ext' => 30000, ':dh' => 500000, ':dr' => 0, ':st' => 'active',
            ]);
            $orderIds[] = (int)$pdo->lastInsertId();

            // Sale order (completed): 900k net, 0 deposit
            $ins->execute([
                ':oc' => 'ORD-S1-' . $suffix, ':ot' => 'sale', ':cid' => $customerId, ':iid' => $itemId,
                ':sd' => '2027-08-15', ':ed' => null,
                ':sub' => 900000, ':disc' => 0, ':ext' => 0, ':dh' => 0, ':dr' => 0, ':st' => 'completed',
            ]);
            $orderIds[] = (int)$pdo->lastInsertId();

            // Cancelled rental (excluded)
            $ins->execute([
                ':oc' => 'ORD-C1-' . $suffix, ':ot' => 'rental', ':cid' => $customerId, ':iid' => $itemId,
                ':sd' => '2027-08-22', ':ed' => '2027-08-24',
                ':sub' => 700000, ':disc' => 0, ':ext' => 0, ':dh' => 400000, ':dr' => 0, ':st' => 'cancelled',
            ]);
            $orderIds[] = (int)$pdo->lastInsertId();

            // Overlap checks
            $this->assertFalse($availability->isAvailable($itemId, '2027-08-11', '2027-08-13'));
            $this->assertTrue($availability->isAvailable($itemId, '2027-08-13', '2027-08-14'));
            $this->assertTrue($availability->isAvailable($itemId, '2027-08-22', '2027-08-24')); // cancelled doesn't block

            // Accounting checks
            $rep = $reports->buildReport('2027-08-01', '2027-08-31', today: '2027-08-20');
            $this->assertEquals(580000.0, $rep['kpis']['rental_revenue']);
            $this->assertEquals(900000.0, $rep['kpis']['sale_revenue']);
            $this->assertEquals(1480000.0, $rep['kpis']['net_revenue']);
            $this->assertEquals(500000.0, $rep['kpis']['deposit_held_period']);
            $this->assertEquals(2, $rep['kpis']['total_orders']);
            $this->assertEquals(1, $rep['kpis']['cancelled_orders']);
            $this->assertGreaterThanOrEqual(1, $rep['kpis']['overdue_rentals']);

            // Admin Report page & Excel export
            $adminCookie = $this->makeAdminCookie();
            $repRes = $this->app->handle(
                $this->factory->createServerRequest('GET', '/admin/retail-reports')
                    ->withQueryParams(['from' => '2027-08-01', 'to' => '2027-08-31'])
                    ->withCookieParams(['lite_admin_auth' => $adminCookie])
            );
            $this->assertStatus($repRes, 200);
            $this->assertStringContainsString('1.480.000', (string)$repRes->getBody());

            $xlsxRes = $this->app->handle(
                $this->factory->createServerRequest('GET', '/admin/retail-reports/export')
                    ->withQueryParams(['from' => '2027-08-01', 'to' => '2027-08-31'])
                    ->withCookieParams(['lite_admin_auth' => $adminCookie])
            );
            $this->assertStatus($xlsxRes, 200);
            $this->assertStringStartsWith("PK\x03\x04", (string)$xlsxRes->getBody());

            // SQL Exporter includes both security RBAC tables & RetailExtension tables
            $baseDir = dirname(__DIR__);
            $sqlBundle = (new MigrationSqlExporter())->export([
                $baseDir . '/database/migrations',
                $baseDir . '/app/Modules/RetailExtension/migrations',
            ]);
            $this->assertStringContainsString('CREATE TABLE IF NOT EXISTS security_audit_logs', $sqlBundle['sql']);
            $this->assertStringContainsString('CREATE TABLE IF NOT EXISTS retail_items', $sqlBundle['sql']);
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
        $secret = (string)(
            $config['admin']['auth_secret']
            ?? $config['security']['csrf_secret']
            ?? AdminToken::DEFAULT_INSECURE_SECRET
        );

        return AdminToken::issue($username, $secret, 3600, tokenVersion: $tokenVersion);
    }

    /**
     * @return array<string, string>
     */
    private function buildValidHoneypotPayload(object $container): array
    {
        if (!$container->has(HoneypotGuard::class)) {
            return [];
        }

        /** @var HoneypotGuard $hp */
        $hp = $container->get(HoneypotGuard::class);
        $fieldsHtml = $hp->renderFields(time() - 5);
        preg_match('/name="_hp_ts"\s+value="([^"]+)"/', $fieldsHtml, $m);

        return [
            HoneypotGuard::TRAP_FIELD => '',
            HoneypotGuard::TIME_FIELD => $m[1] ?? '',
        ];
    }
}
