<?php

declare(strict_types=1);

/**
 * Migration: Deep Plugin Security, Dynamic RBAC, Encrypted Plugin Settings,
 * Tamper-Evident Security Audit Logs, and User Session Revocation / 2FA columns.
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
            CREATE TABLE IF NOT EXISTS roles (
                id {$pk},
                slug VARCHAR(64) NOT NULL UNIQUE,
                name VARCHAR(120) NOT NULL,
                is_system TINYINT(1) NOT NULL DEFAULT 0,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP
            ){$engine};
        ");

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS permissions (
                id {$pk},
                code VARCHAR(120) NOT NULL UNIQUE,
                module_id VARCHAR(120) NOT NULL DEFAULT 'core',
                label VARCHAR(255) NOT NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP
            ){$engine};
        ");

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS role_permissions (
                role_id BIGINT NOT NULL,
                permission_id BIGINT NOT NULL,
                PRIMARY KEY (role_id, permission_id)
            ){$engine};
        ");

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS user_roles (
                user_id BIGINT NOT NULL,
                role_id BIGINT NOT NULL,
                PRIMARY KEY (user_id, role_id)
            ){$engine};
        ");

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS plugin_registry (
                plugin_id VARCHAR(120) PRIMARY KEY,
                version VARCHAR(32) NOT NULL DEFAULT '1.0.0',
                state VARCHAR(24) NOT NULL DEFAULT 'enabled',
                checksum_sha256 VARCHAR(128) NULL,
                granted_capabilities_json TEXT NULL,
                failure_count INT NOT NULL DEFAULT 0,
                last_error TEXT NULL,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
            ){$engine};
        ");

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS plugin_settings (
                plugin_id VARCHAR(120) NOT NULL,
                setting_key VARCHAR(120) NOT NULL,
                setting_value TEXT NULL,
                is_encrypted TINYINT(1) NOT NULL DEFAULT 0,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (plugin_id, setting_key)
            ){$engine};
        ");

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS security_audit_logs (
                id {$pk},
                occurred_at VARCHAR(32) NOT NULL,
                severity VARCHAR(16) NOT NULL,
                event_type VARCHAR(80) NOT NULL,
                actor VARCHAR(160) NOT NULL DEFAULT 'system',
                actor_ip VARCHAR(64) NULL,
                module_id VARCHAR(120) NOT NULL DEFAULT 'core',
                target VARCHAR(255) NULL,
                context_json TEXT NULL,
                prev_hash VARCHAR(64) NOT NULL,
                entry_hash VARCHAR(64) NOT NULL
            ){$engine};
        ");

        // Add session revocation (token_version), account lock (is_active), and TOTP 2FA secret to users
        $userColumns = [
            'token_version' => 'INT NOT NULL DEFAULT 1',
            'is_active'     => 'TINYINT(1) NOT NULL DEFAULT 1',
            'totp_secret'   => 'VARCHAR(255) NULL',
        ];
        foreach ($userColumns as $col => $ddl) {
            if (!$this->columnExists($pdo, $driver, 'users', $col)) {
                $pdo->exec("ALTER TABLE users ADD COLUMN {$col} {$ddl};");
            }
        }

        // Seed baseline system roles idempotently
        $seedRoles = [
            ['superadmin', 'Super Administrator', 1],
            ['admin', 'Administrator', 1],
            ['manager', 'Store Manager', 1],
            ['staff', 'Operations Staff', 1],
            ['viewer', 'Read-Only Viewer', 1],
        ];
        $insertVerb = match ($driver) {
            'mysql' => 'INSERT IGNORE INTO',
            'pgsql' => 'INSERT INTO',
            default => 'INSERT OR IGNORE INTO',
        };
        $suffix = $driver === 'pgsql' ? ' ON CONFLICT (slug) DO NOTHING' : '';

        foreach ($seedRoles as [$slug, $name, $sys]) {
            $stmt = $pdo->prepare("{$insertVerb} roles (slug, name, is_system) VALUES (:slug, :name, :sys){$suffix}");
            $stmt->execute([':slug' => $slug, ':name' => $name, ':sys' => $sys]);
        }
    }

    private function columnExists(PDO $pdo, string $driver, string $table, string $column): bool
    {
        try {
            if ($driver === 'sqlite') {
                $stmt = $pdo->query("PRAGMA table_info({$table})");
                if ($stmt === false) {
                    return false;
                }
                foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $col) {
                    if (($col['name'] ?? '') === $column) {
                        return true;
                    }
                }
                return false;
            }
            if ($driver === 'mysql') {
                $stmt = $pdo->query("SHOW COLUMNS FROM `{$table}` LIKE '{$column}'");
                return $stmt !== false && $stmt->fetch() !== false;
            }
            $stmt = $pdo->prepare("SELECT 1 FROM information_schema.columns WHERE table_name = :t AND column_name = :c");
            $stmt->execute([':t' => $table, ':c' => $column]);
            return $stmt->fetchColumn() !== false;
        } catch (\Throwable) {
            return false;
        }
    }
};
