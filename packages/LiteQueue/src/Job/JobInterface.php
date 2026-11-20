<?php

declare(strict_types=1);

namespace LiteQueue\Job;

/**
 * Standard contract for dispatchable background jobs.
 */
interface JobInterface
{
    /**
     * Execute the job's business logic.
     */
    public function handle(): void;

    /**
     * Called when the job has completely failed after exceeding all retry attempts.
     */
    public function failed(\Throwable $exception): void;

    /**
     * Maximum number of attempts allowed for this job.
     */
    public function tries(): int;

    /**
     * Execution timeout in seconds.
     */
    public function timeout(): int;

    /**
     * Backoff delay in seconds before retrying a failed attempt.
     */
    public function backoff(): int;
}
