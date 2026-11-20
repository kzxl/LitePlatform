<?php

declare(strict_types=1);

namespace LiteValidate\Attribute;

use Attribute;
use LiteValidate\I18n\Translator;

#[Attribute(Attribute::TARGET_PROPERTY)]
readonly class Email implements RuleInterface
{
    public function __construct(public ?string $message = null) {}

    public function validate(string $field, mixed $value, object|array $context): ?string
    {
        if ($value === null || $value === '') return null;

        if (!is_string($value) || !filter_var($value, FILTER_VALIDATE_EMAIL)) {
            return Translator::trans('email', ['field' => $field], $this->message);
        }
        return null;
    }
}
