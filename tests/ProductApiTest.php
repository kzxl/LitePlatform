<?php

declare(strict_types=1);

namespace Tests;

use App\Testing\TestCase;

class ProductApiTest extends TestCase
{
    public function testListProductsReturnsSuccess(): void
    {
        $res = $this->get('/api/products');
        $this->assertStatus($res, 200);
        $this->assertJsonOk($res);
    }

    public function testCreateProductReturns201AndValidJson(): void
    {
        $payload = [
            'name'      => 'Wireless Gaming Mouse',
            'price'     => 79.99,
            'stock'     => 150,
            'is_active' => true,
        ];

        $res = $this->postJson('/api/products', $payload);
        $this->assertStatus($res, 201);
        $this->assertJsonOk($res);
        $this->assertJsonContains($res, 'data.name', 'Wireless Gaming Mouse');
    }

    public function testNonExistentProductReturns404(): void
    {
        $res = $this->get('/api/products/99999');
        $this->assertStatus($res, 404);
    }
}
