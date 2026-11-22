<?php

declare(strict_types=1);

namespace Lite\Framework\Theme;

use Throwable;

/**
 * Pluggable Section Registry (Shopify OS 2.0-style Server-Side Layout Composer).
 * Core and any Extension can register composable UI sections with typed default props.
 * Disabled or missing plugin sections are gracefully skipped without crashing pages.
 */
final class SectionRegistry
{
    /**
     * @var array<string, array{
     *   type: string,
     *   label: string,
     *   category: string,
     *   module_id: string,
     *   default_props: array<string, mixed>,
     *   renderer: callable(array<string, mixed>, array<string, mixed>): string
     * }>
     */
    private array $definitions = [];

    /**
     * @param array<string, mixed> $defaultProps
     * @param callable(array<string, mixed>, array<string, mixed>): string $renderer
     */
    public function register(
        string $type,
        string $label,
        string $category,
        array $defaultProps,
        callable $renderer,
        string $moduleId = 'core'
    ): self {
        $type = preg_replace('/[^a-z0-9_]/', '', strtolower($type)) ?? '';
        if ($type === '') {
            return $this;
        }
        $this->definitions[$type] = [
            'type'          => $type,
            'label'         => $label,
            'category'      => $category,
            'module_id'     => $moduleId,
            'default_props' => $defaultProps,
            'renderer'      => $renderer,
        ];
        return $this;
    }

    public function has(string $type): bool
    {
        return isset($this->definitions[$type]);
    }

    /**
     * Return section catalog metadata for the Admin Appearance Section Composer UI.
     *
     * @return list<array{type: string, label: string, category: string, module_id: string, default_props: array<string, mixed>}>
     */
    public function listDefinitions(): array
    {
        $list = [];
        foreach ($this->definitions as $def) {
            $list[] = [
                'type'          => $def['type'],
                'label'         => $def['label'],
                'category'      => $def['category'],
                'module_id'     => $def['module_id'],
                'default_props' => $def['default_props'],
            ];
        }
        return $list;
    }

    /**
     * Render an ordered list of configured sections into clean SSR HTML.
     * Unknown sections (e.g. from a disabled plugin) or throwing renderers are safely skipped.
     *
     * @param list<array{type: string, enabled?: bool, props?: array<string, mixed>}> $sections
     * @param array<string, mixed> $pageContext
     */
    public function renderSections(array $sections, array $pageContext = []): string
    {
        $chunks = [];
        foreach ($sections as $section) {
            if (!($section['enabled'] ?? true)) {
                continue;
            }
            $type = (string)($section['type'] ?? '');
            if (!isset($this->definitions[$type])) {
                continue;
            }

            $def = $this->definitions[$type];
            $props = array_merge($def['default_props'], is_array($section['props'] ?? null) ? $section['props'] : []);

            try {
                $html = (string)($def['renderer'])($props, $pageContext);
                if (trim($html) !== '') {
                    $chunks[] = $html;
                }
            } catch (Throwable) {
                // Fault isolation: skip broken section without crashing the page
            }
        }

        return implode("\n", $chunks);
    }

    /**
     * Register built-in Core sections (Hero, Features Grid, Latest Blog, CTA Banner, Rich Text).
     */
    public function registerCoreSections(?\Lite\Framework\Security\HtmlSanitizer $sanitizer = null): self
    {
        $e = static fn(string $v): string => htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        // 1. Hero Banner
        $this->register(
            type: 'hero',
            label: 'Hero Banner',
            category: 'Core',
            defaultProps: [
                'title'            => '',
                'subtitle'         => '',
                'align'            => 'center',
                'primary_label'    => '',
                'primary_url'      => '/contact',
                'secondary_label'  => '',
                'secondary_url'    => '/blog',
            ],
            renderer: static function (array $props, array $ctx) use ($e): string {
                $t = $ctx['t'] ?? static fn(string $k, string $fb = ''): string => $fb ?: $k;
                $title = trim((string)($props['title'] ?? '')) ?: (string)$t('home.hero_title', 'LitePlatform');
                $subtitle = trim((string)($props['subtitle'] ?? ''));
                if ($subtitle === '') {
                    $subtitle = trim((string)($ctx['tagline'] ?? '')) ?: (string)$t('home.hero_subtitle', '');
                }
                $align = ($props['align'] ?? 'center') === 'left' ? 'left' : 'center';
                $justify = $align === 'left' ? 'flex-start' : 'center';
                $marginAuto = $align === 'left' ? '0 0 1.75rem 0' : '0 auto 1.75rem';

                $pLabel = trim((string)($props['primary_label'] ?? '')) ?: (string)$t('nav.contact', 'Contact');
                $pUrl = trim((string)($props['primary_url'] ?? '/contact')) ?: '/contact';
                $sLabel = trim((string)($props['secondary_label'] ?? '')) ?: (string)$t('nav.blog', 'Blog');
                $sUrl = trim((string)($props['secondary_url'] ?? '/blog')) ?: '/blog';
                $adminCta = (string)$t('home.cta_admin', 'Admin');

                return <<<HTML
<section class="card" data-section="hero" style="padding:3rem 2rem;text-align:{$align};">
    <h1 style="font-size:2.25rem;font-weight:800;line-height:1.2;letter-spacing:-0.025em;margin-bottom:0.85rem;color:var(--text-primary);">{$e($title)}</h1>
    <p style="font-size:1.05rem;color:var(--text-secondary);max-width:680px;margin:{$marginAuto};">{$e($subtitle)}</p>
    <div style="display:flex;justify-content:{$justify};gap:0.85rem;flex-wrap:wrap;">
        <a href="{$e($pUrl)}" class="btn btn-primary">{$e($pLabel)}</a>
        <a href="{$e($sUrl)}" class="btn btn-outline">{$e($sLabel)}</a>
        <a href="/admin" class="btn btn-outline">{$e($adminCta)}</a>
    </div>
</section>
HTML;
            }
        );

        // 2. Latest Blog Posts
        $this->register(
            type: 'blog_latest',
            label: 'Latest Blog Articles',
            category: 'Core',
            defaultProps: ['heading' => '', 'limit' => 3, 'columns' => 3],
            renderer: static function (array $props, array $ctx) use ($e): string {
                $t = $ctx['t'] ?? static fn(string $k, string $fb = ''): string => $fb ?: $k;
                $heading = trim((string)($props['heading'] ?? '')) ?: (string)$t('home.latest_posts', 'Latest Articles');
                $limit = max(1, min(12, (int)($props['limit'] ?? 3)));
                $posts = array_slice((array)($ctx['latestPosts'] ?? []), 0, $limit);
                $viewAll = (string)$t('home.view_all', 'View all');
                $readMore = (string)$t('home.read_more', 'Read more');

                if (empty($posts)) {
                    $emptyTxt = (string)$t('home.empty_posts', 'No published posts yet.');
                    $createTxt = (string)$t('home.create_first_post', 'Create Post');
                    $body = "<div class=\"card\" style=\"display:flex;justify-content:space-between;align-items:center;gap:1rem;flex-wrap:wrap;\"><p style=\"color:var(--text-secondary);\">{$e($emptyTxt)}</p><a href=\"/admin/post/create\" class=\"btn btn-outline\">{$e($createTxt)}</a></div>";
                } else {
                    $cards = '';
                    foreach ($posts as $post) {
                        $dateHtml = !empty($post['published_at'])
                            ? '<div style="font-size:0.78rem;color:var(--text-secondary);margin-bottom:0.35rem;">' . $e(substr((string)$post['published_at'], 0, 10)) . '</div>'
                            : '';
                        $excerptHtml = !empty($post['excerpt'])
                            ? '<p style="font-size:0.9rem;color:var(--text-secondary);">' . $e((string)$post['excerpt']) . '</p>'
                            : '';
                        $slug = rawurlencode((string)$post['slug']);
                        $cards .= "<article class=\"card\" style=\"display:flex;flex-direction:column;justify-content:space-between;gap:0.75rem;\">"
                            . "<div>{$dateHtml}<h3 style=\"font-size:1.12rem;line-height:1.35;margin-bottom:0.5rem;\"><a href=\"/blog/{$slug}\" style=\"color:var(--text-primary);text-decoration:none;\">{$e((string)$post['title'])}</a></h3>{$excerptHtml}</div>"
                            . "<a href=\"/blog/{$slug}\" style=\"font-size:0.88rem;font-weight:600;color:var(--accent);text-decoration:none;\">{$e($readMore)} &rarr;</a>"
                            . "</article>";
                    }
                    $cols = max(2, min(4, (int)($props['columns'] ?? 3)));
                    $minW = $cols >= 4 ? '230px' : '280px';
                    $body = "<div style=\"display:grid;grid-template-columns:repeat(auto-fit, minmax({$minW}, 1fr));gap:1.25rem;\">{$cards}</div>";
                }

                return "<section data-section=\"blog_latest\"><div class=\"section-head\"><h2>{$e($heading)}</h2><a href=\"/blog\">{$e($viewAll)} &rarr;</a></div>{$body}</section>";
            }
        );

        // 3. Features / Services Grid
        $this->register(
            type: 'features_grid',
            label: 'Highlights / Value Props Grid',
            category: 'Core',
            defaultProps: [
                'heading' => 'Vì sao chọn chúng tôi',
                'item_1_title' => 'Tư vấn tận tâm',
                'item_1_desc'  => 'Hỗ trợ chọn mẫu, đặt lịch và tư vấn nhanh qua Zalo / Hotline.',
                'item_2_title' => 'Mẫu mới cập nhật liên tục',
                'item_2_desc'  => 'Hình ảnh thực tế rõ nét, kiểm tra chất lượng trước khi giao.',
                'item_3_title' => 'Chi phí minh bạch',
                'item_3_desc'  => 'Giá thuê/bán và tiền cọc công khai rõ ràng, hoàn cọc ngay khi trả.',
            ],
            renderer: static function (array $props) use ($e): string {
                $heading = trim((string)($props['heading'] ?? 'Highlights'));
                $cards = '';
                for ($i = 1; $i <= 3; $i++) {
                    $t = trim((string)($props["item_{$i}_title"] ?? ''));
                    $d = trim((string)($props["item_{$i}_desc"] ?? ''));
                    if ($t === '' && $d === '') {
                        continue;
                    }
                    $cards .= "<div class=\"card\"><h3 style=\"font-size:1.05rem;font-weight:700;margin-bottom:0.4rem;\">{$e($t)}</h3><p style=\"font-size:0.9rem;color:var(--text-secondary);\">{$e($d)}</p></div>";
                }
                return "<section data-section=\"features_grid\"><div class=\"section-head\"><h2>{$e($heading)}</h2></div><div style=\"display:grid;grid-template-columns:repeat(auto-fit, minmax(250px, 1fr));gap:1.25rem;\">{$cards}</div></section>";
            }
        );

        // 4. CTA Conversion Strip
        $this->register(
            type: 'cta_banner',
            label: 'Call-to-Action Banner',
            category: 'Core',
            defaultProps: [
                'heading'      => 'Cần tư vấn nhanh hoặc đặt lịch hẹn?',
                'subtitle'     => 'Để lại thông tin hoặc nhắn trực tiếp để được hỗ trợ trong vài phút.',
                'button_label' => 'Liên hệ ngay',
                'button_url'   => '/contact',
            ],
            renderer: static function (array $props) use ($e): string {
                $h = trim((string)($props['heading'] ?? ''));
                $s = trim((string)($props['subtitle'] ?? ''));
                $bl = trim((string)($props['button_label'] ?? 'Liên hệ'));
                $bu = trim((string)($props['button_url'] ?? '/contact'));
                return "<section class=\"card\" data-section=\"cta_banner\" style=\"display:flex;justify-content:space-between;align-items:center;gap:1.25rem;flex-wrap:wrap;padding:2rem;\">"
                    . "<div><h2 style=\"font-size:1.3rem;font-weight:700;margin-bottom:0.35rem;\">{$e($h)}</h2><p style=\"color:var(--text-secondary);font-size:0.94rem;\">{$e($s)}</p></div>"
                    . "<a href=\"{$e($bu)}\" class=\"btn btn-primary\">{$e($bl)}</a>"
                    . "</section>";
            }
        );

        // 5. Sanitized Rich Text / Brand Story Block
        $this->register(
            type: 'rich_text',
            label: 'Brand Story / Custom HTML',
            category: 'Core',
            defaultProps: ['heading' => 'Câu chuyện thương hiệu', 'content' => '<p>Giới thiệu về cửa hàng hoặc doanh nghiệp của bạn.</p>'],
            renderer: static function (array $props) use ($e, $sanitizer): string {
                $h = trim((string)($props['heading'] ?? ''));
                $raw = (string)($props['content'] ?? '');
                $safeHtml = $sanitizer ? $sanitizer->sanitize($raw) : $e($raw);
                $headHtml = $h !== '' ? "<h2 style=\"font-size:1.3rem;font-weight:700;margin-bottom:0.75rem;\">{$e($h)}</h2>" : '';
                return "<section class=\"card\" data-section=\"rich_text\">{$headHtml}<div style=\"line-height:1.75;color:var(--text-primary);\">{$safeHtml}</div></section>";
            }
        );

        return $this;
    }
}
