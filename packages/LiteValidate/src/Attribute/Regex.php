<?php

declare(strict_types=1);

namespace LiteValidate\Attribute;

use Attribute;

#[Attribute(Attribute::TARGET_PROPERTY)]
readonly class Regex implements RuleInterface
{
    public function __construct(
        public string $pattern,
        public ?string $message = null,
    ) {}

    public function validate(string $field, mixed $value, object|array $context): ?string
    {
        if ($value === null || $value === '') return null;

        if (!preg_match($this->pattern, (string) $value)) {
            return $this->message ?? "The {$field} field format is invalid.";
        }
        return null;
    }
}
