<?php

declare(strict_types=1);

namespace LiteValidate\Attribute;

interface RuleInterface
{
    /**
     * Validate the given field value.
     *
     * @param string $field The field name being validated
     * @param mixed $value The field value
     * @param object|array $context The entire DTO or payload being validated
     * @return string|null Returns an error message if invalid, or null if valid.
     */
    public function validate(string $field, mixed $value, object|array $context): ?string;
}
