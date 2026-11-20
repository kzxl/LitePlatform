<?php

declare(strict_types=1);

namespace LiteQueue\Tests;

use LiteQueue\Driver\DatabaseQueueDriver;
use LiteQueue\Job\AbstractJob;
use LiteQueue\{QueueManager, Worker, WorkerOptions};
use PDO;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Throwable;

class SuccessfulJob extends AbstractJob
{
    public static int $executionCount = 0;

    public function handle(): void
    {
        self::$executionCount++;
    }
}

class FlakyJob extends AbstractJob
{
    public static int $attempts = 0;
    public static bool $failedCalled = false;

    public function tries(): int
    {
        return 2;
    }

    public function backoff(): int
    {
        return 0; // Immediate retry for test speed
    }

    public function handle(): void
    {
        self::$attempts++;
        throw new RuntimeException("Simulated task failure");
    }

    public function failed(Throwable $exception): void
    {
        self::$failedCalled = true;
    }
}

class WorkerTest extends TestCase
{
    private PDO $pdo;
    private DatabaseQueueDriver $driver;
    private QueueManager $manager;
    private Worker $worker;

    protected function setUp(): void
    {
        SuccessfulJob::$executionCount = 0;
        FlakyJob::$attempts = 0;
        FlakyJob::$failedCalled = false;

        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->driver = new DatabaseQueueDriver($this->pdo);
        $this->manager = new QueueManager($this->driver);
        $this->worker = new Worker($this->manager);
    }

    public function testProcessesSuccessfulJob(): void
    {
        $this->manager->push(new SuccessfulJob());
        $this->assertSame(1, $this->manager->size());

        $processed = $this->worker->runNext('default');

        $this->assertTrue($processed);
        $this->assertSame(1, SuccessfulJob::$executionCount);
        $this->assertSame(0, $this->manager->size());
    }

    public function testReturnsFalseWhenQueueIsEmpty(): void
    {
        $processed = $this->worker->runNext('default');
        $this->assertFalse($processed);
    }

    public function testRetriesFailingJobUpToMaxAttemptsThenFails(): void
    {
        $this->manager->push(new FlakyJob());

        $options = new WorkerOptions(maxAttempts: 2);

        // Attempt 1 -> fails and gets released back to queue
        $this->worker->runNext('default', $options);
        $this->assertSame(1, FlakyJob::$attempts);
        $this->assertFalse(FlakyJob::$failedCalled);
        $this->assertSame(1, $this->manager->size());
        $this->assertCount(0, $this->driver->getFailedJobs());

        // Attempt 2 -> fails and exceeds maxAttempts (2) -> moved to failed table!
        $this->worker->runNext('default', $options);
        $this->assertSame(2, FlakyJob::$attempts);
        $this->assertTrue(FlakyJob::$failedCalled);
        $this->assertSame(0, $this->manager->size());

        // Verified in failed jobs table
        $failedJobs = $this->driver->getFailedJobs();
        $this->assertCount(1, $failedJobs);
        $this->assertStringContainsString('Simulated task failure', $failedJobs[0]['exception']);
    }

    public function testDaemonStopsAtMaxJobsLimit(): void
    {
        $this->manager->push(new SuccessfulJob());
        $this->manager->push(new SuccessfulJob());
        $this->manager->push(new SuccessfulJob());

        $options = new WorkerOptions(maxJobs: 2, sleep: 0);
        $this->worker->daemon('default', $options);

        // Processed exactly 2 jobs then broke out of loop
        $this->assertSame(2, SuccessfulJob::$executionCount);
        $this->assertSame(1, $this->manager->size());
    }
}
