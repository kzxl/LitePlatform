<?php

declare(strict_types=1);

namespace LiteQueue\Driver;

/**
 * Storage driver contract for queuing, reserving, acknowledging, and retrying jobs.
 */
interface QueueDriverInterface
{
    /**
     * Push a serialized job payload onto the queue with optional delay.
     *
     * @param string $queue Queue name
     * @param string $payload Serialized job payload
     * @param int $delay Delay in seconds before the job becomes available
     * @return string|int Job ID
     */
    public function push(string $queue, string $payload, int $delay = 0): string|int;

    /**
     * Atomically pop and reserve the next available job for processing.
     *
     * @param string $queue Queue name
     * @param int $timeout Reservation timeout in seconds (lock duration)
     * @return QueueRecord|null The reserved job, or null if queue is empty
     */
    public function pop(string $queue, int $timeout = 60): ?QueueRecord;

    /**
     * Acknowledge successful job completion (deletes from queue).
     */
    public function ack(string|int $jobId): void;

    /**
     * Release a failed or unacknowledged job back to the queue with a backoff delay.
     */
    public function release(string|int $jobId, int $delay = 0): void;

    /**
     * Move a job permanently to the failed jobs store / dead letter queue.
     */
    public function fail(string|int $jobId, string $error): void;

    /**
     * Get the count of pending and reserved jobs in a queue.
     */
    public function size(string $queue): int;

    /**
     * Remove all pending and reserved jobs from a queue.
     */
    public function purge(string $queue): void;

    /**
     * Retrieve failed jobs for inspection or reprocessing.
     *
     * @return list<array<string, mixed>>
     */
    public function getFailedJobs(int $limit = 50, int $offset = 0): array;

    /**
     * Retry a specific failed job by moving it back to pending.
     */
    public function retryFailedJob(string|int $id): bool;

    /**
     * Clear all failed jobs.
     */
    public function flushFailedJobs(): void;
}
