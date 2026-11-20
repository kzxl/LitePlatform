<?php

declare(strict_types=1);

namespace LiteAdmin\UI;

use LiteAdmin\Field\FieldRegistry;
use LiteAdmin\Resource\ResourceMetadata;
use LiteORM\Query\Paginator;

/**
 * Renders modern, responsive HTML admin interfaces with pluggable FieldRegistry,
 * 2-column WordPress/Filament editor layout, live SEO preview, and Media Picker modal.
 */
class HtmlRenderer
{
    private string $prefix;
    /** @var array<string, list<ResourceMetadata>> */
    private array $menuGroups;
    /** @var array<string, list<array{slug: string, title: string}>> */
    private array $customPages;
    private FieldRegistry $fields;

    /**
     * @param string $prefix URL prefix (e.g. '/admin')
     * @param array<string, list<ResourceMetadata>> $menuGroups
     * @param array<string, list<array{slug: string, title: string}>> $customPages
     */
    public function __construct(
        string $prefix,
        array $menuGroups,
        ?FieldRegistry $fields = null,
        array $customPages = []
    ) {
        $this->prefix = rtrim($prefix, '/');
        $this->menuGroups = $menuGroups;
        $this->fields = $fields ?? new FieldRegistry();
        $this->customPages = $customPages;
    }

    /**
     * Wrap page content inside master layout.
     */
    public function layout(string $title, string $content, ?string $currentSlug = null, ?string $flashSuccess = null, ?string $flashError = null): string
    {
        $css = Theme::getStyles();
        $navHtml = $this->renderSidebar($currentSlug);
        $safeTitle = htmlspecialchars($title, ENT_QUOTES, 'UTF-8');
        $prefix = htmlspecialchars($this->prefix, ENT_QUOTES, 'UTF-8');
        $adminJs = $this->renderAdminScripts();

        $flashHtml = '';
        if ($flashSuccess) {
            $flashHtml .= '<div class="alert alert-success">' . htmlspecialchars($flashSuccess, ENT_QUOTES) . '</div>';
        }
        if ($flashError) {
            $flashHtml .= '<div class="alert alert-danger">' . htmlspecialchars($flashError, ENT_QUOTES) . '</div>';
        }

        return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{$safeTitle} | LiteAdmin</title>
    <style>{$css}</style>
</head>
<body>
    <aside class="sidebar">
        <h2>LiteAdmin</h2>
        {$navHtml}
    </aside>
    <div class="main-wrapper">
        <header class="topbar">
            <strong>{$safeTitle}</strong>
            <div style="display:flex;align-items:center;gap:0.75rem;font-size:0.85rem;">
                <a href="/" target="_blank" class="btn btn-outline btn-sm">Visit Site &nearr;</a>
                <a href="{$prefix}/logout" class="btn btn-outline btn-sm">Logout</a>
            </div>
        </header>
        <main class="content-body">
            {$flashHtml}
            {$content}
        </main>
    </div>

    <!-- Shared Media Library Picker Modal -->
    <div class="media-modal-backdrop" id="lite-media-modal" data-admin-prefix="{$prefix}">
        <div class="media-modal">
            <div class="media-modal-head">
                <strong>Media Library</strong>
                <div style="display:flex;gap:0.5rem;align-items:center;">
                    <label class="btn btn-primary btn-sm" style="margin:0;cursor:pointer;">
                        + Upload Image
                        <input type="file" accept="image/*" id="lite-modal-upload" style="display:none;">
                    </label>
                    <button type="button" class="btn btn-outline btn-sm" id="lite-modal-close">Close</button>
                </div>
            </div>
            <div class="media-modal-body" id="lite-media-grid">
                <p style="color:var(--text-muted);font-size:0.85rem;">Loading media...</p>
            </div>
        </div>
    </div>

    <script>{$adminJs}</script>
</body>
</html>
HTML;
    }

    /**
     * Render DataTable list view.
     *
     * @param array<string, mixed> $queryParams
     */
    public function renderList(ResourceMetadata $res, Paginator $paginator, array $queryParams = [], ?string $csrfToken = null): string
    {
        $createUrl = "{$this->prefix}/{$res->slug}/create";
        $exportUrl = "{$this->prefix}/{$res->slug}/export";
        $currentSearch = htmlspecialchars((string)($queryParams['search'] ?? ''), ENT_QUOTES);

        $html = '<div class="page-header">';
        $html .= '<div><h1>' . htmlspecialchars($res->title) . '</h1>';
        $html .= '<p style="color: var(--text-muted); font-size: 0.875rem;">Total: ' . $paginator->total . ' records</p></div>';
        $html .= '<div style="display: flex; gap: 0.5rem;">';
        $html .= '<a href="' . $exportUrl . '" class="btn btn-outline">Export Excel</a>';
        $html .= '<a href="' . $createUrl . '" class="btn btn-primary">+ Create New</a>';
        $html .= '</div></div>';

        $html .= '<div class="card" style="padding: 1rem;">';
        $html .= '<form method="GET" action="' . $this->prefix . '/' . $res->slug . '" style="display: flex; gap: 0.5rem;">';
        $html .= '<input type="text" name="search" value="' . $currentSearch . '" class="form-control" placeholder="Search..." style="max-width: 350px;">';
        $html .= '<button type="submit" class="btn btn-outline">Search</button>';
        if ($currentSearch !== '') {
            $html .= '<a href="' . $this->prefix . '/' . $res->slug . '" class="btn btn-outline">Reset</a>';
        }
        $html .= '</form></div>';

        $html .= '<div class="card"><div class="table-responsive"><table class="data-table"><thead><tr>';
        foreach ($res->columns as $col) {
            $html .= '<th>' . htmlspecialchars($col['label']) . '</th>';
        }
        $html .= '<th style="text-align: right;">Actions</th></tr></thead><tbody>';

        if ($paginator->isEmpty()) {
            $html .= '<tr><td colspan="' . (count($res->columns) + 1) . '" style="text-align: center; padding: 2rem; color: var(--text-muted);">No records found</td></tr>';
        } else {
            foreach ($paginator->items as $item) {
                $id = $item->{$res->primaryKey} ?? null;
                $html .= '<tr>';
                foreach ($res->columns as $prop => $col) {
                    $val = $item->{$prop} ?? null;
                    $formatted = $this->formatValue($val, $col['format']);
                    $html .= '<td>' . $formatted . '</td>';
                }
                $editUrl = "{$this->prefix}/{$res->slug}/edit/{$id}";
                $detailUrl = "{$this->prefix}/{$res->slug}/detail/{$id}";
                $deleteUrl = "{$this->prefix}/{$res->slug}/delete/{$id}";

                $html .= '<td style="text-align: right; white-space: nowrap;">';
                $html .= '<a href="' . $detailUrl . '" class="btn btn-sm btn-outline" style="margin-right: 0.25rem;">View</a>';
                $html .= '<a href="' . $editUrl . '" class="btn btn-sm btn-outline" style="margin-right: 0.25rem;">Edit</a>';
                $html .= '<form method="POST" action="' . $deleteUrl . '" style="display: inline;" onsubmit="return confirm(\'Are you sure you want to delete this record?\');">';
                if ($csrfToken) {
                    $html .= '<input type="hidden" name="_csrf" value="' . htmlspecialchars($csrfToken, ENT_QUOTES) . '">';
                }
                $html .= '<button type="submit" class="btn btn-sm btn-danger">Delete</button>';
                $html .= '</form>';
                $html .= '</td></tr>';
            }
        }

        $html .= '</tbody></table></div>';

        if ($paginator->lastPage > 1) {
            $html .= '<div class="pagination">';
            $html .= '<div>Page ' . $paginator->currentPage . ' of ' . $paginator->lastPage . '</div>';
            $html .= '<div class="nav-links">';
            if ($paginator->currentPage > 1) {
                $prevUrl = $this->buildPageUrl($res->slug, $paginator->currentPage - 1, $queryParams);
                $html .= '<a href="' . $prevUrl . '" class="btn btn-sm btn-outline">&laquo; Previous</a>';
            }
            if ($paginator->hasMorePages()) {
                $nextUrl = $this->buildPageUrl($res->slug, $paginator->currentPage + 1, $queryParams);
                $html .= '<a href="' . $nextUrl . '" class="btn btn-sm btn-outline">Next &raquo;</a>';
            }
            $html .= '</div></div>';
        }

        $html .= '</div>';

        return $html;
    }

    /**
     * Render Create / Edit Form with automatic 2-column CMS layout when sidebar/seo fields exist.
     *
     * @param array<string, string> $errors Field validation errors
     * @param array<string, mixed> $old Old input values
     * @param array<string, array<string|int, string>> $relationOptions Dynamic options map per property
     */
    public function renderForm(
        ResourceMetadata $res,
        ?object $entity = null,
        array $errors = [],
        array $old = [],
        ?string $csrfToken = null,
        array $relationOptions = []
    ): string {
        $isEdit = ($entity !== null);
        $id = $isEdit ? ($entity->{$res->primaryKey} ?? '') : null;
        $title = $isEdit ? "Edit {$res->title} #{$id}" : "Create {$res->title}";
        $actionUrl = $isEdit ? "{$this->prefix}/{$res->slug}/edit/{$id}" : "{$this->prefix}/{$res->slug}/create";
        $cancelUrl = "{$this->prefix}/{$res->slug}";

        $mainFields = [];
        $seoFields = [];
        $sidebarFields = [];

        foreach ($res->fields as $field) {
            if ($field['readonly'] && !$isEdit) {
                continue;
            }
            $sec = strtolower((string)($field['section'] ?? 'main'));
            if ($sec === 'sidebar') {
                $sidebarFields[] = $field;
            } elseif ($sec === 'seo') {
                $seoFields[] = $field;
            } else {
                $mainFields[] = $field;
            }
        }

        $useTwoColumns = ($sidebarFields !== [] || $seoFields !== []);

        $html = '<div class="page-header">';
        $html .= '<h1>' . htmlspecialchars($title) . '</h1>';
        $html .= '<a href="' . $cancelUrl . '" class="btn btn-outline">Back</a>';
        $html .= '</div>';

        $html .= '<form method="POST" action="' . $actionUrl . '" enctype="multipart/form-data" data-lite-admin-form="1">';
        if ($csrfToken) {
            $html .= '<input type="hidden" name="_csrf" value="' . htmlspecialchars($csrfToken, ENT_QUOTES) . '">';
        }

        if ($useTwoColumns) {
            $html .= '<div class="admin-form-grid">';
            // Left Main Column
            $html .= '<div>';
            $html .= '<div class="card">';
            $html .= '<div class="section-card-title">Content</div>';
            foreach ($mainFields as $field) {
                $html .= $this->renderFormGroup($field, $entity, $errors, $old, $csrfToken, $relationOptions);
            }
            $html .= '</div>';

            if ($seoFields !== []) {
                $html .= '<div class="card">';
                $html .= '<div class="section-card-title">Search Engine Optimization (Google SERP Preview)</div>';
                $html .= '<div class="seo-preview">'
                    . '<div class="seo-preview-url" id="seo-preview-url">https://example.com/' . htmlspecialchars($res->slug) . '/...</div>'
                    . '<div class="seo-preview-title" id="seo-preview-title">Page Title Preview</div>'
                    . '<div class="seo-preview-desc" id="seo-preview-desc">Meta description preview will update live as you type...</div>'
                    . '</div>';
                foreach ($seoFields as $field) {
                    $html .= $this->renderFormGroup($field, $entity, $errors, $old, $csrfToken, $relationOptions);
                }
                $html .= '</div>';
            }
            $html .= '</div>';

            // Right Sidebar Column
            $html .= '<div>';
            $html .= '<div class="card">';
            $html .= '<div class="section-card-title">Publish &amp; Settings</div>';
            foreach ($sidebarFields as $field) {
                $html .= $this->renderFormGroup($field, $entity, $errors, $old, $csrfToken, $relationOptions);
            }
            $html .= '<div style="margin-top: 1.5rem; display: flex; gap: 0.6rem;">';
            $html .= '<button type="submit" class="btn btn-primary" style="flex:1;justify-content:center;">Save</button>';
            $html .= '<a href="' . $cancelUrl . '" class="btn btn-outline">Cancel</a>';
            $html .= '</div>';
            $html .= '</div>';
            $html .= '</div>';
            $html .= '</div>';
        } else {
            $html .= '<div class="card" style="max-width: 820px;">';
            foreach ($mainFields as $field) {
                $html .= $this->renderFormGroup($field, $entity, $errors, $old, $csrfToken, $relationOptions);
            }
            $html .= '<div style="margin-top: 2rem; display: flex; gap: 0.75rem;">';
            $html .= '<button type="submit" class="btn btn-primary">Save</button>';
            $html .= '<a href="' . $cancelUrl . '" class="btn btn-outline">Cancel</a>';
            $html .= '</div></div>';
        }

        $html .= '</form>';

        return $html;
    }

    /**
     * Render Entity Detail View with Audit Trail Timeline.
     *
     * @param list<\LiteAudit\Model\AuditRecord> $auditHistory
     */
    public function renderDetail(ResourceMetadata $res, object $entity, array $auditHistory = []): string
    {
        $id = $entity->{$res->primaryKey} ?? '';
        $title = "Details for {$res->title} #{$id}";
        $editUrl = "{$this->prefix}/{$res->slug}/edit/{$id}";
        $listUrl = "{$this->prefix}/{$res->slug}";

        $html = '<div class="page-header">';
        $html .= '<h1>' . htmlspecialchars($title) . '</h1>';
        $html .= '<div style="display: flex; gap: 0.5rem;">';
        $html .= '<a href="' . $editUrl . '" class="btn btn-primary">Edit</a>';
        $html .= '<a href="' . $listUrl . '" class="btn btn-outline">Back to List</a>';
        $html .= '</div></div>';

        $html .= '<div class="card"><table class="data-table"><tbody>';
        foreach ($res->columns as $prop => $col) {
            $val = $entity->{$prop} ?? null;
            $html .= '<tr><th style="width: 220px;">' . htmlspecialchars($col['label']) . '</th>';
            $html .= '<td>' . $this->formatValue($val, $col['format']) . '</td></tr>';
        }
        $html .= '</tbody></table></div>';

        if (!empty($auditHistory)) {
            $html .= '<div class="card">';
            $html .= '<h3 style="margin-bottom: 1rem;">Audit Trail</h3>';
            $html .= '<table class="data-table"><thead><tr><th>Timestamp</th><th>Action</th><th>Actor</th><th>Changes</th></tr></thead><tbody>';
            foreach ($auditHistory as $record) {
                $html .= '<tr>';
                $html .= '<td>' . htmlspecialchars($record->timestamp->format('Y-m-d H:i:s')) . '</td>';
                $badgeClass = match ($record->action->value) {
                    'create' => 'badge-success',
                    'delete' => 'badge-danger',
                    default => 'badge',
                };
                $html .= '<td><span class="badge ' . $badgeClass . '">' . strtoupper($record->action->value) . '</span></td>';
                $html .= '<td>' . htmlspecialchars($record->actorId ?? 'System') . '</td>';

                $diffText = '';
                foreach ($record->diff as $field => $delta) {
                    $diffText .= '<div><strong>' . htmlspecialchars($field) . ':</strong> '
                        . htmlspecialchars((string)($delta['old'] ?? 'null')) . ' &rarr; '
                        . htmlspecialchars((string)($delta['new'] ?? 'null')) . '</div>';
                }
                $html .= '<td>' . ($diffText ?: '<em>No field changes</em>') . '</td>';
                $html .= '</tr>';
            }
            $html .= '</tbody></table></div>';
        }

        return $html;
    }

    /**
     * @param array<string, mixed> $field
     * @param array<string, string> $errors
     * @param array<string, mixed> $old
     * @param array<string, array<string|int, string>> $relationOptions
     */
    private function renderFormGroup(
        array $field,
        ?object $entity,
        array $errors,
        array $old,
        ?string $csrfToken,
        array $relationOptions
    ): string {
        $prop = $field['property'];
        $currentVal = $old[$prop] ?? ($entity->{$prop} ?? null);
        $fieldError = $errors[$prop] ?? null;

        $counter = '';
        if ($prop === 'metaTitle') {
            $counter = '<span class="seo-counter" id="seo-title-count">0 / 60</span>';
        } elseif ($prop === 'metaDescription') {
            $counter = '<span class="seo-counter" id="seo-desc-count">0 / 160</span>';
        }

        $html = '<div class="form-group">';
        $html .= '<label for="field_' . htmlspecialchars($prop) . '">' . htmlspecialchars($field['label']);
        if (!empty($field['required'])) {
            $html .= ' <span style="color: var(--danger);">*</span>';
        }
        $html .= $counter . '</label>';

        $context = [
            'prefix'     => $this->prefix,
            'csrf_token' => $csrfToken,
            'options'    => $relationOptions[$prop] ?? ($field['options'] ?? []),
        ];
        $html .= $this->fields->render($field, $currentVal, $context);

        if (!empty($field['help'])) {
            $html .= '<div class="help-text">' . htmlspecialchars((string)$field['help']) . '</div>';
        }
        if ($fieldError) {
            $html .= '<div class="error-text">' . htmlspecialchars($fieldError) . '</div>';
        }
        $html .= '</div>';

        return $html;
    }

    private function renderSidebar(?string $currentSlug): string
    {
        $groups = [];
        foreach ($this->menuGroups as $group => $resources) {
            foreach ($resources as $res) {
                $groups[$group][] = ['slug' => $res->slug, 'title' => $res->title];
            }
        }
        foreach ($this->customPages as $group => $pages) {
            foreach ($pages as $page) {
                $groups[$group][] = $page;
            }
        }

        $html = '';
        foreach ($groups as $group => $items) {
            $html .= '<div class="group-title">' . htmlspecialchars($group) . '</div>';
            foreach ($items as $item) {
                $active = ($currentSlug === $item['slug']) ? 'active' : '';
                $url = "{$this->prefix}/{$item['slug']}";
                $html .= '<a href="' . $url . '" class="' . $active . '">';
                $html .= '<span>' . htmlspecialchars($item['title']) . '</span>';
                $html .= '</a>';
            }
        }

        return $html;
    }

    private function formatValue(mixed $val, ?string $format): string
    {
        if ($val === null) {
            return '<span style="color: var(--text-muted);">-</span>';
        }
        if ($val instanceof \DateTimeInterface) {
            return $val->format('Y-m-d H:i:s');
        }
        if (is_bool($val) || $format === 'boolean') {
            return $val ? '<span class="badge badge-success">Yes</span>' : '<span class="badge badge-danger">No</span>';
        }

        return htmlspecialchars((string)$val);
    }

    private function buildPageUrl(string $slug, int $page, array $queryParams): string
    {
        $params = array_merge($queryParams, ['page' => $page]);
        return "{$this->prefix}/{$slug}?" . http_build_query($params);
    }

    private function renderAdminScripts(): string
    {
        return <<<'JS'
(function () {
    // 1. Vietnamese-aware Auto-Slug Generator
    function toSlug(str) {
        return (str || '')
            .replace(/đ/g, 'd').replace(/Đ/g, 'd')
            .normalize('NFD').replace(/[\u0300-\u036f]/g, '')
            .toLowerCase()
            .replace(/[^a-z0-9]+/g, '-')
            .replace(/^-+|-+$/g, '');
    }

    document.querySelectorAll('[data-slug-source]').forEach(slugInput => {
        const srcName = slugInput.getAttribute('data-slug-source');
        const srcInput = document.getElementById('field_' + srcName);
        if (!srcInput) return;
        let touched = slugInput.value.trim() !== '';
        slugInput.addEventListener('input', () => { touched = slugInput.value.trim() !== ''; });
        srcInput.addEventListener('input', () => {
            if (!touched) slugInput.value = toSlug(srcInput.value);
            updateSeoPreview();
        });
    });

    document.querySelectorAll('[data-slug-regen]').forEach(btn => {
        btn.addEventListener('click', () => {
            const name = btn.getAttribute('data-slug-regen');
            const slugInput = document.getElementById('field_' + name);
            if (!slugInput) return;
            const srcInput = document.getElementById('field_' + slugInput.getAttribute('data-slug-source'));
            if (srcInput) slugInput.value = toSlug(srcInput.value);
            updateSeoPreview();
        });
    });

    // 2. Live Google SERP Preview & Character Counters
    function updateSeoPreview() {
        const titleEl = document.getElementById('field_metaTitle') || document.getElementById('field_title');
        const fallbackTitle = document.getElementById('field_title');
        const descEl = document.getElementById('field_metaDescription') || document.getElementById('field_excerpt');
        const slugEl = document.getElementById('field_slug');

        const pTitle = document.getElementById('seo-preview-title');
        const pDesc = document.getElementById('seo-preview-desc');
        const pUrl = document.getElementById('seo-preview-url');
        const cTitle = document.getElementById('seo-title-count');
        const cDesc = document.getElementById('seo-desc-count');

        const tVal = (titleEl && titleEl.value.trim()) ? titleEl.value.trim() : (fallbackTitle ? fallbackTitle.value.trim() : '');
        const dVal = descEl ? descEl.value.trim() : '';

        if (pTitle) pTitle.textContent = tVal || 'Page Title Preview';
        if (pDesc) pDesc.textContent = dVal || 'Meta description preview will appear here...';
        if (pUrl && slugEl && slugEl.value.trim()) {
            pUrl.textContent = window.location.origin + '/' + slugEl.value.trim();
        }
        if (cTitle) {
            const len = (document.getElementById('field_metaTitle')?.value || '').length;
            cTitle.textContent = len + ' / 60';
            cTitle.style.color = len > 60 ? 'var(--danger)' : 'var(--text-muted)';
        }
        if (cDesc) {
            const len = (document.getElementById('field_metaDescription')?.value || '').length;
            cDesc.textContent = len + ' / 160';
            cDesc.style.color = len > 160 ? 'var(--danger)' : 'var(--text-muted)';
        }
    }
    ['field_title', 'field_slug', 'field_metaTitle', 'field_metaDescription', 'field_excerpt'].forEach(id => {
        const el = document.getElementById(id);
        if (el) el.addEventListener('input', updateSeoPreview);
    });
    updateSeoPreview();

    // 3. Zero-Dependency WYSIWYG Rich Text Editor
    let activeMediaCallback = null;
    document.querySelectorAll('.rte-wrapper').forEach(wrap => {
        const name = wrap.getAttribute('data-rte-field');
        const editor = wrap.querySelector('.rte-editor');
        const source = wrap.querySelector('.rte-source');
        let inSource = false;

        function syncToSource() { if (!inSource) source.value = editor.innerHTML; }
        editor.addEventListener('input', syncToSource);

        wrap.closest('form')?.addEventListener('submit', () => {
            if (!inSource) source.value = editor.innerHTML;
        });

        wrap.querySelectorAll('.rte-toolbar button').forEach(btn => {
            btn.addEventListener('click', () => {
                const cmd = btn.getAttribute('data-cmd');
                const val = btn.getAttribute('data-val');
                const action = btn.getAttribute('data-action');

                if (action === 'source') {
                    inSource = !inSource;
                    if (inSource) {
                        source.value = editor.innerHTML;
                        editor.style.display = 'none';
                        source.style.display = 'block';
                    } else {
                        editor.innerHTML = source.value;
                        source.style.display = 'none';
                        editor.style.display = 'block';
                    }
                    return;
                }

                if (inSource) return;
                editor.focus();

                if (cmd) {
                    document.execCommand(cmd, false, val || null);
                    syncToSource();
                } else if (action === 'link') {
                    const url = prompt('Enter URL (https://...):', 'https://');
                    if (url) { document.execCommand('createLink', false, url); syncToSource(); }
                } else if (action === 'media') {
                    openMediaModal(asset => {
                        editor.focus();
                        document.execCommand('insertHTML', false, asset.img_tag || ('<img src="' + asset.relative_url + '" alt="" loading="lazy">'));
                        syncToSource();
                    });
                }
            });
        });
    });

    // 4. Image Field Direct Upload + Media Library Modal
    function getCsrfToken() {
        return document.querySelector('input[name="_csrf"]')?.value || '';
    }

    async function uploadFileToMedia(file, prefix) {
        const fd = new FormData();
        fd.append('file', file);
        fd.append('_csrf', getCsrfToken());
        const res = await fetch(prefix + '/media/upload', { method: 'POST', body: fd });
        return await res.json();
    }

    document.querySelectorAll('[data-image-upload]').forEach(input => {
        input.addEventListener('change', async () => {
            const file = input.files?.[0];
            if (!file) return;
            const name = input.getAttribute('data-image-upload');
            const wrap = input.closest('.image-field');
            const prefix = wrap?.getAttribute('data-admin-prefix') || '/admin';
            const json = await uploadFileToMedia(file, prefix);
            if (json.ok && json.asset) {
                setImageFieldValue(name, json.asset.relative_url);
            } else {
                alert(json.error || 'Upload failed');
            }
            input.value = '';
        });
    });

    function setImageFieldValue(name, url) {
        const inp = document.getElementById('field_' + name);
        const img = document.getElementById('preview_' + name);
        if (inp) inp.value = url;
        if (img) {
            img.src = url;
            img.parentElement.style.display = url ? 'flex' : 'none';
        }
    }

    document.querySelectorAll('[data-clear-image]').forEach(btn => {
        btn.addEventListener('click', () => setImageFieldValue(btn.getAttribute('data-clear-image'), ''));
    });

    document.querySelectorAll('[data-open-media]').forEach(btn => {
        btn.addEventListener('click', () => {
            const name = btn.getAttribute('data-open-media');
            openMediaModal(asset => setImageFieldValue(name, asset.relative_url));
        });
    });

    const modal = document.getElementById('lite-media-modal');
    const modalGrid = document.getElementById('lite-media-grid');
    const modalClose = document.getElementById('lite-modal-close');
    const modalUpload = document.getElementById('lite-modal-upload');

    async function loadMediaGrid() {
        if (!modal || !modalGrid) return;
        const prefix = modal.getAttribute('data-admin-prefix') || '/admin';
        modalGrid.innerHTML = '<p style="color:var(--text-muted);font-size:0.85rem;">Loading...</p>';
        try {
            const res = await fetch(prefix + '/media/json');
            const json = await res.json();
            const items = json.items || [];
            if (items.length === 0) {
                modalGrid.innerHTML = '<p style="color:var(--text-muted);font-size:0.85rem;">No media uploaded yet. Click "+ Upload Image" above.</p>';
                return;
            }
            modalGrid.innerHTML = '';
            items.forEach(item => {
                const tile = document.createElement('div');
                tile.className = 'media-tile';
                tile.innerHTML = '<img src="' + item.relative_url + '" alt=""><div class="media-tile-meta">' + (item.original_name || item.filename) + ' (' + item.width + 'x' + item.height + ')</div>';
                tile.addEventListener('click', () => {
                    if (activeMediaCallback) activeMediaCallback(item);
                    modal.classList.remove('open');
                });
                modalGrid.appendChild(tile);
            });
        } catch (e) {
            modalGrid.innerHTML = '<p style="color:var(--danger);font-size:0.85rem;">Failed loading media library.</p>';
        }
    }

    function openMediaModal(onSelect) {
        activeMediaCallback = onSelect;
        if (modal) {
            modal.classList.add('open');
            loadMediaGrid();
        }
    }

    if (modalClose) modalClose.addEventListener('click', () => modal?.classList.remove('open'));
    if (modalUpload) {
        modalUpload.addEventListener('change', async () => {
            const file = modalUpload.files?.[0];
            if (!file) return;
            const prefix = modal?.getAttribute('data-admin-prefix') || '/admin';
            const json = await uploadFileToMedia(file, prefix);
            modalUpload.value = '';
            if (json.ok) loadMediaGrid(); else alert(json.error || 'Upload failed');
        });
    }

    // 5. Repeater / JSON Key-Value Field
    document.querySelectorAll('[data-repeater-field]').forEach(wrap => {
        const name = wrap.getAttribute('data-repeater-field');
        const hidden = document.getElementById('field_' + name);
        const rowsEl = document.getElementById('repeater_rows_' + name);
        const addBtn = wrap.querySelector('[data-repeater-add]');
        let items = [];
        try { items = JSON.parse(hidden.value || '[]'); if (!Array.isArray(items)) items = []; } catch (e) { items = []; }

        function sync() { hidden.value = JSON.stringify(items); }
        function render() {
            rowsEl.innerHTML = '';
            items.forEach((row, idx) => {
                const div = document.createElement('div');
                div.className = 'repeater-row';
                div.innerHTML = '<input type="text" class="form-control" placeholder="Key" value="' + (row.key || '').replace(/"/g, '&quot;') + '">'
                    + '<input type="text" class="form-control" placeholder="Value" value="' + (row.value || '').replace(/"/g, '&quot;') + '">'
                    + '<button type="button" class="btn btn-outline btn-sm">&times;</button>';
                const inputs = div.querySelectorAll('input');
                inputs[0].addEventListener('input', () => { items[idx].key = inputs[0].value; sync(); });
                inputs[1].addEventListener('input', () => { items[idx].value = inputs[1].value; sync(); });
                div.querySelector('button').addEventListener('click', () => { items.splice(idx, 1); sync(); render(); });
                rowsEl.appendChild(div);
            });
        }
        addBtn?.addEventListener('click', () => { items.push({ key: '', value: '' }); sync(); render(); });
        render();
    });
})();
JS;
    }
}
