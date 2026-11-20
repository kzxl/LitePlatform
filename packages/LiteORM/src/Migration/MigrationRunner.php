<?php

declare(strict_types=1);

namespace LiteORM\Migration;

use PDO;
use RuntimeException;
use Throwable;

/**
 * Sovereign, Zero-Dependency Database Schema Migration Runner for LiteORM.
 * Supports SQLite, MySQL, and PostgreSQL with transaction safety and batch rollback.
 */
class MigrationRunner
{
    private PDO $pdo;
    private string $table;

    public function __construct(PDO $pdo, string $table = 'lite_migrations')
    {
        $this->pdo = $pdo;
        $this->table = $table;
        $this->initTable();
    }

    /**
     * Ensure the migrations tracking table exists.
     */
    public function initTable(): void
    {
        $driver = (string)$this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        if ($driver === 'sqlite') {
            $sql = "CREATE TABLE IF NOT EXISTS {$this->table} (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                migration VARCHAR(255) NOT NULL UNIQUE,
                batch INTEGER NOT NULL,
                executed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )";
        } else {
            $sql = "CREATE TABLE IF NOT EXISTS {$this->table} (
                id INT AUTO_INCREMENT PRIMARY KEY,
                migration VARCHAR(255) NOT NULL UNIQUE,
                batch INT NOT NULL,
                executed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
        }
        $this->pdo->exec($sql);
    }

    /**
     * Get list of all migration names that have already been executed.
     *
     * @return string[]
     */
    public function getExecuted(): array
    {
        $stmt = $this->pdo->query("SELECT migration FROM {$this->table} ORDER BY id ASC");
        return $stmt ? $stmt->fetchAll(PDO::FETCH_COLUMN) : [];
    }

    /**
     * Get the latest batch number.
     */
    public function getLatestBatch(): int
    {
        $stmt = $this->pdo->query("SELECT MAX(batch) FROM {$this->table}");
        $max = $stmt ? $stmt->fetchColumn() : null;
        return $max !== null && $max !== false ? (int)$max : 0;
    }

    /**
     * Run all pending migrations in chronological order.
     *
     * @param string $migrationsPath Directory containing migration files
     * @return string[] List of successfully migrated file names
     */
    public function migrate(string $migrationsPath): array
    {
        if (!is_dir($migrationsPath)) {
            return [];
        }

        $executed = $this->getExecuted();
        $files = glob(rtrim($migrationsPath, '/\\') . '/*.php');
        if ($files === false || empty($files)) {
            return [];
        }

        sort($files);
        $nextBatch = $this->getLatestBatch() + 1;
        $migrated = [];

        foreach ($files as $file) {
            $migrationName = basename($file, '.php');
            if (in_array($migrationName, $executed, true)) {
                continue;
            }

            $migration = $this->resolveMigrationInstance($file);
            $migration->setPdo($this->pdo);

            $inTx = false;
            try {
                // SQLite and PostgreSQL support transactional DDL; MySQL auto-commits DDL
                if ($this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'mysql') {
                    $this->pdo->beginTransaction();
                    $inTx = true;
                }

                $migration->up();

                $stmt = $this->pdo->prepare("INSERT INTO {$this->table} (migration, batch) VALUES (?, ?)");
                $stmt->execute([$migrationName, $nextBatch]);

                if ($inTx && $this->pdo->inTransaction()) {
                    $this->pdo->commit();
                }

                $migrated[] = $migrationName;
            } catch (Throwable $e) {
                if ($inTx && $this->pdo->inTransaction()) {
                    $this->pdo->rollBack();
                }
                throw new RuntimeException("Migration failed in '{$migrationName}': " . $e->getMessage(), 0, $e);
            }
        }

        return $migrated;
    }

    /**
     * Rollback migrations by batch or step count.
     *
     * @param string $migrationsPath Directory containing migration files
     * @param int $steps Number of batches to rollback (default 1)
     * @return string[] List of rolled back migration names
     */
    public function rollback(string $migrationsPath, int $steps = 1): array
    {
        $latestBatch = $this->getLatestBatch();
        if ($latestBatch === 0) {
            return [];
        }

        $targetBatch = max(1, $latestBatch - $steps + 1);
        $stmt = $this->pdo->prepare("SELECT migration FROM {$this->table} WHERE batch >= ? ORDER BY id DESC");
        $stmt->execute([$targetBatch]);
        $migrationsToRollback = $stmt->fetchAll(PDO::FETCH_COLUMN);

        $rolledBack = [];

        foreach ($migrationsToRollback as $migrationName) {
            $file = rtrim($migrationsPath, '/\\') . '/' . $migrationName . '.php';
            if (!file_exists($file)) {
                throw new RuntimeException("Migration file not found for rollback: {$file}");
            }

            $migration = $this->resolveMigrationInstance($file);
            $migration->setPdo($this->pdo);

            $inTx = false;
            try {
                if ($this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'mysql') {
                    $this->pdo->beginTransaction();
                    $inTx = true;
                }

                $migration->down();

                $delStmt = $this->pdo->prepare("DELETE FROM {$this->table} WHERE migration = ?");
                $delStmt->execute([$migrationName]);

                if ($inTx && $this->pdo->inTransaction()) {
                    $this->pdo->commit();
                }

                $rolledBack[] = $migrationName;
            } catch (Throwable $e) {
                if ($inTx && $this->pdo->inTransaction()) {
                    $this->pdo->rollBack();
                }
                throw new RuntimeException("Rollback failed in '{$migrationName}': " . $e->getMessage(), 0, $e);
            }
        }

        return $rolledBack;
    }

    /**
     * Get status overview of all migration files.
     *
     * @return array<int, array{migration: string, ran: bool, batch: ?int, executed_at: ?string}>
     */
    public function status(string $migrationsPath): array
    {
        $files = is_dir($migrationsPath) ? glob(rtrim($migrationsPath, '/\\') . '/*.php') : [];
        if ($files === false) {
            $files = [];
        }
        sort($files);

        $stmt = $this->pdo->query("SELECT migration, batch, executed_at FROM {$this->table} ORDER BY id ASC");
        $executedRows = [];
        if ($stmt) {
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $executedRows[$row['migration']] = $row;
            }
        }

        $statuses = [];
        foreach ($files as $file) {
            $name = basename($file, '.php');
            $isExecuted = isset($executedRows[$name]);
            $statuses[] = [
                'migration'   => $name,
                'ran'         => $isExecuted,
                'batch'       => $isExecuted ? (int)$executedRows[$name]['batch'] : null,
                'executed_at' => $isExecuted ? (string)$executedRows[$name]['executed_at'] : null,
            ];
        }

        return $statuses;
    }

    /**
     * Create a new timestamped migration file from template.
     *
     * @param string $name Short descriptive name (e.g. 'create_orders_table')
     * @param string $migrationsPath Destination directory
     * @return string Full path to generated file
     */
    public function create(string $name, string $migrationsPath): string
    {
        if (!is_dir($migrationsPath)) {
            mkdir($migrationsPath, 0777, true);
        }

        $timestamp = date('Y_m_d_His');
        $cleanName = preg_replace('/[^a-zA-Z0-9_]/', '_', strtolower($name));
        $fileName = "{$timestamp}_{$cleanName}.php";
        $fullPath = rtrim($migrationsPath, '/\\') . '/' . $fileName;

        $template = <<<PHP
<?php

declare(strict_types=1);

use LiteORM\Migration\AbstractMigration;

return new class extends AbstractMigration
{
    public function up(): void
    {
        // Example:
        // \$this->execute("CREATE TABLE users (id INTEGER PRIMARY KEY, name VARCHAR(255))");
    }

    public function down(): void
    {
        // Example:
        // \$this->execute("DROP TABLE IF EXISTS users");
    }
};

PHP;

        file_put_contents($fullPath, $template);
        return $fullPath;
    }

    private function resolveMigrationInstance(string $file): AbstractMigration
    {
        $instance = require $file;
        if ($instance instanceof AbstractMigration) {
            return $instance;
        }

        throw new RuntimeException("Migration file '{$file}' must return an anonymous class extending AbstractMigration");
    }
}
