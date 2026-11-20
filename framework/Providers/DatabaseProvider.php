<?php

declare(strict_types=1);

namespace Lite\Framework\Providers;

use Lite\Framework\Core\ServiceProviderInterface;
use DI\Container;
use PDO;
use Slim\App;

/**
 * Service provider for Database connection (PDO) and LiteORM EntityManager.
 */
class DatabaseProvider implements ServiceProviderInterface
{
    public function register(Container $container, array $config): void
    {
        $dbConfig = $config['database'] ?? [];
        $driver = $dbConfig['default'] ?? 'sqlite';
        $connection = $dbConfig['connections'][$driver] ?? [];

        // 1. Resolve DSN and connection parameters
        $dsn = '';
        $username = null;
        $password = null;
        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ];

        if ($driver === 'sqlite') {
            $database = $connection['database'] ?? ($config['base_path'] . '/storage/app.sqlite');
            $dir = dirname($database);
            if (!is_dir($dir)) {
                mkdir($dir, 0777, true);
            }
            $dsn = "sqlite:{$database}";
        } elseif ($driver === 'mysql') {
            $dsn = sprintf(
                'mysql:host=%s;port=%s;dbname=%s;charset=%s',
                $connection['host'] ?? '127.0.0.1',
                $connection['port'] ?? '3306',
                $connection['database'] ?? '',
                $connection['charset'] ?? 'utf8mb4'
            );
            $username = $connection['username'] ?? 'root';
            $password = $connection['password'] ?? '';
        }

        if ($dsn !== '') {
            $pdo = null;

            // 1. Initialize LiteORM EntityManager if installed and obtain shared PDO instance
            if (class_exists(\LiteORM\EntityManager::class)) {
                $em = new \LiteORM\EntityManager($dsn, $username, $password, $options);
                $em->setDebugMode((bool)($config['debug'] ?? true));
                $container->set(\LiteORM\EntityManager::class, $em);
                $container->set('em', $em);

                $getPdo = \Closure::bind(fn() => $this->conn->getWriteConnection(), $em, \LiteORM\EntityManager::class);
                $pdo = $getPdo();
            } else {
                $pdo = new PDO($dsn, $username, $password, $options);
            }

            if ($driver === 'sqlite') {
                $pdo->exec('PRAGMA journal_mode = WAL;');
                $pdo->exec('PRAGMA busy_timeout = 5000;');
            }

            $container->set(PDO::class, $pdo);
            $container->set('db', $pdo);

            // 2. Register MigrationManager & auto-migrate in dev
            $migrationsPath = $config['base_path'] . '/database/migrations';
            $seedersPath = $config['base_path'] . '/database/seeders';
            $migrator = new \Lite\Framework\Core\MigrationManager($pdo, $migrationsPath, $seedersPath);
            $container->set(\Lite\Framework\Core\MigrationManager::class, $migrator);
            $container->set('migrator', $migrator);

            if ((bool)($config['debug'] ?? true)) {
                $migrator->migrate();
                $migrator->seed();
            }
        }
    }

    public function boot(App $app, Container $container, array $config): void
    {
        // No route/middleware boot needed for DatabaseProvider
    }
}
