<?php

declare(strict_types=1);

namespace Tests;

use App\Core\AppBootstrap;
use App\Core\Tenancy\Tenant;
use App\Core\Tenancy\TenantContextInterface;
use App\Testing\TestCase;

/**
 * Tests verifying multi-tenant resolution and context propagation.
 */
class MultiTenancyTest extends TestCase
{
    private TenantContextInterface $tenantContext;

    public function setUp(): void
    {
        parent::setUp();
        $this->tenantContext = $this->app->getContainer()->get(TenantContextInterface::class);

        // Register sample tenants for testing
        $this->tenantContext->registerTenant(new Tenant(
            id: 10,
            code: 'CTY_ALPHA',
            name: 'Công ty Cổ phần Alpha Logistics',
            subdomain: 'alpha'
        ));

        $this->tenantContext->registerTenant(new Tenant(
            id: 20,
            code: 'CTY_BETA',
            name: 'Tập đoàn Beta Trading',
            subdomain: 'beta'
        ));
    }

    public function tearDown(): void
    {
        $this->tenantContext->setCurrentTenant(null);
        parent::tearDown();
    }

    public function testTenantResolvedFromHeaderId(): void
    {
        $res = $this->get('/api/status', [
            'X-Tenant-ID' => '10',
        ]);

        $this->assertStatus($res, 200);
        $this->assertEquals('10', $res->getHeaderLine('X-Resolved-Tenant-ID'));
    }

    public function testTenantResolvedFromCompanyCode(): void
    {
        $res = $this->get('/api/status', [
            'X-Company-Code' => 'CTY_BETA',
        ]);

        $this->assertStatus($res, 200);
        $this->assertEquals('20', $res->getHeaderLine('X-Resolved-Tenant-ID'));
    }

    public function testTenantResolvedFromQueryParam(): void
    {
        $res = $this->get('/api/status?tenant_id=10');

        $this->assertStatus($res, 200);
        $this->assertEquals('10', $res->getHeaderLine('X-Resolved-Tenant-ID'));
    }

    public function testTenantContextPropagatesToModuleUseCase(): void
    {
        $res = $this->get('/api/inventory/summary', [
            'X-Tenant-ID' => '10',
        ]);

        $this->assertStatus($res, 200);
        $this->assertEquals('10', $res->getHeaderLine('X-Resolved-Tenant-ID'));

        $body = (string)$res->getBody();
        $json = json_decode($body, true);

        $this->assertEquals('success', $json['status']);
        $this->assertEquals(10, $json['data']['tenant']['id']);
        $this->assertEquals('Công ty Cổ phần Alpha Logistics', $json['data']['tenant']['name']);
        $this->assertEquals(3, $json['data']['summary']['total_warehouses']);
    }
}
