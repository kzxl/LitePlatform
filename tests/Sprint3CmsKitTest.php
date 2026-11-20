<?php

declare(strict_types=1);

namespace Tests;

use App\Entities\Post;
use Lite\Framework\Cms\SiteSettings;
use Lite\Framework\Media\MediaManager;
use Lite\Framework\Seo\SitemapGenerator;
use Lite\Framework\Testing\TestCase;
use LiteCache\CacheManager;
use PDO;

/**
 * Verifies Sprint 3 CMS Micro-Kit:
 * - SiteSettings (0-query LiteCache reads, upsert/delete, XSS-safe GA4/Pixel/GSC tags)
 * - MediaManager (UploadGuard + ImageOptimizer + DB ledger + safe file deletion)
 * - Vietnamese SEO Blog starter (Str::slug, Article/Breadcrumb JSON-LD, @sanitize XSS strip, dynamic Sitemap)
 */
class Sprint3CmsKitTest extends TestCase
{
    public function testSiteSettingsCachesReadsAndValidatesTrackingTags(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec('CREATE TABLE site_settings (setting_key VARCHAR(120) PRIMARY KEY, setting_value TEXT NULL, setting_group VARCHAR(60), updated_at DATETIME)');

        $settings = new SiteSettings($pdo, CacheManager::memory());
        $settings->setMany([
            'hotline'              => '0909 123 456',
            'ga4_id'               => 'G-ABC1234567',
            'meta_pixel_id'        => '123456789012345',
            'search_console_token' => 'abcDEF_1234567890',
        ], 'seo');

        $this->assertEquals('0909 123 456', $settings->get('hotline'));
        $this->assertEquals('fallback', $settings->get('non_existent', 'fallback'));

        $head = $settings->renderTrackingHead();
        $this->assertStringContainsString('G-ABC1234567', $head);
        $this->assertStringContainsString('123456789012345', $head);
        $this->assertStringContainsString('google-site-verification', $head);

        // Malicious GA4 / Pixel injection must be rejected silently
        $settings->set('ga4_id', "G-123');alert(1);//");
        $settings->set('meta_pixel_id', '123<script>');
        $badHead = $settings->renderTrackingHead();
        $this->assertStringNotContainsString('alert(1)', $badHead);
        $this->assertStringNotContainsString('123<script>', $badHead);
    }

    public function testMediaManagerUploadsOptimizesListsAndDeletesSafely(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec('CREATE TABLE media_assets (id INTEGER PRIMARY KEY AUTOINCREMENT, filename VARCHAR(180), original_name VARCHAR(255), relative_url VARCHAR(255), mime_type VARCHAR(80), width INT, height INT, bytes INT, alt_text VARCHAR(255), created_at DATETIME)');

        $tmpUploads = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'lite_media_test_' . bin2hex(random_bytes(4));
        mkdir($tmpUploads, 0777, true);

        $tmpSrc = $tmpUploads . DIRECTORY_SEPARATOR . 'raw.bin';
        file_put_contents($tmpSrc, base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg=='
        ));

        try {
            $manager = new MediaManager($pdo, $tmpUploads, '/uploads');
            $asset = $manager->upload($tmpSrc, 'banner-tet.png', 'Banner Tết 2026', maxWidth: 1200);

            $this->assertGreaterThan(0, $asset['id']);
            $this->assertEquals(1, $asset['width']);
            $this->assertEquals(1, $asset['height']);
            $this->assertStringContainsString('loading="lazy"', $asset['img_tag']);
            $this->assertStringContainsString('width="1" height="1"', $asset['img_tag']);

            $list = $manager->list();
            $this->assertCount(1, $list);
            $this->assertEquals('banner-tet.png', $list[0]['original_name']);

            $this->assertTrue($manager->delete($asset['id']));
            $this->assertCount(0, $manager->list());
        } finally {
            @unlink($tmpSrc);
            $it = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($tmpUploads, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST
            );
            foreach ($it as $item) {
                $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
            }
            @rmdir($tmpUploads);
        }
    }

    public function testVietnameseBlogSlugRenderingSanitizationAndSitemapIntegration(): void
    {
        $slug = Post::generateSlug('Hướng Dẫn Tối Ưu SEO Shared Hosting 2026!');
        $this->assertEquals('huong-dan-toi-uu-seo-shared-hosting-2026', $slug);

        /** @var PDO $pdo */
        $pdo = $this->app->getContainer()->get(PDO::class);
        $testSlug = 'bai-viet-test-' . bin2hex(random_bytes(3));

        $stmt = $pdo->prepare(
            "INSERT INTO posts (title, slug, excerpt, content, meta_title, meta_description, status, published_at)
             VALUES (:title, :slug, :excerpt, :content, :mt, :md, 'published', :pub)"
        );
        $stmt->execute([
            ':title'   => 'Tối ưu PHP trên Shared Hosting',
            ':slug'    => $testSlug,
            ':excerpt' => 'Tóm tắt ngắn chuẩn SEO.',
            ':content' => '<p>Nội dung <strong>chuẩn</strong></p><script>alert(1)</script>',
            ':mt'      => 'Tối ưu PHP Shared Hosting',
            ':md'      => 'Hướng dẫn chạy PHP siêu nhẹ với TTFB dưới 50ms.',
            ':pub'     => date('Y-m-d H:i:s'),
        ]);

        try {
            // 1. Blog index lists the published post
            $listRes = $this->get('/blog');
            $this->assertStatus($listRes, 200);
            $this->assertStringContainsString($testSlug, (string)$listRes->getBody());

            // 2. Article detail renders Article JSON-LD & strips <script> via @sanitize
            $showRes = $this->get('/blog/' . $testSlug);
            $this->assertStatus($showRes, 200);
            $html = (string)$showRes->getBody();
            $this->assertStringContainsString('"@type":"Article"', $html);
            $this->assertStringContainsString('"@type":"BreadcrumbList"', $html);
            $this->assertStringContainsString('<strong>chuẩn</strong>', $html);
            $this->assertStringNotContainsString('<script>alert(1)</script>', $html);

            // 3. Dynamic Sitemap automatically includes the published blog post
            /** @var SitemapGenerator $sitemap */
            $sitemap = $this->app->getContainer()->get(SitemapGenerator::class);
            $sitemap->invalidateCache();
            $xml = $sitemap->renderXml(useCache: false);
            $this->assertStringContainsString('/blog/' . $testSlug, $xml);
        } finally {
            $del = $pdo->prepare('DELETE FROM posts WHERE slug = :slug');
            $del->execute([':slug' => $testSlug]);
        }
    }
}
