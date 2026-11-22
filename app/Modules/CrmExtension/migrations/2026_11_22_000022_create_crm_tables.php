<?php

declare(strict_types=1);

/**
 * Migration: CrmExtension table (`retail_customers`) — standalone customer CRM.
 */
return new class {
    public function up(PDO $pdo): void
    {
        $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        $pk = match ($driver) {
            'mysql' => 'BIGINT AUTO_INCREMENT PRIMARY KEY',
            'pgsql' => 'BIGSERIAL PRIMARY KEY',
            default => 'INTEGER PRIMARY KEY AUTOINCREMENT',
        };
        $engine = $driver === 'mysql' ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci' : '';

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS retail_customers (
                id {$pk},
                name VARCHAR(150) NOT NULL,
                phone VARCHAR(40) NOT NULL UNIQUE,
                chat_handle VARCHAR(120) NULL,
                email VARCHAR(160) NULL,
                profile_specs TEXT NULL,
                notes TEXT NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NULL
            ){$engine};
        ");
    }
};
