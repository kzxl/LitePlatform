<?php

declare(strict_types=1);

namespace Lite\Framework\Generators;

use InvalidArgumentException;

/**
 * Generates a complete, LES v1 Hardened Plugin skeleton (`php bin/lite make:plugin <Name>`).
 * Output passes `php bin/lite plugin:audit` with 0 critical findings out of the box.
 */
final class PluginGenerator
{
    public function __construct(private readonly string $basePath)
    {
    }

    /**
     * @return array{module_dir: string, files: list<string>, module_class: string, module_id: string}
     */
    public function generate(string $rawName): array
    {
        $studly = preg_replace('/[^a-zA-Z0-9]/', '', ucwords(str_replace(['-', '_'], ' ', trim($rawName)))) ?? '';
        if ($studly === '' || !ctype_alpha($studly[0])) {
            throw new InvalidArgumentException("Invalid plugin name '{$rawName}'. Use PascalCase letters/digits (e.g. BookingExtension).");
        }

        $kebab = strtolower((string)preg_replace('/(?<!^)[A-Z]/', '-$0', $studly));
        $slug = preg_replace('/-extension$/', '', $kebab) ?: $kebab;
        $moduleId = 'com.liteplatform.' . $kebab;
        $namespace = 'App\\Modules\\' . $studly;
        $moduleClass = $studly . 'Module';
        $moduleDir = rtrim($this->basePath, '/\\') . '/app/Modules/' . $studly;

        if (is_dir($moduleDir)) {
            throw new InvalidArgumentException("Plugin directory already exists: app/Modules/{$studly}");
        }

        foreach (['Controllers', 'migrations', 'templates'] as $sub) {
            mkdir($moduleDir . '/' . $sub, 0777, true);
        }

        $files = [];

        // 1. Module Entrypoint (HardenedPluginInterface + SecurityAwareModuleInterface + WebModuleInterface)
        $moduleFile = $moduleDir . '/' . $moduleClass . '.php';
        file_put_contents($moduleFile, <<<PHP
<?php

declare(strict_types=1);

namespace {$namespace};

use {$namespace}\\Controllers\\{$studly}Controller;
use DI\\Container;
use Lite\\Framework\\Core\\KernelInterface;
use Lite\\Framework\\Core\\Plugin\\Capability;
use Lite\\Framework\\Core\\Plugin\\HardenedPluginInterface;
use Lite\\Framework\\Core\\Plugin\\PluginContext;
use Lite\\Framework\\Core\\Plugin\\PluginManifest;
use Lite\\Framework\\Core\\Plugin\\SecurityAwareModuleInterface;
use Lite\\Framework\\Core\\Plugin\\WebModuleInterface;
use Lite\\Framework\\Core\\Routing\\RouteRegistryInterface;
use Lite\\Framework\\Seo\\SitemapGenerator;

final class {$moduleClass} implements HardenedPluginInterface, SecurityAwareModuleInterface, WebModuleInterface
{
    public const MODULE_ID = '{$moduleId}';

    private ?PluginManifest \$cachedManifest = null;

    public function manifest(): PluginManifest
    {
        return \$this->cachedManifest ??= new PluginManifest(
            id: self::MODULE_ID,
            name: '{$studly}',
            version: '1.0.0',
            description: 'Sovereign LES v1 extension: {$studly}.',
            capabilities: [
                Capability::DB_READ,
                Capability::DB_WRITE,
                Capability::CACHE_SCOPED,
                Capability::PUBLIC_ROUTES,
                Capability::HOOKS_SUBSCRIBE,
            ],
            routePrefixes: ['/{$slug}'],
            tablePrefixes: ['{$slug}_']
        );
    }

    public function getId(): string { return self::MODULE_ID; }
    public function getName(): string { return \$this->manifest()->name; }
    public function getVersion(): string { return \$this->manifest()->version; }
    public function getManifest(): array { return \$this->manifest()->toArray(); }

    public function getPermissions(): array
    {
        return [
            '{$slug}.read'  => 'View {$studly} resources',
            '{$slug}.write' => 'Manage {$studly} resources',
        ];
    }

    public function getDefaultRoleGrants(): array
    {
        return [
            'manager' => ['{$slug}.read', '{$slug}.write'],
            'staff'   => ['{$slug}.read'],
        ];
    }

    public function getMigrationsPath(): ?string { return __DIR__ . '/migrations'; }
    public function getViewNamespace(): ?string { return '{$slug}'; }
    public function getViewsPath(): ?string { return __DIR__ . '/templates'; }
    public function getNavLinks(): array { return [['label' => '{$studly}', 'href' => '/{$slug}']]; }

    public function registerSitemap(SitemapGenerator \$sitemap, Container \$container): void
    {
        \$sitemap->addUrl('/{$slug}', changefreq: 'weekly', priority: '0.7');
    }

    public function bootPlugin(PluginContext \$context): void
    {
        \$context->registerService(
            {$studly}Controller::class,
            static fn(): {$studly}Controller => new {$studly}Controller(\$context)
        );
    }

    public function registerServices(Container \$container): void {}

    public function registerRoutes(RouteRegistryInterface \$routes): void
    {
        \$routes->get('/{$slug}', [{$studly}Controller::class, 'index'], name: '{$slug}.index', moduleId: self::MODULE_ID);
    }

    public function install(KernelInterface \$kernel): void {}
    public function uninstall(KernelInterface \$kernel): void {}
}
PHP
        );
        $files[] = $moduleFile;

        // 2. Scoped Controller
        $ctrlFile = $moduleDir . '/Controllers/' . $studly . 'Controller.php';
        file_put_contents($ctrlFile, <<<PHP
<?php

declare(strict_types=1);

namespace {$namespace}\\Controllers;

use Lite\\Framework\\Core\\Plugin\\PluginContext;
use LiteView\\ViewEngine;
use Psr\\Http\\Message\\ResponseInterface as Response;
use Psr\\Http\\Message\\ServerRequestInterface as Request;

final class {$studly}Controller
{
    public function __construct(private readonly PluginContext \$context)
    {
    }

    public function index(Request \$request, Response \$response): Response
    {
        /** @var ViewEngine \$view */
        \$view = \$this->context->getService(ViewEngine::class);
        return \$view->renderResponse(\$response, '{$slug}::index', [
            'title'    => '{$studly}',
            'pluginId' => \$this->context->getPluginId(),
        ]);
    }
}
PHP
        );
        $files[] = $ctrlFile;

        // 3. View Template
        $tplFile = $moduleDir . '/templates/index.lite.php';
        file_put_contents($tplFile, <<<BLADE
@extends('layouts.app')

@section('content')
<div class="card">
    <h1>{{ \$title ?? '{$studly}' }}</h1>
    <p style="color:var(--text-secondary);margin-top:0.5rem;">Powered by LES v1 Sandboxed Plugin <code>{{ \$pluginId ?? '{$moduleId}' }}</code>.</p>
</div>
@endsection
BLADE
        );
        $files[] = $tplFile;

        return [
            'module_dir'   => $moduleDir,
            'files'        => $files,
            'module_class' => $namespace . '\\' . $moduleClass,
            'module_id'    => $moduleId,
        ];
    }
}
