<?php

declare(strict_types=1);

namespace Tests;

use App\Testing\TestCase;

class SystemStatusTest extends TestCase
{
    public function testStatusEndpointReturnsHealthy(): void
    {
        $res = $this->get('/api/status');
        $this->assertStatus($res, 200);

        $json = $this->assertJsonOk($res);
        $this->assertJsonContains($res, 'data.status', 'healthy');
        $this->assertJsonContains($res, 'data.modules.orm', true);
    }

    public function testDocsEndpointReturns200(): void
    {
        $res = $this->get('/api/docs');
        $this->assertStatus($res, 200);
    }
}
