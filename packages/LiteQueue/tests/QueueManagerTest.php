<?php

declare(strict_types=1);

namespace LiteQueue\Tests;

use LiteQueue\Driver\SyncQueueDriver;
use LiteQueue\Job\AbstractJob;
use LiteQueue\QueueManager;
use PHPUnit\Framework\TestCase;

class SimpleTestJob extends AbstractJob
{
    public static bool $executed = false;
    public string $message;

    public function __construct(string $message = 'hello')
    {
        $this->message = $message;
    }

    public function handle(): void
    {
        self::$executed = true;
    }
}

class QueueManagerTest extends TestCase
{
    protected function setUp(): void
    {
        SimpleTestJob::$executed = false;
    }

    public function testSerializationAndUnserialization(): void
    {
        $manager = new QueueManager();
        $job = new SimpleTestJob('world');

        $payload = $manager->serializeJob($job);
        $this->assertJson($payload);

        /** @var SimpleTestJob $restored */
        $restored = $manager->unserializeJob($payload);
        $this->assertInstanceOf(SimpleTestJob::class, $restored);
        $this->assertSame('world', $restored->message);
    }

    public function testSyncDriverExecutesImmediatelyOnPush(): void
    {
        $syncDriver = new SyncQueueDriver();
        $manager = new QueueManager($syncDriver);

        $this->assertFalse(SimpleTestJob::$executed);

        $manager->push(new SimpleTestJob('immediate'));

        $this->assertTrue(SimpleTestJob::$executed);
    }

    public function testMultipleDriversSwitching(): void
    {
        $defaultDriver = new SyncQueueDriver();
        $analyticsDriver = new SyncQueueDriver();

        $manager = new QueueManager($defaultDriver);
        $manager->addDriver('analytics', $analyticsDriver);

        $this->assertSame($defaultDriver, $manager->driver());
        $this->assertSame($analyticsDriver, $manager->driver('analytics'));
    }
}
