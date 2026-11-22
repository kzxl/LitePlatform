<?php

declare(strict_types=1);

namespace Lite\Framework\Core;

use PDO;
use RuntimeException;

/**
 * Lightweight, zero-dependency database migration and seeder engine.
 * Supports SQLite, MySQL, and PostgreSQL with atomic execution.
 */
class MigrationManager
{
    /** @var list<string> */
    private array $extraPaths = [];

    public function __construct(
        private PDO $pdo,
        private string $migrationsPath,
        private string $seedersPath
    ) {
        $this->ensureMigrationTable();
    }

    /**
     * Register an additional migrations directory (e.g. from a Plugin Module).
     */
    public function addMigrationPath(string $path): self
    {
        $normalized = rtrim($path, '/\\');
        if ($normalized !== '' && is_dir($normalized) && !in_array($normalized, $this->extraPaths, true)) {
            $this->extraPaths[] = $normalized;
        }

        return $this;
    }

    /**
     * @return list<string>
     */
    public function getMigrationPaths(): array
    {
        $paths = [];
        if (is_dir($this->migrationsPath)) {
            $paths[] = rtrim($this->migrationsPath, '/\\');
        }
        foreach ($this->extraPaths as $extra) {
            if (is_dir($extra) && !in_array($extra, $paths, true)) {
                $paths[] = $extra;
            }
        }

        return $paths;
    }

    /**
     * Ensure the internal `migrations` tracking table exists.
     */
    public function ensureMigrationTable(): void
    {
        $driver = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);

        $sql = match ($driver) {
            'sqlite' => "
                CREATE TABLE IF NOT EXISTS migrations (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    migration TEXT UNIQUE NOT NULL,
                    batch INTEGER NOT NULL,
                    executed_at DATETIME NOT NULL
                );
            ",
            'pgsql' => "
                CREATE TABLE IF NOT EXISTS migrations (
                    id SERIAL PRIMARY KEY,
                    migration VARCHAR(255) UNIQUE NOT NULL,
                    batch INTEGER NOT NULL,
                    executed_at TIMESTAMP NOT NULL
                );
            ",
            default => "
                CREATE TABLE IF NOT EXISTS migrations (
                    id BIGINT AUTO_INCREMENT PRIMARY KEY,
                    migration VARCHAR(255) UNIQUE NOT NULL,
                    batch INT NOT NULL,
                    executed_at DATETIME NOT NULL
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
            ",
        };

        $this->pdo->exec($sql);
    }

    /**
     * Execute all pending migrations.
     *
     * @return list<string> List of executed migration names
     */
    public function migrate(): array
    {
        $allFiles = [];
        foreach ($this->getMigrationPaths() as $dir) {
            $found = glob($dir . '/*.{sql,php}', GLOB_BRACE) ?: [];
            foreach ($found as $f) {
                $allFiles[basename($f)] = $f;
            }
        }

        if ($allFiles === []) {
            return [];
        }

        ksort($allFiles);
        $allFiles = array_values($allFiles);

        $stmt = $this->pdo->query("SELECT migration FROM migrations");
        $executed = $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];

        $batchStmt = $this->pdo->query("SELECT COALESCE(MAX(batch), 0) + 1 FROM migrations");
        $nextBatch = (int)$batchStmt->fetchColumn();

        $migrated = [];

        foreach ($allFiles as $filePath) {
            $name = basename($filePath);
            if (in_array($name, $executed, true)) {
                continue;
            }

            $this->pdo->beginTransaction();
            try {
                $ext = pathinfo($filePath, PATHINFO_EXTENSION);
                if ($ext === 'sql') {
                    $sql = file_get_contents($filePath);
                    if ($sql !== false && trim($sql) !== '') {
                        $this->pdo->exec($sql);
                    }
                } elseif ($ext === 'php') {
                    $migration = require $filePath;
                    if (is_object($migration) && method_exists($migration, 'up')) {
                        $migration->up($this->pdo);
                    } elseif (is_callable($migration)) {
                        $migration($this->pdo);
                    }
                }

                $insert = $this->pdo->prepare(
                    "INSERT INTO migrations (migration, batch, executed_at) VALUES (:migration, :batch, :executed_at)"
                );
                $insert->execute([
                    'migration'   => $name,
                    'batch'       => $nextBatch,
                    'executed_at' => date('Y-m-d H:i:s'),
                ]);

                $this->pdo->commit();
                $migrated[] = $name;
            } catch (\Throwable $e) {
                if ($this->pdo->inTransaction()) {
                    $this->pdo->rollBack();
                }
                throw new RuntimeException("Migration failed on [{$name}]: " . $e->getMessage(), 0, $e);
            }
        }

        return $migrated;
    }

    /**
     * Run database seeders.
     *
     * @return list<string> List of executed seeder class names
     */
    public function seed(?string $seederClass = null): array
    {
        if (!is_dir($this->seedersPath)) {
            return [];
        }

        $seeded = [];
        $targetClass = $seederClass ?? 'DatabaseSeeder';
        $file = $this->seedersPath . '/' . $targetClass . '.php';

        if (!file_exists($file)) {
            throw new RuntimeException("Seeder file [{$file}] not found.");
        }

        require_once $file;
        $fullClass = 'App\\Database\\Seeders\\' . $targetClass;
        if (!class_exists($fullClass)) {
            // Check global namespace fallback
            $fullClass = $targetClass;
        }

        if (class_exists($fullClass)) {
            $instance = new $fullClass();
            if (method_exists($instance, 'run')) {
                $instance->run($this->pdo);
                $seeded[] = $fullClass;
            }
        }

        return $seeded;
    }
}
