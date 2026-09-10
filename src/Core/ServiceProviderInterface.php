<?php

declare(strict_types=1);

namespace App\Core;

use DI\Container;
use Slim\App;

/**
 * Contract for modular service providers.
 */
interface ServiceProviderInterface
{
    /**
     * Register services into the DI container.
     *
     * @param Container $container
     * @param array<string, mixed> $config
     */
    public function register(Container $container, array $config): void;

    /**
     * Boot services and attach middleware or routes to the Slim App.
     *
     * @param App $app
     * @param Container $container
     * @param array<string, mixed> $config
     */
    public function boot(App $app, Container $container, array $config): void;
}
