<?php

declare(strict_types=1);

namespace App\Modules\AoDaiRental;

use App\Modules\AoDaiRental\Controllers\RentalReportController;
use App\Modules\AoDaiRental\Controllers\ShowcaseController;
use App\Modules\AoDaiRental\Entities\AoDai;
use App\Modules\AoDaiRental\Entities\Customer;
use App\Modules\AoDaiRental\Entities\RentalOrder;
use App\Modules\AoDaiRental\Services\AvailabilityService;
use App\Modules\AoDaiRental\Services\RevenueReportService;
use DI\Container;
use Lite\Framework\Core\KernelInterface;
use Lite\Framework\Core\Plugin\AdminAwareModuleInterface;
use Lite\Framework\Core\Plugin\IPluginModule;
use Lite\Framework\Core\Plugin\WebModuleInterface;
use Lite\Framework\Core\Routing\RouteRegistryInterface;
use Lite\Framework\Middleware\RateLimitMiddleware;
use Lite\Framework\Seo\SitemapGenerator;
use LiteAdmin\AdminDashboard;
use LiteAdmin\Metric\AdminMetric;
use LiteCache\CacheInterface;
use PDO;

/**
 * Self-contained Ao Dai Rental Plugin Module.
 *
 * Plugs cleanly into LitePlatform Core via IPluginModule + WebModuleInterface + AdminAwareModuleInterface.
 * Removing this class from config/app.php['modules'] cleanly disables the entire feature with zero core impact.
 */
final class AoDaiRentalModule implements IPluginModule, WebModuleInterface, AdminAwareModuleInterface
{
    public const MODULE_ID = 'com.liteplatform.aodai-rental';

    public function getId(): string
    {
        return self::MODULE_ID;
    }

    public function getName(): string
    {
        return 'Ao Dai Rental Management';
    }

    public function getVersion(): string
    {
        return '1.0.0';
    }

    public function getManifest(): array
    {
        return [
            'id'           => $this->getId(),
            'name'         => $this->getName(),
            'version'      => $this->getVersion(),
            'description'  => 'Ao Dai catalog showcase, multi-design selection & quick inquiry, customer CRM, rental orders with overlap guard, and accurate accounting revenue reports.',
            'author'       => 'LitePlatform Plugins',
            'icon'         => 'SparklesOutlined',
            'dependencies' => [],
        ];
    }

    public function getMigrationsPath(): ?string
    {
        return __DIR__ . '/migrations';
    }

    public function getViewNamespace(): ?string
    {
        return 'aodai';
    }

    public function getViewsPath(): ?string
    {
        return __DIR__ . '/templates';
    }

    public function getNavLinks(): array
    {
        return [
            ['label' => 'Mẫu Áo Dài', 'href' => '/mau-ao-dai'],
        ];
    }

    public function registerSitemap(SitemapGenerator $sitemap, Container $container): void
    {
        $sitemap->addUrl('/mau-ao-dai', changefreq: 'daily', priority: '0.9');

        if ($container->has(PDO::class)) {
            $sitemap->addProvider(static function () use ($container): array {
                /** @var PDO $pdo */
                $pdo = $container->get(PDO::class);
                try {
                    $stmt = $pdo->query("SELECT slug, updated_at, created_at FROM ao_dais WHERE status = 'available' ORDER BY id DESC");
                    $rows = $stmt ? ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
                } catch (\Throwable) {
                    return [];
                }

                $entries = [];
                foreach ($rows as $row) {
                    $slug = trim((string)($row['slug'] ?? ''));
                    if ($slug === '') {
                        continue;
                    }
                    $entries[] = [
                        'loc'        => '/mau-ao-dai/' . rawurlencode($slug),
                        'priority'   => '0.8',
                        'changefreq' => 'weekly',
                        'lastmod'    => substr((string)($row['updated_at'] ?: $row['created_at'] ?: date('Y-m-d')), 0, 10),
                    ];
                }
                return $entries;
            });
        }
    }

    public function registerServices(Container $container): void
    {
        if ($container->has(PDO::class)) {
            $container->set(AvailabilityService::class, static fn(Container $c): AvailabilityService => new AvailabilityService($c->get(PDO::class)));
            $container->set(RevenueReportService::class, static fn(Container $c): RevenueReportService => new RevenueReportService($c->get(PDO::class)));
        }

        $container->set(ShowcaseController::class, static fn(Container $c): ShowcaseController => new ShowcaseController($c));
        $container->set(RentalReportController::class, static fn(Container $c): RentalReportController => new RentalReportController($c));

        if ($container->has(CacheInterface::class)) {
            $container->set('aodai.inquiry.ratelimit', static fn(Container $c): RateLimitMiddleware => new RateLimitMiddleware(
                cache: $c->get(CacheInterface::class),
                maxRequests: 15,
                decaySeconds: 60,
                bucket: 'aodai_inquiry'
            ));
        } else {
            $container->set('aodai.inquiry.ratelimit', static fn(): callable => static fn($req, $handler) => $handler->handle($req));
        }
    }

    public function registerRoutes(RouteRegistryInterface $routes): void
    {
        $routes->get(
            '/mau-ao-dai',
            [ShowcaseController::class, 'index'],
            name: 'aodai.index',
            moduleId: $this->getId()
        );

        $routes->post(
            '/mau-ao-dai/inquiry',
            [ShowcaseController::class, 'submitInquiry'],
            middlewares: ['aodai.inquiry.ratelimit'],
            name: 'aodai.inquiry',
            moduleId: $this->getId()
        );

        $routes->get(
            '/mau-ao-dai/{slug}',
            [ShowcaseController::class, 'show'],
            name: 'aodai.show',
            moduleId: $this->getId()
        );
    }

    public function registerAdmin(AdminDashboard $admin, Container $container): void
    {
        $admin->register(AoDai::class);
        $admin->register(Customer::class);
        $admin->register(RentalOrder::class);

        $admin->registerPage(
            slug: 'aodai-reports',
            title: 'Revenue & Deposits',
            group: 'Ao Dai Rental',
            getHandler: [RentalReportController::class, 'show']
        );

        $admin->registerAction(
            method: 'GET',
            subPath: 'aodai-reports/export',
            handler: [RentalReportController::class, 'export']
        );

        if ($container->has(RevenueReportService::class)) {
            /** @var RevenueReportService $reports */
            $reports = $container->get(RevenueReportService::class);
            try {
                $summary = $reports->buildReport(date('Y-m-01'), date('Y-m-t'));
                $netRev = number_format($summary['kpis']['net_revenue'], 0, ',', '.') . ' ₫';
                $depHeld = number_format($summary['kpis']['deposit_currently_held'], 0, ',', '.') . ' ₫';
            } catch (\Throwable) {
                $netRev = '0 ₫';
                $depHeld = '0 ₫';
            }

            $admin->addMetric(new AdminMetric(
                label: 'Ao Dai Revenue (Month)',
                value: $netRev,
                description: 'Net rental income (excl. refundable deposits)'
            ));

            $admin->addMetric(new AdminMetric(
                label: 'Deposits Held',
                value: $depHeld,
                description: 'Refundable customer security deposits'
            ));
        }
    }

    public function install(KernelInterface $kernel): void
    {
        // Migrations are auto-registered via WebModuleInterface::getMigrationsPath()
    }

    public function uninstall(KernelInterface $kernel): void
    {
        // Non-destructive uninstall: preserves rental accounting records
    }
}
