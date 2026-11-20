<?php

declare(strict_types=1);

namespace LiteValidate\Attribute;

use Attribute;

#[Attribute(Attribute::TARGET_PROPERTY | Attribute::IS_REPEATABLE)]
readonly class ValidateIf implements RuleInterface
{
    /**
     * @param string $otherField The context field name to evaluate
     * @param mixed $otherValue The expected value or array of values that activates this rule
     * @param RuleInterface $rule The rule to execute when the condition is met
     */
    public function __construct(
        public string $otherField,
        public mixed $otherValue,
        public RuleInterface $rule
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
            return $this->rule->validate($field, $value, $context);
        }

        return null;
    }
}
