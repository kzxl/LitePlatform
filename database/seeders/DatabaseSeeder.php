<?php

declare(strict_types=1);

namespace App\Database\Seeders;

use PDO;

class DatabaseSeeder
{
    public function run(PDO $pdo): void
    {
        $count = (int)$pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();
        if ($count === 0) {
            $stmt = $pdo->prepare("
                INSERT INTO users (name, email, password_hash, role, created_at)
                VALUES (:name, :email, :password_hash, :role, :created_at)
            ");

            $now = date('Y-m-d H:i:s');
            $adminPassword = (string)($_ENV['ADMIN_DEFAULT_PASSWORD'] ?? 'admin123');
            $stmt->execute([
                'name'          => 'System Administrator',
                'email'         => 'admin@liteplatform.io',
                'password_hash' => password_hash($adminPassword, PASSWORD_DEFAULT),
                'role'          => 'admin',
                'created_at'    => $now,
            ]);

            $stmt->execute([
                'name'          => 'Standard User',
                'email'         => 'user@liteplatform.io',
                'password_hash' => password_hash('user12345', PASSWORD_DEFAULT),
                'role'          => 'user',
                'created_at'    => $now,
            ]);
        }
    }
}
