<?php

declare(strict_types=1);

namespace Tests;

use App\Core\Plugin\ModuleManagerInterface;
use App\Core\Tenancy\Tenant;
use App\Core\Tenancy\TenantContextInterface;
use App\Modules\Inventory\InventoryModule;
use App\Testing\TestCase;

/**
 * Tests verifying the Dynamic Plugin / Feature Module System,
 * Route mounting, Manifest discovery, and Tenant-level Module Guard.
 */
class ModuleSystemTest extends TestCase
{
    private ModuleManagerInterface $moduleManager;
    private TenantContextInterface $tenantContext;

    public function setUp(): void
    {
        parent::setUp();
        $container = $this->app->getContainer();
        $this->moduleManager = $container->get(ModuleManagerInterface::class);
        $this->tenantContext = $container->get(TenantContextInterface::class);

        // Register a test tenant
        $this->tenantContext->registerTenant(new Tenant(
            id: 999,
            code: 'TENANT_DISABLED_TEST',
            name: 'Doanh nghiệp Thử Nghiệm Bật Tắt Module',
            subdomain: 'testcorp'
        ));
    }

    public function tearDown(): void
    {
        $this->tenantContext->setCurrentTenant(null);
        // Reset entitlement for tenant 999
        $this->moduleManager->enableForTenant(999, InventoryModule::MODULE_ID);
        parent::tearDown();
    }

    public function testDynamicModuleRouteRegisteredAndDispatched(): void
    {
        $res = $this->get('/api/inventory/summary');

        $this->assertStatus($res, 200);
        $body = (string)$res->getBody();
        $json = json_decode($body, true);

        $this->assertEquals('success', $json['status']);
        $this->assertEquals(3, $json['data']['summary']['total_warehouses']);
        $this->assertEquals(5370, $json['data']['summary']['total_sku']);
    }

    public function testModuleManifestAccessibleViaModuleManager(): void
    {
        $this->assertTrue($this->moduleManager->hasModule(InventoryModule::MODULE_ID));

        $module = $this->moduleManager->getModule(InventoryModule::MODULE_ID);
        $this->assertNotNull($module);

        $manifest = $module->getManifest();
        $this->assertEquals('com.kzxl.inventory', $manifest['id']);
        $this->assertEquals('DatabaseOutlined', $manifest['icon']);
        $this->assertEquals('Tổng quan Kho', $manifest['adminMenu'][0]['label']);
    }

    public function testModuleGuardReturns403WhenDisabledForTenant(): void
    {
        // Explicitly disable Inventory module for tenant 999
        $this->moduleManager->disableForTenant(999, InventoryModule::MODULE_ID);

        // Request with tenant 999
        $res = $this->get('/api/inventory/summary', [
            'X-Tenant-ID' => '999',
        ]);

        // Expect 403 Forbidden with Problem Details
        $this->assertStatus($res, 403);
        $body = (string)$res->getBody();
        $json = json_decode($body, true);

        $this->assertEquals(403, $json['status']);
        $this->assertEquals('Module Access Denied', $json['title']);
    }

    public function testModuleReenabledReturns200ForTenant(): void
    {
        // Enable module for tenant 999
        $this->moduleManager->enableForTenant(999, InventoryModule::MODULE_ID);

        $res = $this->get('/api/inventory/summary', [
            'X-Tenant-ID' => '999',
        ]);

        $this->assertStatus($res, 200);
        $body = (string)$res->getBody();
        $json = json_decode($body, true);
        $this->assertEquals('success', $json['status']);
    }
}
