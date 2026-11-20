<?php

declare(strict_types=1);

namespace LiteQueue;

/**
 * Configuration options for the background worker process.
 */
final readonly class WorkerOptions
{
    public function __construct(
        public int $maxAttempts = 3,
        public int $timeout = 60,
        public int $sleep = 3,
        public int $maxMemoryMb = 128,
        public int $maxJobs = 0,
        public bool $stopOnError = false,
    ) {}
}
