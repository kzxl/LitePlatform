<?php

declare(strict_types=1);

namespace LiteView;

/**
 * Compiles Blade-like template syntax into native, cached PHP code.
 */
class Compiler
{
    /** @var array<string, callable> */
    private array $customDirectives = [];

    /**
     * Register a custom directive.
     * Handler signature: fn(?string $expression): string
     */
    public function directive(string $name, callable $handler): self
    {
        $this->customDirectives[$name] = $handler;
        return $this;
    }

    /**
     * Compile template content to raw PHP code.
     */
    public function compile(string $content): string
    {
        // 1. Comments: {{-- comment --}} completely stripped
        $content = preg_replace('/\{\{--(.*?)--\}\}/s', '', $content) ?? $content;

        // 2. Raw Echo: {!! $expr !!}
        $content = preg_replace('/\{!!\s*(.+?)\s*!!\}/s', '<?= $1; ?>', $content) ?? $content;

        // 3. Escaped Echo: {{ $expr }}
        $content = preg_replace('/\{\{\s*(.+?)\s*\}\}/s', '<?= htmlspecialchars((string)($1), ENT_QUOTES, \'UTF-8\'); ?>', $content) ?? $content;

        // 4. Custom Directives
        foreach ($this->customDirectives as $name => $handler) {
            $pattern = '/@' . preg_quote($name, '/') . '(?:\((.*?)\))?/';
            $content = preg_replace_callback($pattern, function ($matches) use ($handler) {
                $expr = $matches[1] ?? null;
                return $handler($expr);
            }, $content) ?? $content;
        }

        // 5. Layout and inheritance directives
        $content = $this->compileLayoutDirectives($content);

        // 6. HTMX Fragments
        $content = $this->compileFragmentDirectives($content);

        // 7. Components & Slots (<x-tag>, <x-slot>, @component, @slot)
        $content = $this->compileComponentsAndSlots($content);

        // 8. Standard control structures
        $content = $this->compileControlStructures($content);

        return $content;
    }

    private function compileComponentsAndSlots(string $content): string
    {
        // 1. Named slots: <x-slot:name> or <x-slot name="name">
        $content = preg_replace(
            '/<x-slot:([a-zA-Z0-9_\.-]+)>/',
            '<?php $__engine->startSlot(\'$1\'); ?>',
            $content
        ) ?? $content;

        $content = preg_replace(
            '/<x-slot\s+name=["\']([a-zA-Z0-9_\.-]+)["\']\s*>/',
            '<?php $__engine->startSlot(\'$1\'); ?>',
            $content
        ) ?? $content;

        $content = preg_replace(
            '/<\/x-slot(?::[a-zA-Z0-9_\.-]+)?>/',
            '<?php $__engine->endSlot(); ?>',
            $content
        ) ?? $content;

        // 2. Self-closing components: <x-alert type="warning" />
        $content = preg_replace_callback(
            '/<x-([a-zA-Z0-9_\.-]+)\s*([^>]*?)\/>/s',
            function ($matches) {
                $name = $matches[1];
                $attrs = $this->compileAttributes($matches[2] ?? '');
                return "<?= \$__engine->renderComponent('{$name}', {$attrs}); ?>";
            },
            $content
        ) ?? $content;

        // 3. Opening component tags: <x-alert type="warning">
        $content = preg_replace_callback(
            '/<x-([a-zA-Z0-9_\.-]+)\s*([^>]*?)>/s',
            function ($matches) {
                $name = $matches[1];
                $attrs = $this->compileAttributes($matches[2] ?? '');
                return "<?php \$__engine->startComponent('{$name}', {$attrs}); ?>";
            },
            $content
        ) ?? $content;

        // 4. Closing component tags: </x-alert>
        $content = preg_replace(
            '/<\/x-([a-zA-Z0-9_\.-]+)>/',
            '<?= $__engine->renderComponentEnd(); ?>',
            $content
        ) ?? $content;

        // 5. Classic @component / @endcomponent
        $content = preg_replace(
            '/@component\((.*?)\)/',
            '<?php $__engine->startComponent($1); ?>',
            $content
        ) ?? $content;

        $content = preg_replace(
            '/@endcomponent/',
            '<?= $__engine->renderComponentEnd(); ?>',
            $content
        ) ?? $content;

        // 6. Classic @slot / @endslot
        $content = preg_replace(
            '/@slot\((.*?)\)/',
            '<?php $__engine->startSlot($1); ?>',
            $content
        ) ?? $content;

        $content = preg_replace(
            '/@endslot/',
            '<?php $__engine->endSlot(); ?>',
            $content
        ) ?? $content;

        return $content;
    }

    public function compileAttributes(string $attributeString): string
    {
        $attributeString = trim($attributeString);
        if ($attributeString === '') {
            return '[]';
        }

        $pattern = '/(?:\:([a-zA-Z0-9_\.-]+)=["\'](.*?)["\'])|(?:([a-zA-Z0-9_\.-]+)=["\'](.*?)["\'])|(?:([a-zA-Z0-9_\.-]+))/s';
        preg_match_all($pattern, $attributeString, $matches, PREG_SET_ORDER);

        $pairs = [];
        foreach ($matches as $match) {
            if (!empty($match[1])) {
                $name = $match[1];
                $expr = $match[2];
                $pairs[] = var_export($name, true) . ' => (' . $expr . ')';
            } elseif (!empty($match[3])) {
                $name = $match[3];
                $val = $match[4];
                $pairs[] = var_export($name, true) . ' => ' . var_export($val, true);
            } elseif (!empty($match[5])) {
                $name = $match[5];
                $pairs[] = var_export($name, true) . ' => true';
            }
        }

        return '[' . implode(', ', $pairs) . ']';
    }

    private function compileLayoutDirectives(string $content): string
    {
        // @extends('layout')
        $content = preg_replace('/@extends\((.*?)\)/', '<?php $__engine->extend($1); ?>', $content) ?? $content;

        // @section('name')
        $content = preg_replace('/@section\((.*?)\)/', '<?php $__engine->startSection($1); ?>', $content) ?? $content;

        // @endsection
        $content = preg_replace('/@endsection/', '<?php $__engine->endSection(); ?>', $content) ?? $content;

        // @parent
        $content = preg_replace('/@parent/', '<?php $__engine->parentPlaceholder(); ?>', $content) ?? $content;

        // @yield('name', 'default')
        $content = preg_replace('/@yield\((.*?)\)/', '<?= $__engine->yieldSection($1); ?>', $content) ?? $content;

        // @include('view', ['key' => 'val'])
        $content = preg_replace('/@include\((.*?)\)/', '<?= $__engine->include($1); ?>', $content) ?? $content;

        return $content;
    }

    private function compileFragmentDirectives(string $content): string
    {
        // @fragment('name')
        $content = preg_replace('/@fragment\((.*?)\)/', '<?php $__engine->startFragment($1); ?>', $content) ?? $content;

        // @endfragment
        $content = preg_replace('/@endfragment/', '<?php $__engine->endFragment(); ?>', $content) ?? $content;

        return $content;
    }

    private function compileControlStructures(string $content): string
    {
        // Balanced parenthesis group: $1 = outer (...), $2 = inner expression
        $balanced = '(\(((?:[^()]++|(?1))*)\))';

        $patterns = [
            // If / Elseif / Else / Endif
            '/@if' . $balanced . '/' => '<?php if ($2): ?>',
            '/@elseif' . $balanced . '/' => '<?php elseif ($2): ?>',
            '/@else\b/' => '<?php else: ?>',
            '/@endif\b/' => '<?php endif; ?>',

            // Unless
            '/@unless' . $balanced . '/' => '<?php if (!($2)): ?>',
            '/@endunless\b/' => '<?php endif; ?>',

            // Isset / Empty
            '/@isset' . $balanced . '/' => '<?php if (isset($2)): ?>',
            '/@endisset\b/' => '<?php endif; ?>',
            '/@empty' . $balanced . '/' => '<?php if (empty($2)): ?>',
            '/@endempty\b/' => '<?php endif; ?>',

            // Loops: Foreach / For / While
            '/@foreach' . $balanced . '/' => '<?php foreach ($2): ?>',
            '/@endforeach\b/' => '<?php endforeach; ?>',
            '/@for' . $balanced . '/' => '<?php for ($2): ?>',
            '/@endfor\b/' => '<?php endfor; ?>',
            '/@while' . $balanced . '/' => '<?php while ($2): ?>',
            '/@endwhile\b/' => '<?php endwhile; ?>',

            // Raw PHP
            '/@php\b/' => '<?php ',
            '/@endphp\b/' => ' ?>',

            // CSRF & Method
            '/@csrf\b/' => '<?= \LiteView\ViewEngine::csrfField(); ?>',
            '/@method' . $balanced . '/' => '<input type="hidden" name="_method" value="<?= htmlspecialchars((string)($2), ENT_QUOTES, \'UTF-8\'); ?>">',

            // Auth & Guest
            '/@auth\b/' => '<?php if (!empty($currentUser) || !empty($_SESSION[\'user_id\'])): ?>',
            '/@elseauth\b/' => '<?php else: ?>',
            '/@endauth\b/' => '<?php endif; ?>',
            '/@guest\b/' => '<?php if (empty($currentUser) && empty($_SESSION[\'user_id\'])): ?>',
            '/@endguest\b/' => '<?php endif; ?>',
        ];

        return preg_replace(array_keys($patterns), array_values($patterns), $content) ?? $content;
    }
}
