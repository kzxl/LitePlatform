<?php

declare(strict_types=1);

namespace App\Providers;

use App\Core\ServiceProviderInterface;
use DI\Container;
use PDO;
use Slim\App;

/**
 * Service provider for LiteQueue background job queue and worker.
 */
class QueueProvider implements ServiceProviderInterface
{
    public function register(Container $container, array $config): void
    {
        if (!class_exists(\LiteQueue\QueueManager::class) || !$container->has(PDO::class)) {
            return;
        }

        $pdo = $container->get(PDO::class);
        $driver = new \LiteQueue\Driver\DatabaseQueueDriver($pdo);
        $queue = new \LiteQueue\QueueManager($driver);

        $container->set(\LiteQueue\QueueManager::class, $queue);
        $container->set('queue', $queue);
    }

    public function boot(App $app, Container $container, array $config): void
    {
        // Queue ready for dispatching jobs from controllers or CLI
    }
}
