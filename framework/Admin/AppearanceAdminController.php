<?php

declare(strict_types=1);

namespace Lite\Framework\Admin;

use DI\Container;
use Lite\Framework\Middleware\PageCacheMiddleware;
use Lite\Framework\Security\SecurityAuditLog;
use Lite\Framework\Theme\SectionRegistry;
use Lite\Framework\Theme\ThemeManager;
use LiteAdmin\AdminDashboard;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * 3-Tier Appearance & Section Composer Studio (`/admin/appearance`).
 * Allows non-technical admins to switch Child-Themes, apply Style Presets, customize Design Tokens,
 * reorder/configure Homepage Sections from Core & active Plugins, and export/import Theme Presets.
 */
final class AppearanceAdminController
{
    public function __construct(private readonly Container $container)
    {
    }

    public function show(Request $request, Response $response): Response
    {
        return $this->render($request, $response);
    }

    public function save(Request $request, Response $response): Response
    {
        /** @var ThemeManager $tm */
        $tm = $this->container->get(ThemeManager::class);
        $body = (array)$request->getParsedBody();
        $action = trim((string)($body['action'] ?? 'save_all'));
        $actor = (string)$request->getAttribute('admin_user', 'admin');
        $ip = (string)($request->getAttribute('client_ip') ?? ($request->getServerParams()['REMOTE_ADDR'] ?? ''));
        $flash = 'Appearance settings saved.';

        if ($action === 'apply_preset') {
            $presetKey = trim((string)($body['preset'] ?? ''));
            if ($tm->applyStylePreset($presetKey)) {
                $flash = "Applied Style Preset [{$presetKey}].";
            }
        } elseif ($action === 'import_json') {
            $json = trim((string)($body['preset_json'] ?? ''));
            $flash = $tm->importPresetJson($json)
                ? 'Imported Theme Preset JSON successfully.'
                : 'Invalid JSON payload — no changes applied.';
        } else {
            if (isset($body['active_theme'])) {
                $tm->setActiveTheme((string)$body['active_theme']);
            }
            $tm->saveTokens([
                'bg_color'       => $body['bg_color'] ?? null,
                'surface_color'  => $body['surface_color'] ?? null,
                'border_color'   => $body['border_color'] ?? null,
                'text_primary'   => $body['text_primary'] ?? null,
                'text_secondary' => $body['text_secondary'] ?? null,
                'accent'         => $body['accent'] ?? null,
                'accent_hover'   => $body['accent_hover'] ?? null,
                'font_body'      => $body['font_body'] ?? null,
                'font_heading'   => $body['font_heading'] ?? null,
                'radius'         => $body['radius'] ?? null,
                'header_style'   => $body['header_style'] ?? null,
                'card_ratio'     => $body['card_ratio'] ?? null,
            ]);
            if (isset($body['home_sections_json'])) {
                $decoded = json_decode((string)$body['home_sections_json'], true);
                if (is_array($decoded)) {
                    $tm->saveHomeSections($decoded);
                }
            }
        }

        if ($this->container->has(PageCacheMiddleware::class)) {
            $this->container->get(PageCacheMiddleware::class)->invalidateAll();
        }
        if ($this->container->has(SecurityAuditLog::class)) {
            try {
                $this->container->get(SecurityAuditLog::class)->record('appearance.updated', 'INFO', $actor, $ip ?: null, 'core', '/admin/appearance', ['action' => $action]);
            } catch (\Throwable) {
            }
        }

        return $this->render($request, $response, $flash);
    }

    private function render(Request $request, Response $response, ?string $flashSuccess = null): Response
    {
        /** @var AdminDashboard $admin */
        $admin = $this->container->get(AdminDashboard::class);
        /** @var ThemeManager $tm */
        $tm = $this->container->get(ThemeManager::class);
        /** @var SectionRegistry $registry */
        $registry = $this->container->get(SectionRegistry::class);

        $csrf = htmlspecialchars((string)$request->getAttribute('csrf_token', ''), ENT_QUOTES, 'UTF-8');
        $e = static fn(string $v): string => htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        $activeTheme = $tm->getActiveThemeSlug();
        $themes = $tm->listThemes();
        $tokens = $tm->getTokens();
        $sectionsJson = json_encode($tm->getHomeSections(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '[]';
        $catalogJson = json_encode($registry->listDefinitions(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '[]';
        $exportJson = $tm->exportPresetJson();

        $presetCards = '';
        foreach ($tm->listStylePresets() as $key => $preset) {
            $t = $preset['tokens'];
            $presetCards .= '<div style="border:1px solid var(--border);border-radius:8px;padding:0.9rem;display:flex;flex-direction:column;gap:0.45rem;">'
                . '<div style="display:flex;gap:0.35rem;align-items:center;">'
                . '<span style="width:16px;height:16px;border-radius:4px;border:1px solid #cbd5e1;background:' . $e($t['bg_color']) . ';"></span>'
                . '<span style="width:16px;height:16px;border-radius:4px;border:1px solid #cbd5e1;background:' . $e($t['accent']) . ';"></span>'
                . '<span style="width:16px;height:16px;border-radius:4px;border:1px solid #cbd5e1;background:' . $e($t['text_primary']) . ';"></span>'
                . '<strong style="margin-left:0.25rem;">' . $e($preset['name']) . '</strong>'
                . '</div>'
                . '<p style="font-size:0.78rem;color:var(--text-muted);flex:1;">' . $e($preset['description']) . '</p>'
                . '<form method="POST" action="/admin/appearance"><input type="hidden" name="_csrf" value="' . $csrf . '"><input type="hidden" name="action" value="apply_preset"><input type="hidden" name="preset" value="' . $e($key) . '"><button type="submit" class="btn btn-outline btn-sm">Apply Preset</button></form>'
                . '</div>';
        }

        $themeOptions = '';
        foreach ($themes as $slug => $meta) {
            $sel = $slug === $activeTheme ? 'selected' : '';
            $themeOptions .= '<option value="' . $e($slug) . '" ' . $sel . '>' . $e($meta['name']) . ' (' . $e($slug) . ')</option>';
        }

        $opt = static function (array $choices, string $current) use ($e): string {
            $out = '';
            foreach ($choices as $k => $lbl) {
                $sel = $k === $current ? 'selected' : '';
                $out .= '<option value="' . $e($k) . '" ' . $sel . '>' . $e($lbl) . '</option>';
            }
            return $out;
        };

        $fontHeadOpts = $opt(['sans' => 'Modern Sans', 'serif' => 'Editorial Serif', 'mono' => 'Technical Mono'], $tokens['font_heading']);
        $fontBodyOpts = $opt(['sans' => 'Modern Sans', 'serif' => 'Editorial Serif'], $tokens['font_body']);
        $radiusOpts = $opt(['0px' => 'Sharp (0px)', '4px' => 'Luxury Subtle (4px)', '6px' => 'Clean (6px)', '10px' => 'Modern Soft (10px)', '14px' => 'Rounded (14px)'], $tokens['radius']);
        $headerOpts = $opt(['left' => 'Classic Left Logo', 'center' => 'Boutique Centered Brand'], $tokens['header_style']);
        $ratioOpts = $opt(['3/4' => 'Portrait 3:4 (Fashion / Apparel)', '1/1' => 'Square 1:1 (Retail Products)', '4/3' => 'Classic 4:3 (Equipment)', '16/9' => 'Wide 16:9 (Media / Real Estate)'], $tokens['card_ratio']);

        $safeSectionsJson = $e($sectionsJson);
        $safeExportJson = $e($exportJson);

        $content = <<<HTML
<div class="page-header" style="flex-wrap:wrap;gap:1rem;">
    <div>
        <h1>Appearance &amp; Layout Studio</h1>
        <p style="color:var(--text-muted);font-size:0.85rem;">3-Tier UI Customization: Design Tokens, Homepage Section Composer &amp; Child-Theme Packages (0 DB queries via OpCache).</p>
    </div>
    <a href="/" target="_blank" class="btn btn-outline">Preview Storefront &nearr;</a>
</div>

<div class="card">
    <div class="section-card-title">1-Click Style Presets</div>
    <div style="display:grid;grid-template-columns:repeat(auto-fill, minmax(250px, 1fr));gap:0.85rem;">
        {$presetCards}
    </div>
</div>

<form method="POST" action="/admin/appearance" id="appearance-main-form">
    <input type="hidden" name="_csrf" value="{$csrf}">
    <input type="hidden" name="action" value="save_all">
    <input type="hidden" name="home_sections_json" id="home-sections-json" value="{$safeSectionsJson}">

    <div class="admin-form-grid">
        <div>
            <div class="card">
                <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:1rem;flex-wrap:wrap;gap:0.5rem;">
                    <div class="section-card-title" style="margin:0;border:none;padding:0;">Homepage Section Composer (Tier 2)</div>
                    <div style="display:flex;gap:0.45rem;align-items:center;">
                        <select id="add-section-select" class="form-control" style="width:auto;padding:0.35rem 0.6rem;"></select>
                        <button type="button" id="add-section-btn" class="btn btn-outline btn-sm">+ Add Section</button>
                    </div>
                </div>
                <div id="section-composer-list" style="display:flex;flex-direction:column;gap:0.75rem;"></div>
            </div>
        </div>

        <div>
            <div class="card">
                <div class="section-card-title">Child Theme &amp; Design Tokens (Tier 1 &amp; 3)</div>
                <div class="form-group">
                    <label>Active Child Theme (themes/*)</label>
                    <select name="active_theme" class="form-control">{$themeOptions}</select>
                </div>
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:0.65rem;">
                    <div class="form-group"><label>Accent</label><input type="color" name="accent" value="{$e($tokens['accent'])}" class="form-control" style="height:38px;padding:3px;"></div>
                    <div class="form-group"><label>Accent Hover</label><input type="color" name="accent_hover" value="{$e($tokens['accent_hover'])}" class="form-control" style="height:38px;padding:3px;"></div>
                    <div class="form-group"><label>Page Background</label><input type="color" name="bg_color" value="{$e($tokens['bg_color'])}" class="form-control" style="height:38px;padding:3px;"></div>
                    <div class="form-group"><label>Card Surface</label><input type="color" name="surface_color" value="{$e($tokens['surface_color'])}" class="form-control" style="height:38px;padding:3px;"></div>
                    <div class="form-group"><label>Primary Text</label><input type="color" name="text_primary" value="{$e($tokens['text_primary'])}" class="form-control" style="height:38px;padding:3px;"></div>
                    <div class="form-group"><label>Border Color</label><input type="color" name="border_color" value="{$e($tokens['border_color'])}" class="form-control" style="height:38px;padding:3px;"></div>
                </div>
                <div class="form-group"><label>Heading Typography</label><select name="font_heading" class="form-control">{$fontHeadOpts}</select></div>
                <div class="form-group"><label>Body Typography</label><select name="font_body" class="form-control">{$fontBodyOpts}</select></div>
                <div class="form-group"><label>Border Radius</label><select name="radius" class="form-control">{$radiusOpts}</select></div>
                <div class="form-group"><label>Header Alignment</label><select name="header_style" class="form-control">{$headerOpts}</select></div>
                <div class="form-group"><label>Catalog Card Ratio</label><select name="card_ratio" class="form-control">{$ratioOpts}</select></div>
                <button type="submit" class="btn btn-primary" style="width:100%;">Save Appearance &amp; Layout</button>
            </div>
        </div>
    </div>
</form>

<div class="card">
    <div class="section-card-title">Clone / Transfer Theme Preset (JSON Export &amp; Import)</div>
    <form method="POST" action="/admin/appearance">
        <input type="hidden" name="_csrf" value="{$csrf}">
        <input type="hidden" name="action" value="import_json">
        <textarea name="preset_json" rows="6" class="form-control" style="font-family:monospace;font-size:0.78rem;margin-bottom:0.75rem;">{$safeExportJson}</textarea>
        <button type="submit" class="btn btn-outline btn-sm">Import Preset JSON</button>
    </form>
</div>

<script>
(function () {
    const catalog = {$catalogJson};
    const hidden = document.getElementById('home-sections-json');
    const listEl = document.getElementById('section-composer-list');
    const addSelect = document.getElementById('add-section-select');
    const addBtn = document.getElementById('add-section-btn');

    let sections = [];
    try { sections = JSON.parse(hidden.value || '[]'); if (!Array.isArray(sections)) sections = []; } catch (e) { sections = []; }

    const catalogByType = {};
    catalog.forEach(def => {
        catalogByType[def.type] = def;
        const opt = document.createElement('option');
        opt.value = def.type;
        opt.textContent = '[' + def.category + '] ' + def.label;
        addSelect.appendChild(opt);
    });

    function sync() { hidden.value = JSON.stringify(sections); }

    function render() {
        listEl.innerHTML = '';
        if (sections.length === 0) {
            listEl.innerHTML = '<p style="color:var(--text-muted);font-size:0.85rem;">No homepage sections configured. Click "+ Add Section" above.</p>';
            return;
        }
        sections.forEach((sec, idx) => {
            const def = catalogByType[sec.type] || { label: sec.type, category: 'Plugin', default_props: {} };
            const props = Object.assign({}, def.default_props || {}, sec.props || {});
            sec.props = props;

            const card = document.createElement('div');
            card.style.cssText = 'border:1px solid var(--border);border-radius:8px;padding:0.85rem;background:var(--bg-main);';

            const head = document.createElement('div');
            head.style.cssText = 'display:flex;justify-content:space-between;align-items:center;gap:0.5rem;margin-bottom:0.65rem;flex-wrap:wrap;';
            head.innerHTML = '<div style="display:flex;align-items:center;gap:0.5rem;">'
                + '<input type="checkbox" ' + (sec.enabled !== false ? 'checked' : '') + ' data-toggle="' + idx + '">'
                + '<strong>' + def.label + '</strong>'
                + '<code style="font-size:0.74rem;color:var(--text-muted);">' + sec.type + '</code>'
                + '</div>'
                + '<div style="display:flex;gap:0.3rem;">'
                + '<button type="button" class="btn btn-outline btn-sm" data-up="' + idx + '">&uarr;</button>'
                + '<button type="button" class="btn btn-outline btn-sm" data-down="' + idx + '">&darr;</button>'
                + '<button type="button" class="btn btn-outline btn-sm" data-rm="' + idx + '">&times;</button>'
                + '</div>';
            card.appendChild(head);

            const grid = document.createElement('div');
            grid.style.cssText = 'display:grid;grid-template-columns:repeat(auto-fill, minmax(210px, 1fr));gap:0.5rem;';
            Object.keys(props).forEach(pk => {
                const wrap = document.createElement('div');
                const lbl = document.createElement('label');
                lbl.textContent = pk;
                lbl.style.cssText = 'display:block;font-size:0.72rem;color:var(--text-muted);margin-bottom:0.2rem;';
                const inp = document.createElement('input');
                inp.type = typeof props[pk] === 'number' ? 'number' : 'text';
                inp.className = 'form-control';
                inp.style.padding = '0.35rem 0.55rem';
                inp.value = String(props[pk] ?? '');
                inp.addEventListener('input', () => {
                    sec.props[pk] = inp.type === 'number' ? Number(inp.value) : inp.value;
                    sync();
                });
                wrap.appendChild(lbl);
                wrap.appendChild(inp);
                grid.appendChild(wrap);
            });
            card.appendChild(grid);

            head.querySelector('[data-toggle]')?.addEventListener('change', e => { sec.enabled = e.target.checked; sync(); });
            head.querySelector('[data-up]')?.addEventListener('click', () => {
                if (idx > 0) { [sections[idx - 1], sections[idx]] = [sections[idx], sections[idx - 1]]; sync(); render(); }
            });
            head.querySelector('[data-down]')?.addEventListener('click', () => {
                if (idx < sections.length - 1) { [sections[idx + 1], sections[idx]] = [sections[idx], sections[idx + 1]]; sync(); render(); }
            });
            head.querySelector('[data-rm]')?.addEventListener('click', () => { sections.splice(idx, 1); sync(); render(); });

            listEl.appendChild(card);
        });
    }

    addBtn?.addEventListener('click', () => {
        const type = addSelect.value;
        const def = catalogByType[type];
        if (!def) return;
        sections.push({ type: type, enabled: true, props: Object.assign({}, def.default_props || {}) });
        sync();
        render();
    });

    render();
    sync();
})();
</script>
HTML;

        $html = $admin->getHtmlRenderer()->layout('Appearance & Layout', $content, 'appearance', flashSuccess: $flashSuccess);
        $response->getBody()->write($html);
        return $response->withHeader('Content-Type', 'text/html; charset=UTF-8');
    }
}
