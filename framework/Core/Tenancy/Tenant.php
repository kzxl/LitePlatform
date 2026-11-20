<?php

declare(strict_types=1);

namespace Lite\Framework\Core\Tenancy;

/**
 * Value Object representing a Company / Tenant.
 */
class Tenant
{
    public function __construct(
        public readonly int $id,
        public readonly string $code,
        public readonly string $name,
        public readonly ?string $domain = null,
        public readonly ?string $subdomain = null,
        public readonly array $databaseConfig = [],
        public readonly bool $isActive = true
    ) {}

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'name' => $this->name,
            'domain' => $this->domain,
            'subdomain' => $this->subdomain,
            'is_active' => $this->isActive,
        ];
    }
}
