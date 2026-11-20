<?php

declare(strict_types=1);

namespace LiteQueue\Driver;

/**
 * Immutable value object representing a queued job record.
 */
final readonly class QueueRecord
{
    public function __construct(
        public string|int $id,
        public string $queue,
        public string $payload,
        public int $attempts,
        public ?int $reservedAt = null,
        public int $availableAt = 0,
        public int $createdAt = 0,
    ) {}
}
