<?php

declare(strict_types=1);

namespace Lite\Framework\Admin;

use DI\Container;
use Lite\Framework\Cms\SiteSettings;
use Lite\Framework\Media\MediaManager;
use Lite\Framework\Middleware\PageCacheMiddleware;
use LiteAdmin\AdminDashboard;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Throwable;

/**
 * Built-in WordPress-style Custom Admin Pages for Site Settings (/admin/settings)
 * and Visual Media Library (/admin/media + JSON picker endpoints).
 */
final class CmsAdminController
{
    public function __construct(private readonly Container $container)
    {
    }

    public function showSettings(Request $request, Response $response): Response
    {
        return $this->renderSettings($request, $response);
    }

    private function renderSettings(Request $request, Response $response, ?string $flashSuccess = null): Response
    {
        /** @var AdminDashboard $admin */
        $admin = $this->container->get(AdminDashboard::class);
        /** @var SiteSettings $settings */
        $settings = $this->container->get(SiteSettings::class);
        $csrf = htmlspecialchars((string)$request->getAttribute('csrf_token', ''), ENT_QUOTES, 'UTF-8');
        $e = static fn(string $v): string => htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        $siteName = $e($settings->get('site_name', (string)($this->container->get('config')['name'] ?? 'LitePlatform')));
        $tagline = $e($settings->get('tagline'));
        $hotline = $e($settings->get('hotline'));
        $email = $e($settings->get('email'));
        $address = $e($settings->get('address'));
        $ga4 = $e($settings->get('ga4_id'));
        $pixel = $e($settings->get('meta_pixel_id'));
        $gsc = $e($settings->get('search_console_token'));
        $showProducts = $settings->get('show_home_products', '0') === '1' ? 'checked' : '';

        $content = <<<HTML
<div class="page-header">
    <div>
        <h1>Site Settings</h1>
        <p style="color:var(--text-muted);font-size:0.875rem;">Cached on OpCache (0 DB queries per page load).</p>
    </div>
</div>
<form method="POST" action="/admin/settings">
    <input type="hidden" name="_csrf" value="{$csrf}">
    <div class="admin-form-grid">
        <div>
            <div class="card">
                <div class="section-card-title">General Identity</div>
                <div class="form-group">
                    <label>Site Name</label>
                    <input type="text" name="site_name" value="{$siteName}" class="form-control" required>
                </div>
                <div class="form-group">
                    <label>Tagline / SEO Description</label>
                    <input type="text" name="tagline" value="{$tagline}" class="form-control">
                </div>
                <div class="form-group">
                    <label>Hotline</label>
                    <input type="text" name="hotline" value="{$hotline}" class="form-control" placeholder="0909 123 456">
                </div>
                <div class="form-group">
                    <label>Contact Email</label>
                    <input type="email" name="email" value="{$email}" class="form-control" placeholder="hello@domain.com">
                </div>
                <div class="form-group">
                    <label>Address</label>
                    <input type="text" name="address" value="{$address}" class="form-control">
                </div>
            </div>
            <div class="card">
                <div class="section-card-title">SEO Verification &amp; Analytics</div>
                <div class="form-group">
                    <label>Google Analytics 4 ID (G-XXXXXXXXXX)</label>
                    <input type="text" name="ga4_id" value="{$ga4}" class="form-control" placeholder="G-ABC1234567">
                </div>
                <div class="form-group">
                    <label>Meta Pixel ID (numeric)</label>
                    <input type="text" name="meta_pixel_id" value="{$pixel}" class="form-control" placeholder="123456789012345">
                </div>
                <div class="form-group">
                    <label>Google Search Console Verification Token</label>
                    <input type="text" name="search_console_token" value="{$gsc}" class="form-control">
                </div>
            </div>
        </div>
        <div>
            <div class="card">
                <div class="section-card-title">Homepage Sections</div>
                <div class="form-group">
                    <input type="hidden" name="show_home_products" value="0">
                    <label style="display:flex;align-items:center;gap:0.5rem;font-weight:500;cursor:pointer;">
                        <input type="checkbox" name="show_home_products" value="1" {$showProducts} style="width:18px;height:18px;">
                        <span>Show Products section on Homepage</span>
                    </label>
                    <div class="help-text">Disable for pure Blog, Portfolio, or Company Profile websites.</div>
                </div>
                <button type="submit" class="btn btn-primary" style="width:100%;justify-content:center;margin-top:1rem;">Save Settings</button>
            </div>
        </div>
    </div>
</form>
HTML;

        $html = $admin->getHtmlRenderer()->layout('Site Settings', $content, 'settings', flashSuccess: $flashSuccess);
        $response->getBody()->write($html);
        return $response->withHeader('Content-Type', 'text/html; charset=UTF-8');
    }

    public function saveSettings(Request $request, Response $response): Response
    {
        /** @var SiteSettings $settings */
        $settings = $this->container->get(SiteSettings::class);
        $body = (array)$request->getParsedBody();

        $allowed = [
            'site_name', 'tagline', 'hotline', 'email', 'address',
            'ga4_id', 'meta_pixel_id', 'search_console_token', 'show_home_products',
        ];
        $pairs = [];
        foreach ($allowed as $key) {
            if (array_key_exists($key, $body)) {
                $pairs[$key] = trim((string)$body[$key]);
            }
        }

        $settings->setMany($pairs, 'general');

        if ($this->container->has(PageCacheMiddleware::class)) {
            $this->container->get(PageCacheMiddleware::class)->invalidateAll();
        }

        return $this->renderSettings($request, $response, 'Settings saved and cache refreshed.');
    }

    public function showMedia(Request $request, Response $response): Response
    {
        /** @var AdminDashboard $admin */
        $admin = $this->container->get(AdminDashboard::class);
        /** @var MediaManager $media */
        $media = $this->container->get(MediaManager::class);
        $items = $media->list(60, 0);
        $csrf = htmlspecialchars((string)$request->getAttribute('csrf_token', ''), ENT_QUOTES, 'UTF-8');

        $tiles = '';
        if ($items === []) {
            $tiles = '<p style="color:var(--text-muted);">No media files uploaded yet. Upload your first image above.</p>';
        } else {
            foreach ($items as $item) {
                $url = htmlspecialchars((string)$item['relative_url'], ENT_QUOTES, 'UTF-8');
                $name = htmlspecialchars((string)$item['original_name'], ENT_QUOTES, 'UTF-8');
                $id = (int)$item['id'];
                $dims = (int)$item['width'] . 'x' . (int)$item['height'];
                $kb = round(((int)$item['bytes']) / 1024, 1);

                $tiles .= <<<HTML
<div class="card" style="padding:0.75rem;margin-bottom:0;display:flex;flex-direction:column;gap:0.5rem;">
    <img src="{$url}" alt="{$name}" style="width:100%;height:130px;object-fit:cover;border-radius:6px;background:var(--bg-main);">
    <div style="font-size:0.8rem;font-weight:600;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;" title="{$name}">{$name}</div>
    <div style="font-size:0.74rem;color:var(--text-muted);">{$dims} &middot; {$kb} KB</div>
    <input type="text" value="{$url}" class="form-control" readonly onclick="this.select();" style="font-size:0.75rem;padding:0.35rem 0.5rem;">
    <form method="POST" action="/admin/media/delete/{$id}" onsubmit="return confirm('Delete this file?');">
        <input type="hidden" name="_csrf" value="{$csrf}">
        <button type="submit" class="btn btn-danger btn-sm" style="width:100%;justify-content:center;">Delete</button>
    </form>
</div>
HTML;
            }
        }

        $content = <<<HTML
<div class="page-header">
    <div>
        <h1>Media Library</h1>
        <p style="color:var(--text-muted);font-size:0.875rem;">Validated by UploadGuard + auto-optimized to WebP when GD is enabled.</p>
    </div>
    <form method="POST" action="/admin/media/upload?redirect=/admin/media" enctype="multipart/form-data" style="display:flex;gap:0.5rem;align-items:center;">
        <input type="hidden" name="_csrf" value="{$csrf}">
        <input type="file" name="file" accept="image/*" required class="form-control" style="max-width:260px;">
        <button type="submit" class="btn btn-primary">Upload Image</button>
    </form>
</div>
<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(210px,1fr));gap:1rem;">
    {$tiles}
</div>
HTML;

        $html = $admin->getHtmlRenderer()->layout('Media Library', $content, 'media');
        $response->getBody()->write($html);
        return $response->withHeader('Content-Type', 'text/html; charset=UTF-8');
    }

    public function listMediaJson(Request $request, Response $response): Response
    {
        /** @var MediaManager $media */
        $media = $this->container->get(MediaManager::class);
        $items = $media->list(60, 0);

        $response->getBody()->write((string)json_encode(['ok' => true, 'items' => $items], JSON_UNESCAPED_UNICODE));
        return $response->withHeader('Content-Type', 'application/json; charset=utf-8');
    }

    public function uploadMedia(Request $request, Response $response): Response
    {
        /** @var MediaManager $media */
        $media = $this->container->get(MediaManager::class);
        $redirect = (string)($request->getQueryParams()['redirect'] ?? '');

        $uploadedFiles = $request->getUploadedFiles();
        $file = $uploadedFiles['file'] ?? null;

        if ($file === null || $file->getError() !== UPLOAD_ERR_OK) {
            return $this->jsonError($response, 'No valid image file uploaded.', 422);
        }

        $tmpPath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'lite_up_' . bin2hex(random_bytes(8));
        try {
            $file->moveTo($tmpPath);
            $asset = $media->upload($tmpPath, (string)$file->getClientFilename());
        } catch (Throwable $e) {
            return $this->jsonError($response, $e->getMessage(), 422);
        } finally {
            @unlink($tmpPath);
        }

        if ($redirect !== '' && str_starts_with($redirect, '/') && !str_starts_with($redirect, '//')) {
            return $response->withStatus(302)->withHeader('Location', $redirect);
        }

        $response->getBody()->write((string)json_encode(['ok' => true, 'asset' => $asset], JSON_UNESCAPED_UNICODE));
        return $response->withHeader('Content-Type', 'application/json; charset=utf-8');
    }

    public function deleteMedia(Request $request, Response $response, array $args): Response
    {
        /** @var MediaManager $media */
        $media = $this->container->get(MediaManager::class);
        $media->delete((int)($args['id'] ?? 0));

        return $response->withStatus(302)->withHeader('Location', '/admin/media');
    }

    private function jsonError(Response $response, string $message, int $status): Response
    {
        $response->getBody()->write((string)json_encode(['ok' => false, 'error' => $message], JSON_UNESCAPED_UNICODE));
        return $response->withStatus($status)->withHeader('Content-Type', 'application/json; charset=utf-8');
    }
}
