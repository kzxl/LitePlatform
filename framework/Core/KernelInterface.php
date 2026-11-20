<?php

declare(strict_types=1);

namespace Lite\Framework\Core;

use Lite\Framework\Core\Events\EventBusInterface;
use Lite\Framework\Core\Plugin\ModuleManagerInterface;
use Lite\Framework\Core\Routing\RouteRegistryInterface;
use Lite\Framework\Core\Tenancy\TenantContextInterface;
use DI\Container;

/**
 * Physical Laws: Core Protocol-Agnostic Application Kernel Interface.
 * Free from any dependency on Slim 4, HTTP transports, or CLI execution contexts.
 */
interface KernelInterface
{
    /**
     * Get the root base path of the project.
     */
    public function getBasePath(): string;

    /**
     * Get application configuration array.
     *
     * @return array<string, mixed>
     */
    public function getConfig(): array;

    /**
     * Get PSR-11 Dependency Injection Container.
     */
    public function getContainer(): Container;

    /**
     * Get the Tenant Context manager.
     */
    public function getTenantContext(): TenantContextInterface;

    /**
     * Get the protocol-agnostic Route Registry.
     */
    public function getRouteRegistry(): RouteRegistryInterface;

    /**
     * Get the Dynamic Module / Plugin Manager.
     */
    public function getModuleManager(): ModuleManagerInterface;

    /**
     * Get the In-Memory EventBus.
     */
    public function getEventBus(): EventBusInterface;

    /**
     * Boot the kernel services, providers, and modules.
     */
    public function boot(): self;

    /**
     * Check if the kernel has already completed booting.
     */
    public function isBooted(): bool;
}
