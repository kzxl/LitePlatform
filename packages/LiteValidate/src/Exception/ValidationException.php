<?php

declare(strict_types=1);

namespace LiteValidate\Exception;

use RuntimeException;

class ValidationException extends RuntimeException
{
    /**
     * @param array<string, string[]> $errors
     */
    public function __construct(
        private readonly array $errors,
        string $message = 'The given data failed validation.',
        int $code = 422,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, $code, $previous);
    }

    /**
     * @return array<string, string[]>
     */
    public function getErrors(): array
    {
        return $this->errors;
    }
}
