<?php

declare(strict_types=1);

namespace LiteQueue;

use LiteQueue\Driver\QueueRecord;
use LiteQueue\Job\JobInterface;
use Throwable;

/**
 * Background worker daemon process that consumes and executes queued jobs.
 */
class Worker
{
    private QueueManager $manager;
    private bool $shouldQuit = false;

    /** @var callable|null */
    private $onSuccess = null;

    /** @var callable|null */
    private $onFailure = null;

    /** @var callable|null */
    private $onSleep = null;

    public function __construct(QueueManager $manager)
    {
        $this->manager = $manager;
    }

    public function stop(): void
    {
        $this->shouldQuit = true;
    }

    public function onJobSuccess(?callable $callback): self
    {
        $this->onSuccess = $callback;
        return $this;
    }

    public function onJobFailure(?callable $callback): self
    {
        $this->onFailure = $callback;
        return $this;
    }

    public function onWorkerSleep(?callable $callback): self
    {
        $this->onSleep = $callback;
        return $this;
    }

    /**
     * Process the next single job from the queue(s).
     * If multiple queues are provided, checks them in priority order.
     *
     * @param string|string[] $queue Single queue name, comma-separated list, or array of queue names
     * @return bool True if a job was processed, false if all queues were empty
     */
    public function runNext(string|array $queue = 'default', ?WorkerOptions $options = null): bool
    {
        $options = $options ?? new WorkerOptions();
        $driver = $this->manager->driver();

        $queues = is_array($queue) ? $queue : array_map('trim', explode(',', $queue));

        foreach ($queues as $q) {
            $record = $driver->pop($q, $options->timeout);
            if ($record !== null) {
                $this->processJob($record, $options);
                return true;
            }
        }

        return false;
    }

    /**
     * Run the worker loop continuously as a background daemon.
     *
     * @param string|string[] $queue
     */
    public function daemon(string|array $queue = 'default', ?WorkerOptions $options = null): void
    {
        $options = $options ?? new WorkerOptions();
        $jobsProcessed = 0;

        while (!$this->shouldQuit) {
            // Check memory limit
            if ($this->memoryExceeded($options->maxMemoryMb)) {
                $this->stop();
                break;
            }

            // Check max jobs
            if ($options->maxJobs > 0 && $jobsProcessed >= $options->maxJobs) {
                $this->stop();
                break;
            }

            $ran = $this->runNext($queue, $options);
            if ($ran) {
                $jobsProcessed++;
            } else {
                if ($this->onSleep !== null) {
                    ($this->onSleep)($options->sleep);
                }
                sleep($options->sleep);
            }
        }
    }

    private function processJob(QueueRecord $record, WorkerOptions $options): void
    {
        $driver = $this->manager->driver();
        $job = null;

        try {
            $job = $this->manager->unserializeJob($record->payload);

            if ($job instanceof JobInterface) {
                $job->handle();
            } elseif (is_callable($job)) {
                $job();
            }

            // Acknowledge successful execution
            $driver->ack($record->id);

            if ($this->onSuccess !== null) {
                ($this->onSuccess)($record, $job);
            }
        } catch (Throwable $e) {
            $maxTries = ($job instanceof JobInterface) ? $job->tries() : $options->maxAttempts;
            $backoff = ($job instanceof JobInterface) ? $job->backoff() : 5;

            if ($record->attempts >= $maxTries) {
                // Job completely failed after max attempts
                if ($job instanceof JobInterface) {
                    try {
                        $job->failed($e);
                    } catch (Throwable) {
                        // Suppress exception during failure hook
                    }
                }

                $driver->fail($record->id, (string)$e);
            } else {
                // Release back to queue with retry backoff
                $driver->release($record->id, $backoff);
            }

            if ($this->onFailure !== null) {
                ($this->onFailure)($record, $job, $e);
            }

            if ($options->stopOnError) {
                $this->stop();
                throw $e;
            }
        }
    }

    private function memoryExceeded(int $maxMemoryMb): bool
    {
        return (memory_get_usage(true) / 1024 / 1024) >= $maxMemoryMb;
    }
}
