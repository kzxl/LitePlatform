<?php

declare(strict_types=1);

namespace Lite\Framework\Core\Plugin;

use InvalidArgumentException;

/**
 * Immutable Plugin Manifest DTO conforming to LitePlatform Sovereign Extension Standard (LES v1).
 */
final class PluginManifest
{
    /** Core route prefixes that NO plugin is permitted to claim or shadow. */
    private const RESERVED_PREFIXES = [
        '/admin/login',
        '/admin/logout',
        '/admin/settings',
        '/admin/media',
        '/admin/api/modules',
        '/install',
        '/_studio',
        '/dev/',
        '/api/login',
        '/api/status',
    ];

    /**
     * @param list<Capability> $capabilities
     * @param list<string> $routePrefixes
     * @param list<string> $tablePrefixes
     * @param array<string, string> $dependencies moduleId => versionConstraint
     */
    public function __construct(
        public readonly string $id,
        public readonly string $name,
        public readonly string $version,
        public readonly string $description,
        public readonly string $author = 'LitePlatform',
        public readonly string $coreConstraint = '^4.0',
        public readonly array $capabilities = [],
        public readonly array $routePrefixes = [],
        public readonly array $tablePrefixes = [],
        public readonly array $dependencies = [],
    ) {
        $this->validate();
    }

    public function hasCapability(Capability $capability): bool
    {
        return in_array($capability, $this->capabilities, true);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id'              => $this->id,
            'name'            => $this->name,
            'version'         => $this->version,
            'description'     => $this->description,
            'author'          => $this->author,
            'core_constraint' => $this->coreConstraint,
            'capabilities'    => array_map(static fn(Capability $c): string => $c->value, $this->capabilities),
            'route_prefixes'  => $this->routePrefixes,
            'table_prefixes'  => $this->tablePrefixes,
            'dependencies'    => $this->dependencies,
        ];
    }

    public static function assertRouteAllowed(string $routePath): void
    {
        $normalized = '/' . ltrim(strtolower(trim($routePath)), '/');
        foreach (self::RESERVED_PREFIXES as $reserved) {
            if ($normalized === $reserved || str_starts_with($normalized, rtrim($reserved, '/') . '/')) {
                throw new InvalidArgumentException("Plugin route '{$routePath}' collides with reserved Core path '{$reserved}'.");
            }
        }
    }

    private function validate(): void
    {
        if (!preg_match('/^[a-z0-9]+(\.[a-z0-9-]+){2,}$/', $this->id)) {
            throw new InvalidArgumentException("Invalid plugin ID '{$this->id}'. Expected reverse-DNS format (e.g. com.vendor.module).");
        }
        if (!preg_match('/^\d+\.\d+\.\d+(-[a-zA-Z0-9.-]+)?$/', $this->version)) {
            throw new InvalidArgumentException("Invalid SemVer version '{$this->version}' for plugin '{$this->id}'.");
        }
        foreach ($this->routePrefixes as $prefix) {
            self::assertRouteAllowed($prefix);
        }
    }
}
