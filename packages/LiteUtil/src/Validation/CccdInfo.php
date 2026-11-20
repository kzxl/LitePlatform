<?php

declare(strict_types=1);

namespace LiteUtil\Validation;

/**
 * Decoded Citizen Identity Card (CCCD) information.
 */
readonly class CccdInfo
{
    public function __construct(
        public string $id,
        public int $birthYear,
        public bool $isMale,
        public string $provinceName,
    ) {}

    public function getGender(): string
    {
        return $this->isMale ? 'Nam' : 'Nữ';
    }
}
