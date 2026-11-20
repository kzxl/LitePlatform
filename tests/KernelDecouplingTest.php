<?php

declare(strict_types=1);

namespace Tests;

use Lite\Framework\Core\Events\EventBusInterface;
use Lite\Framework\Core\Kernel;
use Lite\Framework\Core\Plugin\ModuleManagerInterface;
use Lite\Framework\Core\Routing\RouteRegistryInterface;
use Lite\Framework\Core\Tenancy\Tenant;
use Lite\Framework\Core\Tenancy\TenantContextInterface;
use App\Modules\Inventory\Application\GetStockSummaryUseCase;
use Lite\Framework\Testing\TestCase;

/**
 * Tests verifying that the Core Kernel is 100% protocol-agnostic
 * and business use cases can run directly in pure PHP without HTTP/Slim.
 */
class KernelDecouplingTest extends TestCase
{
    public function testKernelBootsIndependentlyWithoutHttpTransport(): void
    {
        $baseDir = dirname(__DIR__);
        $kernel = new Kernel($baseDir);
        $kernel->boot();

        $this->assertTrue($kernel->isBooted());

        $container = $kernel->getContainer();
        $this->assertTrue($container->has(EventBusInterface::class));
        $this->assertTrue($container->has(TenantContextInterface::class));
        $this->assertTrue($container->has(RouteRegistryInterface::class));
        $this->assertTrue($container->has(ModuleManagerInterface::class));
    }

    public function testUseCaseExecutesInPurePhpWithoutHttpPipeline(): void
    {
        $baseDir = dirname(__DIR__);
        $kernel = new Kernel($baseDir);
        $kernel->boot();

        $tenantContext = $kernel->getTenantContext();
        $tenant = new Tenant(
            id: 101,
            code: 'VINAMILK',
            name: 'Công ty Cổ phần Sữa Việt Nam',
            subdomain: 'vinamilk'
        );
        $tenantContext->setCurrentTenant($tenant);

        /** @var GetStockSummaryUseCase $useCase */
        $useCase = $kernel->getContainer()->get(GetStockSummaryUseCase::class);
        $result = $useCase->execute(['warehouse_id' => 1]);

        $this->assertNotNull($result);
        $this->assertEquals(101, $result['tenant']['id']);
        $this->assertEquals('Công ty Cổ phần Sữa Việt Nam', $result['tenant']['name']);
        $this->assertEquals(3, $result['summary']['total_warehouses']);
        $this->assertEquals(5370, $result['summary']['total_sku']);
    }

    public function testInMemoryEventBusSignalsBetweenModules(): void
    {
        $baseDir = dirname(__DIR__);
        $kernel = new Kernel($baseDir);
        $kernel->boot();

        $eventBus = $kernel->getEventBus();

        $handledEvent = null;
        $eventBus->subscribe(\stdClass::class, function ($event) use (&$handledEvent) {
            $handledEvent = $event;
        });

        $testEvent = new \stdClass();
        $testEvent->message = 'Stock Movement Completed';
        $eventBus->dispatch($testEvent);

        $this->assertNotNull($handledEvent);
        $this->assertEquals('Stock Movement Completed', $handledEvent->message);
    }
}
