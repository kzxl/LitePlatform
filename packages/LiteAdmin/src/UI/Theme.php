<?php

declare(strict_types=1);

namespace LiteAdmin\UI;

/**
 * Built-in zero-dependency CSS styling for LiteAdmin.
 */
final class Theme
{
    public static function getStyles(): string
    {
        return <<<'CSS'
:root {
    --bg-main: #f8fafc;
    --bg-card: #ffffff;
    --border: #e2e8f0;
    --text-main: #0f172a;
    --text-muted: #64748b;
    --primary: #2563eb;
    --primary-hover: #1d4ed8;
    --danger: #ef4444;
    --danger-hover: #dc2626;
    --success: #10b981;
    --warning: #f59e0b;
    --sidebar-bg: #0f172a;
    --sidebar-text: #f1f5f9;
    --sidebar-hover: #1e293b;
    --radius: 8px;
    --font: system-ui, -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, Cantarell, sans-serif;
}

@media (prefers-color-scheme: dark) {
    :root {
        --bg-main: #090d16;
        --bg-card: #111827;
        --border: #1f2937;
        --text-main: #f3f4f6;
        --text-muted: #9ca3af;
        --sidebar-bg: #030712;
        --sidebar-hover: #111827;
    }
}

* { box-sizing: border-box; margin: 0; padding: 0; font-family: var(--font); }
body { background: var(--bg-main); color: var(--text-main); display: flex; min-height: 100vh; }

/* Sidebar */
.sidebar { width: 260px; background: var(--sidebar-bg); color: var(--sidebar-text); padding: 1.5rem 1rem; flex-shrink: 0; display: flex; flex-direction: column; }
.sidebar h2 { font-size: 1.25rem; font-weight: 700; margin-bottom: 2rem; display: flex; align-items: center; gap: 0.5rem; color: #60a5fa; }
.sidebar .group-title { font-size: 0.75rem; text-transform: uppercase; color: #94a3b8; margin: 1.25rem 0.5rem 0.5rem; font-weight: 600; letter-spacing: 0.05em; }
.sidebar a { display: flex; align-items: center; gap: 0.75rem; padding: 0.6rem 0.75rem; color: #cbd5e1; text-decoration: none; border-radius: var(--radius); font-size: 0.9rem; transition: background 0.15s; }
.sidebar a:hover, .sidebar a.active { background: var(--sidebar-hover); color: #ffffff; }

/* Main layout */
.main-wrapper { flex: 1; display: flex; flex-direction: column; overflow-x: hidden; }
header.topbar { background: var(--bg-card); border-bottom: 1px solid var(--border); padding: 1rem 2rem; display: flex; justify-content: space-between; align-items: center; }
.content-body { padding: 2rem; flex: 1; max-width: 1400px; width: 100%; margin: 0 auto; }

/* Cards & Containers */
.card { background: var(--bg-card); border: 1px solid var(--border); border-radius: var(--radius); padding: 1.5rem; box-shadow: 0 1px 3px rgba(0,0,0,0.05); margin-bottom: 1.5rem; }
.page-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem; }
.page-header h1 { font-size: 1.5rem; font-weight: 700; }

/* Controls & Buttons */
.btn { display: inline-flex; align-items: center; gap: 0.4rem; padding: 0.5rem 1rem; font-size: 0.875rem; font-weight: 500; border-radius: var(--radius); text-decoration: none; cursor: pointer; border: 1px solid transparent; transition: all 0.15s; }
.btn-primary { background: var(--primary); color: #fff; }
.btn-primary:hover { background: var(--primary-hover); }
.btn-danger { background: var(--danger); color: #fff; }
.btn-danger:hover { background: var(--danger-hover); }
.btn-outline { background: transparent; border-color: var(--border); color: var(--text-main); }
.btn-outline:hover { background: var(--border); }
.btn-sm { padding: 0.25rem 0.5rem; font-size: 0.75rem; }

/* Table */
.table-responsive { overflow-x: auto; }
table.data-table { width: 100%; border-collapse: collapse; text-align: left; font-size: 0.875rem; }
table.data-table th, table.data-table td { padding: 0.75rem 1rem; border-bottom: 1px solid var(--border); }
table.data-table th { background: var(--bg-main); font-weight: 600; color: var(--text-muted); }
table.data-table tr:hover { background: rgba(0,0,0,0.02); }

/* Form inputs */
.form-group { margin-bottom: 1.25rem; }
.form-group label { display: block; font-size: 0.875rem; font-weight: 600; margin-bottom: 0.4rem; }
.form-control { width: 100%; padding: 0.6rem 0.75rem; font-size: 0.875rem; border: 1px solid var(--border); border-radius: var(--radius); background: var(--bg-card); color: var(--text-main); }
.form-control:focus { outline: none; border-color: var(--primary); ring: 2px var(--primary); }
.error-text { color: var(--danger); font-size: 0.75rem; margin-top: 0.25rem; }

/* Badges */
.badge { display: inline-block; padding: 0.2rem 0.5rem; border-radius: 9999px; font-size: 0.75rem; font-weight: 600; }
.badge-success { background: #dcfce7; color: #166534; }
.badge-danger { background: #fee2e2; color: #991b1b; }

/* Pagination */
.pagination { display: flex; justify-content: space-between; align-items: center; margin-top: 1.5rem; font-size: 0.875rem; color: var(--text-muted); }
.pagination .nav-links { display: flex; gap: 0.5rem; }

/* Flash message */
.alert { padding: 0.75rem 1rem; border-radius: var(--radius); margin-bottom: 1rem; font-size: 0.875rem; }
.alert-success { background: #dcfce7; border: 1px solid #86efac; color: #166534; }
.alert-danger { background: #fee2e2; border: 1px solid #fca5a5; color: #991b1b; }

/* KPI Metrics */
.metrics-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 1.25rem; margin-bottom: 2rem; }
.metric-card { background: var(--bg-card); border: 1px solid var(--border); border-radius: var(--radius); padding: 1.25rem; box-shadow: 0 1px 3px rgba(0,0,0,0.05); }
.metric-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.5rem; }
.metric-label { font-size: 0.875rem; font-weight: 600; color: var(--text-muted); }
.metric-icon { font-size: 1.25rem; }
.metric-value { font-size: 1.75rem; font-weight: 700; color: var(--text-main); line-height: 1.2; }
.metric-desc { font-size: 0.75rem; color: var(--text-muted); margin-top: 0.35rem; }

/* WordPress/Filament-style 2-Column Form Layout */
.admin-form-grid { display: grid; grid-template-columns: minmax(0, 1fr) 330px; gap: 1.5rem; align-items: start; }
@media (max-width: 960px) { .admin-form-grid { grid-template-columns: 1fr; } }
.section-card-title { font-size: 0.95rem; font-weight: 700; margin-bottom: 1rem; padding-bottom: 0.6rem; border-bottom: 1px solid var(--border); }
.help-text { color: var(--text-muted); font-size: 0.75rem; margin-top: 0.25rem; }

/* Slug Field */
.slug-wrap { display: flex; gap: 0.5rem; align-items: center; }

/* Zero-dep WYSIWYG Rich Text Editor */
.rte-wrapper { border: 1px solid var(--border); border-radius: var(--radius); overflow: hidden; background: var(--bg-card); }
.rte-toolbar { display: flex; flex-wrap: wrap; gap: 0.25rem; padding: 0.45rem 0.6rem; background: var(--bg-main); border-bottom: 1px solid var(--border); align-items: center; }
.rte-toolbar button { background: transparent; border: 1px solid transparent; border-radius: 5px; padding: 0.28rem 0.52rem; font-size: 0.8rem; cursor: pointer; color: var(--text-main); }
.rte-toolbar button:hover { background: var(--bg-card); border-color: var(--border); }
.rte-sep { width: 1px; height: 18px; background: var(--border); margin: 0 0.25rem; }
.rte-editor { min-height: 280px; padding: 1rem; outline: none; line-height: 1.7; font-size: 0.94rem; color: var(--text-main); }
.rte-editor h2 { font-size: 1.35rem; margin: 1rem 0 0.5rem; }
.rte-editor h3 { font-size: 1.15rem; margin: 0.85rem 0 0.4rem; }
.rte-editor p { margin-bottom: 0.75rem; }
.rte-editor ul, .rte-editor ol { padding-left: 1.5rem; margin-bottom: 0.75rem; }
.rte-editor blockquote { border-left: 3px solid var(--primary); padding-left: 0.85rem; color: var(--text-muted); margin: 0.75rem 0; }
.rte-editor img { max-width: 100%; height: auto; border-radius: 6px; margin: 0.5rem 0; }
.rte-source { border: none; border-radius: 0; font-family: ui-monospace, SFMono-Regular, Menlo, monospace; font-size: 0.84rem; }

/* Image Field & Media Picker Modal */
.image-field { display: flex; flex-direction: column; gap: 0.5rem; }
.image-preview-box { border: 1px solid var(--border); border-radius: var(--radius); overflow: hidden; background: var(--bg-main); max-height: 180px; display: flex; align-items: center; justify-content: center; }
.image-preview-box img { max-width: 100%; max-height: 180px; object-fit: contain; display: block; }
.image-field-actions { display: flex; gap: 0.4rem; flex-wrap: wrap; }
.media-modal-backdrop { position: fixed; inset: 0; background: rgba(15, 23, 42, 0.6); z-index: 1000; display: none; align-items: center; justify-content: center; padding: 1.5rem; }
.media-modal-backdrop.open { display: flex; }
.media-modal { background: var(--bg-card); border: 1px solid var(--border); border-radius: 10px; width: 100%; max-width: 820px; max-height: 85vh; display: flex; flex-direction: column; overflow: hidden; }
.media-modal-head { display: flex; justify-content: space-between; align-items: center; padding: 1rem 1.25rem; border-bottom: 1px solid var(--border); }
.media-modal-body { padding: 1.25rem; overflow-y: auto; display: grid; grid-template-columns: repeat(auto-fill, minmax(150px, 1fr)); gap: 0.85rem; }
.media-tile { border: 1px solid var(--border); border-radius: 8px; overflow: hidden; cursor: pointer; background: var(--bg-main); display: flex; flex-direction: column; }
.media-tile:hover { border-color: var(--primary); }
.media-tile img { width: 100%; height: 110px; object-fit: cover; }
.media-tile-meta { padding: 0.4rem 0.5rem; font-size: 0.72rem; color: var(--text-muted); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }

/* SEO Google SERP Live Preview */
.seo-preview { background: var(--bg-main); border: 1px solid var(--border); border-radius: var(--radius); padding: 0.9rem 1rem; margin-bottom: 1rem; }
.seo-preview-url { font-size: 0.75rem; color: var(--text-muted); margin-bottom: 0.15rem; }
.seo-preview-title { font-size: 1.05rem; color: #1a0dab; font-weight: 600; margin-bottom: 0.2rem; }
.seo-preview-desc { font-size: 0.82rem; color: var(--text-muted); line-height: 1.45; }
.seo-counter { font-size: 0.72rem; color: var(--text-muted); float: right; font-weight: 500; }

/* Repeater */
.repeater-row { display: flex; gap: 0.4rem; margin-bottom: 0.45rem; }
CSS;
    }
}
