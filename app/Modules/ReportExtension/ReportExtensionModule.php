<?php

declare(strict_types=1);

namespace App\Modules\ReportExtension;

use App\Modules\OrderExtension\OrderExtensionModule;
use App\Modules\ReportExtension\Controllers\ReportController;
use App\Modules\ReportExtension\Services\RevenueReportService;
use DI\Container;
use Lite\Framework\Core\KernelInterface;
use Lite\Framework\Core\Plugin\AdminAwareModuleInterface;
use Lite\Framework\Core\Plugin\Capability;
use Lite\Framework\Core\Plugin\HardenedPluginInterface;
use Lite\Framework\Core\Plugin\PluginContext;
use Lite\Framework\Core\Plugin\PluginManifest;
use Lite\Framework\Core\Plugin\SecurityAwareModuleInterface;
use Lite\Framework\Core\Routing\RouteRegistryInterface;
use LiteAdmin\AdminDashboard;
use LiteAdmin\Metric\AdminMetric;

/**
 * Decoupled Accounting & Analytics Report Extension (`com.liteplatform.reports`).
 * Explicitly declares DAG dependency on OrderExtension; cleanly removable or replaceable.
 */
final class ReportExtensionModule implements
    HardenedPluginInterface,
    SecurityAwareModuleInterface,
    AdminAwareModuleInterface
{
    public const MODULE_ID = 'com.liteplatform.reports';

    private ?PluginManifest $cachedManifest = null;

    public function manifest(): PluginManifest
    {
        return $this->cachedManifest ??= new PluginManifest(
            id: self::MODULE_ID,
            name: 'Revenue & Analytics Extension',
            version: '1.0.0',
            description: 'Accounting-grade net revenue analytics (rental vs sale, deposit liability separation), KPI cards, SVG charts, and Excel export.',
            capabilities: [
                Capability::DB_READ,
                Capability::ADMIN_UI,
            ],
            dependencies: [
                OrderExtensionModule::MODULE_ID => '^1.0',
            ]
        );
    }

    public function getId(): string { return self::MODULE_ID; }
    public function getName(): string { return $this->manifest()->name; }
    public function getVersion(): string { return $this->manifest()->version; }
    public function getManifest(): array { return $this->manifest()->toArray(); }

    public function getPermissions(): array
    {
        return [
            'reports.view'   => 'View revenue & deposit financial reports',
            'reports.export' => 'Export financial revenue ledgers to Excel',
        ];
    }

    public function getDefaultRoleGrants(): array
    {
        return [
            'manager' => ['reports.view', 'reports.export'],
        ];
    }

    public function bootPlugin(PluginContext $context): void
    {
        $context->registerService(
            RevenueReportService::class,
            static fn(): RevenueReportService => new RevenueReportService($context->db())
        );

        $context->registerService(
            ReportController::class,
            static fn(): ReportController => new ReportController($context)
        );
    }

    public function registerServices(Container $container): void {}
    public function registerRoutes(RouteRegistryInterface $routes): void {}

    public function registerAdmin(AdminDashboard $admin, Container $container): void
    {
        $admin->registerPage(
            slug: 'reports',
            title: 'Revenue & Deposits',
            group: 'Analytics',
            getHandler: [ReportController::class, 'show'],
            permission: 'reports.view'
        );

        $admin->registerAction(
            method: 'GET',
            subPath: 'reports/export',
            handler: [ReportController::class, 'export'],
            permission: 'reports.export'
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

    public function install(KernelInterface $kernel): void {}
    public function uninstall(KernelInterface $kernel): void {}
}
