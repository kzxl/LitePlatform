<?php

declare(strict_types=1);

namespace Lite\Framework\Providers;

use Lite\Framework\Core\ServiceProviderInterface;
use DI\Container;
use Slim\App;

/**
 * Service provider for LiteView template engine (OpCache Blade compiler with HTMX fragments).
 */
class ViewProvider implements ServiceProviderInterface
{
    public function register(Container $container, array $config): void
    {
        if (!class_exists(\LiteView\ViewEngine::class)) {
            return;
        }

        $basePath = $config['base_path'] ?? dirname(__DIR__, 2);
        $viewsPath = $basePath . '/templates';
        $cachePath = $basePath . '/storage/cache/views';

        if (!is_dir($viewsPath)) {
            mkdir($viewsPath, 0777, true);
        }
        if (!is_dir($cachePath)) {
            mkdir($cachePath, 0777, true);
        }

        $viewEngine = new \LiteView\ViewEngine($viewsPath, $cachePath);

        // Share global app variables & security template helpers
        $viewEngine->share('appName', $config['name'] ?? 'LitePlatform');
        $viewEngine->share('appEnv', $config['env'] ?? 'development');

        $sanitizer = new \Lite\Framework\Security\HtmlSanitizer();
        $container->set(\Lite\Framework\Security\HtmlSanitizer::class, $sanitizer);
        $viewEngine->share('sanitizer', $sanitizer);
        $viewEngine->share('sanitize', static fn(string $html): string => $sanitizer->sanitize($html));

        $hpSecret = (string)($config['security']['csrf_secret'] ?? 'csrf-secret-key-for-stateless-tokens');
        $honeypot = new \Lite\Framework\Security\HoneypotGuard($hpSecret);
        $container->set(\Lite\Framework\Security\HoneypotGuard::class, $honeypot);
        $viewEngine->share('honeypot', $honeypot);

        // Register @sanitize($html) and @honeypot directives
        $viewEngine->getCompiler()->directive('sanitize', static function (?string $expr): string {
            $arg = $expr ?? "''";
            return "<?= \$sanitizer->sanitize((string)({$arg})); ?>";
        });
        $viewEngine->getCompiler()->directive('honeypot', static function (): string {
            return '<?= $honeypot->renderFields(); ?>';
        });

        $container->set(\LiteView\ViewEngine::class, $viewEngine);
        $container->set('view', $viewEngine);
    }

    public function boot(App $app, Container $container, array $config): void
    {
        // View engine ready for controller invocation
    }
}
