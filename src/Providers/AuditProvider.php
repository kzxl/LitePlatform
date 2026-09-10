<?php

declare(strict_types=1);

namespace App\Providers;

use App\Core\ServiceProviderInterface;
use DI\Container;
use PDO;
use Slim\App;

/**
 * Service provider for LiteAudit entity mutation ledger.
 */
class AuditProvider implements ServiceProviderInterface
{
    public function register(Container $container, array $config): void
    {
        if (!class_exists(\LiteAudit\AuditManager::class) || !$container->has(PDO::class)) {
            return;
        }

        $pdo = $container->get(PDO::class);
        $storage = new \LiteAudit\Storage\PdoAuditStorage($pdo);
        $audit = new \LiteAudit\AuditManager($storage);

        // Bridge with LiteORM if available
        if ($container->has(\LiteORM\EntityManager::class) && class_exists(\LiteAudit\Bridge\LiteOrmAuditBridge::class)) {
            $em = $container->get(\LiteORM\EntityManager::class);
            \LiteAudit\Bridge\LiteOrmAuditBridge::register($em, $audit);
        }

        $container->set(\LiteAudit\Storage\AuditStorageInterface::class, $storage);
        $container->set(\LiteAudit\AuditManager::class, $audit);
        $container->set('audit', $audit);
    }

    public function boot(App $app, Container $container, array $config): void
    {
        // Audit manager ready
    }
}
