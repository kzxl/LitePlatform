<?php

declare(strict_types=1);

namespace LiteView\Tests;

use LiteView\ViewEngine;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\TestCase;

final class ViewEngineTest extends TestCase
{
    private string $tempViewsDir;
    private string $tempCacheDir;
    private ViewEngine $engine;

    protected function setUp(): void
    {
        $this->tempViewsDir = sys_get_temp_dir() . '/lite_view_test_views_' . uniqid();
        $this->tempCacheDir = sys_get_temp_dir() . '/lite_view_test_cache_' . uniqid();

        mkdir($this->tempViewsDir . '/layouts', 0777, true);
        mkdir($this->tempCacheDir, 0777, true);

        $this->engine = new ViewEngine($this->tempViewsDir, $this->tempCacheDir);
    }

    protected function tearDown(): void
    {
        $this->recursiveDelete($this->tempViewsDir);
        $this->recursiveDelete($this->tempCacheDir);
    }

    public function testSimpleRenderingAndEscaping(): void
    {
        file_put_contents($this->tempViewsDir . '/hello.lite.php', '<h1>Hello {{ $name }}</h1><div>{!! $raw !!}</div>');

        $html = $this->engine->render('hello', [
            'name' => '<script>alert(1)</script>',
            'raw' => '<strong>Safe</strong>',
        ]);

        $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $html);
        $this->assertStringContainsString('<strong>Safe</strong>', $html);
    }

    public function testLayoutInheritance(): void
    {
        file_put_contents(
            $this->tempViewsDir . '/layouts/main.lite.php',
            '<!DOCTYPE html><html><head><title>@yield("title", "Default Title")</title></head><body><main>@yield("content")</main></body></html>'
        );

        file_put_contents(
            $this->tempViewsDir . '/page.lite.php',
            '@extends("layouts.main")
@section("title")My Custom Title@endsection
@section("content")<p>Page Content for {{ $user }}</p>@endsection'
        );

        $html = $this->engine->render('page', ['user' => 'Alice']);

        $this->assertStringContainsString('<title>My Custom Title</title>', $html);
        $this->assertStringContainsString('<p>Page Content for Alice</p>', $html);
    }

    public function testHtmxFragmentIsolation(): void
    {
        file_put_contents(
            $this->tempViewsDir . '/users.lite.php',
            '<div>
                <header>Full Page Header</header>
                @fragment("user-list")
                <ul id="users">
                    @foreach($users as $user)
                        <li>{{ $user }}</li>
                    @endforeach
                </ul>
                @endfragment
                <footer>Full Page Footer</footer>
            </div>'
        );

        // 1. Full view render includes header, fragment, and footer
        $fullHtml = $this->engine->render('users', ['users' => ['Alice', 'Bob']]);
        $this->assertStringContainsString('Full Page Header', $fullHtml);
        $this->assertStringContainsString('<li>Alice</li>', $fullHtml);
        $this->assertStringContainsString('Full Page Footer', $fullHtml);

        // 2. Fragment render ONLY returns the fragment content!
        $fragmentHtml = $this->engine->renderFragment('users', 'user-list', ['users' => ['Charlie', 'David']]);
        $this->assertStringNotContainsString('Full Page Header', $fragmentHtml);
        $this->assertStringNotContainsString('Full Page Footer', $fragmentHtml);
        $this->assertStringContainsString('<ul id="users">', $fragmentHtml);
        $this->assertStringContainsString('<li>Charlie</li>', $fragmentHtml);
        $this->assertStringContainsString('<li>David</li>', $fragmentHtml);
    }

    public function testPsr7ResponseRendering(): void
    {
        file_put_contents($this->tempViewsDir . '/greeting.lite.php', '<p>Welcome {{ $name }}</p>');

        $response = new Response();
        $renderedResponse = $this->engine->renderResponse($response, 'greeting', ['name' => 'Eve']);

        $this->assertEquals(200, $renderedResponse->getStatusCode());
        $this->assertEquals('text/html; charset=UTF-8', $renderedResponse->getHeaderLine('Content-Type'));
        $this->assertStringContainsString('<p>Welcome Eve</p>', (string)$renderedResponse->getBody());
    }

    private function recursiveDelete(string $dir): void
    {
        if (!is_dir($dir)) return;
        $files = array_diff(scandir($dir) ?: [], ['.', '..']);
        foreach ($files as $file) {
            $path = "$dir/$file";
            is_dir($path) ? $this->recursiveDelete($path) : @unlink($path);
        }
        @rmdir($dir);
    }
}
