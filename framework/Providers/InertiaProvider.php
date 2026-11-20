<?php

declare(strict_types=1);

namespace Lite\Framework\Providers;

use Lite\Framework\Core\ServiceProviderInterface;
use DI\Container;
use Slim\App;

/**
 * Service provider for LiteInertia (Vue / React SPA bridge).
 */
class InertiaProvider implements ServiceProviderInterface
{
    public function register(Container $container, array $config): void
    {
        if (!class_exists(\LiteInertia\Inertia::class)) {
            return;
        }

        $inertia = new \LiteInertia\Inertia();
        $inertia->share([
            'appName' => $config['name'] ?? 'LitePlatform',
        ]);

        $container->set(\LiteInertia\Inertia::class, $inertia);
        $container->set('inertia', $inertia);
    }

    public function boot(App $app, Container $container, array $config): void
    {
        if ($container->has(\LiteInertia\Inertia::class) && class_exists(\LiteInertia\InertiaMiddleware::class)) {
            $inertia = $container->get(\LiteInertia\Inertia::class);
            $app->add(new \LiteInertia\InertiaMiddleware($inertia));
        }
    }
}
