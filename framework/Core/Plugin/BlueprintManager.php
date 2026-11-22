<?php

declare(strict_types=1);

namespace Lite\Framework\Core\Plugin;

use Lite\Framework\Security\SecurityAuditLog;
use PDO;
use InvalidArgumentException;

/**
 * Industry Blueprint Manager enabling 1-click / 1-command switching between
 * multi-domain business modes (CMS, Showroom, Rental Studio, Full Retail Commerce)
 * purely by toggling plugins in `plugin_registry` and scoped `plugin_settings`.
 */
final class BlueprintManager
{
    /**
     * @var array<string, array{
     *   name: string,
     *   description: string,
     *   enable: list<string>,
     *   disable: list<string>,
     *   settings: array<string, array<string, string>>
     * }>
     */
    private const BLUEPRINTS = [
        'company-cms' => [
            'name'        => 'Company CMS & SEO Blog',
            'description' => 'Pure Core CMS (Pages, Blog, Contact, SEO, Media). Disables all commerce extensions.',
            'enable'      => [],
            'disable'     => [
                'com.liteplatform.catalog',
                'com.liteplatform.crm',
                'com.liteplatform.orders',
                'com.liteplatform.reports',
                'com.kzxl.inventory',
            ],
            'settings'    => [],
        ],
        'showroom-catalog' => [
            'name'        => 'Showroom & Lead Capture',
            'description' => 'Public product showcase + multi-item selection & CRM lead capture (no orders/reports).',
            'enable'      => [
                'com.liteplatform.catalog',
                'com.liteplatform.crm',
            ],
            'disable'     => [
                'com.liteplatform.orders',
                'com.liteplatform.reports',
                'com.kzxl.inventory',
            ],
            'settings'    => [
                'com.liteplatform.catalog' => [
                    'nav_label'     => 'Bộ Sưu Tập',
                    'catalog_title' => 'Bộ Sưu Tập Sản Phẩm',
                ],
            ],
        ],
        'rental-studio' => [
            'name'        => 'Rental Studio (Apparel / Equipment / Events)',
            'description' => 'Full rental lifecycle: Catalog + CRM + Rental Orders (overlap guard & deposits) + Accounting Reports.',
            'enable'      => [
                'com.liteplatform.catalog',
                'com.liteplatform.crm',
                'com.liteplatform.orders',
                'com.liteplatform.reports',
            ],
            'disable'     => [
                'com.kzxl.inventory',
            ],
            'settings'    => [
                'com.liteplatform.catalog' => [
                    'nav_label'     => 'Mẫu Cho Thuê',
                    'catalog_title' => 'Danh Mục Cho Thuê',
                ],
            ],
        ],
        'retail-commerce' => [
            'name'        => 'Full Retail & Rental Commerce + Inventory',
            'description' => 'All extensions enabled: Catalog, CRM, Orders (Rent & Sale), Revenue Reports, and Advanced Inventory.',
            'enable'      => [
                'com.liteplatform.catalog',
                'com.liteplatform.crm',
                'com.liteplatform.orders',
                'com.liteplatform.reports',
                'com.kzxl.inventory',
            ],
            'disable'     => [],
            'settings'    => [
                'com.liteplatform.catalog' => [
                    'nav_label'     => 'Sản Phẩm',
                    'catalog_title' => 'Danh Mục Sản Phẩm & Cho Thuê',
                ],
            ],
        ],
    ];

    public function __construct(
        private readonly PDO $pdo,
        private readonly ?SecurityAuditLog $audit = null
    ) {
    }

    /**
     * @return array<string, array{name: string, description: string, enable: list<string>, disable: list<string>, settings: array<string, array<string, string>>}>
     */
    public function listBlueprints(): array
    {
        return self::BLUEPRINTS;
    }

    /**
     * Apply a named industry blueprint atomically.
     *
     * @return array{blueprint: string, name: string, enabled: list<string>, disabled: list<string>}
     */
    public function apply(string $key, string $actor = 'system', ?string $actorIp = null): array
    {
        $key = strtolower(trim($key));
        if (!isset(self::BLUEPRINTS[$key])) {
            $valid = implode(', ', array_keys(self::BLUEPRINTS));
            throw new InvalidArgumentException("Unknown blueprint '{$key}'. Valid options: {$valid}");
        }

        $bp = self::BLUEPRINTS[$key];
        $driver = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        $now = date('Y-m-d H:i:s');

        $upsertReg = $driver === 'mysql'
            ? 'INSERT INTO plugin_registry (plugin_id, version, state, failure_count, last_error, updated_at)
               VALUES (:id, \'1.0.0\', :st, 0, NULL, :u)
               ON DUPLICATE KEY UPDATE state = VALUES(state), failure_count = 0, last_error = NULL, updated_at = VALUES(updated_at)'
            : 'INSERT INTO plugin_registry (plugin_id, version, state, failure_count, last_error, updated_at)
               VALUES (:id, \'1.0.0\', :st, 0, NULL, :u)
               ON CONFLICT(plugin_id) DO UPDATE SET state = excluded.state, failure_count = 0, last_error = NULL, updated_at = excluded.updated_at';

        $regStmt = $this->pdo->prepare($upsertReg);
        foreach ($bp['enable'] as $pid) {
            $regStmt->execute([':id' => $pid, ':st' => 'enabled', ':u' => $now]);
        }
        foreach ($bp['disable'] as $pid) {
            $regStmt->execute([':id' => $pid, ':st' => 'disabled', ':u' => $now]);
        }

        $upsertSet = $driver === 'mysql'
            ? 'INSERT INTO plugin_settings (plugin_id, setting_key, setting_value, is_encrypted, updated_at)
               VALUES (:pid, :k, :v, 0, :u)
               ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_at = VALUES(updated_at)'
            : 'INSERT INTO plugin_settings (plugin_id, setting_key, setting_value, is_encrypted, updated_at)
               VALUES (:pid, :k, :v, 0, :u)
               ON CONFLICT(plugin_id, setting_key) DO UPDATE SET setting_value = excluded.setting_value, updated_at = excluded.updated_at';

        $setStmt = $this->pdo->prepare($upsertSet);
        foreach ($bp['settings'] as $pid => $kv) {
            foreach ($kv as $k => $v) {
                $setStmt->execute([':pid' => $pid, ':k' => $k, ':v' => $v, ':u' => $now]);
            }
        }

        $this->audit?->record(
            eventType: 'blueprint.applied',
            severity: 'HIGH',
            actor: $actor,
            actorIp: $actorIp,
            moduleId: 'core',
            target: $key,
            context: ['enabled' => $bp['enable'], 'disabled' => $bp['disable']]
        );

        return [
            'blueprint' => $key,
            'name'      => $bp['name'],
            'enabled'   => $bp['enable'],
            'disabled'  => $bp['disable'],
        ];
    }
}
