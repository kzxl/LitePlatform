<?php

declare(strict_types=1);

namespace Lite\Framework\Core\Plugin;

use Lite\Framework\Core\KernelInterface;
use Lite\Framework\Core\Routing\RouteRegistryInterface;
use DI\Container;

/**
 * Sovereign Plugin / Feature Module Contract conforming to Universe Architecture v4.0.
 * A module is an autonomous, self-contained domain feature.
 */
interface IPluginModule
{
    /**
     * Unique reverse-DNS identifier (e.g. "com.kzxl.inventory").
     */
    public function getId(): string;

    /**
     * Human-readable module name.
     */
    public function getName(): string;

    /**
     * Semantic version string (e.g. "1.0.0").
     */
    public function getVersion(): string;

    /**
     * Module manifest metadata (description, author, icon, adminMenu, dependencies).
     *
     * @return array<string, mixed>
     */
    public function getManifest(): array;

    /**
     * Register module services, repositories, and use-cases into the DI container.
     */
    public function registerServices(Container $container): void;

    /**
     * Register module routes into the protocol-agnostic RouteRegistry.
     */
    public function registerRoutes(RouteRegistryInterface $routes): void;

    /**
     * Lifecycle Hook: Executed when the module is installed (e.g. run module migrations).
     */
    public function install(KernelInterface $kernel): void;

    /**
     * Lifecycle Hook: Executed when the module is uninstalled.
     */
    public function uninstall(KernelInterface $kernel): void;
}
