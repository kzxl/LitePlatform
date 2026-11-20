<?php

declare(strict_types=1);

namespace LiteValidate\Attribute;

use Attribute;

#[Attribute(Attribute::TARGET_PROPERTY)]
readonly class Numeric implements RuleInterface
{
    public function __construct(public ?string $message = null) {}

    public function validate(string $field, mixed $value, object|array $context): ?string
    {
        if ($value === null || $value === '') return null;

        if (!is_numeric($value)) {
            return $this->message ?? "The {$field} field must be numeric.";
        }
        return null;
    }
}
