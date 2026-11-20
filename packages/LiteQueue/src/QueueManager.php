<?php

declare(strict_types=1);

namespace LiteQueue;

use InvalidArgumentException;
use LiteQueue\Driver\QueueDriverInterface;
use RuntimeException;

/**
 * Main coordinator for managing background queue connections and dispatching jobs.
 */
class QueueManager
{
    /** @var array<string, QueueDriverInterface> */
    private array $drivers = [];
    private string $defaultConnection;

    public function __construct(?QueueDriverInterface $defaultDriver = null, string $defaultConnection = 'default')
    {
        $this->defaultConnection = $defaultConnection;
        if ($defaultDriver !== null) {
            $this->addDriver($defaultConnection, $defaultDriver);
        }
    }

    public function addDriver(string $name, QueueDriverInterface $driver): self
    {
        $this->drivers[$name] = $driver;
        return $this;
    }

    public function driver(?string $name = null): QueueDriverInterface
    {
        $name = $name ?? $this->defaultConnection;
        if (!isset($this->drivers[$name])) {
            throw new InvalidArgumentException("Queue driver connection [{$name}] is not defined.");
        }
        return $this->drivers[$name];
    }

    /**
     * Push a new job onto the queue.
     *
     * @param object|callable $job
     * @param int|null $delay Delay in seconds
     * @param string $queue Queue name
     * @param string|null $connection Driver connection name
     * @return string|int Dispatched Job ID
     */
    public function push(
        object|callable $job,
        ?int $delay = null,
        string $queue = 'default',
        ?string $connection = null,
    ): string|int {
        $payload = $this->serializeJob($job);
        return $this->driver($connection)->push($queue, $payload, $delay ?? 0);
    }

    /**
     * Push a new job onto the queue after a specific delay in seconds.
     */
    public function later(
        int $delaySeconds,
        object|callable $job,
        string $queue = 'default',
        ?string $connection = null,
    ): string|int {
        return $this->push($job, delay: $delaySeconds, queue: $queue, connection: $connection);
    }

    /**
     * Get the count of pending and reserved jobs in a queue.
     */
    public function size(string $queue = 'default', ?string $connection = null): int
    {
        return $this->driver($connection)->size($queue);
    }

    /**
     * Purge all jobs from a queue.
     */
    public function purge(string $queue = 'default', ?string $connection = null): void
    {
        $this->driver($connection)->purge($queue);
    }

    /**
     * Serialize a job into a JSON transport payload.
     */
    public function serializeJob(object|callable $job): string
    {
        $className = is_object($job) ? get_class($job) : 'Closure';
        $data = serialize($job);

        return json_encode([
            'class' => $className,
            'data' => $data,
            'pushed_at' => microtime(true),
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    }

    /**
     * Unserialize a job payload from string.
     */
    public function unserializeJob(string $payload): object|callable
    {
        $decoded = json_decode($payload, true);
        if (!is_array($decoded) || !isset($decoded['data'])) {
            throw new RuntimeException("Malformed queue job payload.");
        }

        $job = unserialize($decoded['data']);
        if (!is_object($job) && !is_callable($job)) {
            throw new RuntimeException("Unserialized job is neither an object nor a callable.");
        }

        return $job;
    }
}
