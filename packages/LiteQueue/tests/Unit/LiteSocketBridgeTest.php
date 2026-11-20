<?php

declare(strict_types=1);

namespace LiteQueue\Tests\Unit;

use LiteQueue\Bridge\LiteSocketBridge;
use LiteQueue\Driver\QueueRecord;
use LiteQueue\Driver\SyncQueueDriver;
use LiteQueue\Job\AbstractJob;
use LiteQueue\Job\BroadcastableJobInterface;
use LiteQueue\Job\BroadcastableJobTrait;
use LiteQueue\QueueManager;
use LiteQueue\Worker;
use PHPUnit\Framework\TestCase;

class TestBroadcastJob extends AbstractJob implements BroadcastableJobInterface
{
    use BroadcastableJobTrait;

    public static bool $handled = false;

    public function handle(): void
    {
        self::$handled = true;
    }
}

class TestFailingBroadcastJob extends AbstractJob implements BroadcastableJobInterface
{
    use BroadcastableJobTrait;

    public function handle(): void
    {
        throw new \RuntimeException("Simulation of failure");
    }
}

class LiteSocketBridgeTest extends TestCase
{
    public function testBroadcastSuccessTriggered(): void
    {
        $dispatched = [];
        $bridge = LiteSocketBridge::custom(function (string $channel, array $payload) use (&$dispatched) {
            $dispatched[] = ['channel' => $channel, 'payload' => $payload];
        });

        $job = new TestBroadcastJob();
        $job->setBroadcast('room_sales', 'report.generated', ['url' => 'https://example.com/report.pdf']);

        $record = new QueueRecord('job_123', 'default', '', 1, time());

        $bridge->handleSuccess($record, $job);

        $this->assertCount(1, $dispatched);
        $this->assertEquals('room_sales', $dispatched[0]['channel']);
        $this->assertEquals('job_completed', $dispatched[0]['payload']['type']);
        $this->assertEquals('report.generated', $dispatched[0]['payload']['event']);
        $this->assertEquals('https://example.com/report.pdf', $dispatched[0]['payload']['data']['url']);
    }

    public function testBroadcastFailureTriggered(): void
    {
        $dispatched = [];
        $bridge = LiteSocketBridge::custom(function (string $channel, array $payload) use (&$dispatched) {
            $dispatched[] = ['channel' => $channel, 'payload' => $payload];
        });

        $job = new TestFailingBroadcastJob();
        $job->setBroadcast('user_99', 'order.payment_failed');

        $record = new QueueRecord('job_456', 'default', '', 3, time());
        $exception = new \RuntimeException("Insufficient balance");

        $bridge->handleFailure($record, $job, $exception);

        $this->assertCount(1, $dispatched);
        $this->assertEquals('user_99', $dispatched[0]['channel']);
        $this->assertEquals('job_failed', $dispatched[0]['payload']['type']);
        $this->assertEquals('Insufficient balance', $dispatched[0]['payload']['error']);
    }

    public function testWorkerAttachIntegration(): void
    {
        $pdo = new \PDO('sqlite::memory:');
        $driver = new \LiteQueue\Driver\DatabaseQueueDriver($pdo);
        $manager = new QueueManager($driver);
        $worker = new Worker($manager);

        $dispatched = [];
        $bridge = LiteSocketBridge::custom(function (string $channel, array $payload) use (&$dispatched) {
            $dispatched[] = ['channel' => $channel, 'payload' => $payload];
        });
        $bridge->attach($worker);

        $job = new TestBroadcastJob();
        $job->setBroadcast('admin_channel', 'inventory.synced', ['count' => 50]);

        $manager->push($job);
        $worker->runNext('default');

        $this->assertTrue(TestBroadcastJob::$handled);
        $this->assertCount(1, $dispatched);
        $this->assertEquals('admin_channel', $dispatched[0]['channel']);
        $this->assertEquals('inventory.synced', $dispatched[0]['payload']['event']);
        $this->assertEquals(50, $dispatched[0]['payload']['data']['count']);
    }
}
