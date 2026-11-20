<?php

declare(strict_types=1);

namespace LiteValidate\Attribute;

use Attribute;

#[Attribute(Attribute::TARGET_PROPERTY)]
readonly class PositiveOrZero implements RuleInterface
{
    public function __construct(public ?string $message = null) {}

    public function validate(string $field, mixed $value, object|array $context): ?string
    {
        if ($value === null || $value === '' || !is_numeric($value)) return null;

        if (((float) $value) < 0) {
            return $this->message ?? "The {$field} field cannot be negative.";
        }
        return null;
    }
}
