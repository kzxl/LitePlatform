<?php

declare(strict_types=1);

namespace LiteView\Tests;

use LiteView\Htmx;
use LiteView\ViewEngine;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\TestCase;

final class ComponentAndOobTest extends TestCase
{
    private string $viewsDir;
    private string $cacheDir;
    private ViewEngine $engine;

    protected function setUp(): void
    {
        $this->viewsDir = sys_get_temp_dir() . '/lite_view_components_' . bin2hex(random_bytes(4));
        $this->cacheDir = sys_get_temp_dir() . '/lite_view_cache_' . bin2hex(random_bytes(4));

        @mkdir($this->viewsDir . '/components', 0777, true);
        @mkdir($this->cacheDir, 0777, true);

        $this->engine = new ViewEngine($this->viewsDir, $this->cacheDir);
    }

    protected function tearDown(): void
    {
        $this->deleteDir($this->viewsDir);
        $this->deleteDir($this->cacheDir);
    }

    public function testAnonymousComponentWithDefaultSlot(): void
    {
        // 1. Create component template
        file_put_contents($this->viewsDir . '/components/card.lite.php', '<div class="card"><h4>{{ $title }}</h4><div class="body">{!! $slot !!}</div></div>');

        // 2. Create main template calling <x-card>
        file_put_contents($this->viewsDir . '/main.lite.php', '<x-card title="Dashboard">Welcome Admin</x-card>');

        $html = $this->engine->render('main');
        $this->assertStringContainsString('<div class="card"><h4>Dashboard</h4><div class="body">Welcome Admin</div></div>', $html);
    }

    public function testAnonymousComponentWithNamedSlots(): void
    {
        file_put_contents($this->viewsDir . '/components/modal.lite.php', '<div class="modal"><header>{!! $header ?? "" !!}</header><main>{!! $slot !!}</main><footer>{!! $footer ?? "" !!}</footer></div>');

        file_put_contents($this->viewsDir . '/page.lite.php', '
<x-modal>
  <x-slot:header><h1>Modal Title</h1></x-slot:header>
  <p>Modal body paragraph</p>
  <x-slot:footer><button>Close</button></x-slot:footer>
</x-modal>
        ');

        $html = $this->engine->render('page');
        $this->assertStringContainsString('<header><h1>Modal Title</h1></header>', $html);
        $this->assertStringContainsString('<main><p>Modal body paragraph</p></main>', $html);
        $this->assertStringContainsString('<footer><button>Close</button></footer>', $html);
    }

    public function testSelfClosingComponent(): void
    {
        file_put_contents($this->viewsDir . '/components/icon.lite.php', '<i class="icon-{{ $name }} {{ $class ?? "" }}"></i>');

        file_put_contents($this->viewsDir . '/icon_test.lite.php', '<x-icon name="check" class="text-green" />');

        $html = $this->engine->render('icon_test');
        $this->assertEquals('<i class="icon-check text-green"></i>', $html);
    }

    public function testBoundAttributeExpressions(): void
    {
        file_put_contents($this->viewsDir . '/components/badge.lite.php', '<span class="badge {{ $active ? "active" : "" }}">Count: {{ $count }}</span>');

        file_put_contents($this->viewsDir . '/badge_test.lite.php', '<x-badge :active="true" :count="10 + 5" />');

        $html = $this->engine->render('badge_test');
        $this->assertEquals('<span class="badge active">Count: 15</span>', $html);
    }

    public function testBladeStyleComponentAndSlot(): void
    {
        file_put_contents($this->viewsDir . '/components/alert.lite.php', '<div class="alert {{ $type }}"><strong>{!! $title ?? "" !!}</strong>{!! $slot !!}</div>');

        file_put_contents($this->viewsDir . '/blade_comp.lite.php', '@component(\'components.alert\', [\'type\' => \'danger\'])@slot(\'title\')Warning! @endslotSystem error@endcomponent');

        $html = $this->engine->render('blade_comp');
        $this->assertEquals('<div class="alert danger"><strong>Warning! </strong>System error</div>', $html);
    }

    public function testHtmxOobSwaps(): void
    {
        file_put_contents($this->viewsDir . '/dashboard.lite.php', '
@fragment(\'feed\')
<ul id="feed-items"><li>Item 1</li><li>Item 2</li></ul>
@endfragment

@fragment(\'cart\')
<span id="cart-count">5</span>
@endfragment
        ');

        $oobHtml = $this->engine->renderOob('dashboard', [
            'feed' => true,
            'cart' => 'outerHTML:#cart-count',
        ]);

        $this->assertStringContainsString('<ul hx-swap-oob="true" id="feed-items">', $oobHtml);
        $this->assertStringContainsString('<span hx-swap-oob="outerHTML:#cart-count" id="cart-count">5</span>', $oobHtml);

        $res = $this->engine->renderOobResponse(new Response(), 'dashboard', [
            'feed' => true,
        ]);
        $this->assertEquals(200, $res->getStatusCode());
        $this->assertEquals('text/html; charset=UTF-8', $res->getHeaderLine('Content-Type'));
    }

    private function deleteDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = glob($dir . '/*') ?: [];
        foreach ($items as $item) {
            if (is_dir($item)) {
                $this->deleteDir($item);
                @rmdir($item);
            } else {
                @unlink($item);
            }
        }
        @rmdir($dir);
    }
}
