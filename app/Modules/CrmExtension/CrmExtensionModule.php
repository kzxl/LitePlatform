<?php

declare(strict_types=1);

namespace App\Modules\CrmExtension;

use App\Modules\CrmExtension\Entities\Customer;
use App\Modules\CrmExtension\Services\CustomerService;
use DI\Container;
use Lite\Framework\Core\KernelInterface;
use Lite\Framework\Core\Plugin\AdminAwareModuleInterface;
use Lite\Framework\Core\Plugin\Capability;
use Lite\Framework\Core\Plugin\HardenedPluginInterface;
use Lite\Framework\Core\Plugin\PluginContext;
use Lite\Framework\Core\Plugin\PluginManifest;
use Lite\Framework\Core\Plugin\SecurityAwareModuleInterface;
use Lite\Framework\Core\Plugin\WebModuleInterface;
use Lite\Framework\Core\Routing\RouteRegistryInterface;
use Lite\Framework\Seo\SitemapGenerator;
use LiteAdmin\AdminDashboard;

/**
 * Standalone Customer CRM Extension (`com.liteplatform.crm`, zero dependencies).
 * Automatically listens to `catalog.inquiry.submitted` via HookBus to capture customer profiles.
 */
final class CrmExtensionModule implements
    HardenedPluginInterface,
    SecurityAwareModuleInterface,
    WebModuleInterface,
    AdminAwareModuleInterface
{
    public const MODULE_ID = 'com.liteplatform.crm';

    private ?PluginManifest $cachedManifest = null;

    public function manifest(): PluginManifest
    {
        return $this->cachedManifest ??= new PluginManifest(
            id: self::MODULE_ID,
            name: 'Customer CRM Extension',
            version: '1.0.0',
            description: 'Standalone Customer CRM with phone deduplication, profile specs, and HookBus auto-capture from catalog inquiries.',
            capabilities: [
                Capability::DB_READ,
                Capability::DB_WRITE,
                Capability::ADMIN_UI,
                Capability::HOOKS_SUBSCRIBE,
            ],
            tablePrefixes: ['retail_customers']
        );
    }

    public function getId(): string { return self::MODULE_ID; }
    public function getName(): string { return $this->manifest()->name; }
    public function getVersion(): string { return $this->manifest()->version; }
    public function getManifest(): array { return $this->manifest()->toArray(); }

    public function getPermissions(): array
    {
        return [
            'crm.customers.read'  => 'View customer profiles',
            'crm.customers.write' => 'Create and edit customer records',
        ];
    }

    public function getDefaultRoleGrants(): array
    {
        return [
            'manager' => ['crm.customers.read', 'crm.customers.write'],
            'staff'   => ['crm.customers.read', 'crm.customers.write'],
            'viewer'  => ['crm.customers.read'],
        ];
    }

    public function getMigrationsPath(): ?string { return __DIR__ . '/migrations'; }
    public function getViewNamespace(): ?string { return null; }
    public function getViewsPath(): ?string { return null; }
    public function getNavLinks(): array { return []; }
    public function registerSitemap(SitemapGenerator $sitemap, Container $container): void {}

    public function bootPlugin(PluginContext $context): void
    {
        $service = new CustomerService($context->db());
        $context->registerService(CustomerService::class, static fn(): CustomerService => $service);

        // Decoupled cross-extension integration via HookBus!
        $context->addAction('catalog.inquiry.submitted', static function (array $payload) use ($service): void {
            $name = trim((string)($payload['name'] ?? ''));
            $phone = trim((string)($payload['phone'] ?? ''));
            if ($name !== '' && $phone !== '') {
                $service->upsertByPhone(
                    $name,
                    $phone,
                    (string)($payload['chat_handle'] ?? $phone),
                    (string)($payload['notes'] ?? '')
                );
            }
        });
    }

    public function registerServices(Container $container): void {}
    public function registerRoutes(RouteRegistryInterface $routes): void {}

    public function registerAdmin(AdminDashboard $admin, Container $container): void
    {
        $admin->register(Customer::class);
    }

    public function install(KernelInterface $kernel): void {}
    public function uninstall(KernelInterface $kernel): void {}
}
