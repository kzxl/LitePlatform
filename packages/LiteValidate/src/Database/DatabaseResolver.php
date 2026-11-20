<?php

declare(strict_types=1);

namespace LiteValidate\Database;

use PDO;

/**
 * Resolver for database connections used by database validation rules (Unique, Exists).
 */
class DatabaseResolver
{
    /** @var ?callable(?string $connectionName): ?PDO */
    private static $connectionResolver = null;

    /** @var ?PDO Default PDO instance */
    private static ?PDO $defaultPdo = null;

    public static function setPdo(?PDO $pdo): void
    {
        self::$defaultPdo = $pdo;
    }

    public static function setConnectionResolver(callable $resolver): void
    {
        self::$connectionResolver = $resolver;
    }

    public static function getPdo(?string $connection = null): ?PDO
    {
        if (self::$connectionResolver !== null) {
            $pdo = (self::$connectionResolver)($connection);
            if ($pdo instanceof PDO) {
                return $pdo;
            }
        }

        return self::$defaultPdo;
    }
}
