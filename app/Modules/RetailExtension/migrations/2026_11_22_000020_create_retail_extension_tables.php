<?php

declare(strict_types=1);

/**
 * Migration: Generic RetailExtension tables supporting both Rental and Sale workflows
 * (retail_items, retail_customers, retail_orders, retail_order_items).
 * Cross-engine compatible: SQLite, MySQL/MariaDB (InnoDB utf8mb4), PostgreSQL.
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

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS retail_orders (
                id {$pk},
                order_code VARCHAR(64) NOT NULL UNIQUE,
                order_type VARCHAR(16) NOT NULL DEFAULT 'rental',
                customer_id BIGINT NOT NULL,
                item_id BIGINT NOT NULL,
                variant VARCHAR(80) NULL,
                start_date DATE NOT NULL,
                end_date DATE NULL,
                actual_return_date DATE NULL,
                subtotal DECIMAL(12,2) NOT NULL DEFAULT 0,
                discount DECIMAL(12,2) NOT NULL DEFAULT 0,
                extra_fees DECIMAL(12,2) NOT NULL DEFAULT 0,
                deposit_held DECIMAL(12,2) NOT NULL DEFAULT 0,
                deposit_refunded DECIMAL(12,2) NOT NULL DEFAULT 0,
                status VARCHAR(20) NOT NULL DEFAULT 'confirmed',
                notes TEXT NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NULL
            ){$engine};
        ");

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS retail_order_items (
                id {$pk},
                order_id BIGINT NOT NULL,
                item_id BIGINT NOT NULL,
                variant VARCHAR(80) NULL,
                unit_price DECIMAL(12,2) NOT NULL DEFAULT 0,
                qty INT NOT NULL DEFAULT 1
            ){$engine};
        ");

        if ($driver === 'mysql') {
            $indexes = [
                'retail_items'       => ['idx_retail_items_status', 'status, listing_type'],
                'retail_orders'      => ['idx_retail_orders_sched', 'item_id, order_type, status, start_date, end_date'],
                'retail_order_items' => ['idx_retail_oitems_order', 'order_id'],
            ];
            foreach ($indexes as $table => [$idxName, $cols]) {
                try {
                    $pdo->exec("CREATE INDEX {$idxName} ON {$table} ({$cols});");
                } catch (\Throwable) {
                }
            }
            try {
                $pdo->exec('CREATE INDEX idx_retail_orders_customer ON retail_orders (customer_id);');
            } catch (\Throwable) {
            }
        } else {
            $pdo->exec('CREATE INDEX IF NOT EXISTS idx_retail_items_status ON retail_items (status, listing_type);');
            $pdo->exec('CREATE INDEX IF NOT EXISTS idx_retail_orders_sched ON retail_orders (item_id, order_type, status, start_date, end_date);');
            $pdo->exec('CREATE INDEX IF NOT EXISTS idx_retail_orders_customer ON retail_orders (customer_id);');
            $pdo->exec('CREATE INDEX IF NOT EXISTS idx_retail_oitems_order ON retail_order_items (order_id);');
        }
    }
};
