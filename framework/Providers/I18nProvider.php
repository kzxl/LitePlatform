<?php

declare(strict_types=1);

namespace Lite\Framework\Providers;

use DI\Container;
use Lite\Framework\Core\ServiceProviderInterface;
use Lite\Framework\I18n\Translator;
use Lite\Framework\Middleware\LocaleMiddleware;
use LiteView\ViewEngine;
use Nyholm\Psr7\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\App;

/**
 * Service provider for multi-language UI (i18n):
 * - Registers OpCache-backed Translator (lang/*.php)
 * - Adds escaped @lang('key') directive to LiteView Compiler
 * - Mounts LocaleMiddleware and safe /lang/{locale} switcher endpoint
 */
final class I18nProvider implements ServiceProviderInterface
{
    public function register(Container $container, array $config): void
    {
        $i18n = (array)($config['i18n'] ?? []);
        $basePath = (string)($config['base_path'] ?? dirname(__DIR__, 2));
        $langPath = $basePath . '/lang';

        $defaultLocale = (string)($i18n['default'] ?? 'vi');
        $fallbackLocale = (string)($i18n['fallback'] ?? 'en');
        $supported = (array)($i18n['supported'] ?? ['vi', 'en']);

        $translator = new Translator($langPath, $defaultLocale, $fallbackLocale, $supported);
        $container->set(Translator::class, $translator);
        $container->set('translator', $translator);

        if ($container->has(ViewEngine::class)) {
            /** @var ViewEngine $view */
            $view = $container->get(ViewEngine::class);
            $view->share('locale', $translator->getLocale());
            $view->share('supportedLocales', $translator->getSupportedLocales());
            $view->share('translator', $translator);
            $view->share('t', static fn(string $key, array $replace = []): string => $translator->get($key, $replace));

            // Register auto-escaped @lang('key') or @lang('key', ['name' => $x]) directive
            $view->getCompiler()->directive('lang', static function (?string $expr): string {
                $args = $expr ?? "''";
                return "<?= htmlspecialchars(\$translator->get({$args}), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>";
            });
        }
    }

    public function boot(App $app, Container $container, array $config): void
    {
        if (!$container->has(Translator::class)) {
            return;
        }

        /** @var Translator $translator */
        $translator = $container->get(Translator::class);
        $view = $container->has(ViewEngine::class) ? $container->get(ViewEngine::class) : null;
        $i18n = (array)($config['i18n'] ?? []);
        $secureCookie = (bool)($config['security']['cookie_secure'] ?? false);

        // Attach LocaleMiddleware so active locale is resolved before View / SEO / PageCache
        $app->add(new LocaleMiddleware(
            translator: $translator,
            view: $view,
            detectHeader: (bool)($i18n['detect_header'] ?? true),
            secureCookie: $secureCookie
        ));

        // Clean locale switch route: GET /lang/{locale}?redirect=/current-path
        $app->get('/lang/{locale}', function (Request $request, ResponseInterface $response, array $args) use ($translator, $secureCookie): ResponseInterface {
            $requested = strtolower(trim((string)($args['locale'] ?? '')));
            $locale = $translator->isSupported($requested) ? $requested : $translator->getLocale();

            // Prevent open redirect: only allow local relative paths starting with single '/'
            $redirect = (string)($request->getQueryParams()['redirect'] ?? '/');
            if ($redirect === '' || !str_starts_with($redirect, '/') || str_starts_with($redirect, '//')) {
                $redirect = '/';
            }

            $secureFlag = $secureCookie ? '; Secure' : '';
            $cookie = sprintf(
                '%s=%s; Path=/; Max-Age=31536000; SameSite=Lax%s',
                LocaleMiddleware::COOKIE_NAME,
                $locale,
                $secureFlag
            );

            return (new Response(302))
                ->withHeader('Location', $redirect)
                ->withHeader('Set-Cookie', $cookie);
        });
    }
}
