<?php

declare(strict_types=1);

namespace Lite\Framework\Core\Plugin;

/**
 * Hardened Plugin Contract (LES v1).
 * Plugins implementing this interface declare an immutable PluginManifest (capabilities,
 * route/table prefixes, dependencies) and receive a scoped PluginContext instead of raw DI access.
 */
interface HardenedPluginInterface extends IPluginModule
{
    public function manifest(): PluginManifest;

    /**
     * Boot plugin services and hooks through the capability-checked PluginContext facade.
     */
    public function bootPlugin(PluginContext $context): void;
}
