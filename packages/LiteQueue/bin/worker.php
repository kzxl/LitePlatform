<?php

declare(strict_types=1);

/**
 * CLI Worker Runner for LiteQueue.
 *
 * Usage:
 *   php bin/worker.php --queue=default --sleep=3 --memory=128 --tries=3
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Worker can only be executed via PHP CLI.\n");
    exit(1);
}

$autoloadPaths = [
    __DIR__ . '/../vendor/autoload.php',
    __DIR__ . '/../../../vendor/autoload.php',
];

foreach ($autoloadPaths as $path) {
    if (file_exists($path)) {
        require_once $path;
        break;
    }
}

use LiteQueue\{QueueManager, Worker, WorkerOptions};
use LiteQueue\Driver\DatabaseQueueDriver;

$options = getopt('', ['queue::', 'sleep::', 'memory::', 'tries::', 'help::']);

if (isset($options['help'])) {
    echo "LiteQueue CLI Worker Runner\n";
    echo "Options:\n";
    echo "  --queue=NAME    Queue to consume (default: default)\n";
    echo "  --sleep=SEC     Seconds to sleep when empty (default: 3)\n";
    echo "  --memory=MB     Max memory in MB before graceful restart (default: 128)\n";
    echo "  --tries=NUM     Max attempt tries (default: 3)\n";
    exit(0);
}

$queue = (string)($options['queue'] ?? 'default');
$sleep = (int)($options['sleep'] ?? 3);
$memory = (int)($options['memory'] ?? 128);
$tries = (int)($options['tries'] ?? 3);

echo "Starting LiteQueue worker on queue [{$queue}] (sleep: {$sleep}s, max-memory: {$memory}MB)...\n";
