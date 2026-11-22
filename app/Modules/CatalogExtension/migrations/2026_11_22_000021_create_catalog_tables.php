<?php

declare(strict_types=1);

/**
 * Migration: CatalogExtension table (`retail_items`) — domain-agnostic product/rental catalog.
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
            CREATE TABLE IF NOT EXISTS retail_items (
                id {$pk},
                sku VARCHAR(64) NOT NULL UNIQUE,
                name VARCHAR(255) NOT NULL,
                slug VARCHAR(255) NOT NULL UNIQUE,
                category_id BIGINT NULL,
                listing_type VARCHAR(16) NOT NULL DEFAULT 'rent',
                price DECIMAL(12,2) NOT NULL DEFAULT 0,
                deposit_default DECIMAL(12,2) NOT NULL DEFAULT 0,
                variant_summary VARCHAR(180) NULL,
                attributes_json TEXT NULL,
                description TEXT NULL,
                featured_image VARCHAR(255) NULL,
                gallery_json TEXT NULL,
                meta_title VARCHAR(255) NULL,
                meta_description VARCHAR(320) NULL,
                status VARCHAR(20) NOT NULL DEFAULT 'active',
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NULL
            ){$engine};
        ");

        if ($driver === 'mysql') {
            try {
                $pdo->exec('CREATE INDEX idx_retail_items_status ON retail_items (status, listing_type);');
            } catch (\Throwable) {
            }
        } else {
            $pdo->exec('CREATE INDEX IF NOT EXISTS idx_retail_items_status ON retail_items (status, listing_type);');
        }
    }
};
