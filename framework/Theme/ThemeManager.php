<?php

declare(strict_types=1);

namespace Lite\Framework\Theme;

use Lite\Framework\Cms\SiteSettings;

/**
 * 3-Tier Theme & Design Token Manager (Tier 1 Tokens + Tier 2 Layout JSON + Tier 3 Child Themes).
 * Reads/writes via SiteSettings (OpCache-backed) with strict CSS value validation to prevent CSS injection.
 */
final class ThemeManager
{
    public const SETTING_ACTIVE_THEME = 'appearance_theme';
    public const SETTING_TOKENS = 'appearance_tokens_json';
    public const SETTING_HOME_SECTIONS = 'appearance_home_sections_json';

    /** @var array<string, array{name: string, description: string, theme: string, tokens: array<string, string>}> */
    private const STYLE_PRESETS = [
        'corporate-clean' => [
            'name'        => 'Corporate Clean',
            'description' => 'Crisp cool-slate enterprise palette with IBM Blue accent and modern sans typography.',
            'theme'       => 'default',
            'tokens'      => [
                'bg_color'       => '#f8fafc',
                'surface_color'  => '#ffffff',
                'surface_hover'  => '#f1f5f9',
                'border_color'   => '#e2e8f0',
                'text_primary'   => '#0f172a',
                'text_secondary' => '#475569',
                'accent'         => '#0f62fe',
                'accent_hover'   => '#0353e9',
                'font_body'      => 'sans',
                'font_heading'   => 'sans',
                'radius'         => '10px',
                'header_style'   => 'left',
                'card_ratio'     => '3/4',
            ],
        ],
        'editorial-boutique' => [
            'name'        => 'Editorial Boutique (Studio / Fashion / Rental)',
            'description' => 'Warm ivory canvas, deep burgundy accent, serif display headings, and centered boutique header.',
            'theme'       => 'editorial',
            'tokens'      => [
                'bg_color'       => '#faf8f5',
                'surface_color'  => '#ffffff',
                'surface_hover'  => '#f3eee7',
                'border_color'   => '#e6dfd5',
                'text_primary'   => '#1c1917',
                'text_secondary' => '#57534e',
                'accent'         => '#8c2d3b',
                'accent_hover'   => '#6f212d',
                'font_body'      => 'sans',
                'font_heading'   => 'serif',
                'radius'         => '4px',
                'header_style'   => 'center',
                'card_ratio'     => '3/4',
            ],
        ],
        'modern-minimal' => [
            'name'        => 'Modern Minimal (Retail / Tech / Showroom)',
            'description' => 'High-contrast monochrome gallery surface with crisp 1:1 product framing.',
            'theme'       => 'default',
            'tokens'      => [
                'bg_color'       => '#ffffff',
                'surface_color'  => '#ffffff',
                'surface_hover'  => '#f4f4f5',
                'border_color'   => '#e4e4e7',
                'text_primary'   => '#18181b',
                'text_secondary' => '#52525b',
                'accent'         => '#18181b',
                'accent_hover'   => '#3f3f46',
                'font_body'      => 'sans',
                'font_heading'   => 'sans',
                'radius'         => '6px',
                'header_style'   => 'left',
                'card_ratio'     => '1/1',
            ],
        ],
    ];

    /** @var array<string, string> */
    private const FONT_STACKS = [
        'sans'  => "-apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif",
        'serif' => "Georgia, Cambria, 'Times New Roman', Times, serif",
        'mono'  => "ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace",
    ];

    public function __construct(
        private readonly string $basePath,
        private readonly ?SiteSettings $settings = null
    ) {
    }

    /**
     * Discover built-in default theme + all Child-Theme packages in `themes/<slug>/theme.json`.
     *
     * @return array<string, array{slug: string, name: string, description: string, author: string, version: string, path: ?string}>
     */
    public function listThemes(): array
    {
        $themes = [
            'default' => [
                'slug'        => 'default',
                'name'        => 'Default Starter Theme',
                'description' => 'Core responsive layout (templates/layouts/app.lite.php).',
                'author'      => 'LitePlatform',
                'version'     => '1.0.0',
                'path'        => null,
            ],
        ];

        $themesRoot = rtrim($this->basePath, '/\\') . '/themes';
        if (is_dir($themesRoot)) {
            foreach (glob($themesRoot . '/*', GLOB_ONLYDIR) ?: [] as $dir) {
                $slug = basename($dir);
                if (!preg_match('/^[a-zA-Z0-9_-]+$/', $slug)) {
                    continue;
                }
                $metaFile = $dir . '/theme.json';
                $meta = file_exists($metaFile) ? (json_decode((string)file_get_contents($metaFile), true) ?: []) : [];
                $themes[$slug] = [
                    'slug'        => $slug,
                    'name'        => (string)($meta['name'] ?? ucfirst($slug)),
                    'description' => (string)($meta['description'] ?? 'Custom child theme override.'),
                    'author'      => (string)($meta['author'] ?? 'Custom'),
                    'version'     => (string)($meta['version'] ?? '1.0.0'),
                    'path'        => $dir,
                ];
            }
        }

        return $themes;
    }

    /**
     * @return array<string, array{name: string, description: string, theme: string, tokens: array<string, string>}>
     */
    public function listStylePresets(): array
    {
        return self::STYLE_PRESETS;
    }

    public function getActiveThemeSlug(string $fallback = 'default'): string
    {
        $saved = $this->settings ? trim($this->settings->get(self::SETTING_ACTIVE_THEME, '')) : '';
        $slug = $saved !== '' ? $saved : $fallback;
        $themes = $this->listThemes();
        return isset($themes[$slug]) ? $slug : 'default';
    }

    public function resolveActiveThemePath(string $fallback = 'default'): ?string
    {
        $slug = $this->getActiveThemeSlug($fallback);
        $themes = $this->listThemes();
        return $themes[$slug]['path'] ?? null;
    }

    /**
     * @return array<string, string>
     */
    public function getTokens(): array
    {
        $defaults = self::STYLE_PRESETS['corporate-clean']['tokens'];
        if ($this->settings === null) {
            return $defaults;
        }
        $raw = $this->settings->get(self::SETTING_TOKENS, '');
        $decoded = $raw !== '' ? json_decode($raw, true) : null;
        if (!is_array($decoded)) {
            return $defaults;
        }
        return array_merge($defaults, $this->sanitizeTokens($decoded));
    }

    /**
     * @param array<string, mixed> $input
     */
    public function saveTokens(array $input): void
    {
        if ($this->settings === null) {
            return;
        }
        $merged = array_merge($this->getTokens(), $this->sanitizeTokens($input));
        $this->settings->set(self::SETTING_TOKENS, json_encode($merged, JSON_UNESCAPED_SLASHES) ?: '{}');
    }

    public function setActiveTheme(string $slug): void
    {
        if ($this->settings === null) {
            return;
        }
        $themes = $this->listThemes();
        $safe = isset($themes[$slug]) ? $slug : 'default';
        $this->settings->set(self::SETTING_ACTIVE_THEME, $safe);
    }

    public function applyStylePreset(string $presetKey): bool
    {
        if (!isset(self::STYLE_PRESETS[$presetKey])) {
            return false;
        }
        $preset = self::STYLE_PRESETS[$presetKey];
        $this->setActiveTheme($preset['theme']);
        $this->saveTokens($preset['tokens']);
        return true;
    }

    /**
     * @return list<array{type: string, enabled: bool, props: array<string, mixed>}>
     */
    public function getHomeSections(): array
    {
        if ($this->settings !== null) {
            $raw = $this->settings->get(self::SETTING_HOME_SECTIONS, '');
            if ($raw !== '') {
                $decoded = json_decode($raw, true);
                if (is_array($decoded) && !empty($decoded)) {
                    $clean = [];
                    foreach ($decoded as $sec) {
                        if (is_array($sec) && !empty($sec['type'])) {
                            $clean[] = [
                                'type'    => preg_replace('/[^a-z0-9_]/', '', strtolower((string)$sec['type'])) ?? '',
                                'enabled' => (bool)($sec['enabled'] ?? true),
                                'props'   => is_array($sec['props'] ?? null) ? $sec['props'] : [],
                            ];
                        }
                    }
                    if (!empty($clean)) {
                        return $clean;
                    }
                }
            }
        }

        // Default homepage layout matching existing starter UX
        return [
            ['type' => 'hero', 'enabled' => true, 'props' => ['align' => 'center']],
            ['type' => 'catalog_featured', 'enabled' => true, 'props' => ['heading' => 'Sản phẩm & Mẫu nổi bật', 'limit' => 8, 'columns' => 4, 'listing_type' => 'all']],
            ['type' => 'blog_latest', 'enabled' => true, 'props' => ['heading' => '', 'limit' => 3, 'columns' => 3]],
        ];
    }

    /**
     * @param list<array{type: string, enabled?: bool, props?: array<string, mixed>}> $sections
     */
    public function saveHomeSections(array $sections): void
    {
        if ($this->settings === null) {
            return;
        }
        $normalized = [];
        foreach ($sections as $sec) {
            $type = preg_replace('/[^a-z0-9_]/', '', strtolower((string)($sec['type'] ?? ''))) ?? '';
            if ($type === '') {
                continue;
            }
            $normalized[] = [
                'type'    => $type,
                'enabled' => (bool)($sec['enabled'] ?? true),
                'props'   => is_array($sec['props'] ?? null) ? $sec['props'] : [],
            ];
        }
        $this->settings->set(self::SETTING_HOME_SECTIONS, json_encode($normalized, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '[]');
    }

    /**
     * Render sanitized CSS custom properties block (`:root { ... }`) for injection in `<head>`.
     */
    public function renderCssVariables(): string
    {
        $t = $this->getTokens();
        $bodyFont = self::FONT_STACKS[$t['font_body']] ?? self::FONT_STACKS['sans'];
        $headFont = self::FONT_STACKS[$t['font_heading']] ?? self::FONT_STACKS['sans'];
        $headerJustify = $t['header_style'] === 'center' ? 'center' : 'space-between';
        $headerDir = $t['header_style'] === 'center' ? 'column' : 'row';

        return "<style id=\"lite-theme-tokens\">:root{"
            . "--bg-color:{$t['bg_color']};"
            . "--surface-color:{$t['surface_color']};"
            . "--surface-hover:{$t['surface_hover']};"
            . "--border-color:{$t['border_color']};"
            . "--text-primary:{$t['text_primary']};"
            . "--text-secondary:{$t['text_secondary']};"
            . "--accent:{$t['accent']};"
            . "--accent-hover:{$t['accent_hover']};"
            . "--font-body:{$bodyFont};"
            . "--font-heading:{$headFont};"
            . "--radius-base:{$t['radius']};"
            . "--card-ratio:{$t['card_ratio']};"
            . "}"
            . "body{font-family:var(--font-body);}"
            . "h1,h2,h3,.brand{font-family:var(--font-heading);}"
            . ".card,.btn,.rt-card,.rt-main-photo{border-radius:var(--radius-base);}"
            . ".rt-thumb,.rt-main-photo{aspect-ratio:var(--card-ratio);}"
            . ".nav-container{flex-direction:{$headerDir};justify-content:{$headerJustify};}"
            . "</style>";
    }

    public function exportPresetJson(): string
    {
        return json_encode([
            'schema'   => 'lite-appearance-v1',
            'theme'    => $this->getActiveThemeSlug(),
            'tokens'   => $this->getTokens(),
            'sections' => $this->getHomeSections(),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}';
    }

    public function importPresetJson(string $json): bool
    {
        $decoded = json_decode($json, true);
        if (!is_array($decoded)) {
            return false;
        }
        if (isset($decoded['theme']) && is_string($decoded['theme'])) {
            $this->setActiveTheme($decoded['theme']);
        }
        if (isset($decoded['tokens']) && is_array($decoded['tokens'])) {
            $this->saveTokens($decoded['tokens']);
        }
        if (isset($decoded['sections']) && is_array($decoded['sections'])) {
            $this->saveHomeSections($decoded['sections']);
        }
        return true;
    }

    /**
     * Strict allowlist sanitization preventing CSS injection in custom tokens.
     *
     * @param array<string, mixed> $raw
     * @return array<string, string>
     */
    private function sanitizeTokens(array $raw): array
    {
        $clean = [];
        $colorKeys = ['bg_color', 'surface_color', 'surface_hover', 'border_color', 'text_primary', 'text_secondary', 'accent', 'accent_hover'];
        foreach ($colorKeys as $k) {
            if (isset($raw[$k]) && is_string($raw[$k]) && preg_match('/^#[0-9a-fA-F]{6}$/', trim($raw[$k]))) {
                $clean[$k] = strtolower(trim($raw[$k]));
            }
        }
        foreach (['font_body', 'font_heading'] as $fk) {
            if (isset($raw[$fk]) && is_string($raw[$fk]) && isset(self::FONT_STACKS[$raw[$fk]])) {
                $clean[$fk] = $raw[$fk];
            }
        }
        if (isset($raw['radius']) && is_string($raw['radius']) && in_array($raw['radius'], ['0px', '4px', '6px', '10px', '14px'], true)) {
            $clean['radius'] = $raw['radius'];
        }
        if (isset($raw['header_style']) && is_string($raw['header_style']) && in_array($raw['header_style'], ['left', 'center'], true)) {
            $clean['header_style'] = $raw['header_style'];
        }
        if (isset($raw['card_ratio']) && is_string($raw['card_ratio']) && in_array($raw['card_ratio'], ['3/4', '1/1', '4/3', '16/9'], true)) {
            $clean['card_ratio'] = $raw['card_ratio'];
        }
        return $clean;
    }
}
