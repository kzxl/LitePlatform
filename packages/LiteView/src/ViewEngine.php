<?php

declare(strict_types=1);

namespace LiteView;

use InvalidArgumentException;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;

/**
 * Main rendering engine for LiteView templates.
 */
class ViewEngine
{
    private string $viewsPath;
    private string $cachePath;
    private Compiler $compiler;

    /** @var array<string, string> Plugin/module template namespaces (e.g. 'retail' => '/path/to/templates') */
    private array $namespaces = [];

    /** @var string|null Active Child-Theme override directory (checked before default templates & plugin views) */
    private ?string $themePath = null;

    /** @var array<string, mixed> */
    private array $sharedData = [];

    /** @var array<string, string> */
    private array $sections = [];

    /** @var array<string, string> */
    private array $fragments = [];

    private ?string $layout = null;

    /** @var list<string> */
    private array $sectionStack = [];

    /** @var list<string> */
    private array $fragmentStack = [];

    /** @var list<array{name: string, data: array<string, mixed>, slots: array<string, string>, currentSlot: ?string}> */
    private array $componentStack = [];

    private static ?string $csrfToken = null;

    public function __construct(string $viewsPath, ?string $cachePath = null, ?Compiler $compiler = null)
    {
        $this->viewsPath = rtrim($viewsPath, '/\\');
        $this->cachePath = rtrim($cachePath ?? sys_get_temp_dir() . '/lite_view_cache', '/\\');
        $this->compiler = $compiler ?? new Compiler();

        if (!is_dir($this->cachePath) && !mkdir($this->cachePath, 0777, true) && !is_dir($this->cachePath)) {
            throw new RuntimeException("Cannot create view cache directory: {$this->cachePath}");
        }
    }

    public function getCompiler(): Compiler
    {
        return $this->compiler;
    }

    /**
     * Register a namespaced views directory for plugin modules (e.g. 'retail::index').
     */
    public function addNamespace(string $namespace, string $path): self
    {
        $this->namespaces[$namespace] = rtrim($path, '/\\');
        return $this;
    }

    /**
     * Set active Child-Theme override directory (WordPress-style non-destructive UI override).
     * Resolution order:
     * - Core views: {themePath}/{view}.lite.php -> {viewsPath}/{view}.lite.php
     * - Plugin views (ns::view): {themePath}/modules/{ns}/{view}.lite.php -> {namespaces[ns]}/{view}.lite.php
     */
    public function setThemePath(?string $themePath): self
    {
        $this->themePath = ($themePath !== null && trim($themePath) !== '')
            ? rtrim($themePath, '/\\')
            : null;
        return $this;
    }

    public function getThemePath(): ?string
    {
        return $this->themePath;
    }

    public static function setCsrfToken(?string $token): void
    {
        self::$csrfToken = $token;
    }

    public static function csrfField(): string
    {
        $token = self::$csrfToken ?? '';
        return '<input type="hidden" name="_csrf" value="' . htmlspecialchars($token, ENT_QUOTES, 'UTF-8') . '">';
    }

    /**
     * Share global data with all rendered views.
     */
    public function share(string $key, mixed $value): self
    {
        $this->sharedData[$key] = $value;
        return $this;
    }

    /**
     * Render a template and return output as string.
     *
     * @param string $view Template name with dot notation (e.g. 'users.index')
     * @param array<string, mixed> $data View parameters
     */
    public function render(string $view, array $data = []): string
    {
        $this->resetState();

        $compiledPath = $this->compileTemplate($view);
        $mergedData = array_merge($this->sharedData, $data);

        $content = $this->evaluate($compiledPath, $mergedData);

        // If template extends a parent layout, render layout with captured sections
        if ($this->layout !== null) {
            $layoutView = $this->layout;
            $this->layout = null; // Clear so layout doesn't loop
            $layoutCompiledPath = $this->compileTemplate($layoutView);
            $content = $this->evaluate($layoutCompiledPath, $mergedData);
        }

        return $content;
    }

    /**
     * Render only a specific tagged @fragment('name') from a template.
     * Essential for HTMX partial swaps without creating new endpoints.
     */
    public function renderFragment(string $view, string $fragment, array $data = []): string
    {
        $this->resetState();

        $compiledPath = $this->compileTemplate($view);
        $mergedData = array_merge($this->sharedData, $data);

        // Evaluate view to trigger fragment captures
        $this->evaluate($compiledPath, $mergedData);

        if (!isset($this->fragments[$fragment])) {
            throw new InvalidArgumentException("Fragment '{$fragment}' not found in view '{$view}'");
        }

        return $this->fragments[$fragment];
    }

    /**
     * Render a view directly into a PSR-7 ResponseInterface.
     */
    public function renderResponse(ResponseInterface $response, string $view, array $data = []): ResponseInterface
    {
        $html = $this->render($view, $data);
        $response->getBody()->write($html);
        return $response->withHeader('Content-Type', 'text/html; charset=UTF-8');
    }

    /**
     * Render a fragment directly into a PSR-7 ResponseInterface.
     */
    public function renderFragmentResponse(ResponseInterface $response, string $view, string $fragment, array $data = []): ResponseInterface
    {
        $html = $this->renderFragment($view, $fragment, $data);
        $response->getBody()->write($html);
        return $response->withHeader('Content-Type', 'text/html; charset=UTF-8');
    }

    /**
     * Render multiple fragments as Out-Of-Band (OOB) swaps in a single payload for HTMX.
     *
     * @param string $view Template name
     * @param array<string, string|bool> $fragments Mapping of fragmentName => target selector (e.g. true, '#sidebar', 'outerHTML:#badge')
     * @param array<string, mixed> $data View parameters
     */
    public function renderOob(string $view, array $fragments, array $data = []): string
    {
        $this->resetState();

        $compiledPath = $this->compileTemplate($view);
        $mergedData = array_merge($this->sharedData, $data);

        // Evaluate view to trigger fragment captures
        $this->evaluate($compiledPath, $mergedData);

        $output = '';
        foreach ($fragments as $fragmentName => $oobTarget) {
            if (!isset($this->fragments[$fragmentName])) {
                throw new InvalidArgumentException("Fragment '{$fragmentName}' not found in view '{$view}'");
            }
            $fragmentHtml = $this->fragments[$fragmentName];
            $output .= Htmx::oob($fragmentHtml, $oobTarget) . "\n";
        }

        return trim($output);
    }

    /**
     * Render multiple fragments as Out-Of-Band (OOB) swaps directly into a PSR-7 ResponseInterface.
     */
    public function renderOobResponse(
        ResponseInterface $response,
        string $view,
        array $fragments,
        array $data = []
    ): ResponseInterface {
        $html = $this->renderOob($view, $fragments, $data);
        $response->getBody()->write($html);
        return $response->withHeader('Content-Type', 'text/html; charset=UTF-8');
    }

    // ─── Template Runtime Helpers (Called by compiled PHP) ─────────

    public function extend(string $layout): void
    {
        $this->layout = $layout;
    }

    public function startSection(string $name): void
    {
        $this->sectionStack[] = $name;
        ob_start();
    }

    public function endSection(): void
    {
        if (empty($this->sectionStack)) {
            throw new RuntimeException("Cannot end section: no open sections found");
        }

        $name = array_pop($this->sectionStack);
        $content = ob_get_clean();

        $this->sections[$name] = $content !== false ? $content : '';
    }

    public function yieldSection(string $name, string $default = ''): string
    {
        return $this->sections[$name] ?? $default;
    }

    public function parentPlaceholder(): string
    {
        return '<!-- __LITE_VIEW_PARENT_PLACEHOLDER__ -->';
    }

    public function startFragment(string $name): void
    {
        $this->fragmentStack[] = $name;
        ob_start();
    }

    public function endFragment(): void
    {
        if (empty($this->fragmentStack)) {
            throw new RuntimeException("Cannot end fragment: no open fragments found");
        }

        $name = array_pop($this->fragmentStack);
        $content = ob_get_clean();

        $fragmentContent = $content !== false ? $content : '';
        $this->fragments[$name] = $fragmentContent;

        // Echo content out so normal view rendering still includes it
        echo $fragmentContent;
    }

    public function include(string $view, array $data = []): string
    {
        $compiledPath = $this->compileTemplate($view);
        $mergedData = array_merge($this->sharedData, $data);

        return $this->evaluate($compiledPath, $mergedData);
    }

    public function startComponent(string $name, array $data = []): void
    {
        $this->componentStack[] = [
            'name' => $name,
            'data' => $data,
            'slots' => [],
            'currentSlot' => null,
        ];
        ob_start();
    }

    public function startSlot(string $name): void
    {
        if (empty($this->componentStack)) {
            throw new RuntimeException("Cannot start slot [{$name}]: no active component.");
        }

        $idx = array_key_last($this->componentStack);
        $this->componentStack[$idx]['currentSlot'] = $name;
        ob_start();
    }

    public function endSlot(): void
    {
        if (empty($this->componentStack)) {
            throw new RuntimeException("Cannot end slot: no active component.");
        }

        $idx = array_key_last($this->componentStack);
        $slotName = $this->componentStack[$idx]['currentSlot'];

        if ($slotName === null) {
            throw new RuntimeException("Cannot end slot: no active slot was opened.");
        }

        $content = ob_get_clean();
        $this->componentStack[$idx]['slots'][$slotName] = $content !== false ? $content : '';
        $this->componentStack[$idx]['currentSlot'] = null;
    }

    public function renderComponentEnd(): string
    {
        if (empty($this->componentStack)) {
            throw new RuntimeException("Cannot end component: component stack is empty.");
        }

        $content = ob_get_clean();
        $component = array_pop($this->componentStack);

        $defaultSlot = $content !== false ? trim($content) : '';
        $slots = $component['slots'];
        $slots['slot'] = $defaultSlot;

        $viewData = array_merge($component['data'], $slots);
        $viewName = $this->resolveComponentView($component['name']);

        return $this->render($viewName, $viewData);
    }

    public function renderComponent(string $name, array $data = []): string
    {
        $viewName = $this->resolveComponentView($name);
        $data['slot'] = $data['slot'] ?? '';
        return $this->render($viewName, $data);
    }

    private function resolveComponentView(string $name): string
    {
        if (str_starts_with($name, 'components.')) {
            return $name;
        }

        try {
            $this->resolveViewPath($name);
            return $name;
        } catch (InvalidArgumentException $e) {
            return 'components.' . $name;
        }
    }

    // ─── Internal Compilation & Evaluation ─────────────────────────

    private function resetState(): void
    {
        $this->sections = [];
        $this->fragments = [];
        $this->layout = null;
        $this->sectionStack = [];
        $this->fragmentStack = [];
        $this->componentStack = [];
    }

    private function compileTemplate(string $view): string
    {
        $sourcePath = $this->resolveViewPath($view);
        $hash = md5($sourcePath);
        $cacheFile = $this->cachePath . '/' . $hash . '.php';

        // Recompile if cache missing or source file modified
        if (!file_exists($cacheFile) || filemtime($sourcePath) > filemtime($cacheFile)) {
            $rawContent = file_get_contents($sourcePath);
            if ($rawContent === false) {
                throw new RuntimeException("Unable to read view file: {$sourcePath}");
            }

            $compiled = $this->compiler->compile($rawContent);
            file_put_contents($cacheFile, $compiled, LOCK_EX);
        }

        return $cacheFile;
    }

    private function resolveViewPath(string $view): string
    {
        $baseDir = $this->viewsPath;
        $viewName = $view;
        $namespace = null;

        // Support plugin namespaced views: 'retail::index' -> $this->namespaces['retail']/index.lite.php
        if (str_contains($view, '::')) {
            [$ns, $subView] = explode('::', $view, 2);
            if (!isset($this->namespaces[$ns])) {
                throw new InvalidArgumentException("View namespace [{$ns}] is not registered.");
            }
            $namespace = $ns;
            $baseDir = $this->namespaces[$ns];
            $viewName = $subView;
        }

        // Support dot notation: 'users.index' -> 'users/index.lite.php'
        $normalized = str_replace('.', '/', $viewName);
        $extensions = ['.lite.php', '.php', '.html'];

        // 1. Check active Child-Theme override directory first (if configured)
        if ($this->themePath !== null && is_dir($this->themePath)) {
            $themeBase = $namespace !== null
                ? $this->themePath . '/modules/' . $namespace
                : $this->themePath;
            foreach ($extensions as $ext) {
                $override = $themeBase . '/' . $normalized . $ext;
                if (file_exists($override)) {
                    return $override;
                }
            }
        }

        // 2. Fallback to default Core or Plugin template directory
        foreach ($extensions as $ext) {
            $candidate = $baseDir . '/' . $normalized . $ext;
            if (file_exists($candidate)) {
                return $candidate;
            }
        }

        throw new InvalidArgumentException("View [{$view}] not found in [{$baseDir}]");
    }

    /**
     * Isolate evaluation scope.
     *
     * @param string $__file
     * @param array<string, mixed> $__data
     */
    private function evaluate(string $__file, array $__data): string
    {
        $__engine = $this;
        extract($__data, EXTR_SKIP);

        ob_start();
        try {
            include $__file;
        } catch (\Throwable $e) {
            ob_end_clean();
            throw $e;
        }

        return (string)ob_get_clean();
    }
}
