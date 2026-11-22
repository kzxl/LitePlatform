<?php

declare(strict_types=1);

namespace App\Modules\OrderExtension;

use App\Modules\CatalogExtension\CatalogExtensionModule;
use App\Modules\CrmExtension\CrmExtensionModule;
use App\Modules\OrderExtension\Controllers\OrderReceiptController;
use App\Modules\OrderExtension\Entities\Order;
use App\Modules\OrderExtension\Services\AvailabilityService;
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
use Lite\Framework\Seo\SitemapGenerator;
use LiteAdmin\AdminDashboard;

/**
 * Composable Orders & Rentals Extension (`com.liteplatform.orders`).
 * Explicitly declares DAG dependencies on CatalogExtension and CrmExtension.
 */
final class OrderExtensionModule implements
    HardenedPluginInterface,
    SecurityAwareModuleInterface,
    WebModuleInterface,
    AdminAwareModuleInterface
{
    public const MODULE_ID = 'com.liteplatform.orders';

    private ?PluginManifest $cachedManifest = null;

    public function manifest(): PluginManifest
    {
        return $this->cachedManifest ??= new PluginManifest(
            id: self::MODULE_ID,
            name: 'Orders & Rentals Extension',
            version: '1.0.0',
            description: 'Unified retail & rental order management with date-overlap availability guard and refundable deposit tracking.',
            capabilities: [
                Capability::DB_READ,
                Capability::DB_WRITE,
                Capability::CACHE_SCOPED,
                Capability::MAIL_SEND,
                Capability::ADMIN_UI,
                Capability::HOOKS_SUBSCRIBE,
            ],
            tablePrefixes: ['retail_orders', 'retail_order_items'],
            dependencies: [
                CatalogExtensionModule::MODULE_ID => '^1.0',
                CrmExtensionModule::MODULE_ID     => '^1.0',
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
            'orders.read'  => 'View retail & rental orders',
            'orders.write' => 'Create and manage orders & deposits',
        ];
    }

    public function getDefaultRoleGrants(): array
    {
        return [
            'manager' => ['orders.read', 'orders.write'],
            'staff'   => ['orders.read', 'orders.write'],
            'viewer'  => ['orders.read'],
        ];
    }

    public function getMigrationsPath(): ?string { return __DIR__ . '/migrations'; }
    public function getViewNamespace(): ?string { return null; }
    public function getViewsPath(): ?string { return null; }
    public function getNavLinks(): array { return []; }
    public function registerSitemap(SitemapGenerator $sitemap, Container $container): void {}

    public function bootPlugin(PluginContext $context): void
    {
        $context->registerService(
            AvailabilityService::class,
            static fn(): AvailabilityService => new AvailabilityService($context->db())
        );

        $context->registerService(
            OrderReceiptController::class,
            static fn(): OrderReceiptController => new OrderReceiptController($context)
        );

        // Daily overdue/due-today rental reminder dispatched via Core Web Cron (`cron.tick`)
        $context->addAction('cron.tick', static function (array $payload) use ($context): void {
            $today = (string)($payload['today'] ?? date('Y-m-d'));
            $dedupKey = 'rental_reminder_' . $today;
            if ($context->cacheGet($dedupKey, false) === true) {
                return;
            }

            try {
                $stmt = $context->db()->prepare("
                    SELECT COUNT(*) FROM retail_orders
                    WHERE order_type = 'rental' AND status = 'active' AND end_date IS NOT NULL AND end_date <= :today
                ");
                $stmt->execute([':today' => $today]);
                $dueCount = (int)$stmt->fetchColumn();

                $context->cacheSet($dedupKey, true, 86400);
                if ($dueCount > 0 && $context->hasService(\Lite\Framework\Mail\TelegramNotifier::class)) {
                    $tg = $context->getService(\Lite\Framework\Mail\TelegramNotifier::class);
                    if ($tg->isConfigured()) {
                        $tg->send("<b>[Nhắc lịch trả đồ]</b> Có <b>{$dueCount}</b> đơn thuê đến hạn hoặc quá hạn trả hôm nay ({$today}).");
                    }
                }
            } catch (\Throwable) {
            }
        });
    }

    public function registerServices(Container $container): void {}
    public function registerRoutes(RouteRegistryInterface $routes): void {}

    public function registerAdmin(AdminDashboard $admin, Container $container): void
    {
        $admin->register(Order::class);

        $admin->registerAction(
            method: 'GET',
            subPath: 'order/receipt/{id}',
            handler: [OrderReceiptController::class, 'show'],
            permission: 'orders.read'
        );

        $admin->registerRowAction(
            entityClass: Order::class,
            label: 'In phiếu',
            urlTemplate: '/admin/order/receipt/{id}',
            newTab: true
        );

        // Enforce rental overlap guard & accounting invariants directly on Admin Create/Update forms
        if ($container->has(AvailabilityService::class)) {
            /** @var AvailabilityService $availability */
            $availability = $container->get(AvailabilityService::class);
            $admin->beforeSave(Order::class, static function (array $data, ?object $existing) use ($availability): array {
                $errors = [];
                $type = strtolower(trim((string)($data['orderType'] ?? 'rental')));
                $status = strtolower(trim((string)($data['status'] ?? 'confirmed')));
                $startDate = trim((string)($data['startDate'] ?? ''));
                $endDate = trim((string)($data['endDate'] ?? ''));
                $itemId = (int)($data['itemId'] ?? 0);
                $depHeld = (float)($data['depositHeld'] ?? 0);
                $depRef = (float)($data['depositRefunded'] ?? 0);

                if ($depRef > $depHeld && $depHeld >= 0) {
                    $errors['depositRefunded'] = 'Refunded deposit cannot exceed collected deposit.';
                }

                if ($type === 'rental') {
                    if ($endDate === '') {
                        $errors['endDate'] = 'Expected return date is required for rental orders.';
                    } elseif ($startDate !== '' && $endDate < $startDate) {
                        $errors['endDate'] = 'Return date must be on or after the rental start date.';
                    } elseif ($itemId > 0 && $startDate !== '' && in_array($status, ['confirmed', 'active'], true)) {
                        $excludeId = ($existing instanceof Order) ? $existing->id : null;
                        $conflicts = $availability->findConflicts($itemId, $startDate, $endDate, $excludeId);
                        if (!empty($conflicts)) {
                            $codes = implode(', ', array_map(static fn(array $c): string => (string)$c['order_code'], $conflicts));
                            $errors['startDate'] = "Item is already reserved on overlapping dates (conflict: {$codes}).";
                        }
                    }
                }

                return $errors;
            });
        }
    }

    public function install(KernelInterface $kernel): void {}
    public function uninstall(KernelInterface $kernel): void {}
}
