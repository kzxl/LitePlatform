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

        // Optional Child-Theme Override Layer: themes/<active_theme>/ overrides templates/ & plugin views
        $activeTheme = trim((string)($config['theme'] ?? $_ENV['APP_THEME'] ?? ''));
        if ($activeTheme !== '' && preg_match('/^[a-zA-Z0-9_-]+$/', $activeTheme)) {
            $themeDir = $basePath . '/themes/' . $activeTheme;
            if (is_dir($themeDir)) {
                $viewEngine->setThemePath($themeDir);
            }
        }

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

        // Register Pluggable SectionRegistry (Core + Extensions can register composable UI sections)
        $sectionRegistry = (new \Lite\Framework\Theme\SectionRegistry())->registerCoreSections($sanitizer);
        $container->set(\Lite\Framework\Theme\SectionRegistry::class, $sectionRegistry);
    }

    public function boot(App $app, Container $container, array $config): void
    {
        if (!$container->has(\LiteView\ViewEngine::class)) {
            return;
        }

        /** @var \LiteView\ViewEngine $viewEngine */
        $viewEngine = $container->get(\LiteView\ViewEngine::class);
        $basePath = (string)($config['base_path'] ?? dirname(__DIR__, 2));
        $settings = $container->has(\Lite\Framework\Cms\SiteSettings::class)
            ? $container->get(\Lite\Framework\Cms\SiteSettings::class)
            : null;

        $themeManager = new \Lite\Framework\Theme\ThemeManager($basePath, $settings);
        $container->set(\Lite\Framework\Theme\ThemeManager::class, $themeManager);

        $fallbackSlug = trim((string)($config['theme'] ?? $_ENV['APP_THEME'] ?? 'default')) ?: 'default';
        $themePath = $themeManager->resolveActiveThemePath($fallbackSlug);
        $viewEngine->setThemePath($themePath);
        $viewEngine->share('themeCss', $themeManager->renderCssVariables());
    }
}
