<?php

declare(strict_types=1);

namespace LiteValidate\Attribute;

use Attribute;
use LiteValidate\I18n\Translator;

#[Attribute(Attribute::TARGET_PROPERTY)]
readonly class Length implements RuleInterface
{
    public function __construct(
        public ?int $min = null,
        public ?int $max = null,
        public ?string $message = null,
    ) {}

    public function validate(string $field, mixed $value, object|array $context): ?string
    {
        if ($value === null || $value === '') return null;

        $len = is_string($value) ? mb_strlen($value, 'UTF-8') : (is_countable($value) ? count($value) : 0);

        if ($this->min !== null && $len < $this->min) {
            return Translator::trans('length.min', ['field' => $field, 'min' => $this->min], $this->message);
        }
        if ($this->max !== null && $len > $this->max) {
            return Translator::trans('length.max', ['field' => $field, 'max' => $this->max], $this->message);
        }
        return null;
    }
}
