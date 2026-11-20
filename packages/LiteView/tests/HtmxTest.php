<?php

declare(strict_types=1);

namespace LiteView\Tests;

use LiteView\Htmx;
use Nyholm\Psr7\{Response, ServerRequest};
use PHPUnit\Framework\TestCase;

final class HtmxTest extends TestCase
{
    public function testIsHtmxDetection(): void
    {
        $req1 = (new ServerRequest('GET', '/'))->withHeader('HX-Request', 'true');
        $req2 = new ServerRequest('GET', '/');

        $this->assertTrue(Htmx::isHtmx($req1));
        $this->assertFalse(Htmx::isHtmx($req2));
    }

    public function testGetTargetAndTrigger(): void
    {
        $req = (new ServerRequest('GET', '/'))
            ->withHeader('HX-Target', '#table-body')
            ->withHeader('HX-Trigger', 'search-btn');

        $this->assertEquals('#table-body', Htmx::getTarget($req));
        $this->assertEquals('search-btn', Htmx::getTrigger($req));
    }

    public function testResponseModifiers(): void
    {
        $res = new Response();

        $res = Htmx::trigger($res, ['userCreated' => ['id' => 10]]);
        $res = Htmx::redirect($res, '/dashboard');
        $res = Htmx::reswap($res, 'outerHTML');
        $res = Htmx::retarget($res, '#main-content');

        $this->assertEquals('{"userCreated":{"id":10}}', $res->getHeaderLine('HX-Trigger'));
        $this->assertEquals('/dashboard', $res->getHeaderLine('HX-Redirect'));
        $this->assertEquals('outerHTML', $res->getHeaderLine('HX-Reswap'));
        $this->assertEquals('#main-content', $res->getHeaderLine('HX-Retarget'));
    }
}
