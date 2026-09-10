<?php

declare(strict_types=1);

namespace App\Providers;

use App\Core\ServiceProviderInterface;
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
            $pdo = new PDO($dsn, $username, $password, $options);
            $container->set(PDO::class, $pdo);
            $container->set('db', $pdo);

            // 2. Initialize LiteORM EntityManager if installed
            if (class_exists(\LiteORM\EntityManager::class)) {
                $em = new \LiteORM\EntityManager($dsn, $username, $password, $options);
                $em->setDebugMode((bool)($config['debug'] ?? true));
                $container->set(\LiteORM\EntityManager::class, $em);
                $container->set('em', $em);
            }

            // 3. Auto-initialize demo users table if using SQLite
            if ($driver === 'sqlite') {
                $pdo->exec("
                    CREATE TABLE IF NOT EXISTS users (
                        id INTEGER PRIMARY KEY AUTOINCREMENT,
                        name VARCHAR(100) NOT NULL,
                        email VARCHAR(150) NOT NULL UNIQUE,
                        role VARCHAR(50) DEFAULT 'user',
                        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                        updated_at DATETIME
                    )
                ");
                $count = (int)$pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();
                if ($count === 0) {
                    $pdo->exec("
                        INSERT INTO users (name, email, role, created_at) VALUES
                        ('System Administrator', 'admin@liteplatform.io', 'admin', datetime('now')),
                        ('Demo User', 'demo@liteplatform.io', 'user', datetime('now'))
                    ");
                }
            }
        }
    }

    public function boot(App $app, Container $container, array $config): void
    {
        // No route/middleware boot needed for DatabaseProvider
    }
}
