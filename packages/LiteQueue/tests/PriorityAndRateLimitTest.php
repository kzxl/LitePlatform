<?php

declare(strict_types=1);

namespace LiteQueue\Tests;

use PHPUnit\Framework\TestCase;
use LiteQueue\Driver\DatabaseQueueDriver;
use LiteQueue\Job\AbstractJob;
use LiteQueue\RateLimiter\JobRateLimiter;
use LiteQueue\{QueueManager, Worker};
use PDO;

class PriorityJob extends AbstractJob
{
    public static array $executedOrder = [];

    public function __construct(public string $name) {}

    public function handle(): void
    {
        self::$executedOrder[] = $this->name;
    }
}

class PriorityAndRateLimitTest extends TestCase
{
    private PDO $pdo;
    private DatabaseQueueDriver $driver;
    private QueueManager $manager;
    private Worker $worker;

    protected function setUp(): void
    {
        PriorityJob::$executedOrder = [];

        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $this->driver = new DatabaseQueueDriver($this->pdo);

        $this->manager = new QueueManager($this->driver);
        $this->worker = new Worker($this->manager);

        JobRateLimiter::clear();
    }

    public function testPriorityQueuesExecuteHigherPriorityFirst(): void
    {
        // Push to low queue first
        $this->manager->push(new PriorityJob('low-1'), queue: 'low');
        $this->manager->push(new PriorityJob('low-2'), queue: 'low');

        // Then push to high queue
        $this->manager->push(new PriorityJob('high-1'), queue: 'high');

        // Worker checking ['high', 'low'] priority order
        $this->worker->runNext(['high', 'low']);

        // First processed job must be high-1 despite low-1 being pushed earlier!
        $this->assertSame(['high-1'], PriorityJob::$executedOrder);

        // Next job processed should be low-1
        $this->worker->runNext(['high', 'low']);
        $this->assertSame(['high-1', 'low-1'], PriorityJob::$executedOrder);

        // Next job processed should be low-2
        $this->worker->runNext(['high', 'low']);
        $this->assertSame(['high-1', 'low-1', 'low-2'], PriorityJob::$executedOrder);

        // All empty now
        $this->assertFalse($this->worker->runNext(['high', 'low']));
    }

    public function testJobRateLimiter(): void
    {
        $counter = 0;
        $key = 'test-action';

        // Limit: 2 attempts per 60 seconds
        for ($i = 0; $i < 2; $i++) {
            $res = JobRateLimiter::attempt($key, 2, 60, function () use (&$counter) {
                return ++$counter;
            });
            $this->assertSame($i + 1, $res);
        }

        // 3rd attempt should exceed rate limit
        $this->assertTrue(JobRateLimiter::tooManyAttempts($key, 2, 60));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Rate limit exceeded');
        JobRateLimiter::attempt($key, 2, 60, fn() => ++$counter);
    }

    public function testJobRateLimiterFallback(): void
    {
        $key = 'test-fallback';

        JobRateLimiter::hit($key);
        JobRateLimiter::hit($key);

        $fallbackCalled = false;
        $res = JobRateLimiter::attempt(
            $key,
            2,
            60,
            fn() => 'success',
            function () use (&$fallbackCalled) {
                $fallbackCalled = true;
                return 'fallback';
            }
        );

        $this->assertTrue($fallbackCalled);
        $this->assertSame('fallback', $res);
    }
}
