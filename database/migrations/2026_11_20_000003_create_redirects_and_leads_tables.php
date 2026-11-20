<?php

declare(strict_types=1);

/**
 * Migration: Create SEO 301 Redirects & Contact Leads tables.
 * Cross-engine compatible with MySQL/MariaDB (InnoDB/utf8mb4), SQLite, and PostgreSQL.
 */
return new class {
    public function up(PDO $pdo): void
    {
        $driver = (string)$pdo->getAttribute(PDO::ATTR_DRIVER_NAME);

        if ($driver === 'sqlite') {
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS redirects (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    source_path VARCHAR(255) NOT NULL UNIQUE,
                    target_url VARCHAR(500) NOT NULL,
                    status_code INTEGER NOT NULL DEFAULT 301,
                    is_active INTEGER NOT NULL DEFAULT 1,
                    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
                );
            ");

            $pdo->exec("
                CREATE TABLE IF NOT EXISTS contact_leads (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    name VARCHAR(120) NOT NULL,
                    email VARCHAR(160) NOT NULL,
                    phone VARCHAR(40) NULL,
                    message TEXT NOT NULL,
                    ip_address VARCHAR(64) NULL,
                    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
                );
            ");
            return;
        }

        if ($driver === 'pgsql') {
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS redirects (
                    id SERIAL PRIMARY KEY,
                    source_path VARCHAR(255) NOT NULL UNIQUE,
                    target_url VARCHAR(500) NOT NULL,
                    status_code INTEGER NOT NULL DEFAULT 301,
                    is_active BOOLEAN NOT NULL DEFAULT TRUE,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
                );
            ");

            $pdo->exec("
                CREATE TABLE IF NOT EXISTS contact_leads (
                    id SERIAL PRIMARY KEY,
                    name VARCHAR(120) NOT NULL,
                    email VARCHAR(160) NOT NULL,
                    phone VARCHAR(40) NULL,
                    message TEXT NOT NULL,
                    ip_address VARCHAR(64) NULL,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
                );
            ");
            return;
        }

        // MySQL / MariaDB
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS redirects (
                id BIGINT AUTO_INCREMENT PRIMARY KEY,
                source_path VARCHAR(255) NOT NULL UNIQUE,
                target_url VARCHAR(500) NOT NULL,
                status_code SMALLINT NOT NULL DEFAULT 301,
                is_active TINYINT(1) NOT NULL DEFAULT 1,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ");

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS contact_leads (
                id BIGINT AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(120) NOT NULL,
                email VARCHAR(160) NOT NULL,
                phone VARCHAR(40) NULL,
                message TEXT NOT NULL,
                ip_address VARCHAR(64) NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ");
    }
};
