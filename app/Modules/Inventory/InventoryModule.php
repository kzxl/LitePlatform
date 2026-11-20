<?php

declare(strict_types=1);

namespace App\Modules\Inventory;

use Lite\Framework\Core\KernelInterface;
use Lite\Framework\Core\Plugin\IPluginModule;
use Lite\Framework\Core\Routing\RouteRegistryInterface;
use App\Modules\Inventory\Application\GetStockSummaryUseCase;
use App\Modules\Inventory\Http\InventoryController;
use DI\Container;

/**
 * Autonomous Inventory Feature Module conforming to Universe Architecture v4.0.
 */
class InventoryModule implements IPluginModule
{
    public const MODULE_ID = 'com.kzxl.inventory';

    public function getId(): string
    {
        return self::MODULE_ID;
    }

    public function getName(): string
    {
        return 'Quản lý Kho Chuyên Sâu (Advanced Inventory)';
    }

    public function getVersion(): string
    {
        return '1.0.0';
    }

    public function getManifest(): array
    {
        return [
            'id' => $this->getId(),
            'name' => $this->getName(),
            'version' => $this->getVersion(),
            'description' => 'Quản lý xuất nhập tồn, kho đa chi nhánh, cảnh báo hạn mức, tích hợp đa công ty',
            'author' => 'LitePlatform Enterprise Team',
            'icon' => 'DatabaseOutlined',
            'dependencies' => [],
            'adminMenu' => [
                [
                    'label' => 'Tổng quan Kho',
                    'path' => '/inventory/summary',
                    'icon' => 'DatabaseOutlined',
                ],
            ],
        ];
    }

    public function registerServices(Container $container): void
    {
        $container->set(GetStockSummaryUseCase::class, function (Container $c) {
            return new GetStockSummaryUseCase(
                $c->get(\Lite\Framework\Core\Tenancy\TenantContextInterface::class),
                $c->has(\Lite\Framework\Core\Events\EventBusInterface::class) ? $c->get(\Lite\Framework\Core\Events\EventBusInterface::class) : null
            );
        });

        $container->set(InventoryController::class, function (Container $c) {
            return new InventoryController($c->get(GetStockSummaryUseCase::class));
        });
    }

    public function registerRoutes(RouteRegistryInterface $routes): void
    {
        $routes->get(
            '/api/inventory/summary',
            [InventoryController::class, 'summary'],
            name: 'inventory.summary',
            moduleId: $this->getId()
        );
    }

    public function install(KernelInterface $kernel): void
    {
        // Execute module migrations if applicable
    }

    public function uninstall(KernelInterface $kernel): void
    {
        // Clean up module resources
    }
}
