<?php

declare(strict_types=1);

namespace LiteValidate;

use LiteValidate\Exception\ValidationException;

/**
 * Result object encapsulating validation status and field error messages.
 */
class ValidationResult
{
    /**
     * @param array<string, string[]> $errors Map of field name to array of error messages
     */
    public function __construct(
        private array $errors = [],
        private readonly mixed $validatedData = null,
    ) {}

    public function isValid(): bool
    {
        return empty($this->errors);
    }

    public function isFailed(): bool
    {
        return !$this->isValid();
    }

    /**
     * @return array<string, string[]>
     */
    public function getErrors(): array
    {
        return $this->errors;
    }

    public function addError(string $field, string $message): void
    {
        $this->errors[$field][] = $message;
    }

    /**
     * Get first error for a specific field or across all fields.
     */
    public function getFirstError(?string $field = null): ?string
    {
        if ($field !== null) {
            return $this->errors[$field][0] ?? null;
        }

        foreach ($this->errors as $fieldErrors) {
            if (!empty($fieldErrors)) {
                return $fieldErrors[0];
            }
        }

        return null;
    }

    /**
     * Return validated data / hydrated DTO.
     */
    public function validated(): mixed
    {
        return $this->validatedData;
    }

    /**
     * Throw a ValidationException if validation failed.
     * @throws ValidationException
     */
    public function validateOrFail(): mixed
    {
        if ($this->isFailed()) {
            throw new ValidationException($this->errors);
        }

        return $this->validatedData;
    }
}
