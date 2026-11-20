<?php

declare(strict_types=1);

/**
 * Migration: Create CMS Micro-Kit tables (site_settings, media_assets, posts)
 * Cross-engine compatible with MySQL/MariaDB (InnoDB/utf8mb4), SQLite, and PostgreSQL.
 */
return new class {
    public function up(PDO $pdo): void
    {
        $driver = (string)$pdo->getAttribute(PDO::ATTR_DRIVER_NAME);

        if ($driver === 'sqlite') {
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS site_settings (
                    setting_key VARCHAR(120) PRIMARY KEY,
                    setting_value TEXT NULL,
                    setting_group VARCHAR(60) DEFAULT 'general',
                    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
                );
            ");

            $pdo->exec("
                CREATE TABLE IF NOT EXISTS media_assets (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    filename VARCHAR(180) NOT NULL,
                    original_name VARCHAR(255) NOT NULL,
                    relative_url VARCHAR(255) NOT NULL,
                    mime_type VARCHAR(80) NOT NULL,
                    width INTEGER NOT NULL DEFAULT 0,
                    height INTEGER NOT NULL DEFAULT 0,
                    bytes INTEGER NOT NULL DEFAULT 0,
                    alt_text VARCHAR(255) DEFAULT '',
                    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
                );
            ");

            $pdo->exec("
                CREATE TABLE IF NOT EXISTS posts (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    title VARCHAR(255) NOT NULL,
                    slug VARCHAR(255) NOT NULL UNIQUE,
                    excerpt TEXT NULL,
                    content TEXT NOT NULL,
                    featured_image VARCHAR(255) NULL,
                    meta_title VARCHAR(255) NULL,
                    meta_description VARCHAR(320) NULL,
                    status VARCHAR(20) NOT NULL DEFAULT 'draft',
                    published_at DATETIME NULL,
                    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                    updated_at DATETIME NULL
                );
            ");

            $pdo->exec('CREATE INDEX IF NOT EXISTS idx_posts_status_pub ON posts (status, published_at);');
            return;
        }

        if ($driver === 'pgsql') {
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS site_settings (
                    setting_key VARCHAR(120) PRIMARY KEY,
                    setting_value TEXT NULL,
                    setting_group VARCHAR(60) DEFAULT 'general',
                    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
                );
            ");

            $pdo->exec("
                CREATE TABLE IF NOT EXISTS media_assets (
                    id SERIAL PRIMARY KEY,
                    filename VARCHAR(180) NOT NULL,
                    original_name VARCHAR(255) NOT NULL,
                    relative_url VARCHAR(255) NOT NULL,
                    mime_type VARCHAR(80) NOT NULL,
                    width INTEGER NOT NULL DEFAULT 0,
                    height INTEGER NOT NULL DEFAULT 0,
                    bytes INTEGER NOT NULL DEFAULT 0,
                    alt_text VARCHAR(255) DEFAULT '',
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
                );
            ");

            $pdo->exec("
                CREATE TABLE IF NOT EXISTS posts (
                    id SERIAL PRIMARY KEY,
                    title VARCHAR(255) NOT NULL,
                    slug VARCHAR(255) NOT NULL UNIQUE,
                    excerpt TEXT NULL,
                    content TEXT NOT NULL,
                    featured_image VARCHAR(255) NULL,
                    meta_title VARCHAR(255) NULL,
                    meta_description VARCHAR(320) NULL,
                    status VARCHAR(20) NOT NULL DEFAULT 'draft',
                    published_at TIMESTAMP NULL,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    updated_at TIMESTAMP NULL
                );
            ");

            $pdo->exec('CREATE INDEX IF NOT EXISTS idx_posts_status_pub ON posts (status, published_at);');
            return;
        }

        // MySQL / MariaDB (Shared Hosting default)
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS site_settings (
                setting_key VARCHAR(120) PRIMARY KEY,
                setting_value TEXT NULL,
                setting_group VARCHAR(60) DEFAULT 'general',
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ");

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS media_assets (
                id BIGINT AUTO_INCREMENT PRIMARY KEY,
                filename VARCHAR(180) NOT NULL,
                original_name VARCHAR(255) NOT NULL,
                relative_url VARCHAR(255) NOT NULL,
                mime_type VARCHAR(80) NOT NULL,
                width INT NOT NULL DEFAULT 0,
                height INT NOT NULL DEFAULT 0,
                bytes INT NOT NULL DEFAULT 0,
                alt_text VARCHAR(255) DEFAULT '',
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ");

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS posts (
                id BIGINT AUTO_INCREMENT PRIMARY KEY,
                title VARCHAR(255) NOT NULL,
                slug VARCHAR(255) NOT NULL UNIQUE,
                excerpt TEXT NULL,
                content MEDIUMTEXT NOT NULL,
                featured_image VARCHAR(255) NULL,
                meta_title VARCHAR(255) NULL,
                meta_description VARCHAR(320) NULL,
                status VARCHAR(20) NOT NULL DEFAULT 'draft',
                published_at DATETIME NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NULL,
                INDEX idx_posts_status_pub (status, published_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ");
    }
};
