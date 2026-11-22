<?php

declare(strict_types=1);

namespace Tests;

use App\Modules\AoDaiRental\AoDaiRentalModule;
use App\Modules\AoDaiRental\Services\AvailabilityService;
use App\Modules\AoDaiRental\Services\RevenueReportService;
use Lite\Framework\Console\MigrationSqlExporter;
use Lite\Framework\Core\Plugin\ModuleManagerInterface;
use Lite\Framework\Security\AdminToken;
use Lite\Framework\Security\CsrfGuard;
use Lite\Framework\Security\HoneypotGuard;
use Lite\Framework\Seo\SitemapGenerator;
use Lite\Framework\Testing\TestCase;
use LiteAdmin\Field\FieldRegistry;
use PDO;

/**
 * End-to-end verification of Core Plugin Seams (WebModuleInterface, AdminAwareModuleInterface,
 * ViewEngine namespaces, multi-path migrations) and the self-contained AoDaiRental plugin.
 */
class AoDaiRentalModuleTest extends TestCase
{
    public function testPluginSeamsWireMigrationsViewsNavbarSitemapAndAdminResources(): void
    {
        $container = $this->app->getContainer();

        /** @var ModuleManagerInterface $mm */
        $mm = $container->get(ModuleManagerInterface::class);
        $this->assertTrue($mm->hasModule(AoDaiRentalModule::MODULE_ID));

        // 1. Navbar includes module nav link on homepage
        $homeRes = $this->app->handle($this->factory->createServerRequest('GET', '/'));
        $this->assertStatus($homeRes, 200);
        $this->assertStringContainsString('/mau-ao-dai', (string)$homeRes->getBody());

        // 2. Sitemap includes module static & dynamic URLs
        /** @var PDO $pdo */
        $pdo = $container->get(PDO::class);
        $slug = 'probe-sitemap-aodai-' . uniqid();
        $code = 'SM-' . substr(uniqid(), -6);

        try {
            $pdo->prepare("INSERT INTO ao_dais (code, name, slug, rental_price, status) VALUES (:c, :n, :s, 450000, 'available')")
                ->execute([':c' => $code, ':n' => 'Áo Dài Sitemap Probe', ':s' => $slug]);

            /** @var SitemapGenerator $sitemap */
            $sitemap = $container->get(SitemapGenerator::class);
            $sitemap->invalidateCache();
            $xml = $sitemap->renderXml(useCache: false);

            $this->assertStringContainsString('/mau-ao-dai', $xml);
            $this->assertStringContainsString('/mau-ao-dai/' . $slug, $xml);
        } finally {
            $pdo->prepare('DELETE FROM ao_dais WHERE slug = :s')->execute([':s' => $slug]);
            if ($container->has(SitemapGenerator::class)) {
                $container->get(SitemapGenerator::class)->invalidateCache();
            }
        }
    }

    public function testGalleryAndDateFieldsRenderInAdminForm(): void
    {
        $registry = new FieldRegistry();
        $galleryHtml = $registry->render(['property' => 'galleryJson', 'type' => 'gallery'], '["/uploads/a.jpg"]', ['prefix' => '/admin']);
        $this->assertStringContainsString('data-gallery-field="galleryJson"', $galleryHtml);
        $this->assertStringContainsString('data-gallery-media="galleryJson"', $galleryHtml);

        $dateHtml = $registry->render(['property' => 'rentDate', 'type' => 'date'], '2026-12-20');
        $this->assertStringContainsString('type="date"', $dateHtml);
        $this->assertStringContainsString('value="2026-12-20"', $dateHtml);

        $adminCookie = $this->makeAdminCookie();
        $res = $this->app->handle(
            $this->factory->createServerRequest('GET', '/admin/ao-dai/create')
                ->withCookieParams(['lite_admin_auth' => $adminCookie])
        );
        $this->assertStatus($res, 200);
        $html = (string)$res->getBody();
        $this->assertStringContainsString('data-gallery-field="galleryJson"', $html);
        $this->assertStringContainsString('data-slug-source="name"', $html);
        $this->assertStringContainsString('/admin/aodai-reports', $html);
    }

    public function testAvailabilityServicePreventsDateOverlapDoubleBooking(): void
    {
        $container = $this->app->getContainer();
        /** @var PDO $pdo */
        $pdo = $container->get(PDO::class);
        /** @var AvailabilityService $availability */
        $availability = $container->get(AvailabilityService::class);

        $suffix = substr(uniqid(), -6);
        $aoDaiId = 0;
        $customerId = 0;
        $orderId = 0;
        $cancelledId = 0;

        try {
            $pdo->prepare("INSERT INTO ao_dais (code, name, slug, rental_price, status) VALUES (:c, :n, :s, 500000, 'available')")
                ->execute([':c' => 'AV-' . $suffix, ':n' => 'Availability Probe', ':s' => 'avail-probe-' . $suffix]);
            $aoDaiId = (int)$pdo->lastInsertId();

            $pdo->prepare("INSERT INTO customers (name, phone) VALUES ('Probe Customer', :p)")
                ->execute([':p' => '0999' . $suffix]);
            $customerId = (int)$pdo->lastInsertId();

            $pdo->prepare("
                INSERT INTO rental_orders (order_code, customer_id, ao_dai_id, rent_date, return_date, rental_fee, status)
                VALUES (:oc, :cid, :aid, '2027-04-10', '2027-04-12', 500000, 'booked')
            ")->execute([':oc' => 'RO-AV1-' . $suffix, ':cid' => $customerId, ':aid' => $aoDaiId]);
            $orderId = (int)$pdo->lastInsertId();

            $pdo->prepare("
                INSERT INTO rental_orders (order_code, customer_id, ao_dai_id, rent_date, return_date, rental_fee, status)
                VALUES (:oc, :cid, :aid, '2027-04-20', '2027-04-22', 500000, 'cancelled')
            ")->execute([':oc' => 'RO-AV2-' . $suffix, ':cid' => $customerId, ':aid' => $aoDaiId]);
            $cancelledId = (int)$pdo->lastInsertId();

            // Overlapping ranges must be blocked
            $this->assertFalse($availability->isAvailable($aoDaiId, '2027-04-11', '2027-04-13'));
            $this->assertFalse($availability->isAvailable($aoDaiId, '2027-04-12', '2027-04-14'));
            $this->assertFalse($availability->isAvailable($aoDaiId, '2027-04-08', '2027-04-10'));

            // Excluding the same order (edit mode) must be allowed
            $this->assertTrue($availability->isAvailable($aoDaiId, '2027-04-10', '2027-04-12', excludeOrderId: $orderId));

            // Non-overlapping range & cancelled order dates must be available
            $this->assertTrue($availability->isAvailable($aoDaiId, '2027-04-13', '2027-04-15'));
            $this->assertTrue($availability->isAvailable($aoDaiId, '2027-04-20', '2027-04-22'));
        } finally {
            if ($orderId > 0) {
                $pdo->prepare('DELETE FROM rental_orders WHERE id = :id')->execute([':id' => $orderId]);
            }
            if ($cancelledId > 0) {
                $pdo->prepare('DELETE FROM rental_orders WHERE id = :id')->execute([':id' => $cancelledId]);
            }
            if ($customerId > 0) {
                $pdo->prepare('DELETE FROM customers WHERE id = :id')->execute([':id' => $customerId]);
            }
            if ($aoDaiId > 0) {
                $pdo->prepare('DELETE FROM ao_dais WHERE id = :id')->execute([':id' => $aoDaiId]);
            }
        }
    }

    public function testRevenueReportSeparatesNetRevenueFromDepositsAndExportsXlsx(): void
    {
        $container = $this->app->getContainer();
        /** @var PDO $pdo */
        $pdo = $container->get(PDO::class);
        /** @var RevenueReportService $reports */
        $reports = $container->get(RevenueReportService::class);

        $suffix = substr(uniqid(), -6);
        $aoDaiId = 0;
        $customerId = 0;
        $orderIds = [];

        try {
            $pdo->prepare("INSERT INTO ao_dais (code, name, slug, rental_price, status) VALUES (:c, :n, :s, 500000, 'available')")
                ->execute([':c' => 'RV-' . $suffix, ':n' => 'Áo Dài Revenue Test', ':s' => 'rev-probe-' . $suffix]);
            $aoDaiId = (int)$pdo->lastInsertId();

            $pdo->prepare("INSERT INTO customers (name, phone) VALUES ('Trần Thanh Hà', :p)")
                ->execute([':p' => '0988' . $suffix]);
            $customerId = (int)$pdo->lastInsertId();

            $ins = $pdo->prepare("
                INSERT INTO rental_orders (
                    order_code, customer_id, ao_dai_id, rent_date, return_date,
                    rental_fee, discount, late_fee, damage_fee, deposit_held, deposit_refunded, status
                ) VALUES (
                    :oc, :cid, :aid, :rd, :ret,
                    :fee, :disc, :late, :dmg, :dep_h, :dep_r, :st
                )
            ");

            // 1. Returned: 500k - 50k + 30k + 20k = 500k net; deposit 500k collected & 500k refunded (0 open)
            $ins->execute([
                ':oc' => 'REV-1-' . $suffix, ':cid' => $customerId, ':aid' => $aoDaiId,
                ':rd' => '2027-05-05', ':ret' => '2027-05-07',
                ':fee' => 500000, ':disc' => 50000, ':late' => 30000, ':dmg' => 20000,
                ':dep_h' => 500000, ':dep_r' => 500000, ':st' => 'returned',
            ]);
            $orderIds[] = (int)$pdo->lastInsertId();

            // 2. Picked up (overdue vs 2027-05-25): 600k net; deposit 700k held
            $ins->execute([
                ':oc' => 'REV-2-' . $suffix, ':cid' => $customerId, ':aid' => $aoDaiId,
                ':rd' => '2027-05-18', ':ret' => '2027-05-20',
                ':fee' => 600000, ':disc' => 0, ':late' => 0, ':dmg' => 0,
                ':dep_h' => 700000, ':dep_r' => 0, ':st' => 'picked_up',
            ]);
            $orderIds[] = (int)$pdo->lastInsertId();

            // 3. Booked: 400k net; deposit 400k held
            $ins->execute([
                ':oc' => 'REV-3-' . $suffix, ':cid' => $customerId, ':aid' => $aoDaiId,
                ':rd' => '2027-05-28', ':ret' => '2027-05-30',
                ':fee' => 400000, ':disc' => 0, ':late' => 0, ':dmg' => 0,
                ':dep_h' => 400000, ':dep_r' => 0, ':st' => 'booked',
            ]);
            $orderIds[] = (int)$pdo->lastInsertId();

            // 4. Cancelled: MUST be excluded from net revenue & deposit totals
            $ins->execute([
                ':oc' => 'REV-4-' . $suffix, ':cid' => $customerId, ':aid' => $aoDaiId,
                ':rd' => '2027-05-15', ':ret' => '2027-05-16',
                ':fee' => 900000, ':disc' => 0, ':late' => 0, ':dmg' => 0,
                ':dep_h' => 500000, ':dep_r' => 0, ':st' => 'cancelled',
            ]);
            $orderIds[] = (int)$pdo->lastInsertId();

            $report = $reports->buildReport('2027-05-01', '2027-05-31', today: '2027-05-25');
            $kpis = $report['kpis'];

            $this->assertEquals(1500000.0, $kpis['net_revenue']);
            $this->assertEquals(1500000.0, $kpis['gross_rental_fee']);
            $this->assertEquals(50000.0, $kpis['total_discount']);
            $this->assertEquals(50000.0, $kpis['total_extra_fees']);
            $this->assertEquals(1600000.0, $kpis['deposit_held_period']);
            $this->assertEquals(500000.0, $kpis['deposit_refunded_period']);
            $this->assertEquals(3, $kpis['total_orders']);
            $this->assertEquals(1, $kpis['cancelled_orders']);
            $this->assertGreaterThanOrEqual(1, $kpis['overdue_orders']);
            $this->assertNotEmpty($report['top_garments']);
            $this->assertNotEmpty($report['top_customers']);

            // Verify Admin Report HTML page
            $adminCookie = $this->makeAdminCookie();
            $pageRes = $this->app->handle(
                $this->factory->createServerRequest('GET', '/admin/aodai-reports')
                    ->withQueryParams(['from' => '2027-05-01', 'to' => '2027-05-31'])
                    ->withCookieParams(['lite_admin_auth' => $adminCookie])
            );
            $this->assertStatus($pageRes, 200);
            $this->assertStringContainsString('1.500.000', (string)$pageRes->getBody());
            $this->assertStringContainsString('RV-' . $suffix, (string)$pageRes->getBody());

            // Verify Excel (.xlsx) Export
            $exportRes = $this->app->handle(
                $this->factory->createServerRequest('GET', '/admin/aodai-reports/export')
                    ->withQueryParams(['from' => '2027-05-01', 'to' => '2027-05-31'])
                    ->withCookieParams(['lite_admin_auth' => $adminCookie])
            );
            $this->assertStatus($exportRes, 200);
            $this->assertStringContainsString(
                'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                $exportRes->getHeaderLine('Content-Type')
            );
            $this->assertStringStartsWith("PK\x03\x04", (string)$exportRes->getBody());
        } finally {
            foreach ($orderIds as $oid) {
                $pdo->prepare('DELETE FROM rental_orders WHERE id = :id')->execute([':id' => $oid]);
            }
            if ($customerId > 0) {
                $pdo->prepare('DELETE FROM customers WHERE id = :id')->execute([':id' => $customerId]);
            }
            if ($aoDaiId > 0) {
                $pdo->prepare('DELETE FROM ao_dais WHERE id = :id')->execute([':id' => $aoDaiId]);
            }
        }
    }

    public function testPublicShowcaseDetailAndFastInquiryUpsertCustomerAndLead(): void
    {
        $container = $this->app->getContainer();
        /** @var PDO $pdo */
        $pdo = $container->get(PDO::class);

        $suffix = substr(uniqid(), -6);
        $slug = 'ao-dai-lua-sen-' . $suffix;
        $code = 'AD-' . $suffix;
        $phone = '0977' . random_int(100000, 999999);

        try {
            $pdo->prepare("
                INSERT INTO ao_dais (code, name, slug, rental_price, deposit_default, color, sizes, material, description, status)
                VALUES (:c, :n, :s, 550000, 500000, 'Đỏ đô', 'S, M, L', 'Lụa tơ tằm', :desc, 'available')
            ")->execute([
                ':c'    => $code,
                ':n'    => 'Áo Dài Lụa Sen ' . $suffix,
                ':s'    => $slug,
                ':desc' => '<p>Thêu tay tinh xảo</p><script>alert("xss")</script>',
            ]);

            // 1. Catalog listing with search filter
            $listRes = $this->app->handle(
                $this->factory->createServerRequest('GET', '/mau-ao-dai')
                    ->withQueryParams(['q' => $code])
            );
            $this->assertStatus($listRes, 200);
            $listHtml = (string)$listRes->getBody();
            $this->assertStringContainsString($code, $listHtml);
            $this->assertStringContainsString('550.000', $listHtml);

            // 2. Detail page renders Product Schema + sanitized HTML (no raw <script>)
            $detailRes = $this->app->handle(
                $this->factory->createServerRequest('GET', '/mau-ao-dai/' . $slug)
            );
            $this->assertStatus($detailRes, 200);
            $detailHtml = (string)$detailRes->getBody();
            $this->assertStringContainsString('"@type":"Product"', $detailHtml);
            $this->assertStringContainsString('<p>Thêu tay tinh xảo</p>', $detailHtml);
            $this->assertStringNotContainsString('<script>alert("xss")</script>', $detailHtml);

            // 3. Submit fast booking inquiry with valid CSRF + Honeypot
            preg_match('/name="_csrf"\s+value="([^"]+)"/', $detailHtml, $csrfM);
            $csrf = $csrfM[1] ?? '';
            $this->assertNotEmpty($csrf);

            $hpPayload = $this->buildValidHoneypotPayload($container);
            $postRes = $this->app->handle(
                $this->factory->createServerRequest('POST', '/mau-ao-dai/inquiry')
                    ->withCookieParams([CsrfGuard::COOKIE_NAME => $csrf])
                    ->withParsedBody(array_merge($hpPayload, [
                        '_csrf'          => $csrf,
                        'return_slug'    => $slug,
                        'selected_codes' => $code,
                        'name'           => 'Lê Minh Thư',
                        'phone'          => $phone,
                        'wear_date'      => '2027-06-15',
                        'notes'          => 'Size M, thử sáng thứ 7',
                    ]))
            );
            $this->assertStatus($postRes, 200);
            $this->assertStringContainsString('Đã gửi yêu cầu thành công', (string)$postRes->getBody());

            // Verify Customer upserted & ContactLead saved
            $custStmt = $pdo->prepare('SELECT name FROM customers WHERE phone = :p');
            $custStmt->execute([':p' => $phone]);
            $this->assertEquals('Lê Minh Thư', $custStmt->fetchColumn());

            $leadStmt = $pdo->prepare('SELECT message FROM contact_leads WHERE phone = :p');
            $leadStmt->execute([':p' => $phone]);
            $leadMsg = (string)$leadStmt->fetchColumn();
            $this->assertStringContainsString($code, $leadMsg);
        } finally {
            $pdo->prepare('DELETE FROM contact_leads WHERE phone = :p')->execute([':p' => $phone]);
            $pdo->prepare('DELETE FROM customers WHERE phone = :p')->execute([':p' => $phone]);
            $pdo->prepare('DELETE FROM ao_dais WHERE slug = :s')->execute([':s' => $slug]);
        }
    }

    public function testMigrationSqlExporterIncludesPluginModuleMigrations(): void
    {
        $baseDir = dirname(__DIR__);
        $exporter = new MigrationSqlExporter();
        $result = $exporter->export([
            $baseDir . '/database/migrations',
            $baseDir . '/app/Modules/AoDaiRental/migrations',
        ]);

        $this->assertStringContainsString('CREATE TABLE IF NOT EXISTS ao_dais', $result['sql']);
        $this->assertStringContainsString('CREATE TABLE IF NOT EXISTS customers', $result['sql']);
        $this->assertStringContainsString('CREATE TABLE IF NOT EXISTS rental_orders', $result['sql']);
        $this->assertContains('2026_11_22_000001_create_aodai_rental_tables', $result['migrations']);
    }

    private function makeAdminCookie(): string
    {
        $config = (array)$this->app->getContainer()->get('config');
        $secret = (string)(
            $config['admin']['auth_secret']
            ?? $config['security']['csrf_secret']
            ?? AdminToken::DEFAULT_INSECURE_SECRET
        );

        return AdminToken::issue('admin', $secret, 3600);
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
