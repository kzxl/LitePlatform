<?php

declare(strict_types=1);

namespace LiteAdmin\Field;

use DateTimeInterface;

/**
 * Extensible Field Registry powering LiteAdmin v2 forms.
 * Ships with production CMS fields: select, slug (VN auto-slug), richtext (WYSIWYG + Media insert),
 * image (direct upload + Media Picker + thumbnail), json/repeater, datetime, checkbox, number, text/email/url.
 */
final class FieldRegistry
{
    /** @var array<string, FieldInterface|callable(array<string, mixed>, mixed, array<string, mixed>): string> */
    private array $custom = [];

    /**
     * Register or override a field type renderer.
     *
     * @param FieldInterface|callable(array<string, mixed>, mixed, array<string, mixed>): string $renderer
     */
    public function register(string $type, FieldInterface|callable $renderer): self
    {
        $this->custom[strtolower($type)] = $renderer;
        return $this;
    }

    public function has(string $type): bool
    {
        return isset($this->custom[strtolower($type)]);
    }

    /**
     * @param array<string, mixed> $field
     * @param array<string, mixed> $context
     */
    public function render(array $field, mixed $value, array $context = []): string
    {
        $type = strtolower((string)($field['type'] ?? 'text'));

        if (isset($this->custom[$type])) {
            $handler = $this->custom[$type];
            return $handler instanceof FieldInterface
                ? $handler->render($field, $value, $context)
                : $handler($field, $value, $context);
        }

        return match ($type) {
            'select'            => $this->renderSelect($field, $value, $context),
            'slug'              => $this->renderSlug($field, $value),
            'richtext'          => $this->renderRichText($field, $value, $context),
            'image'             => $this->renderImage($field, $value, $context),
            'gallery'           => $this->renderGallery($field, $value, $context),
            'json', 'repeater'  => $this->renderRepeater($field, $value),
            'textarea'          => $this->renderTextarea($field, $value),
            'checkbox'          => $this->renderCheckbox($field, $value),
            'number'            => $this->renderNumber($field, $value),
            'date'              => $this->renderDate($field, $value),
            'datetime'          => $this->renderDateTime($field, $value),
            'email', 'url', 'password' => $this->renderBasicInput($type, $field, $value),
            default             => $this->renderBasicInput('text', $field, $value),
        };
    }

    /**
     * @param array<string, mixed> $field
     * @param array<string, mixed> $context
     */
    private function renderSelect(array $field, mixed $value, array $context): string
    {
        $name = $this->e((string)$field['property']);
        $req = !empty($field['required']) ? 'required' : '';
        $disabled = !empty($field['readonly']) ? 'disabled' : '';
        $options = $context['options'] ?? ($field['options'] ?? []);
        $current = (string)($value ?? '');

        $html = "<select name=\"{$name}\" id=\"field_{$name}\" class=\"form-control\" {$req} {$disabled}>";
        if (empty($field['required'])) {
            $html .= '<option value="">— Select —</option>';
        }
        foreach ($options as $optVal => $optLabel) {
            $sel = ((string)$optVal === $current) ? 'selected' : '';
            $html .= '<option value="' . $this->e((string)$optVal) . '" ' . $sel . '>' . $this->e((string)$optLabel) . '</option>';
        }
        $html .= '</select>';

        return $html;
    }

    /**
     * @param array<string, mixed> $field
     */
    private function renderSlug(array $field, mixed $value): string
    {
        $name = $this->e((string)$field['property']);
        $val = $this->e((string)($value ?? ''));
        $placeholder = !empty($field['placeholder']) ? 'placeholder="' . $this->e((string)$field['placeholder']) . '"' : 'placeholder="auto-generated-slug"';
        $source = $this->e((string)($field['slugFrom'] ?? 'title'));
        $req = !empty($field['required']) ? 'required' : '';

        return <<<HTML
<div class="slug-wrap">
    <input type="text" name="{$name}" id="field_{$name}" value="{$val}" class="form-control" data-slug-source="{$source}" {$placeholder} {$req}>
    <button type="button" class="btn btn-outline btn-sm" data-slug-regen="{$name}" title="Regenerate slug from {$source}">Auto</button>
</div>
HTML;
    }

    /**
     * @param array<string, mixed> $field
     * @param array<string, mixed> $context
     */
    private function renderRichText(array $field, mixed $value, array $context): string
    {
        $name = $this->e((string)$field['property']);
        $val = $this->e((string)($value ?? ''));
        $rawHtml = (string)($value ?? '');
        $prefix = $this->e((string)($context['prefix'] ?? '/admin'));

        return <<<HTML
<div class="rte-wrapper" data-rte-field="{$name}" data-admin-prefix="{$prefix}">
    <div class="rte-toolbar" role="toolbar" aria-label="Rich Text Formatting">
        <button type="button" data-cmd="formatBlock" data-val="H2" title="Heading 2">H2</button>
        <button type="button" data-cmd="formatBlock" data-val="H3" title="Heading 3">H3</button>
        <button type="button" data-cmd="formatBlock" data-val="P" title="Paragraph">P</button>
        <span class="rte-sep"></span>
        <button type="button" data-cmd="bold" title="Bold"><strong>B</strong></button>
        <button type="button" data-cmd="italic" title="Italic"><em>I</em></button>
        <button type="button" data-cmd="underline" title="Underline"><u>U</u></button>
        <span class="rte-sep"></span>
        <button type="button" data-cmd="insertUnorderedList" title="Bullet List">&bull; List</button>
        <button type="button" data-cmd="insertOrderedList" title="Numbered List">1. List</button>
        <button type="button" data-cmd="formatBlock" data-val="BLOCKQUOTE" title="Quote">&ldquo; Quote</button>
        <span class="rte-sep"></span>
        <button type="button" data-action="link" title="Insert Link">Link</button>
        <button type="button" data-action="media" title="Insert Image from Media Library">Image</button>
        <button type="button" data-cmd="removeFormat" title="Clear Formatting">Clear</button>
        <button type="button" data-action="source" style="margin-left:auto;" title="Toggle HTML Source">&lt;/&gt; HTML</button>
    </div>
    <div class="rte-editor" contenteditable="true" data-rte-editor="{$name}">{$rawHtml}</div>
    <textarea name="{$name}" id="field_{$name}" class="form-control rte-source" rows="10" style="display:none;">{$val}</textarea>
</div>
HTML;
    }

    /**
     * @param array<string, mixed> $field
     * @param array<string, mixed> $context
     */
    private function renderImage(array $field, mixed $value, array $context): string
    {
        $name = $this->e((string)$field['property']);
        $val = $this->e((string)($value ?? ''));
        $prefix = $this->e((string)($context['prefix'] ?? '/admin'));
        $hasImg = trim((string)$value) !== '';
        $previewStyle = $hasImg ? '' : 'display:none;';

        return <<<HTML
<div class="image-field" data-image-field="{$name}" data-admin-prefix="{$prefix}">
    <div class="image-preview-box" style="{$previewStyle}">
        <img src="{$val}" alt="Preview" id="preview_{$name}">
    </div>
    <input type="text" name="{$name}" id="field_{$name}" value="{$val}" class="form-control" placeholder="/uploads/...">
    <div class="image-field-actions">
        <label class="btn btn-outline btn-sm" style="margin:0;cursor:pointer;">
            Upload
            <input type="file" accept="image/*" data-image-upload="{$name}" style="display:none;">
        </label>
        <button type="button" class="btn btn-outline btn-sm" data-open-media="{$name}">Media Library</button>
        <button type="button" class="btn btn-outline btn-sm" data-clear-image="{$name}">Clear</button>
    </div>
</div>
HTML;
    }

    /**
     * Multi-image gallery field storing a JSON array of image URLs (["/uploads/a.jpg", ...]).
     *
     * @param array<string, mixed> $field
     * @param array<string, mixed> $context
     */
    private function renderGallery(array $field, mixed $value, array $context): string
    {
        $name = $this->e((string)$field['property']);
        $prefix = $this->e((string)($context['prefix'] ?? '/admin'));
        $json = is_string($value) && trim($value) !== '' ? $value : '[]';
        $safeJson = $this->e($json);

        return <<<HTML
<div class="gallery-field" data-gallery-field="{$name}" data-admin-prefix="{$prefix}">
    <div class="gallery-grid" id="gallery_grid_{$name}"></div>
    <div class="image-field-actions">
        <label class="btn btn-outline btn-sm" style="margin:0;cursor:pointer;">
            + Upload Photos
            <input type="file" accept="image/*" multiple data-gallery-upload="{$name}" style="display:none;">
        </label>
        <button type="button" class="btn btn-outline btn-sm" data-gallery-media="{$name}">+ From Media Library</button>
    </div>
    <input type="hidden" name="{$name}" id="field_{$name}" value="{$safeJson}">
</div>
HTML;
    }

    /**
     * @param array<string, mixed> $field
     */
    private function renderDate(array $field, mixed $value): string
    {
        $name = $this->e((string)$field['property']);
        $formatted = $value instanceof DateTimeInterface
            ? $value->format('Y-m-d')
            : $this->e(substr((string)($value ?? ''), 0, 10));
        $readonly = !empty($field['readonly']) ? 'readonly' : '';
        $req = !empty($field['required']) ? 'required' : '';

        return "<input type=\"date\" name=\"{$name}\" id=\"field_{$name}\" value=\"{$formatted}\" class=\"form-control\" {$readonly} {$req}>";
    }

    /**
     * @param array<string, mixed> $field
     */
    private function renderRepeater(array $field, mixed $value): string
    {
        $name = $this->e((string)$field['property']);
        $json = is_string($value) && $value !== '' ? $value : '[]';
        $safeJson = $this->e($json);

        return <<<HTML
<div class="repeater-wrap" data-repeater-field="{$name}">
    <div class="repeater-rows" id="repeater_rows_{$name}"></div>
    <button type="button" class="btn btn-outline btn-sm" data-repeater-add="{$name}">+ Add Item</button>
    <input type="hidden" name="{$name}" id="field_{$name}" value="{$safeJson}">
</div>
HTML;
    }

    /**
     * @param array<string, mixed> $field
     */
    private function renderTextarea(array $field, mixed $value): string
    {
        $name = $this->e((string)$field['property']);
        $val = $this->e((string)($value ?? ''));
        $placeholder = !empty($field['placeholder']) ? 'placeholder="' . $this->e((string)$field['placeholder']) . '"' : '';
        $readonly = !empty($field['readonly']) ? 'readonly' : '';
        $req = !empty($field['required']) ? 'required' : '';
        $seoAttr = $name === 'metaDescription' ? 'data-seo-desc="1" maxlength="320"' : '';

        return "<textarea name=\"{$name}\" id=\"field_{$name}\" class=\"form-control\" rows=\"3\" {$placeholder} {$readonly} {$req} {$seoAttr}>{$val}</textarea>";
    }

    /**
     * @param array<string, mixed> $field
     */
    private function renderCheckbox(array $field, mixed $value): string
    {
        $name = $this->e((string)$field['property']);
        $checked = $value ? 'checked' : '';
        $readonly = !empty($field['readonly']) ? 'disabled' : '';

        return "<input type=\"hidden\" name=\"{$name}\" value=\"0\">"
            . "<label style=\"display:inline-flex;align-items:center;gap:0.5rem;font-weight:500;cursor:pointer;\">"
            . "<input type=\"checkbox\" name=\"{$name}\" id=\"field_{$name}\" value=\"1\" {$checked} {$readonly} style=\"width:18px;height:18px;\">"
            . "<span>Enabled</span></label>";
    }

    /**
     * @param array<string, mixed> $field
     */
    private function renderNumber(array $field, mixed $value): string
    {
        $name = $this->e((string)$field['property']);
        $val = $this->e((string)($value ?? ''));
        $placeholder = !empty($field['placeholder']) ? 'placeholder="' . $this->e((string)$field['placeholder']) . '"' : '';
        $readonly = !empty($field['readonly']) ? 'readonly' : '';
        $req = !empty($field['required']) ? 'required' : '';

        return "<input type=\"number\" step=\"any\" name=\"{$name}\" id=\"field_{$name}\" value=\"{$val}\" class=\"form-control\" {$placeholder} {$readonly} {$req}>";
    }

    /**
     * @param array<string, mixed> $field
     */
    private function renderDateTime(array $field, mixed $value): string
    {
        $name = $this->e((string)$field['property']);
        $formatted = $value instanceof DateTimeInterface
            ? $value->format('Y-m-d\TH:i')
            : $this->e((string)($value ?? ''));
        $readonly = !empty($field['readonly']) ? 'readonly' : '';

        return "<input type=\"datetime-local\" name=\"{$name}\" id=\"field_{$name}\" value=\"{$formatted}\" class=\"form-control\" {$readonly}>";
    }

    /**
     * @param array<string, mixed> $field
     */
    private function renderBasicInput(string $type, array $field, mixed $value): string
    {
        $name = $this->e((string)$field['property']);
        $val = $this->e((string)($value ?? ''));
        $placeholder = !empty($field['placeholder']) ? 'placeholder="' . $this->e((string)$field['placeholder']) . '"' : '';
        $readonly = !empty($field['readonly']) ? 'readonly style="background: var(--bg-main); cursor: not-allowed;"' : '';
        $req = !empty($field['required']) ? 'required' : '';
        $seoAttr = $name === 'metaTitle' ? 'data-seo-title="1"' : '';

        return "<input type=\"{$type}\" name=\"{$name}\" id=\"field_{$name}\" value=\"{$val}\" class=\"form-control\" {$placeholder} {$readonly} {$req} {$seoAttr}>";
    }

    private function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
