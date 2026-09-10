<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use App\Core\AppBootstrap;
use LiteQueue\Worker;
use LiteQueue\WorkerOptions;

$app = AppBootstrap::boot(dirname(__DIR__));
$container = AppBootstrap::getContainer();

if (!$container->has(\LiteQueue\QueueManager::class)) {
    fwrite(STDERR, "Error: LiteQueue is not enabled or database connection is not configured.\n");
    exit(1);
}

/** @var \LiteQueue\QueueManager $queueManager */
$queueManager = $container->get(\LiteQueue\QueueManager::class);
$worker = new Worker($queueManager);

echo "====================================================\n";
echo "  ⚡ LiteQueue Background Worker Daemon Running     \n";
echo "  PID: " . getmypid() . " | Memory: " . round(memory_get_usage(true) / 1024 / 1024, 2) . " MB\n";
echo "====================================================\n";

$worker->onJobSuccess(function (object $job): void {
    echo "[" . date('Y-m-d H:i:s') . "] [SUCCESS] Executed: " . get_class($job) . "\n";
});

$worker->onJobFailure(function (object $job, \Throwable $e): void {
    echo "[" . date('Y-m-d H:i:s') . "] [FAILED] " . get_class($job) . ": " . $e->getMessage() . "\n";
});

$worker->daemon(new WorkerOptions(sleep: 2, maxMemoryMb: 64));
