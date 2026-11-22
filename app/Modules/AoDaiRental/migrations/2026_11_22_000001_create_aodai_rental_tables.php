<?php

declare(strict_types=1);

/**
 * Migration: Create Ao Dai Rental Plugin tables (ao_dais, customers, rental_orders, rental_order_items).
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
            CREATE TABLE IF NOT EXISTS ao_dais (
                id {$pk},
                code VARCHAR(64) NOT NULL UNIQUE,
                name VARCHAR(255) NOT NULL,
                slug VARCHAR(255) NOT NULL UNIQUE,
                category_id BIGINT NULL,
                rental_price DECIMAL(12,2) NOT NULL DEFAULT 0,
                deposit_default DECIMAL(12,2) NOT NULL DEFAULT 0,
                color VARCHAR(80) NULL,
                sizes VARCHAR(120) NULL,
                material VARCHAR(120) NULL,
                description TEXT NULL,
                featured_image VARCHAR(255) NULL,
                gallery_json TEXT NULL,
                meta_title VARCHAR(255) NULL,
                meta_description VARCHAR(320) NULL,
                status VARCHAR(20) NOT NULL DEFAULT 'available',
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NULL
            ){$engine};
        ");

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS customers (
                id {$pk},
                name VARCHAR(150) NOT NULL,
                phone VARCHAR(40) NOT NULL UNIQUE,
                zalo VARCHAR(80) NULL,
                email VARCHAR(160) NULL,
                measurements TEXT NULL,
                notes TEXT NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NULL
            ){$engine};
        ");

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS rental_orders (
                id {$pk},
                order_code VARCHAR(64) NOT NULL UNIQUE,
                customer_id BIGINT NOT NULL,
                ao_dai_id BIGINT NOT NULL,
                size VARCHAR(32) NULL,
                rent_date DATE NOT NULL,
                return_date DATE NOT NULL,
                actual_return_date DATE NULL,
                rental_fee DECIMAL(12,2) NOT NULL DEFAULT 0,
                discount DECIMAL(12,2) NOT NULL DEFAULT 0,
                late_fee DECIMAL(12,2) NOT NULL DEFAULT 0,
                damage_fee DECIMAL(12,2) NOT NULL DEFAULT 0,
                deposit_held DECIMAL(12,2) NOT NULL DEFAULT 0,
                deposit_refunded DECIMAL(12,2) NOT NULL DEFAULT 0,
                status VARCHAR(20) NOT NULL DEFAULT 'booked',
                notes TEXT NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NULL
            ){$engine};
        ");

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS rental_order_items (
                id {$pk},
                rental_order_id BIGINT NOT NULL,
                ao_dai_id BIGINT NOT NULL,
                size VARCHAR(32) NULL,
                unit_price DECIMAL(12,2) NOT NULL DEFAULT 0,
                qty INT NOT NULL DEFAULT 1
            ){$engine};
        ");

        if ($driver === 'mysql') {
            $indexes = [
                'ao_dais'            => ['idx_aodai_status', 'status'],
                'rental_orders'      => ['idx_rental_aodai_dates', 'ao_dai_id, status, rent_date, return_date'],
                'rental_order_items' => ['idx_rental_items_order', 'rental_order_id'],
            ];
            foreach ($indexes as $table => [$idxName, $cols]) {
                try {
                    $pdo->exec("CREATE INDEX {$idxName} ON {$table} ({$cols});");
                } catch (\Throwable) {
                    // Index already exists on MySQL re-run
                }
            }
            try {
                $pdo->exec('CREATE INDEX idx_rental_customer ON rental_orders (customer_id);');
            } catch (\Throwable) {
            }
        } else {
            $pdo->exec('CREATE INDEX IF NOT EXISTS idx_aodai_status ON ao_dais (status);');
            $pdo->exec('CREATE INDEX IF NOT EXISTS idx_rental_aodai_dates ON rental_orders (ao_dai_id, status, rent_date, return_date);');
            $pdo->exec('CREATE INDEX IF NOT EXISTS idx_rental_customer ON rental_orders (customer_id);');
            $pdo->exec('CREATE INDEX IF NOT EXISTS idx_rental_items_order ON rental_order_items (rental_order_id);');
        }
    }
};
