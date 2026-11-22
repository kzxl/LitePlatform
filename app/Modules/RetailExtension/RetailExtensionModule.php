<?php

declare(strict_types=1);

namespace App\Modules\RetailExtension;

use App\Modules\RetailExtension\Controllers\CatalogController;
use App\Modules\RetailExtension\Controllers\RetailReportController;
use App\Modules\RetailExtension\Entities\CatalogItem;
use App\Modules\RetailExtension\Entities\RetailCustomer;
use App\Modules\RetailExtension\Entities\RetailOrder;
use App\Modules\RetailExtension\Services\AvailabilityService;
use App\Modules\RetailExtension\Services\RevenueReportService;
use DI\Container;
use Lite\Framework\Core\KernelInterface;
use Lite\Framework\Core\Plugin\AdminAwareModuleInterface;
use Lite\Framework\Core\Plugin\Capability;
use Lite\Framework\Core\Plugin\HardenedPluginInterface;
use Lite\Framework\Core\Plugin\PluginContext;
use Lite\Framework\Core\Plugin\PluginManifest;
use Lite\Framework\Core\Plugin\SecurityAwareModuleInterface;
use Lite\Framework\Core\Plugin\WebModuleInterface;
use Lite\Framework\Core\Routing\RouteRegistryInterface;
use Lite\Framework\Middleware\RateLimitMiddleware;
use Lite\Framework\Seo\SitemapGenerator;
use LiteAdmin\AdminDashboard;
use LiteAdmin\Metric\AdminMetric;
use LiteCache\CacheInterface;
use PDO;

/**
 * Universal Retail & Rental Extension conforming to LES v1 Hardened Plugin Standard.
 *
 * Domain-agnostic by design: supports any retail or rental business (apparel, formal wear,
 * electronics, cameras, furniture, event props) through dynamic item attributes, unified
 * rent/sale order accounting, overlap prevention, and granular RBAC permissions.
 */
final class RetailExtensionModule implements
    HardenedPluginInterface,
    SecurityAwareModuleInterface,
    WebModuleInterface,
    AdminAwareModuleInterface
{
    public const MODULE_ID = 'com.liteplatform.retail-extension';

    private ?PluginManifest $cachedManifest = null;
    private ?PluginContext $context = null;

    public function manifest(): PluginManifest
    {
        return $this->cachedManifest ??= new PluginManifest(
            id: self::MODULE_ID,
            name: 'Retail & Rental Extension',
            version: '1.0.0',
            description: 'Universal retail & rental catalog, dynamic item attributes, CRM, date-overlap availability guard, and accounting revenue/deposit reports.',
            author: 'LitePlatform Plugins',
            coreConstraint: '^4.0',
            capabilities: [
                Capability::DB_READ,
                Capability::DB_WRITE,
                Capability::CACHE_SCOPED,
                Capability::MAIL_SEND,
                Capability::ADMIN_UI,
                Capability::PUBLIC_ROUTES,
                Capability::HOOKS_SUBSCRIBE,
                Capability::SECRETS_SCOPED,
                Capability::FILES_UPLOAD,
            ],
            routePrefixes: ['/catalog'],
            tablePrefixes: ['retail_']
        );
    }

    public function getId(): string
    {
        return self::MODULE_ID;
    }

    public function getName(): string
    {
        return $this->manifest()->name;
    }

    public function getVersion(): string
    {
        return $this->manifest()->version;
    }

    public function getManifest(): array
    {
        return $this->manifest()->toArray();
    }

    public function getPermissions(): array
    {
        return [
            'retail.catalog.read'   => 'View retail & rental catalog items',
            'retail.catalog.write'  => 'Create, edit, and delete catalog items',
            'retail.customers.read' => 'View customer profiles and history',
            'retail.customers.write'=> 'Create and edit customer records',
            'retail.orders.read'    => 'View retail & rental orders',
            'retail.orders.write'   => 'Create and update orders & deposits',
            'retail.reports.view'   => 'View revenue & deposit financial reports',
            'retail.reports.export' => 'Export financial revenue ledgers to Excel',
        ];
    }

    public function getDefaultRoleGrants(): array
    {
        return [
            'manager' => array_keys($this->getPermissions()),
            'staff'   => [
                'retail.catalog.read',
                'retail.customers.read',
                'retail.customers.write',
                'retail.orders.read',
                'retail.orders.write',
            ],
            'viewer'  => [
                'retail.catalog.read',
                'retail.orders.read',
            ],
        ];
    }

    public function getMigrationsPath(): ?string
    {
        return __DIR__ . '/migrations';
    }

    public function getViewNamespace(): ?string
    {
        return 'retail';
    }

    public function getViewsPath(): ?string
    {
        return __DIR__ . '/templates';
    }

    public function getNavLinks(): array
    {
        $label = 'Catalog';
        if ($this->context !== null) {
            try {
                $label = $this->context->getSetting('nav_label', 'Catalog');
            } catch (\Throwable) {
            }
        }
        return [
            ['label' => $label, 'href' => '/catalog'],
        ];
    }

    public function registerSitemap(SitemapGenerator $sitemap, Container $container): void
    {
        $sitemap->addUrl('/catalog', changefreq: 'daily', priority: '0.9');

        if ($container->has(PDO::class)) {
            $sitemap->addProvider(static function () use ($container): array {
                /** @var PDO $pdo */
                $pdo = $container->get(PDO::class);
                try {
                    $stmt = $pdo->query("SELECT slug, updated_at, created_at FROM retail_items WHERE status = 'active' ORDER BY id DESC");
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
                        'loc'        => '/catalog/' . rawurlencode($slug),
                        'priority'   => '0.8',
                        'changefreq' => 'weekly',
                        'lastmod'    => substr((string)($row['updated_at'] ?: $row['created_at'] ?: date('Y-m-d')), 0, 10),
                    ];
                }
                return $entries;
            });
        }
    }

    public function bootPlugin(PluginContext $context): void
    {
        $this->context = $context;

        $context->registerService(
            AvailabilityService::class,
            static fn(): AvailabilityService => new AvailabilityService($context->db())
        );

        $context->registerService(
            RevenueReportService::class,
            static fn(): RevenueReportService => new RevenueReportService($context->db())
        );

        $context->registerService(
            CatalogController::class,
            static fn(): CatalogController => new CatalogController($context)
        );

        $context->registerService(
            RetailReportController::class,
            static fn(): RetailReportController => new RetailReportController($context)
        );

        if ($context->hasService(CacheInterface::class)) {
            $context->registerService('retail.inquiry.ratelimit', static fn(): RateLimitMiddleware => new RateLimitMiddleware(
                cache: $context->getService(CacheInterface::class),
                maxRequests: 15,
                decaySeconds: 60,
                bucket: 'retail_inquiry'
            ));
        } else {
            $context->registerService('retail.inquiry.ratelimit', static fn(): callable => static fn($req, $handler) => $handler->handle($req));
        }
    }

    public function registerServices(Container $container): void
    {
        // Unused for HardenedPluginInterface (ModuleManager invokes bootPlugin(PluginContext) instead).
    }

    public function registerRoutes(RouteRegistryInterface $routes): void
    {
        $routes->get(
            '/catalog',
            [CatalogController::class, 'index'],
            name: 'retail.catalog.index',
            moduleId: $this->getId()
        );

        $routes->post(
            '/catalog/inquiry',
            [CatalogController::class, 'submitInquiry'],
            middlewares: ['retail.inquiry.ratelimit'],
            name: 'retail.catalog.inquiry',
            moduleId: $this->getId()
        );

        $routes->get(
            '/catalog/{slug}',
            [CatalogController::class, 'show'],
            name: 'retail.catalog.show',
            moduleId: $this->getId()
        );
    }

    public function registerAdmin(AdminDashboard $admin, Container $container): void
    {
        $admin->register(CatalogItem::class);
        $admin->register(RetailCustomer::class);
        $admin->register(RetailOrder::class);

        $admin->registerPage(
            slug: 'retail-reports',
            title: 'Revenue & Deposits',
            group: 'Retail & Rental',
            getHandler: [RetailReportController::class, 'show'],
            permission: 'retail.reports.view'
        );

        $admin->registerAction(
            method: 'GET',
            subPath: 'retail-reports/export',
            handler: [RetailReportController::class, 'export'],
            permission: 'retail.reports.export'
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
                label: 'Net Revenue (Month)',
                value: $netRev,
                description: 'Rental + Sale income (excl. refundable deposits)'
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
    }

    public function uninstall(KernelInterface $kernel): void
    {
    }
}
