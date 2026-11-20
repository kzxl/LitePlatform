<?php

declare(strict_types=1);

namespace Tests;

use Lite\Framework\Cms\SiteSettings;
use Lite\Framework\Security\AdminToken;
use Lite\Framework\Security\CsrfGuard;
use Lite\Framework\Seo\SitemapGenerator;
use Lite\Framework\Testing\TestCase;
use LiteAdmin\Field\FieldRegistry;
use PDO;

/**
 * Verifies LiteAdmin v2 (WordPress-style 2-column editor + Laravel/Filament extensibility)
 * and universal CMS pages (/admin/settings, /admin/media, dynamic /{slug} Pages, Categories).
 */
class LiteAdminV2AndCmsTest extends TestCase
{
    public function testPostCreatePageRenders2ColumnEditorWysiwygSlugSeoAndMediaPicker(): void
    {
        $adminCookie = $this->makeAdminCookie();

        $req = $this->factory->createServerRequest('GET', '/admin/post/create')
            ->withCookieParams(['lite_admin_auth' => $adminCookie]);
        $res = $this->app->handle($req);
        $this->assertStatus($res, 200);

        $html = (string)$res->getBody();
        $this->assertStringContainsString('admin-form-grid', $html);
        $this->assertStringContainsString('data-slug-source="title"', $html);
        $this->assertStringContainsString('contenteditable="true"', $html);
        $this->assertStringContainsString('seo-preview-title', $html);
        $this->assertStringContainsString('<select name="status"', $html);
        $this->assertStringContainsString('<select name="categoryId"', $html);
        $this->assertStringContainsString('data-open-media="featuredImage"', $html);
        $this->assertStringContainsString('/admin/settings', $html);
        $this->assertStringContainsString('/admin/media', $html);
    }

    public function testFieldRegistrySupportsCustomPluggableFieldTypes(): void
    {
        $registry = new FieldRegistry();
        $registry->register('color', static fn(array $field, mixed $val): string =>
            '<input type="color" name="' . $field['property'] . '" value="' . ($val ?: '#000000') . '">'
        );

        $this->assertTrue($registry->has('color'));
        $rendered = $registry->render(['property' => 'brandColor', 'type' => 'color'], '#2563eb');
        $this->assertStringContainsString('type="color"', $rendered);
        $this->assertStringContainsString('#2563eb', $rendered);
    }

    public function testAdminSettingsAndMediaCustomPagesWorkEndToEnd(): void
    {
        $adminCookie = $this->makeAdminCookie();

        // 1. GET /admin/settings
        $getRes = $this->app->handle(
            $this->factory->createServerRequest('GET', '/admin/settings')
                ->withCookieParams(['lite_admin_auth' => $adminCookie])
        );
        $this->assertStatus($getRes, 200);
        $html = (string)$getRes->getBody();
        $this->assertStringContainsString('Site Settings', $html);

        preg_match('/name="_csrf"\s+value="([^"]+)"/', $html, $m);
        $csrf = $m[1] ?? '';
        $this->assertNotEmpty($csrf);

        // 2. POST /admin/settings saves into SiteSettings
        /** @var SiteSettings $settings */
        $settings = $this->app->getContainer()->get(SiteSettings::class);
        $prevHotline = $settings->get('hotline');

        try {
            $postRes = $this->app->handle(
                $this->factory->createServerRequest('POST', '/admin/settings')
                    ->withCookieParams([
                        'lite_admin_auth'      => $adminCookie,
                        CsrfGuard::COOKIE_NAME => $csrf,
                    ])
                    ->withParsedBody([
                        '_csrf'   => $csrf,
                        'hotline' => '0988 777 666',
                    ])
            );
            $this->assertStatus($postRes, 200);
            $this->assertEquals('0988 777 666', $settings->get('hotline'));
        } finally {
            $settings->set('hotline', $prevHotline);
        }

        // 3. GET /admin/media & /admin/media/json
        $mediaPage = $this->app->handle(
            $this->factory->createServerRequest('GET', '/admin/media')
                ->withCookieParams(['lite_admin_auth' => $adminCookie])
        );
        $this->assertStatus($mediaPage, 200);
        $this->assertStringContainsString('Media Library', (string)$mediaPage->getBody());

        $mediaJson = $this->app->handle(
            $this->factory->createServerRequest('GET', '/admin/media/json')
                ->withCookieParams(['lite_admin_auth' => $adminCookie])
        );
        $this->assertStatus($mediaJson, 200);
        $decoded = json_decode((string)$mediaJson->getBody(), true);
        $this->assertTrue($decoded['ok']);
    }

    public function testDynamicPageEntityRendersAtRootSlugWithSanitizationAndSitemap(): void
    {
        /** @var PDO $pdo */
        $pdo = $this->app->getContainer()->get(PDO::class);
        $slug = 'gioi-thieu-' . bin2hex(random_bytes(3));

        $stmt = $pdo->prepare(
            "INSERT INTO pages (title, slug, content, meta_title, meta_description, show_in_nav, status)
             VALUES (:title, :slug, :content, :mt, :md, 1, 'published')"
        );
        $stmt->execute([
            ':title'   => 'Giới thiệu công ty',
            ':slug'    => $slug,
            ':content' => '<h2>Về chúng tôi</h2><p>Uy tín &amp; chuyên nghiệp.</p><script>alert(9)</script>',
            ':mt'      => 'Giới thiệu',
            ':md'      => 'Thông tin doanh nghiệp chuẩn SEO.',
        ]);

        try {
            $res = $this->get('/' . $slug);
            $this->assertStatus($res, 200);
            $html = (string)$res->getBody();
            $this->assertStringContainsString('Giới thiệu công ty', $html);
            $this->assertStringContainsString('<h2>Về chúng tôi</h2>', $html);
            $this->assertStringNotContainsString('<script>alert(9)</script>', $html);

            /** @var SitemapGenerator $sitemap */
            $sitemap = $this->app->getContainer()->get(SitemapGenerator::class);
            $sitemap->invalidateCache();
            $xml = $sitemap->renderXml(useCache: false);
            $this->assertStringContainsString('/' . $slug, $xml);
        } finally {
            $del = $pdo->prepare('DELETE FROM pages WHERE slug = :slug');
            $del->execute([':slug' => $slug]);
        }
    }

    private function makeAdminCookie(): string
    {
        $config = (array)$this->app->getContainer()->get('config');
        $secret = (string)($config['admin']['auth_secret'] ?? $config['security']['csrf_secret']);
        return AdminToken::issue('admin@liteplatform.io', $secret, 3600);
    }
}
