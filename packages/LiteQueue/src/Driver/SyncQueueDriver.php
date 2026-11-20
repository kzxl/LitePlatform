<?php

declare(strict_types=1);

namespace LiteQueue\Driver;

use LiteQueue\Job\JobInterface;
use RuntimeException;
use Throwable;

/**
 * Synchronous queue driver: executes jobs immediately in-process.
 * Ideal for unit testing, CI pipelines, and local development.
 */
class SyncQueueDriver implements QueueDriverInterface
{
    /** @var list<array<string, mixed>> */
    private array $failedJobs = [];
    private int $idSequence = 1;

    public function push(string $queue, string $payload, int $delay = 0): string|int
    {
        $id = $this->idSequence++;

        try {
            $data = json_decode($payload, true);
            if (!is_array($data) || empty($data['data'])) {
                throw new RuntimeException("Invalid queue job payload format.");
            }

            $job = unserialize($data['data']);
            if ($job instanceof JobInterface) {
                $job->handle();
            } elseif (is_callable($job)) {
                $job();
            }
        } catch (Throwable $e) {
            $this->failedJobs[] = [
                'id' => $id,
                'queue' => $queue,
                'payload' => $payload,
                'exception' => (string)$e,
                'failed_at' => time(),
            ];
            throw $e;
        }

        return $id;
    }

    public function pop(string $queue, int $timeout = 60): ?QueueRecord
    {
        return null; // Sync driver immediately processes on push
    }

    public function ack(string|int $jobId): void {}

    public function release(string|int $jobId, int $delay = 0): void {}

    public function fail(string|int $jobId, string $error): void
    {
        $this->failedJobs[] = [
            'id' => $jobId,
            'queue' => 'default',
            'payload' => '',
            'exception' => $error,
            'failed_at' => time(),
        ];
    }

    public function size(string $queue): int
    {
        return 0;
    }

    public function purge(string $queue): void {}

    public function getFailedJobs(int $limit = 50, int $offset = 0): array
    {
        return array_slice($this->failedJobs, $offset, $limit);
    }

    public function retryFailedJob(string|int $id): bool
    {
        return false;
    }

    public function flushFailedJobs(): void
    {
        $this->failedJobs = [];
    }
}
