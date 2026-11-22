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

        // Register composable Homepage Section (`catalog_featured`) into Core SectionRegistry
        if ($context->hasService(\Lite\Framework\Theme\SectionRegistry::class)) {
            /** @var \Lite\Framework\Theme\SectionRegistry $registry */
            $registry = $context->getService(\Lite\Framework\Theme\SectionRegistry::class);
            $registry->register(
                type: 'catalog_featured',
                label: 'Catalog Featured Grid',
                category: 'Catalog',
                defaultProps: ['heading' => 'Sản phẩm & Mẫu nổi bật', 'limit' => 8, 'columns' => 4, 'listing_type' => 'all'],
                renderer: static function (array $props) use ($context): string {
                    $limit = max(1, min(24, (int)($props['limit'] ?? 8)));
                    $cols = max(2, min(4, (int)($props['columns'] ?? 4)));
                    $mode = (string)($props['listing_type'] ?? 'all');
                    $heading = trim((string)($props['heading'] ?? 'Sản phẩm nổi bật'));

                    try {
                        $pdo = $context->db();
                        $where = "status = 'active'";
                        $params = [];
                        if (in_array($mode, ['rent', 'sale'], true)) {
                            $where .= " AND (listing_type = :lt OR listing_type = 'both')";
                            $params[':lt'] = $mode;
                        }
                        $stmt = $pdo->prepare("SELECT sku, name, slug, price, variant_summary, featured_image FROM retail_items WHERE {$where} ORDER BY id DESC LIMIT {$limit}");
                        $stmt->execute($params);
                        $items = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
                    } catch (\Throwable) {
                        return '';
                    }

                    if (empty($items)) {
                        return '';
                    }

                    $e = static fn(string $v): string => htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                    $minW = $cols >= 4 ? '215px' : ($cols === 3 ? '265px' : '320px');
                    $cards = '';
                    foreach ($items as $it) {
                        $slug = rawurlencode((string)$it['slug']);
                        $img = !empty($it['featured_image'])
                            ? '<img src="' . $e((string)$it['featured_image']) . '" alt="' . $e((string)$it['name']) . '" loading="lazy" style="width:100%;height:100%;object-fit:cover;display:block;">'
                            : '<span style="color:var(--text-secondary);font-size:0.82rem;">' . $e((string)$it['sku']) . '</span>';
                        $cards .= '<article class="card" style="padding:0;overflow:hidden;display:flex;flex-direction:column;">'
                            . '<a href="/catalog/' . $slug . '" style="aspect-ratio:var(--card-ratio, 3/4);background:var(--surface-hover);display:flex;align-items:center;justify-content:center;overflow:hidden;">' . $img . '</a>'
                            . '<div style="padding:0.9rem;display:flex;flex-direction:column;gap:0.35rem;flex:1;">'
                            . '<a href="/catalog/' . $slug . '" style="font-weight:700;color:var(--text-primary);text-decoration:none;font-size:0.95rem;">' . $e((string)$it['name']) . '</a>'
                            . '<div style="font-weight:800;color:var(--accent);margin-top:auto;">' . number_format((float)$it['price'], 0, ',', '.') . ' đ</div>'
                            . '</div></article>';
                    }

                    return '<section data-section="catalog_featured">'
                        . '<div class="section-head"><h2>' . $e($heading) . '</h2><a href="/catalog">Xem tất cả &rarr;</a></div>'
                        . '<div style="display:grid;grid-template-columns:repeat(auto-fill, minmax(' . $minW . ', 1fr));gap:1.15rem;">' . $cards . '</div>'
                        . '</section>';
                },
                moduleId: self::MODULE_ID
            );
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
