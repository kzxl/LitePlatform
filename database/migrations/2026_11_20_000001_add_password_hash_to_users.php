<?php

declare(strict_types=1);

/**
 * Migration: Add password_hash column to users table and backfill default hashed password
 * for seeded accounts that do not yet have a hash.
 */
return new class {
    public function up(PDO $pdo): void
    {
        $driver = (string)$pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        $hasColumn = false;

        if ($driver === 'sqlite') {
            $cols = $pdo->query('PRAGMA table_info(users)')->fetchAll(PDO::FETCH_ASSOC) ?: [];
            foreach ($cols as $col) {
                if (($col['name'] ?? '') === 'password_hash') {
                    $hasColumn = true;
                    break;
                }
            }
        } elseif ($driver === 'pgsql') {
            $stmt = $pdo->prepare(
                "SELECT column_name FROM information_schema.columns WHERE table_name = 'users' AND column_name = 'password_hash'"
            );
            $stmt->execute();
            $hasColumn = $stmt->fetchColumn() !== false;
        } else {
            $stmt = $pdo->query("SHOW COLUMNS FROM users LIKE 'password_hash'");
            $hasColumn = $stmt !== false && $stmt->fetch() !== false;
        }

        if (!$hasColumn) {
            $pdo->exec("ALTER TABLE users ADD COLUMN password_hash VARCHAR(255) NOT NULL DEFAULT ''");
        }

        // Backfill seeded admin account if hash is empty
        $defaultPassword = (string)($_ENV['ADMIN_DEFAULT_PASSWORD'] ?? 'admin123');
        $hash = password_hash($defaultPassword, PASSWORD_DEFAULT);
        $stmt = $pdo->prepare(
            "UPDATE users SET password_hash = :hash WHERE email = 'admin@liteplatform.io' AND (password_hash = '' OR password_hash IS NULL)"
        );
        $stmt->execute([':hash' => $hash]);
    }
};
