<?php

declare(strict_types=1);

namespace LiteORM\Migration;

use PDO;

/**
 * Base class for all database schema migrations in LiteORM.
 * Uses anonymous class returns or named classes extending this.
 */
abstract class AbstractMigration
{
    protected ?PDO $pdo = null;

    public function setPdo(PDO $pdo): void
    {
        $this->pdo = $pdo;
    }

    public function getPdo(): PDO
    {
        if ($this->pdo === null) {
            throw new \RuntimeException("PDO instance has not been injected into migration");
        }
        return $this->pdo;
    }

    /**
     * Run migration forward (schema creation, alteration, data updates).
     */
    abstract public function up(): void;

    /**
     * Revert migration backward (dropping tables, rollback changes).
     */
    abstract public function down(): void;

    /**
     * Execute a raw SQL query on the database.
     *
     * @param string $sql
     * @param array<string|int, mixed> $params
     * @return int Affected rows
     */
    protected function execute(string $sql, array $params = []): int
    {
        $pdo = $this->getPdo();
        if (empty($params)) {
            $result = $pdo->exec($sql);
            return $result !== false ? $result : 0;
        }

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->rowCount();
    }

    /**
     * Get active database driver name ('sqlite', 'mysql', 'pgsql').
     */
    protected function getDriver(): string
    {
        return (string)$this->getPdo()->getAttribute(PDO::ATTR_DRIVER_NAME);
    }
}
