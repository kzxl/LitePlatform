<?php

declare(strict_types=1);

/**
 * Migration: Create universal CMS tables (categories, pages) and add posts.category_id.
 * Cross-engine compatible with MySQL/MariaDB (InnoDB/utf8mb4), SQLite, and PostgreSQL.
 */
return new class {
    public function up(PDO $pdo): void
    {
        $driver = (string)$pdo->getAttribute(PDO::ATTR_DRIVER_NAME);

        if ($driver === 'sqlite') {
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS categories (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    name VARCHAR(120) NOT NULL,
                    slug VARCHAR(150) NOT NULL UNIQUE,
                    description TEXT NULL,
                    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
                );
            ");

            $pdo->exec("
                CREATE TABLE IF NOT EXISTS pages (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    title VARCHAR(255) NOT NULL,
                    slug VARCHAR(255) NOT NULL UNIQUE,
                    content TEXT NOT NULL,
                    featured_image VARCHAR(255) NULL,
                    meta_title VARCHAR(255) NULL,
                    meta_description VARCHAR(320) NULL,
                    show_in_nav INTEGER NOT NULL DEFAULT 1,
                    status VARCHAR(20) NOT NULL DEFAULT 'published',
                    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                    updated_at DATETIME NULL
                );
            ");

            $cols = $pdo->query('PRAGMA table_info(posts)')->fetchAll(PDO::FETCH_ASSOC) ?: [];
            $hasCat = false;
            foreach ($cols as $c) {
                if (($c['name'] ?? '') === 'category_id') {
                    $hasCat = true;
                    break;
                }
            }
            if (!$hasCat) {
                $pdo->exec('ALTER TABLE posts ADD COLUMN category_id INTEGER NULL');
            }
            return;
        }

        if ($driver === 'pgsql') {
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS categories (
                    id SERIAL PRIMARY KEY,
                    name VARCHAR(120) NOT NULL,
                    slug VARCHAR(150) NOT NULL UNIQUE,
                    description TEXT NULL,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
                );
            ");

            $pdo->exec("
                CREATE TABLE IF NOT EXISTS pages (
                    id SERIAL PRIMARY KEY,
                    title VARCHAR(255) NOT NULL,
                    slug VARCHAR(255) NOT NULL UNIQUE,
                    content TEXT NOT NULL,
                    featured_image VARCHAR(255) NULL,
                    meta_title VARCHAR(255) NULL,
                    meta_description VARCHAR(320) NULL,
                    show_in_nav BOOLEAN NOT NULL DEFAULT TRUE,
                    status VARCHAR(20) NOT NULL DEFAULT 'published',
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    updated_at TIMESTAMP NULL
                );
            ");

            $stmt = $pdo->prepare("SELECT column_name FROM information_schema.columns WHERE table_name = 'posts' AND column_name = 'category_id'");
            $stmt->execute();
            if ($stmt->fetchColumn() === false) {
                $pdo->exec('ALTER TABLE posts ADD COLUMN category_id BIGINT NULL');
            }
            return;
        }

        // MySQL / MariaDB
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS categories (
                id BIGINT AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(120) NOT NULL,
                slug VARCHAR(150) NOT NULL UNIQUE,
                description TEXT NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ");

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS pages (
                id BIGINT AUTO_INCREMENT PRIMARY KEY,
                title VARCHAR(255) NOT NULL,
                slug VARCHAR(255) NOT NULL UNIQUE,
                content MEDIUMTEXT NOT NULL,
                featured_image VARCHAR(255) NULL,
                meta_title VARCHAR(255) NULL,
                meta_description VARCHAR(320) NULL,
                show_in_nav TINYINT(1) NOT NULL DEFAULT 1,
                status VARCHAR(20) NOT NULL DEFAULT 'published',
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ");

        $stmt = $pdo->query("SHOW COLUMNS FROM posts LIKE 'category_id'");
        if ($stmt === false || $stmt->fetch() === false) {
            $pdo->exec('ALTER TABLE posts ADD COLUMN category_id BIGINT NULL');
        }
    }
};
