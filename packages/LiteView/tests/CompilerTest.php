<?php

declare(strict_types=1);

namespace LiteView\Tests;

use LiteView\Compiler;
use PHPUnit\Framework\TestCase;

final class CompilerTest extends TestCase
{
    private Compiler $compiler;

    protected function setUp(): void
    {
        $this->compiler = new Compiler();
    }

    public function testEscapedEcho(): void
    {
        $template = 'Hello {{ $name }}!';
        $compiled = $this->compiler->compile($template);

        $this->assertStringContainsString('<?= htmlspecialchars((string)($name), ENT_QUOTES, \'UTF-8\'); ?>', $compiled);
    }

    public function testRawEcho(): void
    {
        $template = 'Hello {!! $html !!}!';
        $compiled = $this->compiler->compile($template);

        $this->assertStringContainsString('<?= $html; ?>', $compiled);
    }

    public function testCommentsStripped(): void
    {
        $template = 'A {{-- this is a secret --}} B';
        $compiled = $this->compiler->compile($template);

        $this->assertStringNotContainsString('this is a secret', $compiled);
        $this->assertEquals('A  B', $compiled);
    }

    public function testControlStructures(): void
    {
        $template = '@if($count > 0) Positive @elseif($count == 0) Zero @else Negative @endif';
        $compiled = $this->compiler->compile($template);

        $this->assertStringContainsString('<?php if ($count > 0): ?>', $compiled);
        $this->assertStringContainsString('<?php elseif ($count == 0): ?>', $compiled);
        $this->assertStringContainsString('<?php else: ?>', $compiled);
        $this->assertStringContainsString('<?php endif; ?>', $compiled);
    }

    public function testLoops(): void
    {
        $template = '@foreach($items as $item) {{ $item }} @endforeach';
        $compiled = $this->compiler->compile($template);

        $this->assertStringContainsString('<?php foreach ($items as $item): ?>', $compiled);
        $this->assertStringContainsString('<?php endforeach; ?>', $compiled);
    }

    public function testCsrfAndMethod(): void
    {
        $template = '<form>@csrf @method("DELETE")</form>';
        $compiled = $this->compiler->compile($template);

        $this->assertStringContainsString('<?= \LiteView\ViewEngine::csrfField(); ?>', $compiled);
        $this->assertStringContainsString('<input type="hidden" name="_method" value="<?= htmlspecialchars((string)("DELETE"), ENT_QUOTES, \'UTF-8\'); ?>">', $compiled);
    }

    public function testCustomDirective(): void
    {
        $this->compiler->directive('upper', function (?string $expr) {
            return "<?= strtoupper($expr); ?>";
        });

        $template = 'Name: @upper("john")';
        $compiled = $this->compiler->compile($template);

        $this->assertStringContainsString('<?= strtoupper("john"); ?>', $compiled);
    }

    public function testAuthAndGuestDirectives(): void
    {
        $template = '@auth Welcome User @elseauth Log in @endauth @guest Welcome Guest @endguest';
        $compiled = $this->compiler->compile($template);

        $this->assertStringContainsString('<?php if (!empty($currentUser) || !empty($_SESSION[\'user_id\'])): ?>', $compiled);
        $this->assertStringContainsString('<?php else: ?>', $compiled);
        $this->assertStringContainsString('<?php endif; ?>', $compiled);
        $this->assertStringContainsString('<?php if (empty($currentUser) && empty($_SESSION[\'user_id\'])): ?>', $compiled);
    }

    public function testControlStructuresWithNestedParentheses(): void
    {
        $template = '@if(isset($seo) && count($items) > 0) @foreach(array_slice($items, 0, 2) as $i) OK @endforeach @endif';
        $compiled = $this->compiler->compile($template);

        $this->assertStringContainsString('<?php if (isset($seo) && count($items) > 0): ?>', $compiled);
        $this->assertStringContainsString('<?php foreach (array_slice($items, 0, 2) as $i): ?>', $compiled);
    }
}
