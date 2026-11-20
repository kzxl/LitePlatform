<?php

declare(strict_types=1);

namespace LiteAdmin\Attribute;

use Attribute;

/**
 * Configures how an entity property is rendered in Create/Edit forms.
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
final readonly class AdminField
{
    /**
     * @param ?string $label Field display label
     * @param string $type Input type: 'text', 'slug', 'richtext', 'image', 'select', 'json', 'textarea', 'number', 'checkbox', 'datetime', 'email', 'url'
     * @param bool $required Whether field is required
     * @param ?string $placeholder Placeholder hint
     * @param array<string|int, string> $options Options for 'select' inputs
     * @param bool $readonly Whether input is read-only
     * @param string $section Form placement: 'main', 'sidebar', or 'seo'
     * @param ?string $slugFrom Source field name for auto-slug generation (e.g. 'title')
     * @param ?string $help Optional helper text shown beneath input
     * @param ?string $relation Optional DB table relation spec 'table:value_col:label_col' for dynamic select options
     */
    public function __construct(
        public ?string $label = null,
        public string $type = 'text',
        public bool $required = false,
        public ?string $placeholder = null,
        public array $options = [],
        public bool $readonly = false,
        public string $section = 'main',
        public ?string $slugFrom = null,
        public ?string $help = null,
        public ?string $relation = null,
    ) {
    }
}
