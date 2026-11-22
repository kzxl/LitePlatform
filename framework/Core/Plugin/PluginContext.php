<?php

declare(strict_types=1);

namespace Lite\Framework\Core\Plugin;

use DI\Container;
use Lite\Framework\Security\RbacManager;
use Lite\Framework\Security\SecretVault;
use Lite\Framework\Security\SecurityAuditLog;
use Lite\Framework\Security\SsrfGuard;
use LiteCache\CacheInterface;
use PDO;
use RuntimeException;

/**
 * Capability-enforced Scoped Context passed to HardenedPluginInterface modules.
 *
 * Prevents plugins from:
 * - Overwriting protected Core DI services (PDO, CsrfGuard, AdminDashboard, SecretVault, etc.)
 * - Reading raw Core secrets from config['security'] / config['mail'] / config['api']
 * - Accessing ungranted capabilities (DB, Cache, Outbound HTTP, Encrypted Secrets)
 * - Reading or overwriting another plugin's settings, secrets, or cache keys
 */
final class PluginContext
{
    /** Core container service IDs that plugins are strictly forbidden to overwrite. */
    private const PROTECTED_SERVICES = [
        'config',
        'db',
        'em',
        'admin',
        'cache',
        'view',
        'logger',
        'jwt.secret',
        'event_bus',
        'module_manager',
        'routes.registry',
        'tenant.context',
        PDO::class,
        \LiteORM\EntityManager::class,
        \LiteAdmin\AdminDashboard::class,
        \LiteCache\CacheInterface::class,
        \LiteView\ViewEngine::class,
        \Lite\Framework\Security\CsrfGuard::class,
        \Lite\Framework\Security\HoneypotGuard::class,
        \Lite\Framework\Security\SecretVault::class,
        \Lite\Framework\Security\SecurityAuditLog::class,
        \Lite\Framework\Security\RbacManager::class,
        \Lite\Framework\Security\SsrfGuard::class,
        \Lite\Framework\Core\KernelInterface::class,
        \Lite\Framework\Core\Plugin\ModuleManagerInterface::class,
        \Lite\Framework\Core\Plugin\HookBus::class,
    ];

    public function __construct(
        private readonly PluginManifest $manifest,
        private readonly Container $container,
        private readonly HookBus $hooks,
        private readonly ?SecretVault $vault = null,
        private readonly ?SecurityAuditLog $audit = null,
        private readonly ?SsrfGuard $ssrf = null
    ) {
    }

    public function getPluginId(): string
    {
        return $this->manifest->id;
    }

    public function manifest(): PluginManifest
    {
        return $this->manifest;
    }

    /**
     * Register a plugin-owned service into the container.
     * Strictly forbids overwriting any protected Core security/infrastructure binding.
     */
    public function registerService(string $serviceId, mixed $definition): void
    {
        if (in_array($serviceId, self::PROTECTED_SERVICES, true)) {
            $this->recordViolation('plugin.di_override_blocked', $serviceId);
            throw new RuntimeException(
                "Security violation: plugin '{$this->manifest->id}' cannot overwrite protected Core service '{$serviceId}'."
            );
        }

        $this->container->set($serviceId, $definition);
    }

    /**
     * Resolve a permitted service from the container (never exposes raw 'config' or 'jwt.secret').
     */
    public function getService(string $serviceId): mixed
    {
        if ($serviceId === 'config' || $serviceId === 'jwt.secret') {
            $this->recordViolation('plugin.secret_config_blocked', $serviceId);
            throw new RuntimeException(
                "Security violation: plugin '{$this->manifest->id}' cannot read raw Core secret binding '{$serviceId}'."
            );
        }

        return $this->container->get($serviceId);
    }

    public function hasService(string $serviceId): bool
    {
        if ($serviceId === 'config' || $serviceId === 'jwt.secret') {
            return false;
        }
        return $this->container->has($serviceId);
    }

    public function db(): PDO
    {
        $this->requireCapability(Capability::DB_READ);
        return $this->container->get(PDO::class);
    }

    public function hooks(): HookBus
    {
        $this->requireCapability(Capability::HOOKS_SUBSCRIBE);
        return $this->hooks;
    }

    public function addAction(string $hook, callable $callback, int $priority = 10): void
    {
        $this->requireCapability(Capability::HOOKS_SUBSCRIBE);
        $this->hooks->addAction($hook, $callback, $priority, $this->manifest->id);
    }

    public function addFilter(string $hook, callable $callback, int $priority = 10): void
    {
        $this->requireCapability(Capability::HOOKS_SUBSCRIBE);
        $this->hooks->addFilter($hook, $callback, $priority, $this->manifest->id);
    }

    /**
     * Scoped cache get/set isolated strictly to "plugin:{id}:{key}".
     */
    public function cacheGet(string $key, mixed $default = null): mixed
    {
        $this->requireCapability(Capability::CACHE_SCOPED);
        if (!$this->container->has(CacheInterface::class)) {
            return $default;
        }
        /** @var CacheInterface $cache */
        $cache = $this->container->get(CacheInterface::class);
        return $cache->get($this->scopedCacheKey($key), $default);
    }

    public function cacheSet(string $key, mixed $value, int $ttl = 300): void
    {
        $this->requireCapability(Capability::CACHE_SCOPED);
        if (!$this->container->has(CacheInterface::class)) {
            return;
        }
        /** @var CacheInterface $cache */
        $cache = $this->container->get(CacheInterface::class);
        $cache->tags(['plugin:' . $this->manifest->id])->set($this->scopedCacheKey($key), $value, $ttl);
    }

    /**
     * Persist a setting (or AEAD-encrypted secret) strictly scoped to this plugin's ID.
     */
    public function setSetting(string $key, string $value, bool $encrypted = false): void
    {
        $this->requireCapability($encrypted ? Capability::SECRETS_SCOPED : Capability::DB_WRITE);
        $pdo = $this->container->get(PDO::class);

        $stored = $value;
        if ($encrypted) {
            if ($this->vault === null) {
                throw new RuntimeException('SecretVault is not available.');
            }
            $stored = $this->vault->encrypt($value, $this->manifest->id, $key);
        }

        $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        $now = date('Y-m-d H:i:s');
        if ($driver === 'mysql') {
            $sql = 'INSERT INTO plugin_settings (plugin_id, setting_key, setting_value, is_encrypted, updated_at)
                    VALUES (:pid, :k, :v, :enc, :u)
                    ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), is_encrypted = VALUES(is_encrypted), updated_at = VALUES(updated_at)';
        } else {
            $sql = 'INSERT INTO plugin_settings (plugin_id, setting_key, setting_value, is_encrypted, updated_at)
                    VALUES (:pid, :k, :v, :enc, :u)
                    ON CONFLICT(plugin_id, setting_key) DO UPDATE SET setting_value = excluded.setting_value, is_encrypted = excluded.is_encrypted, updated_at = excluded.updated_at';
        }

        $pdo->prepare($sql)->execute([
            ':pid' => $this->manifest->id,
            ':k'   => $key,
            ':v'   => $stored,
            ':enc' => $encrypted ? 1 : 0,
            ':u'   => $now,
        ]);
    }

    /**
     * Read a plugin-scoped setting or decrypt a plugin-scoped secret transparently.
     */
    public function getSetting(string $key, string $default = ''): string
    {
        $this->requireCapability(Capability::DB_READ);
        $pdo = $this->container->get(PDO::class);

        $stmt = $pdo->prepare('SELECT setting_value, is_encrypted FROM plugin_settings WHERE plugin_id = :pid AND setting_key = :k LIMIT 1');
        $stmt->execute([':pid' => $this->manifest->id, ':k' => $key]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!is_array($row) || $row['setting_value'] === null) {
            return $default;
        }

        $val = (string)$row['setting_value'];
        if ((int)($row['is_encrypted'] ?? 0) === 1) {
            $this->requireCapability(Capability::SECRETS_SCOPED);
            if ($this->vault === null) {
                throw new RuntimeException('SecretVault is not available to decrypt secret.');
            }
            return $this->vault->decrypt($val, $this->manifest->id, $key);
        }

        return $val;
    }

    /**
     * Enforce SSRF protection on any outbound webhook / HTTP call made by this plugin.
     */
    public function assertOutboundUrlSafe(string $url): void
    {
        $this->requireCapability(Capability::HTTP_OUTBOUND);
        ($this->ssrf ?? new SsrfGuard())->assertUrlSafe($url);
    }

    /**
     * Append a plugin-tagged security audit event.
     *
     * @param array<string, mixed> $context
     */
    public function auditEvent(string $eventType, string $severity = 'INFO', string $actor = 'system', ?string $target = null, array $context = []): void
    {
        $this->audit?->record($eventType, $severity, $actor, null, $this->manifest->id, $target, $context);
    }

    public function requireCapability(Capability $capability): void
    {
        if (!$this->manifest->hasCapability($capability)) {
            $this->recordViolation('plugin.capability_denied', $capability->value);
            throw new RuntimeException(
                "Capability denied: plugin '{$this->manifest->id}' did not declare '{$capability->value}' in its manifest."
            );
        }
    }

    private function scopedCacheKey(string $key): string
    {
        return 'plugin_' . md5($this->manifest->id) . '_' . $key;
    }

    private function recordViolation(string $eventType, string $target): void
    {
        $this->audit?->record($eventType, 'HIGH', 'plugin:' . $this->manifest->id, null, $this->manifest->id, $target);
    }
}
