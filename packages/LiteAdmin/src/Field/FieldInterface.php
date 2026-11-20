<?php

declare(strict_types=1);

namespace LiteAdmin\Field;

/**
 * Contract for pluggable LiteAdmin form field renderers.
 * Implement and register via $admin->registerField('custom_type', new MyField()).
 */
interface FieldInterface
{
    /**
     * Render the HTML input control for a resource field.
     *
     * @param array<string, mixed> $field Field metadata from ResourceMetadata
     * @param mixed $value Current property value
     * @param array<string, mixed> $context Extra context (prefix, options, csrf_token)
     */
    public function render(array $field, mixed $value, array $context = []): string;
}
