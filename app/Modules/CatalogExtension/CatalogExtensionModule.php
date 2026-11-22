<?php

declare(strict_types=1);

namespace App\Modules\CatalogExtension;

use App\Modules\CatalogExtension\Controllers\CatalogController;
use App\Modules\CatalogExtension\Entities\CatalogItem;
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
use Lite\Framework\Middleware\RateLimitMiddleware;
use Lite\Framework\Seo\SitemapGenerator;
use LiteAdmin\AdminDashboard;
use LiteCache\CacheInterface;
use PDO;

/**
 * Standalone Catalog Showcase Extension (`com.liteplatform.catalog`, zero dependencies).
 * Works independently for pure catalog/showroom websites or composes with CRM, Orders, and Reports.
 */
final class CatalogExtensionModule implements
    HardenedPluginInterface,
    SecurityAwareModuleInterface,
    WebModuleInterface,
    AdminAwareModuleInterface
{
    public const MODULE_ID = 'com.liteplatform.catalog';

    private ?PluginManifest $cachedManifest = null;
    private ?PluginContext $context = null;

    public function manifest(): PluginManifest
    {
        return $this->cachedManifest ??= new PluginManifest(
            id: self::MODULE_ID,
            name: 'Catalog Extension',
            version: '1.0.0',
            description: 'Standalone domain-agnostic product & rental catalog with dynamic attributes, gallery, wishlist drawer, and inquiry hooks.',
            capabilities: [
                Capability::DB_READ,
                Capability::DB_WRITE,
                Capability::CACHE_SCOPED,
                Capability::MAIL_SEND,
                Capability::ADMIN_UI,
                Capability::PUBLIC_ROUTES,
                Capability::HOOKS_SUBSCRIBE,
                Capability::FILES_UPLOAD,
            ],
            routePrefixes: ['/catalog'],
            tablePrefixes: ['retail_items']
        );
    }

    public function getId(): string { return self::MODULE_ID; }
    public function getName(): string { return $this->manifest()->name; }
    public function getVersion(): string { return $this->manifest()->version; }
    public function getManifest(): array { return $this->manifest()->toArray(); }

    public function getPermissions(): array
    {
        return [
            'catalog.read'  => 'View catalog items',
            'catalog.write' => 'Create, edit, and delete catalog items',
        ];
    }

    public function getDefaultRoleGrants(): array
    {
        return [
            'manager' => ['catalog.read', 'catalog.write'],
            'staff'   => ['catalog.read'],
            'viewer'  => ['catalog.read'],
        ];
    }

    public function getMigrationsPath(): ?string { return __DIR__ . '/migrations'; }
    public function getViewNamespace(): ?string { return 'catalog'; }
    public function getViewsPath(): ?string { return __DIR__ . '/templates'; }

    public function getNavLinks(): array
    {
        $label = 'Catalog';
        if ($this->context !== null) {
            try {
                $label = $this->context->getSetting('nav_label', 'Catalog');
            } catch (\Throwable) {
            }
        }
        return [['label' => $label, 'href' => '/catalog']];
    }

    public function registerSitemap(SitemapGenerator $sitemap, Container $container): void
    {
        $sitemap->addUrl('/catalog', changefreq: 'daily', priority: '0.9');
        if ($container->has(PDO::class)) {
            $sitemap->addProvider(static function () use ($container): array {
                try {
                    $rows = $container->get(PDO::class)->query("SELECT slug, updated_at, created_at FROM retail_items WHERE status = 'active' ORDER BY id DESC")?->fetchAll(PDO::FETCH_ASSOC) ?: [];
                } catch (\Throwable) {
                    return [];
                }
                $out = [];
                foreach ($rows as $row) {
                    $slug = trim((string)($row['slug'] ?? ''));
                    if ($slug !== '') {
                        $out[] = [
                            'loc'        => '/catalog/' . rawurlencode($slug),
                            'priority'   => '0.8',
                            'changefreq' => 'weekly',
                            'lastmod'    => substr((string)($row['updated_at'] ?: $row['created_at'] ?: date('Y-m-d')), 0, 10),
                        ];
                    }
                }
                return $out;
            });
        }
    }

    public function bootPlugin(PluginContext $context): void
    {
        $this->context = $context;

        $context->registerService(
            CatalogController::class,
            static fn(): CatalogController => new CatalogController($context)
        );

        if ($context->hasService(CacheInterface::class)) {
            $context->registerService('catalog.inquiry.ratelimit', static fn(): RateLimitMiddleware => new RateLimitMiddleware(
                cache: $context->getService(CacheInterface::class),
                maxRequests: 15,
                decaySeconds: 60,
                bucket: 'catalog_inquiry'
            ));
        } else {
            $context->registerService('catalog.inquiry.ratelimit', static fn(): callable => static fn($req, $handler) => $handler->handle($req));
        }
    }

    public function registerServices(Container $container): void {}

    public function registerRoutes(RouteRegistryInterface $routes): void
    {
        $routes->get('/catalog', [CatalogController::class, 'index'], name: 'catalog.index', moduleId: self::MODULE_ID);
        $routes->post('/catalog/inquiry', [CatalogController::class, 'submitInquiry'], middlewares: ['catalog.inquiry.ratelimit'], name: 'catalog.inquiry', moduleId: self::MODULE_ID);
        $routes->get('/catalog/{slug}', [CatalogController::class, 'show'], name: 'catalog.show', moduleId: self::MODULE_ID);
    }

    public function registerAdmin(AdminDashboard $admin, Container $container): void
    {
        $admin->register(CatalogItem::class);
    }

    public function install(KernelInterface $kernel): void {}
    public function uninstall(KernelInterface $kernel): void {}
}
