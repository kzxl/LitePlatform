<?php

declare(strict_types=1);

namespace LiteValidate\Attribute;

use Attribute;
use LiteValidate\I18n\Translator;

#[Attribute(Attribute::TARGET_PROPERTY)]
readonly class Required implements RuleInterface
{
    public function __construct(public ?string $message = null) {}

    public function validate(string $field, mixed $value, object|array $context): ?string
    {
        if ($value === null || $value === '' || (is_array($value) && empty($value))) {
            return Translator::trans('required', ['field' => $field], $this->message);
        }
        return null;
    }
}
