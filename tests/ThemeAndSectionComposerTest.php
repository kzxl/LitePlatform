<?php

declare(strict_types=1);

namespace Tests;

use Lite\Framework\Cms\SiteSettings;
use Lite\Framework\Security\AdminToken;
use Lite\Framework\Security\CsrfGuard;
use Lite\Framework\Theme\SectionRegistry;
use Lite\Framework\Theme\ThemeManager;
use Lite\Framework\Testing\TestCase;
use LiteView\ViewEngine;
use PDO;

/**
 * Verifies the 3-Tier Appearance & Layout System:
 * - Tier 1: Design Tokens CSS variables + strict CSS injection allowlist
 * - Tier 2: Section Composer (ordering, Core + Plugin sections, graceful skip for disabled plugins)
 * - Tier 3: Child-Theme packages (`themes/editorial/`) switched live via /admin/appearance
 */
class ThemeAndSectionComposerTest extends TestCase
{
    public function testThemeTokensSanitizeInjectionAndExportImportPresetJson(): void
    {
        $container = $this->app->getContainer();
        /** @var SiteSettings $settings */
        $settings = $container->get(SiteSettings::class);
        /** @var ThemeManager $tm */
        $tm = $container->get(ThemeManager::class);

        $prevTheme = $settings->get(ThemeManager::SETTING_ACTIVE_THEME, '');
        $prevTokens = $settings->get(ThemeManager::SETTING_TOKENS, '');
        $prevSections = $settings->get(ThemeManager::SETTING_HOME_SECTIONS, '');

        try {
            // Attempt CSS injection via accent & radius -> must be rejected and keep valid defaults
            $tm->saveTokens([
                'accent'       => '#8c2d3b',
                'bg_color'     => 'red; } body { display:none',
                'radius'       => '999px; background:url(javascript:alert(1))',
                'header_style' => 'center',
                'card_ratio'   => '1/1',
            ]);

            $css = $tm->renderCssVariables();
            $this->assertStringContainsString('--accent:#8c2d3b;', $css);
            $this->assertStringContainsString('--card-ratio:1/1;', $css);
            $this->assertStringNotContainsString('display:none', $css);
            $this->assertStringNotContainsString('javascript:', $css);

            // Export & re-import preset JSON cleanly
            $tm->setActiveTheme('editorial');
            $exported = $tm->exportPresetJson();
            $this->assertStringContainsString('"theme": "editorial"', $exported);

            $tm->setActiveTheme('default');
            $this->assertTrue($tm->importPresetJson($exported));
            $this->assertEquals('editorial', $tm->getActiveThemeSlug());
        } finally {
            $settings->set(ThemeManager::SETTING_ACTIVE_THEME, $prevTheme);
            $settings->set(ThemeManager::SETTING_TOKENS, $prevTokens);
            $settings->set(ThemeManager::SETTING_HOME_SECTIONS, $prevSections);
        }
    }

    public function testSectionComposerRendersReorderedCoreAndPluginSectionsAndSkipsMissingPlugins(): void
    {
        $container = $this->app->getContainer();
        /** @var SectionRegistry $registry */
        $registry = $container->get(SectionRegistry::class);
        /** @var PDO $pdo */
        $pdo = $container->get(PDO::class);

        $this->assertTrue($registry->has('hero'));
        $this->assertTrue($registry->has('blog_latest'));
        $this->assertTrue($registry->has('features_grid'));
        $this->assertTrue($registry->has('cta_banner'));
        $this->assertTrue($registry->has('catalog_featured'), 'CatalogExtension must register catalog_featured section.');

        $suffix = substr(uniqid(), -6);
        $sku = 'SEC-' . $suffix;
        $slug = 'sec-item-' . $suffix;

        try {
            $pdo->prepare("INSERT INTO retail_items (sku, name, slug, listing_type, price, status) VALUES (:sku, :n, :s, 'rent', 480000, 'active')")
                ->execute([':sku' => $sku, ':n' => 'Featured Section Item ' . $suffix, ':s' => $slug]);

            $html = $registry->renderSections([
                ['type' => 'cta_banner', 'enabled' => true, 'props' => ['heading' => 'CTA_FIRST_ORDER']],
                ['type' => 'non_existent_disabled_plugin', 'enabled' => true, 'props' => []],
                ['type' => 'catalog_featured', 'enabled' => true, 'props' => ['heading' => 'CATALOG_SECOND_ORDER', 'limit' => 4, 'columns' => 4]],
                ['type' => 'hero', 'enabled' => false, 'props' => ['title' => 'HIDDEN_HERO']],
            ]);

            $this->assertStringContainsString('CTA_FIRST_ORDER', $html);
            $this->assertStringContainsString('CATALOG_SECOND_ORDER', $html);
            $this->assertStringContainsString('Featured Section Item ' . $suffix, $html);
            $this->assertStringNotContainsString('HIDDEN_HERO', $html);
            $this->assertLessThan(strpos($html, 'CATALOG_SECOND_ORDER'), strpos($html, 'CTA_FIRST_ORDER'));
        } finally {
            $pdo->prepare('DELETE FROM retail_items WHERE slug = :s')->execute([':s' => $slug]);
        }
    }

    public function testAdminAppearanceStudioSwitchesEditorialChildThemeAndRendersOnHomepage(): void
    {
        $container = $this->app->getContainer();
        /** @var SiteSettings $settings */
        $settings = $container->get(SiteSettings::class);
        /** @var ThemeManager $tm */
        $tm = $container->get(ThemeManager::class);
        /** @var ViewEngine $view */
        $view = $container->get(ViewEngine::class);

        $prevTheme = $settings->get(ThemeManager::SETTING_ACTIVE_THEME, '');
        $prevTokens = $settings->get(ThemeManager::SETTING_TOKENS, '');
        $prevSections = $settings->get(ThemeManager::SETTING_HOME_SECTIONS, '');

        $adminCookie = $this->makeAdminCookie();

        try {
            // 1. GET /admin/appearance renders composer + style presets + editorial theme option
            $getRes = $this->app->handle(
                $this->factory->createServerRequest('GET', '/admin/appearance')
                    ->withCookieParams(['lite_admin_auth' => $adminCookie])
            );
            $this->assertStatus($getRes, 200);
            $html = (string)$getRes->getBody();
            $this->assertStringContainsString('Appearance &amp; Layout Studio', $html);
            $this->assertStringContainsString('Editorial Boutique', $html);
            $this->assertStringContainsString('catalog_featured', $html);

            preg_match('/name="_csrf"\s+value="([^"]+)"/', $html, $m);
            $csrf = $m[1] ?? '';
            $this->assertNotEmpty($csrf);

            // 2. POST /admin/appearance applies editorial-boutique preset
            $postRes = $this->app->handle(
                $this->factory->createServerRequest('POST', '/admin/appearance')
                    ->withCookieParams([
                        'lite_admin_auth'      => $adminCookie,
                        CsrfGuard::COOKIE_NAME => $csrf,
                    ])
                    ->withParsedBody([
                        '_csrf'  => $csrf,
                        'action' => 'apply_preset',
                        'preset' => 'editorial-boutique',
                    ])
            );
            $this->assertStatus($postRes, 200);
            $this->assertEquals('editorial', $tm->getActiveThemeSlug());

            // 3. Apply resolved Child-Theme path to ViewEngine (as ViewProvider::boot does on next request) and verify homepage!
            $view->setThemePath($tm->resolveActiveThemePath());
            $view->share('themeCss', $tm->renderCssVariables());

            $homeRes = $this->app->handle($this->factory->createServerRequest('GET', '/'));
            $this->assertStatus($homeRes, 200);
            $homeHtml = (string)$homeRes->getBody();
            $this->assertStringContainsString('data-theme="editorial"', $homeHtml);
            $this->assertStringContainsString('--accent:#8c2d3b;', $homeHtml);
        } finally {
            $settings->set(ThemeManager::SETTING_ACTIVE_THEME, $prevTheme);
            $settings->set(ThemeManager::SETTING_TOKENS, $prevTokens);
            $settings->set(ThemeManager::SETTING_HOME_SECTIONS, $prevSections);
            $view->setThemePath($tm->resolveActiveThemePath());
            $view->share('themeCss', $tm->renderCssVariables());
        }
    }

    private function makeAdminCookie(): string
    {
        $config = (array)$this->app->getContainer()->get('config');
        $secret = (string)($config['admin']['auth_secret'] ?? $config['security']['csrf_secret'] ?? AdminToken::DEFAULT_INSECURE_SECRET);
        return AdminToken::issue('admin', $secret, 3600);
    }
}
