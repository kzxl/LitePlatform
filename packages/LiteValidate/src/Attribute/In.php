<?php

declare(strict_types=1);

namespace LiteValidate\Attribute;

use Attribute;

#[Attribute(Attribute::TARGET_PROPERTY)]
readonly class In implements RuleInterface
{
    /**
     * @param array<int|string, mixed> $values
     */
    public function __construct(
        public array $values,
        public ?string $message = null,
    ) {}

    public function validate(string $field, mixed $value, object|array $context): ?string
    {
        if ($value === null || $value === '') return null;

        if (!in_array($value, $this->values, true)) {
            $allowed = implode(', ', array_map(fn($v) => (string) $v, $this->values));
            return $this->message ?? "The {$field} field must be one of: {$allowed}.";
        }
        return null;
    }
}
