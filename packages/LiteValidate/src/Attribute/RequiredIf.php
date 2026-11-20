<?php

declare(strict_types=1);

namespace LiteValidate\Attribute;

use Attribute;
use LiteValidate\I18n\Translator;

#[Attribute(Attribute::TARGET_PROPERTY | Attribute::IS_REPEATABLE)]
readonly class RequiredIf implements RuleInterface
{
    /**
     * @param string $otherField The context field name to evaluate
     * @param mixed $otherValue The expected value or array of values that triggers the requirement
     * @param string|null $message Custom error message override
     */
    public function __construct(
        public string $otherField,
        public mixed $otherValue = true,
        public ?string $message = null
    ) {}

    public function validate(string $field, mixed $value, object|array $context): ?string
    {
        $actualValue = is_array($context)
            ? ($context[$this->otherField] ?? null)
            : ($context->{$this->otherField} ?? null);

        $conditionMet = is_array($this->otherValue)
            ? in_array($actualValue, $this->otherValue, false)
            : ($actualValue == $this->otherValue);

        if ($conditionMet) {
            if ($value === null || $value === '' || (is_array($value) && empty($value))) {
                return Translator::trans('required_if', [
                    'field' => $field,
                    'other' => $this->otherField,
                    'value' => is_array($this->otherValue) ? implode(', ', $this->otherValue) : (string)$this->otherValue,
                ], $this->message);
            }
        }

        return null;
    }
}
