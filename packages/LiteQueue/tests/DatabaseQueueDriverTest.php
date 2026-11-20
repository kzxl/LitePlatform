<?php

declare(strict_types=1);

namespace LiteQueue\Tests;

use LiteQueue\Driver\DatabaseQueueDriver;
use PDO;
use PHPUnit\Framework\TestCase;

class DatabaseQueueDriverTest extends TestCase
{
    private PDO $pdo;
    private DatabaseQueueDriver $driver;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->driver = new DatabaseQueueDriver($this->pdo);
    }

    public function testPushAndAtomicPop(): void
    {
        $id = $this->driver->push('default', '{"data":"sample"}');
        $this->assertNotEmpty($id);
        $this->assertSame(1, $this->driver->size('default'));

        // Pop the job
        $record = $this->driver->pop('default', timeout: 60);
        $this->assertNotNull($record);
        $this->assertSame($id, (string)$record->id);
        $this->assertSame('{"data":"sample"}', $record->payload);
        $this->assertSame(1, $record->attempts);

        // Immediate next pop on same queue should yield null (already reserved)
        $secondPop = $this->driver->pop('default', timeout: 60);
        $this->assertNull($secondPop);
    }

    public function testDelayedJobIsNotAvailableUntilDelayExpires(): void
    {
        // Delayed by 100 seconds
        $this->driver->push('default', '{"data":"delayed"}', delay: 100);

        // Should not be poppable right now
        $record = $this->driver->pop('default', timeout: 60);
        $this->assertNull($record);
        $this->assertSame(1, $this->driver->size('default'));
    }

    public function testAckRemovesJobPermanently(): void
    {
        $id = $this->driver->push('default', '{"data":"to-ack"}');
        $record = $this->driver->pop('default');
        $this->assertNotNull($record);

        $this->driver->ack($record->id);
        $this->assertSame(0, $this->driver->size('default'));
    }

    public function testReleasePutsJobBackToQueue(): void
    {
        $this->driver->push('default', '{"data":"to-release"}');
        $record = $this->driver->pop('default');
        $this->assertNotNull($record);

        // Release with 0 delay (immediately available)
        $this->driver->release($record->id, delay: 0);

        // Pop again: attempts should be 2
        $rePopped = $this->driver->pop('default');
        $this->assertNotNull($rePopped);
        $this->assertSame(2, $rePopped->attempts);
    }

    public function testFailAndRetryFailedJob(): void
    {
        $this->driver->push('emails', '{"data":"failing-job"}');
        $record = $this->driver->pop('emails');
        $this->assertNotNull($record);

        $this->driver->fail($record->id, 'SMTP connection timeout');

        // Main queue should be empty
        $this->assertSame(0, $this->driver->size('emails'));

        // Failed table has 1 record
        $failed = $this->driver->getFailedJobs();
        $this->assertCount(1, $failed);
        $this->assertSame('emails', $failed[0]['queue']);
        $this->assertStringContainsString('SMTP connection timeout', $failed[0]['exception']);

        // Retry failed job
        $retried = $this->driver->retryFailedJob($failed[0]['id']);
        $this->assertTrue($retried);

        // Now main queue has the job back!
        $this->assertSame(1, $this->driver->size('emails'));
        $this->assertCount(0, $this->driver->getFailedJobs());
    }

    public function testPurge(): void
    {
        $this->driver->push('logs', '{"data":"log1"}');
        $this->driver->push('logs', '{"data":"log2"}');
        $this->assertSame(2, $this->driver->size('logs'));

        $this->driver->purge('logs');
        $this->assertSame(0, $this->driver->size('logs'));
    }
}
