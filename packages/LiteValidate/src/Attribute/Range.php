<?php

declare(strict_types=1);

namespace LiteValidate\Attribute;

use Attribute;

#[Attribute(Attribute::TARGET_PROPERTY)]
readonly class Range implements RuleInterface
{
    public function __construct(
        public ?float $min = null,
        public ?float $max = null,
        public ?string $message = null,
    ) {}

    public function validate(string $field, mixed $value, object|array $context): ?string
    {
        if ($value === null || $value === '' || !is_numeric($value)) return null;

        $val = (float) $value;
        if ($this->min !== null && $val < $this->min) {
            return $this->message ?? "The {$field} field must be at least {$this->min}.";
        }
        if ($this->max !== null && $val > $this->max) {
            return $this->message ?? "The {$field} field must not exceed {$this->max}.";
        }
        return null;
    }
}
